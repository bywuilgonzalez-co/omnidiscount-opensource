<?php
/**
 * Standalone smoke test for CartController::resolve_line_item_promo_id() —
 * the helper added to close the "Vía B promo id is never stamped on order
 * line items" bug: RulesEngine's calculation logic is frozen, so this
 * mirrors the SAME pattern already used by the sandbox-preview layer
 * (apply_sandbox_item_adjustments()) — re-applying RulesEngine's own PUBLIC
 * matching predicates from outside, rather than touching RulesEngine.php.
 *
 * No PHPUnit, no WooCommerce, no database — same hard-failing-assert style as
 * tests/test-cart-fee-stacking-cap.php. resolve_line_item_promo_id() is a
 * pure private method, exercised via ReflectionMethod exactly like that test
 * does with scale_fees_to_subtotal(). A minimal duck-typed RulesEngine stand-in
 * is passed in directly (the method takes no type hint), so none of the real
 * RulesEngine/Conditions/Adjustments code is touched or required.
 *
 * Coverage:
 *   (a) No active rules at all -> 0.
 *   (b) A promo-sourced, item-level, matching rule that targets the product
 *       -> its promo_id.
 *   (c) A manually-authored rule (no promo_id / source !== 'promo') is never
 *       attributed, even if it matches and targets the product.
 *   (d) A cart-level promo rule (is_cart_level_rule() true) is skipped —
 *       cart-level fees aren't tied to a single line item.
 *   (e) A rule that doesn't match the cart (is_rule_matched() false) is
 *       skipped.
 *   (f) A rule that doesn't target this product (is_product_targeted_by_rule()
 *       false) is skipped.
 *   (g) drw_global_no_coupon_stacking option + an applied coupon skips a rule
 *       that hasn't opted out of that global setting.
 *   (h) A rule with no_coupon_stacking=true is skipped once a coupon is
 *       applied, even without the global option.
 *   (i) Multiple matching promo-sourced rules -> the FIRST one returned by
 *       get_active_rules() (priority order) wins.
 */

define('ABSPATH', dirname(__DIR__) . '/');

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

// --- Minimal WP option shim ---------------------------------------------
$GLOBALS['drw_test_options'] = array();
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['drw_test_options']) ? $GLOBALS['drw_test_options'][$key] : $default;
}

// --- Minimal WC_Cart / WC_Product stand-ins ------------------------------
class WC_Cart {
    private $coupons;
    public function __construct(array $coupons = array()) {
        $this->coupons = $coupons;
    }
    public function get_applied_coupons() {
        return $this->coupons;
    }
}

class WC_Product {
    private $id;
    public function __construct($id = 1) {
        $this->id = $id;
    }
    public function get_id() {
        return $this->id;
    }
}

/**
 * Duck-typed RulesEngine stand-in: resolve_line_item_promo_id() takes no
 * type hint on its $engine parameter, so this fully controls
 * get_active_rules()/is_cart_level_rule()/is_rule_matched()/
 * is_product_targeted_by_rule() without touching the real (frozen) class.
 */
class RulesEngineStub {
    /** @var array */
    public $rules = array();
    /** @var array<int,bool> rule index => is_cart_level_rule() result */
    public $cart_level = array();
    /** @var array<int,bool> rule index => is_rule_matched() result */
    public $matched = array();
    /** @var array<int,bool> rule index => is_product_targeted_by_rule() result */
    public $targeted = array();

    public function get_active_rules() {
        return $this->rules;
    }

    public function is_cart_level_rule(array $rule) {
        $i = $rule['__i'];
        return isset($this->cart_level[$i]) ? $this->cart_level[$i] : false;
    }

    public function is_rule_matched(array $rule, $cart = null) {
        $i = $rule['__i'];
        return isset($this->matched[$i]) ? $this->matched[$i] : true;
    }

    public function is_product_targeted_by_rule(array $rule, $product) {
        $i = $rule['__i'];
        return isset($this->targeted[$i]) ? $this->targeted[$i] : true;
    }
}

require_once dirname(__DIR__) . '/src/Controllers/CartController.php';

use Drw\App\Controllers\CartController;

$controller = CartController::instance();

function invoke_resolve($controller, $product, $cart, $engine) {
    $ref = new ReflectionMethod(CartController::class, 'resolve_line_item_promo_id');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $product, $cart, $engine);
}

function reset_test_state() {
    $GLOBALS['drw_test_options'] = array();
}

// === (a) No active rules at all -> 0 ======================================
reset_test_state();
$engine = new RulesEngineStub();
$product = new WC_Product(1);
$cart = new WC_Cart();
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(a) No active rules must resolve to 0.');

// === (b) A promo-sourced, item-level, matching rule -> its promo_id =======
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 42, 'source' => 'promo'),
);
assert_same(42, invoke_resolve($controller, $product, $cart, $engine), '(b) A single matching promo-sourced rule must resolve to its promo_id.');

// === (c) A manually-authored rule (no promo_id) is never attributed =======
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => null, 'source' => null),
);
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(c) A manually-authored rule (no promo_id) must resolve to 0.');

reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    // Has a promo_id but source explicitly isn't 'promo' (defensive case).
    array('__i' => 0, 'promo_id' => 99, 'source' => 'manual'),
);
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(c) A rule whose source is not "promo" must never be attributed, even with a promo_id set.');

// === (d) A cart-level promo rule is skipped ===============================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 7, 'source' => 'promo'),
);
$engine->cart_level = array(0 => true);
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(d) A cart-level promo rule must be skipped (not tied to a single line item).');

// === (e) A rule that does not match the cart is skipped ====================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 7, 'source' => 'promo'),
);
$engine->matched = array(0 => false);
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(e) A rule that fails is_rule_matched() must be skipped.');

// === (f) A rule that does not target this product is skipped ==============
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 7, 'source' => 'promo'),
);
$engine->targeted = array(0 => false);
assert_same(0, invoke_resolve($controller, $product, $cart, $engine), '(f) A rule that fails is_product_targeted_by_rule() must be skipped.');

// === (g) Global no-coupon-stacking option + an applied coupon skips a rule =
$GLOBALS['drw_test_options']['drw_global_no_coupon_stacking'] = true;
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 7, 'source' => 'promo', 'no_coupon_stacking' => false),
);
$cart_with_coupon = new WC_Cart(array('SAVE10'));
assert_same(0, invoke_resolve($controller, $product, $cart_with_coupon, $engine), '(g) The global no-coupon-stacking option must skip a rule when a coupon is applied.');

// Sanity: same setup but WITHOUT an applied coupon must still resolve.
assert_same(7, invoke_resolve($controller, $product, $cart, $engine), '(g) Without an applied coupon, the same rule must still resolve normally.');

// === (h) Per-rule no_coupon_stacking=true skips it even without the global =
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 7, 'source' => 'promo', 'no_coupon_stacking' => true),
);
assert_same(0, invoke_resolve($controller, $product, $cart_with_coupon, $engine), '(h) A rule with its own no_coupon_stacking=true must be skipped once a coupon is applied.');

// === (i) Multiple matching promo-sourced rules -> the FIRST one wins =======
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'promo_id' => 11, 'source' => 'promo'),
    array('__i' => 1, 'promo_id' => 22, 'source' => 'promo'),
);
assert_same(11, invoke_resolve($controller, $product, $cart, $engine), '(i) The first matching promo-sourced rule (priority order) must win.');

echo "CartController line-item promo attribution OK\n";
