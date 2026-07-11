<?php
/**
 * Focused smoke test for the one-click discount-rule template gallery.
 *
 * Locks down RuleTemplateRegistry so its `rule` payloads keep exactly the shape
 * DrwApp.handleAddRule() (admin-app.js) builds for a blank rule. If a future
 * edit drops `filters`/`adjustments` or reshapes a field, the RuleEditor would
 * silently break when a merchant picks that template — this test catches it.
 */
define('ABSPATH', dirname(__DIR__) . '/');

function __($text, $domain = null) {
    return $text;
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true($condition, $message) {
    assert_same(true, (bool) $condition, $message);
}

require_once dirname(__DIR__) . '/src/Models/RuleTemplateRegistry.php';

use Drw\App\Models\RuleTemplateRegistry;

$expected_ids = [
    'volume_discount', 'category_percentage', 'free_shipping_threshold',
    'bogo_2x1', 'wholesale_role', 'first_purchase',
];

assert_same($expected_ids, RuleTemplateRegistry::ids(), 'Registry should expose exactly the 6 known template ids, in catalogue order.');
assert_same(count($expected_ids), count(RuleTemplateRegistry::all()), 'all() should return one definition per known id.');

// This is the exact default shape DrwApp.handleAddRule() builds for a blank
// rule. Every template's `rule` must be a complete, RuleEditor-ready object
// with all of these top-level keys present.
$required_rule_keys        = ['title', 'enabled', 'exclusive', 'priority', 'apply_to', 'filters', 'conditions', 'adjustments'];
$required_filter_keys      = ['product_ids', 'category_ids', 'exclude_product_ids', 'exclude_category_ids'];
$required_adjustment_keys  = ['type', 'value', 'tiers'];

foreach (RuleTemplateRegistry::all() as $tpl) {
    foreach (['id', 'label', 'description', 'icon', 'color', 'rule'] as $field) {
        assert_true(array_key_exists($field, $tpl), "Template '{$tpl['id']}' should define the '{$field}' field.");
    }

    $rule = $tpl['rule'];
    foreach ($required_rule_keys as $key) {
        assert_true(array_key_exists($key, $rule), "Template '{$tpl['id']}' rule should define '{$key}'.");
    }

    // filters must carry all four list buckets so updateFilters() never reads undefined.
    foreach ($required_filter_keys as $key) {
        assert_true(array_key_exists($key, $rule['filters']), "Template '{$tpl['id']}' filters should define '{$key}'.");
        assert_true(is_array($rule['filters'][$key]), "Template '{$tpl['id']}' filters['{$key}'] should be an array.");
    }

    // adjustments must carry type/value/tiers so the pricing section renders.
    foreach ($required_adjustment_keys as $key) {
        assert_true(array_key_exists($key, $rule['adjustments']), "Template '{$tpl['id']}' adjustments should define '{$key}'.");
    }
    assert_true(is_array($rule['adjustments']['tiers']), "Template '{$tpl['id']}' adjustments['tiers'] should be an array.");

    assert_true(is_array($rule['conditions']), "Template '{$tpl['id']}' conditions should be an array.");

    // apply_to must be one of the three values the RuleEditor <select> offers.
    assert_true(
        in_array($rule['apply_to'], ['all_products', 'specific_products', 'specific_categories'], true),
        "Template '{$tpl['id']}' apply_to should be a recognised value, got '{$rule['apply_to']}'."
    );
}

// Spot-check the mechanics that make each template meaningful.
assert_same('bulk', RuleTemplateRegistry::get('volume_discount')['rule']['adjustments']['type'], 'volume_discount should be a bulk/tiered rule.');
assert_same(3, count(RuleTemplateRegistry::get('volume_discount')['rule']['adjustments']['tiers']), 'volume_discount should ship 3 example tiers.');
assert_same('specific_categories', RuleTemplateRegistry::get('category_percentage')['rule']['apply_to'], 'category_percentage should target specific categories.');
assert_same(20, RuleTemplateRegistry::get('category_percentage')['rule']['adjustments']['value'], 'category_percentage should be 20%.');
assert_same('free_shipping', RuleTemplateRegistry::get('free_shipping_threshold')['rule']['adjustments']['type'], 'free_shipping_threshold should adjust to free shipping.');
assert_same('subtotal', RuleTemplateRegistry::get('free_shipping_threshold')['rule']['conditions'][0]['type'], 'free_shipping_threshold should gate on cart subtotal.');
assert_same('bogo', RuleTemplateRegistry::get('bogo_2x1')['rule']['adjustments']['type'], 'bogo_2x1 should be a BOGO rule.');
assert_same(2, RuleTemplateRegistry::get('bogo_2x1')['rule']['adjustments']['buy_qty'], 'bogo_2x1 should buy 2.');
assert_same(1, RuleTemplateRegistry::get('bogo_2x1')['rule']['adjustments']['get_qty'], 'bogo_2x1 should get 1.');
assert_same('user_role', RuleTemplateRegistry::get('wholesale_role')['rule']['conditions'][0]['type'], 'wholesale_role should gate on user role.');
assert_same('purchase_history', RuleTemplateRegistry::get('first_purchase')['rule']['conditions'][0]['type'], 'first_purchase should gate on purchase history.');

assert_true(RuleTemplateRegistry::exists('bogo_2x1'), 'exists() should be true for a known template id.');
assert_true(!RuleTemplateRegistry::exists('not-a-real-template'), 'exists() should be false for an unknown template id.');
assert_same(null, RuleTemplateRegistry::get('not-a-real-template'), 'get() should return null for an unknown template id.');

echo "Rule template registry OK\n";
