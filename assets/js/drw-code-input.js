/**
 * OmniDiscount — CodeInput component
 * Promo code field with a random-code generator and a live,
 * non-destructive uniqueness check against the REST API.
 *
 * Uses WordPress core React (wp-element) and wp.apiFetch, same as
 * admin-promos.js. Loaded as an independent script (see AdminController)
 * so it exposes itself as window.DrwCodeInput for consumers to use.
 *
 * Usage:
 *   el(window.DrwCodeInput, {
 *       value: f.code,
 *       promoId: isNew ? null : f.id,
 *       onChange: function (code) { set('code', code); }
 *   })
 */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;

	var DEBOUNCE_MS = 400;
	var CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	var CODE_LENGTH = 8;
	var VALID_CODE_RE = /^[A-Z0-9_]+$/;

	/**
	 * Generate a random uppercase alphanumeric code (8 chars by default).
	 * Uses crypto.getRandomValues when available for better randomness,
	 * falling back to Math.random in environments without it.
	 *
	 * @return {string}
	 */
	function generateRandomCode() {
		var out = '';
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			var buf = new Uint32Array(CODE_LENGTH);
			window.crypto.getRandomValues(buf);
			for (var i = 0; i < CODE_LENGTH; i++) {
				out += CODE_ALPHABET.charAt(buf[i] % CODE_ALPHABET.length);
			}
		} else {
			for (var j = 0; j < CODE_LENGTH; j++) {
				out += CODE_ALPHABET.charAt(Math.floor(Math.random() * CODE_ALPHABET.length));
			}
		}
		return out;
	}

	/**
	 * Small inline status icon (mirrors the dashicons approach used
	 * elsewhere in the admin UI, without depending on admin-promos.js).
	 */
	function StatusIcon(props) {
		return el('span', {
			className: 'dashicons dashicons-' + props.name,
			style: {
				fontSize: '15px',
				width: '15px',
				height: '15px',
				lineHeight: '15px',
				color: props.color
			}
		});
	}

	/**
	 * CodeInput({ value, onChange, promoId })
	 *
	 * - value:    current code string (already expected uppercase; the
	 *             component itself uppercases on every keystroke).
	 * - onChange: function(nextCode) — called on every keystroke and after
	 *             "Generar código" so the parent form state stays in sync.
	 * - promoId:  id of the promo being edited, or falsy/null for a new
	 *             promo. Passed through as ?exclude= so a promo doesn't
	 *             collide with its own currently-saved code.
	 */
	function CodeInput(props) {
		var value = props.value || '';
		var onChange = props.onChange || function () {};
		var promoId = props.promoId || null;

		var statusState = useState('idle'); // idle | checking | available | unavailable | error
		var status = statusState[0];
		var setStatus = statusState[1];

		var messageState = useState('');
		var message = messageState[0];
		var setMessage = messageState[1];

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

		/**
		 * Ask the backend whether `code` is available. Read-only: hits
		 * GET /drw/v1/promos/check-code, which reuses find_duplicate_code()
		 * and never creates/mutates a promo or coupon.
		 *
		 * @param {string} code Candidate code.
		 */
		function runCheck(code) {
			var candidate = (code || '').toString().trim().toUpperCase();

			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}

			if (!candidate) {
				setStatus('idle');
				setMessage('');
				return;
			}

			var myReqId = ++reqIdRef.current;
			setStatus('checking');
			setMessage('');

			var path = '/drw/v1/promos/check-code?code=' + encodeURIComponent(candidate) +
				(promoId ? '&exclude=' + encodeURIComponent(promoId) : '');

			apiFetch({ path: path })
				.then(function (res) {
					// Ignore stale responses from a superseded/out-of-order request.
					if (!mountedRef.current || myReqId !== reqIdRef.current) { return; }
					var available = !!(res && res.available);
					setStatus(available ? 'available' : 'unavailable');
					setMessage((res && res.message) || '');
				})
				.catch(function (err) {
					if (!mountedRef.current || myReqId !== reqIdRef.current) { return; }
					setStatus('error');
					setMessage((err && err.message) || 'No se pudo verificar el código.');
				});
		}

		/**
		 * Debounce live checks while the user is typing (400ms).
		 *
		 * @param {string} code Candidate code.
		 */
		function scheduleCheck(code) {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
			}
			timerRef.current = setTimeout(function () {
				timerRef.current = null;
				runCheck(code);
			}, DEBOUNCE_MS);
		}

		function handleChange(e) {
			var next = e.target.value.toUpperCase();
			onChange(next);
			if (!next.trim()) {
				setStatus('idle');
				setMessage('');
				if (timerRef.current) {
					clearTimeout(timerRef.current);
					timerRef.current = null;
				}
				return;
			}
			scheduleCheck(next);
		}

		function handleBlur() {
			// Leaving the field should not wait out the debounce window.
			runCheck(value);
		}

		function handleGenerate() {
			var next = generateRandomCode();
			onChange(next);
			// A freshly generated code is a deliberate action; verify immediately.
			runCheck(next);
		}

		var borderColor = null;
		if (status === 'available') { borderColor = 'var(--drw-success, #10b981)'; }
		else if (status === 'unavailable' || status === 'error') { borderColor = 'var(--drw-error, #ef4444)'; }

		var statusNode = null;
		if (status === 'checking') {
			statusNode = el('span', { className: 'drw-code-input-status', style: { color: 'var(--drw-muted, #8b8b8b)', fontSize: '11.5px' } },
				'Verificando disponibilidad…'
			);
		} else if (status === 'available') {
			statusNode = el('span', { className: 'drw-code-input-status', style: { display: 'inline-flex', alignItems: 'center', gap: '4px', color: 'var(--drw-success, #10b981)', fontSize: '11.5px' } },
				el(StatusIcon, { name: 'yes-alt', color: 'var(--drw-success, #10b981)' }),
				message || 'Código disponible.'
			);
		} else if (status === 'unavailable' || status === 'error') {
			statusNode = el('span', { className: 'drw-code-input-status', style: { display: 'inline-flex', alignItems: 'center', gap: '4px', color: 'var(--drw-error, #ef4444)', fontSize: '11.5px' } },
				el(StatusIcon, { name: 'warning', color: 'var(--drw-error, #ef4444)' }),
				message || 'Este código no está disponible.'
			);
		}

		return el('div', { className: 'drw-code-input' },
			el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
				el('input', {
					type: 'text',
					className: 'drw-text-mono',
					value: value,
					placeholder: 'MADRUGON15',
					style: borderColor ? { borderColor: borderColor, flex: 1 } : { flex: 1 },
					onChange: handleChange,
					onBlur: handleBlur
				}),
				el('button', {
					type: 'button',
					className: 'drw-btn drw-btn-ghost drw-btn-sm',
					onClick: handleGenerate
				}, 'Generar código')
			),
			statusNode && el('div', { style: { marginTop: '4px' } }, statusNode)
		);
	}

	window.DrwCodeInput = CodeInput;

	// Exposed for reuse/testing without spinning up the full component tree.
	window.DrwCodeInput.generateRandomCode = generateRandomCode;
	window.DrwCodeInput.isValidCodeFormat = function (code) {
		return VALID_CODE_RE.test((code || '').toString().trim().toUpperCase());
	};

})();
