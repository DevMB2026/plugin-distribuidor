<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente HTTP hacia la API central del catálogo, en su namespace PARA
 * DISTRIBUIDORES (/distribuidores/productos), autenticado con X-API-Key.
 *
 * La key NUNCA se manda al navegador del visitante: esta llamada ocurre
 * siempre en el servidor de WordPress (wp_remote_get), igual que el plugin
 * público. El backend decide qué catálogo ve este distribuidor (su
 * asignación vive en el servidor, no aquí) — este plugin no elige nada,
 * solo se identifica.
 */
class Catalogo_Distribuidor_Bridge_Client {

	// Render free tier duerme el servicio tras inactividad y puede tardar
	// 30-50s en despertar en el primer request; 8s lo hacía fallar seguido.
	// Seguro de subir: en operación normal el front-end lee de la BD local
	// (Store::query / Store::get_by_slug), este timeout solo aplica a la
	// sincronización de fondo (cron/webhook) y al fallback cacheado cuando
	// la BD local todavía está vacía.
	const TIMEOUT = 45;

	private static function base_url() {
		$url = get_option( 'catalogo_distribuidor_api_base_url', 'https://api-catalogo-productos.onrender.com/api/v1' );
		return untrailingslashit( $url );
	}

	private static function api_key() {
		return get_option( 'catalogo_distribuidor_api_key', '' );
	}

	/**
	 * GET /distribuidores/productos — listado. $args admite: brand, category,
	 * q, page, limit. (Sin "catalogo": el catálogo de un distribuidor lo
	 * decide el backend a partir de su API Key, no un parámetro del cliente
	 * — mandarlo no tendría efecto, así que ni se ofrece aquí.)
	 */
	public static function get_products( $args = array() ) {
		$allowed = array( 'brand', 'category', 'q', 'page', 'limit' );
		$query   = array();

		foreach ( $allowed as $key ) {
			if ( isset( $args[ $key ] ) && $args[ $key ] !== '' ) {
				$query[ $key ] = $args[ $key ];
			}
		}

		$url = self::base_url() . '/distribuidores/productos';
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		return self::request( $url );
	}

	/**
	 * GET /distribuidores/productos/slug/:slug — detalle por slug.
	 */
	public static function get_product_by_slug( $slug ) {
		$url = self::base_url() . '/distribuidores/productos/slug/' . rawurlencode( $slug );
		return self::request( $url );
	}

	/**
	 * GET /distribuidores/productos/changes?since=... — usado por
	 * Catalogo_Distribuidor_Bridge_Sync para poblar/actualizar la BD local.
	 * Ya viene escoped al catálogo asignado del distribuidor (el backend
	 * resuelve eso a partir de la misma X-API-Key), con el mismo detalle
	 * completo que get_product_by_slug (variantes, opciones, etc.).
	 */
	public static function get_changes( $since ) {
		$api_key = self::api_key();
		if ( empty( $api_key ) ) {
			return array( 'ok' => false, 'status' => 0, 'data' => null, 'server_time' => null, 'message' => 'Falta la API Key.' );
		}

		$url      = self::base_url() . '/distribuidores/productos/changes?since=' . rawurlencode( $since );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json', 'X-API-Key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'data' => null, 'server_time' => null, 'message' => 'No se pudo conectar con el catálogo.' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			return array( 'ok' => false, 'status' => $status, 'data' => null, 'server_time' => null, 'message' => 'El catálogo no está disponible en este momento.' );
		}

		return array(
			'ok'          => true,
			'status'      => $status,
			'data'        => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array(),
			'server_time' => isset( $decoded['serverTime'] ) ? $decoded['serverTime'] : null,
			'message'     => '',
		);
	}

	/**
	 * POST /distribuidores/productos/webhook — registra la URL del receptor
	 * de webhooks de este sitio (ya autenticado con la misma X-API-Key, así
	 * que no hay riesgo de registro anónimo). Devuelve el secreto UNA vez —
	 * hay que guardarlo localmente, el backend no lo vuelve a mostrar.
	 */
	public static function register_webhook( $callback_url ) {
		$api_key = self::api_key();
		if ( empty( $api_key ) ) {
			return array( 'ok' => false, 'secret' => null );
		}

		$response = wp_remote_post(
			self::base_url() . '/distribuidores/productos/webhook',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json', 'X-API-Key' => $api_key ),
				'body'    => wp_json_encode( array( 'url' => $callback_url ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'secret' => null );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || empty( $decoded['success'] ) ) {
			return array( 'ok' => false, 'secret' => null );
		}
		return array( 'ok' => true, 'secret' => isset( $decoded['data']['webhookSecret'] ) ? $decoded['data']['webhookSecret'] : null );
	}

	/**
	 * GET /categories — árbol completo de categorías (con "parent"). A
	 * diferencia del resto de este cliente, este endpoint es público (no
	 * vive bajo /distribuidores ni pide X-API-Key), así que llama a
	 * wp_remote_get() directo en vez de pasar por request(). Lo usa
	 * únicamente Catalogo_Distribuidor_Bridge_Store::categories_tree() para
	 * agrupar automáticamente categorías crudas bajo su categoría raíz real
	 * — en vez de que cada categoría hija (ej. "fleece", hija de
	 * "Sudaderas" en la API) aparezca como si fuera su propia categoría
	 * suelta en el catálogo.
	 */
	public static function get_categories() {
		$response = wp_remote_get(
			self::base_url() . '/categories',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'data' => null );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			return array( 'ok' => false, 'data' => null );
		}

		return array( 'ok' => true, 'data' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array() );
	}

	/** DELETE /distribuidores/productos/webhook — best-effort, se llama al desactivar el plugin. */
	public static function unregister_webhook() {
		$api_key = self::api_key();
		if ( empty( $api_key ) ) {
			return;
		}
		wp_remote_request(
			self::base_url() . '/distribuidores/productos/webhook',
			array(
				'method'  => 'DELETE',
				'timeout' => 5,
				'headers' => array( 'X-API-Key' => $api_key ),
			)
		);
	}

	/**
	 * Igual que el cliente público, pero agrega el header X-API-Key y
	 * distingue el caso "todavía no configurado" para poder avisarle al
	 * ADMIN del sitio (nunca al visitante) que falta la key.
	 */
	private static function request( $url ) {
		$api_key = self::api_key();

		if ( empty( $api_key ) ) {
			return array(
				'ok'            => false,
				'status'        => 0,
				'data'          => null,
				'pagination'    => null,
				'message'       => 'El catálogo no está disponible en este momento. Intenta más tarde.',
				'key_missing'   => true,
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/json',
					'X-API-Key'  => $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'         => false,
				'status'     => 0,
				'data'       => null,
				'pagination' => null,
				'message'    => 'No se pudo conectar con el catálogo. Intenta más tarde.',
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 404 === $status ) {
			return array(
				'ok'         => false,
				'status'     => 404,
				'data'       => null,
				'pagination' => null,
				'message'    => 'Producto no encontrado.',
			);
		}

		// 401 (key ausente/invalida en el backend) se trata igual que
		// cualquier otro error de cara al visitante — el mensaje nunca
		// revela el motivo técnico. El admin ve el aviso aparte, en Ajustes.
		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			return array(
				'ok'          => false,
				'status'      => $status,
				'data'        => null,
				'pagination'  => null,
				'message'     => 'El catálogo no está disponible en este momento. Intenta más tarde.',
				'auth_failed' => in_array( $status, array( 401 ), true ),
			);
		}

		return array(
			'ok'         => true,
			'status'     => $status,
			'data'       => isset( $decoded['data'] ) ? $decoded['data'] : null,
			'pagination' => isset( $decoded['pagination'] ) ? $decoded['pagination'] : null,
			'message'    => '',
		);
	}
}
