/**
 * Dynamic Pricing & Discount Rules for WooCommerce Admin App
 * Built using WordPress core React (wp-element) and UI Components (wp-components).
 */

(function($) {
    'use strict';

    // Safely retrieve WordPress React globals
    const { createElement: el, useState, useEffect } = wp.element;
    const { render } = wp.element;
    const {
        Button,
        TextControl,
        SelectControl,
        ToggleControl,
        Notice,
        Spinner,
        TabPanel
    } = wp.components;

    const apiFetch = wp.apiFetch;

    // ------------------------------------------------------------------
    // Condition-type catalogue shared by the Rule Editor and Configuración
    // Global. `value` is the type slug RulesEngine.php maps to a Condition
    // class; `settingsKey` links it to settings.conditions[...] so the
    // "Condiciones y Filtros Habilitados" tab can hide a type from the editor.
    // The value/label pairs are unchanged from the editor's original inline
    // dropdown — only the settingsKey column and the enable-filtering are new.
    // ------------------------------------------------------------------
    const CONDITION_TYPE_OPTIONS = [
        { label: 'Subtotal del carrito', value: 'subtotal', settingsKey: 'cart_subtotal' },
        { label: 'Cantidad de artículos del carrito', value: 'items_count', settingsKey: 'cart_line_items_count' },
        { label: 'Rol de usuario', value: 'user_role', settingsKey: 'user_role' },
        { label: 'Correo del usuario', value: 'user_email', settingsKey: 'user_email' },
        { label: 'Dirección de envío', value: 'shipping_location', settingsKey: 'shipping_location' },
        { label: 'Cupón aplicado en el carrito', value: 'cart_coupon', settingsKey: 'cart_coupon' },
        { label: 'Cantidad total de artículos del carrito', value: 'cart_items_quantity', settingsKey: 'cart_items_quantity' },
        { label: 'Peso total del carrito', value: 'cart_items_weight', settingsKey: 'cart_items_weight' },
        { label: 'Estado de productos en oferta', value: 'onsale_products', settingsKey: 'cart_item_product_onsale' },
        { label: 'Combinación de productos/categorías', value: 'product_combination', settingsKey: 'cart_item_product_combination' },
        { label: 'Estado de sesión del usuario', value: 'user_logged_in', settingsKey: 'user_logged_in' },
        { label: 'Lista de usuarios (IDs específicos)', value: 'user_list', settingsKey: 'user_list' },
        { label: 'Ciudad de facturación', value: 'billing_city', settingsKey: 'billing_city' },
        { label: 'Programación (fechas/horas/días)', value: 'order_date', settingsKey: 'order_date' },
        { label: 'Historial de compras del cliente', value: 'purchase_history', settingsKey: 'purchase_history' }
    ];

    // A condition type is available in the editor unless Configuración Global
    // explicitly disabled it. Missing map / missing key ⇒ enabled (fail-open),
    // so a stale or absent settings payload never hides expected options.
    const isConditionTypeEnabled = (conditionsSettings, settingsKey) => {
        if (!conditionsSettings || typeof conditionsSettings !== 'object') {
            return true;
        }
        const entry = conditionsSettings[settingsKey];
        if (!entry || typeof entry !== 'object') {
            return true;
        }
        return entry.enabled !== false;
    };

    // Initial localized data passed from PHP
    const adminData = window.drwAdminData || {
        apiRoot: '/wp-json/drw/v1/rules',
        settingsApiRoot: '/wp-json/drw/v1/settings',
        nonce: '',
        products: [],
        categories: [],
        roles: []
    };

    if (adminData.nonce && apiFetch.createNonceMiddleware) {
        apiFetch.use(apiFetch.createNonceMiddleware(adminData.nonce));
    }

    const normalizeIds = (ids) => {
        if (!Array.isArray(ids)) {
            return [];
        }
        return ids.map((id) => parseInt(id, 10)).filter((id) => id > 0);
    };

    const productLabel = (product) => {
        if (!product) {
            return '';
        }
        return product.sku ? `${product.name} (${product.sku})` : product.name;
    };

    /**
     * Async product selector for stores with large catalogs.
     */
    function ProductSearchMultiSelect({ label, selectedIds, onChange, help }) {
        const ids = normalizeIds(selectedIds);
        const [search, setSearch] = useState('');
        const [results, setResults] = useState([]);
        const [selectedProducts, setSelectedProducts] = useState([]);
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState('');

        useEffect(() => {
            if (ids.length === 0) {
                setSelectedProducts([]);
                return;
            }

            apiFetch({ path: `/drw/v1/products?include=${encodeURIComponent(ids.join(','))}&per_page=50` })
                .then((data) => {
                    setSelectedProducts(data.items || []);
                })
                .catch(() => {
                    const fallback = (adminData.products || []).filter((product) => ids.includes(parseInt(product.id, 10)));
                    setSelectedProducts(fallback);
                });
        }, [ids.join(',')]);

        useEffect(() => {
            const query = search.trim();
            if (query.length < 2) {
                setResults([]);
                setLoading(false);
                setError('');
                return;
            }

            setLoading(true);
            const timeout = setTimeout(() => {
                apiFetch({ path: `/drw/v1/products?search=${encodeURIComponent(query)}&per_page=20` })
                    .then((data) => {
                        setResults(data.items || []);
                        setError('');
                    })
                    .catch((err) => {
                        setResults([]);
                        setError(err.message || 'No se pudieron buscar los productos.');
                    })
                    .finally(() => setLoading(false));
            }, 250);

            return () => clearTimeout(timeout);
        }, [search]);

        const addProduct = (product) => {
            const productId = parseInt(product.id, 10);
            if (!ids.includes(productId)) {
                onChange([...ids, productId]);
                setSelectedProducts([...selectedProducts, product]);
            }
            setSearch('');
            setResults([]);
        };

        const removeProduct = (productId) => {
            onChange(ids.filter((id) => id !== productId));
            setSelectedProducts(selectedProducts.filter((product) => parseInt(product.id, 10) !== productId));
        };

        const mergedSelected = ids.map((id) => {
            return selectedProducts.find((product) => parseInt(product.id, 10) === id)
                || (adminData.products || []).find((product) => parseInt(product.id, 10) === id)
                || { id, name: `Producto #${id}`, sku: '' };
        });

        return el('div', { className: 'drw-product-search' },
            el(TextControl, {
                label,
                type: 'search',
                value: search,
                help: help || 'Escribe al menos 2 caracteres para buscar en todo el catálogo de WooCommerce.',
                placeholder: 'Busca productos por nombre o SKU...',
                onChange: setSearch
            }),
            loading && el('div', { className: 'drw-product-search-status' }, el(Spinner), el('span', null, 'Buscando productos...')),
            error && el('div', { className: 'drw-product-search-error' }, error),
            results.length > 0 && el('ul', { className: 'drw-product-search-results' },
                results.map((product) => {
                    const productId = parseInt(product.id, 10);
                    const disabled = ids.includes(productId);
                    return el('li', { key: productId },
                        el(Button, {
                            type: 'button',
                            disabled,
                            onClick: () => addProduct(product)
                        }, disabled ? `${productLabel(product)} - seleccionado` : productLabel(product))
                    );
                })
            ),
            mergedSelected.length > 0 && el('div', { className: 'drw-selected-products' },
                mergedSelected.map((product) => {
                    const productId = parseInt(product.id, 10);
                    return el('span', { key: productId, className: 'drw-selected-product' },
                        productLabel(product),
                        el(Button, {
                            type: 'button',
                            className: 'drw-selected-product-remove',
                            onClick: () => removeProduct(productId),
                            label: `Quitar ${product.name}`
                        }, 'x')
                    );
                })
            )
        );
    }

    /**
     * Main App Component
     */
    function DrwApp() {
        // Open the SPA on the screen the merchant navigated to. Each OmniDiscount
        // submenu (Reglas / Cupones y Promociones / Configuración) sets
        // window.drwAdminData.initialScreen server-side from $_GET['page']; fall
        // back to 'list' when absent. Valid: 'list', 'edit', 'settings', 'promos'.
        var drwInitialScreen = (window.drwAdminData && window.drwAdminData.initialScreen) || 'list';
        const [screen, setScreen] = useState(drwInitialScreen);
        const [rules, setRules] = useState([]);
        const [editingRule, setEditingRule] = useState(null);
        const [loading, setLoading] = useState(true);
        const [errorMsg, setErrorMsg] = useState('');
        const [successMsg, setSuccessMsg] = useState('');

        // Shared, session-live copy of settings.conditions (key => {enabled}).
        // Seeded from the PHP localize (window.drwAdminData.conditionsSettings)
        // so the Rule Editor can filter its condition-type dropdown on first
        // paint; GlobalSettings refreshes it via onConditionsChange after a save
        // so toggling a condition on/off takes effect without a page reload.
        const [conditionsSettings, setConditionsSettings] = useState(
            (window.drwAdminData && window.drwAdminData.conditionsSettings) || null
        );

        // Fetch rules on mount
        useEffect(() => {
            fetchRules();
        }, []);

        const fetchRules = () => {
            setLoading(true);
            apiFetch({ path: '/drw/v1/rules' })
                .then((data) => {
                    setRules(data);
                    setLoading(false);
                })
                .catch((err) => {
                    setErrorMsg(err.message || 'Error al cargar las reglas.');
                    setLoading(false);
                });
        };

        // The blank-rule default. Kept as the single source of truth for BOTH
        // the "Empezar en blanco" path and what the template gallery falls back
        // to, so "empezar en blanco" produces the exact same object the old
        // handleAddRule() used to build directly.
        const buildDefaultRule = () => ({
            title: '',
            enabled: true,
            exclusive: false,
            priority: 10,
            apply_to: 'all_products',
            filters: {
                product_ids: [],
                category_ids: [],
                exclude_product_ids: [],
                exclude_category_ids: []
            },
            conditions: [],
            adjustments: {
                type: 'percentage', // percentage, fixed, bulk
                value: 10,
                tiers: []
            }
        });

        // "+ Crear regla" now opens the one-click template gallery first instead
        // of jumping straight into the long form. editingRule is cleared so the
        // header reads "Crear nueva regla de descuento" while the gallery shows.
        const handleAddRule = () => {
            setEditingRule(null);
            setScreen('templates');
        };

        // Gallery: "Empezar en blanco" — identical behaviour to the old
        // handleAddRule(): seed the editor with the blank default and open it.
        const handleStartBlank = () => {
            setEditingRule(buildDefaultRule());
            setScreen('edit');
        };

        // Gallery: a template card was chosen. Seed editingRule with a DEEP COPY
        // of the template's `rule` (the localized catalogue is shared, and the
        // RuleEditor mutates nested tier/condition objects in place) and open the
        // editor. RuleEditor itself is untouched — only its initial data differs.
        const handleSelectTemplate = (tpl) => {
            const rule = tpl && tpl.rule ? tpl.rule : buildDefaultRule();
            setEditingRule(JSON.parse(JSON.stringify(rule)));
            setScreen('edit');
        };

        const handleEditRule = (rule) => {
            // Deep copy to prevent mutating list state before saving
            setEditingRule(JSON.parse(JSON.stringify(rule)));
            setScreen('edit');
        };

        const handleDeleteRule = (id) => {
            if (!confirm('¿Seguro que deseas eliminar esta regla?')) {
                return;
            }
            setLoading(true);
            apiFetch({
                path: `/drw/v1/rules/${id}`,
                method: 'DELETE'
            })
            .then(() => {
                showSuccess('Regla eliminada correctamente.');
                fetchRules();
            })
            .catch((err) => {
                setErrorMsg(err.message || 'Error al eliminar la regla.');
                setLoading(false);
            });
        };

        const handleToggleStatus = (rule) => {
            const updated = { ...rule, enabled: !rule.enabled };
            apiFetch({
                path: '/drw/v1/rules',
                method: 'POST',
                data: updated
            })
            .then(() => {
                fetchRules();
            })
            .catch((err) => {
                setErrorMsg(err.message || 'Error al actualizar el estado.');
            });
        };

        const handleSaveRule = () => {
            if (!editingRule.title.trim()) {
                setErrorMsg('El título de la regla es obligatorio.');
                return;
            }
            setLoading(true);
            apiFetch({
                path: '/drw/v1/rules',
                method: 'POST',
                data: editingRule
            })
            .then(() => {
                showSuccess('Regla guardada correctamente.');
                fetchRules();
                setScreen('list');
            })
            .catch((err) => {
                setErrorMsg(err.message || 'Error al guardar la regla.');
                setLoading(false);
            });
        };

        const showSuccess = (msg) => {
            setSuccessMsg(msg);
            setTimeout(() => setSuccessMsg(''), 4000);
        };

        // Render loading state
        if (loading && rules.length === 0) {
            return el('div', { className: 'drw-dashboard', style: { textAlign: 'center', padding: '50px' } }, 
                el(Spinner),
                el('p', null, 'Cargando motor de reglas...')
            );
        }

        return el('div', { className: 'drw-dashboard' },
            // Notices
            successMsg && el(Notice, { status: 'success', isDismissible: true, onDismiss: () => setSuccessMsg('') }, successMsg),
            errorMsg && el(Notice, { status: 'error', isDismissible: true, onDismiss: () => setErrorMsg('') }, errorMsg),

            // Header Section
            el('div', { className: 'drw-header' },
                el('h2', { className: 'drw-title' },
                    screen === 'settings' ? 'Configuración Global' :
                    screen === 'promos'   ? 'Cupones y Promociones' :
                    screen === 'list'     ? 'OmniDiscount Dashboard' :
                    (editingRule && editingRule.id ? 'Editar regla de descuento' : 'Crear nueva regla de descuento')
                ),
                screen === 'list' && el('div', { style: { display: 'flex', gap: '8px' } },
                    el(Button, { className: 'drw-secondary-btn', onClick: () => setScreen('promos') }, '🎟 Cupones'),
                    el(Button, { className: 'drw-secondary-btn', onClick: () => setScreen('settings') }, '⚙ Configuración'),
                    el(Button, { className: 'drw-primary-btn', onClick: handleAddRule }, '+ Crear regla')
                )
            ),

            // Screens
            screen === 'settings'
                ? el(GlobalSettings, { onBack: () => setScreen('list'), onConditionsChange: setConditionsSettings })
                : screen === 'promos'
                    ? (window.DrwPromosPage ? el(window.DrwPromosPage, { onBack: () => setScreen('list') }) : el('p', null, 'Cargando promociones...'))
                    : screen === 'list'
                        ? el(RulesList, { rules, onEdit: handleEditRule, onDelete: handleDeleteRule, onToggle: handleToggleStatus })
                        : screen === 'templates'
                            ? el(RuleTemplatePicker, { onSelectTemplate: handleSelectTemplate, onStartBlank: handleStartBlank, onCancel: () => setScreen('list') })
                            : el(RuleEditor, { rule: editingRule, setRule: setEditingRule, onSave: handleSaveRule, onCancel: () => setScreen('list'), conditionsSettings: conditionsSettings })
        );
    }

    /**
     * Rules Grid Listing Screen
     */
    function RulesList({ rules, onEdit, onDelete, onToggle }) {
        if (rules.length === 0) {
            return el('div', { style: { textAlign: 'center', padding: '40px 0', color: '#64748b' } },
                el('p', { style: { fontSize: '16px' } }, 'Aún no hay reglas. Haz clic en "+ Crear regla" para configurar tu primer descuento.'),
            );
        }

        return el('div', null,
            rules.map((rule) => {
                const adjType = rule.adjustments ? rule.adjustments.type : 'percentage';
                const adjVal = rule.adjustments ? rule.adjustments.value : 0;
                let detailsText = '';
                
                if (adjType === 'percentage') {
                    detailsText = `${adjVal}% de descuento`;
                } else if (adjType === 'fixed') {
                    detailsText = `$${adjVal} de descuento fijo`;
                } else if (adjType === 'bulk') {
                    detailsText = 'Descuento por niveles de cantidad';
                } else if (adjType === 'bogo') {
                    const bogoType = rule.adjustments.bogo_discount_type || 'free';
                    const bogoVal = rule.adjustments.bogo_value || 0;
                    const bogoText = bogoType === 'free' ? 'gratis' : (bogoType === 'percentage' ? `${bogoVal}% de descuento` : `$${bogoVal} de descuento`);
                    detailsText = `BOGO: compra ${rule.adjustments.buy_qty || 1} y llévate ${rule.adjustments.get_qty || 1} (${bogoText})`;
                } else if (adjType === 'free_shipping') {
                    detailsText = 'Envío gratis';
                } else if (adjType === 'bundle_set' || adjType === 'bundle') {
                    detailsText = `Precio de paquete ($${rule.adjustments.bundle_price || rule.adjustments.set_price || 0})`;
                }

                return el('div', { key: rule.id, className: 'drw-rule-card' },
                    el('div', { className: 'drw-rule-info' },
                        el('h4', { className: 'drw-rule-name' }, 
                            rule.title,
                            el('span', { className: `drw-badge ${rule.enabled ? 'drw-badge-active' : 'drw-badge-inactive'}` }, rule.enabled ? 'Activa' : 'Inactiva')
                        ),
                        el('div', { className: 'drw-rule-meta' },
                            el('span', null, `Prioridad: ${rule.priority}`),
                            el('span', null, `Aplica a: ${({ all_products: 'todos los productos', specific_products: 'productos específicos', specific_categories: 'categorías específicas' })[rule.apply_to] || rule.apply_to.replace('_', ' ')}`),
                            el('span', { className: 'drw-badge drw-badge-type' }, detailsText)
                        )
                    ),
                    el('div', { className: 'drw-rule-actions' },
                        el(ToggleControl, {
                            checked: rule.enabled,
                            onChange: () => onToggle(rule)
                        }),
                        el(Button, { className: 'drw-secondary-btn', onClick: () => onEdit(rule) }, 'Editar'),
                        el(Button, { className: 'drw-remove-btn', onClick: () => onDelete(rule.id) }, 'Eliminar')
                    )
                );
            })
        );
    }

    /**
     * Rule Template Gallery Screen
     *
     * Thin wrapper that reuses the shared window.DrwTemplateGallery component in
     * its generic mode, fed by the RuleTemplateRegistry catalogue localized as
     * adminData.ruleTemplates. The gallery's styling lives in admin-promos.css
     * whose --drw-* tokens are scoped to a container class, so we render inside
     * `.drw-token-scope` to make those tokens resolve on the Reglas screen.
     */
    function RuleTemplatePicker({ onSelectTemplate, onStartBlank, onCancel }) {
        const Gallery = window.DrwTemplateGallery;
        const templates = adminData.ruleTemplates || [];

        if (typeof Gallery !== 'function') {
            // Graceful degradation: never trap the merchant if the gallery script
            // failed to load — send them straight into a blank rule.
            return el('div', { className: 'drw-form-section' },
                el('p', null, 'La galería de plantillas no está disponible.'),
                el('div', { style: { display: 'flex', gap: '12px' } },
                    el(Button, { className: 'drw-primary-btn', onClick: onStartBlank }, 'Empezar en blanco'),
                    el(Button, { className: 'drw-secondary-btn', onClick: onCancel }, 'Cancelar')
                )
            );
        }

        return el('div', { className: 'drw-token-scope drw-rule-template-gallery' },
            el('div', { style: { marginBottom: '10px' } },
                el('button', { type: 'button', className: 'drw-btn drw-btn-ghost drw-btn-sm', onClick: onCancel },
                    '← Volver a reglas'
                )
            ),
            el(Gallery, {
                templates,
                iconSet: 'dashicon',
                title: '¿Qué tipo de descuento quieres crear?',
                subtitle: 'Elige una plantilla para empezar con la configuración lista, o crea una regla desde cero.',
                blankLabel: 'Empezar en blanco',
                onSelectTemplate,
                onStartBlank
            })
        );
    }

    /**
     * Collapsible section wrapper for the Rule Editor.
     *
     * Wraps each existing form block so the merchant can fold the long form into
     * digestible sections. Sections default to open, so the editor still shows
     * everything on first render exactly as before — collapsing is opt-in.
     */
    function Collapsible({ title, children, defaultOpen = true }) {
        const [open, setOpen] = useState(defaultOpen);
        // Spread children as individual args (not a single array) so React
        // doesn't emit spurious "unique key" warnings for the static section body.
        const kids = Array.isArray(children) ? children : [children];
        // WAI-ARIA disclosure pattern: the toggle <button> lives INSIDE the
        // heading (valid HTML + keeps the section heading in the a11y tree).
        return el('div', { className: 'drw-form-section drw-collapsible' + (open ? ' is-open' : '') },
            el('h3', { className: 'drw-collapsible-heading' },
                el('button', {
                    type: 'button',
                    className: 'drw-collapsible-toggle',
                    'aria-expanded': open,
                    onClick: () => setOpen(!open)
                },
                    el('span', { className: 'drw-collapsible-title' }, title),
                    el('span', { className: 'drw-collapsible-chevron', 'aria-hidden': 'true' },
                        el('svg', { width: 16, height: 16, viewBox: '0 0 16 16', fill: 'none' },
                            el('path', { d: 'M4 6l4 4 4-4', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' })
                        )
                    )
                )
            ),
            open && el.apply(null, ['div', { className: 'drw-collapsible-body' }].concat(kids))
        );
    }

    /**
     * Rule Editor Screen
     */
    function RuleEditor({ rule, setRule, onSave, onCancel, conditionsSettings }) {
        // Condition types the merchant left enabled in Configuración Global →
        // "Condiciones y Filtros Habilitados". This is the real wiring: a
        // disabled condition disappears from the "+ Agregar condición" dropdown
        // instead of only being stored server-side.
        const enabledConditionOptions = CONDITION_TYPE_OPTIONS.filter((opt) =>
            isConditionTypeEnabled(conditionsSettings, opt.settingsKey)
        );

        // Options for a specific row. A row whose already-saved type was later
        // disabled still shows that type (appended) so an existing rule never
        // loses data or renders a SelectControl with a value outside its list.
        const optionsForCondition = (currentType) => {
            const base = enabledConditionOptions.map((o) => ({ label: o.label, value: o.value }));
            if (base.some((o) => o.value === currentType)) {
                return base;
            }
            const current = CONDITION_TYPE_OPTIONS.find((o) => o.value === currentType);
            return current ? base.concat([{ label: current.label + ' (deshabilitada)', value: current.value }]) : base;
        };

        const updateRuleField = (field, val) => {
            setRule({ ...rule, [field]: val });
        };

        const updateFilters = (field, val) => {
            setRule({
                ...rule,
                filters: {
                    ...rule.filters,
                    [field]: val
                }
            });
        };

        const updateAdjustments = (field, val) => {
            setRule({
                ...rule,
                adjustments: {
                    ...rule.adjustments,
                    [field]: val
                }
            });
        };

        // Add a new condition. Defaults to the first ENABLED type so we never
        // seed a row with a condition the merchant disabled (falls back to
        // 'subtotal' when everything is enabled, matching the old behaviour).
        const addCondition = () => {
            if (enabledConditionOptions.length === 0) {
                return;
            }
            const defaultType = enabledConditionOptions[0].value;
            const conditions = [...(rule.conditions || [])];
            conditions.push({
                type: defaultType, // subtotal, items_count, user_role, user_email, shipping_location…
                operator: 'greater_than_or_equal',
                value: 100,
                location_type: 'country',
                check_type: 'total_quantity'
            });
            updateRuleField('conditions', conditions);
        };

        // Remove a condition
        const removeCondition = (index) => {
            const conditions = [...(rule.conditions || [])];
            conditions.splice(index, 1);
            updateRuleField('conditions', conditions);
        };

        // Update a single condition parameter
        const updateCondition = (index, field, val) => {
            const conditions = [...(rule.conditions || [])];
            conditions[index][field] = val;
            updateRuleField('conditions', conditions);
        };

        // Add a bulk tier
        const addTier = () => {
            const tiers = [...(rule.adjustments.tiers || [])];
            tiers.push({
                min: 1,
                max: '',
                type: 'percentage',
                value: 5
            });
            updateAdjustments('tiers', tiers);
        };

        // Remove a bulk tier
        const removeTier = (index) => {
            const tiers = [...(rule.adjustments.tiers || [])];
            tiers.splice(index, 1);
            updateAdjustments('tiers', tiers);
        };

        // Update bulk tier parameter
        const updateTier = (index, field, val) => {
            const tiers = [...(rule.adjustments.tiers || [])];
            tiers[index][field] = val;
            updateAdjustments('tiers', tiers);
        };

        return el('div', null,
            // Section 1: Basic Config
            el(Collapsible, { title: 'Configuración General' },
                el(TextControl, {
                    label: 'Título de la regla',
                    value: rule.title,
                    onChange: (val) => updateRuleField('title', val),
                    placeholder: 'Ej. Liquidación de verano 15%'
                }),
                el('div', { className: 'drw-flex-row' },
                    el(TextControl, {
                        label: 'Prioridad',
                        type: 'number',
                        value: rule.priority,
                        onChange: (val) => updateRuleField('priority', parseInt(val) || 10)
                    }),
                    el('div', { style: { paddingTop: '28px' } },
                        el(ToggleControl, {
                            label: 'Exclusiva (impide que se apliquen otras reglas)',
                            checked: rule.exclusive,
                            onChange: (val) => updateRuleField('exclusive', val)
                        })
                    )
                )
            ),

            // Section 2: Target Scope
            el(Collapsible, { title: 'Filtrado de productos (Aplica a)' },
                el(SelectControl, {
                    label: 'Aplicar la regla a:',
                    value: rule.apply_to,
                    options: [
                        { label: 'Todos los productos', value: 'all_products' },
                        { label: 'Solo productos específicos', value: 'specific_products' },
                        { label: 'Solo categorías específicas', value: 'specific_categories' }
                    ],
                    onChange: (val) => updateRuleField('apply_to', val)
                }),

                // Specific Products Select
                rule.apply_to === 'specific_products' && el('div', { style: { marginTop: '12px' } },
                    el(ProductSearchMultiSelect, {
                        label: 'Selecciona los productos objetivo',
                        selectedIds: rule.filters.product_ids || [],
                        onChange: (ids) => updateFilters('product_ids', ids)
                    })
                ),

                // Specific Categories Select
                rule.apply_to === 'specific_categories' && el('div', { style: { marginTop: '12px' } },
                    el('p', { style: { fontWeight: '600', marginBottom: '6px' } }, 'Selecciona las categorías objetivo:'),
                    el('div', { style: { maxHeight: '150px', overflowY: 'auto', border: '1px solid #cbd5e1', padding: '10px', borderRadius: '6px', background: '#fff' } },
                        adminData.categories.map((cat) => {
                            const isChecked = (rule.filters.category_ids || []).includes(cat.id);
                            return el('div', { key: cat.id, style: { marginBottom: '6px' } },
                                el('label', null,
                                    el('input', {
                                        type: 'checkbox',
                                        checked: isChecked,
                                        style: { marginRight: '8px' },
                                        onChange: (e) => {
                                            const list = [...(rule.filters.category_ids || [])];
                                            if (e.target.checked) {
                                                list.push(cat.id);
                                            } else {
                                                const idx = list.indexOf(cat.id);
                                                if (idx > -1) list.splice(idx, 1);
                                            }
                                            updateFilters('category_ids', list);
                                        }
                                    }),
                                    cat.name
                                )
                            );
                        })
                    )
                ),

                el('div', { className: 'drw-filter-exclusions' },
                    el('h4', null, 'Exclusiones'),
                    el('p', { className: 'drw-help-text' }, 'Excluye productos o categorías de esta regla aunque coincidan con el alcance seleccionado.'),
                    el(ProductSearchMultiSelect, {
                        label: 'Excluir productos',
                        selectedIds: rule.filters.exclude_product_ids || [],
                        help: 'Los productos seleccionados aquí nunca recibirán el descuento de esta regla.',
                        onChange: (ids) => updateFilters('exclude_product_ids', ids)
                    }),
                    el('div', { style: { marginTop: '12px' } },
                        el('span', { className: 'drw-field-label' }, 'Excluir categorías:'),
                        el('div', { className: 'drw-checklist-box drw-exclusion-category-box' },
                            adminData.categories.map((cat) => {
                                const isChecked = (rule.filters.exclude_category_ids || []).includes(cat.id);
                                return el('div', { key: cat.id, className: 'drw-checkbox-item' },
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: isChecked,
                                            onChange: (e) => {
                                                const list = [...(rule.filters.exclude_category_ids || [])];
                                                if (e.target.checked) {
                                                    list.push(cat.id);
                                                } else {
                                                    const idx = list.indexOf(cat.id);
                                                    if (idx > -1) list.splice(idx, 1);
                                                }
                                                updateFilters('exclude_category_ids', list);
                                            }
                                        }),
                                        ' ' + cat.name
                                    )
                                );
                            })
                        )
                    )
                )
            ),

            // Section 3: Pricing Adjustments
            el(Collapsible, { title: 'Ajustes de precio' },
                el(SelectControl, {
                    label: 'Tipo de descuento',
                    value: rule.adjustments.type === 'bundle' ? 'bundle_set' : rule.adjustments.type,
                    options: [
                        { label: 'Descuento porcentual', value: 'percentage' },
                        { label: 'Descuento de precio fijo', value: 'fixed' },
                        { label: 'Descuento escalonado por cantidad', value: 'bulk' },
                        { label: 'BOGO Compra X Lleva Y', value: 'bogo' },
                        { label: 'Envío gratis', value: 'free_shipping' },
                        { label: 'Precio de paquete (bundle)', value: 'bundle_set' }
                    ],
                    onChange: (val) => updateAdjustments('type', val)
                }),

                // Single values
                (rule.adjustments.type === 'percentage' || rule.adjustments.type === 'fixed') && el(TextControl, {
                    label: rule.adjustments.type === 'percentage' ? 'Valor porcentual (%)' : 'Valor del descuento fijo ($)',
                    type: 'number',
                    value: rule.adjustments.value,
                    onChange: (val) => updateAdjustments('value', parseFloat(val) || 0)
                }),

                // BOGO parameters
                rule.adjustments.type === 'bogo' && el('div', { className: 'drw-bogo-container', style: { marginTop: '12px' } },
                    el('div', { className: 'drw-flex-row' },
                        el(TextControl, {
                            label: 'Cantidad a comprar',
                            type: 'number',
                            value: rule.adjustments.buy_qty || '',
                            onChange: (val) => updateAdjustments('buy_qty', parseInt(val) || 1)
                        }),
                        el(ProductSearchMultiSelect, {
                            label: 'Selección de producto de regalo',
                            selectedIds: rule.adjustments.get_products || (rule.adjustments.get_product_id ? [rule.adjustments.get_product_id] : []),
                            help: 'Busca y selecciona uno o más productos para descontar como productos de regalo.',
                            onChange: (ids) => {
                                setRule({
                                    ...rule,
                                    adjustments: {
                                        ...rule.adjustments,
                                        get_product_type: ids.length > 0 ? 'different' : 'same',
                                        get_products: ids
                                    }
                                });
                            }
                        }),
                        el(TextControl, {
                            label: 'Cantidad a llevar',
                            type: 'number',
                            value: rule.adjustments.get_qty || '',
                            onChange: (val) => updateAdjustments('get_qty', parseInt(val) || 1)
                        })
                    ),
                    el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                        el(SelectControl, {
                            label: 'Tipo de descuento BOGO',
                            value: rule.adjustments.discount_type || rule.adjustments.bogo_discount_type || 'free',
                            options: [
                                { label: 'Producto gratis', value: 'free' },
                                { label: 'Descuento porcentual', value: 'percentage' },
                                { label: 'Descuento de precio fijo', value: 'fixed' }
                            ],
                            onChange: (val) => updateAdjustments('discount_type', val)
                        }),
                        ((rule.adjustments.discount_type || rule.adjustments.bogo_discount_type) === 'percentage' || (rule.adjustments.discount_type || rule.adjustments.bogo_discount_type) === 'fixed') && el(TextControl, {
                            label: 'Valor del descuento BOGO',
                            type: 'number',
                            value: rule.adjustments.discount_value || rule.adjustments.bogo_value || '',
                            onChange: (val) => updateAdjustments('discount_value', parseFloat(val) || 0)
                        })
                    )
                ),

                // Bundle parameters
                (rule.adjustments.type === 'bundle_set' || rule.adjustments.type === 'bundle') && el('div', { className: 'drw-bundle-container', style: { marginTop: '12px' } },
                    el(TextControl, {
                        label: 'Precio del paquete ($)',
                        type: 'number',
                        value: rule.adjustments.bundle_price || rule.adjustments.set_price || '',
                        onChange: (val) => updateAdjustments('bundle_price', parseFloat(val) || 0)
                    })
                ),

                // Tiered values
                rule.adjustments.type === 'bulk' && el('div', { style: { marginTop: '12px' } },
                    el('p', { style: { fontWeight: '600' } }, 'Niveles por cantidad:'),
                    el('table', { className: 'drw-tier-table' },
                        el('thead', null,
                            el('tr', null,
                                el('th', null, 'Cant. mín.'),
                                el('th', null, 'Cant. máx.'),
                                el('th', null, 'Tipo de descuento'),
                                el('th', null, 'Valor'),
                                el('th', null, 'Acciones')
                            )
                        ),
                        el('tbody', null,
                            (rule.adjustments.tiers || []).map((tier, idx) => {
                                return el('tr', { key: idx },
                                    el('td', null, el('input', {
                                        type: 'number',
                                        style: { width: '80px' },
                                        value: tier.min,
                                        onChange: (e) => updateTier(idx, 'min', parseInt(e.target.value) || 0)
                                    })),
                                    el('td', null, el('input', {
                                        type: 'number',
                                        placeholder: 'Infinito',
                                        style: { width: '80px' },
                                        value: tier.max,
                                        onChange: (e) => updateTier(idx, 'max', e.target.value === '' ? '' : parseInt(e.target.value))
                                    })),
                                    el('td', null, el('select', {
                                        value: tier.type,
                                        onChange: (e) => updateTier(idx, 'type', e.target.value)
                                    },
                                        el('option', { value: 'percentage' }, 'Porcentaje de descuento'),
                                        el('option', { value: 'fixed' }, 'Descuento fijo')
                                    )),
                                    el('td', null, el('input', {
                                        type: 'number',
                                        style: { width: '80px' },
                                        value: tier.value,
                                        onChange: (e) => updateTier(idx, 'value', parseFloat(e.target.value) || 0)
                                    })),
                                    el('td', null, el(Button, { className: 'drw-remove-btn', onClick: () => removeTier(idx) }, 'Eliminar'))
                                );
                            })
                        )
                    ),
                    el(Button, { className: 'drw-secondary-btn', onClick: addTier }, '+ Agregar nivel')
                )
            ),

            // Section 4: Rules Conditions
            el(Collapsible, { title: 'Lista de condiciones' },
                (rule.conditions || []).map((cond, idx) => {
                    return el('div', { key: idx, className: 'drw-condition-row' },
                        // Condition type selector. Options come from the shared
                        // CONDITION_TYPE_OPTIONS filtered by Configuración Global's
                        // enabled/disabled toggles (optionsForCondition keeps the
                        // row's own already-saved type visible even if disabled).
                        el(SelectControl, {
                            value: cond.type,
                            options: optionsForCondition(cond.type),
                            onChange: (val) => updateCondition(idx, 'type', val)
                        }),

                        // Render parameters based on type
                        cond.type === 'subtotal' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: '>= Mayor o igual que', value: 'greater_than_or_equal' },
                                { label: '<= Menor o igual que', value: 'less_than_or_equal' },
                                { label: '> Mayor que', value: 'greater_than' },
                                { label: '< Menor que', value: 'less_than' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'subtotal' && el(TextControl, {
                            type: 'number',
                            value: cond.value,
                            onChange: (val) => updateCondition(idx, 'value', parseFloat(val) || 0)
                        }),

                        cond.type === 'items_count' && el(SelectControl, {
                            value: cond.check_type,
                            options: [
                                { label: 'Cantidad total de artículos', value: 'total_quantity' },
                                { label: 'Número de líneas distintas', value: 'line_items_count' }
                            ],
                            onChange: (val) => updateCondition(idx, 'check_type', val)
                        }),
                        cond.type === 'items_count' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: '>= Mayor o igual que', value: 'greater_than_or_equal' },
                                { label: '<= Menor o igual que', value: 'less_than_or_equal' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'items_count' && el(TextControl, {
                            type: 'number',
                            value: cond.value,
                            onChange: (val) => updateCondition(idx, 'value', parseInt(val) || 0)
                        }),

                        cond.type === 'user_role' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: 'Está en la lista', value: 'in_list' },
                                { label: 'No está en la lista', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'user_role' && el('div', { style: { maxHeight: '100px', overflowY: 'auto', border: '1px solid #cbd5e1', padding: '6px', borderRadius: '4px', background: '#fff', width: '180px' } },
                            adminData.roles.map((r) => {
                                const isChecked = (cond.value || []).includes(r.id);
                                return el('div', { key: r.id },
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: isChecked,
                                            onChange: (e) => {
                                                const list = [...(cond.value || [])];
                                                if (e.target.checked) {
                                                    list.push(r.id);
                                                } else {
                                                    const idxOf = list.indexOf(r.id);
                                                    if (idxOf > -1) list.splice(idxOf, 1);
                                                }
                                                updateCondition(idx, 'value', list);
                                            }
                                        }),
                                        ' ' + r.name
                                    )
                                );
                            })
                        ),

                        cond.type === 'user_email' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: 'Coincide con dominio/correo', value: 'in_list' },
                                { label: 'No coincide', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'user_email' && el(TextControl, {
                            placeholder: 'Ej. *@gmail.com, correo@empresa.com',
                            value: Array.isArray(cond.value) ? cond.value.join(', ') : cond.value,
                            onChange: (val) => updateCondition(idx, 'value', val.split(',').map(s => s.trim()))
                        }),

                        cond.type === 'shipping_location' && el(SelectControl, {
                            value: cond.location_type,
                            options: [
                                { label: 'Código de país', value: 'country' },
                                { label: 'Código de estado/departamento', value: 'state' },
                                { label: 'Nombre de ciudad', value: 'city' },
                                { label: 'Código postal', value: 'zip' }
                            ],
                            onChange: (val) => updateCondition(idx, 'location_type', val)
                        }),
                        cond.type === 'shipping_location' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: 'Coincide con la dirección', value: 'in_list' },
                                { label: 'No coincide', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'shipping_location' && el(TextControl, {
                            placeholder: 'Ej. US, CO, NY, 902*',
                            value: Array.isArray(cond.value) ? cond.value.join(', ') : cond.value,
                            onChange: (val) => updateCondition(idx, 'value', val.split(',').map(s => s.trim()))
                        }),

                        // Cart Coupon Applied Condition
                        cond.type === 'cart_coupon' && el(SelectControl, {
                            value: cond.operator || 'applied',
                            options: [
                                { label: 'Está aplicado', value: 'applied' },
                                { label: 'No está aplicado', value: 'not_applied' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'cart_coupon' && el(TextControl, {
                            placeholder: 'Ej. promo50, verano20',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),
                        cond.type === 'cart_coupon' && el('div', { className: 'drw-coupon-schedule-container' },
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Fecha de inicio',
                                    type: 'date',
                                    value: cond.start_date || '',
                                    onChange: (val) => updateCondition(idx, 'start_date', val)
                                }),
                                el(TextControl, {
                                    label: 'Fecha de fin',
                                    type: 'date',
                                    value: cond.end_date || '',
                                    onChange: (val) => updateCondition(idx, 'end_date', val)
                                })
                            ),
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Hora de inicio',
                                    type: 'time',
                                    value: cond.start_time || '',
                                    onChange: (val) => updateCondition(idx, 'start_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Hora de fin',
                                    type: 'time',
                                    value: cond.end_time || '',
                                    onChange: (val) => updateCondition(idx, 'end_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Duración (minutos)',
                                    type: 'number',
                                    value: cond.duration_minutes || '',
                                    help: 'Opcional. Si se define, la duración cuenta desde la fecha/hora de inicio.',
                                    onChange: (val) => updateCondition(idx, 'duration_minutes', parseInt(val) || '')
                                })
                            )
                        ),

                        // Total Cart Items Quantity Condition
                        cond.type === 'cart_items_quantity' && el(SelectControl, {
                            value: cond.operator || 'greater_than_or_equal',
                            options: [
                                { label: '>= Mayor o igual que', value: 'greater_than_or_equal' },
                                { label: '<= Menor o igual que', value: 'less_than_or_equal' },
                                { label: '> Mayor que', value: 'greater_than' },
                                { label: '< Menor que', value: 'less_than' },
                                { label: '== Igual a', value: 'equal' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'cart_items_quantity' && el(TextControl, {
                            type: 'number',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', parseInt(val) || 0)
                        }),

                        // Total Cart Weight Condition
                        cond.type === 'cart_items_weight' && el(SelectControl, {
                            value: cond.operator || 'greater_than_or_equal',
                            options: [
                                { label: '>= Mayor o igual que', value: 'greater_than_or_equal' },
                                { label: '<= Menor o igual que', value: 'less_than_or_equal' },
                                { label: '> Mayor que', value: 'greater_than' },
                                { label: '< Menor que', value: 'less_than' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'cart_items_weight' && el(TextControl, {
                            type: 'number',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', parseFloat(val) || 0)
                        }),

                        // Already On Sale Status Condition
                        cond.type === 'onsale_products' && el(SelectControl, {
                            value: cond.value || 'exclude',
                            options: [
                                { label: 'Excluir productos en oferta', value: 'exclude' },
                                { label: 'Solo productos en oferta', value: 'only' }
                            ],
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Product/Category Combination Condition
                        cond.type === 'product_combination' && el('div', { className: 'drw-combination-wrapper' },
                            el(SelectControl, {
                                value: cond.operator || 'contains_any',
                                options: [
                                    { label: 'Contiene cualquiera de estos', value: 'contains_any' },
                                    { label: 'Contiene todos estos', value: 'contains_all' },
                                    { label: 'No contiene ninguno de estos', value: 'contains_none' }
                                ],
                                onChange: (val) => updateCondition(idx, 'operator', val)
                            }),
                            el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                                el('div', null,
                                    el(ProductSearchMultiSelect, {
                                        label: 'Productos',
                                        selectedIds: cond.product_ids || [],
                                        onChange: (ids) => updateCondition(idx, 'product_ids', ids)
                                    })
                                ),
                                el('div', null,
                                    el('span', { className: 'drw-field-label' }, 'Categorías:'),
                                    el('div', { className: 'drw-checklist-box' },
                                        (adminData.categories || []).map(c => {
                                            const isChecked = (cond.category_ids || []).includes(c.id);
                                            return el('div', { key: c.id, className: 'drw-checkbox-item' },
                                                el('label', null,
                                                    el('input', {
                                                        type: 'checkbox',
                                                        checked: isChecked,
                                                        onChange: (e) => {
                                                            const list = [...(cond.category_ids || [])];
                                                            if (e.target.checked) {
                                                                list.push(c.id);
                                                            } else {
                                                                const idxOf = list.indexOf(c.id);
                                                                if (idxOf > -1) list.splice(idxOf, 1);
                                                            }
                                                            updateCondition(idx, 'category_ids', list);
                                                        }
                                                    }),
                                                    ' ' + c.name
                                                )
                                            );
                                        })
                                    )
                                )
                            )
                        ),

                        // User Logged In Status Condition
                        cond.type === 'user_logged_in' && el(SelectControl, {
                            value: cond.value || 'yes',
                            options: [
                                { label: 'El usuario ha iniciado sesión', value: 'yes' },
                                { label: 'El usuario es invitado (sin sesión)', value: 'no' }
                            ],
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // User List (Specific IDs) Condition
                        cond.type === 'user_list' && el(SelectControl, {
                            value: cond.operator || 'in_list',
                            options: [
                                { label: 'El ID de usuario está en la lista', value: 'in_list' },
                                { label: 'El ID de usuario NO está en la lista', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'user_list' && el(TextControl, {
                            placeholder: 'Ej. 1, 25, 48',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Billing Address City Condition
                        cond.type === 'billing_city' && el(SelectControl, {
                            value: cond.operator || 'in_list',
                            options: [
                                { label: 'Coincide con la ciudad', value: 'in_list' },
                                { label: 'No coincide con la ciudad', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'billing_city' && el(TextControl, {
                            placeholder: 'Ej. Bogotá, Medellín, Cali',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Scheduling Condition
                        cond.type === 'order_date' && el('div', { className: 'drw-order-date-container' },
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Fecha de inicio',
                                    type: 'date',
                                    value: cond.start_date || '',
                                    onChange: (val) => updateCondition(idx, 'start_date', val)
                                }),
                                el(TextControl, {
                                    label: 'Fecha de fin',
                                    type: 'date',
                                    value: cond.end_date || '',
                                    onChange: (val) => updateCondition(idx, 'end_date', val)
                                })
                            ),
                            el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                                el(TextControl, {
                                    label: 'Hora de inicio',
                                    type: 'time',
                                    value: cond.start_time || '',
                                    onChange: (val) => updateCondition(idx, 'start_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Hora de fin',
                                    type: 'time',
                                    value: cond.end_time || '',
                                    onChange: (val) => updateCondition(idx, 'end_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Duración (minutos)',
                                    type: 'number',
                                    value: cond.duration_minutes || '',
                                    help: 'Opcional. Si se define, la duración cuenta desde la fecha/hora de inicio.',
                                    onChange: (val) => updateCondition(idx, 'duration_minutes', parseInt(val) || '')
                                })
                            ),
                            el('div', { style: { marginTop: '8px' } },
                                el('span', { className: 'drw-field-label' }, 'Días de la semana activos:'),
                                el('div', { className: 'drw-days-checkboxes' },
                                    // Stored value stays the English day name (the OrderDate
                                    // condition matches strtolower(date('l')) server-side);
                                    // only the visible abbreviation is translated.
                                    [
                                        { value: 'Monday', label: 'Lun' },
                                        { value: 'Tuesday', label: 'Mar' },
                                        { value: 'Wednesday', label: 'Mié' },
                                        { value: 'Thursday', label: 'Jue' },
                                        { value: 'Friday', label: 'Vie' },
                                        { value: 'Saturday', label: 'Sáb' },
                                        { value: 'Sunday', label: 'Dom' }
                                    ].map(day => {
                                        const isChecked = (cond.weekdays || []).includes(day.value);
                                        return el('label', { key: day.value, className: 'drw-day-checkbox-label' },
                                            el('input', {
                                                type: 'checkbox',
                                                checked: isChecked,
                                                onChange: (e) => {
                                                    const list = [...(cond.weekdays || [])];
                                                    if (e.target.checked) {
                                                        list.push(day.value);
                                                    } else {
                                                        const idxOf = list.indexOf(day.value);
                                                        if (idxOf > -1) list.splice(idxOf, 1);
                                                    }
                                                    updateCondition(idx, 'weekdays', list);
                                                }
                                            }),
                                            ' ' + day.label
                                        );
                                    })
                                )
                            )
                        ),

                        // Customer Purchase History Condition
                        cond.type === 'purchase_history' && el('div', { className: 'drw-history-container' },
                            el(SelectControl, {
                                value: cond.history_metric || 'orders_count',
                                options: [
                                    { label: 'Número total de pedidos', value: 'orders_count' },
                                    { label: 'Monto total gastado ($)', value: 'revenue' },
                                    { label: 'Productos comprados anteriormente', value: 'products_bought' }
                                ],
                                onChange: (val) => {
                                    updateCondition(idx, 'history_metric', val);
                                    if (val === 'products_bought') {
                                        updateCondition(idx, 'operator', 'contains_any');
                                        updateCondition(idx, 'value', []);
                                    } else {
                                        updateCondition(idx, 'operator', 'greater_than_or_equal');
                                        updateCondition(idx, 'value', 0);
                                    }
                                }
                            }),
                            (cond.history_metric === 'orders_count' || cond.history_metric === 'revenue' || !cond.history_metric) && el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                                el(SelectControl, {
                                    value: cond.operator || 'greater_than_or_equal',
                                    options: [
                                        { label: '>= Mayor o igual que', value: 'greater_than_or_equal' },
                                        { label: '<= Menor o igual que', value: 'less_than_or_equal' }
                                    ],
                                    onChange: (val) => updateCondition(idx, 'operator', val)
                                }),
                                el(TextControl, {
                                    type: 'number',
                                    value: cond.value || '',
                                    onChange: (val) => updateCondition(idx, 'value', parseFloat(val) || 0)
                                })
                            ),
                            cond.history_metric === 'products_bought' && el('div', { style: { marginTop: '8px' } },
                                el(SelectControl, {
                                    value: cond.operator || 'contains_any',
                                    options: [
                                        { label: 'Contiene cualquiera de estos', value: 'contains_any' },
                                        { label: 'Contiene todos estos', value: 'contains_all' }
                                    ],
                                    onChange: (val) => updateCondition(idx, 'operator', val)
                                }),
                                el(ProductSearchMultiSelect, {
                                    label: 'Selecciona productos',
                                    selectedIds: Array.isArray(cond.value) ? cond.value : [],
                                    onChange: (ids) => updateCondition(idx, 'value', ids)
                                })
                            )
                        ),

                        el(Button, { className: 'drw-remove-btn', onClick: () => removeCondition(idx) }, 'Eliminar')
                    );
                }),
                el(Button, {
                    className: 'drw-secondary-btn',
                    onClick: addCondition,
                    disabled: enabledConditionOptions.length === 0
                }, '+ Agregar condición'),
                enabledConditionOptions.length === 0 && el('p', { className: 'drw-help-text', style: { marginTop: '8px' } },
                    'Todas las condiciones están deshabilitadas en Configuración Global → Condiciones y Filtros Habilitados.')
            ),

            // Save / Cancel Actions
            el('div', { style: { display: 'flex', gap: '12px', marginTop: '24px' } },
                el(Button, { className: 'drw-primary-btn', onClick: onSave }, 'Guardar configuración de reglas'),
                el(Button, { className: 'drw-secondary-btn', onClick: onCancel }, 'Cancelar')
            )
        );
    }

    /**
     * Color swatch for the theme-preset preview. Decorative + informative:
     * role="img" + aria-label so the hex is announced, title for the tooltip.
     */
    function ThemeSwatch({ name, hex }) {
        return el('span', {
            className: 'drw-swatch',
            role: 'img',
            title: name + ': ' + hex,
            'aria-label': name + ' ' + hex,
            style: { background: hex }
        });
    }

    /**
     * Global Settings Screen.
     *
     * Reorganised from one long vertical scroll into a wp.components TabPanel
     * with five tabs (Tipos de descuento / Comportamiento / Características /
     * Apariencia / Condiciones y Filtros Habilitados). The per-tab CONTENT is
     * the same as the old sections — only the layout changed — except the new
     * Condiciones tab, which drives the Rule Editor's condition-type filtering
     * (see onConditionsChange / RuleEditor.conditionsSettings), and the
     * Apariencia tab, which now previews theme palettes and edits the storefront
     * badge colours that public-style.css consumes via CSS variables.
     */
    function GlobalSettings({ onBack, onConditionsChange }) {
        const [settings, setSettings]           = useState(null);
        const [loading, setLoading]             = useState(true);
        const [saving, setSaving]               = useState(false);
        const [notice, setNotice]               = useState(null);
        // JSON snapshot of the last-saved settings — powers the "Cambios sin
        // guardar" indicator (compare against the live `settings`).
        const [savedSnapshot, setSavedSnapshot] = useState('');
        // Palettes for the swatch preview (GET /settings/themes). null = loading.
        const [themePresets, setThemePresets]   = useState(null);
        // [{id,label,enabled}] for the Condiciones tab (GET /settings/conditions,
        // labels already localised to Spanish server-side). null = loading.
        const [conditionMeta, setConditionMeta] = useState(null);

        // Derive REST path from localized URL
        const settingsPath = (adminData.settingsApiRoot || '')
            .replace(/^https?:\/\/[^/]+\/wp-json/, '') || '/drw/v1/settings';

        useEffect(() => {
            apiFetch({ path: settingsPath })
                .then((data) => {
                    setSettings(data);
                    setSavedSnapshot(JSON.stringify(data));
                    setLoading(false);
                })
                .catch((err) => {
                    setNotice({ type: 'error', msg: err.message || 'Error al cargar la configuración.' });
                    setLoading(false);
                });
            // Theme palettes for the Apariencia swatch preview.
            apiFetch({ path: '/drw/v1/settings/themes' })
                .then((data) => setThemePresets(data && typeof data === 'object' ? data : {}))
                .catch(() => setThemePresets({}));
            // Condition labels for the "Condiciones y Filtros Habilitados" tab.
            apiFetch({ path: '/drw/v1/settings/conditions' })
                .then((data) => setConditionMeta(Array.isArray(data) ? data : []))
                .catch(() => setConditionMeta([]));
        }, []);

        // Immutable deep-set via dot-path
        const updateNested = (obj, keys, value) => {
            const copy = Object.assign({}, obj);
            if (keys.length === 1) { copy[keys[0]] = value; return copy; }
            copy[keys[0]] = updateNested(copy[keys[0]] || {}, keys.slice(1), value);
            return copy;
        };
        const updateSetting = (path, value) =>
            setSettings((prev) => updateNested(JSON.parse(JSON.stringify(prev)), path.split('.'), value));

        // Push the freshly-persisted conditions map up to DrwApp so the Rule
        // Editor's filtering reflects the save without a page reload.
        const syncConditions = (fresh) => {
            if (typeof onConditionsChange === 'function' && fresh && fresh.conditions) {
                onConditionsChange(fresh.conditions);
            }
        };

        const handleSave = () => {
            setSaving(true);
            apiFetch({ path: settingsPath, method: 'POST', data: settings })
                .then((data) => {
                    const fresh = data && typeof data === 'object' && data.discount_types ? data : settings;
                    setSettings(fresh);
                    setSavedSnapshot(JSON.stringify(fresh));
                    syncConditions(fresh);
                    setNotice({ type: 'success', msg: 'Configuración guardada exitosamente.' });
                    setSaving(false);
                })
                .catch((err) => { setNotice({ type: 'error', msg: err.message || 'Error al guardar.' }); setSaving(false); });
        };

        const handleReset = () => {
            // Two-step confirmation for a destructive, irreversible action.
            if (!confirm('¿Restaurar TODA la configuración a los valores por defecto?\n\nSe descartarán tus tipos de descuento, condiciones, comportamiento y apariencia personalizados. Esta acción no se puede deshacer.')) return;
            if (!confirm('Confirmación final: se restaurarán todos los valores por defecto ahora.')) return;
            apiFetch({ path: settingsPath + '/reset', method: 'POST' })
                .then((data) => {
                    setSettings(data);
                    setSavedSnapshot(JSON.stringify(data));
                    syncConditions(data);
                    setNotice({ type: 'success', msg: 'Configuración restaurada a valores por defecto.' });
                })
                .catch((err) => { setNotice({ type: 'error', msg: err.message || 'Error al restaurar.' }); });
        };

        if (loading) {
            return el('div', { style: { textAlign: 'center', padding: '50px' } },
                el(Spinner), el('p', null, 'Cargando configuración...'));
        }
        if (!settings) {
            return el(Notice, { status: 'error' }, 'No se pudo cargar la configuración.');
        }

        const dt = settings.discount_types  || {};
        const rb = settings.rules_behavior  || {};
        const ft = settings.features        || {};
        const th = settings.theme           || {};
        const cc = th.custom_colors         || {};
        const ty = th.typography            || {};
        const sp = th.spacing               || {};

        // Unsaved-changes detection: live settings vs. last-saved snapshot.
        const isDirty = !!savedSnapshot && JSON.stringify(settings) !== savedSnapshot;

        // Selected preset's palette + one-click "apply to custom colors".
        const activePreset = (themePresets && themePresets[th.preset || 'default']) || null;
        const applyPreset = () => {
            if (!activePreset || !activePreset.colors) return;
            setSettings((prev) => {
                const copy = JSON.parse(JSON.stringify(prev));
                copy.theme = copy.theme || {};
                copy.theme.custom_colors = Object.assign({}, copy.theme.custom_colors || {}, activePreset.colors);
                return copy;
            });
        };

        /* ── Panel: Tipos de descuento ─────────────────────────────── */
        const typesPanel = el('div', { className: 'drw-form-section' },
            el('p', { className: 'drw-help-text', style: { marginTop: 0 } }, 'Activa solo los tipos de descuento que quieres ofrecer al crear reglas.'),
            el('div', { className: 'drw-settings-toggles' },
                el(ToggleControl, { label: 'Porcentaje (% Off)',                  checked: !!(dt.percentage  && dt.percentage.enabled),  onChange: (v) => updateSetting('discount_types.percentage.enabled',  v) }),
                el(ToggleControl, { label: 'Precio Fijo ($ Off)',                 checked: !!(dt.fixed       && dt.fixed.enabled),        onChange: (v) => updateSetting('discount_types.fixed.enabled',       v) }),
                el(ToggleControl, { label: 'Bulk / Escalonado por cantidad',      checked: !!(dt.bulk        && dt.bulk.enabled),         onChange: (v) => updateSetting('discount_types.bulk.enabled',        v) }),
                el(ToggleControl, { label: 'BOGO – Compra X, Lleva Y',           checked: !!(dt.bogo        && dt.bogo.enabled),         onChange: (v) => updateSetting('discount_types.bogo.enabled',        v) }),
                el(ToggleControl, { label: 'Bundle / Paquete a precio especial', checked: !!(dt.bundle_set  && dt.bundle_set.enabled),   onChange: (v) => updateSetting('discount_types.bundle_set.enabled',  v) }),
                el(ToggleControl, { label: 'Envío Gratis',                        checked: !!(dt.free_shipping && dt.free_shipping.enabled), onChange: (v) => updateSetting('discount_types.free_shipping.enabled', v) })
            ),
            dt.bulk && dt.bulk.enabled && el('div', { style: { marginTop: '8px', maxWidth: '200px' } },
                el(TextControl, {
                    label: 'Máximo de niveles Bulk',
                    type: 'number',
                    value: String(dt.bulk.max_tiers || 10),
                    onChange: (v) => updateSetting('discount_types.bulk.max_tiers', parseInt(v, 10) || 10)
                })
            )
        );

        /* ── Panel: Comportamiento ─────────────────────────────────── */
        const behaviorPanel = el('div', { className: 'drw-form-section' },
            el(ToggleControl, {
                label: 'Permitir múltiples descuentos en el mismo carrito',
                checked: !!rb.allow_multiple_discounts,
                onChange: (v) => updateSetting('rules_behavior.allow_multiple_discounts', v)
            }),
            el(SelectControl, {
                label: 'Estrategia de combinación',
                value: rb.combination_strategy || 'sum_best',
                options: [
                    { label: 'Usar el mejor descuento',       value: 'sum_best'       },
                    { label: 'Sumar todos los descuentos',    value: 'sum_all'        },
                    { label: 'Solo el descuento más alto',    value: 'highest_single' }
                ],
                onChange: (v) => updateSetting('rules_behavior.combination_strategy', v)
            }),
            el(SelectControl, {
                label: 'Orden de aplicación de reglas',
                value: rb.apply_order || 'priority',
                options: [
                    { label: 'Por prioridad asignada',  value: 'priority'      },
                    { label: 'Por fecha de creación',   value: 'creation_date' }
                ],
                onChange: (v) => updateSetting('rules_behavior.apply_order', v)
            }),
            el(ToggleControl, {
                label: 'Una regla exclusiva cancela las demás reglas activas',
                checked: !!rb.exclusive_override,
                onChange: (v) => updateSetting('rules_behavior.exclusive_override', v)
            })
        );

        /* ── Panel: Características ──────────────────────────────────── */
        const featuresPanel = el('div', { className: 'drw-form-section' },
            el('div', { className: 'drw-settings-toggles' },
                el(ToggleControl, { label: 'Habilitar programación por fechas (date_from / date_to)', checked: !!ft.enable_scheduling,    onChange: (v) => updateSetting('features.enable_scheduling',    v) }),
                el(ToggleControl, { label: 'Habilitar límites de uso por regla',                      checked: !!ft.enable_usage_limits,  onChange: (v) => updateSetting('features.enable_usage_limits',  v) }),
                el(ToggleControl, { label: 'Mostrar etiquetas de descuento en el catálogo',           checked: !!ft.show_discount_labels, onChange: (v) => updateSetting('features.show_discount_labels', v) }),
                el(ToggleControl, { label: 'Modo debug (registrar en consola del navegador)',          checked: !!ft.enable_debug_mode,    onChange: (v) => updateSetting('features.enable_debug_mode',    v) })
            ),
            el(SelectControl, {
                label: 'Redondeo de precios calculados',
                value: ft.round_prices || 'standard',
                options: [
                    { label: 'Estándar (al más cercano)', value: 'standard' },
                    { label: 'Siempre hacia abajo',       value: 'down'     },
                    { label: 'Siempre hacia arriba',      value: 'up'       },
                    { label: 'Half-up (0.5 sube)',        value: 'half_up'  }
                ],
                onChange: (v) => updateSetting('features.round_prices', v)
            })
        );

        /* ── Panel: Apariencia ──────────────────────────────────────── */
        const appearancePanel = el('div', { className: 'drw-form-section' },
            el(SelectControl, {
                label: 'Tema predefinido',
                value: th.preset || 'default',
                options: [
                    { label: 'Default (Azul moderno)', value: 'default'  },
                    { label: 'Dark (Oscuro)',           value: 'dark'     },
                    { label: 'Colorful (Multicolor)',   value: 'colorful' },
                    { label: 'Minimal (Negro/Blanco)',  value: 'minimal'  }
                ],
                onChange: (v) => updateSetting('theme.preset', v)
            }),
            activePreset && el('div', { className: 'drw-theme-preview' },
                el('div', { className: 'drw-swatch-row' },
                    Object.keys(activePreset.colors || {}).map((k) =>
                        el(ThemeSwatch, { key: k, name: k, hex: activePreset.colors[k] })
                    )
                ),
                el(Button, { className: 'drw-secondary-btn drw-apply-preset-btn', onClick: applyPreset },
                    'Aplicar esta paleta a los colores personalizados')
            ),
            el('p', { className: 'drw-settings-label' }, 'Colores personalizados (formato hex: #RRGGBB)'),
            el('div', { className: 'drw-colors-grid' },
                el(TextControl, { label: 'Primario',        value: cc.primary   || '#3b82f6', onChange: (v) => updateSetting('theme.custom_colors.primary',   v) }),
                el(TextControl, { label: 'Secundario',      value: cc.secondary || '#475569', onChange: (v) => updateSetting('theme.custom_colors.secondary', v) }),
                el(TextControl, { label: 'Éxito',           value: cc.success   || '#16a34a', onChange: (v) => updateSetting('theme.custom_colors.success',   v) }),
                el(TextControl, { label: 'Advertencia',     value: cc.warning   || '#ea580c', onChange: (v) => updateSetting('theme.custom_colors.warning',   v) }),
                el(TextControl, { label: 'Peligro / Error', value: cc.danger    || '#dc2626', onChange: (v) => updateSetting('theme.custom_colors.danger',    v) })
            ),
            el('p', { className: 'drw-settings-label' }, 'Insignias del escaparate (mini-cart y promos destacadas)'),
            el('p', { className: 'drw-help-text', style: { marginTop: 0 } }, 'Estos colores se emiten como variables CSS y los usan las insignias del mini-cart y el shortcode de promociones destacadas en la tienda.'),
            el('div', { className: 'drw-badge-preview' },
                el('span', { className: 'drw-badge-preview-pill', style: { background: cc.badge_enabled_bg || '#dcfce7', color: cc.badge_enabled_text || '#166534' } }, 'Promo activa'),
                el('span', { className: 'drw-badge-preview-pill', style: { background: cc.badge_disabled_bg || '#f1f5f9', color: cc.badge_disabled_text || '#64748b' } }, 'Promo inactiva')
            ),
            el('div', { className: 'drw-colors-grid' },
                el(TextControl, { label: 'Fondo insignia activa',  value: cc.badge_enabled_bg    || '#dcfce7', onChange: (v) => updateSetting('theme.custom_colors.badge_enabled_bg',    v) }),
                el(TextControl, { label: 'Texto insignia activa',  value: cc.badge_enabled_text  || '#166534', onChange: (v) => updateSetting('theme.custom_colors.badge_enabled_text',  v) }),
                el(TextControl, { label: 'Fondo insignia inactiva',value: cc.badge_disabled_bg   || '#f1f5f9', onChange: (v) => updateSetting('theme.custom_colors.badge_disabled_bg',   v) }),
                el(TextControl, { label: 'Texto insignia inactiva',value: cc.badge_disabled_text || '#64748b', onChange: (v) => updateSetting('theme.custom_colors.badge_disabled_text', v) })
            ),
            el('p', { className: 'drw-settings-label' }, 'Tipografía'),
            el('div', { className: 'drw-colors-grid' },
                el(SelectControl, {
                    label: 'Familia tipográfica',
                    value: ty.font_family || 'system-ui',
                    options: [
                        { label: 'System UI (por defecto)', value: 'system-ui'           },
                        { label: 'Arial',                   value: 'Arial, sans-serif'   },
                        { label: 'Inter',                   value: "'Inter', sans-serif"  },
                        { label: 'Roboto',                  value: "'Roboto', sans-serif" }
                    ],
                    onChange: (v) => updateSetting('theme.typography.font_family', v)
                }),
                el(TextControl, { label: 'Tamaño fuente base (px)',   type: 'number', value: String(ty.base_size    || 14), onChange: (v) => updateSetting('theme.typography.base_size',    parseInt(v, 10) || 14) }),
                el(TextControl, { label: 'Tamaño fuente título (px)', type: 'number', value: String(ty.heading_size || 20), onChange: (v) => updateSetting('theme.typography.heading_size', parseInt(v, 10) || 20) })
            ),
            el('p', { className: 'drw-settings-label' }, 'Espaciado'),
            el('div', { className: 'drw-colors-grid' },
                el(TextControl, { label: 'Padding base (px)',  type: 'number', value: String(sp.padding_base  || 16), onChange: (v) => updateSetting('theme.spacing.padding_base',  parseInt(v, 10) || 16) }),
                el(TextControl, { label: 'Border radius (px)', type: 'number', value: String(sp.border_radius || 8),  onChange: (v) => updateSetting('theme.spacing.border_radius', parseInt(v, 10) || 8)  }),
                el(SelectControl, {
                    label: 'Nivel de sombra',
                    value: sp.shadow_level || 'medium',
                    options: [
                        { label: 'Sin sombra',   value: 'none'   },
                        { label: 'Suave',        value: 'light'  },
                        { label: 'Medio',        value: 'medium' },
                        { label: 'Pronunciado',  value: 'heavy'  }
                    ],
                    onChange: (v) => updateSetting('theme.spacing.shadow_level', v)
                })
            )
        );

        /* ── Panel: Condiciones y Filtros Habilitados ──────────────── */
        const conditionsMap = settings.conditions || {};
        const conditionsPanel = el('div', { className: 'drw-form-section' },
            el('p', { className: 'drw-help-text', style: { marginTop: 0 } }, 'Desactiva una condición para ocultarla del selector al crear o editar reglas. Las reglas que ya la usan la conservan.'),
            conditionMeta === null
                ? el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
                    el(Spinner), el('span', null, 'Cargando condiciones...'))
                : el('div', { className: 'drw-settings-toggles' },
                    conditionMeta.map((c) => el(ToggleControl, {
                        key: c.id,
                        label: c.label,
                        checked: !!(conditionsMap[c.id] && conditionsMap[c.id].enabled),
                        onChange: (v) => updateSetting('conditions.' + c.id + '.enabled', v)
                    }))
                )
        );

        const panels = {
            types:      typesPanel,
            behavior:   behaviorPanel,
            features:   featuresPanel,
            appearance: appearancePanel,
            conditions: conditionsPanel
        };

        return el('div', { className: 'drw-settings-wrap' },

            notice && el(Notice, { status: notice.type, isDismissible: true, onDismiss: () => setNotice(null) }, notice.msg),

            el(TabPanel, {
                className: 'drw-settings-tabs',
                activeClass: 'is-active',
                tabs: [
                    { name: 'types',      title: 'Tipos de descuento' },
                    { name: 'behavior',   title: 'Comportamiento' },
                    { name: 'features',   title: 'Características' },
                    { name: 'appearance', title: 'Apariencia' },
                    { name: 'conditions', title: 'Condiciones y Filtros Habilitados' }
                ]
            }, (tab) => panels[tab.name] || null),

            /* ── Acciones ──────────────────────────────────────────── */
            el('div', { className: 'drw-settings-actions' },
                el('div', { className: 'drw-settings-actions-main' },
                    el(Button, { className: 'drw-primary-btn', onClick: handleSave, disabled: saving || !isDirty },
                        saving ? 'Guardando...' : 'Guardar cambios'),
                    el(Button, { className: 'drw-secondary-btn', onClick: onBack }, '← Volver a Reglas'),
                    isDirty && el('span', { className: 'drw-unsaved-indicator', role: 'status' },
                        el('span', { className: 'drw-unsaved-dot', 'aria-hidden': 'true' }),
                        'Cambios sin guardar')
                ),
                el('div', { className: 'drw-settings-danger-zone' },
                    el('span', { className: 'drw-danger-zone-label' }, 'Zona de riesgo'),
                    el(Button, { className: 'drw-danger-btn', onClick: handleReset }, 'Restaurar valores por defecto')
                )
            )
        );
    }

    // Mount when DOM is ready
    $(document).ready(function() {
        const container = document.getElementById('drw-admin-app');
        if (container) {
            render(el(DrwApp), container);
        }
    });

})(jQuery);
