<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [catalogo_distribuidor marca="" categoria="" limite="12" estilo="cuadricula"]
 *
 * A diferencia del shortcode público, NO tiene atributo "catalogo": el
 * catálogo de un distribuidor lo decide el backend a partir de su API Key,
 * nunca un parámetro que este shortcode pudiera mandar.
 *
 * "estilo" SÍ es decisión del distribuidor (no toca qué productos ve, solo
 * cómo se acomodan): cuadricula (por defecto), lista o compacta. No afecta
 * la llamada a la API ni su caché — solo cambia clases CSS en el render.
 */
class Catalogo_Distribuidor_Bridge_Shortcodes {

	const ESTILOS_VALIDOS = array( 'cuadricula', 'lista', 'compacta' );

	public static function init() {
		add_shortcode( 'catalogo_distribuidor', array( __CLASS__, 'render_grid' ) );
	}

	public static function render_grid( $atts ) {
		$atts = shortcode_atts(
			array(
				'marca'     => '',
				'categoria' => '',
				'categoria_inicial' => '',
				'orden'     => '',
				'limite'    => 12,
				'estilo'    => 'cuadricula',
			),
			$atts,
			'catalogo_distribuidor'
		);

		// Categoría fija por el dueño de la página (atributo del shortcode). Si
		// no fijó ninguna, el visitante puede filtrar por categoría desde los
		// botones/aside sin salir de la página (?cdb_categoria=slug) — un
		// simple recargado con GET, sin JS, igual de espíritu que el resto del
		// plugin (server-rendered).
		//
		// `categoria_inicial` (ej. "chamarras"): la que se ve AL ENTRAR, sin
		// fijarla — el visitante puede cambiar a otra, y "Quitar filtro" lleva a
		// ?cdb_categoria=todas para ver todo el catálogo (sin ese valor especial,
		// quitar el filtro volvería a caer en la categoría inicial).
		$categoria_fija    = sanitize_title( $atts['categoria'] );
		$categoria_inicial = sanitize_title( $atts['categoria_inicial'] );
		$categoria_actual  = $categoria_fija;
		if ( empty( $categoria_fija ) ) {
			$pedida = isset( $_GET['cdb_categoria'] ) ? sanitize_title( wp_unslash( $_GET['cdb_categoria'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'todas' === $pedida ) {
				$categoria_actual = '';
			} elseif ( '' !== $pedida ) {
				$categoria_actual = $pedida;
			} else {
				$categoria_actual = $categoria_inicial;
			}
		}

		// Página actual del listado (?cdb_pagina=N), mismo patrón sin JS que el
		// filtro de categoría. "limite" solo controla cuántos productos se ven
		// POR PÁGINA — con esta paginación, ningún producto del catálogo
		// asignado queda fuera de alcance sin importar cuántos tenga el
		// distribuidor, sin que tenga que configurar nada.
		$pagina_actual = isset( $_GET['cdb_pagina'] ) ? max( 1, (int) $_GET['cdb_pagina'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'brand'    => sanitize_title( $atts['marca'] ),
			'category' => $categoria_actual,
			'limit'    => max( 1, min( 100, (int) $atts['limite'] ) ),
			'page'     => $pagina_actual,
			// Orden manual (ej. orden="shell, atractive, hydro, reaction"): esos
			// productos primero y en ese orden, el resto después. Se aplica en la
			// consulta, así que la paginación lo respeta.
			'orden'    => Catalogo_Distribuidor_Bridge_Store::parse_orden( $atts['orden'] ),
		);

		// Valor desconocido (typo del distribuidor, etc.) cae a "cuadricula"
		// en vez de romper el render o dejar pasar una clase CSS arbitraria.
		$estilo = in_array( $atts['estilo'], self::ESTILOS_VALIDOS, true ) ? $atts['estilo'] : 'cuadricula';

		$catalogo_vacio = Catalogo_Distribuidor_Bridge_Store::is_empty();

		$result = $catalogo_vacio
			? Catalogo_Distribuidor_Bridge_Cache::remember( Catalogo_Distribuidor_Bridge_Cache::key_for_products( $args ), function () use ( $args ) {
				return Catalogo_Distribuidor_Bridge_Client::get_products( $args );
			} )
			: Catalogo_Distribuidor_Bridge_Store::query( $args );

		// Categorías (botones + aside) y destacados: solo tienen sentido sobre
		// la BD local ya sincronizada — si el sitio apenas se activó y todavía
		// está vacía, se omiten en vez de mostrar bloques huecos.
		$categorias            = $catalogo_vacio ? array() : Catalogo_Distribuidor_Bridge_Store::get_categories();
		$destacados            = $catalogo_vacio ? array() : Catalogo_Distribuidor_Bridge_Store::get_featured( 3 );
		$categoria_fija_activa = $categoria_fija;

		ob_start();
		include Catalogo_Distribuidor_Bridge_Templates::locate( 'grid.php' );
		return ob_get_clean();
	}
}
