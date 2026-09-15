<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Desde la base de datos local (Catalogo_Distribuidor_Bridge_Store), esta
 * clase ya NO es el camino normal para servir el catálogo — solo se usa como
 * bootstrap de UNA sola llamada en vivo cuando la tabla local todavía está
 * vacía (justo después de activar el plugin o registrar la API Key, antes
 * del primer sync). Se deja intacta por si algún tema personalizado la sigue
 * llamando directamente.
 *
 * Caché en dos capas sobre las llamadas a la API — ambas viven en la base de
 * datos de WordPress (tabla wp_options), nunca en memoria del proceso.
 * Prefijo de clave distinto ("catalogo_dist_...") para que nunca choque con
 * el del catálogo público, por si algún día ambos plugins conviven en el
 * mismo sitio.
 *
 *  1. "Fresca" (transient, expira a los TTL segundos): si existe, se usa tal
 *     cual y no se llama a la API. Esto es lo que evita que cada visita a
 *     cada uno de los sitios de distribuidores que consumen esta API
 *     dispare una llamada en vivo — con varios distribuidores usando el
 *     plugin, esto reduce muchísimo la carga sobre la API central.
 *
 *  2. "De respaldo" (option normal, sin expiración, autoload=false para no
 *     inflar cada carga de página con datos que casi nunca se leen): se
 *     actualiza cada vez que la API responde bien. Si la API llega a fallar
 *     y no hay copia fresca disponible, se usa esta en vez de mostrarle un
 *     error al distribuidor — su catálogo sigue funcionando con el último
 *     dato bueno conocido, aunque el backend esté abajo.
 */
class Catalogo_Distribuidor_Bridge_Cache {

	const TTL = 3600; // 1 hora — el catálogo no cambia minuto a minuto.

	/**
	 * Devuelve el valor cacheado en $key, o ejecuta $fetch(), lo cachea (solo
	 * si fue exitoso) y lo devuelve. Si $fetch() falla y hay una copia de
	 * respaldo previa, se usa esa en vez de propagar el error.
	 */
	public static function remember( $key, callable $fetch ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $fetch();

		if ( ! empty( $result['ok'] ) ) {
			set_transient( $key, $result, self::TTL );
			update_option( self::backup_key( $key ), $result, false );
			return $result;
		}

		// Solo se usa el respaldo cuando la API está realmente caída/inalcanzable
		// (status 0 = no se pudo conectar, o API Key sin configurar; 5xx = el
		// servidor respondió con un error). Un 404 ("no encontrado") o un 401
		// ("key inválida/revocada") son respuestas VÁLIDAS de la API — mostrarlas
		// es lo correcto, no hay que taparlas con datos viejos.
		$status = isset( $result['status'] ) ? (int) $result['status'] : 0;
		if ( 0 === $status || $status >= 500 ) {
			$backup = get_option( self::backup_key( $key ), false );
			if ( false !== $backup ) {
				return $backup;
			}
		}

		return $result;
	}

	private static function backup_key( $key ) {
		return $key . '_bak';
	}

	public static function key_for_products( array $args ) {
		ksort( $args );
		return 'catalogo_dist_products_' . md5( serialize( $args ) );
	}

	public static function key_for_product_slug( $slug ) {
		return 'catalogo_dist_product_' . md5( (string) $slug );
	}
}
