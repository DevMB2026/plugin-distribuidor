<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ruta amigable /catalogo-distribuidor/producto/{slug}/ para la ficha de
 * producto. Prefijo distinto al del plugin público a propósito, para que
 * nunca choquen si algún día conviven en el mismo sitio.
 */
class Catalogo_Distribuidor_Bridge_Rewrite {

	const QUERY_VAR = 'catalogo_dist_producto';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'template_include', array( __CLASS__, 'maybe_load_template' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'set_document_title' ) );
	}

	public static function add_rules() {
		add_rewrite_rule(
			'^catalogo-distribuidor/producto/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_load_template( $template ) {
		$slug = get_query_var( self::QUERY_VAR );
		if ( $slug ) {
			return Catalogo_Distribuidor_Bridge_Templates::locate( 'detail.php' );
		}
		return $template;
	}

	/**
	 * La ficha de producto no tiene un WP_Post real detrás — WordPress no
	 * reconoce la ruta virtual y cae al título por defecto del sitio (el de
	 * la página de entradas, "Blog" en la mayoría de temas). Aquí se
	 * reemplaza por el nombre real del producto, con el mismo patrón de
	 * caché que ya usa templates/detail.php.
	 */
	public static function set_document_title( $title_parts ) {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug ) {
			return $title_parts;
		}

		$result = Catalogo_Distribuidor_Bridge_Store::is_empty()
			? Catalogo_Distribuidor_Bridge_Cache::remember( Catalogo_Distribuidor_Bridge_Cache::key_for_product_slug( $slug ), function () use ( $slug ) {
				return Catalogo_Distribuidor_Bridge_Client::get_product_by_slug( $slug );
			} )
			: Catalogo_Distribuidor_Bridge_Store::get_by_slug( $slug );

		$title_parts['title'] = ( ! empty( $result['ok'] ) && ! empty( $result['data']['nombre'] ) )
			? $result['data']['nombre']
			: 'Catálogo';

		return $title_parts;
	}

	public static function activate() {
		self::add_rules();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function product_url( $slug ) {
		return home_url( '/catalogo-distribuidor/producto/' . rawurlencode( $slug ) . '/' );
	}

	/**
	 * URL de la página donde el distribuidor puso el shortcode
	 * [catalogo_distribuidor] — el plugin no puede saber cuál es esa página
	 * por su cuenta (no hay una ruta fija como con product_url()), así que
	 * se configura en Ajustes → Catálogo Distribuidor. Si no se configuró,
	 * cae a la portada del sitio en vez de a un enlace roto.
	 */
	public static function catalog_url() {
		$configurada = get_option( 'catalogo_distribuidor_catalog_page_url', '' );
		return $configurada ? $configurada : home_url( '/' );
	}

	/**
	 * Enlace wa.me con el mensaje ya redactado (nombre del producto, SKU y
	 * la URL de la ficha) para el botón "Cotizar por WhatsApp" — así el
	 * cliente solo tiene que presionar Enviar, sin escribir nada.
	 */
	public static function build_whatsapp_quote_url( $numero, $nombre, $sku ) {
		$product_url = esc_url_raw( home_url( add_query_arg( array(), sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ) );
		$mensaje     = "Hola, quiero cotizar este producto: {$nombre}";
		if ( $sku ) {
			$mensaje .= " (SKU {$sku})";
		}
		$mensaje .= " - {$product_url}";
		return 'https://wa.me/' . rawurlencode( $numero ) . '?text=' . rawurlencode( $mensaje );
	}
}
