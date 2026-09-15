<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base de datos LOCAL del catálogo del distribuidor — reemplaza el
 * caché-por-visita (Catalogo_Distribuidor_Bridge_Cache) como fuente para
 * renderizar grid/detalle. La alimenta Catalogo_Distribuidor_Bridge_Sync.
 *
 * A diferencia del plugin público, NO hay tabla de "catálogo curado": el
 * backend ya devuelve solo lo que le corresponde a este distribuidor según
 * su API Key, así que todo lo que hay en esta tabla es, por definición, su
 * catálogo completo.
 *
 * `payload` guarda el producto completo tal cual lo devuelve la API (mismo
 * detalle que get_product_by_slug), para que grid.php y detail.php sigan
 * recibiendo exactamente la misma forma de datos que antes.
 */
class Catalogo_Distribuidor_Bridge_Store {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'catalogo_dist_products';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		dbDelta(
			"CREATE TABLE {$table} (
				product_id VARCHAR(24) NOT NULL,
				sku VARCHAR(191) NOT NULL DEFAULT '',
				slug VARCHAR(191) NOT NULL DEFAULT '',
				nombre VARCHAR(255) NOT NULL DEFAULT '',
				activo TINYINT(1) NOT NULL DEFAULT 1,
				brand_slug VARCHAR(191) NOT NULL DEFAULT '',
				brands_slugs TEXT NULL,
				category_slug VARCHAR(191) NOT NULL DEFAULT '',
				sexo VARCHAR(100) NOT NULL DEFAULT '',
				destacado TINYINT(1) NOT NULL DEFAULT 0,
				payload LONGTEXT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (product_id),
				KEY slug (slug),
				KEY sku (sku),
				KEY brand_slug (brand_slug),
				KEY category_slug (category_slug),
				KEY activo (activo)
			) {$charset_collate};"
		);
	}

	public static function upsert_many( array $products ) {
		global $wpdb;
		if ( empty( $products ) ) {
			return;
		}
		$table = self::table();
		foreach ( $products as $product ) {
			if ( empty( $product['_id'] ) ) {
				continue;
			}
			$brands_slugs = array();
			if ( ! empty( $product['brands'] ) && is_array( $product['brands'] ) ) {
				foreach ( $product['brands'] as $b ) {
					if ( ! empty( $b['slug'] ) ) {
						$brands_slugs[] = $b['slug'];
					}
				}
			}
			$sexo = ( ! empty( $product['sexo'] ) && is_array( $product['sexo'] ) ) ? implode( ',', $product['sexo'] ) : '';

			$wpdb->replace(
				$table,
				array(
					'product_id'    => $product['_id'],
					'sku'           => isset( $product['sku'] ) ? $product['sku'] : '',
					'slug'          => isset( $product['slug'] ) ? $product['slug'] : '',
					'nombre'        => isset( $product['nombre'] ) ? $product['nombre'] : '',
					'activo'        => empty( $product['activo'] ) ? 0 : 1,
					'brand_slug'    => isset( $product['brand']['slug'] ) ? $product['brand']['slug'] : '',
					'brands_slugs'  => ',' . implode( ',', $brands_slugs ) . ',',
					'category_slug' => isset( $product['category']['slug'] ) ? $product['category']['slug'] : '',
					'sexo'          => $sexo,
					'destacado'     => empty( $product['destacado'] ) ? 0 : 1,
					'payload'       => wp_json_encode( $product ),
					'updated_at'    => isset( $product['updatedAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $product['updatedAt'] ) ) : gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/** Borra filas cuyo product_id NO esté en $keep_ids — limpieza de hard-deletes y de productos que salieron del catálogo asignado. */
	public static function prune_missing( array $keep_ids ) {
		global $wpdb;
		$table = self::table();
		if ( empty( $keep_ids ) ) {
			$wpdb->query( "DELETE FROM {$table}" );
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE product_id NOT IN ({$placeholders})", $keep_ids ) ); // phpcs:ignore
	}

	public static function count_active() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE activo = 1' );
	}

	public static function is_empty() {
		global $wpdb;
		return 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/**
	 * Traduce el mismo $args que ya arma el shortcode (brand, category, q,
	 * limit, page) a una consulta contra la tabla local. Devuelve la MISMA
	 * forma normalizada que Catalogo_Distribuidor_Bridge_Client:
	 * ['ok', 'status', 'data', 'pagination', 'message'].
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();
		$where = array( 'activo = 1' );
		$vals  = array();

		if ( ! empty( $args['brand'] ) ) {
			$where[] = 'brands_slugs LIKE %s';
			$vals[]  = '%,' . $wpdb->esc_like( $args['brand'] ) . ',%';
		}
		if ( ! empty( $args['category'] ) ) {
			$slugs = self::raw_slugs_for_filter( $args['category'] );
			if ( count( $slugs ) > 1 ) {
				$where[] = 'category_slug IN (' . implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) . ')';
				foreach ( $slugs as $s ) {
					$vals[] = $s;
				}
			} else {
				$where[] = 'category_slug = %s';
				$vals[]  = $slugs[0];
			}
		}
		if ( ! empty( $args['q'] ) ) {
			$where[] = '(nombre LIKE %s OR sku LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['q'] ) . '%';
			$vals[]  = $like;
			$vals[]  = $like;
		}

		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT payload FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		$prepared_vals = array_merge( $vals, array( $limit, $offset ) );
		$rows          = $wpdb->get_col( $wpdb->prepare( $sql, $prepared_vals ) ); // phpcs:ignore
		$total         = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql ); // phpcs:ignore

		$data = array_map(
			function ( $json ) {
				return json_decode( $json, true );
			},
			$rows
		);

		return array(
			'ok'         => true,
			'status'     => 200,
			'data'       => $data,
			'pagination' => array( 'page' => $page, 'limit' => $limit, 'total' => $total, 'totalPages' => (int) ceil( $total / $limit ) ),
			'message'    => '',
		);
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . self::table() . ' WHERE slug = %s AND activo = 1', $slug ) );
		if ( ! $row ) {
			return array( 'ok' => false, 'status' => 404, 'data' => null, 'pagination' => null, 'message' => 'Producto no encontrado.' );
		}
		return array( 'ok' => true, 'status' => 200, 'data' => json_decode( $row, true ), 'pagination' => null, 'message' => '' );
	}

	/** Primera imagen "principal" del producto (o la primera que haya) — misma regla que usan grid.php y product-card.php. */
	public static function main_image( $product ) {
		if ( empty( $product['media'] ) || ! is_array( $product['media'] ) ) {
			return '';
		}
		foreach ( $product['media'] as $media ) {
			if ( ! empty( $media['principal'] ) && ! empty( $media['url'] ) ) {
				return $media['url'];
			}
		}
		return ! empty( $product['media'][0]['url'] ) ? $product['media'][0]['url'] : '';
	}

	/**
	 * Normaliza el nombre de una categoría a "Primera letra mayúscula, resto
	 * minúsculas" (ej. "PLAYERA POLO MANGA CORTA" -> "Playera polo manga
	 * corta") sin importar cómo venga capitalizado en la API — el catálogo
	 * sincronizado no es consistente en esto (algunas categorías llegan bien,
	 * otras completamente en mayúsculas). Solo se aplica cuando el
	 * distribuidor no puso ya un nombre a mano en Ajustes (ver
	 * get_categories()), así que un nombre corregido manualmente nunca se
	 * vuelve a tocar.
	 */
	private static function format_nombre( $nombre ) {
		$nombre = trim( (string) $nombre );
		if ( '' === $nombre ) {
			return $nombre;
		}

		// Minúsculas sin depender de la extensión mbstring (no todos los
		// hostings la traen habilitada) — strtolower() nativo ya resuelve
		// las letras sin acento; esta tabla cubre las vocales acentuadas y
		// la "ñ" del español, que strtolower() deja intactas por ser
		// multibyte.
		$mayus_a_minus = array( 'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ñ' => 'ñ' );
		$nombre        = strtolower( strtr( $nombre, $mayus_a_minus ) );

		// Primera letra en mayúscula: se extrae de forma segura para UTF-8
		// con PCRE (el modificador "u"), ya que un acento ocupa más de un
		// byte y mb_substr() no siempre está disponible.
		$minus_a_mayus = array_flip( $mayus_a_minus );
		$primera       = preg_replace( '/^(.).*$/us', '$1', $nombre );
		$resto         = preg_replace( '/^./us', '', $nombre );
		$primera       = isset( $minus_a_mayus[ $primera ] ) ? $minus_a_mayus[ $primera ] : strtoupper( $primera );

		return $primera . $resto;
	}

	/**
	 * Árbol de categorías tal cual lo maneja la API central (GET
	 * /categories: cada una con su "parent"), cacheado en un transient
	 * porque casi no cambia y así evitamos una llamada HTTP extra en cada
	 * carga del catálogo. Mapa slug crudo => ['nombre', 'parent_slug'].
	 * Si la API no responde, se cachea un árbol vacío por poco tiempo (en
	 * vez de por un día completo) para reintentar pronto sin machacar la
	 * API mientras tanto — sin romper nada: root_category() simplemente no
	 * encuentra relación y el catálogo se comporta como si esa categoría no
	 * tuviera padre (queda suelta, con su propio botón).
	 */
	private static function categories_tree() {
		$cache_key = 'catalogo_distribuidor_categories_tree';
		$arbol     = get_transient( $cache_key );
		if ( is_array( $arbol ) ) {
			return $arbol;
		}

		$arbol    = array();
		$response = Catalogo_Distribuidor_Bridge_Client::get_categories();
		if ( ! empty( $response['ok'] ) && is_array( $response['data'] ) ) {
			$por_id = array();
			foreach ( $response['data'] as $cat ) {
				if ( ! empty( $cat['_id'] ) ) {
					$por_id[ $cat['_id'] ] = $cat;
				}
			}
			foreach ( $response['data'] as $cat ) {
				if ( empty( $cat['slug'] ) ) {
					continue;
				}
				$parent_slug = '';
				if ( ! empty( $cat['parent'] ) && isset( $por_id[ $cat['parent'] ]['slug'] ) ) {
					$parent_slug = $por_id[ $cat['parent'] ]['slug'];
				}
				$arbol[ $cat['slug'] ] = array(
					'nombre'      => isset( $cat['nombre'] ) ? $cat['nombre'] : $cat['slug'],
					'parent_slug' => $parent_slug,
				);
			}
		}

		set_transient( $cache_key, $arbol, $arbol ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $arbol;
	}

	/**
	 * Categoría raíz real (sin "parent") de un category_slug crudo, según
	 * la jerarquía que maneja la API central — ej. "fleece" es hija de
	 * "Sudaderas" ahí, así que su raíz es "sudaderas"/"Sudaderas". Devuelve
	 * null si el slug ya es raíz, o si la API no trae relación para él
	 * (categoría vieja no sincronizada al árbol, o desconocida): en
	 * cualquiera de esos casos no hay nada que agrupar automáticamente.
	 */
	private static function root_category( $slug ) {
		$arbol = self::categories_tree();
		if ( empty( $arbol[ $slug ] ) ) {
			return null;
		}

		$actual = $slug;
		$visto  = array();
		while ( ! empty( $arbol[ $actual ]['parent_slug'] ) && ! isset( $visto[ $actual ] ) ) {
			$visto[ $actual ] = true;
			$siguiente        = $arbol[ $actual ]['parent_slug'];
			if ( empty( $arbol[ $siguiente ] ) ) {
				break;
			}
			$actual = $siguiente;
		}

		if ( $actual === $slug ) {
			return null; // ya era una categoría raíz, nada que agrupar.
		}

		return array(
			'slug'   => $actual,
			'nombre' => $arbol[ $actual ]['nombre'],
		);
	}

	/**
	 * Slug del GRUPO final (ya fusionado) al que pertenece un category_slug
	 * crudo — el de su categoría raíz real según la jerarquía de la API
	 * (ver root_category()), o el suyo propio si ya es una categoría raíz.
	 * La fusión es siempre automática, según lo que ya maneja la API
	 * central: no hace falta configurar nada a mano para que "fleece",
	 * "hoodie", etc. caigan dentro de "sudaderas". Es la clave con la que
	 * se guardan los overrides de Ajustes (nombre/imagen/orden/oculto) —
	 * uno por grupo, nunca por category_slug crudo.
	 */
	private static function group_key_for( $slug ) {
		$raiz = self::root_category( $slug );
		return $raiz ? $raiz['slug'] : $slug;
	}

	/** Overrides guardados a mano en Ajustes, por GRUPO ya fusionado (ej. "sudaderas", "playeras") — ver group_key_for(). */
	private static function group_overrides() {
		return get_option( 'catalogo_distribuidor_category_overrides', array() );
	}

	/**
	 * Nombre "para mostrar" de una categoría, ya resuelto, en este orden:
	 * el nombre que el distribuidor le puso a mano en Ajustes a su GRUPO
	 * (ver group_key_for() + group_overrides()); si no, el de su categoría
	 * raíz real según la jerarquía de la API (ver root_category()); y si
	 * tampoco hay eso (categoría ya raíz, o desconocida para la API), el
	 * nombre que trae la API pasado por format_nombre(). Punto único de
	 * verdad para que una categoría se vea IGUAL en todos lados —
	 * botones/aside del catálogo (get_categories()), migajero de pan y
	 * meta de la ficha de producto (detail.php), y la categoría de cada
	 * tarjeta (product-card.php) — en vez de que cada plantilla decida por
	 * su cuenta si normaliza o no.
	 */
	public static function display_category_nombre( $slug, $nombre_original = '' ) {
		$overrides = self::group_overrides();
		$override  = isset( $overrides[ self::group_key_for( $slug ) ] ) ? $overrides[ self::group_key_for( $slug ) ] : array();
		if ( ! empty( $override['nombre'] ) ) {
			return $override['nombre'];
		}
		$raiz = self::root_category( $slug );
		if ( $raiz ) {
			return self::format_nombre( $raiz['nombre'] );
		}
		return self::format_nombre( $nombre_original );
	}

	/**
	 * Slug "de filtro" de una categoría cruda: el de su grupo ya fusionado
	 * (ver group_key_for()). Lo usan los enlaces que parten de un producto
	 * individual (migajero de pan) para apuntar siempre al mismo filtro que
	 * usan los botones del catálogo, en vez de a la categoría cruda de ese
	 * producto.
	 */
	public static function filter_slug_for( $slug ) {
		return self::group_key_for( $slug );
	}

	/** Todos los category_slug crudos que corresponden a un slug de filtro (su grupo ya fusionado) — lo usa query() para armar el WHERE. */
	private static function raw_slugs_for_filter( $requested_slug ) {
		global $wpdb;
		$table = self::table();
		$slugs = $wpdb->get_col( "SELECT DISTINCT category_slug FROM {$table} WHERE category_slug != ''" );

		$matches = array();
		foreach ( $slugs as $raw ) {
			if ( self::group_key_for( $raw ) === $requested_slug ) {
				$matches[] = $raw;
			}
		}
		return $matches ? $matches : array( $requested_slug );
	}

	/**
	 * Categorías CRUDAS presentes en el catálogo local — una fila por
	 * category_slug tal cual llega de la API — con conteo de productos
	 * activos, imagen representativa, nombre ya resuelto (agrupado) y la
	 * clave de su grupo final. Es el insumo de get_categories(), que junta
	 * estas filas por grupo.
	 */
	private static function get_categories_raw() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT category_slug, COUNT(*) AS total FROM {$table} WHERE activo = 1 AND category_slug != '' GROUP BY category_slug ORDER BY category_slug ASC",
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}

		$categorias = array();
		foreach ( $rows as $row ) {
			$slug    = $row['category_slug'];
			$payload = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT payload FROM {$table} WHERE category_slug = %s AND activo = 1 ORDER BY updated_at DESC LIMIT 1",
					$slug
				)
			);
			$product = $payload ? json_decode( $payload, true ) : null;
			$nombre  = isset( $product['category']['nombre'] ) ? $product['category']['nombre'] : $slug;

			$categorias[] = array(
				'slug'   => $slug,
				'nombre' => self::display_category_nombre( $slug, $nombre ),
				'total'  => (int) $row['total'],
				'imagen' => $product ? self::main_image( $product ) : '',
				'grupo'  => self::group_key_for( $slug ),
			);
		}

		return $categorias;
	}

	/**
	 * Categorías del catálogo YA FUSIONADAS por su grupo real (ver
	 * group_key_for()) — con conteo de productos activos y una imagen
	 * representativa. Alimenta el bloque de "categorías" (botones grandes)
	 * y el filtro del aside en grid.php, y también el panel de Ajustes
	 * (render_categories_block()): una fila por cada categoría que el
	 * visitante realmente ve, no por category_slug crudo. El catálogo no
	 * trae precios (ver readme), así que no hay equivalente a un filtro de
	 * precio: categorías es el filtro relevante aquí.
	 *
	 * El nombre, la imagen, el orden y si se muestra o no cada categoría se
	 * pueden reemplazar/fijar desde Ajustes → Catálogo Distribuidor — ver
	 * Catalogo_Distribuidor_Bridge_Settings::render_categories_block(). Sin
	 * un orden fijado a mano, se mantiene el orden alfabético.
	 *
	 * @param bool $incluir_ocultas Si es true, incluye también las
	 *             categorías marcadas como "ocultar" — solo lo usa el panel
	 *             de administración. El catálogo público (grid.php) siempre
	 *             las omite.
	 */
	public static function get_categories( $incluir_ocultas = false ) {
		$crudas = self::get_categories_raw();
		if ( ! $crudas ) {
			return array();
		}

		$grupos = array();
		foreach ( $crudas as $cruda ) {
			$key = $cruda['grupo'];

			if ( ! isset( $grupos[ $key ] ) ) {
				$grupos[ $key ] = array(
					'slug'   => $key,
					'nombre' => $cruda['nombre'], // Ya resuelto (con override de nombre incluido) por display_category_nombre().
					'total'  => 0,
					'imagen' => '',
				);
			}

			$grupos[ $key ]['total'] += $cruda['total'];
			if ( '' === $grupos[ $key ]['imagen'] && '' !== $cruda['imagen'] ) {
				$grupos[ $key ]['imagen'] = $cruda['imagen'];
			}
		}

		$overrides  = self::group_overrides();
		$categorias = array();
		foreach ( $grupos as $key => $grupo ) {
			$override     = isset( $overrides[ $key ] ) ? $overrides[ $key ] : array();
			$categorias[] = array(
				'slug'   => $key,
				'nombre' => $grupo['nombre'],
				'total'  => $grupo['total'],
				'imagen' => ! empty( $override['imagen'] ) ? $override['imagen'] : $grupo['imagen'],
				'orden'  => isset( $override['orden'] ) && '' !== $override['orden'] ? (int) $override['orden'] : null,
				'oculto' => ! empty( $override['oculto'] ),
			);
		}

		if ( ! $incluir_ocultas ) {
			$categorias = array_values(
				array_filter(
					$categorias,
					function ( $cat ) {
						return ! $cat['oculto'];
					}
				)
			);
		}

		// Las que tienen un orden fijado a mano van primero, en ese orden;
		// las que no, se quedan al final por orden alfabético — mismo
		// criterio de siempre para las que nadie tocó. usort() no garantiza
		// ser estable antes de PHP 8.0 (mínimo de este plugin: 7.4), así que
		// el desempate alfabético va explícito en el comparador en vez de
		// confiar en el orden de llegada.
		usort(
			$categorias,
			function ( $a, $b ) {
				$orden_a = null !== $a['orden'] ? $a['orden'] : PHP_INT_MAX;
				$orden_b = null !== $b['orden'] ? $b['orden'] : PHP_INT_MAX;
				if ( $orden_a === $orden_b ) {
					return strcasecmp( $a['nombre'], $b['nombre'] );
				}
				return $orden_a <=> $orden_b;
			}
		);

		return $categorias;
	}

	/**
	 * Productos marcados como "destacado" en el panel admin — para el bloque
	 * "Productos destacados" del aside. Si el distribuidor todavía no marcó
	 * ninguno como destacado, el bloque no debe desaparecer sin explicación:
	 * se completa con los productos más recientes en su lugar.
	 */
	public static function get_featured( $limit = 4 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE activo = 1 AND destacado = 1 ORDER BY updated_at DESC LIMIT %d",
				$limit
			)
		);

		if ( ! $rows ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT payload FROM {$table} WHERE activo = 1 ORDER BY updated_at DESC LIMIT %d",
					$limit
				)
			);
		}

		return array_map(
			function ( $json ) {
				return json_decode( $json, true );
			},
			$rows
		);
	}

	/** Otros productos activos de la misma categoría — para "Productos relacionados" en la ficha de producto. */
	public static function get_related( $category_slug, $exclude_id, $limit = 4 ) {
		global $wpdb;
		if ( empty( $category_slug ) ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload FROM {$table} WHERE activo = 1 AND category_slug = %s AND product_id != %s ORDER BY updated_at DESC LIMIT %d",
				$category_slug,
				(string) $exclude_id,
				max( 1, (int) $limit )
			)
		);
		return array_map(
			function ( $json ) {
				return json_decode( $json, true );
			},
			$rows
		);
	}
}
