<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $result Viene de la plantilla que lo incluye (grid.php o detail.php). */
$mensaje = ! empty( $result['message'] ) ? $result['message'] : 'El catálogo no está disponible en este momento. Intenta más tarde.';
?>
<p class="catalogo-dist-bridge-error"><?php echo esc_html( $mensaje ); ?></p>
