<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ficha de producto — misma interacción que ProductoDetalle.jsx (sitio React)
 * y que catalogo-api-bridge: selección de género/color/talla resuelve una
 * variante y actualiza galería, composición y SKU en vivo, vía
 * assets/js/detail.js. El PHP solo renderiza el estado INICIAL (primer
 * valor de cada eje) y deja embebidos los datos crudos del producto en un
 * <script type="application/json"> para que el JS pueda recalcular sin
 * volver a pedirle nada a la API.
 */

$slug   = get_query_var( Catalogo_Distribuidor_Bridge_Rewrite::QUERY_VAR );
$result = Catalogo_Distribuidor_Bridge_Store::is_empty()
	? Catalogo_Distribuidor_Bridge_Cache::remember( Catalogo_Distribuidor_Bridge_Cache::key_for_product_slug( $slug ), function () use ( $slug ) {
		return Catalogo_Distribuidor_Bridge_Client::get_product_by_slug( $slug );
	} )
	: Catalogo_Distribuidor_Bridge_Store::get_by_slug( $slug );

if ( empty( $result['ok'] ) && 404 === $result['status'] ) {
	status_header( 404 );
}

get_header();

if ( empty( $result['ok'] ) ) :
	?>
	<main class="catalogo-dist-bridge-detail-wrap">
		<?php include Catalogo_Distribuidor_Bridge_Templates::locate( 'error.php' ); ?>
	</main>
	<?php
	get_footer();
	return;
endif;

$product = $result['data'];

$sexo_label = array( 'hombre' => 'Caballero', 'mujer' => 'Dama', 'unisex' => 'Unisex' );

$nombre      = isset( $product['nombre'] ) ? $product['nombre'] : '';
$marca       = isset( $product['brand']['nombre'] ) ? $product['brand']['nombre'] : '';
$categoria_slug = isset( $product['category']['slug'] ) ? $product['category']['slug'] : '';
// Mismo nombre "para mostrar" que ya usan los botones/aside del catálogo:
// el corregido a mano en Ajustes si existe, o si no, normalizado a
// "Primera letra mayúscula, resto minúsculas" — la API no es consistente
// en cómo llega capitalizado.
$categoria   = Catalogo_Distribuidor_Bridge_Store::display_category_nombre( $categoria_slug, isset( $product['category']['nombre'] ) ? $product['category']['nombre'] : '' );
$catalogo_url   = Catalogo_Distribuidor_Bridge_Rewrite::catalog_url();
$sku         = isset( $product['sku'] ) ? $product['sku'] : '';
$descripcion = isset( $product['descripcion'] ) ? $product['descripcion'] : '';
$activo      = ! empty( $product['activo'] );
$sexos       = ( ! empty( $product['sexo'] ) && is_array( $product['sexo'] ) ) ? $product['sexo'] : array();
$options     = ( ! empty( $product['options'] ) && is_array( $product['options'] ) ) ? $product['options'] : array();
$variants    = ( ! empty( $product['variants'] ) && is_array( $product['variants'] ) ) ? $product['variants'] : array();
$applications = ( ! empty( $product['applications'] ) && is_array( $product['applications'] ) ) ? $product['applications'] : array();
$features    = ( ! empty( $product['features'] ) && is_array( $product['features'] ) ) ? $product['features'] : array();
$attributes  = ( ! empty( $product['attributes'] ) && is_array( $product['attributes'] ) ) ? $product['attributes'] : array();
// Tabla de medidas: si el producto combina hombre y mujer con cortes
// distintos, puede traer una tabla POR género además de (u opcional en vez
// de) la general — la ficha muestra la que corresponde al género
// seleccionado, con el mismo selector que ya cambia color/talla/galería.
$size_chart_default = ( ! empty( $product['sizeChart']['rows'] ) ) ? $product['sizeChart'] : null;
$size_chart_hombre  = ( ! empty( $product['sizeChartHombre']['rows'] ) ) ? $product['sizeChartHombre'] : null;
$size_chart_mujer   = ( ! empty( $product['sizeChartMujer']['rows'] ) ) ? $product['sizeChartMujer'] : null;
$faq         = ( ! empty( $product['faq'] ) && is_array( $product['faq'] ) ) ? $product['faq'] : array();

// --- Selección inicial: primer valor de cada eje + primer género ---
$selected = array();
foreach ( $options as $o ) {
	$opt_id = isset( $o['option']['_id'] ) ? $o['option']['_id'] : null;
	$first_val = isset( $o['values'][0]['_id'] ) ? $o['values'][0]['_id'] : null;
	if ( $opt_id && $first_val ) {
		$selected[ $opt_id ] = $first_val;
	}
}
$sel_sexo = isset( $sexos[0] ) ? $sexos[0] : null;

/**
 * Pinta una sola tabla de medidas ($chart = ['unidad','columns','rows'])
 * dentro de la guía de tallas. TRANSPUESTA a propósito respecto a como llega
 * el dato ($chart['rows'] trae una fila por talla): la columna de tallas
 * (XCH, CH, M...) se puede volver larga (hasta 3XG y más), y esta tabla vive
 * en una columna angosta de la ficha — puesta así, cada talla necesitaba su
 * propia fila y la tabla se hacía muy alta con scroll horizontal corto. En
 * fila (una talla por columna) aprovecha mejor el ancho disponible y se
 * lee más como una guía de tallas típica.
 */
function cdb_render_size_table( $chart ) {
	if ( empty( $chart['rows'] ) ) {
		return;
	}
	$columns = (array) ( $chart['columns'] ?? array() );
	?>
	<div class="cdb-table-scroll">
		<table>
			<thead>
				<tr>
					<th>Talla</th>
					<?php foreach ( $chart['rows'] as $row ) : ?><th><?php echo esc_html( $row['label'] ?? '' ); ?></th><?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $columns as $ci => $c ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $c ); ?></strong></td>
						<?php foreach ( $chart['rows'] as $row ) : ?>
							<td><?php echo esc_html( $row['values'][ $ci ] ?? '—' ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function cdb_find_variant( $variants, $selected ) {
	$selected_ids = array_values( $selected );
	sort( $selected_ids );
	foreach ( $variants as $v ) {
		$ids = array_map(
			function ( $ov ) {
				return isset( $ov['_id'] ) ? $ov['_id'] : null;
			},
			isset( $v['optionValues'] ) && is_array( $v['optionValues'] ) ? $v['optionValues'] : array()
		);
		sort( $ids );
		if ( $ids === $selected_ids ) {
			return $v;
		}
	}
	return null;
}
$variant = cdb_find_variant( $variants, $selected );

// Eje de color, si el producto tiene uno — mismo criterio que ya usa el
// bloque de swatches más abajo. Se necesita porque las fotos de color NO
// viven dentro de cada variante (las variantes de esta API casi nunca
// traen su propio "media") — viven en el arreglo de fotos del PRODUCTO,
// cada una etiquetada con el _id del valor de color al que pertenece
// ($m['optionValue']), igual patrón que $m['sexo'] para género.
$color_option_id = null;
foreach ( $options as $o ) {
	$opt_tipo = isset( $o['option']['tipo'] ) ? $o['option']['tipo'] : '';
	$opt_slug = isset( $o['option']['slug'] ) ? $o['option']['slug'] : '';
	$opt_name = isset( $o['option']['nombre'] ) ? $o['option']['nombre'] : '';
	if ( ( 'swatch' === $opt_tipo ) || preg_match( '/color/i', $opt_slug ) || preg_match( '/color/i', $opt_name ) ) {
		$color_option_id = isset( $o['option']['_id'] ) ? $o['option']['_id'] : null;
		break;
	}
}
$selected_color_value_id = ( $color_option_id && ! empty( $selected[ $color_option_id ] ) ) ? $selected[ $color_option_id ] : null;

function cdb_dedupe_media( $media ) {
	$seen = array();
	$out  = array();
	foreach ( (array) $media as $m ) {
		if ( ! empty( $m['url'] ) && empty( $seen[ $m['url'] ] ) ) {
			$seen[ $m['url'] ] = true;
			$out[] = $m;
		}
	}
	return $out;
}
// Filtra por género usando la etiqueta real de cada foto ($m['sexo'], puesta
// a mano en el panel admin) — las fotos sin etiquetar sirven para cualquier
// género, así que nunca desaparecen por no marcarse. El JS recalcula esto
// mismo (filterGenero en detail.js) al cambiar de género.
function cdb_filter_genero( $imgs, $sexo ) {
	if ( ! $sexo ) {
		return $imgs;
	}
	$propias   = array_values( array_filter( $imgs, function ( $m ) use ( $sexo ) { return isset( $m['sexo'] ) && $m['sexo'] === $sexo; } ) );
	$genericas = array_values( array_filter( $imgs, function ( $m ) { return empty( $m['sexo'] ); } ) );
	$out       = array_merge( $propias, $genericas );
	return $out ? $out : $imgs;
}

// Filtra por color usando la etiqueta real de cada foto ($m['optionValue'],
// el _id del valor de color al que pertenece esa foto) — mismo criterio que
// cdb_filter_genero, solo que por color en vez de género. El JS recalcula
// esto mismo (filterColor en detail.js) al cambiar de color.
function cdb_filter_color( $imgs, $color_value_id ) {
	if ( ! $color_value_id ) {
		return $imgs;
	}
	$propias   = array_values( array_filter( $imgs, function ( $m ) use ( $color_value_id ) { return isset( $m['optionValue'] ) && $m['optionValue'] === $color_value_id; } ) );
	$genericas = array_values( array_filter( $imgs, function ( $m ) { return empty( $m['optionValue'] ); } ) );
	$out       = array_merge( $propias, $genericas );
	return $out ? $out : $imgs;
}

// Las variantes de esta API casi nunca traen su propio "media" — cuando sí
// lo traen (otro catálogo podría hacerlo distinto), se respeta tal cual;
// si no, se usan las fotos del producto filtradas primero por color y
// luego por género.
$variant_media       = $variant && ! empty( $variant['media'] ) ? cdb_dedupe_media( $variant['media'] ) : array();
$product_media       = cdb_dedupe_media( isset( $product['media'] ) ? $product['media'] : array() );
$product_media_color = cdb_filter_color( $product_media, $selected_color_value_id );
$images              = cdb_filter_genero( $variant_media ? $variant_media : $product_media_color, $sel_sexo );
$main_img            = isset( $images[0] ) ? $images[0] : null;

$disponible = $activo && ( ! $variant || false !== $variant['activo'] );

$js_data = array(
	'sexoLabel'    => $sexo_label,
	'sexos'        => $sexos,
	'options'      => $options,
	'variants'     => $variants,
	'productMedia' => $product['media'] ?? array(),
	'nombre'       => $nombre,
	'activo'       => $activo,
);
?>
<main class="catalogo-dist-bridge-detail-wrap">
	<nav class="catalogo-dist-bridge-breadcrumb">
		<a href="<?php echo esc_url( $catalogo_url ); ?>">Catálogo</a>
		<?php if ( $categoria ) : ?>
			<span>/</span>
			<?php if ( $categoria_slug ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'cdb_categoria', Catalogo_Distribuidor_Bridge_Store::filter_slug_for( $categoria_slug ), $catalogo_url ) ); ?>"><?php echo esc_html( $categoria ); ?></a>
			<?php else : ?>
				<span><?php echo esc_html( $categoria ); ?></span>
			<?php endif; ?>
		<?php endif; ?>
	</nav>

	<?php
	$galeria_layout = get_option( 'catalogo_distribuidor_galeria_layout', 'abajo' );

	// Íconos SVG inline (sin librería externa, sin depender del tema) — se
	// duplican en detail.js con el mismo trazo, mismo criterio que
	// class-colors.php para que este plugin no dependa de nada más.
	$cdb_icon_zoom  = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
	$cdb_icon_close = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
	$cdb_icon_prev  = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';
	$cdb_icon_next  = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
	?>
	<article class="catalogo-dist-bridge-detail" data-product='<?php echo wp_json_encode( $js_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?>'>
		<?php if ( 'izquierda' === $galeria_layout ) : ?>
			<!-- Galería clásica: miniaturas en columna a la izquierda + imagen
				 principal grande a la derecha, con flechas prev/next, una lupa que
				 abre la foto actual a pantalla completa (mismo lightbox que usa el
				 layout mosaico) y puntos de paginación debajo — mismo acomodo que
				 una ficha de producto de tienda en línea típica. El contenedor de
				 miniaturas SIEMPRE ocupa su espacio en el layout (nunca
				 display:none) aunque la variante seleccionada tenga 1 sola foto —
				 así la columna de miniaturas reserva su ancho siempre y la imagen
				 principal no cambia de tamaño/posición al cambiar de color o
				 variante. Si no hay miniaturas que mostrar, el contenedor queda
				 vacío y .cdb-thumbs:empty (en catalogo.css) lo oculta visualmente
				 sin quitarle el espacio reservado. -->
			<div class="catalogo-dist-bridge-detail-gallery catalogo-dist-bridge-detail-gallery--izquierda">
				<div class="cdb-thumbs" data-role="gallery-thumbs">
					<?php foreach ( $images as $i => $im ) : ?>
						<button type="button" class="cdb-thumb<?php echo 0 === $i ? ' active' : ''; ?>" data-idx="<?php echo (int) $i; ?>">
							<img src="<?php echo esc_url( $im['url'] ); ?>" alt="" />
						</button>
					<?php endforeach; ?>
				</div>
				<div class="cdb-main-col">
					<div class="cdb-main-img">
						<span class="cdb-main-img-frame" data-role="main-img-frame">
							<?php if ( $main_img ) : ?>
								<img src="<?php echo esc_url( $main_img['url'] ); ?>" alt="<?php echo esc_attr( $nombre ); ?>" class="cdb-main-img-el" />
							<?php else : ?>
								<div class="catalogo-dist-bridge-no-img">Sin imagen</div>
							<?php endif; ?>
						</span>
						<button type="button" class="cdb-gallery-arrow cdb-gallery-prev" data-role="gallery-prev" aria-label="Anterior" style="<?php echo count( $images ) > 1 ? '' : 'display:none'; ?>"><?php echo $cdb_icon_prev; // phpcs:ignore ?></button>
						<button type="button" class="cdb-gallery-arrow cdb-gallery-next" data-role="gallery-next" aria-label="Siguiente" style="<?php echo count( $images ) > 1 ? '' : 'display:none'; ?>"><?php echo $cdb_icon_next; // phpcs:ignore ?></button>
						<button type="button" class="cdb-gallery-expand" data-role="gallery-expand" aria-label="Ver a pantalla completa" style="<?php echo $main_img ? '' : 'display:none'; ?>"><?php echo $cdb_icon_zoom; // phpcs:ignore ?></button>
					</div>
					<div class="cdb-gallery-dots" data-role="gallery-dots">
						<?php foreach ( $images as $i => $im ) : ?>
							<button type="button" class="cdb-gallery-dot<?php echo 0 === $i ? ' active' : ''; ?>" data-idx="<?php echo (int) $i; ?>" aria-label="Foto <?php echo (int) ( $i + 1 ); ?>"></button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php else : ?>
			<!-- Galería mosaico: todas las fotos visibles a la vez, en vez de
				 una principal + miniaturas — con zoom a pantalla completa al
				 hacer clic (misma idea que el acercamiento con lupa de una
				 tienda tipo mayorista/uniformes). -->
			<div class="catalogo-dist-bridge-detail-gallery cdb-gallery-mosaic">
				<?php if ( $images ) : ?>
					<div class="cdb-mosaic" data-role="gallery-mosaic">
						<?php foreach ( $images as $i => $im ) : ?>
							<button type="button" class="cdb-mosaic-item" data-idx="<?php echo (int) $i; ?>">
								<img src="<?php echo esc_url( $im['url'] ); ?>" alt="<?php echo esc_attr( $nombre ); ?>" loading="<?php echo 0 === $i ? 'eager' : 'lazy'; ?>" />
								<span class="cdb-mosaic-zoom" aria-hidden="true"><?php echo $cdb_icon_zoom; // phpcs:ignore ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="catalogo-dist-bridge-no-img">Sin imagen</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<!-- Lightbox: se renderiza una sola vez sin importar el layout de
			 galería elegido — tanto la lupa del layout clásico como el clic
			 sobre una foto del mosaico abren este mismo overlay. -->
		<div class="cdb-lightbox" data-role="lightbox">
			<button type="button" class="cdb-lightbox-close" data-role="lightbox-close" aria-label="Cerrar"><?php echo $cdb_icon_close; // phpcs:ignore ?></button>
			<button type="button" class="cdb-lightbox-nav cdb-lightbox-prev" data-role="lightbox-prev" aria-label="Anterior"><?php echo $cdb_icon_prev; // phpcs:ignore ?></button>
			<img class="cdb-lightbox-img" data-role="lightbox-img" src="" alt="" />
			<button type="button" class="cdb-lightbox-nav cdb-lightbox-next" data-role="lightbox-next" aria-label="Siguiente"><?php echo $cdb_icon_next; // phpcs:ignore ?></button>
		</div>

		<div class="catalogo-dist-bridge-detail-info">
			<a href="<?php echo esc_url( $catalogo_url ); ?>" class="cdb-back-btn">&larr; Regresar al catálogo</a>
			<div class="cdb-top-row">
				<?php if ( $marca ) : ?><span class="catalogo-dist-bridge-detail-brand"><?php echo esc_html( strtoupper( $marca ) ); ?></span><?php endif; ?>
				<span class="cdb-badge-disponible" style="<?php echo $disponible ? '' : 'display:none'; ?>">● Disponible</span>
			</div>

			<h1 class="catalogo-dist-bridge-detail-title"><?php echo esc_html( $nombre ); ?></h1>
			<p class="catalogo-dist-bridge-detail-meta">
				<?php echo esc_html( $categoria ); ?>
				<?php if ( $sku ) : ?> &middot; SKU <span class="cdb-sku-text"><?php echo esc_html( $sku ); ?></span><?php endif; ?>
			</p>

			<?php
			$whatsapp_numero = get_option( 'catalogo_distribuidor_whatsapp_numero', '' );
			if ( $whatsapp_numero ) :
				$whatsapp_url = Catalogo_Distribuidor_Bridge_Rewrite::build_whatsapp_quote_url( $whatsapp_numero, $nombre, $sku );
				?>
				<a href="<?php echo esc_url( $whatsapp_url ); ?>" class="cdb-whatsapp-btn" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 32 32" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M16.004 3C9.373 3 4 8.373 4 15.004c0 2.386.7 4.61 1.91 6.478L4 29l7.71-1.874A11.94 11.94 0 0 0 16.004 27c6.631 0 12.001-5.373 12.001-12.004C28.005 8.373 22.635 3 16.004 3zm0 21.818a9.78 9.78 0 0 1-5.031-1.386l-.361-.214-4.575 1.112 1.132-4.462-.236-.376a9.767 9.767 0 0 1-1.5-5.204c0-5.417 4.408-9.825 9.826-9.825 2.625 0 5.093 1.023 6.949 2.879a9.756 9.756 0 0 1 2.876 6.951c0 5.417-4.408 9.826-9.825 9.826zm5.393-7.35c-.296-.148-1.75-.864-2.021-.963-.271-.099-.469-.148-.667.148-.198.297-.766.963-.939 1.161-.173.198-.346.223-.642.074-.296-.148-1.25-.461-2.381-1.469-.88-.785-1.475-1.755-1.648-2.052-.173-.297-.019-.457.13-.605.134-.133.297-.346.445-.519.148-.173.198-.297.297-.495.099-.198.05-.372-.025-.52-.074-.148-.667-1.607-.914-2.202-.241-.579-.486-.5-.667-.51l-.568-.01c-.198 0-.52.074-.792.372-.271.297-1.038 1.014-1.038 2.473 0 1.459 1.063 2.868 1.211 3.066.148.198 2.093 3.196 5.073 4.481.709.306 1.262.489 1.693.626.711.226 1.358.194 1.87.118.57-.085 1.75-.716 1.997-1.408.247-.692.247-1.285.173-1.408-.074-.124-.271-.198-.568-.346z"/></svg>
					Cotizar por WhatsApp
				</a>
			<?php endif; ?>

			<?php if ( $sexos ) : ?>
				<div class="cdb-section">
					<div class="cdb-section-head">
						<p class="cdb-section-title">Género</p>
						<span class="cdb-section-value" data-role="genero-value"><?php echo esc_html( isset( $sexo_label[ $sel_sexo ] ) ? $sexo_label[ $sel_sexo ] : $sel_sexo ); ?></span>
					</div>
					<div class="cdb-pills">
						<?php foreach ( $sexos as $s ) : ?>
							<button type="button" class="cdb-pill cdb-genero-btn<?php echo $s === $sel_sexo ? ' active' : ''; ?>" data-sexo="<?php echo esc_attr( $s ); ?>">
								<?php echo esc_html( isset( $sexo_label[ $s ] ) ? $sexo_label[ $s ] : $s ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $options as $o ) :
				$opt_id   = isset( $o['option']['_id'] ) ? $o['option']['_id'] : '';
				$opt_name = isset( $o['option']['nombre'] ) ? $o['option']['nombre'] : '';
				$opt_tipo = isset( $o['option']['tipo'] ) ? $o['option']['tipo'] : '';
				$opt_slug = isset( $o['option']['slug'] ) ? $o['option']['slug'] : '';
				$is_color = ( 'swatch' === $opt_tipo ) || preg_match( '/color/i', $opt_slug ) || preg_match( '/color/i', $opt_name );
				$is_talla = preg_match( '/talla/i', $opt_slug ) || preg_match( '/talla/i', $opt_name );
				$values   = isset( $o['values'] ) && is_array( $o['values'] ) ? $o['values'] : array();
				$sel_val  = isset( $selected[ $opt_id ] ) ? $selected[ $opt_id ] : null;
				$sel_obj  = null;
				foreach ( $values as $v ) { if ( isset( $v['_id'] ) && $v['_id'] === $sel_val ) { $sel_obj = $v; break; } }
				?>
				<div class="cdb-section" data-option-id="<?php echo esc_attr( $opt_id ); ?>">
					<div class="cdb-section-head">
						<p class="cdb-section-title"><?php echo esc_html( $opt_name ); ?></p>
						<span class="cdb-section-value" data-role="option-value"><?php echo esc_html( $sel_obj ? $sel_obj['valor'] : '' ); ?></span>
					</div>
					<div class="cdb-pills">
						<?php foreach ( $values as $val ) :
							$val_id = isset( $val['_id'] ) ? $val['_id'] : '';
							$active = ( $val_id === $sel_val );
							if ( $is_color ) :
								$bg = Catalogo_Distribuidor_Bridge_Colors::swatch_background( $val['valor'] ?? '', $val['meta']['hex'] ?? null );
								?>
								<button type="button" class="cdb-swatch<?php echo $active ? ' active' : ''; ?>" title="<?php echo esc_attr( $val['valor'] ?? '' ); ?>" style="background:<?php echo esc_attr( $bg ); ?>" data-option="<?php echo esc_attr( $opt_id ); ?>" data-value="<?php echo esc_attr( $val_id ); ?>" data-label="<?php echo esc_attr( $val['valor'] ?? '' ); ?>"></button>
							<?php else : ?>
								<button type="button" class="cdb-pill<?php echo $active ? ' active' : ''; ?>" data-option="<?php echo esc_attr( $opt_id ); ?>" data-value="<?php echo esc_attr( $val_id ); ?>" data-label="<?php echo esc_attr( $val['valor'] ?? '' ); ?>">
									<?php echo esc_html( $val['valor'] ?? '' ); ?>
								</button>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( $is_talla && ( $size_chart_mujer || $size_chart_hombre || $size_chart_default ) ) : ?>
					<details class="cdb-sizeguide">
						<summary class="cdb-sizeguide-toggle">Guía de tallas</summary>
						<div class="cdb-sizeguide-panel">
							<?php if ( $size_chart_mujer || $size_chart_hombre ) : ?>
								<?php if ( $size_chart_mujer ) : ?>
									<div class="cdb-sizeguide-block">
										<h3>Dama <span>(<?php echo esc_html( $size_chart_mujer['unidad'] ?? '' ); ?>)</span></h3>
										<?php cdb_render_size_table( $size_chart_mujer ); ?>
									</div>
								<?php endif; ?>
								<?php if ( $size_chart_hombre ) : ?>
									<div class="cdb-sizeguide-block">
										<h3>Caballero <span>(<?php echo esc_html( $size_chart_hombre['unidad'] ?? '' ); ?>)</span></h3>
										<?php cdb_render_size_table( $size_chart_hombre ); ?>
									</div>
								<?php endif; ?>
							<?php else : ?>
								<div class="cdb-sizeguide-block">
									<h3>Medidas <span>(<?php echo esc_html( $size_chart_default['unidad'] ?? '' ); ?>)</span></h3>
									<?php cdb_render_size_table( $size_chart_default ); ?>
								</div>
							<?php endif; ?>
						</div>
					</details>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="cdb-section" data-role="composicion-section" style="<?php echo ( $variant && ! empty( $variant['composicion'] ) ) ? '' : 'display:none'; ?>">
				<p class="cdb-section-title">Composición</p>
				<p class="cdb-composicion-text" data-role="composicion-text"><?php echo esc_html( $variant['composicion'] ?? '' ); ?></p>
			</div>

			<div class="cdb-sku-line" data-role="sku-line" style="<?php echo $variant ? '' : 'display:none'; ?>">
				<span data-role="sku-full">SKU <?php echo esc_html( $variant['sku'] ?? '' ); ?><?php echo ( ! empty( $variant['stock'] ) && $variant['stock'] > 0 ) ? ' · ' . (int) $variant['stock'] . ' en stock' : ''; ?></span>
			</div>

			<?php if ( $applications ) : ?>
				<div class="cdb-section">
					<p class="cdb-section-title">Personalización</p>
					<div class="cdb-chips">
						<?php foreach ( $applications as $a ) : ?>
							<span class="cdb-chip"><?php echo esc_html( $a['nombre'] ?? '' ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $features ) : ?>
				<div class="cdb-section">
					<p class="cdb-section-title">Características</p>
					<ul class="cdb-features">
						<?php foreach ( $features as $f ) : ?>
							<li>✓ <?php echo esc_html( $f['nombre'] ?? '' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $attributes ) : ?>
				<div class="cdb-section">
					<p class="cdb-section-title">Especificaciones</p>
					<dl class="cdb-attrs">
						<?php foreach ( $attributes as $i => $a ) :
							$def = $a['attribute'] ?? array();
							$v   = $a['value'] ?? '';
							$display = $v;
							if ( isset( $def['type'] ) ) {
								if ( 'boolean' === $def['type'] ) {
									$display = $v ? 'Sí' : 'No';
								} elseif ( 'select' === $def['type'] ) {
									foreach ( (array) ( $def['options'] ?? array() ) as $opt ) {
										if ( ( $opt['value'] ?? null ) === $v ) { $display = $opt['label'] ?? $v; break; }
									}
								} elseif ( 'multiselect' === $def['type'] ) {
									$labels = array();
									foreach ( (array) $v as $val ) {
										$lbl = $val;
										foreach ( (array) ( $def['options'] ?? array() ) as $opt ) {
											if ( ( $opt['value'] ?? null ) === $val ) { $lbl = $opt['label'] ?? $val; break; }
										}
										$labels[] = $lbl;
									}
									$display = implode( ', ', $labels );
								} elseif ( 'number' === $def['type'] ) {
									$display = $v . ( ! empty( $def['unit'] ) ? ' ' . $def['unit'] : '' );
								}
							}
							?>
							<div class="cdb-attr-row<?php echo ( $i % 2 ) ? '' : ' alt'; ?>">
								<dt><?php echo esc_html( $def['label'] ?? '' ); ?></dt>
								<dd><?php echo esc_html( is_scalar( $display ) ? $display : wp_json_encode( $display ) ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</div>
			<?php endif; ?>

			<?php if ( $descripcion ) : ?>
				<div class="cdb-section">
					<p class="cdb-section-title">Descripción</p>
					<p class="catalogo-dist-bridge-detail-desc"><?php echo esc_html( $descripcion ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</article>

	<?php if ( $faq ) : ?>
		<section class="cdb-faq">
			<h2>Preguntas frecuentes</h2>
			<?php foreach ( $faq as $f ) : ?>
				<div class="cdb-faq-item">
					<p class="cdb-faq-q"><?php echo esc_html( $f['pregunta'] ?? '' ); ?></p>
					<p class="cdb-faq-a"><?php echo esc_html( $f['respuesta'] ?? '' ); ?></p>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php
	// Productos relacionados: otros activos de la misma categoría. Se
	// calcula al final porque reutiliza product-card.php (que espera una
	// variable $product) — para entonces ya no hace falta el $product del
	// producto principal, así que reusar el nombre no rompe nada de arriba.
	$related = Catalogo_Distribuidor_Bridge_Store::get_related(
		isset( $product['category']['slug'] ) ? $product['category']['slug'] : '',
		isset( $product['_id'] ) ? $product['_id'] : '',
		4
	);
	?>
	<?php if ( $related ) : ?>
		<section class="cdb-related">
			<h2>Productos relacionados</h2>
			<div class="catalogo-dist-bridge-grid catalogo-dist-bridge-grid--cuadricula cdb-related-grid">
				<?php foreach ( $related as $product ) : ?>
					<?php include Catalogo_Distribuidor_Bridge_Templates::locate( 'product-card.php' ); ?>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>
</main>
<?php
get_footer();
