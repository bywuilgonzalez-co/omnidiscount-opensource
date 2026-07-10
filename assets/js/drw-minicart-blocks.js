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
 * src/Controllers/StoreApiController.php). No se cambia nada del lado servidor;
 * este script solo LEE ese dato desde el data store 'wc/store/cart' de
 * @wordpress/data, donde WooCommerce Blocks lo copia bajo
 * cart.extensions['discount-rules-woo'].
 *
 * Enfoque deliberado: en vez de un slot-fill oficial de @woocommerce/blocks-checkout
 * (registerPlugin + ExperimentalOrderMeta/Fragments), que exigiría fijar una
 * versión exacta del paquete @woocommerce/blocks-checkout como dependencia de
 * script — algo frágil sin poder probarlo en un sitio real — este archivo observa
 * el DOM con MutationObserver y, cuando encuentra el drawer del bloque Mini-Cart,
 * inyecta/actualiza un badge de solo lectura directamente. No usa
 * wp.plugins/registerPlugin.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO VERIFICADO EN NAVEGADOR REAL. Este entorno no tiene acceso a un WordPress +
 * WooCommerce real con el bloque Mini-Cart activo, así que NADA de lo siguiente
 * fue confirmado en vivo — es la mejor estimación posible basada en convenciones
 * conocidas de WordPress core / WooCommerce Blocks. Antes de confiar en este
 * archivo en producción, en un sitio real con el bloque Mini-Cart:
 *
 *   1. Selector del drawer — DRAWER_SELECTORS abajo asume '.wc-block-mini-cart__drawer'
 *      siguiendo el patrón 'wc-block-{nombre-de-bloque}' que usan los demás bloques
 *      de WooCommerce, pero no hay confirmación de que sea la clase real, ni de
 *      qué versión de WooCommerce/WooCommerce Blocks se probó.
 *   2. Selector/API del data store — se asume que
 *      wp.data.select('wc/store/cart').getCartData() existe y expone las
 *      extensiones de Store API bajo '.extensions["discount-rules-woo"]' (el
 *      patrón documentado de ExtendSchema de WooCommerce Blocks); el nombre exacto
 *      del selector y el shape del objeto deben confirmarse ejecutando
 *      `wp.data.select('wc/store/cart').getCartData()` en la consola del navegador.
 *   3. Si los notices de wc_add_notice() (StoreApiController::emit_promo_cart_notices())
 *      YA se muestran solos dentro del Mini-Cart Block (WooCommerce podría
 *      suprimirlos deliberadamente en esa UI compacta) — no se investigó ni se
 *      asume nada al respecto aquí. Este badge es una vía independiente y
 *      complementaria, no depende de esa respuesta.
 *   4. Punto de inserción dentro del drawer — se inyecta como PRIMER hijo del nodo
 *      del drawer por ser la opción menos dependiente de sub-clases internas; la
 *      ubicación visualmente ideal (p. ej. junto al botón de checkout) puede
 *      requerir un selector más específico una vez confirmado en vivo.
 *   5. Riesgo de reconciliación de React — el drawer del Mini-Cart Block es un
 *      árbol React; React podría remover el nodo inyectado en su siguiente render.
 *      El MutationObserver reinserta el badge cuando eso ocurre, pero el efecto
 *      visual (¿parpadeo?) no fue probado en vivo.
 * ─────────────────────────────────────────────────────────────────────────────
 */
(function () {
	'use strict';

	// wp-data es un script core de WordPress desde 5.0 (misma familia que
	// wp-element, ya usado por admin-app.js/drw-promo-wizard.js). AdminController
	// declara 'wp-data' como dependencia al encolar este archivo (ver
	// StoreApiController::enqueue_minicart_blocks_assets()); este guard solo evita
	// un error si por lo que sea no está disponible.
	if (typeof wp === 'undefined' || !wp.data || typeof wp.data.select !== 'function' || typeof wp.data.subscribe !== 'function') {
		return;
	}
	if (typeof MutationObserver === 'undefined' || typeof document.querySelector !== 'function') {
		return;
	}

	var STORE_NAME = 'wc/store/cart';
	var EXTENSION_NS = 'discount-rules-woo';

	// Ver punto 1 del bloque "NO VERIFICADO EN NAVEGADOR REAL" arriba.
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
	 * Lee 'promos' desde el data store del Store API. Devuelve null (no [])
	 * cuando el store 'wc/store/cart' todavía no está registrado/listo, para
	 * distinguir "sin datos todavía" de "carrito sin promos" — ver punto 2 del
	 * bloque "NO VERIFICADO" arriba.
	 */
	function getPromos() {
		var store = wp.data.select(STORE_NAME);
		if (!store || typeof store.getCartData !== 'function') {
			return null;
		}
		var cartData = store.getCartData();
		var ext = cartData && cartData.extensions ? cartData.extensions[EXTENSION_NS] : null;
		return (ext && Object.prototype.toString.call(ext.promos) === '[object Array]') ? ext.promos : [];
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

		var promos = getPromos();
		if (promos === null) {
			// Store todavía no listo; el siguiente subscribe()/MutationObserver
			// reintentará automáticamente.
			return;
		}

		var rows = visibleRows(promos);
		var existingWrap = drawer.querySelector('.' + WRAP_CLASS);

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
			// innecesario en cada tick de wp.data.subscribe()).
			return;
		}

		var wrap = buildWrap(rows);
		wrap.setAttribute('data-drw-sig', signature);

		if (existingWrap && existingWrap.parentNode) {
			existingWrap.parentNode.replaceChild(wrap, existingWrap);
			return;
		}

		// Punto 4 del bloque "NO VERIFICADO EN NAVEGADOR REAL" arriba: primer hijo
		// del drawer, la posición menos dependiente de sub-clases internas.
		wrap.setAttribute('data-drw-entering', '');
		drawer.insertBefore(wrap, drawer.firstChild);
		window.requestAnimationFrame(function () {
			wrap.removeAttribute('data-drw-entering');
		});
	}

	var syncQueued = false;
	function scheduleSync() {
		if (syncQueued) {
			return;
		}
		syncQueued = true;
		window.requestAnimationFrame(function () {
			syncQueued = false;
			sync();
		});
	}

	// Reacciona a cambios del carrito (cantidades, promos, etc.) vía @wordpress/data.
	wp.data.subscribe(scheduleSync);

	// Reacciona a la apertura/cierre del drawer (montaje/desmontaje del bloque) y,
	// en el mismo movimiento, a un posible re-render de React que haya quitado el
	// badge inyectado — ver punto 5 del bloque "NO VERIFICADO" arriba.
	var observer = new MutationObserver(scheduleSync);
	observer.observe(document.body, { childList: true, subtree: true });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scheduleSync);
	} else {
		scheduleSync();
	}
})();
