/**
 * OmniDiscount — PromoWizard (4-step create/edit flow)
 *
 * Replaces the single-step PromoEditor modal from admin-promos.js with a
 * guided, 4-step wizard that stitches together the standalone component
 * library shipped alongside it:
 *
 *   Paso 0 — window.DrwTemplateGallery      (plantillas por objetivo)
 *   Paso 1 — Alcance real vía window.DrwProductCategoryPicker
 *            (pestañas Toda la tienda / Productos / Categorías) → { target, ids }
 *   Paso 2 — Valor + límites + vigencia + código, usando los editores
 *            avanzados según el tipo:
 *              tiered                         -> window.DrwTieredTableEditor
 *              2x1 | 3x2 | second_unit | gift -> window.DrwBogoBuyGetPicker
 *              bundle                         -> window.DrwBundleBuilder
 *            y window.DrwCodeInput en vez del input de código plano.
 *   Paso 3 — Resumen en texto plano + "Guardar borrador" / "Publicar".
 *
 * Plain wp.element (no JSX/build step), ES5-style closures, exposed as
 * window.DrwPromoWizard. It is a controlled modal: it receives { promo,
 * onClose, onSave, onExpertMode } from PromosPage and hands a payload back to
 * the SAME onSave that already talks to POST/PUT /drw/v1/promos — the only
 * contract change is that `scope` is now a { target, ids } object instead of a
 * free-form string (PromosController::validate_promo accepts both shapes).
 */
(function () {
	'use strict';

	if (typeof wp === 'undefined' || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;

	// Single source of truth: PromoTypeRegistry (PHP) via wp_localize_script.
	var PROMO_TYPES = (window.drwAdminData && window.drwAdminData.promoTypes) || [];

	function getType(id) {
		for (var i = 0; i < PROMO_TYPES.length; i++) {
			if (PROMO_TYPES[i].id === id) {
				return PROMO_TYPES[i];
			}
		}
		return PROMO_TYPES[0] || { id: 'percent', label: 'Descuento', valueType: 'percent', needsCode: false, color: '#5b7b41', icon: 'tag', short: '% OFF' };
	}

	// -- Small helpers ------------------------------------------------------
	function todayISO() {
		return new Date().toISOString().slice(0, 10);
	}

	function fmtN(v) {
		return Number(v || 0).toLocaleString('es-CO');
	}

	function fmtCOP(v) {
		return '$' + Number(v || 0).toLocaleString('es-CO');
	}

	// Dashicons wrapper (mirrors admin-promos.js's Icon so the type grid looks
	// identical to the classic editor; registry icons are dashicon names).
	function Icon(props) {
		var s = props.size || 16;
		return el('span', {
			className: 'dashicons dashicons-' + props.name,
			style: Object.assign({ fontSize: s + 'px', width: s + 'px', height: s + 'px', lineHeight: s + 'px' }, props.style || {})
		});
	}

	function maxTierDiscount(tiers) {
		if (!Array.isArray(tiers) || !tiers.length) { return 0; }
		var m = 0;
		tiers.forEach(function (t) {
			var d = Number(t.discountPercent) || 0;
			if (d > m) { m = d; }
		});
		return m;
	}

	/**
	 * Normalise scope into the canonical { target, ids } object the backend
	 * now stores. A legacy free-form string cannot be mapped to concrete ids,
	 * so it degrades to "toda la tienda".
	 */
	function normalizeScope(scope) {
		if (scope && typeof scope === 'object') {
			var target = scope.target || 'all';
			if (target !== 'products' && target !== 'category') { target = 'all'; }
			var ids = [];
			if (target !== 'all' && Array.isArray(scope.ids)) {
				scope.ids.forEach(function (x) {
					var n = parseInt(x, 10);
					if (n > 0 && ids.indexOf(n) === -1) { ids.push(n); }
				});
			}
			return { target: target, ids: ids };
		}
		return { target: 'all', ids: [] };
	}

	function scopeSummary(scope) {
		var s = normalizeScope(scope);
		if (s.target === 'all') { return 'Toda la tienda'; }
		var n = s.ids.length;
		if (s.target === 'products') { return n === 1 ? '1 producto específico' : n + ' productos específicos'; }
		return n === 1 ? '1 categoría específica' : n + ' categorías específicas';
	}

	function defaultForm() {
		return {
			name: '', code: '', type: 'percent', value: 10,
			scope: { target: 'all', ids: [] },
			minAmount: 0, limitGlobal: 0, limitUser: 1,
			start: todayISO(), end: '',
			active: true, home: false, priority: 5,
			cartMessage: '', giftText: '',
			// UI-only editor state for this phase (not persisted server-side yet;
			// stripped from the save payload so the REST contract is unchanged).
			tiers: [], bogo: null, bundle: { items: [], price: '' },
			uses: 0
		};
	}

	function seedFromPromo(promo) {
		var merged = Object.assign(defaultForm(), promo);
		merged.scope = normalizeScope(promo.scope);
		if (!merged.bundle) { merged.bundle = { items: [], price: '' }; }
		if (!Array.isArray(merged.tiers)) { merged.tiers = []; }
		return merged;
	}

	// =======================================================================
	// StepperNav — 4 numbered steps with a clickable, guarded progress bar
	// =======================================================================
	var STEP_LABELS = ['Plantilla', 'Alcance', 'Configuración', 'Resumen'];

	function StepperNav(props) {
		var current = props.current;
		var onGo = props.onGo;
		var minStep = props.minStep || 0; // editing skips the template step

		var nodes = [];
		for (var i = 0; i < STEP_LABELS.length; i++) {
			(function (idx) {
				var cls = 'drw-stepper-step';
				if (idx === current) { cls += ' active'; }
				else if (idx < current) { cls += ' completed'; }
				var reachable = idx >= minStep && idx <= current;
				nodes.push(el('button', {
					key: 'step-' + idx,
					type: 'button',
					className: cls,
					disabled: !reachable,
					onClick: reachable ? function () { onGo(idx); } : undefined
				},
					el('span', { className: 'drw-stepper-num' }, idx < current ? '✓' : String(idx + 1)),
					el('span', { className: 'drw-stepper-label' }, STEP_LABELS[idx])
				));
				if (idx < STEP_LABELS.length - 1) {
					nodes.push(el('span', { key: 'line-' + idx, className: 'drw-stepper-line' + (idx < current ? ' done' : '') }));
				}
			})(i);
		}
		return el('div', { className: 'drw-stepper' }, nodes);
	}

	// =======================================================================
	// ScopePicker — Paso 1: three tabs producing { target, ids }
	// =======================================================================
	var SCOPE_TABS = [
		{ id: 'all', label: 'Toda la tienda' },
		{ id: 'products', label: 'Productos específicos' },
		{ id: 'category', label: 'Categorías específicas' }
	];

	function ScopePicker(props) {
		var value = normalizeScope(props.value);
		var onChange = props.onChange || function () {};
		var target = value.target;
		var Picker = window.DrwProductCategoryPicker;

		function setTarget(t) {
			if (t === target) { return; }
			onChange({ target: t, ids: [] });
		}
		function setIds(ids) {
			onChange({ target: target, ids: Array.isArray(ids) ? ids : [] });
		}

		return el('div', { className: 'drw-scope-picker' },
			el('div', { className: 'drw-scope-tabs' },
				SCOPE_TABS.map(function (tab) {
					return el('button', {
						key: tab.id,
						type: 'button',
						className: 'drw-scope-tab' + (target === tab.id ? ' active' : ''),
						onClick: function () { setTarget(tab.id); }
					}, tab.label);
				})
			),

			target === 'all' && el('div', { className: 'drw-scope-note' },
				'La promoción se aplicará a todos los productos del carrito.'
			),

			target === 'products' && (typeof Picker === 'function'
				? el(Picker, {
					mode: 'products',
					value: value.ids,
					onChange: setIds,
					label: 'Buscar productos',
					help: 'Elige los productos a los que aplica la promoción.'
				})
				: el('div', { className: 'drw-scope-note' }, 'Selector de productos no disponible.')
			),

			target === 'category' && (typeof Picker === 'function'
				? el(Picker, {
					mode: 'categories',
					value: value.ids,
					onChange: setIds,
					label: 'Buscar categorías',
					help: 'Elige las categorías a las que aplica la promoción.'
				})
				: el('div', { className: 'drw-scope-note' }, 'Selector de categorías no disponible.')
			)
		);
	}

	// =======================================================================
	// PromoWizard
	// =======================================================================
	function PromoWizard(props) {
		var promo = props.promo;
		var onClose = props.onClose || function () {};
		var onSave = props.onSave || function () {};
		var onExpertMode = props.onExpertMode;
		var isNew = !promo;

		var formState = useState(isNew ? defaultForm() : seedFromPromo(promo));
		var f = formState[0];
		var setF = formState[1];

		// New promos start at the gallery; editing skips straight to Alcance.
		var stepState = useState(isNew ? 0 : 1);
		var step = stepState[0];
		var setStep = stepState[1];

		var savingState = useState(false);
		var saving = savingState[0];
		var setSaving = savingState[1];

		// Sample product for LivePreviewPanel (Paso 2) when the scope itself
		// doesn't already name a specific product (target === 'products').
		var previewProductState = useState(null);
		var previewProductId = previewProductState[0];
		var setPreviewProductId = previewProductState[1];

		// "Probar en mi tienda" (sandbox mode) result, Paso 3. Local/ephemeral —
		// not part of the saved promo, just feedback for the click.
		var sandboxState = useState({ status: 'idle', message: '' }); // idle | loading | ok | error
		var sandbox = sandboxState[0];
		var setSandbox = sandboxState[1];

		var mountedRef = useRef(true);
		useEffect(function () {
			mountedRef.current = true;
			return function () { mountedRef.current = false; };
		}, []);

		function set(k, v) {
			setF(function (s) {
				var n = Object.assign({}, s);
				n[k] = v;
				return n;
			});
		}
		function patch(obj) {
			setF(function (s) { return Object.assign({}, s, obj); });
		}

		var t = getType(f.type);
		var needsCode = !!t.needsCode;
		var valid = (f.name || '').trim().length >= 3 && (!needsCode || (f.code || '').trim().length >= 3);
		var minStep = isNew ? 0 : 1;

		// -- Template gallery callbacks -------------------------------------
		function applyTemplate(tpl) {
			tpl = tpl || {};
			patch({
				name: tpl.name || '',
				type: tpl.type || 'percent',
				value: tpl.value !== undefined ? tpl.value : 0,
				scope: normalizeScope(tpl.scope),
				code: tpl.code || '',
				minAmount: tpl.minAmount || 0,
				limitGlobal: tpl.limitGlobal || 0,
				limitUser: tpl.limitUser || 0,
				start: tpl.start || todayISO(),
				end: tpl.end || '',
				cartMessage: tpl.cartMessage || '',
				giftText: tpl.giftText || ''
			});
			setStep(2); // template chosen -> jump straight to config, pre-filled
		}
		function startBlank() {
			setStep(1);
		}

		// -- Save -----------------------------------------------------------
		function buildPayload(active) {
			var value = Number(f.value) || 0;
			if (f.type === 'bundle') {
				value = Number(f.bundle && f.bundle.price) || 0;
			} else if (f.type === 'tiered') {
				value = maxTierDiscount(f.tiers);
			}

			var payload = Object.assign({}, f, {
				code: (f.code || '').trim().toUpperCase(),
				value: value,
				scope: normalizeScope(f.scope),
				minAmount: Number(f.minAmount) || 0,
				limitGlobal: Number(f.limitGlobal) || 0,
				limitUser: Number(f.limitUser) || 0,
				priority: Number(f.priority) || 5,
				active: !!active
			});

			// UI-only editor state — not part of the REST contract yet.
			delete payload.tiers;
			delete payload.bogo;
			delete payload.bundle;
			return payload;
		}

		function doSave(active) {
			if (saving || !valid) { return; }
			setSaving(true);
			var ret = onSave(buildPayload(active));
			if (ret && typeof ret.then === 'function') {
				ret.then(function () {}, function () {}).then(function () {
					if (mountedRef.current) { setSaving(false); }
				});
			} else {
				setSaving(false);
			}
		}

		// -- Sandbox mode (Paso 3) -------------------------------------------
		// Only meaningful for an already-saved promo (needs a real id) of an
		// automatic type (needsCode === false) — see
		// PromosController::activate_sandbox() for the same two guards
		// enforced server-side.
		function doSandbox() {
			if (isNew || needsCode || sandbox.status === 'loading') { return; }
			setSandbox({ status: 'loading', message: '' });
			apiFetch({ path: '/drw/v1/promos/' + f.id + '/sandbox', method: 'POST' })
				.then(function (res) {
					if (!mountedRef.current) { return; }
					setSandbox({ status: 'ok', message: (res && res.message) || 'Activado solo para tu sesión de administrador.' });
				})
				.catch(function (err) {
					if (!mountedRef.current) { return; }
					setSandbox({ status: 'error', message: (err && err.message) || 'No se pudo activar el modo sandbox.' });
				});
		}

		// -- Value / mechanic editors (Paso 2) ------------------------------
		function renderMechanic() {
			var type = f.type;
			var nodes = [];

			// Generic value input — suppressed for tiered/bundle where a
			// dedicated editor owns the "value" concept (per-tier % / combo price).
			var showGenericValue = t.valueType !== 'none' && type !== 'tiered' && type !== 'bundle';
			if (showGenericValue) {
				if (t.valueType === 'percent') {
					nodes.push(el('div', { className: 'drw-field', key: 'val' },
						el('label', null, type === 'second_unit' ? 'Descuento en la 2ª unidad' : 'Porcentaje de descuento'),
						el('div', { style: { display: 'flex', alignItems: 'center', gap: 8 } },
							el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); }, style: { flex: 1 } }),
							el('span', { className: 'drw-field-hint' }, '%')
						)
					));
				} else if (t.valueType === 'currency') {
					nodes.push(el('div', { className: 'drw-field', key: 'val' },
						el('label', null, type === 'launch' ? 'Precio de lanzamiento (COP)' : 'Monto de descuento (COP)'),
						el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); } })
					));
				} else if (t.valueType === 'text') {
					nodes.push(el('div', { className: 'drw-field', key: 'val' },
						el('label', null, 'Regalo incluido'),
						el('input', { value: f.giftText || '', onChange: function (e) { set('giftText', e.target.value); }, placeholder: 'Ej. Bolsa reutilizable' })
					));
				}
			}

			// Advanced, type-specific editors.
			if (type === 'tiered' && typeof window.DrwTieredTableEditor === 'function') {
				nodes.push(el(window.DrwTieredTableEditor, {
					key: 'tiered',
					tiers: f.tiers || [],
					onChange: function (tiers) { set('tiers', tiers); }
				}));
			} else if ((type === '2x1' || type === '3x2' || type === 'second_unit' || type === 'gift') && typeof window.DrwBogoBuyGetPicker === 'function') {
				nodes.push(el(window.DrwBogoBuyGetPicker, {
					key: 'bogo',
					value: f.bogo || {},
					onChange: function (v) { set('bogo', v); }
				}));
			} else if (type === 'bundle' && typeof window.DrwBundleBuilder === 'function') {
				var b = f.bundle || { items: [], price: '' };
				nodes.push(el(window.DrwBundleBuilder, {
					key: 'bundle',
					items: b.items || [],
					price: b.price,
					onChange: function (next) { set('bundle', { items: (next && next.items) || [], price: next ? next.price : '' }); }
				}));
			}

			return nodes;
		}

		function valueSummary() {
			if (f.type === 'free_ship' || f.type === 'free_ship_threshold') { return 'Envío gratis'; }
			if (f.type === '2x1') { return '2×1'; }
			if (f.type === '3x2') { return '3×2'; }
			if (f.type === 'bundle') { return fmtCOP(Number(f.bundle && f.bundle.price) || 0); }
			if (f.type === 'tiered') { return 'Hasta ' + maxTierDiscount(f.tiers) + '% OFF por cantidad'; }
			if (t.valueType === 'percent') { return (Number(f.value) || 0) + '% OFF'; }
			if (t.valueType === 'currency') { return (f.type === 'launch' ? '' : '−') + fmtCOP(Number(f.value) || 0); }
			if (t.valueType === 'text') { return f.giftText || 'Regalo'; }
			return t.short;
		}

		// -- Step renderers -------------------------------------------------
		function renderTemplateStep() {
			var Gallery = window.DrwTemplateGallery;
			if (typeof Gallery === 'function') {
				return el(Gallery, { onSelectTemplate: applyTemplate, onStartBlank: startBlank });
			}
			return el('div', { className: 'drw-scope-note' },
				el('p', { style: { margin: '0 0 12px' } }, 'La galería de plantillas no está disponible.'),
				el('button', { type: 'button', className: 'drw-btn drw-btn-primary', onClick: startBlank }, 'Empezar en blanco')
			);
		}

		function renderScopeStep() {
			return el('div', null,
				el('label', { className: 'drw-section-label' }, '¿A qué aplica la promoción?'),
				el(ScopePicker, { value: f.scope, onChange: function (s) { set('scope', s); } })
			);
		}

		function renderConfigStep() {
			var mainFields = el('div', { className: 'drw-fields' },
				el('div', null,
					el('label', { className: 'drw-section-label' }, 'Tipo de oferta'),
					el('div', { className: 'drw-promo-type-grid' },
						PROMO_TYPES.map(function (tt) {
							var isActive = f.type === tt.id;
							var btnStyle = isActive
								? { borderColor: tt.color, background: 'color-mix(in srgb, ' + tt.color + ' 8%, white)' }
								: undefined;
							return el('button', {
								key: tt.id,
								type: 'button',
								className: 'drw-promo-type' + (isActive ? ' active' : ''),
								onClick: function () { set('type', tt.id); },
								style: btnStyle
							},
								el('span', {
									className: 'drw-type-icon',
									style: { background: 'color-mix(in srgb, ' + tt.color + ' 16%, white)', color: tt.color }
								}, el(Icon, { name: tt.icon, size: 15 })),
								el('span', { className: 'drw-type-name' }, tt.label)
							);
						})
					)
				),

				el('div', { className: 'drw-fields-row', style: { gridTemplateColumns: needsCode ? '1.4fr 1fr' : '1fr' } },
					el('div', { className: 'drw-field' },
						el('label', null, 'Nombre interno'),
						el('input', { value: f.name, onChange: function (e) { set('name', e.target.value); }, placeholder: 'Ej. Madrugón marca propia' })
					),
					needsCode && el('div', { className: 'drw-field' },
						el('label', null, 'Código'),
						typeof window.DrwCodeInput === 'function'
							? el(window.DrwCodeInput, { value: f.code, promoId: isNew ? null : f.id, onChange: function (c) { set('code', c); } })
							: el('input', { className: 'drw-text-mono', value: f.code, onChange: function (e) { set('code', e.target.value.toUpperCase()); }, placeholder: 'MADRUGON15' })
					)
				),

				el('div', null, renderMechanic()),

				el('div', { className: 'drw-fields-row', style: { gridTemplateColumns: '1fr 1fr 1fr' } },
					el('div', { className: 'drw-field' },
						el('label', null, 'Compra mínima (COP)'),
						el('input', { type: 'number', value: f.minAmount, onChange: function (e) { set('minAmount', e.target.value); }, placeholder: '0 = sin mínimo' })
					),
					el('div', { className: 'drw-field' },
						el('label', null, 'Límite total de usos'),
						el('input', { type: 'number', value: f.limitGlobal, onChange: function (e) { set('limitGlobal', e.target.value); }, placeholder: '0 = ilimitado' })
					),
					el('div', { className: 'drw-field' },
						el('label', null, 'Usos por cliente'),
						el('input', { type: 'number', value: f.limitUser, onChange: function (e) { set('limitUser', e.target.value); }, placeholder: '0 = ilimitado' })
					)
				),

				el('div', { className: 'drw-fields-row' },
					el('div', { className: 'drw-field' },
						el('label', null, 'Inicia'),
						el('input', { type: 'date', value: f.start, onChange: function (e) { set('start', e.target.value); } })
					),
					el('div', { className: 'drw-field' },
						el('label', null, 'Termina'),
						el('span', { className: 'drw-field-hint' }, 'vacío = permanente'),
						el('input', { type: 'date', value: f.end, onChange: function (e) { set('end', e.target.value); } })
					)
				),

				el('div', { className: 'drw-field' },
					el('label', null, 'Mensaje en el carrito'),
					el('input', { value: f.cartMessage, onChange: function (e) { set('cartMessage', e.target.value); }, placeholder: 'Ej. ¡Descuento aplicado!' })
				),

				el('div', { style: { display: 'flex', gap: 18, padding: '4px 2px' } },
					el('label', { className: 'drw-toggle-label' },
						el('button', { type: 'button', className: 'drw-sw' + (f.active ? ' on' : ''), onClick: function () { set('active', !f.active); } }),
						' Activa'
					),
					el('label', { className: 'drw-toggle-label' },
						el('button', { type: 'button', className: 'drw-sw' + (f.home ? ' on' : ''), onClick: function () { set('home', !f.home); } }),
						' Mostrar en portada'
					)
				)
			);

			// Live preview needs a real WC product to price against. Reuse the
			// scope's own first product when it names one; otherwise let the
			// merchant pick any product just for previewing (not persisted).
			var scopeProductId = (f.scope && f.scope.target === 'products' && Array.isArray(f.scope.ids) && f.scope.ids.length > 0)
				? f.scope.ids[0]
				: null;
			var sampleProductId = scopeProductId || previewProductId;

			var LivePreview = window.DrwLivePreviewPanel;
			var Picker = window.DrwProductCategoryPicker;
			// No section label here: LivePreviewPanel already renders its own
			// "Vista previa en vivo" header, so an outer one would just repeat it.
			var sidebar = typeof LivePreview !== 'function' ? null : el('div', {
				className: 'drw-wizard-sidebar',
				style: { flex: '0 1 280px', minWidth: 0 }
			},
				!scopeProductId && typeof Picker === 'function' && el('div', { className: 'drw-field', style: { marginBottom: 10 } },
					el('label', null, 'Producto de ejemplo'),
					el(Picker, {
						mode: 'products',
						value: previewProductId ? [previewProductId] : [],
						onChange: function (ids) {
							var last = Array.isArray(ids) && ids.length ? ids[ids.length - 1] : null;
							setPreviewProductId(last);
						},
						label: 'Buscar un producto para previsualizar',
						help: 'Solo para esta vista previa; no cambia el alcance de la promoción. Si eliges varios, se usa el último que selecciones.'
					})
				),
				el(LivePreview, { promoDraft: f, sampleProductId: sampleProductId })
			);

			return el('div', {
				className: 'drw-wizard-config-layout',
				style: { display: 'flex', flexWrap: 'wrap', gap: '20px', alignItems: 'flex-start' }
			},
				el('div', { style: { flex: '1 1 380px', minWidth: 0 } }, mainFields),
				sidebar
			);
		}

		function summaryRow(key, val) {
			return el('div', { className: 'drw-summary-row', key: key },
				el('div', { className: 'drw-summary-key' }, key),
				el('div', { className: 'drw-summary-val' }, val)
			);
		}

		function renderSummaryStep() {
			var vigencia = f.end ? (f.start + ' → ' + f.end) : (f.start ? ('Desde ' + f.start + ' (permanente)') : 'Permanente');
			var NLSummary = window.DrwNaturalLanguageSummary;
			var ConflictChecker = window.DrwConflictChecker;
			var StatsPanel = window.DrwPromoStatsPanel;

			return el('div', null,
				el('label', { className: 'drw-section-label' }, 'Revisa y publica'),

				typeof NLSummary === 'function' && el('div', { style: { marginBottom: 14 } },
					el(NLSummary, { promoDraft: f })
				),

				el('div', { className: 'drw-summary-card' },
					summaryRow('Nombre', f.name || el('span', { className: 'drw-field-hint' }, 'Sin nombre')),
					summaryRow('Tipo', t.label),
					summaryRow('Valor', valueSummary()),
					summaryRow('Aplica a', scopeSummary(f.scope)),
					summaryRow('Código', f.code ? el('span', { className: 'drw-text-mono' }, f.code) : el('span', { className: 'drw-field-hint' }, 'Automática (sin código)')),
					summaryRow('Vigencia', vigencia),
					summaryRow('Compra mínima', Number(f.minAmount) > 0 ? fmtCOP(f.minAmount) : 'Sin mínimo'),
					summaryRow('Límite total', Number(f.limitGlobal) > 0 ? fmtN(f.limitGlobal) + ' usos' : 'Ilimitado'),
					summaryRow('Por cliente', Number(f.limitUser) > 0 ? f.limitUser + '×' : 'Ilimitado'),
					f.cartMessage && summaryRow('Mensaje', f.cartMessage)
				),

				typeof ConflictChecker === 'function' && el('div', { style: { marginTop: 14 } },
					el(ConflictChecker, {
						promoDraft: {
							id: isNew ? null : f.id,
							name: f.name,
							code: f.code,
							type: f.type,
							value: f.value,
							scope: f.scope,
							start: f.start,
							end: f.end
						}
					})
				),

				!isNew && typeof StatsPanel === 'function' && el('div', { style: { marginTop: 16 } },
					el('label', { className: 'drw-section-label' }, 'Desempeño real'),
					el(StatsPanel, { promoId: f.id })
				),

				!isNew && !needsCode && el('div', { className: 'drw-sandbox-box', style: { marginTop: 16, padding: '12px 14px', border: '1px dashed var(--drw-border, #d8dcd3)', borderRadius: 8 } },
					el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' } },
						el('div', null,
							el('div', { style: { fontWeight: 600, fontSize: 13 } }, 'Probar en mi tienda'),
							el('div', { className: 'drw-field-hint' }, 'Actívala solo para tu sesión de administrador, sin publicarla a clientes reales.')
						),
						el('button', {
							type: 'button',
							className: 'drw-btn drw-btn-ghost drw-btn-sm',
							disabled: sandbox.status === 'loading',
							onClick: doSandbox
						}, sandbox.status === 'loading' ? 'Activando…' : 'Probar en mi tienda')
					),
					sandbox.status === 'ok' && el('p', { style: { marginTop: 8, marginBottom: 0, fontSize: 12.5, color: 'var(--drw-success, #10b981)' } }, sandbox.message),
					sandbox.status === 'error' && el('p', { style: { marginTop: 8, marginBottom: 0, fontSize: 12.5, color: 'var(--drw-error, #ef4444)' } }, sandbox.message)
				),

				!valid && el('p', { className: 'drw-field-hint', style: { marginTop: 12, color: 'var(--drw-error)' } },
					needsCode && (f.code || '').trim().length < 3
						? 'Falta un código válido (mínimo 3 caracteres) para este tipo de promoción.'
						: 'Falta el nombre interno (mínimo 3 caracteres).'
				)
			);
		}

		// -- Footer navigation ----------------------------------------------
		function renderFooter() {
			if (step === 0) {
				// The gallery owns its own primary actions (select / blank).
				return el('div', { className: 'drw-wizard-foot' },
					el('button', { type: 'button', className: 'drw-btn drw-btn-ghost', onClick: onClose }, 'Cancelar'),
					el('div', { className: 'drw-wizard-foot-right' },
						el('button', { type: 'button', className: 'drw-btn drw-btn-ghost', onClick: startBlank }, 'Empezar en blanco →')
					)
				);
			}

			var backTarget = step - 1 < minStep ? null : step - 1;

			var left = backTarget === null
				? el('button', { type: 'button', className: 'drw-btn drw-btn-ghost', onClick: onClose }, 'Cancelar')
				: el('button', { type: 'button', className: 'drw-btn drw-btn-ghost', onClick: function () { setStep(backTarget); } }, '← Atrás');

			var right;
			if (step < 3) {
				right = el('button', {
					type: 'button',
					className: 'drw-btn drw-btn-primary',
					disabled: step === 2 && !valid,
					style: { opacity: (step === 2 && !valid) ? 0.5 : 1 },
					onClick: function () { setStep(step + 1); }
				}, 'Siguiente →');
			} else {
				right = el('div', { className: 'drw-wizard-foot-right' },
					el('button', {
						type: 'button',
						className: 'drw-btn drw-btn-ghost',
						disabled: !valid || saving,
						style: { opacity: (!valid || saving) ? 0.5 : 1 },
						onClick: function () { doSave(false); }
					}, 'Guardar borrador'),
					el('button', {
						type: 'button',
						className: 'drw-btn drw-btn-primary',
						disabled: !valid || saving,
						style: { opacity: (!valid || saving) ? 0.5 : 1 },
						onClick: function () { doSave(true); }
					}, saving ? 'Guardando…' : (isNew ? 'Publicar' : 'Guardar y publicar'))
				);
			}

			return el('div', { className: 'drw-wizard-foot' }, left, right);
		}

		var body;
		if (step === 0) { body = renderTemplateStep(); }
		else if (step === 1) { body = renderScopeStep(); }
		else if (step === 2) { body = renderConfigStep(); }
		else { body = renderSummaryStep(); }

		return el('div', { className: 'drw-overlay', onClick: onClose },
			el('div', { className: 'drw-modal drw-wizard-modal', onClick: function (e) { e.stopPropagation(); } },
				el('div', { className: 'drw-modal-header' },
					el('div', { className: 'drw-wizard-head' },
						el('h3', { className: 'drw-modal-title' }, isNew ? 'Nueva promoción' : 'Editar promoción')
					),
					el('div', { className: 'drw-wizard-head-actions' },
						typeof onExpertMode === 'function' && el('button', {
							type: 'button',
							className: 'drw-btn drw-btn-ghost drw-btn-sm',
							title: 'Editar con el formulario clásico de un solo paso',
							onClick: onExpertMode
						}, 'Modo experto'),
						el('button', { className: 'drw-modal-close', onClick: onClose, 'aria-label': 'Cerrar' },
							el(Icon, { name: 'no-alt', size: 15 })
						)
					)
				),

				el(StepperNav, { current: step, minStep: minStep, onGo: function (i) { setStep(i); } }),

				el('div', { className: 'drw-wizard-body' }, body),

				renderFooter()
			)
		);
	}

	window.DrwPromoWizard = PromoWizard;

})();
