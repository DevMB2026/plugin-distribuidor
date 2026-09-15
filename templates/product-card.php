<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $product Un producto del array $result['data'], inyectado por grid.php. */

$nombre     = isset( $product['nombre'] ) ? $product['nombre'] : '';
$marca      = isset( $product['brand']['nombre'] ) ? $product['brand']['nombre'] : '';
// Mismo nombre "para mostrar" que ya usan los botones/aside del catálogo:
// el corregido a mano en Ajustes si existe, o si no, normalizado a
// "Primera letra mayúscula, resto minúsculas" — la API no es consistente
// en cómo llega capitalizado.
$categoria  = Catalogo_Distribuidor_Bridge_Store::display_category_nombre(
	isset( $product['category']['slug'] ) ? $product['category']['slug'] : '',
	isset( $product['category']['nombre'] ) ? $product['category']['nombre'] : ''
);
$slug       = isset( $product['slug'] ) ? $product['slug'] : '';
$disponible = ! empty( $product['activo'] );

$imagen = Catalogo_Distribuidor_Bridge_Store::main_image( $product );

// Colores y tallas: las dos mismas opciones que ya distingue detail.php
// (una de tipo "swatch"/color, otra de tipo "size"/talla). Aquí se resumen
// para que la tarjeta no se sature: colores, máximo 6 con un "+N" si el
// producto tiene más; tallas, como un rango "menor – mayor" (las tallas ya
// vienen ordenadas por su campo "orden", de la más chica a la más grande)
// en vez de listar cada una.
$swatches   = array();
$tallas_min = '';
$tallas_max = '';
if ( ! empty( $product['options'] ) && is_array( $product['options'] ) ) {
	foreach ( $product['options'] as $o ) {
		$opt_tipo = isset( $o['option']['tipo'] ) ? $o['option']['tipo'] : '';
		$opt_slug = isset( $o['option']['slug'] ) ? $o['option']['slug'] : '';
		$opt_name = isset( $o['option']['nombre'] ) ? $o['option']['nombre'] : '';
		$values   = ( ! empty( $o['values'] ) && is_array( $o['values'] ) ) ? $o['values'] : array();

		$is_color = ( 'swatch' === $opt_tipo ) || preg_match( '/color/i', $opt_slug ) || preg_match( '/color/i', $opt_name );
		$is_talla = ( 'size' === $opt_tipo ) || preg_match( '/talla|size/i', $opt_slug ) || preg_match( '/talla|size/i', $opt_name );

		if ( $is_color && $values && ! $swatches ) {
			$swatches = $values;
		}
		if ( $is_talla && $values && '' === $tallas_min ) {
			usort(
				$values,
				function ( $a, $b ) {
					return ( $a['orden'] ?? 0 ) <=> ( $b['orden'] ?? 0 );
				}
			);
			$tallas_min = $values[0]['valor'] ?? '';
			$tallas_max = $values[ count( $values ) - 1 ]['valor'] ?? '';
		}
	}
}
$swatches_visibles  = array_slice( $swatches, 0, 6 );
$swatches_restantes = max( 0, count( $swatches ) - 6 );
?>
<a class="catalogo-dist-bridge-card" href="<?php echo esc_url( Catalogo_Distribuidor_Bridge_Rewrite::product_url( $slug ) ); ?>">
	<div class="catalogo-dist-bridge-card-img">
		<?php if ( $imagen ) : ?>
			<img src="<?php echo esc_url( $imagen ); ?>" alt="<?php echo esc_attr( $nombre ); ?>" loading="lazy" />
		<?php else : ?>
			<span class="catalogo-dist-bridge-no-img">Sin imagen</span>
		<?php endif; ?>
		<?php if ( $disponible ) : ?>
			<span class="cdb-card-badge">Stock disponible</span>
		<?php endif; ?>
	</div>
	<div class="catalogo-dist-bridge-card-body">
		<?php if ( $marca ) : ?>
			<p class="catalogo-dist-bridge-card-brand"><?php echo esc_html( strtoupper( $marca ) ); ?></p>
		<?php endif; ?>
		<h3 class="catalogo-dist-bridge-card-title"><?php echo esc_html( $nombre ); ?></h3>
		<?php if ( $categoria ) : ?>
			<p class="catalogo-dist-bridge-card-categoria"><?php echo esc_html( $categoria ); ?></p>
		<?php endif; ?>
		<?php if ( $tallas_min ) : ?>
			<p class="cdb-card-tallas"><strong>Tallas:</strong> <?php echo esc_html( $tallas_min ); ?>&nbsp;&ndash;&nbsp;<?php echo esc_html( $tallas_max ); ?></p>
		<?php endif; ?>
		<?php if ( $swatches ) : ?>
			<div class="cdb-card-swatches">
				<?php foreach ( $swatches_visibles as $val ) :
					$bg = Catalogo_Distribuidor_Bridge_Colors::swatch_background( $val['valor'] ?? '', $val['meta']['hex'] ?? null );
					?>
					<span class="cdb-card-swatch" title="<?php echo esc_attr( $val['valor'] ?? '' ); ?>" style="background:<?php echo esc_attr( $bg ); ?>"></span>
				<?php endforeach; ?>
				<?php if ( $swatches_restantes > 0 ) : ?>
					<span class="cdb-card-swatch-more">+<?php echo (int) $swatches_restantes; ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<span class="cdb-card-cta">Ver producto</span>
	</div>
</a>
