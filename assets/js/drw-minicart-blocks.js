/**
 * OmniDiscount — Mini-Cart (Bloques) badge de promos
 *
 * Distinto del mini-cart CLÁSICO (widget "Cart"/[woocommerce_cart], cubierto por
 * CartController::render_minicart_promos_html() vía el hook
 * woocommerce_widget_shopping_cart_before_buttons — ver ".drw-minicart-promo*" en
 * assets/css/public-style.css). Este archivo cubre el bloque Mini-Cart de
 * WooCommerce Blocks (woocommerce/mini-cart), que renderiza su propio árbol React
 * del lado del cliente y no pasa por esos hooks PHP clásicos.
 *
 * Fuente de datos: el campo 'promos' que StoreApiController::get_cart_extension_data()
 * ya expone en el Store API bajo el namespace 'discount-rules-woo' (registrado vía
 * woocommerce_store_api_register_endpoint_data() — ver
 * src/Controllers/StoreApiController.php).
 *
 * VERIFICADO EN NAVEGADOR REAL (sitio Local, Storefront + WooCommerce Blocks):
 * el selector del drawer '.wc-block-mini-cart__drawer' es correcto y el drawer sí
 * se abre con datos reales. PERO el intento original de leer los datos vía
 * wp.data.select('wc/store/cart') falló en vivo: en el frontend de este sitio,
 * `window.wp.data.stores` está vacío — el bundle de WooCommerce Blocks para el
 * frontend NO comparte su registro interno de @wordpress/data con la instancia
 * global `wp.data` que este script recibe como dependencia. Por eso la fuente de
 * datos se cambió a leer directamente el mismo endpoint REST que WooCommerce
 * Blocks consume internamente (wc/store/v1/cart), vía fetch — evita por completo
 * la incertidumbre del registro de módulos y no depende de 'wp-data'.
 *
 * Enfoque: MutationObserver detecta cuando el drawer del Mini-Cart está en el DOM
 * y lo abre el usuario; en ese momento (y cuando cambia el carrito) se hace un
 * fetch a wc/store/v1/cart y se inyecta/actualiza un badge de solo lectura dentro
 * de '.wc-block-components-drawer__content' (ver findInsertionPoint()), justo
 * antes de la lista de productos. No usa wp.plugins/registerPlugin ni ningún
 * slot-fill oficial de @woocommerce/blocks-checkout.
 *
 * También verificado en vivo: insertar como primer hijo del DRAWER en sí (en vez
 * de su wrapper '__content') deja el badge flotando por encima del título y el
 * botón de cerrar, fuera del panel con padding — de ahí findInsertionPoint().
 *
 * También verificado en vivo: el debounce de scheduleSync() usa setTimeout, no
 * requestAnimationFrame — rAF nunca se ejecuta mientras la pestaña está en
 * segundo plano (document.hidden=true), comportamiento estándar de todo
 * navegador, y el badge debe poder aparecer aunque el carrito cambie con la
 * pestaña sin foco.
 *
 * Riesgo de reconciliación de React: el drawer del Mini-Cart Block es un árbol
 * React; React podría remover el nodo inyectado en su siguiente render. El
 * MutationObserver reinserta el badge cuando eso ocurre.
 */
(function () {
	'use strict';

	if (typeof MutationObserver === 'undefined' || typeof document.querySelector !== 'function' || typeof fetch !== 'function') {
		return;
	}

	var STORE_API_CART_URL = (window.wc && window.wc.wcSettings && typeof window.wc.wcSettings.getSetting === 'function' && window.wc.wcSettings.getSetting('storeApiUrl'))
		? window.wc.wcSettings.getSetting('storeApiUrl').replace(/\/$/, '') + '/cart'
		: '/wp-json/wc/store/v1/cart';
	var EXTENSION_NS = 'discount-rules-woo';

	// Confirmado en vivo: coincide con la clase real que renderiza WooCommerce Blocks.
	var DRAWER_SELECTORS = [
		'.wc-block-mini-cart__drawer'
	];

	var WRAP_CLASS = 'drw-minicart-blocks-promos';
	var ROW_CLASS = 'drw-minicart-blocks-promo';

	function findDrawer() {
		for (var i = 0; i < DRAWER_SELECTORS.length; i++) {
			var node = document.querySelector(DRAWER_SELECTORS[i]);
			if (node) {
				return node;
			}
		}
		return null;
	}

	/**
	 * El drawer en sí solo tiene DOS hijos directos: el título/botón de cerrar
	 * viven dentro de '.wc-block-components-drawer__content' (confirmado en
	 * vivo) — insertar como primer hijo del DRAWER (en vez de ese wrapper de
	 * contenido) deja el badge flotando por ENCIMA del título y el botón de
	 * cerrar, fuera del padding del panel.
	 *
	 * También confirmado en vivo: '.wc-block-mini-cart__items' NO es hijo
	 * DIRECTO de '.wc-block-components-drawer__content' (hay un nivel intermedio,
	 * '.wc-block-mini-cart__template-part') — insertBefore(nodo, ancla) exige que
	 * `ancla` sea hijo directo del contenedor en el que se llama, así que usar
	 * '__content' como contenedor con la lista de productos como ancla lanza
	 * DOMException (silenciosa: no hay .catch() en la promesa) y el badge nunca
	 * aparece. El contenedor correcto es el PADRE REAL de la lista de productos,
	 * sea cual sea su profundidad de anidamiento.
	 */
	function findInsertionPoint(drawer) {
		var content = drawer.querySelector('.wc-block-components-drawer__content') || drawer;
		var itemsBlock = content.querySelector(
			'.wc-block-mini-cart__items, .wp-block-woocommerce-mini-cart-items-block'
		);
		if (itemsBlock && itemsBlock.parentNode) {
			return { container: itemsBlock.parentNode, anchor: itemsBlock };
		}
		return { container: content, anchor: content.firstChild };
	}

	// Evita golpear el endpoint en cada mutación del DOM (el drawer del Mini-Cart
	// re-renderiza seguido); una promesa en vuelo se reutiliza y el resultado se
	// cachea por FETCH_MIN_INTERVAL_MS.
	var FETCH_MIN_INTERVAL_MS = 800;
	var lastFetchAt = 0;
	var lastFetchPromise = null;

	/**
	 * Lee 'promos' desde el mismo endpoint REST del Store API que WooCommerce
	 * Blocks consume para pintar el drawer (wc/store/v1/cart), no desde un data
	 * store de @wordpress/data — ver la nota "VERIFICADO EN NAVEGADOR REAL" al
	 * inicio del archivo sobre por qué se abandonó ese enfoque. Devuelve una
	 * Promise<Array>; en caso de error de red devuelve [] (no rompe el resto del
	 * carrito, que ya se pintó por su cuenta).
	 */
	function getPromos() {
		var now = Date.now();
		if (lastFetchPromise && (now - lastFetchAt) < FETCH_MIN_INTERVAL_MS) {
			return lastFetchPromise;
		}
		lastFetchAt = now;
		lastFetchPromise = fetch(STORE_API_CART_URL, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (res) { return res.ok ? res.json() : null; })
			.then(function (cartData) {
				var ext = cartData && cartData.extensions ? cartData.extensions[EXTENSION_NS] : null;
				return (ext && Object.prototype.toString.call(ext.promos) === '[object Array]') ? ext.promos : [];
			})
			['catch'](function () {
				return [];
			});
		return lastFetchPromise;
	}

	/**
	 * Misma regla de "¿se muestra?" que ya usa PHP en dos sitios — si esa lógica
	 * cambia ahí, hay que replicarla aquí manualmente:
	 *   - Drw\App\Controllers\StoreApiController::emit_promo_cart_notices()
	 *   - Drw\App\Controllers\CartController::render_minicart_promos_html()
	 * (ambas delegan en Drw\App\Models\PromoBadgeHelper::collect() para construir
	 * cada badge; esta función solo repite el filtro "should_show" sobre el mismo
	 * shape que 'promos' ya trae).
	 */
	function visibleRows(promos) {
		var rows = [];
		for (var i = 0; i < promos.length; i++) {
			var badge = promos[i] || {};
			var isThreshold = !!badge.progress;
			var remaining = isThreshold ? parseFloat(badge.progress.remaining) : 0;
			var applied = !!badge.applied;
			var shouldShow = isThreshold ? (!applied && remaining > 0) : applied;
			if (!shouldShow) {
				continue;
			}
			var text = badge.message || badge.title || '';
			if (text === '') {
				continue;
			}
			rows.push({ text: text, applied: applied });
		}
		return rows;
	}

	function rowsSignature(rows) {
		var parts = [];
		for (var i = 0; i < rows.length; i++) {
			parts.push((rows[i].applied ? '1' : '0') + ':' + rows[i].text);
		}
		return parts.join('|');
	}

	function buildWrap(rows) {
		var wrap = document.createElement('div');
		wrap.className = WRAP_CLASS;
		// Solo informativo, no interactivo: sin tabindex/foco, aria-live para que
		// un cambio de mensaje (p.ej. "Te faltan $5.000" -> desbloqueado) se
		// anuncie sin robar el foco del usuario.
		wrap.setAttribute('aria-live', 'polite');

		for (var i = 0; i < rows.length; i++) {
			var row = document.createElement('div');
			row.className = ROW_CLASS + (rows[i].applied ? ' is-applied' : ' is-progress');

			var mark = document.createElement('span');
			mark.className = ROW_CLASS + '__mark';
			mark.setAttribute('aria-hidden', 'true');

			var text = document.createElement('span');
			text.className = ROW_CLASS + '__text';
			// textContent (no innerHTML): cart_message ya llega como texto plano
			// (sanitize_text_field en PromosController::validate_promo()), pero se
			// usa textContent de todas formas como defensa en profundidad.
			text.textContent = rows[i].text;

			row.appendChild(mark);
			row.appendChild(text);
			wrap.appendChild(row);
		}

		return wrap;
	}

	function sync() {
		var drawer = findDrawer();
		if (!drawer) {
			return;
		}

		getPromos().then(function (promos) {
			// El drawer puede haberse cerrado/desmontado mientras el fetch estaba
			// en vuelo; volver a resolverlo en vez de asumir que sigue en el DOM.
			var currentDrawer = findDrawer();
			if (!currentDrawer) {
				return;
			}

			var rows = visibleRows(promos);
			var existingWrap = currentDrawer.querySelector('.' + WRAP_CLASS);

			if (rows.length === 0) {
				// Mismo criterio "cero huella en el DOM" que
				// CartController::render_minicart_promos_html(): sin promo aplicable,
				// no queda ningún nodo del badge.
				if (existingWrap && existingWrap.parentNode) {
					existingWrap.parentNode.removeChild(existingWrap);
				}
				return;
			}

			var signature = rowsSignature(rows);
			if (existingWrap && existingWrap.getAttribute('data-drw-sig') === signature) {
				// Mismo contenido: no reescribir el DOM (evita reflow/parpadeo
				// innecesario en cada mutación observada).
				return;
			}

			var wrap = buildWrap(rows);
			wrap.setAttribute('data-drw-sig', signature);

			if (existingWrap && existingWrap.parentNode) {
				existingWrap.parentNode.replaceChild(wrap, existingWrap);
				return;
			}

			var target = findInsertionPoint(currentDrawer);
			wrap.setAttribute('data-drw-entering', '');
			target.container.insertBefore(wrap, target.anchor);
			window.setTimeout(function () {
				wrap.removeAttribute('data-drw-entering');
			}, 16);
		})['catch'](function (e) {
			// Una excepción aquí (p. ej. un futuro cambio de marcado de WooCommerce
			// Blocks que rompa un supuesto de findInsertionPoint()) no debe tumbar
			// el resto del carrito — pero tampoco debe desaparecer en silencio como
			// pasó con el bug real de insertBefore() encontrado en pruebas: sin
			// esta rama, la promesa rechazada quedaba como "unhandled rejection" y
			// el badge simplemente nunca aparecía, sin ninguna pista en consola.
			if (window.console && console.error) {
				console.error('[OmniDiscount] mini-cart blocks badge sync failed:', e);
			}
		});
	}

	// setTimeout, no requestAnimationFrame: verificado en vivo que rAF nunca se
	// ejecuta mientras la pestaña está en segundo plano (document.hidden=true) —
	// comportamiento estándar de todos los navegadores, no un artefacto de
	// pruebas. El badge debe aparecer aunque el carrito cambie con la pestaña sin
	// foco (p. ej. abierta desde otra pestaña), así que el debounce no puede
	// depender de una API atada al ciclo de pintado visual.
	var syncQueued = false;
	function scheduleSync() {
		if (syncQueued) {
			return;
		}
		syncQueued = true;
		window.setTimeout(function () {
			syncQueued = false;
			sync();
		}, 50);
	}

	// Reacciona a la apertura/cierre del drawer (montaje/desmontaje del bloque),
	// a cambios de cantidades y a un posible re-render de React que haya quitado
	// el badge inyectado. getPromos() se autolimita (FETCH_MIN_INTERVAL_MS), así
	// que observar todo document.body es seguro pese a ser una superficie amplia.
	var observer = new MutationObserver(scheduleSync);
	observer.observe(document.body, { childList: true, subtree: true });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scheduleSync);
	} else {
		scheduleSync();
	}
})();
