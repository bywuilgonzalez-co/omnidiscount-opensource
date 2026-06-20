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

	var PROMO_TYPES = [
		{ id: 'percent', label: 'Descuento porcentual', icon: 'tag', color: '#5b7b41', needsCode: true, value: 'percent', short: '% OFF' },
		{ id: 'fixed', label: 'Descuento fijo', icon: 'tag', color: '#3a5a2a', needsCode: true, value: 'money', short: '$ OFF' },
		{ id: 'launch', label: 'Precio de lanzamiento', icon: 'star-filled', color: '#00a000', needsCode: false, value: 'money', short: 'Lanzamiento' },
		{ id: '2x1', label: '2x1', icon: 'archive', color: '#00c0b4', needsCode: false, value: 'none', short: '2x1' },
		{ id: '3x2', label: '3x2', icon: 'archive', color: '#00c0b4', needsCode: false, value: 'none', short: '3x2' },
		{ id: 'second_unit', label: 'Segunda unidad', icon: 'archive', color: '#008cd4', needsCode: true, value: 'percent', short: '2ª und.' },
		{ id: 'tiered', label: 'Escalonado por monto', icon: 'chart-bar', color: '#1d5c9e', needsCode: false, value: 'percent', short: 'Escalonado' },
		{ id: 'bundle', label: 'Bundle / combo', icon: 'screenoptions', color: '#8a32a2', needsCode: false, value: 'money', short: 'Combo' },
		{ id: 'free_ship_threshold', label: 'Envío gratis con umbral', icon: 'car', color: '#bb8855', needsCode: false, value: 'money', short: 'Envío' },
		{ id: 'free_ship', label: 'Envío gratis', icon: 'car', color: '#bb8855', needsCode: true, value: 'none', short: 'Envío' },
		{ id: 'welcome', label: 'Cupón de bienvenida', icon: 'star-filled', color: '#d4af37', needsCode: true, value: 'percent', short: 'Bienvenida' },
		{ id: 'gift', label: 'Regalo por compra', icon: 'cart', color: '#ff1a80', needsCode: false, value: 'text', short: 'Regalo' },
		{ id: 'cashback', label: 'Puntos / cashback', icon: 'star-filled', color: '#7a3fa8', needsCode: false, value: 'percent', short: 'Cashback' },
		{ id: 'flash', label: 'Oferta flash con contador', icon: 'update', color: '#b8412a', needsCode: false, value: 'percent', short: 'Flash' },
		{ id: 'data_capture', label: 'Descuento por datos', icon: 'groups', color: '#0b7a55', needsCode: true, value: 'percent', short: 'Datos' }
	];

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
		if (t.value === 'percent') { return p.value + '% OFF'; }
		if (t.value === 'money') {
			if (p.type === 'bundle' || p.type === 'launch') { return fmtCOP(p.value); }
			return '−' + fmtCOP(p.value);
		}
		if (t.value === 'text') { return p.giftText || 'Regalo'; }
		return t.short;
	}

	function Icon(props) {
		return el('span', {
			className: 'dashicons dashicons-' + props.name,
			style: Object.assign({ fontSize: (props.size || 16) + 'px', width: (props.size || 16) + 'px', height: (props.size || 16) + 'px', lineHeight: (props.size || 16) + 'px' }, props.style || {})
		});
	}

	var toastContainer = null;

	function showToast(msg) {
		if (!toastContainer) {
			toastContainer = document.createElement('div');
			toastContainer.className = 'drw-toasts';
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
					el('div', { className: 'drw-promo-type-label' }, t.label + (p.scope ? ' · ' + p.scope : ''))
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
			active: true, home: false, priority: 5, cartMessage: '', giftText: '', uses: 0
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
						t.value === 'percent' && el('div', { className: 'drw-field' },
							el('label', null, 'Porcentaje de descuento'),
							el('div', { style: { display: 'flex', alignItems: 'center', gap: 8 } },
								el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); }, style: { flex: 1 } }),
								el('span', { className: 'drw-field-hint' }, '%')
							)
						),
						t.value === 'money' && el('div', { className: 'drw-field' },
							el('label', null,
								f.type === 'bundle' ? 'Precio del combo (COP)' :
								f.type === 'launch' ? 'Precio de lanzamiento (COP)' :
								'Monto de descuento (COP)'
							),
							el('input', { type: 'number', value: f.value, onChange: function (e) { set('value', e.target.value); } })
						),
						t.value === 'text' && el('div', { className: 'drw-field' },
							el('label', null, 'Regalo incluido'),
							el('input', { value: f.giftText || '', onChange: function (e) { set('giftText', e.target.value); }, placeholder: 'Ej. Bolsa reutilizable' })
						),
						el('div', { className: 'drw-field' },
							el('label', null, 'Aplica a'),
							el('select', { value: f.scope, onChange: function (e) { set('scope', e.target.value); } },
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
							el('button', { className: 'drw-sw' + (f.active ? ' on' : ''), onClick: function () { set('active', !f.active); } }),
							' Activa'
						),
						el('label', { className: 'drw-toggle-label' },
							el('button', { className: 'drw-sw' + (f.home ? ' on' : ''), onClick: function () { set('home', !f.home); } }),
							' Mostrar en portada'
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
		var filterState = useState('Todas');
		var filter = filterState[0];
		var setFilter = filterState[1];
		var loadingState = useState(true);
		var loading = loadingState[0];
		var setLoading = loadingState[1];

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

		useEffect(function () { fetchPromos(); }, []);

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
				.catch(function () { showToast('Error al cambiar estado'); });
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

		function handleSave(payload) {
			var isNew = !payload.id;
			var method = isNew ? 'POST' : 'PUT';
			var path = isNew ? '/drw/v1/promos' : '/drw/v1/promos/' + payload.id;

			apiFetch({ path: path, method: method, data: payload })
				.then(function () {
					fetchPromos();
					setEditing(null);
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

			editing && el(PromoEditor, {
				promo: editing === 'new' ? null : editing,
				onClose: function () { setEditing(null); },
				onSave: handleSave
			})
		);
	}

	window.DrwPromosPage = PromosPage;

})();
