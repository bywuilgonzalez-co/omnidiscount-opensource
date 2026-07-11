<?php
/**
 * Standalone smoke test for the new, narrow, opt-in should_skip_due_to_sale_item()
 * guard in RulesEngine.php (Vía B / automatic, no-code promos and manually
 * authored rules). No PHPUnit, no WooCommerce, no database — same reflection-
 * based style as tests/test-rulesengine-percentage-floor.php.
 *
 * Proves, via real execution:
 *   (a) exclude_sale_items=false (the default) still discounts an on-sale
 *       product exactly as before — byte-for-byte identical behaviour.
 *   (b) exclude_sale_items=true skips an on-sale product entirely — no
 *       discount applied, the product keeps its own (sale) price untouched.
 *   (c) the SAME rule (exclude_sale_items=true) still discounts a different,
 *       non-sale product/line normally — proving this is a per-product guard,
 *       not an all-or-nothing rule-level skip.
 *
 * Covers both insertion points:
 *   - calculate_catalog_discount() (single-product context, both branches of
 *     the compounding strategy switch).
 *   - apply_rule_adjustments() (private, via reflection) — the per-cart-item
 *     loops for 'percentage'/'fixed' and 'bulk', proving (c) actually holds
 *     inside a single mixed cart (on-sale item skipped, non-sale item in the
 *     very same pass still discounted).
 */

define('ABSPATH', dirname(__DIR__) . '/');

global $mock_product_categories;
$mock_product_categories = [];

class WC_Product {}

class Drw_Sale_Test_Product extends WC_Product {
    private $id;
    private $parent_id;
    private $regular_price;
    private $price;
    private $on_sale;

    public function __construct($id, $price, $on_sale = false, $parent_id = 0) {
        $this->id            = (int)$id;
        $this->parent_id     = (int)$parent_id;
        $this->regular_price = (float)$price;
        $this->price         = (float)$price;
        $this->on_sale       = (bool)$on_sale;
    }

    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent_id; }
    public function get_regular_price() { return $this->regular_price; }
    public function get_price() { return $this->price; }
    public function set_price($price) { $this->price = (float)$price; }
    public function is_on_sale() { return $this->on_sale; }
}

/** Minimal cart stub: only the surface apply_rule_adjustments() touches. */
class Drw_Sale_Test_Cart {
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

function apply_filters($tag, $value) { return $value; }

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

$set_rules = function(array $rules) use ($engine) {
    $ref = new ReflectionProperty(RulesEngine::class, 'cached_rules');
    $ref->setAccessible(true);
    $ref->setValue($engine, $rules);
};

// === calculate_catalog_discount() ==========================================

// (a) exclude_sale_items=false (default), on-sale product -> discounts normally.
$rule_no_exclude = [
    'title'              => 'Storewide 20% (no sale exclusion)',
    'apply_to'            => 'all_products',
    'exclusive'           => false,
    'exclude_sale_items'  => false,
    'filters'             => [],
    'conditions'          => [],
    'adjustments'         => ['type' => 'percentage', 'value' => 20],
];
$set_rules([$rule_no_exclude]);
$on_sale_product = new Drw_Sale_Test_Product(1, 80.0, true);
$price = $engine->calculate_catalog_discount($on_sale_product, 80.0);
assert_true($price !== null, '(a) exclude_sale_items=false must still apply to an on-sale product.');
assert_same(64.0, (float)$price, '(a) 20% off 80 (on-sale product, flag off) = 64.0, unchanged behaviour.');

// (b) exclude_sale_items=true, on-sale product -> no discount at all.
$rule_exclude = [
    'title'              => 'Storewide 20% (excludes sale items)',
    'apply_to'            => 'all_products',
    'exclusive'           => false,
    'exclude_sale_items'  => true,
    'filters'             => [],
    'conditions'          => [],
    'adjustments'         => ['type' => 'percentage', 'value' => 20],
];
$set_rules([$rule_exclude]);
$on_sale_product_2 = new Drw_Sale_Test_Product(2, 80.0, true);
$price = $engine->calculate_catalog_discount($on_sale_product_2, 80.0);
assert_true($price === null, '(b) exclude_sale_items=true must skip an on-sale product entirely (no rule applied).');
assert_same(80.0, $on_sale_product_2->get_price(), "(b) on-sale product's own price must stay untouched.");

// (c) SAME rule, non-sale product in the same evaluation -> still discounts normally.
$non_sale_product = new Drw_Sale_Test_Product(3, 80.0, false);
$price = $engine->calculate_catalog_discount($non_sale_product, 80.0);
assert_true($price !== null, '(c) exclude_sale_items=true must NOT skip a non-sale product.');
assert_same(64.0, (float)$price, '(c) 20% off 80 (non-sale product, same rule) = 64.0, discounted normally.');

// Note: calculate_catalog_discount()'s 'highest' branch has its own,
// byte-identical should_skip_due_to_sale_item() call site (same guard, same
// position relative to should_skip_due_to_coupons()); not re-exercised here
// since apply_rule_adjustments() below (shared by both compounding
// strategies) is the higher-value real-execution proof for the cart path.

// === apply_rule_adjustments() (private) — mixed cart, per-item guard =======

$invoke_apply = function(array $rule, $cart, array $item_prices) use ($engine) {
    $ref = new ReflectionMethod(RulesEngine::class, 'apply_rule_adjustments');
    $ref->setAccessible(true);
    $fees          = [];
    $free_shipping = false;
    $args          = [$rule, $cart, &$item_prices, &$fees, &$free_shipping];
    $ref->invokeArgs($engine, $args);
    return $item_prices;
};

$sale_item     = new Drw_Sale_Test_Product(10, 100.0, true);
$non_sale_item = new Drw_Sale_Test_Product(11, 100.0, false);
$cart = new Drw_Sale_Test_Cart([
    'sale_key'     => ['product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $sale_item],
    'non_sale_key' => ['product_id' => 11, 'variation_id' => 0, 'quantity' => 1, 'data' => $non_sale_item],
]);

$percentage_rule = [
    'title'              => 'Cart-wide 25% excluding sale items',
    'apply_to'            => 'all_products',
    'exclusive'           => false,
    'exclude_sale_items'  => true,
    'filters'             => [],
    'conditions'          => [],
    'adjustments'         => ['type' => 'percentage', 'value' => 25],
];
$result = $invoke_apply($percentage_rule, $cart, ['sale_key' => 100.0, 'non_sale_key' => 100.0]);
assert_same(100.0, $result['sale_key'], '(b) percentage: on-sale line item must be skipped entirely (price unchanged).');
assert_same(75.0, $result['non_sale_key'], '(c) percentage: non-sale line item in the SAME rule/cart pass must still discount to 75.0.');

$bulk_rule = [
    'title'              => 'Cart-wide bulk 30% excluding sale items',
    'apply_to'            => 'all_products',
    'exclusive'           => false,
    'exclude_sale_items'  => true,
    'filters'             => [],
    'conditions'          => [],
    'adjustments'         => [
        'type'  => 'bulk',
        'tiers' => [
            ['min' => 1, 'max' => '', 'type' => 'percentage', 'value' => 30],
        ],
    ],
];
$result = $invoke_apply($bulk_rule, $cart, ['sale_key' => 100.0, 'non_sale_key' => 100.0]);
assert_same(100.0, $result['sale_key'], '(b) bulk: on-sale line item must be skipped entirely (price unchanged).');
assert_same(70.0, $result['non_sale_key'], '(c) bulk: non-sale line item in the SAME rule/cart pass must still discount to 70.0.');

// (a) again at the cart level: exclude_sale_items=false must discount BOTH
// items identically regardless of sale status — proving zero regression for
// the default (flag-off) path in the cart-adjustments method too.
$percentage_rule_no_exclude = $percentage_rule;
$percentage_rule_no_exclude['exclude_sale_items'] = false;
$result = $invoke_apply($percentage_rule_no_exclude, $cart, ['sale_key' => 100.0, 'non_sale_key' => 100.0]);
assert_same(75.0, $result['sale_key'], '(a) percentage, flag off: on-sale line item discounts normally, unchanged behaviour.');
assert_same(75.0, $result['non_sale_key'], '(a) percentage, flag off: non-sale line item discounts normally.');

// === apply_rule_adjustments() — cart-level FEE branch (is_cart_level_rule()) ===
// percentage/fixed + apply_to=all_products + a qualifying subtotal/items_count/
// coupon condition routes through the FEE branch (fees[], not item_prices[]),
// a separate code path from the per-item loop exercised above. Round-10 audit
// finding: this branch never called should_skip_due_to_sale_item(), so
// exclude_sale_items was a silent no-op here even though the same flag works
// for the per-item loop above.
$invoke_apply_fees = function(array $rule, $cart, array $item_prices) use ($engine) {
    $ref = new ReflectionMethod(RulesEngine::class, 'apply_rule_adjustments');
    $ref->setAccessible(true);
    $fees          = [];
    $free_shipping = false;
    $args          = [$rule, $cart, &$item_prices, &$fees, &$free_shipping];
    $ref->invokeArgs($engine, $args);
    return $fees;
};

$fee_rule = [
    'title'              => 'Cart-wide 10% fee over $100, excluding sale items',
    'apply_to'            => 'all_products',
    'exclusive'           => false,
    'exclude_sale_items'  => true,
    'filters'             => [],
    // Content of the condition is irrelevant here: apply_rule_adjustments()
    // never calls is_rule_matched() itself (the caller does); only its
    // *type* is read by is_cart_level_rule() to route into the FEE branch.
    'conditions'          => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 100]],
    'adjustments'         => ['type' => 'percentage', 'value' => 10],
];
// Reuse the same mixed cart: $100 on-sale item + $100 non-sale item = $200 subtotal.
$fees = $invoke_apply_fees($fee_rule, $cart, ['sale_key' => 100.0, 'non_sale_key' => 100.0]);
assert_same(1, count($fees), 'Cart-level fee, exclude_sale_items=true: exactly one fee must be emitted.');
assert_same(-10.0, (float)$fees[0]['amount'], 'Cart-level fee, exclude_sale_items=true: fee base must exclude the on-sale item ($100 non-sale x 10% = -10.0), not the full $200 subtotal.');

// Same rule, flag off: fee base must be the FULL subtotal, unchanged behaviour.
$fee_rule_no_exclude = $fee_rule;
$fee_rule_no_exclude['exclude_sale_items'] = false;
$fees = $invoke_apply_fees($fee_rule_no_exclude, $cart, ['sale_key' => 100.0, 'non_sale_key' => 100.0]);
assert_same(1, count($fees), 'Cart-level fee, flag off: exactly one fee must be emitted.');
assert_same(-20.0, (float)$fees[0]['amount'], 'Cart-level fee, flag off: fee base must be the full $200 subtotal (-20.0), unchanged behaviour.');

echo "RulesEngine exclude_sale_items OK\n";
