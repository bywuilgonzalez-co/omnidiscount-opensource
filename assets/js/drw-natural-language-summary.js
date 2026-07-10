/**
 * OmniDiscount — NaturalLanguageSummary component
 *
 * Translates a promo draft (the wizard's camelCase form shape: name, code,
 * type, value, scope {target, ids}, minAmount, limitGlobal, limitUser, start,
 * end, giftText, tiers, bundle...) into one warm, human sentence in Spanish,
 * as if a trusted store advisor were explaining the promotion to the owner.
 *
 * Example output:
 *   "2x1 en la categoría Camisetas, se aplica solo automáticamente,
 *    del 15 al 30 de julio, y cada cliente podrá usarla 1 vez."
 *
 * Covers explicitly: percent, fixed, 2x1, 3x2, free_ship_threshold, bundle,
 * gift, tiered. Any other type falls back to a reasonable generic phrase
 * built from its label in the shared catalogue (PromoTypeRegistry, delivered
 * to the browser as window.drwAdminData.promoTypes).
 *
 * Also understands the RULE shape used by the RuleEditor in admin-app.js
 * (title, apply_to, filters, adjustments {type: percentage/fixed/bulk/bogo/
 * free_shipping/bundle_set}, conditions[]), via a parallel pure builder:
 * window.DrwNaturalLanguageSummary.build_rule(rule). Example output:
 *   "2x1 en la categoría Camisetas, solo si el carrito lleva 3 artículos
 *    o más, y no se combina con otras reglas."
 *
 * Uses WordPress core React (wp-element), same as the other window.Drw*
 * components. Exposes window.DrwNaturalLanguageSummary (the component) and
 * window.DrwNaturalLanguageSummary.build(promoDraft) (the pure phrase
 * builder, handy for tests or plain-text contexts).
 *
 * Usage:
 *   el(window.DrwNaturalLanguageSummary, { promoDraft: f })   // Promos wizard
 *   el(window.DrwNaturalLanguageSummary, { rule: r })         // Rule editor
 */
(function () {
	'use strict';

	var el = wp.element.createElement;

	var PROMO_TYPES = (window.drwAdminData && window.drwAdminData.promoTypes) || [];
	var CATEGORIES = (window.drwAdminData && window.drwAdminData.categories) || [];
	var ROLES = (window.drwAdminData && window.drwAdminData.roles) || [];

	var MONTHS = [
		'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
		'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'
	];

	function typeLabel(id) {
		for (var i = 0; i < PROMO_TYPES.length; i++) {
			if (PROMO_TYPES[i].id === id) { return PROMO_TYPES[i].label || id; }
		}
		return id || 'Promoción';
	}

	function categoryName(id) {
		for (var i = 0; i < CATEGORIES.length; i++) {
			if (Number(CATEGORIES[i].id) === Number(id)) { return CATEGORIES[i].name; }
		}
		return null;
	}

	function fmtMoney(v) {
		return '$' + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
	}

	/**
	 * Parse a 'Y-m-d' string without timezone surprises.
	 *
	 * @param {string} s Date string.
	 * @return {{d: number, m: number, y: number}|null}
	 */
	function parseYmd(s) {
		if (typeof s !== 'string') { return null; }
		var m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
		if (!m) { return null; }
		return { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) };
	}

	function fmtDay(p, withYear) {
		var out = p.d + ' de ' + MONTHS[p.m - 1];
		if (withYear) { out += ' de ' + p.y; }
		return out;
	}

	// =======================================================================
	// Phrase fragments
	// =======================================================================

	/**
	 * What the promo IS — the opening of the sentence, per type.
	 */
	function basePhrase(draft) {
		var type = draft.type || '';
		var value = Number(draft.value) || 0;

		switch (type) {
			case 'percent':
				return value > 0 ? value + '% de descuento' : 'Un descuento porcentual';

			case 'fixed':
				return value > 0 ? fmtMoney(value) + ' de descuento' : 'Un descuento fijo';

			case '2x1':
				return '2x1';

			case '3x2':
				return '3x2';

			case 'free_ship_threshold': {
				var threshold = Number(draft.minAmount) || value;
				return threshold > 0
					? 'Envío gratis para compras desde ' + fmtMoney(threshold)
					: 'Envío gratis a partir de cierto monto de compra';
			}

			case 'bundle': {
				var bundle = draft.bundle || {};
				var items = Array.isArray(bundle.items) ? bundle.items.length : 0;
				var price = Number(bundle.price) || 0;
				if (items > 0 && price > 0) {
					return 'Un combo de ' + items + (items === 1 ? ' producto' : ' productos') + ' por ' + fmtMoney(price);
				}
				if (price > 0) { return 'Un combo a precio especial de ' + fmtMoney(price); }
				return 'Un combo de productos a precio especial';
			}

			case 'gift': {
				var gift = (draft.giftText || '').trim();
				return gift !== ''
					? 'Un regalo de cortesía (' + gift + ') con la compra'
					: 'Un regalo de cortesía con la compra';
			}

			case 'tiered': {
				var tiers = Array.isArray(draft.tiers) ? draft.tiers : [];
				var maxPct = 0;
				tiers.forEach(function (t) {
					var d = Number(t.discountPercent) || 0;
					if (d > maxPct) { maxPct = d; }
				});
				if (maxPct > 0) {
					return 'Un descuento que crece con la compra, hasta ' + maxPct + '% en el nivel más alto';
				}
				return 'Un descuento escalonado: cuanto más compra tu cliente, más ahorra';
			}

			default:
				// Reasonable generic fallback from the shared type catalogue.
				return typeLabel(type);
		}
	}

	/**
	 * Where it applies — from the { target, ids } scope (legacy strings fall
	 * back to "toda la tienda").
	 */
	function scopePhrase(draft) {
		var scope = draft.scope;
		if (!scope || typeof scope === 'string' || scope.target === 'all') {
			return 'en toda la tienda';
		}

		var ids = Array.isArray(scope.ids) ? scope.ids : [];
		var n = ids.length;

		if (scope.target === 'products') {
			if (n === 0) { return 'en los productos que elijas'; }
			return n === 1 ? 'en 1 producto seleccionado' : 'en ' + n + ' productos seleccionados';
		}

		if (scope.target === 'category' || scope.target === 'categories') {
			if (n === 1) {
				var name = categoryName(ids[0]);
				return name ? 'en la categoría ' + name : 'en 1 categoría seleccionada';
			}
			if (n === 0) { return 'en las categorías que elijas'; }
			return 'en ' + n + ' categorías seleccionadas';
		}

		return 'en toda la tienda';
	}

	/**
	 * How the customer gets it — code vs automatic.
	 */
	function activationPhrase(draft) {
		var code = (draft.code || '').trim();
		return code !== ''
			? 'con el código ' + code.toUpperCase()
			: 'automático, sin necesidad de código';
	}

	/**
	 * When it runs — "del 15 al 30 de julio", "desde el...", "hasta el...".
	 * Years only appear when the range crosses years or is not the current one.
	 */
	function datesPhrase(draft) {
		var from = parseYmd(draft.start);
		var to = parseYmd(draft.end);
		var thisYear = new Date().getFullYear();

		if (from && to) {
			var crossYear = from.y !== to.y;
			var showYear = crossYear || to.y !== thisYear;
			if (!crossYear && from.m === to.m) {
				return 'del ' + from.d + ' al ' + fmtDay(to, showYear);
			}
			return 'del ' + fmtDay(from, crossYear) + ' al ' + fmtDay(to, showYear);
		}
		if (from) { return 'desde el ' + fmtDay(from, from.y !== thisYear); }
		if (to) { return 'hasta el ' + fmtDay(to, to.y !== thisYear); }
		return '';
	}

	/**
	 * Extra conditions — minimum purchase and usage limits, phrased kindly.
	 * Returns an array of fragments.
	 */
	function conditionsPhrases(draft) {
		var out = [];
		var type = draft.type || '';
		var minAmount = Number(draft.minAmount) || 0;
		var limitUser = Number(draft.limitUser) || 0;
		var limitGlobal = Number(draft.limitGlobal) || 0;

		// free_ship_threshold already folds the minimum into its base phrase.
		if (minAmount > 0 && type !== 'free_ship_threshold') {
			out.push('para compras desde ' + fmtMoney(minAmount));
		}
		if (limitUser === 1) {
			out.push('máximo 1 vez por cliente');
		} else if (limitUser > 1) {
			out.push('hasta ' + limitUser + ' veces por cliente');
		}
		if (limitGlobal > 0) {
			out.push('limitado a los primeros ' + limitGlobal.toLocaleString('es-CO') + ' canjes');
		}
		return out;
	}

	// =======================================================================
	// Sentence assembly
	// =======================================================================

	/**
	 * Build the full sentence for a promo draft.
	 *
	 * Pure function: same draft in, same phrase out. Joins the fragments with
	 * commas and closes with a period, keeping the warm advisor voice:
	 * "2x1 en la categoría Camisetas, automático, sin necesidad de código,
	 *  del 15 al 30 de julio, máximo 1 vez por cliente."
	 *
	 * @param {Object} promoDraft Wizard form / REST promo shape.
	 * @return {string}
	 */
	function build(promoDraft) {
		var draft = promoDraft || {};

		// Shipping promos are order-level: when they apply store-wide, tacking
		// "en toda la tienda" after the threshold reads clumsy, so we skip it.
		var scope = scopePhrase(draft);
		var isShipping = draft.type === 'free_ship_threshold' || draft.type === 'free_ship';
		var opening = (isShipping && scope === 'en toda la tienda')
			? basePhrase(draft)
			: basePhrase(draft) + ' ' + scope;

		var parts = [opening];
		parts.push(activationPhrase(draft));

		var when = datesPhrase(draft);
		if (when !== '') { parts.push(when); }

		conditionsPhrases(draft).forEach(function (c) { parts.push(c); });

		var sentence = parts.join(', ') + '.';

		// Capitalize the first letter so type bases like "2x1" stay intact
		// but lowercase openers ("envío gratis...") read as a real sentence.
		return sentence.charAt(0).toUpperCase() + sentence.slice(1);
	}

	// =======================================================================
	// Rule phrase fragments (RuleEditor shape — parallel to the promo ones,
	// never shared, so build() stays byte-for-byte untouched)
	// =======================================================================

	function roleName(id) {
		for (var i = 0; i < ROLES.length; i++) {
			if (ROLES[i].id === id) { return ROLES[i].name; }
		}
		return id;
	}

	function plural(n, one, many) {
		return n === 1 ? one : many;
	}

	/**
	 * What the rule DOES — opening phrase from adjustments.type.
	 */
	function ruleBasePhrase(adj) {
		var type = adj.type || '';
		var value = Number(adj.value) || 0;

		switch (type) {
			case 'percentage':
				return value > 0 ? value + '% de descuento' : 'Un descuento porcentual';

			case 'fixed':
				return value > 0 ? fmtMoney(value) + ' de descuento' : 'Un descuento fijo';

			case 'bulk':
				// The tier detail reads better AFTER the scope, so build_rule()
				// appends it as its own fragment (see bulkTiersPhrase).
				return 'Un descuento escalonado por cantidad';

			case 'bogo': {
				var buy = Number(adj.buy_qty) || 1;
				var get = Number(adj.get_qty) || 1;
				var dType = adj.discount_type || adj.bogo_discount_type || 'free';
				var dVal = Number(adj.discount_value || adj.bogo_value) || 0;
				var gifts = Array.isArray(adj.get_products) ? adj.get_products.length : 0;
				if (dType === 'free') {
					if (gifts > 0) {
						return 'Compra ' + buy + ' y lleva ' + get + ' gratis de los productos de regalo elegidos';
					}
					// Classic same-product combos read better as "2x1" / "3x2".
					return (buy + get) + 'x' + buy;
				}
				var rebate = dType === 'percentage' ? dVal + '%' : fmtMoney(dVal);
				return 'Compra ' + buy + ' y lleva ' + get + ' con ' + rebate + ' de descuento';
			}

			case 'free_shipping':
				return 'Envío gratis';

			case 'bundle_set':
			case 'bundle': {
				var price = Number(adj.bundle_price || adj.set_price) || 0;
				return price > 0
					? 'Un paquete de productos por ' + fmtMoney(price)
					: 'Un paquete de productos a precio especial';
			}

			default:
				return 'Un descuento';
		}
	}

	/**
	 * The best bulk tier as a follow-up fragment ("sube hasta 20% llevando
	 * 12 unidades o más"), placed after the scope so the sentence flows.
	 */
	function bulkTiersPhrase(adj) {
		var tiers = Array.isArray(adj.tiers) ? adj.tiers : [];
		var best = null;
		tiers.forEach(function (t) {
			if (t.type === 'percentage' && (Number(t.value) || 0) > (best ? Number(best.value) : 0)) { best = t; }
		});
		if (best) {
			var from = Number(best.min) || 0;
			return 'sube hasta ' + Number(best.value) + '%' +
				(from > 1 ? ' llevando ' + from + ' unidades o más' : '');
		}
		return 'cuanto más lleva tu cliente, más ahorra';
	}

	/**
	 * Where the rule applies — from apply_to + filters, plus exclusions.
	 */
	function ruleScopePhrase(rule) {
		var filters = rule.filters || {};

		if (rule.apply_to === 'specific_products') {
			var np = Array.isArray(filters.product_ids) ? filters.product_ids.length : 0;
			if (np === 0) { return 'en los productos que elijas'; }
			return 'en ' + np + plural(np, ' producto seleccionado', ' productos seleccionados');
		}

		if (rule.apply_to === 'specific_categories') {
			var cats = Array.isArray(filters.category_ids) ? filters.category_ids : [];
			if (cats.length === 1) {
				var name = categoryName(cats[0]);
				return name ? 'en la categoría ' + name : 'en 1 categoría seleccionada';
			}
			if (cats.length === 0) { return 'en las categorías que elijas'; }
			return 'en ' + cats.length + ' categorías seleccionadas';
		}

		return 'en toda la tienda';
	}

	/**
	 * Excluded products/categories, phrased as one gentle aside.
	 */
	function ruleExclusionsPhrase(rule) {
		var filters = rule.filters || {};
		var xp = Array.isArray(filters.exclude_product_ids) ? filters.exclude_product_ids.length : 0;
		var xc = Array.isArray(filters.exclude_category_ids) ? filters.exclude_category_ids.length : 0;
		var parts = [];
		if (xp > 0) { parts.push(xp + plural(xp, ' producto', ' productos')); }
		if (xc > 0) { parts.push(xc + plural(xc, ' categoría', ' categorías')); }
		return parts.length ? 'excluyendo ' + parts.join(' y ') : '';
	}

	function listPreview(v) {
		var arr = Array.isArray(v) ? v : (typeof v === 'string' && v !== '' ? [v] : []);
		arr = arr.filter(function (s) { return String(s).trim() !== ''; });
		if (arr.length === 0) { return ''; }
		if (arr.length <= 2) { return arr.join(' o '); }
		return arr.slice(0, 2).join(', ') + ' y ' + (arr.length - 2) + ' más';
	}

	/**
	 * One warm fragment per condition row ("solo si..."). Unknown types are
	 * skipped rather than guessed, so the sentence never lies to the merchant.
	 */
	function ruleConditionPhrase(cond) {
		var op = cond.operator || '';
		var val = cond.value;

		switch (cond.type) {
			case 'subtotal': {
				var amount = fmtMoney(val);
				if (op === 'less_than_or_equal' || op === 'less_than') { return 'solo para compras de hasta ' + amount; }
				return 'solo para compras desde ' + amount;
			}

			case 'items_count':
			case 'cart_items_quantity': {
				var n = Number(val) || 0;
				if (op === 'less_than_or_equal' || op === 'less_than') {
					return 'solo si el carrito lleva ' + n + plural(n, ' artículo', ' artículos') + ' o menos';
				}
				return 'solo si el carrito lleva ' + n + plural(n, ' artículo', ' artículos') + ' o más';
			}

			case 'user_role': {
				var roles = Array.isArray(val) ? val : [];
				if (roles.length === 0) { return null; }
				var names = roles.map(roleName);
				if (op === 'not_in_list') { return 'excepto para clientes ' + listPreview(names); }
				return 'solo para clientes ' + listPreview(names);
			}

			case 'user_email': {
				var mails = listPreview(val);
				if (!mails) { return null; }
				return (op === 'not_in_list' ? 'excepto correos como ' : 'solo para correos como ') + mails;
			}

			case 'shipping_location': {
				var places = listPreview(val);
				if (!places) { return null; }
				return (op === 'not_in_list' ? 'excepto envíos a ' : 'solo para envíos a ') + places;
			}

			case 'billing_city': {
				if (!val) { return null; }
				return (op === 'not_in_list' ? 'excepto para clientes de ' : 'solo para clientes de ') + val;
			}

			case 'cart_coupon': {
				var code = String(val || '').trim();
				if (op === 'not_applied') {
					return code ? 'solo si el cupón ' + code.toUpperCase() + ' no está aplicado' : 'solo sin cupones aplicados';
				}
				return code ? 'solo con el cupón ' + code.toUpperCase() + ' aplicado' : null;
			}

			case 'cart_items_weight': {
				var kg = Number(val) || 0;
				if (op === 'less_than_or_equal' || op === 'less_than') { return 'solo si el carrito pesa hasta ' + kg; }
				return 'solo si el carrito pesa ' + kg + ' o más';
			}

			case 'onsale_products':
				return val === 'only'
					? 'solo en productos que ya están en oferta'
					: 'sin tocar productos que ya están en oferta';

			case 'product_combination':
				return op === 'contains_none'
					? 'solo si el carrito no incluye ciertos productos'
					: 'solo si el carrito incluye ciertos productos';

			case 'user_logged_in':
				return val === 'no' ? 'solo para visitantes sin cuenta' : 'solo para clientes con sesión iniciada';

			case 'user_list':
				return 'solo para una lista específica de clientes';

			case 'order_date': {
				var from = parseYmd(cond.start_date);
				var to = parseYmd(cond.end_date);
				var thisYear = new Date().getFullYear();
				if (from && to) {
					if (from.y === to.y && from.m === to.m) { return 'del ' + from.d + ' al ' + fmtDay(to, to.y !== thisYear); }
					return 'del ' + fmtDay(from, from.y !== to.y) + ' al ' + fmtDay(to, from.y !== to.y || to.y !== thisYear);
				}
				if (from) { return 'desde el ' + fmtDay(from, from.y !== thisYear); }
				if (to) { return 'hasta el ' + fmtDay(to, to.y !== thisYear); }
				if (Array.isArray(cond.weekdays) && cond.weekdays.length > 0) { return 'solo ciertos días de la semana'; }
				return null;
			}

			case 'purchase_history': {
				var metric = cond.history_metric || 'orders_count';
				if (metric === 'revenue') { return 'solo para clientes que ya han gastado ' + fmtMoney(val) + ' o más'; }
				if (metric === 'products_bought') { return 'solo para clientes que ya compraron ciertos productos'; }
				var orders = Number(val) || 0;
				return 'solo para clientes con ' + orders + plural(orders, ' pedido o más', ' pedidos o más');
			}

			default:
				return null;
		}
	}

	/**
	 * Build the full sentence for a RULE (RuleEditor form / REST rules shape).
	 *
	 * Pure and parallel to build(): same rule in, same phrase out, same warm
	 * advisor voice. Example:
	 *   "2x1 en la categoría Camisetas, solo si el carrito lleva 3 artículos
	 *    o más, y no se combina con otras reglas."
	 *
	 * @param {Object} rule RuleEditor draft.
	 * @return {string}
	 */
	function build_rule(rule) {
		var r = rule || {};
		var adj = r.adjustments || {};

		// Free shipping is order-level: "envío gratis en toda la tienda" reads
		// clumsy, so store-wide shipping keeps just the base (same trick as
		// build() uses for free_ship_threshold).
		var scope = ruleScopePhrase(r);
		var opening = (adj.type === 'free_shipping' && scope === 'en toda la tienda')
			? ruleBasePhrase(adj)
			: ruleBasePhrase(adj) + ' ' + scope;

		var parts = [opening];

		if (adj.type === 'bulk') { parts.push(bulkTiersPhrase(adj)); }

		var excl = ruleExclusionsPhrase(r);
		if (excl !== '') { parts.push(excl); }

		// First 3 conditions verbatim; the rest folded into one honest count
		// so a heavily-conditioned rule still reads as one calm sentence.
		var fragments = (Array.isArray(r.conditions) ? r.conditions : [])
			.map(ruleConditionPhrase)
			.filter(function (f) { return typeof f === 'string' && f !== ''; });
		fragments.slice(0, 3).forEach(function (f) { parts.push(f); });
		if (fragments.length > 3) {
			var rest = fragments.length - 3;
			parts.push('y ' + rest + plural(rest, ' condición más', ' condiciones más'));
		}

		if (r.exclusive) { parts.push('y no se combina con otras reglas'); }

		var sentence = parts.join(', ') + '.';
		return sentence.charAt(0).toUpperCase() + sentence.slice(1);
	}

	// =======================================================================
	// Component
	// =======================================================================

	/**
	 * Renders the phrase in a soft highlight box, prefixed with a small lead
	 * so the merchant knows this is how the promo (or rule) will behave.
	 *
	 * @param {Object} props { promoDraft } for the Promos wizard, or { rule }
	 *                       for the RuleEditor. `rule` wins when both exist.
	 */
	function NaturalLanguageSummary(props) {
		var isRule = !!props.rule;
		var phrase = isRule ? build_rule(props.rule) : build(props.promoDraft || {});
		var lead = isRule ? 'Así funcionará tu regla' : 'Así funcionará tu promoción';

		return el('div', {
			className: 'drw-nl-summary',
			style: {
				display: 'flex',
				gap: '10px',
				alignItems: 'flex-start',
				padding: '12px 14px',
				background: '#f4f8f0',
				border: '1px solid #d8e4cc',
				borderRadius: '8px',
				color: '#3a5a2a'
			}
		},
			el('span', {
				'aria-hidden': 'true',
				style: { fontSize: '18px', lineHeight: 1.3 }
			}, '💬'),
			el('div', null,
				el('div', {
					style: { fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em', opacity: 0.7, marginBottom: '2px' }
				}, lead),
				el('div', {
					style: { fontSize: '14px', lineHeight: 1.5 }
				}, phrase)
			)
		);
	}

	window.DrwNaturalLanguageSummary = NaturalLanguageSummary;
	// Pure builders, exposed for tests and plain-text consumers.
	window.DrwNaturalLanguageSummary.build = build;
	window.DrwNaturalLanguageSummary.build_rule = build_rule;
})();
