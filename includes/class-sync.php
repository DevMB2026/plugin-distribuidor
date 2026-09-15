<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquesta la sincronización de la BD local (Catalogo_Distribuidor_Bridge_Store):
 *  - INMEDIATA: la API llama a un webhook propio de este sitio en cuanto hay
 *    un alta/edición/baja — el receptor (REST route) solo verifica la firma
 *    y agenda delta_sync() para correr fuera de esa misma petición (así el
 *    webhook responde rápido y no se enreda con el timeout que ya tiene el
 *    disparo del lado del backend).
 *  - Diaria: full_sync() — recorre TODO el catálogo asignado (mismo
 *    endpoint de "changes" con un cursor muy antiguo) y poda cualquier
 *    producto que ya no exista o haya salido del catálogo asignado. Es la
 *    red de seguridad para webhooks perdidos (el sitio estaba caído, etc.)
 *    y para hard-deletes, que un cursor de updatedAt no puede representar.
 *
 * El registro del webhook ante el backend ocurre solo, la primera vez que
 * se guarda una API Key (o cuando cambia) — ver hook_option_changes().
 */
class Catalogo_Distribuidor_Bridge_Sync {

	const CRON_FULL_HOOK   = 'catalogo_dist_full_sync';
	const DELTA_EVENT_HOOK = 'catalogo_dist_delta_sync_event';
	const OPT_CURSOR       = 'catalogo_dist_sync_cursor';
	const OPT_STATUS       = 'catalogo_dist_sync_status';
	const OPT_WEBHOOK_SECRET = 'catalogo_distribuidor_webhook_secret';
	const REST_NAMESPACE   = 'catalogo-distribuidor-bridge/v1';
	const PAGE_SIZE        = 200; // debe coincidir con CHANGES_LIMIT del backend.
	const EPOCH            = '1970-01-01T00:00:00.000Z';

	public static function init() {
		add_action( self::CRON_FULL_HOOK, array( __CLASS__, 'full_sync' ) );
		add_action( self::DELTA_EVENT_HOOK, array( __CLASS__, 'delta_sync' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_route' ) );
		add_action( 'update_option_catalogo_distribuidor_api_key', array( __CLASS__, 'maybe_register_webhook' ), 10, 2 );
		add_action( 'add_option_catalogo_distribuidor_api_key', array( __CLASS__, 'maybe_register_webhook_on_add' ), 10, 2 );
	}

	public static function activate() {
		Catalogo_Distribuidor_Bridge_Store::install();
		if ( ! wp_next_scheduled( self::CRON_FULL_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_FULL_HOOK );
		}
		// Primer arranque: si ya hay una key configurada, intenta poblar de una
		// vez y registrar el webhook — si no hay key todavía, no pasa nada
		// (se hace en cuanto el usuario la guarde, ver maybe_register_webhook).
		if ( get_option( 'catalogo_distribuidor_api_key', '' ) ) {
			self::register_webhook();
			self::full_sync();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_FULL_HOOK );
		wp_clear_scheduled_hook( self::DELTA_EVENT_HOOK );
		Catalogo_Distribuidor_Bridge_Client::unregister_webhook();
	}

	// --- Registro de webhook: solo cuando la API Key realmente cambia ---

	public static function maybe_register_webhook( $old_value, $new_value ) {
		if ( $old_value === $new_value || empty( $new_value ) ) {
			return;
		}
		self::register_webhook();
		self::full_sync(); // key nueva probablemente significa catálogo distinto.
	}

	public static function maybe_register_webhook_on_add( $option, $value ) {
		if ( empty( $value ) ) {
			return;
		}
		self::register_webhook();
		self::full_sync();
	}

	private static function register_webhook() {
		$callback_url = rest_url( self::REST_NAMESPACE . '/webhook' );
		$result       = Catalogo_Distribuidor_Bridge_Client::register_webhook( $callback_url );
		if ( ! empty( $result['ok'] ) && ! empty( $result['secret'] ) ) {
			update_option( self::OPT_WEBHOOK_SECRET, $result['secret'], false );
		}
	}

	// --- Receptor del webhook ---

	public static function register_webhook_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true', // la autenticación real es la firma HMAC, verificada abajo.
			)
		);
	}

	public static function handle_webhook( $request ) {
		$secret = get_option( self::OPT_WEBHOOK_SECRET, '' );
		$header = $request->get_header( 'x_catalogo_signature' );

		if ( empty( $secret ) || empty( $header ) || 0 !== strpos( $header, 'sha256=' ) ) {
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $header ) ) {
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}

		// No corre delta_sync() aquí mismo: el disparo del backend ya tiene su
		// propio timeout corto, y encadenar otra llamada de red saliente
		// dentro de esta misma petición apilaría dos saltos de red en ese
		// presupuesto. Se agenda para correr aparte y se responde 200 ya.
		if ( ! wp_next_scheduled( self::DELTA_EVENT_HOOK ) ) {
			wp_schedule_single_event( time(), self::DELTA_EVENT_HOOK );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// --- Sincronización ---

	public static function delta_sync() {
		self::run_from_cursor( get_option( self::OPT_CURSOR, self::EPOCH ), false );
	}

	public static function full_sync() {
		// El árbol de categorías (usado para agrupar automáticamente
		// categorías crudas bajo su raíz real) se cachea aparte y por más
		// tiempo — se refresca aquí también para que un "Sincronizar ahora"
		// manual no se quede con una jerarquía vieja hasta que expire sola.
		delete_transient( 'catalogo_distribuidor_categories_tree' );

		$keep_ids = self::run_from_cursor( self::EPOCH, true );
		if ( null !== $keep_ids ) {
			Catalogo_Distribuidor_Bridge_Store::prune_missing( $keep_ids );
		}
	}

	private static function run_from_cursor( $since, $collect_ids ) {
		$seen_ids = array();
		$cursor   = $since;
		$guard    = 0;

		while ( $guard < 100 ) {
			$guard++;
			$result = Catalogo_Distribuidor_Bridge_Client::get_changes( $cursor );

			if ( empty( $result['ok'] ) ) {
				self::set_status( false, 'No se pudo conectar con el catálogo en la última sincronización.' );
				return null;
			}

			$products = $result['data'];
			Catalogo_Distribuidor_Bridge_Store::upsert_many( $products );

			if ( $collect_ids ) {
				foreach ( $products as $p ) {
					if ( ! empty( $p['_id'] ) ) {
						$seen_ids[] = $p['_id'];
					}
				}
			}

			if ( ! empty( $result['server_time'] ) ) {
				$cursor = $result['server_time'];
			}

			if ( count( $products ) < self::PAGE_SIZE ) {
				break;
			}
		}

		update_option( self::OPT_CURSOR, $cursor, false );
		self::set_status( true, '' );

		return $collect_ids ? $seen_ids : array();
	}

	private static function set_status( $ok, $message ) {
		update_option( self::OPT_STATUS, array( 'ok' => $ok, 'at' => current_time( 'mysql' ), 'message' => $message ), false );
	}

	public static function get_status() {
		return get_option( self::OPT_STATUS, null );
	}
}
