<?php
/**
 * Focused smoke test for rule payload normalization before persistence.
 */

define('ABSPATH', dirname(__DIR__) . '/');

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Models/RuleModel.php';

$rule = Drw\App\Models\RuleModel::sanitize_rule_payload([
    'title' => ' <b>VIP Deal</b> ',
    'apply_to' => 'specific_products',
    'filters' => [
        'product_ids' => ['15', 'bad', -3, 15],
        'category_ids' => ['8', '0'],
    ],
    'adjustments' => [
        'type' => 'bundle',
        'set_price' => '49.95',
    ],
    'conditions' => [
        [
            'type' => 'product_combination',
            'product_ids' => ['9', 'bad', -1],
            'category_ids' => ['3'],
        ],
        [
            'type' => 'purchase_history',
            'history_metric' => 'products_bought',
            'value' => ['11', 'x', '12'],
        ],
    ],
]);

assert_same('VIP Deal', $rule['title'], 'Rule title should be sanitized.');
assert_same([15], $rule['filters']['product_ids'], 'Filter product IDs should be unique positive integers.');
assert_same([8], $rule['filters']['category_ids'], 'Filter category IDs should be unique positive integers.');
assert_same('bundle_set', $rule['adjustments']['type'], 'Legacy bundle type should normalize to engine type.');
assert_same(49.95, $rule['adjustments']['bundle_price'], 'Legacy set_price should normalize to bundle_price.');
assert_same([9], $rule['conditions'][0]['product_ids'], 'Condition product IDs should normalize.');
assert_same([11, 12], $rule['conditions'][1]['value'], 'Purchase history product IDs should normalize.');

$bogo = Drw\App\Models\RuleModel::sanitize_rule_payload([
    'title' => 'BOGO',
    'apply_to' => 'all_products',
    'filters' => [],
    'conditions' => [],
    'adjustments' => [
        'type' => 'bogo',
        'get_product_id' => '23',
        'bogo_discount_type' => 'percentage',
        'bogo_value' => '50',
    ],
]);

assert_same([23], $bogo['adjustments']['get_products'], 'Legacy BOGO get_product_id should normalize to get_products.');
assert_same('percentage', $bogo['adjustments']['discount_type'], 'Legacy BOGO discount type should normalize.');
assert_same(50.0, $bogo['adjustments']['discount_value'], 'Legacy BOGO value should normalize.');

echo "Rule payload normalization OK\n";
