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
        nonce: '',
        products: [],
        categories: [],
        roles: []
    };

    /**
     * Main App Component
     */
    function DrwApp() {
        const [screen, setScreen] = useState('list'); // 'list' or 'edit'
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
                    category_ids: []
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
                el('h2', { className: 'drw-title' }, screen === 'list' ? 'Active Rules Dashboard' : (editingRule.id ? 'Edit Discount Rule' : 'Create New Discount Rule')),
                screen === 'list' && el(Button, { className: 'drw-primary-btn', onClick: handleAddRule }, '+ Create Rule')
            ),

            // Screens
            screen === 'list' 
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
                el('p', { style: { fontSize: '16px' } }, 'No rules found. Click "Create Rule" to configure your first discount!'),
            );
        }

        return el('div', null,
            rules.map((rule) => {
                const adjType = rule.adjustments ? rule.adjustments.type : 'percentage';
                const adjVal = rule.adjustments ? rule.adjustments.value : 0;
                let detailsText = '';
                
                if (adjType === 'percentage') {
                    detailsText = `${adjVal}% Off`;
                } else if (adjType === 'fixed') {
                    detailsText = `$${adjVal} Flat Off`;
                } else if (adjType === 'bulk') {
                    detailsText = 'Bulk Tiered Discount';
                }

                return el('div', { key: rule.id, className: 'drw-rule-card' },
                    el('div', { className: 'drw-rule-info' },
                        el('h4', { className: 'drw-rule-name' }, 
                            rule.title,
                            el('span', { className: `drw-badge ${rule.enabled ? 'drw-badge-active' : 'drw-badge-inactive'}` }, rule.enabled ? 'Enabled' : 'Disabled')
                        ),
                        el('div', { className: 'drw-rule-meta' },
                            el('span', null, `Priority: ${rule.priority}`),
                            el('span', null, `Target: ${rule.apply_to.replace('_', ' ')}`),
                            el('span', { className: 'drw-badge drw-badge-type' }, detailsText)
                        )
                    ),
                    el('div', { className: 'drw-rule-actions' },
                        el(ToggleControl, {
                            checked: rule.enabled,
                            onChange: () => onToggle(rule)
                        }),
                        el(Button, { className: 'drw-secondary-btn', onClick: () => onEdit(rule) }, 'Edit'),
                        el(Button, { className: 'drw-remove-btn', onClick: () => onDelete(rule.id) }, 'Delete')
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
                    el('p', { style: { fontWeight: '600', marginBottom: '6px' } }, 'Select Target Products:'),
                    el('div', { style: { maxHeight: '150px', overflowY: 'auto', border: '1px solid #cbd5e1', padding: '10px', borderRadius: '6px', background: '#fff' } },
                        adminData.products.map((prod) => {
                            const isChecked = (rule.filters.product_ids || []).includes(prod.id);
                            return el('div', { key: prod.id, style: { marginBottom: '6px' } },
                                el('label', null,
                                    el('input', {
                                        type: 'checkbox',
                                        checked: isChecked,
                                        style: { marginRight: '8px' },
                                        onChange: (e) => {
                                            const list = [...(rule.filters.product_ids || [])];
                                            if (e.target.checked) {
                                                list.push(prod.id);
                                            } else {
                                                const idx = list.indexOf(prod.id);
                                                if (idx > -1) list.splice(idx, 1);
                                            }
                                            updateFilters('product_ids', list);
                                        }
                                    }),
                                    prod.name
                                )
                            );
                        })
                    )
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
                )
            ),

            // Section 3: Pricing Adjustments
            el('div', { className: 'drw-form-section' },
                el('h3', null, 'Pricing Adjustments'),
                el(SelectControl, {
                    label: 'Discount Type',
                    value: rule.adjustments.type,
                    options: [
                        { label: 'Percentage Discount', value: 'percentage' },
                        { label: 'Fixed Price Discount', value: 'fixed' },
                        { label: 'Bulk Tiered Discount', value: 'bulk' }
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
                                { label: 'Shipping Address', value: 'shipping_location' }
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

    // Mount when DOM is ready
    $(document).ready(function() {
        const container = document.getElementById('drw-admin-app');
        if (container) {
            render(el(DrwApp), container);
        }
    });

})(jQuery);
