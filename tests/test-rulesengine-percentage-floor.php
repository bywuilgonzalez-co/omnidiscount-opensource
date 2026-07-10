<?php
/**
 * Standalone smoke test proving percentage adjustments can never yield a
 * negative resulting price, mirroring the max(0.0, ...) floor that 'fixed'
 * already applies consistently. No PHPUnit, no WooCommerce, no database —
 * same hard-failing-assert style as tests/test-rule-target-exclusions.php.
 *
 * A percentage value of 150 (as if it slipped past the origin clamp) would,
 * without the floor, drive the price below zero. These assertions cover BOTH
 * fix sites:
 *
 *   - calculate_catalog_discount(): direct percentage AND bulk-tier percentage,
 *     in both the default (priority_exclusivity) and 'highest' strategies.
 *   - apply_rule_adjustments() (private, via reflection): per-item percentage
 *     AND bulk-tier percentage.
 */

define('ABSPATH', dirname(__DIR__) . '/');

global $mock_product_categories;
$mock_product_categories = [];

class WC_Product {}

class Drw_Floor_Test_Product extends WC_Product {
    private $id;
    private $parent_id;
    private $regular_price;
    private $price;

    public function __construct($id, $regular_price, $parent_id = 0) {
        $this->id            = (int)$id;
        $this->parent_id     = (int)$parent_id;
        $this->regular_price = (float)$regular_price;
        $this->price         = (float)$regular_price;
    }

    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent_id; }
    public function get_regular_price() { return $this->regular_price; }
    public function get_price() { return $this->price; }
    public function set_price($price) { $this->price = (float)$price; }
}

/** Minimal cart stub: only the surface apply_rule_adjustments() touches. */
class Drw_Floor_Test_Cart {
    private $items;
    public function __construct($items) { $this->items = $items; }
    public function get_cart() { return $this->items; }
    public function is_empty() { return empty($this->items); }
    public function get_applied_coupons() { return []; }
}

function wc_get_product_term_ids($product_id, $taxonomy) {
    global $mock_product_categories;
    return isset($mock_product_categories[$product_id]) ? $mock_product_categories[$product_id] : [];
}

// should_skip_due_to_coupons() reads this; no stacking suppression under test.
function get_option($name, $default = false) { return $default; }

// get_compounding_strategy() runs its value through this filter.
function apply_filters($tag, $value) {
    if ($tag === 'drw_compounding_strategy' && isset($GLOBALS['drw_test_strategy'])) {
        return $GLOBALS['drw_test_strategy'];
    }
    return $value;
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Controllers/RulesEngine.php';

use Drw\App\Controllers\RulesEngine;

$engine = RulesEngine::instance();

// Inject rules straight into the private request cache so no DB is touched.
$set_rules = function(array $rules) use ($engine) {
    $ref = new ReflectionProperty(RulesEngine::class, 'cached_rules');
    $ref->setAccessible(true);
    $ref->setValue($engine, $rules);
};

$direct_rule = [
    'title'       => 'Overshoot direct',
    'apply_to'    => 'all_products',
    'exclusive'   => false,
    'filters'     => [],
    'conditions'  => [],
    'adjustments' => ['type' => 'percentage', 'value' => 150],
];

$bulk_rule = [
    'title'       => 'Overshoot bulk tier',
    'apply_to'    => 'all_products',
    'exclusive'   => false,
    'filters'     => [],
    'conditions'  => [],
    'adjustments' => [
        'type'  => 'bulk',
        'tiers' => [
            ['min' => 1, 'max' => '', 'type' => 'percentage', 'value' => 150],
        ],
    ],
];

// === calculate_catalog_discount() — default (priority_exclusivity) strategy ===
$set_rules([$direct_rule]);
$price = $engine->calculate_catalog_discount(new Drw_Floor_Test_Product(1, 100.0), 100.0);
assert_true($price !== null, 'Direct 150% rule should apply and return a price.');
assert_true($price >= 0.0, 'Direct 150% percentage must be floored at 0, never negative.');
assert_same(0.0, (float)$price, 'Direct 150% off 100 floors to exactly 0.0.');

$set_rules([$bulk_rule]);
$price = $engine->calculate_catalog_discount(new Drw_Floor_Test_Product(2, 100.0), 100.0);
assert_true($price !== null, 'Bulk-tier 150% rule should apply and return a price.');
assert_true($price >= 0.0, 'Bulk-tier 150% percentage must be floored at 0, never negative.');
assert_same(0.0, (float)$price, 'Bulk-tier 150% off 100 floors to exactly 0.0.');

// === calculate_catalog_discount() — 'highest' strategy ===
$GLOBALS['drw_test_strategy'] = 'highest';

$set_rules([$direct_rule]);
$price = $engine->calculate_catalog_discount(new Drw_Floor_Test_Product(3, 100.0), 100.0);
assert_true($price !== null && $price >= 0.0, 'Highest-strategy direct 150% must be floored, never negative.');
assert_same(0.0, (float)$price, 'Highest-strategy direct 150% off 100 floors to exactly 0.0.');

$set_rules([$bulk_rule]);
$price = $engine->calculate_catalog_discount(new Drw_Floor_Test_Product(4, 100.0), 100.0);
assert_true($price !== null && $price >= 0.0, 'Highest-strategy bulk-tier 150% must be floored, never negative.');
assert_same(0.0, (float)$price, 'Highest-strategy bulk-tier 150% off 100 floors to exactly 0.0.');

unset($GLOBALS['drw_test_strategy']);

// === apply_rule_adjustments() (private) — per-item percentage and bulk tier ===
$invoke_apply = function(array $rule, $cart, array $item_prices) use ($engine) {
    $ref = new ReflectionMethod(RulesEngine::class, 'apply_rule_adjustments');
    $ref->setAccessible(true);
    $fees          = [];
    $free_shipping = false;
    $args          = [$rule, $cart, &$item_prices, &$fees, &$free_shipping];
    $ref->invokeArgs($engine, $args);
    return $item_prices;
};

$product = new Drw_Floor_Test_Product(10, 100.0);
$cart    = new Drw_Floor_Test_Cart([
    'itemkey1' => ['product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $product],
]);

// Per-item percentage at 150% — specific_products avoids the cart-level path.
$item_pct_rule = [
    'title'       => 'Item overshoot pct',
    'apply_to'    => 'specific_products',
    'exclusive'   => false,
    'filters'     => ['product_ids' => [10]],
    'conditions'  => [],
    'adjustments' => ['type' => 'percentage', 'value' => 150],
];
$result = $invoke_apply($item_pct_rule, $cart, ['itemkey1' => 100.0]);
assert_true($result['itemkey1'] >= 0.0, 'apply_rule_adjustments per-item 150% must floor at 0.');
assert_same(0.0, $result['itemkey1'], 'Per-item 150% off 100 floors to exactly 0.0.');

// Bulk-tier percentage at 150%, quantity 1.
$item_bulk_rule = [
    'title'       => 'Item overshoot bulk',
    'apply_to'    => 'specific_products',
    'exclusive'   => false,
    'filters'     => ['product_ids' => [10]],
    'conditions'  => [],
    'adjustments' => [
        'type'  => 'bulk',
        'tiers' => [
            ['min' => 1, 'max' => '', 'type' => 'percentage', 'value' => 150],
        ],
    ],
];
$result = $invoke_apply($item_bulk_rule, $cart, ['itemkey1' => 100.0]);
assert_true($result['itemkey1'] >= 0.0, 'apply_rule_adjustments bulk-tier 150% must floor at 0.');
assert_same(0.0, $result['itemkey1'], 'Bulk-tier 150% off 100 floors to exactly 0.0.');

echo "RulesEngine percentage floor OK\n";
