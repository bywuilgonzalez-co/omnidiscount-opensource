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
        Spinner 
    } = wp.components;
    
    const apiFetch = wp.apiFetch;

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
                        setError(err.message || 'Could not search products.');
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
                || { id, name: `Product #${id}`, sku: '' };
        });

        return el('div', { className: 'drw-product-search' },
            el(TextControl, {
                label,
                type: 'search',
                value: search,
                help: help || 'Type at least 2 characters to search the full WooCommerce catalog.',
                placeholder: 'Search products by name or SKU...',
                onChange: setSearch
            }),
            loading && el('div', { className: 'drw-product-search-status' }, el(Spinner), el('span', null, 'Searching products...')),
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
                        }, disabled ? `${productLabel(product)} - selected` : productLabel(product))
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
                            label: `Remove ${product.name}`
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
                    setErrorMsg(err.message || 'Error fetching rules.');
                    setLoading(false);
                });
        };

        const handleAddRule = () => {
            setEditingRule({
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
            setScreen('edit');
        };

        const handleEditRule = (rule) => {
            // Deep copy to prevent mutating list state before saving
            setEditingRule(JSON.parse(JSON.stringify(rule)));
            setScreen('edit');
        };

        const handleDeleteRule = (id) => {
            if (!confirm('Are you sure you want to delete this rule?')) {
                return;
            }
            setLoading(true);
            apiFetch({
                path: `/drw/v1/rules/${id}`,
                method: 'DELETE'
            })
            .then(() => {
                showSuccess('Rule deleted successfully.');
                fetchRules();
            })
            .catch((err) => {
                setErrorMsg(err.message || 'Error deleting rule.');
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
                setErrorMsg(err.message || 'Error updating status.');
            });
        };

        const handleSaveRule = () => {
            if (!editingRule.title.trim()) {
                setErrorMsg('Rule title is required.');
                return;
            }
            setLoading(true);
            apiFetch({
                path: '/drw/v1/rules',
                method: 'POST',
                data: editingRule
            })
            .then(() => {
                showSuccess('Rule saved successfully.');
                fetchRules();
                setScreen('list');
            })
            .catch((err) => {
                setErrorMsg(err.message || 'Error saving rule.');
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
                el('p', null, 'Loading rules engine...')
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
                ? el(GlobalSettings, { onBack: () => setScreen('list') })
                : screen === 'promos'
                    ? (window.DrwPromosPage ? el(window.DrwPromosPage, { onBack: () => setScreen('list') }) : el('p', null, 'Loading promos...'))
                    : screen === 'list'
                        ? el(RulesList, { rules, onEdit: handleEditRule, onDelete: handleDeleteRule, onToggle: handleToggleStatus })
                        : el(RuleEditor, { rule: editingRule, setRule: setEditingRule, onSave: handleSaveRule, onCancel: () => setScreen('list') })
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
     * Rule Editor Screen
     */
    function RuleEditor({ rule, setRule, onSave, onCancel }) {
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

        // Add a new condition
        const addCondition = () => {
            const conditions = [...(rule.conditions || [])];
            conditions.push({
                type: 'subtotal', // subtotal, items_count, user_role, user_email, shipping_location
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
            el('div', { className: 'drw-form-section' },
                el('h3', null, 'General Configuration'),
                el(TextControl, {
                    label: 'Rule Title',
                    value: rule.title,
                    onChange: (val) => updateRuleField('title', val),
                    placeholder: 'e.g. Summer Storewide Clearance 15%'
                }),
                el('div', { className: 'drw-flex-row' },
                    el(TextControl, {
                        label: 'Priority',
                        type: 'number',
                        value: rule.priority,
                        onChange: (val) => updateRuleField('priority', parseInt(val) || 10)
                    }),
                    el('div', { style: { paddingTop: '28px' } },
                        el(ToggleControl, {
                            label: 'Exclusive (stops other rules from applying)',
                            checked: rule.exclusive,
                            onChange: (val) => updateRuleField('exclusive', val)
                        })
                    )
                )
            ),

            // Section 2: Target Scope
            el('div', { className: 'drw-form-section' },
                el('h3', null, 'Product Filtering (Apply to)'),
                el(SelectControl, {
                    label: 'Apply rule to:',
                    value: rule.apply_to,
                    options: [
                        { label: 'All Products', value: 'all_products' },
                        { label: 'Specific Products Only', value: 'specific_products' },
                        { label: 'Specific Categories Only', value: 'specific_categories' }
                    ],
                    onChange: (val) => updateRuleField('apply_to', val)
                }),

                // Specific Products Select
                rule.apply_to === 'specific_products' && el('div', { style: { marginTop: '12px' } },
                    el(ProductSearchMultiSelect, {
                        label: 'Select Target Products',
                        selectedIds: rule.filters.product_ids || [],
                        onChange: (ids) => updateFilters('product_ids', ids)
                    })
                ),

                // Specific Categories Select
                rule.apply_to === 'specific_categories' && el('div', { style: { marginTop: '12px' } },
                    el('p', { style: { fontWeight: '600', marginBottom: '6px' } }, 'Select Target Categories:'),
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
                    el('h4', null, 'Exclusions'),
                    el('p', { className: 'drw-help-text' }, 'Exclude products or categories from this rule even when they match the selected target scope.'),
                    el(ProductSearchMultiSelect, {
                        label: 'Exclude Products',
                        selectedIds: rule.filters.exclude_product_ids || [],
                        help: 'Products selected here will never receive this rule discount.',
                        onChange: (ids) => updateFilters('exclude_product_ids', ids)
                    }),
                    el('div', { style: { marginTop: '12px' } },
                        el('span', { className: 'drw-field-label' }, 'Exclude Categories:'),
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
            el('div', { className: 'drw-form-section' },
                el('h3', null, 'Pricing Adjustments'),
                el(SelectControl, {
                    label: 'Discount Type',
                    value: rule.adjustments.type === 'bundle' ? 'bundle_set' : rule.adjustments.type,
                    options: [
                        { label: 'Percentage Discount', value: 'percentage' },
                        { label: 'Fixed Price Discount', value: 'fixed' },
                        { label: 'Bulk Tiered Discount', value: 'bulk' },
                        { label: 'BOGO Buy X Get Y', value: 'bogo' },
                        { label: 'Free Shipping', value: 'free_shipping' },
                        { label: 'Bundle Set Pricing', value: 'bundle_set' }
                    ],
                    onChange: (val) => updateAdjustments('type', val)
                }),

                // Single values
                (rule.adjustments.type === 'percentage' || rule.adjustments.type === 'fixed') && el(TextControl, {
                    label: rule.adjustments.type === 'percentage' ? 'Percentage Value (%)' : 'Fixed Discount Value ($)',
                    type: 'number',
                    value: rule.adjustments.value,
                    onChange: (val) => updateAdjustments('value', parseFloat(val) || 0)
                }),

                // BOGO parameters
                rule.adjustments.type === 'bogo' && el('div', { className: 'drw-bogo-container', style: { marginTop: '12px' } },
                    el('div', { className: 'drw-flex-row' },
                        el(TextControl, {
                            label: 'Buy Qty',
                            type: 'number',
                            value: rule.adjustments.buy_qty || '',
                            onChange: (val) => updateAdjustments('buy_qty', parseInt(val) || 1)
                        }),
                        el(ProductSearchMultiSelect, {
                            label: 'Get Product Selection',
                            selectedIds: rule.adjustments.get_products || (rule.adjustments.get_product_id ? [rule.adjustments.get_product_id] : []),
                            help: 'Search and select one or more products to discount as the Get items.',
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
                            label: 'Get Qty',
                            type: 'number',
                            value: rule.adjustments.get_qty || '',
                            onChange: (val) => updateAdjustments('get_qty', parseInt(val) || 1)
                        })
                    ),
                    el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                        el(SelectControl, {
                            label: 'BOGO Discount Type',
                            value: rule.adjustments.discount_type || rule.adjustments.bogo_discount_type || 'free',
                            options: [
                                { label: 'Free Product', value: 'free' },
                                { label: 'Percentage Discount', value: 'percentage' },
                                { label: 'Fixed Price Discount', value: 'fixed' }
                            ],
                            onChange: (val) => updateAdjustments('discount_type', val)
                        }),
                        ((rule.adjustments.discount_type || rule.adjustments.bogo_discount_type) === 'percentage' || (rule.adjustments.discount_type || rule.adjustments.bogo_discount_type) === 'fixed') && el(TextControl, {
                            label: 'BOGO Discount Value',
                            type: 'number',
                            value: rule.adjustments.discount_value || rule.adjustments.bogo_value || '',
                            onChange: (val) => updateAdjustments('discount_value', parseFloat(val) || 0)
                        })
                    )
                ),

                // Bundle parameters
                (rule.adjustments.type === 'bundle_set' || rule.adjustments.type === 'bundle') && el('div', { className: 'drw-bundle-container', style: { marginTop: '12px' } },
                    el(TextControl, {
                        label: 'Bundle Set Price Value ($)',
                        type: 'number',
                        value: rule.adjustments.bundle_price || rule.adjustments.set_price || '',
                        onChange: (val) => updateAdjustments('bundle_price', parseFloat(val) || 0)
                    })
                ),

                // Tiered values
                rule.adjustments.type === 'bulk' && el('div', { style: { marginTop: '12px' } },
                    el('p', { style: { fontWeight: '600' } }, 'Quantity Tiers:'),
                    el('table', { className: 'drw-tier-table' },
                        el('thead', null,
                            el('tr', null,
                                el('th', null, 'Min Qty'),
                                el('th', null, 'Max Qty'),
                                el('th', null, 'Discount Type'),
                                el('th', null, 'Value'),
                                el('th', null, 'Actions')
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
                                        placeholder: 'Infinite',
                                        style: { width: '80px' },
                                        value: tier.max,
                                        onChange: (e) => updateTier(idx, 'max', e.target.value === '' ? '' : parseInt(e.target.value))
                                    })),
                                    el('td', null, el('select', {
                                        value: tier.type,
                                        onChange: (e) => updateTier(idx, 'type', e.target.value)
                                    },
                                        el('option', { value: 'percentage' }, 'Percentage Off'),
                                        el('option', { value: 'fixed' }, 'Fixed Off')
                                    )),
                                    el('td', null, el('input', {
                                        type: 'number',
                                        style: { width: '80px' },
                                        value: tier.value,
                                        onChange: (e) => updateTier(idx, 'value', parseFloat(e.target.value) || 0)
                                    })),
                                    el('td', null, el(Button, { className: 'drw-remove-btn', onClick: () => removeTier(idx) }, 'Remove'))
                                );
                            })
                        )
                    ),
                    el(Button, { className: 'drw-secondary-btn', onClick: addTier }, '+ Add Tier')
                )
            ),

            // Section 4: Rules Conditions
            el('div', { className: 'drw-form-section' },
                el('h3', null, 'Conditions Checklist'),
                (rule.conditions || []).map((cond, idx) => {
                    return el('div', { key: idx, className: 'drw-condition-row' },
                        // Condition type selector
                        el(SelectControl, {
                            value: cond.type,
                            options: [
                                { label: 'Cart Subtotal', value: 'subtotal' },
                                { label: 'Cart Item Count', value: 'items_count' },
                                { label: 'User Role', value: 'user_role' },
                                { label: 'User Email', value: 'user_email' },
                                { label: 'Shipping Address', value: 'shipping_location' },
                                { label: 'Cart Coupon Applied', value: 'cart_coupon' },
                                { label: 'Total Cart Items Quantity', value: 'cart_items_quantity' },
                                { label: 'Total Cart Weight', value: 'cart_items_weight' },
                                { label: 'Already On Sale Status', value: 'onsale_products' },
                                { label: 'Product/Category Combination', value: 'product_combination' },
                                { label: 'User Logged In Status', value: 'user_logged_in' },
                                { label: 'User List (Specific IDs)', value: 'user_list' },
                                { label: 'Billing Address City', value: 'billing_city' },
                                { label: 'Scheduling (Dates/Times/Days)', value: 'order_date' },
                                { label: 'Customer Purchase History', value: 'purchase_history' }
                            ],
                            onChange: (val) => updateCondition(idx, 'type', val)
                        }),

                        // Render parameters based on type
                        cond.type === 'subtotal' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: '>= Greater Than or Equal', value: 'greater_than_or_equal' },
                                { label: '<= Less Than or Equal', value: 'less_than_or_equal' },
                                { label: '> Greater Than', value: 'greater_than' },
                                { label: '< Less Than', value: 'less_than' }
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
                                { label: 'Total items quantity', value: 'total_quantity' },
                                { label: 'Distinct line items count', value: 'line_items_count' }
                            ],
                            onChange: (val) => updateCondition(idx, 'check_type', val)
                        }),
                        cond.type === 'items_count' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: '>= Greater Than or Equal', value: 'greater_than_or_equal' },
                                { label: '<= Less Than or Equal', value: 'less_than_or_equal' }
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
                                { label: 'Is In List', value: 'in_list' },
                                { label: 'Is Not In List', value: 'not_in_list' }
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
                                { label: 'Matches Domain/Email', value: 'in_list' },
                                { label: 'Does Not Match', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'user_email' && el(TextControl, {
                            placeholder: 'e.g. *@gmail.com, test@corp.com',
                            value: Array.isArray(cond.value) ? cond.value.join(', ') : cond.value,
                            onChange: (val) => updateCondition(idx, 'value', val.split(',').map(s => s.trim()))
                        }),

                        cond.type === 'shipping_location' && el(SelectControl, {
                            value: cond.location_type,
                            options: [
                                { label: 'Country Code', value: 'country' },
                                { label: 'State Code', value: 'state' },
                                { label: 'City Name', value: 'city' },
                                { label: 'Zip/Postcode', value: 'zip' }
                            ],
                            onChange: (val) => updateCondition(idx, 'location_type', val)
                        }),
                        cond.type === 'shipping_location' && el(SelectControl, {
                            value: cond.operator,
                            options: [
                                { label: 'Matches Address', value: 'in_list' },
                                { label: 'Does Not Match', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'shipping_location' && el(TextControl, {
                            placeholder: 'e.g. US, CA, NY, 902*',
                            value: Array.isArray(cond.value) ? cond.value.join(', ') : cond.value,
                            onChange: (val) => updateCondition(idx, 'value', val.split(',').map(s => s.trim()))
                        }),

                        // Cart Coupon Applied Condition
                        cond.type === 'cart_coupon' && el(SelectControl, {
                            value: cond.operator || 'applied',
                            options: [
                                { label: 'Is Applied', value: 'applied' },
                                { label: 'Is Not Applied', value: 'not_applied' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'cart_coupon' && el(TextControl, {
                            placeholder: 'e.g. promo50, summer20',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),
                        cond.type === 'cart_coupon' && el('div', { className: 'drw-coupon-schedule-container' },
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Start Date',
                                    type: 'date',
                                    value: cond.start_date || '',
                                    onChange: (val) => updateCondition(idx, 'start_date', val)
                                }),
                                el(TextControl, {
                                    label: 'End Date',
                                    type: 'date',
                                    value: cond.end_date || '',
                                    onChange: (val) => updateCondition(idx, 'end_date', val)
                                })
                            ),
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Start Time',
                                    type: 'time',
                                    value: cond.start_time || '',
                                    onChange: (val) => updateCondition(idx, 'start_time', val)
                                }),
                                el(TextControl, {
                                    label: 'End Time',
                                    type: 'time',
                                    value: cond.end_time || '',
                                    onChange: (val) => updateCondition(idx, 'end_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Duration (minutes)',
                                    type: 'number',
                                    value: cond.duration_minutes || '',
                                    help: 'Optional. If set, duration starts from the start date/time.',
                                    onChange: (val) => updateCondition(idx, 'duration_minutes', parseInt(val) || '')
                                })
                            )
                        ),

                        // Total Cart Items Quantity Condition
                        cond.type === 'cart_items_quantity' && el(SelectControl, {
                            value: cond.operator || 'greater_than_or_equal',
                            options: [
                                { label: '>= Greater Than or Equal', value: 'greater_than_or_equal' },
                                { label: '<= Less Than or Equal', value: 'less_than_or_equal' },
                                { label: '> Greater Than', value: 'greater_than' },
                                { label: '< Less Than', value: 'less_than' },
                                { label: '== Equal', value: 'equal' }
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
                                { label: '>= Greater Than or Equal', value: 'greater_than_or_equal' },
                                { label: '<= Less Than or Equal', value: 'less_than_or_equal' },
                                { label: '> Greater Than', value: 'greater_than' },
                                { label: '< Less Than', value: 'less_than' }
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
                                { label: 'Exclude On-Sale Products', value: 'exclude' },
                                { label: 'Only On-Sale Products', value: 'only' }
                            ],
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Product/Category Combination Condition
                        cond.type === 'product_combination' && el('div', { className: 'drw-combination-wrapper' },
                            el(SelectControl, {
                                value: cond.operator || 'contains_any',
                                options: [
                                    { label: 'Contains Any of these', value: 'contains_any' },
                                    { label: 'Contains All of these', value: 'contains_all' },
                                    { label: 'Contains None of these', value: 'contains_none' }
                                ],
                                onChange: (val) => updateCondition(idx, 'operator', val)
                            }),
                            el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                                el('div', null,
                                    el(ProductSearchMultiSelect, {
                                        label: 'Products',
                                        selectedIds: cond.product_ids || [],
                                        onChange: (ids) => updateCondition(idx, 'product_ids', ids)
                                    })
                                ),
                                el('div', null,
                                    el('span', { className: 'drw-field-label' }, 'Categories:'),
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
                                { label: 'User Is Logged In', value: 'yes' },
                                { label: 'User Is Guest (Not Logged In)', value: 'no' }
                            ],
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // User List (Specific IDs) Condition
                        cond.type === 'user_list' && el(SelectControl, {
                            value: cond.operator || 'in_list',
                            options: [
                                { label: 'User ID is in list', value: 'in_list' },
                                { label: 'User ID is NOT in list', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'user_list' && el(TextControl, {
                            placeholder: 'e.g. 1, 25, 48',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Billing Address City Condition
                        cond.type === 'billing_city' && el(SelectControl, {
                            value: cond.operator || 'in_list',
                            options: [
                                { label: 'Matches City', value: 'in_list' },
                                { label: 'Does Not Match City', value: 'not_in_list' }
                            ],
                            onChange: (val) => updateCondition(idx, 'operator', val)
                        }),
                        cond.type === 'billing_city' && el(TextControl, {
                            placeholder: 'e.g. New York, London, Paris',
                            value: cond.value || '',
                            onChange: (val) => updateCondition(idx, 'value', val)
                        }),

                        // Scheduling Condition
                        cond.type === 'order_date' && el('div', { className: 'drw-order-date-container' },
                            el('div', { className: 'drw-flex-row' },
                                el(TextControl, {
                                    label: 'Start Date',
                                    type: 'date',
                                    value: cond.start_date || '',
                                    onChange: (val) => updateCondition(idx, 'start_date', val)
                                }),
                                el(TextControl, {
                                    label: 'End Date',
                                    type: 'date',
                                    value: cond.end_date || '',
                                    onChange: (val) => updateCondition(idx, 'end_date', val)
                                })
                            ),
                            el('div', { className: 'drw-flex-row', style: { marginTop: '8px' } },
                                el(TextControl, {
                                    label: 'Start Time',
                                    type: 'time',
                                    value: cond.start_time || '',
                                    onChange: (val) => updateCondition(idx, 'start_time', val)
                                }),
                                el(TextControl, {
                                    label: 'End Time',
                                    type: 'time',
                                    value: cond.end_time || '',
                                    onChange: (val) => updateCondition(idx, 'end_time', val)
                                }),
                                el(TextControl, {
                                    label: 'Duration (minutes)',
                                    type: 'number',
                                    value: cond.duration_minutes || '',
                                    help: 'Optional. If set, duration starts from the start date/time.',
                                    onChange: (val) => updateCondition(idx, 'duration_minutes', parseInt(val) || '')
                                })
                            ),
                            el('div', { style: { marginTop: '8px' } },
                                el('span', { className: 'drw-field-label' }, 'Active Days of Week:'),
                                el('div', { className: 'drw-days-checkboxes' },
                                    ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map(day => {
                                        const isChecked = (cond.weekdays || []).includes(day);
                                        return el('label', { key: day, className: 'drw-day-checkbox-label' },
                                            el('input', {
                                                type: 'checkbox',
                                                checked: isChecked,
                                                onChange: (e) => {
                                                    const list = [...(cond.weekdays || [])];
                                                    if (e.target.checked) {
                                                        list.push(day);
                                                    } else {
                                                        const idxOf = list.indexOf(day);
                                                        if (idxOf > -1) list.splice(idxOf, 1);
                                                    }
                                                    updateCondition(idx, 'weekdays', list);
                                                }
                                            }),
                                            ' ' + day.substring(0, 3)
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
                                    { label: 'Total Orders Count', value: 'orders_count' },
                                    { label: 'Total Amount Spent ($)', value: 'revenue' },
                                    { label: 'Previously Purchased Products', value: 'products_bought' }
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
                                        { label: '>= Greater Than or Equal', value: 'greater_than_or_equal' },
                                        { label: '<= Less Than or Equal', value: 'less_than_or_equal' }
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
                                        { label: 'Contains Any of these', value: 'contains_any' },
                                        { label: 'Contains All of these', value: 'contains_all' }
                                    ],
                                    onChange: (val) => updateCondition(idx, 'operator', val)
                                }),
                                el(ProductSearchMultiSelect, {
                                    label: 'Select Products',
                                    selectedIds: Array.isArray(cond.value) ? cond.value : [],
                                    onChange: (ids) => updateCondition(idx, 'value', ids)
                                })
                            )
                        ),

                        el(Button, { className: 'drw-remove-btn', onClick: () => removeCondition(idx) }, 'Delete')
                    );
                }),
                el(Button, { className: 'drw-secondary-btn', onClick: addCondition }, '+ Add Condition')
            ),

            // Save / Cancel Actions
            el('div', { style: { display: 'flex', gap: '12px', marginTop: '24px' } },
                el(Button, { className: 'drw-primary-btn', onClick: onSave }, 'Save Rules Configuration'),
                el(Button, { className: 'drw-secondary-btn', onClick: onCancel }, 'Cancel')
            )
        );
    }

    /**
     * Global Settings Screen
     */
    function GlobalSettings({ onBack }) {
        const [settings, setSettings] = useState(null);
        const [loading, setLoading]   = useState(true);
        const [saving, setSaving]     = useState(false);
        const [notice, setNotice]     = useState(null);

        // Derive REST path from localized URL
        const settingsPath = (adminData.settingsApiRoot || '')
            .replace(/^https?:\/\/[^/]+\/wp-json/, '') || '/drw/v1/settings';

        useEffect(() => {
            apiFetch({ path: settingsPath })
                .then((data) => { setSettings(data); setLoading(false); })
                .catch((err) => {
                    setNotice({ type: 'error', msg: err.message || 'Error al cargar la configuración.' });
                    setLoading(false);
                });
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

        const handleSave = () => {
            setSaving(true);
            apiFetch({ path: settingsPath, method: 'POST', data: settings })
                .then(() => { setNotice({ type: 'success', msg: 'Configuración guardada exitosamente.' }); setSaving(false); })
                .catch((err) => { setNotice({ type: 'error', msg: err.message || 'Error al guardar.' }); setSaving(false); });
        };

        const handleReset = () => {
            if (!confirm('¿Restaurar toda la configuración a valores por defecto? Esta acción no se puede deshacer.')) return;
            apiFetch({ path: settingsPath + '/reset', method: 'POST' })
                .then((data) => { setSettings(data); setNotice({ type: 'success', msg: 'Configuración restaurada a valores por defecto.' }); })
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

        return el('div', { className: 'drw-settings-wrap' },

            notice && el(Notice, { status: notice.type, isDismissible: true, onDismiss: () => setNotice(null) }, notice.msg),

            /* ── 1. Tipos de Descuento ─────────────────────────────── */
            el('div', { className: 'drw-form-section' },
                el('h3', null, '1. Tipos de Descuento Habilitados'),
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
            ),

            /* ── 2. Comportamiento de Reglas ───────────────────────── */
            el('div', { className: 'drw-form-section' },
                el('h3', null, '2. Comportamiento de Reglas'),
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
            ),

            /* ── 3. Características Generales ──────────────────────── */
            el('div', { className: 'drw-form-section' },
                el('h3', null, '3. Características Generales'),
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
            ),

            /* ── 4. Tema y Colores ─────────────────────────────────── */
            el('div', { className: 'drw-form-section' },
                el('h3', null, '4. Tema y Personalización Visual'),
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
                el('p', { className: 'drw-settings-label' }, 'Colores personalizados (formato hex: #RRGGBB)'),
                el('div', { className: 'drw-colors-grid' },
                    el(TextControl, { label: 'Primario',        value: cc.primary   || '#3b82f6', onChange: (v) => updateSetting('theme.custom_colors.primary',   v) }),
                    el(TextControl, { label: 'Secundario',      value: cc.secondary || '#475569', onChange: (v) => updateSetting('theme.custom_colors.secondary', v) }),
                    el(TextControl, { label: 'Éxito',           value: cc.success   || '#16a34a', onChange: (v) => updateSetting('theme.custom_colors.success',   v) }),
                    el(TextControl, { label: 'Advertencia',     value: cc.warning   || '#ea580c', onChange: (v) => updateSetting('theme.custom_colors.warning',   v) }),
                    el(TextControl, { label: 'Peligro / Error', value: cc.danger    || '#dc2626', onChange: (v) => updateSetting('theme.custom_colors.danger',    v) })
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
            ),

            /* ── Acciones ──────────────────────────────────────────── */
            el('div', { className: 'drw-settings-actions' },
                el(Button, { className: 'drw-primary-btn', onClick: handleSave, disabled: saving },
                    saving ? 'Guardando...' : 'Guardar Cambios'),
                el(Button, { className: 'drw-secondary-btn', onClick: handleReset }, 'Restaurar Defaults'),
                el(Button, { className: 'drw-secondary-btn', onClick: onBack }, '← Volver a Reglas')
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
