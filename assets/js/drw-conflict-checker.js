/**
 * OmniDiscount — ConflictChecker component
 * Live, non-blocking "traffic light" for a promo draft still being edited in
 * the wizard. Debounces the draft and calls
 * POST /drw/v1/promos/check-conflicts, which re-runs a curated subset of the
 * same rules validate_promo() enforces hard, downgraded to advisory items:
 *
 *   (a) overlap with another ACTIVE promo on the same product/category scope
 *       and an overlapping date range;
 *   (b) duplicate code (reuses find_duplicate_code(), same lookup as
 *       CodeInput's live check and the real save path);
 *   (c) incoherent dates (bad Y-m-d format, or end date before start);
 *   (d) percentage discount at or above 100% (evident zero/negative margin);
 *   (e) empty scope (target is 'products'/'category' with no ids selected).
 *
 * IMPORTANT — these are ADVISORY ONLY. This component and its endpoint never
 * block a save; the real hard gate remains PromosController::validate_promo()
 * inside create_promo()/update_promo(). Nothing here prevents the form from
 * submitting.
 *
 * Uses WordPress core React (wp-element) and wp.apiFetch, same as
 * drw-code-input.js. Loaded as an independent script (see AdminController)
 * so it exposes itself as window.DrwConflictChecker for consumers to use.
 *
 * Usage:
 *   el(window.DrwConflictChecker, {
 *       promoDraft: {
 *           id: isNew ? null : f.id, // omit/null for a new promo
 *           name: f.name,
 *           code: f.code,
 *           type: f.type,
 *           value: f.value,
 *           scope: f.scope,          // { target, ids } or legacy string
 *           start: f.start,
 *           end: f.end
 *       }
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

	var SEVERITY_ORDER = { error: 2, warning: 1 };

	var COLORS = {
		green: 'var(--drw-success, #10b981)',
		yellow: 'var(--drw-warning, #f59e0b)',
		red: 'var(--drw-error, #ef4444)',
		grey: 'var(--drw-muted, #8b8b8b)'
	};

	/**
	 * Small inline colored dot used as the semaphore light.
	 */
	function Light(props) {
		return el('span', {
			className: 'drw-conflict-light drw-conflict-light-' + props.status,
			style: {
				display: 'inline-block',
				width: '10px',
				height: '10px',
				borderRadius: '50%',
				backgroundColor: props.color,
				flexShrink: 0
			}
		});
	}

	/**
	 * Small inline status icon (mirrors StatusIcon in drw-code-input.js).
	 */
	function Icon(props) {
		return el('span', {
			className: 'dashicons dashicons-' + props.name,
			style: {
				fontSize: '15px',
				width: '15px',
				height: '15px',
				lineHeight: '15px',
				color: props.color,
				flexShrink: 0
			}
		});
	}

	/**
	 * Derive the semaphore status from a warnings list.
	 *
	 * - 'red'    : at least one item has severity 'error'.
	 * - 'yellow' : no errors, but at least one 'warning'.
	 * - 'green'  : empty list — nothing to flag.
	 *
	 * @param {Array} warnings List of { severity, field, message }.
	 * @return {string} 'green' | 'yellow' | 'red'
	 */
	function semaphoreFromWarnings(warnings) {
		var hasError = false;
		var hasWarning = false;
		(warnings || []).forEach(function (w) {
			if (w && w.severity === 'error') { hasError = true; }
			else if (w && w.severity === 'warning') { hasWarning = true; }
		});
		if (hasError) { return 'red'; }
		if (hasWarning) { return 'yellow'; }
		return 'green';
	}

	/**
	 * Sort warnings so 'error' items surface above 'warning' items, keeping
	 * the backend's original ordering within each severity.
	 *
	 * @param {Array} warnings
	 * @return {Array}
	 */
	function sortWarnings(warnings) {
		return (warnings || [])
			.map(function (w, i) { return { w: w, i: i }; })
			.sort(function (a, b) {
				var ra = SEVERITY_ORDER[a.w && a.w.severity] || 0;
				var rb = SEVERITY_ORDER[b.w && b.w.severity] || 0;
				if (ra !== rb) { return rb - ra; }
				return a.i - b.i;
			})
			.map(function (entry) { return entry.w; });
	}

	/**
	 * Call POST /drw/v1/promos/check-conflicts with a promo draft.
	 *
	 * Read-only / non-mutating on the server: it never creates or updates a
	 * promo, it only evaluates the draft against existing data and returns
	 * advisory warnings. Exposed standalone so non-React callers (or tests)
	 * can reuse the exact same request shape as the component.
	 *
	 * @param {Object} promoDraft Draft promo payload (same shape as create/update).
	 * @return {Promise<{ok: boolean, warnings: Array}>}
	 */
	function checkConflicts(promoDraft) {
		return apiFetch({
			path: '/drw/v1/promos/check-conflicts',
			method: 'POST',
			data: promoDraft || {}
		}).then(function (res) {
			var warnings = (res && Array.isArray(res.warnings)) ? res.warnings : [];
			return {
				ok: !!(res && res.ok !== false) && semaphoreFromWarnings(warnings) !== 'red',
				warnings: warnings
			};
		});
	}

	/**
	 * ConflictChecker({ promoDraft, className, style })
	 *
	 * - promoDraft: current draft state from the wizard. Any shape accepted
	 *   by POST /drw/v1/promos/check-conflicts (id, name, code, type, value,
	 *   scope, start, end, ...). Re-checked (debounced) whenever it changes.
	 * - className / style: optional, merged onto the outer wrapper.
	 */
	function ConflictChecker(props) {
		var promoDraft = props.promoDraft || null;

		var statusState = useState('idle'); // idle | checking | green | yellow | red | network-error
		var status = statusState[0];
		var setStatus = statusState[1];

		var warningsState = useState([]);
		var warnings = warningsState[0];
		var setWarnings = warningsState[1];

		var errorMessageState = useState('');
		var errorMessage = errorMessageState[0];
		var setErrorMessage = errorMessageState[1];

		var timerRef = useRef(null);
		var reqIdRef = useRef(0);
		var mountedRef = useRef(true);

		useEffect(function () {
			mountedRef.current = true;
			return function () {
				mountedRef.current = false;
				if (timerRef.current) {
					clearTimeout(timerRef.current);
				}
			};
		}, []);

		// Debounce on every promoDraft change. Stringifying keeps the effect's
		// dependency shallow-comparable without requiring callers to memoize
		// the draft object themselves.
		var draftKey = (function () {
			try {
				return JSON.stringify(promoDraft);
			} catch (e) {
				return String(promoDraft);
			}
		})();

		useEffect(function () {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}

			if (!promoDraft) {
				setStatus('idle');
				setWarnings([]);
				setErrorMessage('');
				return;
			}

			setStatus('checking');

			timerRef.current = setTimeout(function () {
				timerRef.current = null;
				runCheck(promoDraft);
			}, DEBOUNCE_MS);

			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [draftKey]);

		/**
		 * Run the actual request, guarding against stale/out-of-order responses
		 * and updates after unmount (same reqId/mounted pattern as CodeInput).
		 *
		 * @param {Object} draft
		 */
		function runCheck(draft) {
			var myReqId = ++reqIdRef.current;

			checkConflicts(draft)
				.then(function (result) {
					if (!mountedRef.current || myReqId !== reqIdRef.current) { return; }
					setWarnings(result.warnings);
					setErrorMessage('');
					setStatus(semaphoreFromWarnings(result.warnings));
				})
				.catch(function (err) {
					if (!mountedRef.current || myReqId !== reqIdRef.current) { return; }
					// A failed pre-check is informational only — never block the
					// real save. Surface a neutral state instead of red/yellow.
					setWarnings([]);
					setErrorMessage((err && err.message) || 'No se pudo verificar conflictos.');
					setStatus('network-error');
				});
		}

		var color = COLORS.grey;
		var label = 'Sin verificar';
		if (status === 'checking') { color = COLORS.grey; label = 'Verificando…'; }
		else if (status === 'green') { color = COLORS.green; label = 'Sin conflictos'; }
		else if (status === 'yellow') { color = COLORS.yellow; label = warnings.length + ' advertencia' + (warnings.length === 1 ? '' : 's'); }
		else if (status === 'red') { color = COLORS.red; label = warnings.length + ' conflicto' + (warnings.length === 1 ? '' : 's'); }
		else if (status === 'network-error') { color = COLORS.grey; label = 'No se pudo verificar'; }

		var sorted = sortWarnings(warnings);

		return el('div', {
			className: 'drw-conflict-checker' + (props.className ? ' ' + props.className : ''),
			style: props.style || {}
		},
			el('div', {
				className: 'drw-conflict-checker-summary',
				style: { display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--drw-text, inherit)' }
			},
				el(Light, { status: status, color: color }),
				el('span', null, label)
			),

			sorted.length > 0 && el('ul', {
				className: 'drw-conflict-checker-list',
				style: { listStyle: 'none', margin: '8px 0 0', padding: 0, display: 'flex', flexDirection: 'column', gap: '4px' }
			},
				sorted.map(function (w, i) {
					var isError = w.severity === 'error';
					return el('li', {
						key: i,
						className: 'drw-conflict-checker-item drw-conflict-checker-item-' + (w.severity || 'warning'),
						style: {
							display: 'flex',
							alignItems: 'flex-start',
							gap: '6px',
							fontSize: '11.5px',
							color: isError ? COLORS.red : COLORS.yellow
						}
					},
						el(Icon, { name: isError ? 'warning' : 'info-outline', color: isError ? COLORS.red : COLORS.yellow }),
						el('span', null, w.message || '')
					);
				})
			),

			status === 'network-error' && errorMessage && el('div', {
				style: { marginTop: '4px', fontSize: '11px', color: COLORS.grey }
			}, errorMessage),

			(status === 'yellow' || status === 'red') && el('div', {
				className: 'drw-conflict-checker-disclaimer',
				style: { marginTop: '6px', fontSize: '11px', color: COLORS.grey, fontStyle: 'italic' }
			}, 'Estas son advertencias informativas: no bloquean el guardado.')
		);
	}

	window.DrwConflictChecker = ConflictChecker;

	// Exposed for reuse/testing without spinning up the full component tree.
	window.DrwConflictChecker.checkConflicts = checkConflicts;
	window.DrwConflictChecker.semaphoreFromWarnings = semaphoreFromWarnings;

})();
