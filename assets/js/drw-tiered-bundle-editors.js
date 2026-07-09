/**
 * OmniDiscount — Reusable editors for advanced promo/rule types.
 * Same conventions as admin-promos.js: plain wp.element (no JSX/build step),
 * ES5-style closures, one IIFE per file, components exposed on `window`.
 *
 * Ships three self-contained, framework-light components meant to be dropped
 * into the promo/rule editors (see PromoTypeRegistry ids "tiered", "bogo"
 * and "bundle"):
 *
 *   - window.DrwTieredTableEditor({ tiers, onChange })
 *   - window.DrwBogoBuyGetPicker({ value, onChange })
 *   - window.DrwBundleBuilder({ items, price, onChange })
 *
 * None of them fetch data on their own — they are controlled components,
 * exactly like the fields inside admin-promos.js's PromoEditor. Product /
 * category selection is delegated to `window.DrwProductCategoryPicker` when
 * present on the page; otherwise a minimal comma-separated ID input is used
 * so the editors keep working (in a degraded but honest way) before that
 * picker ships.
 */
(function () {
	'use strict';

	var el = wp.element.createElement;

	// -- Generic helpers ---------------------------------------------------

	function toInt(v, fallback) {
		var n = parseInt(v, 10);
		return isNaN(n) ? (fallback || 0) : n;
	}

	function toNum(v, fallback) {
		var n = parseFloat(v);
		return isNaN(n) ? (fallback || 0) : n;
	}

	function clamp(v, min, max) {
		return Math.min(max, Math.max(min, v));
	}

	/** De-duplicated array of positive integer IDs. */
	function normalizeIds(list) {
		if (!Array.isArray(list)) { return []; }
		var out = [];
		for (var i = 0; i < list.length; i++) {
			var id = toInt(list[i], 0);
			if (id > 0 && out.indexOf(id) === -1) { out.push(id); }
		}
		return out;
	}

	/** "12, 45, 103" -> [12, 45, 103] */
	function parseIdList(text) {
		return normalizeIds(String(text || '').split(','));
	}

	function fmtMoney(v) {
		var n = Number(v);
		if (!isFinite(n)) { n = 0; }
		return '$' + n.toLocaleString('es-CO', { maximumFractionDigits: 2 });
	}

	// =======================================================================
	// 1) TieredTableEditor — editable quantity/discount tiers with live preview
	// =======================================================================

	function suggestNextTier(tiers) {
		if (!tiers.length) {
			return { minQty: 2, discountPercent: 5 };
		}
		var last = tiers[tiers.length - 1];
		return {
			minQty: toInt(last.minQty, 0) + 1,
			discountPercent: toInt(last.discountPercent, 0)
		};
	}

	/**
	 * Editable table of volume tiers: [{ minQty, discountPercent }, ...]
	 * with a live "Compra N+ y ahorra X%" preview underneath.
	 */
	function TieredTableEditor(props) {
		var tiers = Array.isArray(props.tiers) ? props.tiers : [];
		var onChange = props.onChange || function () {};

		function updateRow(idx, key, value) {
			var next = tiers.slice();
			next[idx] = Object.assign({}, next[idx]);
			next[idx][key] = value;
			onChange(next);
		}

		function addRow() {
			onChange(tiers.concat([suggestNextTier(tiers)]));
		}

		function removeRow(idx) {
			var next = tiers.slice();
			next.splice(idx, 1);
			onChange(next);
		}

		// Sorted copy for the preview only — editing order in the table is
		// left untouched so typing doesn't reshuffle rows under the cursor.
		var previewTiers = tiers
			.filter(function (t) { return toInt(t.minQty, 0) > 0; })
			.slice()
			.sort(function (a, b) { return toInt(a.minQty, 0) - toInt(b.minQty, 0); });

		return el('div', { className: 'drw-tiered-table-editor' },
			el('label', { className: 'drw-section-label' }, 'Tramos por cantidad'),

			tiers.length === 0
				? el('p', { className: 'drw-field-hint' }, 'Aún no hay tramos. Agrega el primero para empezar.')
				: el('div', { style: { overflowX: 'auto' } },
					el('table', { className: 'drw-tier-table', style: { width: '100%', borderCollapse: 'collapse' } },
						el('thead', null,
							el('tr', null,
								el('th', { style: thStyle }, 'Cantidad mínima'),
								el('th', { style: thStyle }, 'Descuento'),
								el('th', { style: Object.assign({}, thStyle, { width: 40 }) }, '')
							)
						),
						el('tbody', null,
							tiers.map(function (tier, idx) {
								return el('tr', { key: idx },
									el('td', { style: tdStyle },
										el('input', {
											type: 'number',
											min: 1,
											step: 1,
											value: tier.minQty,
											style: cellInputStyle,
											onChange: function (e) { updateRow(idx, 'minQty', toInt(e.target.value, 0)); }
										})
									),
									el('td', { style: tdStyle },
										el('div', { style: { display: 'flex', alignItems: 'center', gap: 6 } },
											el('input', {
												type: 'number',
												min: 0,
												max: 100,
												step: 0.1,
												value: tier.discountPercent,
												style: cellInputStyle,
												onChange: function (e) { updateRow(idx, 'discountPercent', clamp(toNum(e.target.value, 0), 0, 100)); }
											}),
											el('span', { className: 'drw-field-hint' }, '%')
										)
									),
									el('td', { style: tdStyle },
										el('button', {
											type: 'button',
											className: 'drw-btn drw-btn-icon drw-btn-danger drw-btn-sm',
											'aria-label': 'Quitar tramo',
											onClick: function () { removeRow(idx); }
										}, '×')
									)
								);
							})
						)
					)
				),

			el('button', {
				type: 'button',
				className: 'drw-btn drw-btn-ghost drw-btn-sm',
				style: { marginTop: 8 },
				onClick: addRow
			}, '+ Agregar tramo'),

			el('div', { className: 'drw-tiered-preview', style: previewBoxStyle },
				el('div', { className: 'drw-field-hint', style: { marginBottom: previewTiers.length ? 6 : 0, textTransform: 'uppercase', letterSpacing: '0.05em', fontWeight: 700 } }, 'Vista previa'),
				previewTiers.length === 0
					? el('span', { className: 'drw-field-hint' }, 'Agrega tramos válidos (cantidad mínima > 0) para ver la vista previa.')
					: el('ul', { style: { margin: 0, padding: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 4 } },
						previewTiers.map(function (t, i) {
							return el('li', { key: i, style: { fontSize: 13 } },
								'Compra ' + toInt(t.minQty, 0) + '+ y ahorra ' + toNum(t.discountPercent, 0) + '%'
							);
						})
					)
			)
		);
	}

	var thStyle = { textAlign: 'left', padding: '8px 10px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--drw-muted, #767676)', borderBottom: '1px solid var(--drw-line, #e2e2e2)' };
	var tdStyle = { padding: '6px 10px', borderBottom: '1px solid var(--drw-line, #ececec)' };
	var cellInputStyle = { width: 90 };
	var previewBoxStyle = { marginTop: 14, padding: '10px 12px', border: '1px dashed var(--drw-line, #d8d8d8)', borderRadius: 10, background: 'var(--drw-paper-2, #fafafa)' };

	// =======================================================================
	// Shared product/category picker field, used by BogoBuyGetPicker and
	// BundleBuilder. Delegates to window.DrwProductCategoryPicker when it is
	// registered on the page; falls back to a plain comma-separated product
	// ID input otherwise so these editors never hard-fail.
	// =======================================================================

	/**
	 * Reconciles whatever shape window.DrwProductCategoryPicker's onChange
	 * hands back into { productIds, categoryIds }. Accepts a plain array
	 * (assumed to be product IDs) or an object using any of the common key
	 * spellings, so this stays compatible even if that picker's contract
	 * shifts slightly once it ships.
	 */
	function normalizePickerValue(next, prev) {
		if (Array.isArray(next)) {
			return { productIds: normalizeIds(next), categoryIds: (prev && prev.categoryIds) || [] };
		}
		if (next && typeof next === 'object') {
			var productIds = next.productIds || next.selectedProductIds || next.product_ids || (prev && prev.productIds) || [];
			var categoryIds = next.categoryIds || next.selectedCategoryIds || next.category_ids || (prev && prev.categoryIds) || [];
			return { productIds: normalizeIds(productIds), categoryIds: normalizeIds(categoryIds) };
		}
		return { productIds: (prev && prev.productIds) || [], categoryIds: (prev && prev.categoryIds) || [] };
	}

	function PickerField(props) {
		var label = props.label;
		var help = props.help;
		var value = props.value || { productIds: [], categoryIds: [] };
		var onChange = props.onChange || function () {};

		if (typeof window.DrwProductCategoryPicker === 'function') {
			return el('div', { className: 'drw-field' },
				label && el('label', null, label),
				el(window.DrwProductCategoryPicker, {
					label: label,
					productIds: value.productIds || [],
					categoryIds: value.categoryIds || [],
					// Aliases kept for forward-compatibility with whichever
					// prop names the picker ships with.
					selectedProductIds: value.productIds || [],
					selectedCategoryIds: value.categoryIds || [],
					onChange: function (next) { onChange(normalizePickerValue(next, value)); }
				}),
				help && el('p', { className: 'drw-field-hint' }, help)
			);
		}

		// Fallback: the dedicated picker isn't loaded on this screen yet.
		var idsText = (value.productIds || []).join(', ');
		return el('div', { className: 'drw-field' },
			label && el('label', null, label),
			el('input', {
				type: 'text',
				value: idsText,
				placeholder: 'IDs de producto separados por coma. Ej: 12, 45, 103',
				onChange: function (e) { onChange({ productIds: parseIdList(e.target.value), categoryIds: value.categoryIds || [] }); }
			}),
			el('p', { className: 'drw-field-hint' },
				(help ? help + ' ' : '') + 'Selector de productos avanzado no disponible: escribe los IDs manualmente.'
			)
		);
	}

	// =======================================================================
	// 2) BogoBuyGetPicker — "Compra X" / "Lleva Y" configuration
	// =======================================================================

	var DEFAULT_BOGO_VALUE = {
		buyQty: 2, buyProductIds: [], buyCategoryIds: [],
		getQty: 1, getProductIds: [], getCategoryIds: []
	};

	function BogoBuyGetPicker(props) {
		var value = Object.assign({}, DEFAULT_BOGO_VALUE, props.value || {});
		var onChange = props.onChange || function () {};

		function patch(partial) {
			onChange(Object.assign({}, value, partial));
		}

		var buyValue = { productIds: normalizeIds(value.buyProductIds), categoryIds: normalizeIds(value.buyCategoryIds) };
		var getValue = { productIds: normalizeIds(value.getProductIds), categoryIds: normalizeIds(value.getCategoryIds) };

		var buyScope = buyValue.productIds.length || buyValue.categoryIds.length
			? (buyValue.productIds.length + buyValue.categoryIds.length) + ' seleccionados'
			: 'cualquier producto';
		var getScope = getValue.productIds.length || getValue.categoryIds.length
			? (getValue.productIds.length + getValue.categoryIds.length) + ' seleccionados'
			: 'el mismo producto de la compra';

		return el('div', { className: 'drw-bogo-buy-get-picker' },
			el('div', { className: 'drw-fields-row', style: { gridTemplateColumns: '1fr 1fr' } },
				el('div', { className: 'drw-field' },
					el('label', null, 'Compra (cantidad)'),
					el('input', {
						type: 'number', min: 1, step: 1, value: value.buyQty,
						onChange: function (e) { patch({ buyQty: Math.max(1, toInt(e.target.value, 1)) }); }
					})
				),
				el('div', { className: 'drw-field' },
					el('label', null, 'Lleva (cantidad)'),
					el('input', {
						type: 'number', min: 1, step: 1, value: value.getQty,
						onChange: function (e) { patch({ getQty: Math.max(1, toInt(e.target.value, 1)) }); }
					})
				)
			),

			el('div', { className: 'drw-fields-row', style: { gridTemplateColumns: '1fr 1fr' } },
				el(PickerField, {
					label: 'Productos que activan "Compra"',
					value: buyValue,
					help: 'Deja vacío para aplicar a cualquier producto.',
					onChange: function (next) { patch({ buyProductIds: next.productIds, buyCategoryIds: next.categoryIds }); }
				}),
				el(PickerField, {
					label: 'Productos que se "Llevan" con descuento',
					value: getValue,
					help: 'Deja vacío para usar el mismo producto de la compra.',
					onChange: function (next) { patch({ getProductIds: next.productIds, getCategoryIds: next.categoryIds }); }
				})
			),

			el('div', { className: 'drw-bogo-summary', style: previewBoxStyle },
				el('div', { className: 'drw-field-hint', style: { marginBottom: 4, textTransform: 'uppercase', letterSpacing: '0.05em', fontWeight: 700 } }, 'Vista previa'),
				el('span', { style: { fontSize: 13 } },
					'Compra ' + toInt(value.buyQty, 1) + ' (' + buyScope + ') y lleva ' + toInt(value.getQty, 1) + ' más (' + getScope + ') con descuento.'
				)
			)
		);
	}

	// =======================================================================
	// 3) BundleBuilder — combo product list + set price + savings estimate
	// =======================================================================

	/**
	 * Best-effort unit price lookup. Never invents a number: returns null
	 * (meaning "unknown") unless a real price is found on the item itself
	 * or in the localized product catalogue (window.drwAdminData.products).
	 */
	function resolveUnitPrice(item) {
		var candidates = [item.regularPrice, item.regular_price, item.price];
		for (var i = 0; i < candidates.length; i++) {
			var n = Number(candidates[i]);
			if (candidates[i] !== undefined && candidates[i] !== null && candidates[i] !== '' && isFinite(n) && n > 0) {
				return n;
			}
		}

		var catalogue = (window.drwAdminData && Array.isArray(window.drwAdminData.products)) ? window.drwAdminData.products : [];
		for (var j = 0; j < catalogue.length; j++) {
			if (toInt(catalogue[j].id, -1) === toInt(item.productId, -2)) {
				return resolveUnitPrice({ regularPrice: catalogue[j].regular_price, price: catalogue[j].price });
			}
		}

		return null;
	}

	/**
	 * Returns { separateTotal, amount } or null when there isn't enough
	 * real price data to make an honest comparison (per-spec: never guess).
	 */
	function computeBundleSavings(items, price) {
		var priceNum = Number(price);
		if (!items.length || !isFinite(priceNum) || priceNum <= 0) { return null; }

		var separateTotal = 0;
		for (var i = 0; i < items.length; i++) {
			var unitPrice = resolveUnitPrice(items[i]);
			if (unitPrice === null) { return null; }
			var qty = Math.max(1, toInt(items[i].qty, 1));
			separateTotal += unitPrice * qty;
		}

		return { separateTotal: separateTotal, amount: separateTotal - priceNum };
	}

	function BundleBuilder(props) {
		var items = Array.isArray(props.items) ? props.items : [];
		var price = props.price;
		var onChange = props.onChange || function () {};

		function emit(nextItems, nextPrice) {
			onChange({ items: nextItems, price: nextPrice });
		}

		var productIds = normalizeIds(items.map(function (it) { return it.productId; }));

		function handlePickerChange(next) {
			var nextIds = normalizeIds(next.productIds);
			var byId = {};
			items.forEach(function (it) { byId[toInt(it.productId, 0)] = it; });
			var nextItems = nextIds.map(function (id) {
				return byId[id] || { productId: id, qty: 1 };
			});
			emit(nextItems, price);
		}

		function updateItem(idx, patch) {
			var nextItems = items.slice();
			nextItems[idx] = Object.assign({}, nextItems[idx], patch);
			emit(nextItems, price);
		}

		function removeItem(idx) {
			var nextItems = items.slice();
			nextItems.splice(idx, 1);
			emit(nextItems, price);
		}

		function handlePriceChange(e) {
			var raw = e.target.value;
			emit(items, raw === '' ? '' : toNum(raw, 0));
		}

		var savings = computeBundleSavings(items, price);

		return el('div', { className: 'drw-bundle-builder' },
			el('div', { className: 'drw-field' },
				el('label', null, 'Productos del combo'),
				el(PickerField, {
					value: { productIds: productIds, categoryIds: [] },
					help: 'Agrega los productos que forman parte de este combo.',
					onChange: handlePickerChange
				})
			),

			items.length === 0
				? el('p', { className: 'drw-field-hint' }, 'Aún no has agregado productos a este combo.')
				: el('div', { style: { overflowX: 'auto', marginTop: 10 } },
					el('table', { className: 'drw-tier-table', style: { width: '100%', borderCollapse: 'collapse' } },
						el('thead', null,
							el('tr', null,
								el('th', { style: thStyle }, 'Producto'),
								el('th', { style: thStyle }, 'Cantidad'),
								el('th', { style: thStyle }, 'Precio unitario'),
								el('th', { style: Object.assign({}, thStyle, { width: 40 }) }, '')
							)
						),
						el('tbody', null,
							items.map(function (item, idx) {
								var unitPrice = resolveUnitPrice(item);
								return el('tr', { key: idx },
									el('td', { style: tdStyle }, item.name || ('Producto #' + toInt(item.productId, 0))),
									el('td', { style: tdStyle },
										el('input', {
											type: 'number', min: 1, step: 1, value: item.qty,
											style: cellInputStyle,
											onChange: function (e) { updateItem(idx, { qty: Math.max(1, toInt(e.target.value, 1)) }); }
										})
									),
									el('td', { style: tdStyle }, unitPrice === null ? '—' : fmtMoney(unitPrice)),
									el('td', { style: tdStyle },
										el('button', {
											type: 'button',
											className: 'drw-btn drw-btn-icon drw-btn-danger drw-btn-sm',
											'aria-label': 'Quitar producto del combo',
											onClick: function () { removeItem(idx); }
										}, '×')
									)
								);
							})
						)
					)
				),

			el('div', { className: 'drw-field', style: { maxWidth: 220, marginTop: 14 } },
				el('label', null, 'Precio del combo'),
				el('input', {
					type: 'number', min: 0, step: 0.01,
					value: (price === undefined || price === null) ? '' : price,
					onChange: handlePriceChange
				})
			),

			savings && el('div', { className: 'drw-bundle-savings', style: previewBoxStyle },
				el('div', { className: 'drw-field-hint', style: { marginBottom: 4, textTransform: 'uppercase', letterSpacing: '0.05em', fontWeight: 700 } }, 'Vista previa'),
				el('span', { style: { fontSize: 13 } },
					savings.amount > 0
						? ('Ahorras ' + fmtMoney(savings.amount) + ' vs comprar por separado (' + fmtMoney(savings.separateTotal) + ').')
						: ('El precio del combo no representa un ahorro frente a comprar por separado (' + fmtMoney(savings.separateTotal) + ').')
				)
			)
		);
	}

	window.DrwTieredTableEditor = TieredTableEditor;
	window.DrwBogoBuyGetPicker = BogoBuyGetPicker;
	window.DrwBundleBuilder = BundleBuilder;

})();
