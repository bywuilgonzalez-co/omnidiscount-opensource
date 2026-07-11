/**
 * OmniDiscount — Promos & Coupons Dashboard
 * Faithful implementation of the El Caunzal prototype UX.
 * Uses WordPress core React (wp-element) and wp.apiFetch.
 */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;

	// Single source of truth: Drw\App\Models\PromoTypeRegistry (PHP), preloaded
	// via wp_localize_script so there is no duplicate catalogue here anymore.
	var PROMO_TYPES = (window.drwAdminData && window.drwAdminData.promoTypes) || [];

	function getType(id) {
		for (var i = 0; i < PROMO_TYPES.length; i++) {
			if (PROMO_TYPES[i].id === id) {
				return PROMO_TYPES[i];
			}
		}
		return PROMO_TYPES[0];
	}

	function fmtN(v) {
		return Number(v).toLocaleString('es-CO');
	}

	function fmtCOP(v) {
		return '$' + Number(v).toLocaleString('es-CO');
	}

	function promoValueLabel(p) {
		var t = getType(p.type);
		if (p.type === 'free_ship' || p.type === 'free_ship_threshold') { return 'Envío gratis'; }
		if (p.type === '2x1') { return '2×1'; }
		if (p.type === '3x2') { return '3×2'; }
		if (t.valueType === 'percent') { return p.value + '% OFF'; }
		if (t.valueType === 'currency') {
			if (p.type === 'bundle' || p.type === 'launch') { return fmtCOP(p.value); }
			return '−' + fmtCOP(p.value);
		}
		if (t.valueType === 'text') { return p.giftText || 'Regalo'; }
		return t.short;
	}

	function Icon(props) {
		return el('span', {
			className: 'dashicons dashicons-' + props.name,
			style: Object.assign({ fontSize: (props.size || 16) + 'px', width: (props.size || 16) + 'px', height: (props.size || 16) + 'px', lineHeight: (props.size || 16) + 'px' }, props.style || {})
		});
	}

	// Human-readable label for a promo's `scope`, which may be either the
	// legacy free-form string or the new { target, ids } object produced by
	// the wizard's Paso 1.
	function scopeSummary(scope) {
		if (!scope) { return ''; }
		if (typeof scope === 'string') { return scope; }
		if (typeof scope === 'object') {
			var target = scope.target || 'all';
			var n = Array.isArray(scope.ids) ? scope.ids.length : 0;
			if (target === 'products') { return n === 1 ? '1 producto' : n + ' productos'; }
			if (target === 'category') { return n === 1 ? '1 categoría' : n + ' categorías'; }
			return 'Toda la tienda';
		}
		return '';
	}

	var toastContainer = null;

	function showToast(msg) {
		if (!toastContainer) {
			toastContainer = document.createElement('div');
			toastContainer.className = 'drw-toasts';
			toastContainer.setAttribute('aria-live', 'polite');
			document.body.appendChild(toastContainer);
		}
		var toast = document.createElement('div');
		toast.className = 'drw-toast';
		var ico = document.createElement('span');
		ico.className = 'drw-toast-ico';
		ico.textContent = '✓';
		toast.appendChild(ico);
		toast.appendChild(document.createTextNode(' ' + msg));
		toastContainer.appendChild(toast);
		setTimeout(function () {
			toast.style.opacity = '0';
			toast.style.transform = 'translateY(8px)';
			setTimeout(function () {
				if (toast.parentNode) { toast.parentNode.removeChild(toast); }
			}, 200);
		}, 3000);
	}

	// -- Promo Card -------------------------------------------------------
	function PromoCard(props) {
		var p = props.promo;
		var onEdit = props.onEdit;
		var onToggle = props.onToggle;
		var onDelete = props.onDelete;
		var t = getType(p.type);
		var pct = p.limitGlobal ? Math.min(100, Math.round((p.uses / p.limitGlobal) * 100)) : 0;

		return el('div', { className: 'drw-promo-card', style: { opacity: p.active ? 1 : 0.62 } },
			el('div', { className: 'drw-promo-header' },
				el('span', { className: 'drw-icon-tile', style: { '--drw-tile-color': t.color } },
					el(Icon, { name: t.icon, size: 19 })
				),
				el('div', { className: 'drw-promo-title-wrap' },
					el('div', { style: { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' } },
						el('span', { className: 'drw-promo-name' }, p.name),
						p.home && el('span', { className: 'drw-status-badge active' }, 'En portada')
					),
					el('div', { className: 'drw-promo-type-label' }, t.label + (scopeSummary(p.scope) ? ' · ' + scopeSummary(p.scope) : ''))
				),
				el('button', {
					className: 'drw-sw' + (p.active ? ' on' : ''),
					'aria-label': 'Activar ' + p.name,
					onClick: function () { onToggle(p.id); }
				})
			),

			el('div', { style: { display: 'flex', alignItems: 'center', gap: 10 } },
				el('span', {
					className: 'drw-value-badge',
					style: {
						'--drw-badge-bg': 'color-mix(in srgb, ' + t.color + ' 12%, white)',
						'--drw-badge-fg': t.color
					}
				}, promoValueLabel(p)),
				p.code
					? el('button', {
						className: 'drw-code-block',
						title: 'Copiar código',
						onClick: function () {
							if (navigator.clipboard) { navigator.clipboard.writeText(p.code).catch(function () {}); }
							showToast('Código copiado: ' + p.code);
						}
					},
						el('span', { className: 'drw-code-text' }, p.code),
						el(Icon, { name: 'clipboard', size: 13, style: { color: '#8b8b8b' } })
					)
					: el('span', { className: 'drw-promo-type-label', style: { fontStyle: 'italic' } }, 'Automática (sin código)')
			),

			el('div', { className: 'drw-promo-meta' },
				el('div', { className: 'drw-meta-item' },
					el('span', { className: 'drw-meta-label' }, 'Usos'),
					el('span', { className: 'drw-meta-value' }, fmtN(p.uses) + (p.limitGlobal ? ' / ' + fmtN(p.limitGlobal) : ''))
				),
				el('div', { className: 'drw-meta-item' },
					el('span', { className: 'drw-meta-label' }, 'Vigencia'),
					el('span', { className: 'drw-meta-value' }, p.end ? p.start.slice(5) + ' → ' + p.end.slice(5) : 'Permanente')
				),
				el('div', { className: 'drw-meta-item' },
					el('span', { className: 'drw-meta-label' }, 'Por cliente'),
					el('span', { className: 'drw-meta-value' }, p.limitUser ? p.limitUser + '×' : '∞')
				)
			),

			p.limitGlobal > 0 && el('div', { className: 'drw-meter' },
				el('div', { style: { width: pct + '%', background: pct > 85 ? '#f59e0b' : t.color } })
			),

			el('div', { className: 'drw-promo-actions' },
				el('button', { className: 'drw-btn drw-btn-ghost', style: { flex: 1 }, onClick: function () { onEdit(p); } },
					el(Icon, { name: 'edit', size: 14 }), ' Editar'
				),
				el('button', {
					className: 'drw-btn drw-btn-icon drw-btn-danger',
					'aria-label': 'Eliminar ' + p.name,
					onClick: function () { onDelete(p.id); }
				},
					el(Icon, { name: 'no-alt', size: 14 })
				)
			)
		);
	}

	// -- Promo Editor Modal ------------------------------------------------
	function PromoEditor(props) {
		var promo = props.promo;
		var onClose = props.onClose;
		var onSave = props.onSave;
		var isNew = !promo;

		var defaultPromo = {
			name: '', code: '', type: 'percent', value: 10, scope: 'Todo el carrito',
			minAmount: 0, limitGlobal: 0, limitUser: 1,
			start: new Date().toISOString().slice(0, 10), end: '',
			active: true, home: false, exclusive: false, excludeSaleItems: false, showInMinicart: false, priority: 5, cartMessage: '', giftText: '', uses: 0
		};

		var initial = promo ? Object.assign({}, promo) : defaultPromo;
		var formState = useState(initial);
		var f = formState[0];
		var setF = formState[1];

		var t = getType(f.type);

		function set(k, v) {
			setF(function (s) {
				var n = Object.assign({}, s);
				n[k] = v;
				return n;
			});
		}

		var valid = f.name.trim().length > 2 && (!t.needsCode || f.code.trim().length >= 3);

		var SCOPE_OPTIONS = [
			'Todo el carrito',
			'Productos marca propia',
			'Primera compra',
			'Clientes recurrentes'
		];

		function handleSave() {
			var payload = Object.assign({}, f, {
				code: f.code.trim().toUpperCase(),
				value: Number(f.value) || 0,
				minAmount: Number(f.minAmount) || 0,
				limitGlobal: Number(f.limitGlobal) || 0,
				limitUser: Number(f.limitUser) || 0,
				priority: Number(f.priority) || 5
			});
			onSave(payload);
		}

		return el('div', { className: 'drw-overlay', onClick: onClose },
			el('div', { className: 'drw-modal', onClick: function (e) { e.stopPropagation(); } },
				el('div', { className: 'drw-modal-header' },
					el('h3', { className: 'drw-modal-title' }, isNew ? 'Nueva promoción' : 'Editar promoción'),
					el('button', { className: 'drw-modal-close', onClick: onClose, 'aria-label': 'Cerrar' },
						el(Icon, { name: 'no-alt', size: 15 })
					)
				),

				el('div', { className: 'drw-fields' },
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
									className: 'drw-promo-type' + (isActive ? ' active' : ''),
									onClick: function () { set('type', tt.id); },
									style: btnStyle
								},
									el('span', {
										className: 'drw-type-icon',
										style: {
											background: 'color-mix(in srgb, ' + tt.color + ' 16%, white)',
											color: tt.color
										}
									}, el(Icon, { name: tt.icon, size: 15 })),
									el('span', { className: 'drw-type-name' }, tt.label)
								);
							})
						)
					),

					el('div', { className: 'drw-fields-row', style: { gridTemplateColumns: t.needsCode ? '1.4fr 1fr' : '1fr' } },
						el('div', { className: 'drw-field' },
							el('label', null, 'Nombre interno'),
							el('input', { value: f.name, onChange: function (e) { set('name', e.target.value); }, placeholder: 'Ej. Madrugón marca propia' })
						),
						t.needsCode && el('div', { className: 'drw-field' },
							el('label', null, 'Código'),
							el('input', { className: 'drw-text-mono', value: f.code, onChange: function (e) { set('code', e.target.value.toUpperCase()); }, placeholder: 'MADRUGON15' })
						)
					),

					el('div', { className: 'drw-fields-row' },
						t.valueType === 'percent' && el('div', { className: 'drw-field' },
							el('label', null, 'Porcentaje de descuento'),
							el('div', { style: { display: 'flex', alignItems: 'center', gap: 8 } },
								el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); }, style: { flex: 1 } }),
								el('span', { className: 'drw-field-hint' }, '%')
							)
						),
						t.valueType === 'currency' && el('div', { className: 'drw-field' },
							el('label', null,
								f.type === 'bundle' ? 'Precio del combo (COP)' :
								f.type === 'launch' ? 'Precio de lanzamiento (COP)' :
								'Monto de descuento (COP)'
							),
							el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); } })
						),
						t.valueType === 'text' && el('div', { className: 'drw-field' },
							el('label', null, 'Regalo incluido'),
							el('input', { value: f.giftText || '', onChange: function (e) { set('giftText', e.target.value); }, placeholder: 'Ej. Bolsa reutilizable' })
						),
						el('div', { className: 'drw-field' },
							el('label', null, 'Aplica a'),
							// If this promo was created in the wizard, `scope` is a
							// { target, ids } object; keep it intact in state (so a
							// save round-trips it untouched) and only show a plain
							// read-out here. Legacy string scopes use the select.
							(f.scope && typeof f.scope === 'object')
								? el('div', { className: 'drw-field-hint', style: { padding: '10px 0' } },
									scopeSummary(f.scope) + ' — usa el asistente para cambiar el alcance.')
								: el('select', { value: f.scope, onChange: function (e) { set('scope', e.target.value); } },
									SCOPE_OPTIONS.map(function (s) { return el('option', { key: s, value: s }, s); })
								)
						)
					),

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
							el('input', { type: 'date', value: f.end, onChange: function (e) { set('end', e.target.value); } }),
							el('span', { className: 'drw-field-hint' }, 'vacío = permanente')
						)
					),

					el('div', { className: 'drw-field' },
						el('label', null, 'Mensaje en el carrito'),
						el('input', { value: f.cartMessage, onChange: function (e) { set('cartMessage', e.target.value); }, placeholder: 'Ej. ¡Descuento aplicado!' })
					),

					el('div', { style: { display: 'flex', gap: 18, padding: '4px 2px', flexWrap: 'wrap' } },
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.active ? ' on' : ''), onClick: function () { set('active', !f.active); } }),
							' Activa'
						),
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.home ? ' on' : ''), onClick: function () { set('home', !f.home); } }),
							' Mostrar en portada'
						),
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.exclusive ? ' on' : ''), onClick: function () { set('exclusive', !f.exclusive); } }),
							' Exclusiva (no combinable con otras promociones)'
						),
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.excludeSaleItems ? ' on' : ''), onClick: function () { set('excludeSaleItems', !f.excludeSaleItems); } }),
							' No aplica a productos en oferta'
						),
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.showInMinicart ? ' on' : ''), onClick: function () { set('showInMinicart', !f.showInMinicart); } }),
							' Mostrar en el mini-carrito'
						)
					),

					el('div', { className: 'drw-modal-footer' },
						el('button', { className: 'drw-btn drw-btn-ghost', onClick: onClose }, 'Cancelar'),
						el('button', {
							className: 'drw-btn drw-btn-primary',
							disabled: !valid,
							style: { opacity: valid ? 1 : 0.5 },
							onClick: handleSave
						}, isNew ? 'Crear promoción' : 'Guardar cambios')
					)
				)
			)
		);
	}

	// -- Main Promos Page --------------------------------------------------
	function PromosPage(props) {
		var onBack = props.onBack;
		var promosState = useState([]);
		var promos = promosState[0];
		var setPromos = promosState[1];
		var editingState = useState(null);
		var editing = editingState[0];
		var setEditing = editingState[1];
		// 'wizard' (default 4-step flow) | 'expert' (classic single-step editor).
		var editorModeState = useState('wizard');
		var editorMode = editorModeState[0];
		var setEditorMode = editorModeState[1];
		var filterState = useState('Todas');
		var filter = filterState[0];
		var setFilter = filterState[1];
		var loadingState = useState(true);
		var loading = loadingState[0];
		var setLoading = loadingState[1];

		// One-time legacy migration banner: null while unknown/not-applicable
		// (nothing rendered), otherwise { legacyCount, migratedCount,
		// backupExists, needsMigration }. See PromoMigrationController.
		var legacyState = useState(null);
		var legacy = legacyState[0];
		var setLegacy = legacyState[1];
		var migratingState = useState(false);
		var migrating = migratingState[0];
		var setMigrating = migratingState[1];

		function fetchPromos() {
			apiFetch({ path: '/drw/v1/promos' })
				.then(function (data) {
					setPromos(Array.isArray(data) ? data : []);
					setLoading(false);
				})
				.catch(function () {
					setPromos([]);
					setLoading(false);
				});
		}

		function fetchLegacyStatus() {
			apiFetch({ path: '/drw/v1/promos/legacy-migration' })
				.then(function (data) { setLegacy(data); })
				.catch(function () { setLegacy(null); });
		}

		useEffect(function () { fetchPromos(); fetchLegacyStatus(); }, []);

		function runLegacyMigration() {
			if (migrating) { return; }
			setMigrating(true);
			apiFetch({ path: '/drw/v1/promos/legacy-migration', method: 'POST' })
				.then(function (result) {
					var rejected = (result && result.rejected) || [];
					if (result.status === 'ok') {
						showToast('Migradas ' + result.migrated + ' promociones antiguas.');
					} else if (result.status === 'incomplete') {
						if (rejected.length > 0) {
							// These entries failed the same validation gate the
							// editor uses (bad dates, duplicate code, percentage
							// > 100, …) and are NEVER inserted, so the migration
							// can never reach `expected` — retrying will not
							// recover them. Say so, and surface the first reason,
							// instead of implying a retry would finish the job.
							var n = rejected.length;
							var reason = (rejected[0] && rejected[0].reason) ? ' Motivo: ' + rejected[0].reason : '';
							showToast(
								'Se migraron ' + result.migrated + ' de ' + result.expected + '. ' +
								(n === 1
									? '1 promoción antigua no se pudo migrar por datos inválidos y no se recuperará al reintentar.'
									: n + ' promociones antiguas no se pudieron migrar por datos inválidos y no se recuperarán al reintentar.') +
								reason
							);
						} else {
							showToast('Se migraron ' + result.migrated + ' de ' + result.expected + '. Puedes reintentar sin duplicar nada.');
						}
					} else {
						showToast('No había promociones antiguas por migrar.');
					}
					fetchLegacyStatus();
					fetchPromos();
				})
				.catch(function () { showToast('Error al migrar. El respaldo original no se toca; puedes reintentar cuando quieras.'); })
				.then(function () { setMigrating(false); });
		}

		function handleToggle(id) {
			apiFetch({ path: '/drw/v1/promos/' + id + '/toggle', method: 'POST' })
				.then(function () {
					setPromos(function (prev) {
						return prev.map(function (p) {
							if (p.id === id) {
								var toggled = Object.assign({}, p, { active: !p.active });
								showToast(toggled.active ? 'Promoción activada' : 'Promoción pausada');
								return toggled;
							}
							return p;
						});
					});
				})
				.catch(function (err) {
					// Activating re-validates the stored row (toggle_promo →
					// validate_promo); a rejected activation returns a specific
					// {message} explaining why (duplicate code, inverted dates,
					// percentage > 100, …). Surface it instead of a generic
					// "Error al cambiar estado" so the merchant knows what to fix.
					showToast('Error: ' + ((err && err.message) || 'No se pudo cambiar el estado'));
				});
		}

		function handleDelete(id) {
			if (!window.confirm('¿Eliminar esta promoción?')) { return; }
			apiFetch({ path: '/drw/v1/promos/' + id, method: 'DELETE' })
				.then(function () {
					setPromos(function (prev) { return prev.filter(function (p) { return p.id !== id; }); });
					showToast('Promoción eliminada');
				})
				.catch(function () { showToast('Error al eliminar'); });
		}

		function closeEditor() {
			setEditing(null);
			setEditorMode('wizard');
		}

		// Returns the apiFetch promise so the wizard can track its own saving
		// state. On success the editor is closed (unmounted); on error the
		// promise still resolves (the .catch handles it) so callers that ignore
		// the return value — e.g. the classic PromoEditor — never produce an
		// unhandled rejection. The wizard stays open on error because
		// closeEditor() only runs in the success branch.
		function handleSave(payload) {
			var isNew = !payload.id;
			var method = isNew ? 'POST' : 'PUT';
			var path = isNew ? '/drw/v1/promos' : '/drw/v1/promos/' + payload.id;

			return apiFetch({ path: path, method: method, data: payload })
				.then(function () {
					fetchPromos();
					closeEditor();
					showToast(isNew ? 'Promoción creada' : 'Promoción actualizada');
				})
				.catch(function (err) {
					showToast('Error: ' + (err.message || 'No se pudo guardar'));
				});
		}

		var active = promos.filter(function (p) { return p.active; }).length;
		var totalUses = promos.reduce(function (a, p) { return a + (p.uses || 0); }, 0);
		var onHome = promos.filter(function (p) { return p.home && p.active; }).length;

		var filtered = promos.filter(function (p) {
			if (filter === 'Todas') { return true; }
			if (filter === 'Activas') { return p.active; }
			if (filter === 'Pausadas') { return !p.active; }
			if (filter === 'En portada') { return p.home; }
			if (filter === 'Con código') { return !!p.code; }
			if (filter === 'Automáticas') { return !p.code; }
			return true;
		});

		var FILTERS = ['Todas', 'Activas', 'Pausadas', 'En portada', 'Con código', 'Automáticas'];

		if (loading) {
			return el('div', { id: 'drw-promos-app', style: { textAlign: 'center', padding: '60px 20px' } },
				el(wp.components.Spinner),
				el('p', { className: 'drw-text-muted', style: { marginTop: 12 } }, 'Cargando promociones...')
			);
		}

		return el('div', { id: 'drw-promos-app' },
			el('div', { style: { marginBottom: 10 } },
				el('button', { className: 'drw-btn drw-btn-ghost drw-btn-sm', onClick: onBack },
					'← Volver a Reglas'
				)
			),

			legacy && legacy.needsMigration && el('div', {
				className: 'drw-legacy-migration-banner',
				style: {
					display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap',
					padding: '12px 16px', marginBottom: 16,
					background: '#fff8e6', border: '1px solid #f0d585', borderRadius: 8
				}
			},
				el('div', null,
					el('div', { style: { fontWeight: 600, fontSize: 13 } },
						'Detectamos ' + (legacy.legacyCount - legacy.migratedCount) + ' promoción(es) del sistema anterior sin migrar.'
					),
					el('div', { className: 'drw-field-hint' },
						'Se copian a la tabla nueva sin borrar ni modificar el respaldo original. Es seguro repetir esto las veces que haga falta.'
					)
				),
				el('button', {
					type: 'button',
					className: 'drw-btn drw-btn-primary drw-btn-sm',
					disabled: migrating,
					onClick: runLegacyMigration
				}, migrating ? 'Migrando…' : 'Migrar ahora')
			),

			el('div', { className: 'drw-kpi-row' },
				el('div', { className: 'drw-kpi-card' },
					el('div', { className: 'drw-kpi-label' }, 'Promociones activas'),
					el('div', { className: 'drw-kpi-value' }, String(active)),
					el('div', { className: 'drw-kpi-sub' }, 'de ' + promos.length + ' configuradas')
				),
				el('div', { className: 'drw-kpi-card' },
					el('div', { className: 'drw-kpi-label' }, 'Canjes totales'),
					el('div', { className: 'drw-kpi-value' }, fmtN(totalUses)),
					el('div', { className: 'drw-kpi-sub' }, 'en todos los cupones')
				),
				el('div', { className: 'drw-kpi-card' },
					el('div', { className: 'drw-kpi-label' }, 'Visibles en portada'),
					el('div', { className: 'drw-kpi-value' }, String(onHome)),
					el('div', { className: 'drw-kpi-sub' }, 'banners y destacados')
				),
				el('div', { className: 'drw-kpi-card', style: { display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'flex-start', gap: 8 } },
					el('div', { className: 'drw-kpi-label' }, 'Crear nueva'),
					el('button', { className: 'drw-btn drw-btn-primary', onClick: function () { setEditing('new'); } },
						el(Icon, { name: 'plus', size: 15 }), ' Nueva promoción'
					)
				)
			),

			el('div', { className: 'drw-chips' },
				FILTERS.map(function (f) {
					return el('button', {
						key: f,
						className: 'drw-chip' + (filter === f ? ' active' : ''),
						onClick: function () { setFilter(f); }
					}, f);
				})
			),

			el('div', { className: 'drw-promo-grid' },
				filtered.map(function (p) {
					return el(PromoCard, {
						key: p.id,
						promo: p,
						onEdit: function (pr) { setEditing(pr); },
						onToggle: handleToggle,
						onDelete: handleDelete
					});
				})
			),

			filtered.length === 0 && el('div', { className: 'drw-empty' },
				el('div', null,
					el('div', { className: 'drw-empty-icon' },
						el(Icon, { name: 'tag', size: 22 })
					),
					el('h3', { className: 'drw-empty-title' }, 'Sin promociones'),
					el('p', { className: 'drw-empty-text' }, 'No hay promociones en este filtro. Crea una nueva con el botón de arriba.')
				)
			),

			editing && (
				(editorMode !== 'expert' && typeof window.DrwPromoWizard === 'function')
					? el(window.DrwPromoWizard, {
						promo: editing === 'new' ? null : editing,
						onClose: closeEditor,
						onSave: handleSave,
						// "Modo experto": switch to the classic single-step editor.
						onExpertMode: function () { setEditorMode('expert'); }
					})
					: el(PromoEditor, {
						promo: editing === 'new' ? null : editing,
						onClose: closeEditor,
						onSave: handleSave
					})
			)
		);
	}

	window.DrwPromosPage = PromosPage;

})();
