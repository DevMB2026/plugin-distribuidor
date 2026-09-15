<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de ajustes: Ajustes → Catálogo Distribuidor. Dos campos: la URL
 * base de la API (igual que el plugin público) y la API Key del
 * distribuidor.
 *
 * La API Key nunca se vuelve a mostrar en el formulario una vez guardada
 * (el campo aparece vacío con un placeholder que dice que ya está
 * configurada) — así evitamos dejarla repetida en el HTML de la página en
 * cada visita, y evitamos que quede en blanco por accidente: si el campo se
 * envía vacío, se conserva el valor anterior en vez de borrarlo.
 */
class Catalogo_Distribuidor_Bridge_Settings {

	const OPTION_GROUP = 'catalogo_distribuidor_bridge';
	const PAGE_SLUG     = 'catalogo-distribuidor-bridge';
	const DEFAULT_URL   = 'https://api-catalogo-productos.onrender.com/api/v1';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_key_missing' ) );
		add_action( 'admin_post_catalogo_dist_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_catalogo_dist_save_categories', array( __CLASS__, 'handle_save_categories' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media' ) );
	}

	// El selector de imágenes de categoría reutiliza la librería de medios de
	// WordPress (misma ventana que "Añadir imagen destacada") — solo hace
	// falta cargarla, y solo en esta pantalla de ajustes.
	public static function enqueue_media( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
	}

	/**
	 * Guarda nombre/imagen/orden/visibilidad "a mano" por categoría YA
	 * FUSIONADA (una fila por cada botón que el visitante realmente ve en
	 * el catálogo — ver Catalogo_Distribuidor_Bridge_Store::get_categories()
	 * — no por category_slug crudo: la fusión de categorías crudas dentro
	 * de su categoría real ya es automática, según la jerarquía que trae
	 * la API central). Va en su propio formulario (admin-post, no la
	 * Settings API) porque las categorías son dinámicas — dependen de lo
	 * que haya sincronizado, no de campos fijos declarados de antemano.
	 */
	public static function handle_save_categories() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'catalogo_dist_save_categories' ) ) {
			wp_die( 'No autorizado.' );
		}

		$nombres  = isset( $_POST['cdb_cat_nombre'] ) && is_array( $_POST['cdb_cat_nombre'] ) ? wp_unslash( $_POST['cdb_cat_nombre'] ) : array();
		$imagenes = isset( $_POST['cdb_cat_imagen'] ) && is_array( $_POST['cdb_cat_imagen'] ) ? wp_unslash( $_POST['cdb_cat_imagen'] ) : array();
		$ordenes  = isset( $_POST['cdb_cat_orden'] ) && is_array( $_POST['cdb_cat_orden'] ) ? wp_unslash( $_POST['cdb_cat_orden'] ) : array();
		// Un checkbox desmarcado no manda nada en el POST — por eso el
		// slug "está oculto" se decide por presencia en este arreglo, no por
		// su valor, y por qué $slugs (abajo) no puede depender de sus claves
		// para saber qué categorías existen: solo $nombres/$imagenes/$ordenes
		// (inputs de texto, siempre presentes aunque estén vacíos) cubren
		// eso de forma confiable.
		$ocultos = isset( $_POST['cdb_cat_oculto'] ) && is_array( $_POST['cdb_cat_oculto'] ) ? wp_unslash( $_POST['cdb_cat_oculto'] ) : array();

		$overrides = array();
		$slugs     = array_unique( array_merge( array_keys( $nombres ), array_keys( $imagenes ), array_keys( $ordenes ) ) );
		foreach ( $slugs as $slug ) {
			$slug   = sanitize_title( $slug );
			$nombre = isset( $nombres[ $slug ] ) ? sanitize_text_field( $nombres[ $slug ] ) : '';
			$imagen = isset( $imagenes[ $slug ] ) ? esc_url_raw( $imagenes[ $slug ] ) : '';
			$orden  = isset( $ordenes[ $slug ] ) ? trim( (string) $ordenes[ $slug ] ) : '';
			$orden  = ( '' !== $orden && is_numeric( $orden ) ) ? (string) (int) $orden : '';
			$oculto = ! empty( $ocultos[ $slug ] );
			if ( '' === $nombre && '' === $imagen && '' === $orden && ! $oculto ) {
				continue; // Nada que anular en esta categoría — no guardar una entrada vacía.
			}
			$overrides[ $slug ] = array(
				'nombre' => $nombre,
				'imagen' => $imagen,
				'orden'  => $orden,
				'oculto' => $oculto,
			);
		}

		update_option( 'catalogo_distribuidor_category_overrides', $overrides );
		wp_safe_redirect( add_query_arg( 'catalogo_dist_cats_saved', '1', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/** Botón "Sincronizar ahora": corre una sync completa en el momento y vuelve a Ajustes. */
	public static function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'catalogo_dist_sync_now' ) ) {
			wp_die( 'No autorizado.' );
		}
		Catalogo_Distribuidor_Bridge_Sync::full_sync();
		wp_safe_redirect( add_query_arg( 'catalogo_dist_synced', '1', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public static function render_sync_status_block() {
		$status = Catalogo_Distribuidor_Bridge_Sync::get_status();
		$count  = Catalogo_Distribuidor_Bridge_Store::count_active();
		?>
		<hr style="margin: 2rem 0;" />
		<h2>Estado de sincronización</h2>
		<?php if ( isset( $_GET['catalogo_dist_synced'] ) ) : ?>
			<p style="color:#2271b1;"><strong>Sincronización manual completada.</strong></p>
		<?php endif; ?>
		<p>
			<?php if ( $status && ! empty( $status['ok'] ) ) : ?>
				✓ Última sincronización correcta: <?php echo esc_html( $status['at'] ); ?>
			<?php elseif ( $status ) : ?>
				⚠ La última sincronización falló (<?php echo esc_html( $status['at'] ); ?>): <?php echo esc_html( $status['message'] ); ?>. Se sigue mostrando el catálogo de la última sincronización buena.
			<?php else : ?>
				Todavía no hay ninguna sincronización registrada.
			<?php endif; ?>
			<br />
			Productos activos en tu base de datos local: <strong><?php echo (int) $count; ?></strong>.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="catalogo_dist_sync_now" />
			<?php wp_nonce_field( 'catalogo_dist_sync_now' ); ?>
			<button type="submit" class="button">Sincronizar ahora</button>
		</form>
		<p class="description">
			Tu catálogo se guarda en una base de datos local en este sitio. Cualquier cambio en la API (alta, edición o
			baja) llega aquí de inmediato mediante un aviso automático del backend, y una vez al día se hace una revisión
			completa como respaldo. Este botón fuerza una sincronización completa inmediata, sin esperar a nada.
		</p>
		<?php
	}

	public static function add_menu() {
		add_options_page(
			'Catálogo Distribuidor',
			'Catálogo Distribuidor',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_api_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => self::DEFAULT_URL,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_galeria_layout',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_galeria_layout' ),
				'default'           => 'abajo',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_catalog_page_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_custom_css',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_custom_css' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'catalogo_distribuidor_whatsapp_numero',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_whatsapp_numero' ),
				'default'           => '',
			)
		);

		add_settings_section( 'catalogo_distribuidor_bridge_main', '', '__return_false', self::PAGE_SLUG );

		add_settings_field(
			'catalogo_distribuidor_api_base_url',
			'URL base de la API',
			array( __CLASS__, 'render_url_field' ),
			self::PAGE_SLUG,
			'catalogo_distribuidor_bridge_main'
		);

		add_settings_field(
			'catalogo_distribuidor_api_key',
			'API Key del distribuidor',
			array( __CLASS__, 'render_key_field' ),
			self::PAGE_SLUG,
			'catalogo_distribuidor_bridge_main'
		);

		add_settings_field(
			'catalogo_distribuidor_galeria_layout',
			'Galería en la ficha de producto',
			array( __CLASS__, 'render_galeria_layout_field' ),
			self::PAGE_SLUG,
			'catalogo_distribuidor_bridge_main'
		);

		add_settings_field(
			'catalogo_distribuidor_catalog_page_url',
			'URL de tu página de catálogo',
			array( __CLASS__, 'render_catalog_page_url_field' ),
			self::PAGE_SLUG,
			'catalogo_distribuidor_bridge_main'
		);

		add_settings_field(
			'catalogo_distribuidor_whatsapp_numero',
			'WhatsApp para cotizar',
			array( __CLASS__, 'render_whatsapp_numero_field' ),
			self::PAGE_SLUG,
			'catalogo_distribuidor_bridge_main'
		);
	}

	// Valor desconocido (HTML manipulado, etc.) cae a 'abajo' en vez de
	// guardar cualquier cosa que llegue en el POST.
	public static function sanitize_galeria_layout( $submitted ) {
		return 'izquierda' === $submitted ? 'izquierda' : 'abajo';
	}

	// Seguridad: el CSS válido NUNCA contiene "<" — ese carácter solo puede
	// aparecer aquí si alguien intenta colar </style><script>...</script> o
	// HTML dentro del campo. wp_strip_all_tags() elimina cualquier cosa con
	// forma de etiqueta sin tocar CSS legítimo (selectores como ".a > .b"
	// usan ">" solo, nunca "<", así que no se ven afectados). Además, solo
	// llega hasta aquí si quien envía el formulario ya pasó por
	// current_user_can('manage_options') — este campo nunca es de acceso
	// público.
	public static function sanitize_custom_css( $submitted ) {
		$css = wp_strip_all_tags( (string) $submitted );
		return trim( $css );
	}

	// Si llega vacío, conserva la key anterior en vez de borrarla — así un
	// guardado accidental sin tocar el campo no desconfigura el sitio.
	public static function sanitize_api_key( $submitted ) {
		$submitted = trim( (string) $submitted );
		if ( '' === $submitted ) {
			return get_option( 'catalogo_distribuidor_api_key', '' );
		}
		return sanitize_text_field( $submitted );
	}

	public static function render_url_field() {
		$value = get_option( 'catalogo_distribuidor_api_base_url', self::DEFAULT_URL );
		printf(
			'<input type="url" name="catalogo_distribuidor_api_base_url" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr( self::DEFAULT_URL )
		);
		echo '<p class="description">URL base del catálogo, sin diagonal al final. Ej: ' . esc_html( self::DEFAULT_URL ) . '</p>';
	}

	public static function render_key_field() {
		$existe = (bool) get_option( 'catalogo_distribuidor_api_key', '' );
		printf(
			'<input type="password" name="catalogo_distribuidor_api_key" value="" class="regular-text" autocomplete="off" placeholder="%s" />',
			$existe ? 'Ya configurada — deja en blanco para no cambiarla' : 'Pega aquí tu API Key (te la dio el administrador del catálogo)'
		);
		echo '<p class="description">Se usa solo desde el servidor de este sitio para identificar tu catálogo — nunca se envía al navegador de tus visitantes.</p>';
		if ( $existe ) {
			echo '<p class="description" style="color:#2f8f5b;">✓ Hay una API Key configurada.</p>';
		}
	}

	public static function render_galeria_layout_field() {
		$value = get_option( 'catalogo_distribuidor_galeria_layout', 'abajo' );
		?>
		<select name="catalogo_distribuidor_galeria_layout">
			<option value="abajo" <?php selected( $value, 'abajo' ); ?>>Mosaico — todas las fotos visibles a la vez, con zoom al hacer clic (por defecto)</option>
			<option value="izquierda" <?php selected( $value, 'izquierda' ); ?>>Clásica — una imagen principal con miniaturas en columna a la izquierda</option>
		</select>
		<p class="description">Solo cambia el acomodo de las fotos en la página de un producto — no afecta el catálogo/listado.</p>
		<?php
	}

	public static function render_catalog_page_url_field() {
		$value = get_option( 'catalogo_distribuidor_catalog_page_url', '' );
		printf(
			'<input type="url" name="catalogo_distribuidor_catalog_page_url" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr( home_url( '/catalogo/' ) )
		);
		echo '<p class="description">La página donde pegaste el shortcode <code>[catalogo_distribuidor]</code> (tu catálogo). Se usa para el enlace "Catálogo" del migajero de pan y el botón "Regresar al catálogo" en la ficha de cada producto. Si la dejas vacía, se usa la portada de tu sitio (' . esc_html( home_url( '/' ) ) . ').</p>';
	}

	public static function maybe_notice_key_missing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		// No lo repitas en cada pantalla del admin — solo donde tiene sentido actuar.
		if ( $screen && 'settings_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}
		if ( get_option( 'catalogo_distribuidor_api_key', '' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Catálogo Distribuidor: todavía no configuras tu API Key, así que el catálogo no se mostrará.', 'catalogo-distribuidor-bridge' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Configurarla ahora', 'catalogo-distribuidor-bridge' )
		);
	}

	// Solo dígitos, con código de país incluido (formato que espera wa.me,
	// ej. 528112345678) — cualquier espacio, guion, "+" o paréntesis que
	// escriba el usuario se limpia solo.
	public static function sanitize_whatsapp_numero( $submitted ) {
		return preg_replace( '/\D+/', '', (string) $submitted );
	}

	public static function render_whatsapp_numero_field() {
		$value = get_option( 'catalogo_distribuidor_whatsapp_numero', '' );
		printf(
			'<input type="text" name="catalogo_distribuidor_whatsapp_numero" value="%s" class="regular-text" placeholder="528112345678" />',
			esc_attr( $value )
		);
		echo '<p class="description">Número de WhatsApp donde te llegarán las cotizaciones, con código de país y sin espacios ni signos (ej. 52 para México + el número a 10 dígitos: 528112345678). Si lo dejas vacío, el botón "Cotizar por WhatsApp" no aparece en la ficha de producto.</p>';
	}

	public static function render_custom_css_block() {
		$value = get_option( 'catalogo_distribuidor_custom_css', '' );
		?>
		<hr style="margin: 2rem 0;" />
		<h2>CSS personalizado del catálogo</h2>
		<p>Personaliza directamente aquí la apariencia de tu catálogo, sin salir de esta pantalla:</p>
		<textarea
			id="catalogo_distribuidor_custom_css"
			name="catalogo_distribuidor_custom_css"
			rows="16"
			spellcheck="false"
			placeholder="/* Personaliza aquí tu catálogo */&#10;&#10;.catalogo-dist-bridge-card {&#10;    border-radius: 20px;&#10;}&#10;&#10;.catalogo-dist-bridge-detail-title {&#10;    font-family: Georgia, serif;&#10;}"
			style="width:100%; font-family: ui-monospace, Consolas, 'Courier New', monospace; font-size:13px; line-height:1.6; padding:0.75rem; box-sizing:border-box; resize:vertical; white-space:pre;"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p>
			<button type="button" id="catalogo_distribuidor_reset_css" class="button">Restaurar CSS predeterminado</button>
		</p>
		<p class="description">
			Aquí puedes personalizar la apariencia de tu catálogo sin modificar los archivos del plugin. Los cambios se
			aplicarán a tu catálogo en cuanto guardes — usa el mismo botón <strong>Guardar cambios</strong> de arriba. Las
			clases principales son <code>catalogo-dist-bridge-*</code> (catálogo/listado) y <code>cdb-*</code> (ficha de
			producto) — se mantienen estables entre versiones del plugin.
		</p>
		<script>
		document.getElementById( 'catalogo_distribuidor_reset_css' ).addEventListener( 'click', function () {
			if ( confirm( '¿Restaurar el CSS a su valor predeterminado (vacío)? Esto borrará tu personalización actual. Tendrás que guardar cambios para que se aplique.' ) ) {
				document.getElementById( 'catalogo_distribuidor_custom_css' ).value = '';
			}
		} );
		</script>
		<?php
	}

	/**
	 * Nombre, imagen de portada, orden y visibilidad por categoría YA
	 * FUSIONADA — una fila por cada botón/filtro que el visitante realmente
	 * ve en el catálogo (ver Store::get_categories()), no por category_slug
	 * crudo: la fusión de sub-categorías dentro de su categoría real (ej.
	 * "Fleece", "Hoodie" → "Sudaderas") ya es automática, según la
	 * jerarquía que trae la API central — no hace falta configurarla aquí.
	 *
	 * Por defecto el nombre y la imagen vienen del catálogo sincronizado
	 * (el nombre real de esa categoría, la foto del producto más reciente
	 * que tenga) y el orden es alfabético — aquí se puede reemplazar
	 * cualquiera, por ejemplo para corregir una falta de ortografía, poner
	 * una foto propia, o decidir en qué posición aparece cada categoría.
	 * Dejar un campo vacío usa de nuevo el valor automático.
	 */
	public static function render_categories_block() {
		$categorias = Catalogo_Distribuidor_Bridge_Store::get_categories( true );
		$overrides  = get_option( 'catalogo_distribuidor_category_overrides', array() );
		?>
		<hr style="margin: 2rem 0;" />
		<h2>Categorías del catálogo</h2>
		<?php if ( isset( $_GET['catalogo_dist_cats_saved'] ) ) : ?>
			<p style="color:#2271b1;"><strong>Categorías actualizadas.</strong></p>
		<?php endif; ?>
		<?php if ( ! $categorias ) : ?>
			<p class="description">Todavía no hay categorías sincronizadas — vuelve aquí después de la primera sincronización.</p>
		<?php else : ?>
			<p class="description">
				Esta es la lista de categorías tal cual las ve el visitante en el catálogo — las sub-categorías de la API
				(ej. "Fleece", "Hoodie") ya vienen fusionadas dentro de la suya (ej. "Sudaderas"), automáticamente.
				El nombre y la portada de cada una vienen del catálogo sincronizado. Si quieres corregir un nombre o
				poner tu propia foto de portada, cámbialo aquí — deja el campo vacío para volver a usar el valor automático.
				El campo <strong>Orden</strong> decide en qué posición aparece cada una (1 = primera); las que dejes en
				blanco se acomodan al final, por orden alfabético. Desmarca <strong>Mostrar</strong> para quitar el botón
				de una categoría sin dejar de vender sus productos — siguen apareciendo en el catálogo, solo desaparece su
				botón/filtro.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="catalogo_dist_save_categories" />
				<?php wp_nonce_field( 'catalogo_dist_save_categories' ); ?>
				<table class="widefat" style="max-width: 1100px;">
					<thead>
						<tr>
							<th style="width:70px;">Mostrar</th>
							<th style="width:70px;">Orden</th>
							<th style="width:90px;">Portada</th>
							<th>Nombre</th>
							<th style="width:110px;">Productos</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $categorias as $cat ) :
							$slug          = $cat['slug'];
							$override      = isset( $overrides[ $slug ] ) ? $overrides[ $slug ] : array();
							$nombre_field  = ! empty( $override['nombre'] ) ? $override['nombre'] : '';
							$imagen_field  = ! empty( $override['imagen'] ) ? $override['imagen'] : '';
							$orden_field   = ! empty( $override['orden'] ) ? $override['orden'] : '';
							$preview_src   = $imagen_field ? $imagen_field : $cat['imagen'];
							?>
							<tr style="<?php echo $cat['oculto'] ? 'opacity:0.55;' : ''; ?>">
								<td>
									<input type="checkbox" name="cdb_cat_oculto[<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( $cat['oculto'] ); ?> class="cdb-cat-oculto-toggle" />
								</td>
								<td>
									<input type="number" name="cdb_cat_orden[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $orden_field ); ?>" class="small-text" min="1" step="1" />
								</td>
								<td>
									<div class="cdb-cat-preview" style="width:64px; height:64px; border-radius:50%; overflow:hidden; background:#f0f0f1; border:1px solid #dcdcde;">
										<?php if ( $preview_src ) : ?>
											<img src="<?php echo esc_url( $preview_src ); ?>" style="width:100%; height:100%; object-fit:cover;" alt="" />
										<?php endif; ?>
									</div>
									<input type="hidden" class="cdb-cat-imagen-input" name="cdb_cat_imagen[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $imagen_field ); ?>" />
									<p style="margin:0.4rem 0 0;">
										<button type="button" class="button button-small cdb-cat-imagen-elegir">Elegir</button>
										<button type="button" class="button-link cdb-cat-imagen-quitar" style="<?php echo $imagen_field ? '' : 'display:none'; ?>">Quitar</button>
									</p>
								</td>
								<td>
									<input type="text" name="cdb_cat_nombre[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $nombre_field ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $cat['nombre'] ); ?>" />
								</td>
								<td><?php echo (int) $cat['total']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'Guardar categorías' ); ?>
			</form>
			<script>
			document.querySelectorAll( '.cdb-cat-oculto-toggle' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					cb.closest( 'tr' ).style.opacity = cb.checked ? '0.55' : '';
				} );
			} );
			</script>
			<script>
			( function () {
				var frame;
				document.querySelectorAll( '.cdb-cat-imagen-elegir' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var cell    = btn.closest( 'td' );
						var input   = cell.querySelector( '.cdb-cat-imagen-input' );
						var preview = cell.querySelector( '.cdb-cat-preview' );
						var quitar  = cell.querySelector( '.cdb-cat-imagen-quitar' );
						frame = wp.media( { title: 'Elegir portada de categoría', multiple: false, library: { type: 'image' } } );
						frame.on( 'select', function () {
							var attachment = frame.state().get( 'selection' ).first().toJSON();
							var url = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
							input.value = url;
							preview.innerHTML = '<img src="' + url + '" style="width:100%; height:100%; object-fit:cover;" alt="" />';
							quitar.style.display = '';
						} );
						frame.open();
					} );
				} );
				document.querySelectorAll( '.cdb-cat-imagen-quitar' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var cell    = btn.closest( 'td' );
						var input   = cell.querySelector( '.cdb-cat-imagen-input' );
						var preview = cell.querySelector( '.cdb-cat-preview' );
						input.value = '';
						preview.innerHTML = '';
						btn.style.display = 'none';
					} );
				} );
			} )();
			</script>
		<?php endif; ?>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Catálogo Distribuidor Bridge</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				self::render_custom_css_block();
				submit_button();
				?>
			</form>
			<?php self::render_categories_block(); ?>
			<?php self::render_sync_status_block(); ?>
			<p>
				Usa el shortcode <code>[catalogo_distribuidor]</code> en cualquier página o entrada para mostrar tu catálogo.
				Admite los atributos <code>marca</code>, <code>categoria</code>, <code>limite</code> y <code>estilo</code>, por ejemplo:
				<code>[catalogo_distribuidor limite="8" estilo="lista"]</code>. El catálogo que ves es siempre el que te asignó el
				administrador — este plugin no puede cambiarlo, solo mostrarlo.
			</p>
			<p>
				<code>estilo</code> controla solo el acomodo visual (no afecta qué productos se muestran):
			</p>
			<ul style="list-style: disc; margin-left: 1.5rem;">
				<li><code>estilo="cuadricula"</code> — tarjetas en cuadrícula (por defecto, no hace falta escribirlo).</li>
				<li><code>estilo="lista"</code> — filas horizontales con imagen, marca, nombre y categoría.</li>
				<li><code>estilo="compacta"</code> — cuadrícula más densa, solo imagen y nombre, para catálogos grandes.</li>
			</ul>
			<p class="description">
				¿Quieres cambiar colores, tamaños o tipografía? Usa el bloque <strong>"CSS personalizado del catálogo"</strong>
				de arriba — no hace falta salir de esta pantalla ni entrar a Apariencia → Personalizar.
			</p>

			<h2>¿Necesitas un diseño completamente propio?</h2>
			<p>
				Si tienes (o tu cliente tiene) un desarrollador y el CSS no es suficiente, el plugin permite reemplazar
				el HTML por completo <strong>sin dejar de usarlo</strong> — la conexión con la API, la autenticación y el
				caché los sigue manejando el plugin; solo cambia qué archivo dibuja el resultado final.
			</p>
			<p>Para hacerlo:</p>
			<ol style="margin-left: 1.5rem;">
				<li>Copia cualquiera de estos archivos desde la carpeta <code>templates/</code> del plugin: <code>grid.php</code>, <code>product-card.php</code>, <code>detail.php</code> o <code>error.php</code>.</li>
				<li>Pégalo en tu tema activo, dentro de una carpeta nueva llamada <code>catalogo-distribuidor-bridge</code>, por ejemplo: <code>wp-content/themes/tu-tema/catalogo-distribuidor-bridge/detail.php</code>.</li>
				<li>Edítalo como quieras — el plugin detecta automáticamente que existe y usa esa versión en vez de la suya, sin que tengas que activar nada.</li>
				<li>Si algún día borras ese archivo de tu tema, el plugin vuelve solo a su plantilla por defecto — nunca se rompe.</li>
			</ol>
			<p class="description">
				Cada plantilla recibe las mismas variables ya resueltas (ej. <code>$product</code> con los datos del producto,
				<code>$result</code> con la respuesta de la API) — usa el archivo original del plugin como referencia de qué
				datos tienes disponibles.
			</p>

			<h2>¿Necesitas control total, sin usar ni el shortcode ni las plantillas?</h2>
			<p>
				El plugin en el fondo solo es un puente de datos: se conecta a la API, se autentica y mantiene sincronizada
				una copia local — el shortcode y las plantillas de arriba son una forma de mostrarla, no la única. Si tu
				desarrollador prefiere construir la página desde cero (su propio layout, animaciones, framework de CSS,
				lo que sea), puede llamar directamente a estos métodos y usar los datos como quiera:
			</p>
			<pre style="background:#f6f7f7; padding:1rem; overflow-x:auto; border:1px solid #dcdcde;"><code>// Listado (respeta la sincronización local y la caché, igual que el shortcode)
$result = Catalogo_Distribuidor_Bridge_Store::query( array( 'brand' => 'mi-marca', 'limit' => 12 ) );
// $result = ['ok', 'status', 'data' => [...productos...], 'pagination', 'message']

// Detalle de un producto por slug
$detalle = Catalogo_Distribuidor_Bridge_Store::get_by_slug( 'mi-producto' );</code></pre>
			<p class="description">
				Esto se puede usar desde <code>functions.php</code> del tema o desde cualquier plantilla de página —
				sin ninguna marca visual del plugin de por medio. Ver más detalle en el <code>readme.txt</code> del plugin.
			</p>
		</div>
		<?php
	}
}
