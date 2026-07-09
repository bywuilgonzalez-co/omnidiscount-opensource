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
 * Uses WordPress core React (wp-element), same as the other window.Drw*
 * components. Exposes window.DrwNaturalLanguageSummary (the component) and
 * window.DrwNaturalLanguageSummary.build(promoDraft) (the pure phrase
 * builder, handy for tests or plain-text contexts).
 *
 * Usage:
 *   el(window.DrwNaturalLanguageSummary, { promoDraft: f })
 */
(function () {
	'use strict';

	var el = wp.element.createElement;

	var PROMO_TYPES = (window.drwAdminData && window.drwAdminData.promoTypes) || [];
	var CATEGORIES = (window.drwAdminData && window.drwAdminData.categories) || [];

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
	// Component
	// =======================================================================

	/**
	 * Renders the phrase in a soft highlight box, prefixed with a small lead
	 * so the merchant knows this is how the promo will behave.
	 *
	 * @param {Object} props { promoDraft }
	 */
	function NaturalLanguageSummary(props) {
		var draft = props.promoDraft || {};
		var phrase = build(draft);

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
				}, 'Así funcionará tu promoción'),
				el('div', {
					style: { fontSize: '14px', lineHeight: 1.5 }
				}, phrase)
			)
		);
	}

	window.DrwNaturalLanguageSummary = NaturalLanguageSummary;
	// Pure builder, exposed for tests and plain-text consumers.
	window.DrwNaturalLanguageSummary.build = build;
})();
