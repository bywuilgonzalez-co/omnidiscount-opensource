/**
 * OmniDiscount — Template Gallery (Paso 0 del wizard de promociones)
 *
 * Galería de plantillas agrupada por objetivo de negocio (no por mecánica
 * técnica), tal como describe "Paso 0 — Galería de plantillas por objetivo"
 * en docs/superpowers/plans/planmaestrocuponespromociones.md.
 *
 * Uses WordPress core React (wp-element). No build step: var, not const/let.
 *
 * Asume que window.DrwMaterialIcon (drw-material-icon.js) ya está cargado y
 * expone un componente con la firma { name, size, color, className, style },
 * donde `name` es el nombre de la ligature de Material Symbols Rounded
 * (ver la tabla de iconografía en el plan maestro). Si no está disponible
 * todavía, se degrada a un placeholder vacío en vez de romper la pantalla.
 */
(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;

	// -- Icon helper --------------------------------------------------------
	function MaterialIcon(props) {
		var Comp = window.DrwMaterialIcon;
		if (typeof Comp === 'function') {
			return el(Comp, props);
		}
		// Graceful fallback so the gallery never breaks if the icon
		// component has not loaded yet.
		return el('span', {
			className: props.className || '',
			'aria-hidden': 'true',
			style: Object.assign({
				display: 'inline-block',
				width: (props.size || 20) + 'px',
				height: (props.size || 20) + 'px'
			}, props.style || {})
		});
	}

	// Render an icon by name for the given icon set. The promo catalogue uses
	// Material Symbols; the generic gallery (e.g. the Reglas template gallery)
	// uses WordPress dashicons, which inherit their color from the icon tile.
	function renderIcon(iconSet, name, size, extraClass) {
		if (iconSet === 'dashicon') {
			return el('span', {
				className: 'dashicons dashicons-' + name + (extraClass ? ' ' + extraClass : ''),
				'aria-hidden': 'true',
				style: { fontSize: size + 'px', width: size + 'px', height: size + 'px', lineHeight: size + 'px' }
			});
		}
		return el(MaterialIcon, { name: name, size: size, className: extraClass });
	}

	// -- Date helpers ---------------------------------------------------------
	function todayISO() {
		return new Date().toISOString().slice(0, 10);
	}

	function addDaysISO(days) {
		var d = new Date();
		d.setDate(d.getDate() + days);
		return d.toISOString().slice(0, 10);
	}

	function cloneTemplate(t) {
		try {
			return JSON.parse(JSON.stringify(t));
		} catch (e) {
			return Object.assign({}, t);
		}
	}

	// -- Single source of truth for needsCode: Drw\App\Models\PromoTypeRegistry
	// (PHP), preloaded via wp_localize_script. Falls back to TYPE_META below
	// when drwAdminData isn't present (e.g. isolated preview).
	var REGISTRY_TYPES = (window.drwAdminData && window.drwAdminData.promoTypes) || [];

	function needsCodeDefault(typeId) {
		for (var i = 0; i < REGISTRY_TYPES.length; i++) {
			if (REGISTRY_TYPES[i].id === typeId) {
				return !!REGISTRY_TYPES[i].needsCode;
			}
		}
		var meta = TYPE_META[typeId];
		return meta ? !!meta.needsCode : false;
	}

	// -- Material Symbol + brand color per promo type --------------------------
	// Icon names follow the catalogue in planmaestrocuponespromociones.md
	// ("Sistema de iconografía Material Symbols"); colors match
	// PromoTypeRegistry.php so the whole admin stays visually consistent.
	var TYPE_META = {
		percent:              { icon: 'percent',          color: '#5b7b41', label: 'Descuento porcentual',    needsCode: true },
		fixed:                { icon: 'attach_money',      color: '#3a5a2a', label: 'Descuento fijo',          needsCode: true },
		launch:               { icon: 'rocket_launch',     color: '#00a000', label: 'Precio de lanzamiento',   needsCode: false },
		flash:                { icon: 'bolt',              color: '#b8412a', label: 'Oferta flash',            needsCode: false },
		'2x1':                { icon: 'counter_2',         color: '#00c0b4', label: '2x1',                     needsCode: false },
		'3x2':                { icon: 'counter_3',         color: '#00c0b4', label: '3x2',                     needsCode: false },
		second_unit:          { icon: 'stacks',            color: '#008cd4', label: 'Segunda unidad',          needsCode: false },
		tiered:               { icon: 'stairs',            color: '#1d5c9e', label: 'Escalonado por cantidad', needsCode: false },
		bundle:               { icon: 'package_2',         color: '#8a32a2', label: 'Bundle / combo',          needsCode: false },
		free_ship_threshold:  { icon: 'sports_score',      color: '#bb8855', label: 'Envío gratis con umbral', needsCode: false },
		free_ship:            { icon: 'local_shipping',    color: '#bb8855', label: 'Envío gratis con cupón',  needsCode: true },
		welcome:              { icon: 'waving_hand',       color: '#d4af37', label: 'Cupón de bienvenida',     needsCode: true },
		gift:                 { icon: 'card_giftcard',     color: '#ff1a80', label: 'Regalo por compra',       needsCode: false },
		cashback:             { icon: 'loyalty',           color: '#7a3fa8', label: 'Puntos / cashback',       needsCode: false },
		data_capture:         { icon: 'alternate_email',   color: '#0b7a55', label: 'Captura de datos',        needsCode: true }
	};

	// Descripción de una línea + micro-ejemplo por tipo, para las tarjetas
	// de la galería agrupada.
	var TYPE_CONTENT = {
		percent:              { desc: 'Descuento porcentual clásico, con o sin código.',              example: 'Ej: 20% OFF en toda la tienda' },
		fixed:                { desc: 'Resta un monto fijo del total de la compra.',                  example: 'Ej: $10.000 de descuento en pedidos +$50.000' },
		launch:               { desc: 'Precio especial para el lanzamiento de un producto nuevo.',    example: 'Ej: Precio de lanzamiento $29.900 los primeros 7 días' },
		flash:                { desc: 'Oferta relámpago con cuenta regresiva visible.',               example: 'Ej: 30% OFF solo por las próximas 24 horas' },
		'2x1':                { desc: 'Lleva 2 y paga 1 en los productos que elijas.',                example: 'Ej: 2x1 en toda la categoría Camisetas' },
		'3x2':                { desc: 'Lleva 3 y paga 2, ideal para mover inventario.',               example: 'Ej: 3x2 en Accesorios de temporada' },
		second_unit:          { desc: 'Descuento automático en la segunda unidad del mismo producto.', example: 'Ej: 50% OFF en la segunda unidad' },
		tiered:               { desc: 'Más compras, más descuento, por tramos de cantidad.',          example: 'Ej: 3-5 uds → 10%, 6+ uds → 15%' },
		bundle:               { desc: 'Combo de productos a un precio de set.',                       example: 'Ej: Combo de 3 productos por $79.900' },
		free_ship_threshold:  { desc: 'Envío gratis automático al superar un monto mínimo.',           example: 'Ej: Envío gratis desde $150.000' },
		free_ship:            { desc: 'Envío gratis al usar un código de cupón.',                     example: 'Ej: Código ENVIOGRATIS en el checkout' },
		welcome:              { desc: 'Cupón de bienvenida para el primer pedido.',                   example: 'Ej: 10% OFF con el código BIENVENIDO10' },
		gift:                 { desc: 'Regalo automático al superar una compra mínima.',               example: 'Ej: Regalo de cortesía en compras +$80.000' },
		cashback:             { desc: 'Puntos o crédito para la próxima compra.',                     example: 'Ej: 5% de cashback en cada pedido' },
		data_capture:         { desc: 'Descuento a cambio de datos de contacto.',                     example: 'Ej: 15% OFF por suscribirse al boletín' }
	};

	// Valor precargado sugerido por tipo, para que una tarjeta de la galería
	// nunca abra el paso 2 del wizard con campos vacíos.
	var DEFAULT_VALUE = {
		percent: 15, fixed: 10000, launch: 0, flash: 20, '2x1': 0, '3x2': 0,
		second_unit: 50, tiered: 10, bundle: 0, free_ship_threshold: 0, free_ship: 0,
		welcome: 10, gift: 0, cashback: 5, data_capture: 15
	};

	/**
	 * Configuración base precargada para una tarjeta de tipo (no una campaña
	 * específica): valores razonables por defecto que el comerciante puede
	 * ajustar en el paso 2 del wizard.
	 */
	function buildBaseTemplate(typeId) {
		var meta = TYPE_META[typeId] || {};
		return {
			name: meta.label || '',
			type: typeId,
			value: DEFAULT_VALUE[typeId] || 0,
			scope: { target: 'all', ids: [] },
			needsCode: needsCodeDefault(typeId),
			code: '',
			minAmount: typeId === 'free_ship_threshold' ? 100000 : 0,
			limitGlobal: 0,
			limitUser: typeId === 'welcome' ? 1 : 0,
			start: todayISO(),
			end: '',
			cartMessage: '',
			giftText: typeId === 'gift' ? '' : undefined,
			badge: ''
		};
	}

	function quickTemplate(typeId, overrides) {
		return Object.assign(buildBaseTemplate(typeId), overrides);
	}

	// -- Fila superior: 5 plantillas de 1 clic con valores precargados --------
	// (ver tabla "Plantillas prediseñadas recomendadas" del plan maestro).
	var QUICK_TEMPLATES = [
		{
			id: 'quick_black_friday',
			title: 'Black Friday −20% toda la tienda',
			description: 'El clásico de temporada: descuento parejo en todo el catálogo.',
			example: 'Ej: 20% OFF en toda la tienda, sin código',
			typeId: 'percent',
			badge: 'BLACK FRIDAY',
			data: quickTemplate('percent', {
				name: 'Black Friday −20%',
				value: 20,
				scope: { target: 'all', ids: [] },
				needsCode: false,
				badge: 'BLACK FRIDAY',
				cartMessage: '¡Black Friday! 20% OFF en toda la tienda'
			})
		},
		{
			id: 'quick_3x2_category',
			title: '3x2 en categoría',
			description: 'Lleva 3, paga 2 — ideal para vaciar una categoría completa.',
			example: 'Ej: 3x2 en toda la categoría Accesorios',
			typeId: '3x2',
			badge: '',
			data: quickTemplate('3x2', {
				name: '3x2 en categoría',
				scope: { target: 'category', ids: [] },
				cartMessage: '¡Llevas 3 y pagas 2!'
			})
		},
		{
			id: 'quick_free_ship_50',
			title: 'Envío gratis desde $50',
			description: 'Envío gratis automático al superar el monto mínimo.',
			example: 'Ej: Envío gratis en compras desde $50',
			typeId: 'free_ship_threshold',
			badge: '',
			data: quickTemplate('free_ship_threshold', {
				name: 'Envío gratis desde $50',
				minAmount: 50,
				cartMessage: '¡Te faltan {monto} para envío gratis!'
			})
		},
		{
			id: 'quick_welcome_10',
			title: 'Cupón de bienvenida 10%',
			description: 'Recibe a los nuevos clientes con un código exclusivo.',
			example: 'Ej: 10% OFF con el código BIENVENIDO10',
			typeId: 'welcome',
			badge: '',
			data: quickTemplate('welcome', {
				name: 'Cupón de bienvenida 10%',
				value: 10,
				needsCode: true,
				code: 'BIENVENIDO10',
				limitUser: 1,
				conditions: ['first_purchase'],
				cartMessage: '¡Bienvenido! 10% OFF en tu primera compra'
			})
		},
		{
			id: 'quick_flash_24h',
			title: 'Oferta flash 24h',
			description: 'Urgencia real con cuenta regresiva de 24 horas.',
			example: 'Ej: 20% OFF solo por las próximas 24 horas',
			typeId: 'flash',
			badge: 'FLASH',
			data: quickTemplate('flash', {
				name: 'Oferta flash 24h',
				value: 20,
				end: addDaysISO(1),
				badge: 'FLASH',
				cartMessage: '¡Oferta flash! Termina pronto'
			})
		}
	];

	// -- Galería agrupada por objetivo de negocio ------------------------------
	var GROUPS = [
		{
			id: 'direct_discount',
			title: 'Descuento directo',
			subtitle: 'Baja el precio directamente, con o sin código.',
			types: ['percent', 'fixed', 'launch', 'flash']
		},
		{
			id: 'sell_more',
			title: 'Vender más por pedido',
			subtitle: 'Motiva a añadir más productos al carrito.',
			types: ['2x1', '3x2', 'second_unit', 'tiered', 'bundle', 'free_ship_threshold']
		},
		{
			id: 'shipping',
			title: 'Envío',
			subtitle: 'Elimina el freno del costo de envío.',
			types: ['free_ship', 'free_ship_threshold']
		},
		{
			id: 'acquire_retain',
			title: 'Captar y fidelizar',
			subtitle: 'Atrae nuevos clientes y haz que vuelvan.',
			types: ['welcome', 'gift', 'cashback', 'data_capture']
		}
	];

	// -- Tarjeta de tipo (galería agrupada) -------------------------------------
	function TypeTemplateCard(props) {
		var typeId = props.typeId;
		var onSelect = props.onSelect;
		var meta = TYPE_META[typeId] || { icon: 'sell', color: '#5b7b41', label: typeId };
		var content = TYPE_CONTENT[typeId] || { desc: '', example: '' };

		return el('button', {
			type: 'button',
			className: 'drw-promo-card drw-template-card',
			style: { textAlign: 'left', font: 'inherit', cursor: 'pointer', width: '100%' },
			onClick: function () { onSelect(buildBaseTemplate(typeId)); }
		},
			el('div', { className: 'drw-promo-header' },
				el('span', { className: 'drw-icon-tile', style: { '--drw-tile-color': meta.color } },
					el(MaterialIcon, { name: meta.icon, size: 20, color: meta.color })
				),
				el('div', { className: 'drw-promo-title-wrap' },
					el('span', { className: 'drw-promo-name' }, meta.label),
					el('span', { className: 'drw-promo-type-label' },
						needsCodeDefault(typeId) ? 'Con código' : 'Automática, sin código'
					)
				)
			),
			el('p', { className: 'drw-text-muted', style: { margin: 0, fontSize: 12.5, lineHeight: 1.5 } }, content.desc),
			el('p', { className: 'drw-text-muted', style: { margin: 0, fontSize: 12, fontStyle: 'italic' } }, content.example)
		);
	}

	// -- Tarjeta de plantilla de 1 clic (fila superior) -------------------------
	function QuickTemplateCard(props) {
		var tpl = props.template;
		var onSelect = props.onSelect;
		var meta = TYPE_META[tpl.typeId] || { icon: 'sell', color: '#5b7b41' };

		return el('button', {
			type: 'button',
			className: 'drw-promo-card drw-template-card drw-template-card-quick',
			style: { textAlign: 'left', font: 'inherit', cursor: 'pointer', width: '100%' },
			onClick: function () { onSelect(cloneTemplate(tpl.data)); }
		},
			el('div', { className: 'drw-promo-header' },
				el('span', { className: 'drw-icon-tile', style: { '--drw-tile-color': meta.color } },
					el(MaterialIcon, { name: meta.icon, size: 20, color: meta.color })
				),
				el('div', { className: 'drw-promo-title-wrap' },
					el('div', { style: { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' } },
						el('span', { className: 'drw-promo-name' }, tpl.title),
						tpl.badge && el('span', {
							className: 'drw-status-badge',
							style: {
								background: 'color-mix(in srgb, ' + meta.color + ' 16%, #ffffff)',
								color: meta.color
							}
						}, tpl.badge)
					)
				)
			),
			el('p', { className: 'drw-text-muted', style: { margin: 0, fontSize: 12.5, lineHeight: 1.5 } }, tpl.description),
			el('p', { className: 'drw-text-muted', style: { margin: 0, fontSize: 12, fontStyle: 'italic' } }, tpl.example)
		);
	}

	// -- Tarjeta genérica (galería basada en un catálogo `templates`) ----------
	// Consumida por la galería genérica: cada item es { id, label, description,
	// icon, color, ... } y se entrega intacto a onSelectTemplate al hacer clic
	// (el consumidor decide qué campo usar, p. ej. `rule` en la galería de Reglas).
	function GenericTemplateCard(props) {
		var tpl = props.template;
		var onSelect = props.onSelect;
		var iconSet = props.iconSet;
		var color = tpl.color || '#5b7b41';

		return el('button', {
			type: 'button',
			className: 'drw-promo-card drw-template-card',
			style: { textAlign: 'left', font: 'inherit', cursor: 'pointer', width: '100%' },
			onClick: function () { onSelect(tpl); }
		},
			el('div', { className: 'drw-promo-header' },
				el('span', { className: 'drw-icon-tile', style: { '--drw-tile-color': color } },
					renderIcon(iconSet, tpl.icon || 'tag', 20)
				),
				el('div', { className: 'drw-promo-title-wrap' },
					el('span', { className: 'drw-promo-name' }, tpl.label)
				)
			),
			tpl.description && el('p', { className: 'drw-text-muted', style: { margin: 0, fontSize: 12.5, lineHeight: 1.5 } }, tpl.description)
		);
	}

	// -- Galería genérica -------------------------------------------------------
	// Renderiza un catálogo plano de plantillas (prop `templates`) sin agrupar,
	// reutilizando el shell/estilo de la galería de promos. `search` viene del
	// estado de TemplateGallery para no romper la regla de hooks.
	function renderGenericGallery(props, search, setSearch) {
		var templates = props.templates || [];
		var iconSet = props.iconSet || 'material';
		var onSelectTemplate = props.onSelectTemplate;
		var onStartBlank = props.onStartBlank;
		var title = props.title || '¿Qué quieres lograr?';
		var subtitle = props.subtitle || 'Elige una plantilla para empezar con todo prellenado, o crea una desde cero.';
		var blankLabel = props.blankLabel || 'Empezar en blanco';

		function handleSelect(tpl) {
			if (typeof onSelectTemplate === 'function') { onSelectTemplate(tpl); }
		}
		function handleBlank() {
			if (typeof onStartBlank === 'function') { onStartBlank(); }
		}

		var query = search.trim().toLowerCase();
		var visible = templates.filter(function (tpl) {
			if (!query) { return true; }
			return ((tpl.label || '') + ' ' + (tpl.description || '')).toLowerCase().indexOf(query) !== -1;
		});

		return el('div', { className: 'drw-template-gallery' },

			el('div', { className: 'drw-page-header' },
				el('div', null,
					el('h2', { className: 'drw-page-title' }, title),
					el('p', { className: 'drw-text-muted', style: { margin: '4px 0 0', fontSize: 13 } }, subtitle)
				),
				el('button', { type: 'button', className: 'drw-btn drw-btn-ghost', onClick: handleBlank },
					renderIcon(iconSet, 'edit', 15), ' ', blankLabel
				)
			),

			el('div', { className: 'drw-search-wrap', style: { maxWidth: 360, marginBottom: 22 } },
				renderIcon(iconSet, 'search', 16, 'drw-search-icon'),
				el('input', {
					type: 'search',
					'aria-label': 'Buscar plantilla',
					placeholder: 'Buscar plantilla…',
					value: search,
					onChange: function (e) { setSearch(e.target.value); }
				})
			),

			visible.length === 0 && el('div', { className: 'drw-empty' },
				el('div', null,
					el('div', { className: 'drw-empty-icon' }, renderIcon(iconSet, 'search', 22)),
					el('h3', { className: 'drw-empty-title' }, 'Sin resultados'),
					el('p', { className: 'drw-empty-text' },
						'No encontramos plantillas para "' + search + '". Prueba con otra palabra o empieza en blanco.'
					),
					el('button', { type: 'button', className: 'drw-btn drw-btn-primary', onClick: handleBlank }, blankLabel)
				)
			),

			visible.length > 0 && el('div', { className: 'drw-promo-grid' },
				visible.map(function (tpl) {
					return el(GenericTemplateCard, { key: tpl.id, template: tpl, iconSet: iconSet, onSelect: handleSelect });
				})
			),

			visible.length > 0 && el('div', { style: { textAlign: 'center', marginTop: 22 } },
				el('button', { type: 'button', className: 'drw-btn drw-btn-ghost drw-btn-sm', onClick: handleBlank },
					'O empieza en blanco (modo experto) →'
				)
			)
		);
	}

	// -- Galería principal --------------------------------------------------
	function TemplateGallery(props) {
		var onSelectTemplate = props.onSelectTemplate;
		var onStartBlank = props.onStartBlank;

		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];

		// Generic mode: a caller passes a flat `templates` catalogue (e.g. the
		// Reglas gallery). The classic promo mode (no `templates`) is unchanged,
		// so drw-promo-wizard.js keeps working exactly as before.
		if (Array.isArray(props.templates)) {
			return renderGenericGallery(props, search, setSearch);
		}

		function handleSelect(templateData) {
			if (typeof onSelectTemplate === 'function') {
				onSelectTemplate(templateData);
			}
		}

		function handleBlank() {
			if (typeof onStartBlank === 'function') {
				onStartBlank();
			}
		}

		var query = search.trim().toLowerCase();
		function matches() {
			var haystack = Array.prototype.slice.call(arguments).join(' ').toLowerCase();
			return !query || haystack.indexOf(query) !== -1;
		}

		var visibleQuick = QUICK_TEMPLATES.filter(function (tpl) {
			return matches(tpl.title, tpl.description, tpl.example);
		});

		var visibleGroups = GROUPS.map(function (group) {
			var types = group.types.filter(function (typeId) {
				var meta = TYPE_META[typeId] || {};
				var content = TYPE_CONTENT[typeId] || {};
				return matches(meta.label || '', content.desc || '', content.example || '');
			});
			return { id: group.id, title: group.title, subtitle: group.subtitle, types: types };
		}).filter(function (group) { return group.types.length > 0; });

		var hasResults = visibleQuick.length > 0 || visibleGroups.length > 0;

		return el('div', { className: 'drw-template-gallery' },

			el('div', { className: 'drw-page-header' },
				el('div', null,
					el('h2', { className: 'drw-page-title' }, '¿Qué quieres lograr?'),
					el('p', { className: 'drw-text-muted', style: { margin: '4px 0 0', fontSize: 13 } },
						'Elige una plantilla y empieza con todo prellenado, o crea tu propia promoción desde cero.'
					)
				),
				el('button', { className: 'drw-btn drw-btn-ghost', onClick: handleBlank },
					el(MaterialIcon, { name: 'tune', size: 15 }), ' Empezar en blanco'
				)
			),

			el('div', { className: 'drw-search-wrap', style: { maxWidth: 360, marginBottom: 22 } },
				el(MaterialIcon, { name: 'search', size: 16, color: '#8b8b8b', className: 'drw-search-icon' }),
				el('input', {
					type: 'search',
					placeholder: 'Buscar plantilla…',
					value: search,
					onChange: function (e) { setSearch(e.target.value); }
				})
			),

			!hasResults && el('div', { className: 'drw-empty' },
				el('div', null,
					el('div', { className: 'drw-empty-icon' },
						el(MaterialIcon, { name: 'search_off', size: 22 })
					),
					el('h3', { className: 'drw-empty-title' }, 'Sin resultados'),
					el('p', { className: 'drw-empty-text' },
						'No encontramos plantillas para "' + search + '". Prueba con otra palabra o empieza en blanco.'
					),
					el('button', { className: 'drw-btn drw-btn-primary', onClick: handleBlank }, 'Empezar en blanco')
				)
			),

			hasResults && visibleQuick.length > 0 && el('div', { style: { marginBottom: 30 } },
				el('div', { className: 'drw-section-label' }, 'Plantillas de 1 clic'),
				el('div', { className: 'drw-promo-grid' },
					visibleQuick.map(function (tpl) {
						return el(QuickTemplateCard, { key: tpl.id, template: tpl, onSelect: handleSelect });
					})
				)
			),

			hasResults && visibleGroups.map(function (group) {
				return el('div', { key: group.id, style: { marginBottom: 30 } },
					el('div', { className: 'drw-section-label' }, group.title),
					el('p', { className: 'drw-text-muted', style: { margin: '0 0 12px', fontSize: 12.5 } }, group.subtitle),
					el('div', { className: 'drw-promo-grid' },
						group.types.map(function (typeId) {
							return el(TypeTemplateCard, { key: typeId, typeId: typeId, onSelect: handleSelect });
						})
					)
				);
			}),

			hasResults && el('div', { style: { textAlign: 'center', marginTop: 8 } },
				el('button', { className: 'drw-btn drw-btn-ghost drw-btn-sm', onClick: handleBlank },
					'O empieza en blanco (modo experto) →'
				)
			)
		);
	}

	window.DrwTemplateGallery = TemplateGallery;

})();
