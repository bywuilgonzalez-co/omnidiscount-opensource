/**
 * OmniDiscount — PromoStatsPanel component
 *
 * Small metrics panel for a single promo. Fetches
 * GET /drw/v1/promos/<id>/stats (see PromosController::get_promo_stats)
 * and renders:
 *   - canjes totales (uses)
 *   - descuento acumulado (discountTotal)
 *   - ingresos asistidos aproximados (assistedRevenue)
 *   - a 30-day inline-SVG sparkline when the endpoint reports daily data,
 *     or a friendly "aún sin datos suficientes" empty state when it doesn't.
 *
 * Uses WordPress core React (wp-element) and wp.apiFetch, same as the other
 * window.Drw* components (drw-code-input.js, drw-promo-wizard.js). Loaded as
 * an independent script, so it exposes itself as window.DrwPromoStatsPanel.
 *
 * Usage:
 *   el(window.DrwPromoStatsPanel, { promoId: promo.id })
 */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var apiFetch = wp.apiFetch;

	/**
	 * Format a money amount with the store currency symbol.
	 *
	 * @param {number} v      Amount.
	 * @param {string} symbol Currency symbol from the API ('$' fallback).
	 * @return {string}
	 */
	function fmtMoney(v, symbol) {
		return (symbol || '$') + Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
	}

	// =======================================================================
	// Sparkline — inline SVG, no external library
	// =======================================================================

	var SPARK_W = 300;
	var SPARK_H = 56;
	var SPARK_PAD = 4;

	/**
	 * Build the polyline/area points for a numeric series.
	 *
	 * @param {number[]} values One value per day.
	 * @return {{line: string, area: string, lastX: number, lastY: number}}
	 */
	function sparkGeometry(values) {
		var n = values.length;
		var max = 0;
		var i;
		for (i = 0; i < n; i++) {
			if (values[i] > max) { max = values[i]; }
		}
		if (max <= 0) { max = 1; }

		var innerW = SPARK_W - SPARK_PAD * 2;
		var innerH = SPARK_H - SPARK_PAD * 2;
		var stepX = n > 1 ? innerW / (n - 1) : 0;

		var pts = [];
		var x = SPARK_PAD;
		var y = SPARK_H - SPARK_PAD;
		for (i = 0; i < n; i++) {
			x = SPARK_PAD + stepX * i;
			y = SPARK_H - SPARK_PAD - (values[i] / max) * innerH;
			pts.push(x.toFixed(1) + ',' + y.toFixed(1));
		}

		var line = pts.join(' ');
		var area = SPARK_PAD + ',' + (SPARK_H - SPARK_PAD) + ' ' + line + ' ' +
			x.toFixed(1) + ',' + (SPARK_H - SPARK_PAD);

		return { line: line, area: area, lastX: x, lastY: y };
	}

	/**
	 * 30-day sparkline. Plots the daily discount amount; when every discount
	 * is zero but there are redemptions, falls back to plotting daily uses so
	 * the shape still tells a story.
	 *
	 * @param {Object} props { byDay: [{date, uses, discount}] }
	 */
	function Sparkline(props) {
		var byDay = Array.isArray(props.byDay) ? props.byDay : [];
		if (byDay.length < 2) { return null; }

		var values = byDay.map(function (d) { return Number(d.discount) || 0; });
		var hasDiscountSignal = values.some(function (v) { return v > 0; });
		if (!hasDiscountSignal) {
			values = byDay.map(function (d) { return Number(d.uses) || 0; });
		}

		var g = sparkGeometry(values);
		var color = '#5b7b41';

		return el('svg', {
			viewBox: '0 0 ' + SPARK_W + ' ' + SPARK_H,
			width: '100%',
			height: SPARK_H,
			preserveAspectRatio: 'none',
			role: 'img',
			'aria-label': 'Actividad de los últimos 30 días',
			style: { display: 'block' }
		},
			el('polygon', { points: g.area, fill: color, opacity: 0.12 }),
			el('polyline', {
				points: g.line,
				fill: 'none',
				stroke: color,
				strokeWidth: 2,
				strokeLinejoin: 'round',
				strokeLinecap: 'round'
			}),
			el('circle', { cx: g.lastX, cy: g.lastY, r: 3, fill: color })
		);
	}

	// =======================================================================
	// Metric card
	// =======================================================================

	function MetricCard(props) {
		return el('div', {
			className: 'drw-stat-card',
			style: {
				flex: '1 1 0',
				minWidth: '120px',
				padding: '12px 14px',
				background: '#fff',
				border: '1px solid #e2e4e7',
				borderRadius: '8px'
			}
		},
			el('div', {
				style: { fontSize: '12px', color: '#646970', marginBottom: '4px' }
			}, props.label),
			el('div', {
				style: { fontSize: '20px', fontWeight: 600, color: '#1d2327', lineHeight: 1.2 }
			}, props.value),
			props.hint ? el('div', {
				style: { fontSize: '11px', color: '#8c8f94', marginTop: '2px' }
			}, props.hint) : null
		);
	}

	// =======================================================================
	// PromoStatsPanel
	// =======================================================================

	/**
	 * @param {Object} props { promoId: number }
	 */
	function PromoStatsPanel(props) {
		var promoId = props.promoId;

		var stateState = useState({ loading: true, error: null, stats: null });
		var state = stateState[0];
		var setState = stateState[1];

		useEffect(function () {
			if (!promoId) {
				setState({ loading: false, error: null, stats: null });
				return;
			}
			var cancelled = false;
			setState({ loading: true, error: null, stats: null });

			apiFetch({ path: '/drw/v1/promos/' + encodeURIComponent(promoId) + '/stats' })
				.then(function (data) {
					if (!cancelled) { setState({ loading: false, error: null, stats: data }); }
				})
				.catch(function (err) {
					if (!cancelled) {
						setState({
							loading: false,
							error: (err && err.message) ? err.message : 'Error al cargar las estadísticas.',
							stats: null
						});
					}
				});

			return function () { cancelled = true; };
		}, [promoId]);

		if (!promoId) { return null; }

		if (state.loading) {
			return el('div', { className: 'drw-promo-stats', style: { color: '#646970', fontSize: '13px', padding: '8px 0' } },
				'Cargando estadísticas…'
			);
		}

		if (state.error) {
			return el('div', { className: 'drw-promo-stats', style: { color: '#b32d2e', fontSize: '13px', padding: '8px 0' } },
				'No pudimos cargar las estadísticas de esta promoción. ', state.error
			);
		}

		var s = state.stats || {};
		var symbol = s.currencySymbol || '$';
		var byDay = Array.isArray(s.byDay) ? s.byDay : [];
		var hasDaily = !!s.hasDailyData && byDay.length >= 2;

		return el('div', { className: 'drw-promo-stats' },
			el('div', {
				className: 'drw-promo-stats-cards',
				style: { display: 'flex', gap: '10px', flexWrap: 'wrap' }
			},
				el(MetricCard, {
					label: 'Canjes totales',
					value: Number(s.uses || 0).toLocaleString('es-CO')
				}),
				el(MetricCard, {
					label: 'Descuento acumulado',
					value: fmtMoney(s.discountTotal, symbol),
					hint: s.ordersCount ? (s.ordersCount + (s.ordersCount === 1 ? ' pedido' : ' pedidos')) : null
				}),
				el(MetricCard, {
					label: 'Ingresos asistidos',
					value: fmtMoney(s.assistedRevenue, symbol),
					hint: 'Aproximado'
				})
			),

			el('div', {
				className: 'drw-promo-stats-trend',
				style: {
					marginTop: '12px',
					padding: '12px 14px',
					background: '#fff',
					border: '1px solid #e2e4e7',
					borderRadius: '8px'
				}
			},
				el('div', {
					style: { fontSize: '12px', color: '#646970', marginBottom: '8px' }
				}, 'Últimos 30 días'),

				hasDaily
					? el(Sparkline, { byDay: byDay })
					: el('div', {
						style: {
							padding: '14px 8px',
							textAlign: 'center',
							color: '#8c8f94',
							fontSize: '13px',
							lineHeight: 1.5
						}
					},
						el('div', { style: { fontSize: '20px', marginBottom: '4px' }, 'aria-hidden': 'true' }, '📈'),
						'Aún sin datos suficientes para mostrar la tendencia.',
						el('br'),
						'En cuanto tus clientes empiecen a usar esta promoción, aquí verás su evolución.'
					)
			)
		);
	}

	window.DrwPromoStatsPanel = PromoStatsPanel;
})();
