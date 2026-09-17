=== Catálogo Distribuidor Bridge ===
Contributors: prezenza
Tags: catálogo, api, distribuidor, productos
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.23.0
License: proprietary

Consume el catálogo de un distribuidor autenticado con su API Key. Sin precios, con una base de datos local que se sincroniza al instante.

== Description ==

Este plugin es para el sitio de un DISTRIBUIDOR, no para el catálogo público de una marca (para eso existe el plugin separado "Catálogo API Bridge"). Se conecta al namespace autenticado de la API (`/distribuidores/productos`), identificándose con la API Key que el administrador del catálogo le entregó a ese distribuidor.

El catálogo que se muestra lo decide siempre el backend, a partir de esa API Key — este plugin no elige marcas, categorías ni catálogos por su cuenta, solo se identifica y muestra lo que la API le entrega. Si el distribuidor no tiene un catálogo restringido asignado, ve el catálogo completo, igual que el plugin público.

La API Key nunca se envía al navegador del visitante — la llamada a la API ocurre siempre en el servidor de WordPress.

== Configuración ==

1. Activa el plugin.
2. Ve a Ajustes → Catálogo Distribuidor.
3. Pega tu API Key (te la entrega el administrador del catálogo) y guarda.
4. Usa el shortcode `[catalogo_distribuidor]` en cualquier página o entrada para mostrar tu catálogo. Admite los atributos `marca`, `categoria` y `limite`.
5. El detalle de cada producto se sirve automáticamente en `/catalogo-distribuidor/producto/{slug}/`.

== Personalización: 3 niveles ==

El plugin es un puente de datos (conexión a la API, autenticación, caché y sincronización local), no el dueño del diseño. Cada distribuidor tiene su propia línea gráfica, así que hay tres formas de usarlo, de menos a más control:

1. **Shortcode + plantillas por defecto.** `[catalogo_distribuidor]` tal cual, con el bloque de CSS personalizado y el atributo `estilo` de Ajustes → Catálogo Distribuidor. Sin tocar código.
2. **Sobreescritura de plantillas** (mismo patrón que WooCommerce). Copia `grid.php`, `product-card.php`, `detail.php` o `error.php` desde la carpeta `templates/` del plugin a `wp-content/themes/TU-TEMA/catalogo-distribuidor-bridge/` y edítalos como quieras — HTML, CSS, JS, animaciones. El plugin sigue resolviendo la conexión, la caché y la sincronización; solo cambia qué archivo dibuja el resultado.
3. **Headless** (control total, sin usar el shortcode ni las plantillas del plugin). `Catalogo_Distribuidor_Bridge_Store::query( $args )` y `Catalogo_Distribuidor_Bridge_Store::get_by_slug( $slug )` son métodos públicos: devuelven los mismos datos ya sincronizados y cacheados (`['ok', 'status', 'data', 'pagination', 'message']`) para que un desarrollador construya su propia plantilla de página desde cero, con cualquier framework de animación o layout, sin ninguna marca del plugin de por medio.

   Ejemplo mínimo, desde `functions.php` o una plantilla de página del tema:

   `
   $result = Catalogo_Distribuidor_Bridge_Store::query( array( 'brand' => 'mi-marca', 'limit' => 12 ) );
   if ( $result['ok'] ) {
       foreach ( $result['data'] as $product ) {
           // $product trae la misma forma que devuelve la API: nombre, slug, media, variants, options, sizeChart...
       }
   }

   $detalle = Catalogo_Distribuidor_Bridge_Store::get_by_slug( 'mi-producto' );
   `

== Changelog ==

= 1.23.0 =
* Corregido: el catálogo del listado (shortcode `[catalogo_distribuidor]`) no tenía paginación — solo mostraba los primeros "limite" productos (12 por defecto, 100 como máximo posible) y el resto del catálogo asignado al distribuidor quedaba fuera de alcance para siempre, sin ninguna forma de llegar a esos productos desde el sitio. Ahora el listado agrega automáticamente controles "Anterior / Siguiente" (recarga de página con `?cdb_pagina=N`, sin JavaScript, mismo patrón que el filtro de categoría) en cuanto hay más de una página de resultados. "limite" pasa a controlar solo cuántos productos se ven por página, no un tope del catálogo — no requiere ninguna configuración por parte del distribuidor.

= 1.22.0 =
* Actualizaciones automáticas: el plugin ya avisa solo cuando hay una versión nueva (Escritorio → Plugins, igual que cualquier plugin de la tienda oficial), leyendo los tags publicados en https://github.com/DevMB2026/plugin-distribuidor. No requiere acción de los sitios que lo tienen instalado — para publicar una versión nueva basta con subir el número de Version, hacer commit y empujar un tag `vX.Y.Z`.

= 1.20.1 =
* Cambiado: el panel Ajustes → Categorías del catálogo ahora muestra una fila por cada categoría YA FUSIONADA (la que el visitante realmente ve, ej. "Sudaderas") en vez de una fila por cada category_slug crudo de la API (ej. "Sudaderas", "Fleece", "Hoodie", "Cat"... por separado) — la fusión sigue siendo automática según la jerarquía real de la API, esto solo simplifica el panel para editar nombre/portada/orden/mostrar de la categoría final, sin la lista larga y repetida. Se quitó el campo "Grupo" (ya no hace falta, la fusión es automática).

= 1.20.0 =
* Corregido: las categorías del catálogo se armaban a partir de la categoría cruda de cada producto tal cual venía de la API, sin usar la jerarquía real que la API ya maneja (campo "parent") — categorías que en realidad son sub-categorías de otra (ej. "Fleece", "Hoodie" o "Basica", todas hijas de "Sudaderas" en la API) aparecían como si fueran categorías sueltas y de primer nivel, inflando el catálogo con entradas que no corresponden a las categorías reales. Ahora el plugin consulta GET /categories (endpoint público, sin necesidad de API Key) y agrupa automáticamente cada categoría cruda bajo su categoría raíz real, sin depender de que el distribuidor lo configure a mano en Ajustes (esa opción sigue disponible para casos que la API no cubra).

= 1.19.1 =
* Corregido: el nombre de categoría normalizado (o corregido a mano en Ajustes) solo se veía en los botones/aside del catálogo — la ficha de producto (migajero de pan y meta bajo el título) y la categoría de cada tarjeta seguían mostrando el nombre tal cual venía de la API, sin normalizar y sin la corrección manual. Ahora se usa el mismo nombre resuelto en todos lados.

= 1.19.0 =
* Corregido: la pestaña del navegador (y el título SEO) de la ficha de producto mostraba "Blog" en vez del nombre real del producto — la ruta `/catalogo-distribuidor/producto/{slug}/` es virtual y no tiene una página de WordPress real detrás, así que el sitio caía al título por defecto. Ahora se usa el nombre del producto.

= 1.18.0 =
* Nuevo bloque "Categorías del catálogo" en Ajustes → Catálogo Distribuidor: el nombre y la portada de cada categoría venían siempre de la sincronización (el nombre que mandó el administrador del catálogo, la foto del producto más reciente de esa categoría). Ahora se pueden reemplazar a mano por categoría — por ejemplo para corregir una falta de ortografía en el nombre, o subir tu propia foto de portada en vez de la automática. Dejar un campo vacío vuelve a usar el valor automático de siempre.
* El nombre de categoría que no se haya corregido a mano ahora se normaliza solo a "Primera letra mayúscula, resto minúsculas" (ej. "PLAYERA POLO MANGA CORTA" -> "Playera polo manga corta") — el catálogo sincronizado no es consistente en esto, algunas categorías llegan bien capitalizadas y otras en mayúsculas.
* Nuevo campo "Orden" en el mismo bloque de categorías: antes siempre aparecían en orden alfabético (fijo, sin poder cambiarlo); ahora se puede numerar cada una a mano para que aparezca en la posición que el distribuidor decida, tanto en los botones grandes como en el filtro del aside. Las que se dejen sin numerar se acomodan al final, en orden alfabético como hasta ahora.
* Nueva casilla "Mostrar" en el mismo bloque: al desmarcarla, esa categoría deja de tener botón/filtro en el catálogo (tanto en los botones grandes como en el aside), sin dejar de vender sus productos — siguen apareciendo normalmente en el listado, solo desaparece la forma de filtrar por esa categoría en particular.

= 1.17.0 =
* Nuevo ajuste "URL de tu página de catálogo" (Ajustes → Catálogo Distribuidor): antes, el enlace "Catálogo" del migajero de pan en la ficha de producto siempre apuntaba a la portada del sitio, sin importar en qué página estuviera realmente el shortcode `[catalogo_distribuidor]` — el plugin no tenía forma de saberlo. Ahora se configura explícitamente, y se usa tanto en ese enlace como en el nuevo botón nativo "Regresar al catálogo" que se agrega en la ficha (ya no depende de un script externo del distribuidor para existir). Si no se configura, cae a la portada del sitio como antes, en vez de romperse.
* La categoría del migajero de pan ahora es un enlace real (antes era solo texto) que manda al catálogo filtrado a esa categoría (`?cdb_categoria=slug`), cuando el catálogo no tiene ya fija esa categoría en el shortcode.

= 1.16.1 =
* En el layout clásico de la galería, la descripción ya no vive en la sección de ancho completo de más abajo (eso quedó solo para el layout mosaico) — ahora se imprime dentro de la misma columna de la galería, justo debajo de la imagen y los puntos de paginación. Así llena con contenido real el espacio que le sobra a esa columna cuando es más corta que la de información, en vez de dejarlo en blanco.

= 1.16.0 =
* La descripción del producto ya no vive dentro de la columna de información (angosta, ~340-380px) — ahora es una sección de ancho completo debajo de la galería y la info, igual que la tabla de medidas y las preguntas frecuentes. Un texto largo se lee mejor así, y de paso evita que quede un espacio vacío al lado cuando la galería termina antes que el resto de la información.

= 1.15.0 =
* Fix: en la galería clásica de la ficha de producto, cuando la variante seleccionada tenía 1 sola foto (o ninguna), la columna de miniaturas desaparecía por completo (`display:none`) y la imagen principal se estiraba a ocupar ese espacio — cambiando de tamaño y posición cada vez que el visitante elegía un color con menos fotos. Ahora esa columna (y los puntos de paginación) siempre reservan su espacio en el layout, tengan o no miniaturas que mostrar: si no hay, quedan vacíos e invisibles, pero sin ceder su lugar — la imagen principal ya no se mueve ni cambia de tamaño al cambiar de variante.

= 1.14.2 =
* Fix (real, reemplaza al de 1.14.1): las fotos por color NO viven dentro de cada variante — las variantes de la API casi nunca traen su propio "media". Viven en el arreglo de fotos del PRODUCTO, cada una etiquetada con el _id del valor de color al que pertenece (`optionValue`), igual patrón que ya se usaba para género (`sexo`). El intento anterior (1.14.1) buscaba fotos dentro de las variantes, que en la práctica casi nunca las tienen, así que no arreglaba nada. Ahora la ficha filtra las fotos del producto por ese `optionValue` (y luego por género) antes de decidir cuáles mostrar — al cambiar de color, la galería sí cambia. Verificado contra la API pública del catálogo.

= 1.14.1 =
* Fix: al cambiar de color en la ficha de producto, si la combinación exacta de ese color con la talla ya seleccionada no tenía fotos propias (ej. esa talla en particular no se fotografió), la galería caía a mostrar TODAS las fotos genéricas del producto mezcladas (de todos los colores) en vez de solo las del color elegido. Ahora, cuando la variante exacta no tiene fotos, se usan las de cualquier otra variante que comparta el mismo color — las fotos son por color, no por la combinación exacta color+talla. Aplica tanto al primer render como a los cambios de color en vivo (sin recargar la página).

= 1.14.0 =
* Galería clásica de la ficha de producto ("Clásica" en Ajustes → Catálogo Distribuidor → Galería en la ficha de producto), rediseñada al estilo de una ficha de producto de tienda en línea: miniaturas en columna a la izquierda, imagen principal grande a la derecha con flechas prev/next y una lupa que abre la foto actual a pantalla completa (mismo lightbox que ya usaba el layout mosaico), y puntos de paginación debajo de la imagen principal.
* El "Categorías" del catálogo (botones grandes arriba del listado) ahora se centra en vez de quedar pegado a la izquierda cuando hay pocas categorías.

= 1.13.1 =
* La tarjeta del catálogo quita el resumen de "Composición" y las "Características" — se queda solo con lo esencial (marca, nombre, categoría, colores y rango de tallas) para que se vea más limpia; ese detalle sigue disponible en la ficha completa de cada producto.

= 1.13.0 =
* Tarjetas del catálogo parejas: el cuerpo de la tarjeta ahora es una columna flexible que se estira al alto de la fila, y se agrega un botón real "Ver producto" anclado siempre al fondo (antes no existía en la plantilla del plugin) — así todas las tarjetas de una misma fila quedan del mismo tamaño y el botón al mismo nivel, sin importar que la composición o las características de un producto sean más largas que las de otro.
* Colores en la tarjeta: se siguen mostrando como máximo 6 muestras; si el producto tiene más, se agrega un indicador "+N" con el resto en vez de saturar la tarjeta.
* Nuevo: rango de tallas en la tarjeta ("Tallas: XCH – 3XG") en vez de listar cada una — usa la talla más chica y la más grande según su orden real (el mismo campo "orden" que ya define la ficha de producto), no el orden alfabético.

= 1.12.1 =
* La ficha de producto le da mucho más ancho a la galería frente a la columna de información (antes 50/50, ahora la galería lleva casi 2/3 del ancho) y el contenedor general creció de 1100px a 1360px — las fotos se ven notoriamente más grandes, tanto en la galería mosaico como en la clásica (miniaturas + imagen principal), acercándose al acomodo típico de una ficha de producto de tienda en línea.
* "Productos destacados" del aside ya no desaparece si el distribuidor todavía no marcó ningún producto como destacado en el panel admin: en ese caso se completa con los productos más recientes de su catálogo en su lugar, para que el bloque nunca se vea vacío sin explicación.

= 1.12.0 =
* Rediseño de la plantilla por defecto del catálogo (grid.php): nueva fila de "Categorías" en botones grandes con foto (arriba del listado) y una barra lateral con el filtro de categorías (con conteo de productos) y un bloque de "Productos destacados" — igual de espíritu que el archivo de una tienda con filtros, pero sin filtro de precio, porque este catálogo no maneja precios. El filtro de categoría se aplica con un simple parámetro en la URL (`?cdb_categoria=slug`, recarga de página, sin JavaScript) y respeta el atributo `categoria` del shortcode si el distribuidor ya fijó uno: en ese caso no se ofrece cambiarlo desde aquí.
* Tarjetas del catálogo más informativas: ahora muestran una insignia de "Stock disponible" (según el campo `activo` del producto), un resumen de "Composición" (de la primera variante) y hasta 4 "Características", además de las muestras de color cuando el producto tiene una opción de tipo color — todo oculto automáticamente en `estilo="compacta"`, que sigue siendo el acomodo denso de solo imagen y nombre.
* Nueva sección "Productos relacionados" al final de la ficha de producto: hasta 4 productos activos de la misma categoría.
* Nuevos métodos públicos en `Catalogo_Distribuidor_Bridge_Store`: `get_categories()`, `get_featured()`, `get_related()` y `main_image()` — disponibles también para quien use el nivel 3 de personalización (headless).

= 1.11.0 =
* Nueva galería "mosaico" (ahora por defecto, en Ajustes → Catálogo Distribuidor → "Galería en la ficha de producto"): en vez de una imagen principal con miniaturas, se ven todas las fotos del producto a la vez en un acomodo tipo mosaico (2 columnas, cada foto conserva su proporción natural). Al hacer clic en cualquiera se abre a pantalla completa (lightbox), con flechas para pasar a la siguiente/anterior y cierre con Escape. El layout clásico (imagen principal + miniaturas a la izquierda) se conserva como opción alterna para quien ya lo tenía configurado.

= 1.10.0 =
* Documentado el nivel 3 de personalización ("headless"): `Catalogo_Distribuidor_Bridge_Store::query()` y `::get_by_slug()` ya eran públicos, pero no estaban documentados — un desarrollador tenía que leer el código fuente para descubrir que podía saltarse el shortcode y las plantillas por completo. Ahora está explicado en el readme y en Ajustes → Catálogo Distribuidor, con ejemplo de uso.
* Rediseño visual de las plantillas por defecto (grid y tarjeta de producto): más consistente con el resto del plugin (radios de borde, sombras y acentos en índigo iguales a la ficha de producto), con transición al pasar el mouse sobre las tarjetas. Sigue siendo un punto de partida, no un reemplazo de una línea gráfica propia — para eso están los niveles 2 y 3.

= 1.9.0 =
* Fotos por género: cada foto (galería del producto y de cada variante) puede etiquetarse desde el panel admin como "Caballero", "Dama" o "Ambos". La ficha ahora muestra las fotos que corresponden al género seleccionado, en vez de intentar adivinar por el nombre del archivo (que fallaba cuando el nombre traía ambas palabras a la vez, ej. SKUs de dama+caballero fusionados en un mismo producto).

= 1.8.0 =
* Tabla de medidas por género: si un producto combina hombre y mujer con cortes/medidas distintas, la ficha ahora puede mostrar la tabla de medidas correcta según el género que el cliente tenga seleccionado (mismo selector que ya cambia color/talla/galería), en vez de una sola tabla genérica.

= 1.7.0 =
* Base de datos local con sincronización inmediata: el catálogo asignado ya no se consulta en vivo en cada visita, se guarda en una tabla propia de este sitio. En cuanto el administrador da de alta, edita o elimina un producto en la API, el backend avisa a este plugin al instante (webhook autenticado con la misma API Key) y la base de datos local se actualiza en segundos — no hace falta esperar ningún ciclo. Una vez al día corre además una revisión completa como respaldo, por si el aviso no llegara a tiempo (sitio caído, etc.) y para limpiar productos eliminados por completo. Si la API llega a caerse, el catálogo sigue mostrando la última sincronización buena, sin borrar nada.
* Nuevo bloque "Estado de sincronización" en Ajustes → Catálogo Distribuidor, con la hora de la última sincronización, cuántos productos hay guardados localmente, y un botón "Sincronizar ahora" para forzarla sin esperar.

= 1.6.0 =
* Nuevo bloque "CSS personalizado del catálogo" directo en Ajustes → Catálogo Distribuidor: editor de código grande (monoespaciado, ancho completo) donde se puede escribir CSS sin salir del plugin ni usar Apariencia → Personalizar → CSS adicional. Se guarda con el mismo botón "Guardar cambios" y se aplica en el sitio con `wp_add_inline_style()` (después del CSS base, para poder sobreescribirlo sin `!important`). Incluye botón "Restaurar CSS predeterminado" con confirmación. El campo se sanea eliminando cualquier etiqueta HTML/script antes de guardarse.

= 1.5.0 =
* Caché en dos capas: la copia "fresca" ahora dura 1 hora (antes 10 minutos) para reducir cuántas veces cada distribuidor le pega a la API central. Además, se guarda una copia de respaldo sin expiración: si la API llega a fallar (caída, error 5xx), el catálogo del distribuidor sigue mostrando el último dato bueno conocido en vez de un error, sin tapar respuestas legítimas como 404 o 401 (key inválida/revocada).
* Autor actualizado a Prezenza.

= 1.4.0 =
* Nivel 5 de personalización: sobreescritura de plantillas desde el tema del distribuidor (mismo patrón que WooCommerce). Si el tema activo tiene una carpeta `catalogo-distribuidor-bridge/` con `grid.php`, `product-card.php`, `detail.php` o `error.php`, el plugin usa esa versión en vez de la suya — sin dejar de manejar la conexión a la API, la autenticación ni el caché. Si el archivo no existe en el tema, se usa la plantilla por defecto, sin ningún cambio de comportamiento.
* Documentado en Ajustes → Catálogo Distribuidor.

= 1.3.1 =
* Se agrega una sección en Ajustes → Catálogo Distribuidor explicando que las clases CSS del plugin son estables y se pueden sobreescribir libremente desde Apariencia → Personalizar → CSS adicional, con ejemplos. Sin cambios de código — solo documentación visible para el distribuidor.

= 1.3.0 =
* Nuevo ajuste en Ajustes → Catálogo Distribuidor: "Galería en la ficha de producto", con dos opciones — miniaturas abajo de la imagen principal (como antes, sigue siendo lo por defecto) o miniaturas en columna a la izquierda (acomodo tipo Shein). Solo afecta la página de un producto individual, no el catálogo/listado.

= 1.2.0 =
* Nuevo atributo `estilo` en el shortcode `[catalogo_distribuidor]`: cada distribuidor puede elegir cómo se acomoda su catálogo sin tocar código — `cuadricula` (por defecto, igual que antes), `lista` (filas con más info: marca, nombre, categoría) o `compacta` (grid denso, solo imagen y nombre, para catálogos grandes). No cambia qué productos se muestran, solo su acomodo.

= 1.1.0 =
* La ficha de producto ahora tiene la misma información e interacción que el sitio React y que catalogo-api-bridge: selector de Género, círculos de color reales, tallas, galería con miniaturas por variante, Composición y SKU que se actualizan en vivo al cambiar la selección — sin recargar la página. Antes solo mostraba nombre, descripción e imágenes sueltas.
* Se agregan las secciones de Personalización, Características, Especificaciones, Tabla de medidas y Preguntas frecuentes cuando el producto las trae.
* Fix: se agrega `grid-column: 1 / -1` al contenedor principal de la ficha — en temas con un `<main>` de layout tipo grid, el detalle podía comprimirse a un ancho mínimo porque no indicaba cuántas columnas debía ocupar. (Mismo fix que catalogo-api-bridge v1.0.2.)

= 1.0.1 =
* Fix: en algunos temas la columna de la galería de imágenes en la ficha de producto se colapsaba a 0px de ancho (conflicto de CSS Grid con el layout del tema). Se agrega `min-width: 0` a las columnas y `width: 100%` a los contenedores para que no dependan del contexto del tema. (Mismo fix aplicado en catalogo-api-bridge v1.0.1.)

= 1.0.0 =
* Primera versión: listado, detalle, caché, manejo de errores/404/401. Sin precios.
