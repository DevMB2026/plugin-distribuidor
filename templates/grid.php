<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var array  $result Viene de Catalogo_Distribuidor_Bridge_Shortcodes::render_grid().
 * @var string $estilo 'cuadricula' | 'lista' | 'compacta' — ya validado ahí mismo.
 * @var array  $categorias Categorías del catálogo local: ['slug','nombre','total','imagen'].
 * @var array  $destacados Hasta 3 productos marcados como destacados en el panel admin.
 * @var string $categoria_actual Slug de la categoría actualmente filtrada (fija por shortcode o elegida por el visitante).
 * @var string $categoria_fija_activa Slug fijado en el atributo "categoria" del shortcode, si lo hay — si no está vacío, el visitante no puede cambiarlo desde aquí.
 */
$estilo                = isset( $estilo ) && in_array( $estilo, Catalogo_Distribuidor_Bridge_Shortcodes::ESTILOS_VALIDOS, true ) ? $estilo : 'cuadricula';
$categorias            = isset( $categorias ) && is_array( $categorias ) ? $categorias : array();
$destacados            = isset( $destacados ) && is_array( $destacados ) ? $destacados : array();
$categoria_actual      = isset( $categoria_actual ) ? $categoria_actual : '';
$categoria_fija_activa = isset( $categoria_fija_activa ) ? $categoria_fija_activa : '';

// El filtro de categoría (botones grandes + lista del aside) solo se ofrece
// si el dueño de la página no fijó ya una categoría en el shortcode, y si
// hay más de una entre las que elegir — con una sola no hay nada que filtrar.
$mostrar_filtro_categoria = empty( $categoria_fija_activa ) && count( $categorias ) > 1;
?>
<div class="catalogo-dist-bridge-grid-wrap">
	<?php if ( $mostrar_filtro_categoria ) : ?>
		<div class="cdb-cats-section">
			<h2 class="cdb-cats-title">Categorías</h2>
			<div class="cdb-cats-row">
				<?php foreach ( $categorias as $cat ) : ?>
					<a class="cdb-cat-btn<?php echo ( $cat['slug'] === $categoria_actual ) ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cdb_categoria', $cat['slug'] ) ); ?>">
						<span class="cdb-cat-btn-img">
							<?php if ( $cat['imagen'] ) : ?>
								<img src="<?php echo esc_url( $cat['imagen'] ); ?>" alt="<?php echo esc_attr( $cat['nombre'] ); ?>" loading="lazy" />
							<?php endif; ?>
						</span>
						<span class="cdb-cat-btn-label"><?php echo esc_html( $cat['nombre'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="cdb-catalog-layout">
		<div class="cdb-catalog-main">
			<?php if ( empty( $result['ok'] ) ) : ?>
				<?php include Catalogo_Distribuidor_Bridge_Templates::locate( 'error.php' ); ?>
			<?php elseif ( empty( $result['data'] ) ) : ?>
				<p class="catalogo-dist-bridge-empty">No hay productos disponibles con estos filtros.</p>
			<?php else : ?>
				<div class="catalogo-dist-bridge-grid catalogo-dist-bridge-grid--<?php echo esc_attr( $estilo ); ?>">
					<?php foreach ( $result['data'] as $product ) : ?>
						<?php include Catalogo_Distribuidor_Bridge_Templates::locate( 'product-card.php' ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $mostrar_filtro_categoria || $destacados ) : ?>
			<aside class="cdb-catalog-sidebar">
				<?php if ( $mostrar_filtro_categoria ) : ?>
					<div class="cdb-sidebar-block">
						<h3 class="cdb-sidebar-title">Categorías del producto</h3>
						<?php if ( $categoria_actual ) : ?>
							<a class="cdb-cat-list-clear" href="<?php echo esc_url( remove_query_arg( 'cdb_categoria' ) ); ?>">Quitar filtro</a>
						<?php endif; ?>
						<ul class="cdb-cat-list">
							<?php foreach ( $categorias as $cat ) : ?>
								<li>
									<a class="cdb-cat-list-link<?php echo ( $cat['slug'] === $categoria_actual ) ? ' active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cdb_categoria', $cat['slug'] ) ); ?>">
										<span><?php echo esc_html( $cat['nombre'] ); ?></span>
										<span class="cdb-cat-list-count"><?php echo (int) $cat['total']; ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $destacados ) : ?>
					<div class="cdb-sidebar-block">
						<h3 class="cdb-sidebar-title">Productos destacados</h3>
						<ul class="cdb-featured-list">
							<?php foreach ( $destacados as $destacado_producto ) :
								$f_nombre = isset( $destacado_producto['nombre'] ) ? $destacado_producto['nombre'] : '';
								$f_slug   = isset( $destacado_producto['slug'] ) ? $destacado_producto['slug'] : '';
								$f_imagen = Catalogo_Distribuidor_Bridge_Store::main_image( $destacado_producto );
								?>
								<li>
									<a class="cdb-featured-item" href="<?php echo esc_url( Catalogo_Distribuidor_Bridge_Rewrite::product_url( $f_slug ) ); ?>">
										<span class="cdb-featured-img">
											<?php if ( $f_imagen ) : ?>
												<img src="<?php echo esc_url( $f_imagen ); ?>" alt="<?php echo esc_attr( $f_nombre ); ?>" loading="lazy" />
											<?php endif; ?>
										</span>
										<span class="cdb-featured-name"><?php echo esc_html( $f_nombre ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</aside>
		<?php endif; ?>
	</div>
</div>
