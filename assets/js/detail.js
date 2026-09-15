/**
 * Interactividad de la ficha de producto — idéntico a catalogo-api-bridge,
 * duplicado a propósito (mismo criterio que class-colors.php) para que este
 * plugin no dependa del otro. Puerto a JS vanilla de la lógica de
 * selección/variante/galería de ProductoDetalle.jsx.
 */
(function () {
	'use strict';

	function dedupeByUrl(list) {
		var seen = {};
		var out = [];
		(list || []).forEach(function (m) {
			if (m && m.url && !seen[m.url]) {
				seen[m.url] = true;
				out.push(m);
			}
		});
		return out;
	}

	// Filtra por género usando la etiqueta real de cada foto (m.sexo, puesta a
	// mano en el panel admin) — las fotos sin etiquetar sirven para cualquier
	// género, así que nunca desaparecen por no marcarse.
	function filterGenero(imgs, sexo) {
		if (!sexo) return imgs;
		var propias = imgs.filter(function (m) { return m.sexo === sexo; });
		var genericas = imgs.filter(function (m) { return !m.sexo; });
		var out = propias.concat(genericas);
		return out.length ? out : imgs;
	}

	// Mismos trazos que los <svg> inline de templates/detail.php — duplicados
	// a propósito (igual que el resto del plugin) para no depender de nada
	// más que este archivo cuando el JS reconstruye el mosaico.
	var ICON_ZOOM = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';

	function findVariant(variants, selected) {
		var selectedIds = Object.keys(selected).map(function (k) { return selected[k]; }).sort();
		for (var i = 0; i < variants.length; i++) {
			var v = variants[i];
			var ids = (v.optionValues || []).map(function (ov) { return ov._id; }).sort();
			if (ids.length === selectedIds.length && ids.join('|') === selectedIds.join('|')) return v;
		}
		return null;
	}

	// Mismo criterio que ya usa detail.php para distinguir el eje de color de
	// los demás (talla, etc.).
	function findColorOptionId(options) {
		for (var i = 0; i < (options || []).length; i++) {
			var opt = options[i].option || {};
			var isColor = opt.tipo === 'swatch' || /color/i.test(opt.slug || '') || /color/i.test(opt.nombre || '');
			if (isColor) return opt._id;
		}
		return null;
	}

	// Filtra por color usando la etiqueta real de cada foto (m.optionValue,
	// el _id del valor de color al que pertenece esa foto) — mismo patrón que
	// filterGenero, solo que por color. Las variantes de esta API casi nunca
	// traen su propio "media": las fotos de color viven en las del producto.
	function filterColor(imgs, colorValueId) {
		if (!colorValueId) return imgs;
		var propias = imgs.filter(function (m) { return m.optionValue === colorValueId; });
		var genericas = imgs.filter(function (m) { return !m.optionValue; });
		var out = propias.concat(genericas);
		return out.length ? out : imgs;
	}

	function init(article) {
		var data;
		try {
			data = JSON.parse(article.getAttribute('data-product'));
		} catch (e) {
			return;
		}

		var wrap = article.closest('.catalogo-dist-bridge-detail-wrap');
		var mainImgFrameEl = wrap.querySelector('[data-role="main-img-frame"]');
		var thumbsEl = wrap.querySelector('[data-role="gallery-thumbs"]');
		var dotsEl = wrap.querySelector('[data-role="gallery-dots"]');
		var prevBtnEl = wrap.querySelector('[data-role="gallery-prev"]');
		var nextBtnEl = wrap.querySelector('[data-role="gallery-next"]');
		var expandBtnEl = wrap.querySelector('[data-role="gallery-expand"]');
		var mosaicEl = wrap.querySelector('[data-role="gallery-mosaic"]');
		var lightboxEl = wrap.querySelector('[data-role="lightbox"]');
		var lightboxImgEl = wrap.querySelector('[data-role="lightbox-img"]');
		var skuLineEl = wrap.querySelector('[data-role="sku-line"]');
		var skuFullEl = wrap.querySelector('[data-role="sku-full"]');
		var composicionSection = wrap.querySelector('[data-role="composicion-section"]');
		var composicionText = wrap.querySelector('[data-role="composicion-text"]');
		var generoValueEl = wrap.querySelector('[data-role="genero-value"]');

		var selected = {};
		wrap.querySelectorAll('.cdb-swatch.active, .cdb-pill.active[data-option]').forEach(function (btn) {
			selected[btn.getAttribute('data-option')] = btn.getAttribute('data-value');
		});
		var selSexo = null;
		var activeGenero = wrap.querySelector('.cdb-genero-btn.active');
		if (activeGenero) selSexo = activeGenero.getAttribute('data-sexo');
		var imgIdx = 0;
		var currentImages = [];
		var colorOptionId = findColorOptionId(data.options);

		function renderGallery(variant) {
			// Las variantes de esta API casi nunca traen su propio "media" —
			// cuando sí lo traen, se respeta tal cual; si no, se usan las fotos
			// del producto filtradas primero por color y luego por género.
			var variantMedia = dedupeByUrl((variant && variant.media) || []);
			var colorValueId = colorOptionId ? selected[colorOptionId] : null;
			var productMediaByColor = filterColor(dedupeByUrl(data.productMedia || []), colorValueId);
			var baseImages = variantMedia.length ? variantMedia : productMediaByColor;
			currentImages = filterGenero(baseImages, selSexo);
			imgIdx = 0;

			if (mosaicEl) {
				renderMosaic();
				return;
			}

			// Layout clásico (miniaturas a la izquierda): imagen principal +
			// tira de miniaturas que la reemplazan al hacer clic, con flechas
			// prev/next, puntos de paginación y una lupa que abre el lightbox
			// compartido — todos elementos siempre presentes en el HTML (el JS
			// solo los muestra u oculta), así nunca hay que reconstruirlos.
			if (!currentImages.length) {
				mainImgFrameEl.innerHTML = '<div class="catalogo-dist-bridge-no-img">Sin imagen</div>';
			} else {
				mainImgFrameEl.innerHTML = '<img src="' + currentImages[0].url + '" alt="" class="cdb-main-img-el" />';
			}
			if (expandBtnEl) expandBtnEl.style.display = currentImages.length ? '' : 'none';

			var hayVarias = currentImages.length > 1;
			if (prevBtnEl) prevBtnEl.style.display = hayVarias ? '' : 'none';
			if (nextBtnEl) nextBtnEl.style.display = hayVarias ? '' : 'none';

			// El contenedor de miniaturas nunca se oculta con display:none: eso
			// le quitaba su espacio reservado en el layout y hacía que la
			// imagen principal cambiara de tamaño/posición al pasar a una
			// variante con menos fotos. Si no hay varias, se deja vacío — el
			// CSS (.cdb-thumbs:empty) lo oculta visualmente sin quitarle el
			// espacio.
			if (thumbsEl) {
				if (hayVarias) {
					var html = '';
					currentImages.forEach(function (im, i) {
						html += '<button type="button" class="cdb-thumb' + (i === 0 ? ' active' : '') + '" data-idx="' + i + '"><img src="' + im.url + '" alt="" /></button>';
					});
					thumbsEl.innerHTML = html;
				} else {
					thumbsEl.innerHTML = '';
				}
			}

			if (dotsEl) {
				if (hayVarias) {
					var dotsHtml = '';
					currentImages.forEach(function (im, i) {
						dotsHtml += '<button type="button" class="cdb-gallery-dot' + (i === 0 ? ' active' : '') + '" data-idx="' + i + '" aria-label="Foto ' + (i + 1) + '"></button>';
					});
					dotsEl.innerHTML = dotsHtml;
				} else {
					dotsEl.innerHTML = '';
				}
			}
		}

		function selectThumb(i) {
			if (!currentImages[i]) return;
			imgIdx = i;
			mainImgFrameEl.innerHTML = '<img src="' + currentImages[i].url + '" alt="" class="cdb-main-img-el" />';
			if (thumbsEl) {
				thumbsEl.querySelectorAll('.cdb-thumb').forEach(function (t, ti) {
					t.classList.toggle('active', ti === i);
				});
			}
			if (dotsEl) {
				dotsEl.querySelectorAll('.cdb-gallery-dot').forEach(function (d, di) {
					d.classList.toggle('active', di === i);
				});
			}
		}

		// Layout mosaico (por defecto): todas las fotos visibles a la vez, sin
		// concepto de "principal" — se reconstruye completo en cada cambio de
		// variante/género, igual que la tabla de medidas.
		function renderMosaic() {
			if (!currentImages.length) {
				mosaicEl.innerHTML = '<div class="catalogo-dist-bridge-no-img">Sin imagen</div>';
				return;
			}
			var html = '';
			currentImages.forEach(function (im, i) {
				html += '<button type="button" class="cdb-mosaic-item" data-idx="' + i + '">' +
					'<img src="' + im.url + '" alt="" loading="' + (i === 0 ? 'eager' : 'lazy') + '" />' +
					'<span class="cdb-mosaic-zoom" aria-hidden="true">' + ICON_ZOOM + '</span>' +
					'</button>';
			});
			mosaicEl.innerHTML = html;
		}

		function openLightbox(i) {
			if (!lightboxEl || !currentImages[i]) return;
			imgIdx = i;
			lightboxImgEl.src = currentImages[i].url;
			lightboxEl.classList.add('is-open');
			lightboxEl.classList.toggle('cdb-lightbox--single', currentImages.length <= 1);
			document.body.style.overflow = 'hidden';
		}

		function closeLightbox() {
			if (!lightboxEl) return;
			lightboxEl.classList.remove('is-open');
			document.body.style.overflow = '';
		}

		function stepLightbox(delta) {
			if (!currentImages.length) return;
			var next = (imgIdx + delta + currentImages.length) % currentImages.length;
			openLightbox(next);
		}

		function render() {
			var variant = findVariant(data.variants || [], selected);

			renderGallery(variant);

			if (variant && variant.composicion) {
				composicionText.textContent = variant.composicion;
				composicionSection.style.display = '';
			} else if (composicionSection) {
				composicionSection.style.display = 'none';
			}

			if (variant) {
				var txt = 'SKU ' + variant.sku;
				if (variant.stock > 0) txt += ' · ' + variant.stock + ' en stock';
				skuFullEl.textContent = txt;
				skuLineEl.style.display = '';
			} else if (skuLineEl) {
				skuLineEl.style.display = 'none';
			}

			wrap.querySelectorAll('[data-option-id]').forEach(function (section) {
				var optId = section.getAttribute('data-option-id');
				var valId = selected[optId];
				var label = '';
				section.querySelectorAll('.cdb-swatch, .cdb-pill').forEach(function (btn) {
					var isActive = btn.getAttribute('data-value') === valId;
					btn.classList.toggle('active', isActive);
					if (isActive) label = btn.getAttribute('data-label') || '';
				});
				var valueEl = section.querySelector('[data-role="option-value"]');
				if (valueEl) valueEl.textContent = label;
			});

			wrap.querySelectorAll('.cdb-genero-btn').forEach(function (btn) {
				btn.classList.toggle('active', btn.getAttribute('data-sexo') === selSexo);
			});
			if (generoValueEl) {
				generoValueEl.textContent = (data.sexoLabel && data.sexoLabel[selSexo]) || selSexo || '';
			}
		}

		wrap.addEventListener('click', function (e) {
			var swatchOrPill = e.target.closest('.cdb-swatch[data-option], .cdb-pill[data-option]');
			if (swatchOrPill) {
				selected[swatchOrPill.getAttribute('data-option')] = swatchOrPill.getAttribute('data-value');
				render();
				return;
			}
			var generoBtn = e.target.closest('.cdb-genero-btn');
			if (generoBtn) {
				selSexo = generoBtn.getAttribute('data-sexo');
				render();
				return;
			}
			var thumb = e.target.closest('.cdb-thumb');
			if (thumb) {
				selectThumb(parseInt(thumb.getAttribute('data-idx'), 10));
				return;
			}
			var dot = e.target.closest('.cdb-gallery-dot');
			if (dot) {
				selectThumb(parseInt(dot.getAttribute('data-idx'), 10));
				return;
			}
			if (e.target.closest('[data-role="gallery-prev"]')) {
				selectThumb((imgIdx - 1 + currentImages.length) % currentImages.length);
				return;
			}
			if (e.target.closest('[data-role="gallery-next"]')) {
				selectThumb((imgIdx + 1) % currentImages.length);
				return;
			}
			if (e.target.closest('[data-role="gallery-expand"]')) {
				openLightbox(imgIdx);
				return;
			}
			var mosaicItem = e.target.closest('.cdb-mosaic-item');
			if (mosaicItem) {
				openLightbox(parseInt(mosaicItem.getAttribute('data-idx'), 10));
				return;
			}
			if (lightboxEl && lightboxEl.classList.contains('is-open')) {
				if (e.target.closest('[data-role="lightbox-close"]') || e.target === lightboxEl) {
					closeLightbox();
				} else if (e.target.closest('[data-role="lightbox-prev"]')) {
					stepLightbox(-1);
				} else if (e.target.closest('[data-role="lightbox-next"]')) {
					stepLightbox(1);
				}
			}
		});

		if (lightboxEl) {
			document.addEventListener('keydown', function (e) {
				if (!lightboxEl.classList.contains('is-open')) return;
				if (e.key === 'Escape') closeLightbox();
				else if (e.key === 'ArrowLeft') stepLightbox(-1);
				else if (e.key === 'ArrowRight') stepLightbox(1);
			});
		}

		var initialVariant = findVariant(data.variants || [], selected);
		var variantMedia = dedupeByUrl((initialVariant && initialVariant.media) || []);
		var initialColorValueId = colorOptionId ? selected[colorOptionId] : null;
		var productMediaByColor = filterColor(dedupeByUrl(data.productMedia || []), initialColorValueId);
		var baseImages = variantMedia.length ? variantMedia : productMediaByColor;
		currentImages = filterGenero(baseImages, selSexo);
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.catalogo-dist-bridge-detail[data-product]').forEach(init);
	});
})();
