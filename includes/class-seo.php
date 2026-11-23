<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO de la ficha de producto (/catalogo-distribuidor/producto/{slug}/).
 *
 * La ficha es una ruta virtual (no hay un WP_Post detrás), así que Yoast no
 * sabe qué es: no imprime canonical, meta description, og:title/url/imagen ni
 * un Schema de producto. Aquí se imprimen esas etiquetas con los datos reales
 * del producto, sin tocar nada de la ficha visible.
 *
 * - Solo actúa en la ficha de un producto que existe y está activo. Un slug
 *   inexistente o inactivo ya responde 404 (ver detail.php) y no lleva nada.
 * - Si Yoast está activo, se silencian sus versiones de las mismas etiquetas
 *   en esta ruta para no duplicar; en el resto del sitio no se toca nada.
 * - El Schema NO incluye precio (los precios son privados). Se puede apagar
 *   con: add_filter( 'cdb_seo_product_schema', '__return_false' );
 */
class Catalogo_Distribuidor_Bridge_Seo {

	/** @var array|null|false false = aún no cargado, null = no hay producto. */
	private static $product = false;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 6 );

		// Yoast: en la ficha, que no imprima sus versiones (vacías o genéricas)
		// de lo que imprime este plugin. Fuera de la ficha devuelven el valor
		// original, intactos.
		foreach ( array(
			'wpseo_canonical',
			'wpseo_metadesc',
			'wpseo_opengraph_title',
			'wpseo_opengraph_desc',
			'wpseo_opengraph_url',
			'wpseo_opengraph_image',
			'wpseo_twitter_title',
			'wpseo_twitter_description',
			'wpseo_twitter_image',
		) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'silence_yoast' ), 99 );
		}
		add_filter( 'wpseo_opengraph_type', array( __CLASS__, 'og_type' ), 99 );
	}

	public static function silence_yoast( $value ) {
		return self::get_product() ? '' : $value;
	}

	public static function og_type( $type ) {
		return self::get_product() ? 'product' : $type;
	}

	/**
	 * Producto de la ficha actual, o null si no es una ficha / no existe.
	 * Misma fuente que usa detail.php (tabla local, o API si está vacía).
	 */
	private static function get_product() {
		if ( false !== self::$product ) {
			return self::$product;
		}
		self::$product = null;

		$slug = get_query_var( Catalogo_Distribuidor_Bridge_Rewrite::QUERY_VAR );
		if ( ! $slug ) {
			return null;
		}

		$result = Catalogo_Distribuidor_Bridge_Store::is_empty()
			? Catalogo_Distribuidor_Bridge_Cache::remember( Catalogo_Distribuidor_Bridge_Cache::key_for_product_slug( $slug ), function () use ( $slug ) {
				return Catalogo_Distribuidor_Bridge_Client::get_product_by_slug( $slug );
			} )
			: Catalogo_Distribuidor_Bridge_Store::get_by_slug( $slug );

		if ( empty( $result['ok'] ) || empty( $result['data']['nombre'] ) || empty( $result['data']['activo'] ) ) {
			return null;
		}

		$product         = $result['data'];
		$product['slug'] = isset( $product['slug'] ) ? $product['slug'] : $slug;
		self::$product   = $product;
		return self::$product;
	}

	/** Texto plano, sin HTML ni espacios repetidos. */
	private static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Recorta a $max caracteres sin partir una palabra. */
	private static function truncate( $text, $max = 155 ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		// Solo mb_strlen/mb_substr (WordPress las cubre aunque falte mbstring);
		// el espacio es de un byte, así que strrpos() nunca parte un carácter.
		$cut = mb_substr( $text, 0, $max, 'UTF-8' );
		// Si hay un final de frase razonable dentro del límite, se corta ahí.
		if ( preg_match( '/^(.{60,}[.!?])(?:\s|$)/su', $cut, $frase ) ) {
			return $frase[1];
		}
		$space = strrpos( $cut, ' ' );
		if ( false !== $space ) {
			$antes = substr( $cut, 0, $space );
			if ( mb_strlen( $antes, 'UTF-8' ) > $max * 0.6 ) {
				$cut = $antes;
			}
		}
		return rtrim( $cut, " ,;:.-–—" ) . '…';
	}

	public static function build_description( $product ) {
		$nombre = self::plain( $product['nombre'] );
		$texto  = self::plain( isset( $product['descripcion'] ) ? $product['descripcion'] : '' );

		if ( '' === $texto ) {
			$categoria_slug = isset( $product['category']['slug'] ) ? $product['category']['slug'] : '';
			$categoria      = Catalogo_Distribuidor_Bridge_Store::display_category_nombre( $categoria_slug, isset( $product['category']['nombre'] ) ? $product['category']['nombre'] : '' );
			$texto          = $categoria ? $nombre . ' — ' . $categoria . '.' : $nombre . '.';
			return self::truncate( $texto . ' Conoce tallas, colores y características en el catálogo de ' . get_bloginfo( 'name' ) . '.' );
		}

		// Si el texto no menciona el nombre del producto, se antepone.
		if ( ! preg_match( '/' . preg_quote( $nombre, '/' ) . '/iu', $texto ) ) {
			$texto = $nombre . ': ' . $texto;
		}
		return self::truncate( $texto );
	}

	/** URLs de imágenes del producto: la principal primero, sin repetidas, máx. 6. */
	private static function images( $product ) {
		$urls = array();
		$main = Catalogo_Distribuidor_Bridge_Store::main_image( $product );
		if ( $main ) {
			$urls[ $main ] = true;
		}
		if ( ! empty( $product['media'] ) && is_array( $product['media'] ) ) {
			foreach ( $product['media'] as $m ) {
				if ( ! empty( $m['url'] ) && is_string( $m['url'] ) ) {
					$urls[ $m['url'] ] = true;
				}
			}
		}
		return array_slice( array_keys( $urls ), 0, 6 );
	}

	public static function render_head() {
		$product = self::get_product();
		if ( ! $product ) {
			return;
		}

		$nombre      = self::plain( $product['nombre'] );
		$titulo      = $nombre . ' – ' . get_bloginfo( 'name' );
		$url         = Catalogo_Distribuidor_Bridge_Rewrite::product_url( $product['slug'] );
		$descripcion = self::build_description( $product );
		$imagenes    = self::images( $product );
		$tiene_yoast = defined( 'WPSEO_VERSION' );

		echo "\n<!-- Catálogo Distribuidor Bridge: SEO de la ficha -->\n";
		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta name="description" content="' . esc_attr( $descripcion ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $titulo ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $descripcion ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		if ( ! $tiene_yoast ) {
			echo '<meta property="og:type" content="product" />' . "\n";
			echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		}
		if ( $imagenes ) {
			echo '<meta property="og:image" content="' . esc_url( $imagenes[0] ) . '" />' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $imagenes[0] ) . '" />' . "\n";
		}
		echo '<meta name="twitter:title" content="' . esc_attr( $titulo ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $descripcion ) . '" />' . "\n";

		if ( apply_filters( 'cdb_seo_product_schema', true, $product ) ) {
			$schema = self::build_schema( $product, $nombre, $url, $descripcion, $imagenes );
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
		echo "<!-- / Catálogo Distribuidor Bridge -->\n";
	}

	/** Schema Product, sin precio ni ofertas (los precios son privados). */
	private static function build_schema( $product, $nombre, $url, $descripcion, $imagenes ) {
		$marca          = isset( $product['brand']['nombre'] ) ? self::plain( $product['brand']['nombre'] ) : '';
		$categoria_slug = isset( $product['category']['slug'] ) ? $product['category']['slug'] : '';
		$categoria      = Catalogo_Distribuidor_Bridge_Store::display_category_nombre( $categoria_slug, isset( $product['category']['nombre'] ) ? $product['category']['nombre'] : '' );

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $nombre,
			'url'         => $url,
			'description' => $descripcion,
		);
		if ( $imagenes ) {
			$schema['image'] = $imagenes;
		}
		if ( ! empty( $product['sku'] ) ) {
			$schema['sku'] = self::plain( $product['sku'] );
		}
		if ( $marca ) {
			$schema['brand'] = array( '@type' => 'Brand', 'name' => $marca );
		}
		if ( $categoria ) {
			$schema['category'] = $categoria;
		}
		return $schema;
	}
}
