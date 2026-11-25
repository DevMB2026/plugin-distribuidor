<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap de las fichas de producto (/catalogo-distribuidor/producto/{slug}/).
 *
 * Las fichas son rutas virtuales (no hay un WP_Post detrás), así que Yoast no
 * las conoce y no salen en ningún sitemap. Aquí se registra un sitemap propio
 * dentro del índice de Yoast:
 *
 *   /sitemap_index.xml  ->  /catalogo_fichas-sitemap.xml
 *
 * - Solo lista productos ACTIVOS de la tabla local (los inactivos dan 404).
 * - <lastmod> sale de la fecha de actualización del producto.
 * - Incluye la foto principal como <image:image>.
 * - Si Yoast no está activo, no hace nada.
 * - Para ajustar la lista: add_filter( 'cdb_sitemap_products', function ( $rows ) { ... return $rows; } );
 *   Cada fila es array( 'slug', 'updated_at' (Y-m-d H:i:s, UTC), 'image' ).
 */
class Catalogo_Distribuidor_Bridge_Sitemap {

	const TYPE = 'catalogo_fichas';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'add_to_index' ) );
	}

	/** Registra el tipo de sitemap en Yoast (solo si Yoast está cargado). */
	public static function register() {
		global $wpseo_sitemaps;
		if ( ! defined( 'WPSEO_VERSION' ) || ! is_object( $wpseo_sitemaps ) || ! method_exists( $wpseo_sitemaps, 'register_sitemap' ) ) {
			return;
		}
		$wpseo_sitemaps->register_sitemap( self::TYPE, array( __CLASS__, 'render' ) );
	}

	/** Filas del sitemap: productos activos con slug. */
	private static function rows() {
		$rows = Catalogo_Distribuidor_Bridge_Store::sitemap_rows();
		return apply_filters( 'cdb_sitemap_products', $rows );
	}

	/** Agrega la entrada del sitemap al índice de Yoast. */
	public static function add_to_index( $xml ) {
		$rows = self::rows();
		if ( empty( $rows ) ) {
			return $xml;
		}
		$lastmod = '';
		foreach ( $rows as $row ) {
			if ( $row['updated_at'] > $lastmod ) {
				$lastmod = $row['updated_at'];
			}
		}
		$entry  = '<sitemap>' . "\n";
		$entry .= '<loc>' . esc_url( home_url( '/' . self::TYPE . '-sitemap.xml' ) ) . '</loc>' . "\n";
		if ( $lastmod ) {
			$entry .= '<lastmod>' . esc_html( gmdate( 'c', strtotime( $lastmod . ' UTC' ) ) ) . '</lastmod>' . "\n";
		}
		$entry .= '</sitemap>' . "\n";
		return $xml . $entry;
	}

	/** Dibuja /catalogo_fichas-sitemap.xml */
	public static function render() {
		global $wpseo_sitemaps;
		if ( ! is_object( $wpseo_sitemaps ) ) {
			return;
		}

		$out  = '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::rows() as $row ) {
			$out .= "\t<url>\n";
			$out .= "\t\t<loc>" . esc_url( Catalogo_Distribuidor_Bridge_Rewrite::product_url( $row['slug'] ) ) . "</loc>\n";
			if ( ! empty( $row['updated_at'] ) ) {
				$out .= "\t\t<lastmod>" . esc_html( gmdate( 'c', strtotime( $row['updated_at'] . ' UTC' ) ) ) . "</lastmod>\n";
			}
			if ( ! empty( $row['image'] ) ) {
				$out .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( $row['image'] ) . "</image:loc>\n\t\t</image:image>\n";
			}
			$out .= "\t</url>\n";
		}
		$out .= '</urlset>';

		$wpseo_sitemaps->set_sitemap( $out );
	}
}
