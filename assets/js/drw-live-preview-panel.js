/**
 * OmniDiscount — LivePreviewPanel component
 *
 * Shows the before/after price a promo draft would produce on a sample product,
 * recomputed (debounced 500ms) every time the draft changes. It calls the
 * dry-run REST endpoint POST /drw/v1/promos/preview, which computes the price
 * with the SAME engine code production uses (PromoBridgeController builders +
 * Adjustments\* / RulesEngine) WITHOUT saving the promo or touching the cart.
 *
 * Uses WordPress core React (wp-element) and wp.apiFetch — same stack as
 * drw-code-input.js — and is loaded as an independent script (see
 * AdminController), so it exposes itself as window.DrwLivePreviewPanel.
 *
 * Usage:
 *   el(window.DrwLivePreviewPanel, {
 *       promoDraft: form,          // the wizard's in-progress promo object
 *       sampleProductId: 1234,     // a real WC product id to price against
 *       currencySymbol: '$'        // optional; falls back to drwAdminData
 *   })
 */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;

	var DEBOUNCE_MS = 500;
	var PREVIEW_PATH = '/drw/v1/promos/preview';

	/**
	 * Format a numeric amount with an optional currency symbol prefix.
	 *
	 * @param {number|string} value  Amount.
	 * @param {string}        symbol Currency symbol (may be empty).
	 * @return {string}
	 */
	function formatMoney(value, symbol) {
		var n = Number(value);
		if (!isFinite(n)) {
			return String(value);
		}
		var text = n.toFixed(2);
		return symbol ? symbol + text : text;
	}

	/**
	 * Derive a human-readable savings label (amount + percent off) from the
	 * before/after prices returned by the endpoint.
	 *
	 * @param {number} before
	 * @param {number} after
	 * @param {string} symbol
	 * @return {string} '' when there is no positive saving.
	 */
	function savingsLabel(before, after, symbol) {
		var b = Number(before);
		var a = Number(after);
		if (!isFinite(b) || !isFinite(a) || b <= 0 || a >= b) {
			return '';
		}
		var saved = b - a;
		var pct = Math.round((saved / b) * 100);
		return '-' + formatMoney(saved, symbol) + (pct > 0 ? ' (-' + pct + '%)' : '');
	}

	/**
	 * Small inline status/hint text.
	 */
	function Hint(props) {
		return el(
			'div',
			{
				className: 'drw-live-preview-hint',
				style: { color: 'var(--drw-muted, #8b8b8b)', fontSize: '12px', padding: '8px 0' }
			},
			props.children
		);
	}

	/**
	 * LivePreviewPanel({ promoDraft, sampleProductId, currencySymbol })
	 *
	 * - promoDraft:      the current (unsaved) promo object from the wizard.
	 *                    Any change to it re-triggers a debounced recompute.
	 * - sampleProductId: a real WooCommerce product id to price against.
	 * - currencySymbol:  optional; defaults to window.drwAdminData.currencySymbol
	 *                    then to '' (bare number).
	 */
	function LivePreviewPanel(props) {
		var promoDraft = props.promoDraft || {};
		var sampleProductId = props.sampleProductId || null;
		var currencySymbol = props.currencySymbol ||
			(window.drwAdminData && window.drwAdminData.currencySymbol) || '';

		var statusState = useState('idle'); // idle | loading | ready | error
		var status = statusState[0];
		var setStatus = statusState[1];

		var resultState = useState(null);
		var result = resultState[0];
		var setResult = resultState[1];

		var messageState = useState('');
		var message = messageState[0];
		var setMessage = messageState[1];

		var timerRef = useRef(null);
		var reqIdRef = useRef(0);
		var mountedRef = useRef(true);

		// Stable dependency for the effect: a shallow JSON of the draft, so the
		// recompute fires on any field change without depending on object identity.
		var draftKey = JSON.stringify(promoDraft);

		useEffect(function () {
			mountedRef.current = true;
			return function () {
				mountedRef.current = false;
				if (timerRef.current) {
					clearTimeout(timerRef.current);
				}
			};
		}, []);

		useEffect(function () {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}

			if (!sampleProductId) {
				setStatus('idle');
				setResult(null);
				setMessage('Selecciona un producto de ejemplo para ver la vista previa.');
				return undefined;
			}
			if (!promoDraft || !promoDraft.type) {
				setStatus('idle');
				setResult(null);
				setMessage('Elige un tipo de promoción para calcular el precio.');
				return undefined;
			}

			setStatus('loading');
			setMessage('');
			var myReqId = ++reqIdRef.current;

			timerRef.current = setTimeout(function () {
				timerRef.current = null;

				apiFetch({
					path: PREVIEW_PATH,
					method: 'POST',
					// Spread the draft at the top level (the endpoint reads either
					// top-level fields or a nested `promo`), plus the sample id.
					data: Object.assign({}, promoDraft, { product_id: sampleProductId })
				})
					.then(function (res) {
						// Drop stale/out-of-order responses.
						if (!mountedRef.current || myReqId !== reqIdRef.current) {
							return;
						}
						setResult(res);
						setStatus('ready');
						setMessage('');
					})
					.catch(function (err) {
						if (!mountedRef.current || myReqId !== reqIdRef.current) {
							return;
						}
						setResult(null);
						setStatus('error');
						setMessage((err && err.message) || 'No se pudo calcular la vista previa.');
					});
			}, DEBOUNCE_MS);

			return function () {
				if (timerRef.current) {
					clearTimeout(timerRef.current);
					timerRef.current = null;
				}
			};
		}, [draftKey, sampleProductId]);

		// ----- Render ---------------------------------------------------------

		var body;

		if (status === 'idle') {
			body = el(Hint, null, message || 'Vista previa de precio.');
		} else if (status === 'error') {
			body = el(
				'div',
				{ style: { color: 'var(--drw-error, #ef4444)', fontSize: '12.5px', padding: '8px 0' } },
				message || 'No se pudo calcular la vista previa.'
			);
		} else {
			// loading or ready — render the card (dimmed while loading).
			var hasResult = !!result;
			var before = hasResult ? Number(result.priceBefore) : 0;
			var after = hasResult ? Number(result.priceAfter) : 0;
			var changed = hasResult && isFinite(before) && isFinite(after) && after < before;
			var saveText = hasResult ? savingsLabel(before, after, currencySymbol) : '';

			var image = (hasResult && result.productImage)
				? el('img', {
					src: result.productImage,
					alt: (hasResult && result.productName) || '',
					width: 56,
					height: 56,
					style: {
						width: '56px',
						height: '56px',
						objectFit: 'cover',
						borderRadius: '8px',
						flex: '0 0 auto',
						background: 'var(--drw-surface-2, #f0f0f0)'
					}
				})
				: el('div', {
					style: {
						width: '56px',
						height: '56px',
						borderRadius: '8px',
						flex: '0 0 auto',
						background: 'var(--drw-surface-2, #f0f0f0)'
					}
				});

			var priceRow = el(
				'div',
				{ style: { display: 'flex', alignItems: 'baseline', gap: '10px', flexWrap: 'wrap' } },
				changed && el('span', {
					style: {
						textDecoration: 'line-through',
						color: 'var(--drw-muted, #8b8b8b)',
						fontSize: '13px'
					}
				}, formatMoney(before, currencySymbol)),
				el('span', {
					style: {
						fontSize: '18px',
						fontWeight: 700,
						color: changed ? 'var(--drw-success, #10b981)' : 'var(--drw-text, inherit)'
					}
				}, hasResult ? formatMoney(after, currencySymbol) : '—')
			);

			var badges = el(
				'div',
				{ style: { display: 'flex', gap: '6px', flexWrap: 'wrap', marginTop: '4px' } },
				saveText && el('span', {
					style: {
						fontSize: '11px',
						fontWeight: 600,
						color: 'var(--drw-success, #10b981)',
						background: 'var(--drw-success-bg, rgba(16,185,129,0.12))',
						borderRadius: '999px',
						padding: '2px 8px'
					}
				}, saveText),
				hasResult && result.freeShipping && el('span', {
					style: {
						fontSize: '11px',
						fontWeight: 600,
						color: 'var(--drw-accent, #2563eb)',
						background: 'var(--drw-accent-bg, rgba(37,99,235,0.12))',
						borderRadius: '999px',
						padding: '2px 8px'
					}
				}, 'Envío gratis')
			);

			body = el(
				'div',
				{
					style: {
						display: 'flex',
						gap: '12px',
						alignItems: 'center',
						opacity: status === 'loading' ? 0.55 : 1,
						transition: 'opacity 120ms ease'
					}
				},
				image,
				el(
					'div',
					{ style: { minWidth: 0, flex: 1 } },
					el('div', {
						style: {
							fontSize: '12.5px',
							fontWeight: 600,
							whiteSpace: 'nowrap',
							overflow: 'hidden',
							textOverflow: 'ellipsis',
							marginBottom: '2px'
						}
					}, (hasResult && result.productName) || 'Producto de ejemplo'),
					priceRow,
					badges
				)
			);
		}

		return el(
			'div',
			{
				className: 'drw-live-preview-panel',
				style: {
					border: '1px solid var(--drw-border, #e2e2e2)',
					borderRadius: '10px',
					padding: '12px',
					background: 'var(--drw-surface, #fff)'
				}
			},
			el(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'space-between',
						marginBottom: '8px'
					}
				},
				el('span', {
					style: {
						fontSize: '11px',
						fontWeight: 700,
						letterSpacing: '0.04em',
						textTransform: 'uppercase',
						color: 'var(--drw-muted, #8b8b8b)'
					}
				}, 'Vista previa en vivo'),
				status === 'loading' && el('span', {
					style: { fontSize: '11px', color: 'var(--drw-muted, #8b8b8b)' }
				}, 'Calculando…')
			),
			body
		);
	}

	window.DrwLivePreviewPanel = LivePreviewPanel;

	// Exposed for reuse/testing without mounting the component.
	window.DrwLivePreviewPanel.formatMoney = formatMoney;
	window.DrwLivePreviewPanel.savingsLabel = savingsLabel;

})();
