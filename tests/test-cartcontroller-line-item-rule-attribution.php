<?php
/**
 * Standalone smoke test for CartController::resolve_line_item_rule_id() — the
 * sibling of resolve_line_item_promo_id() added to close a round-3 audit
 * finding: a MANUALLY-AUTHORED rule (source IS NULL, created outside the
 * Promos wizard) with its own usage_limit/limit_user was never attributed to
 * anything an order-completion hook could find, so those caps were silently
 * unenforced (RuleModel::increment_usage() was orphaned, and
 * reserve_promo_usage()/track_promo_redemptions() only ever resolved
 * PROMO ids). See resolve_order_rule_ids() / reserve_promo_usage() for the
 * consuming side.
 *
 * Same style/harness as test-cartcontroller-line-item-promo-attribution.php:
 * resolve_line_item_rule_id() is a pure private method, exercised via
 * ReflectionMethod, with a duck-typed RulesEngine stand-in.
 *
 * Coverage:
 *   (a) No active rules at all -> 0.
 *   (b) A manually-authored (no promo_id), item-level, matching rule WITH a
 *       usage_limit -> its rule id.
 *   (c) A manually-authored rule with NEITHER usage_limit NOR limit_user set
 *       -> 0 (nothing to reserve/track, matches reserve_promo_usage()'s own
 *       "no real limit configured" gate).
 *   (d) A rule that already has a promo_id is skipped here, even if it has a
 *       usage_limit — it's already handled via resolve_line_item_promo_id()/
 *       _drw_promo_id, and must never be double-attributed.
 *   (e) A cart-level rule is skipped (same documented limitation as
 *       resolve_line_item_promo_id()).
 *   (f) A rule that doesn't match the cart (is_rule_matched() false) is
 *       skipped.
 *   (g) A rule that doesn't target this product (is_product_targeted_by_rule()
 *       false) is skipped.
 *   (h) no_coupon_stacking (global option + per-rule) skips exactly like
 *       resolve_line_item_promo_id().
 *   (i) Multiple matching manually-authored rules -> the FIRST one returned
 *       by get_active_rules() (priority order) wins.
 *   (j) limit_user alone (no usage_limit) is enough to qualify a rule for
 *       attribution.
 */

define('ABSPATH', dirname(__DIR__) . '/');

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

/** Duck-typed RulesEngine stand-in, same shape as the promo-attribution test. */
class RulesEngineStub {
    public $rules = array();
    public $cart_level = array();
    public $matched = array();
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

function invoke_resolve_rule($controller, $product, $cart, $engine) {
    $ref = new ReflectionMethod(CartController::class, 'resolve_line_item_rule_id');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $product, $cart, $engine);
}

function reset_test_state() {
    $GLOBALS['drw_test_options'] = array();
}

$product = new WC_Product(1);
$cart = new WC_Cart();

// === (a) No active rules at all -> 0 ======================================
reset_test_state();
$engine = new RulesEngineStub();
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(a) No active rules must resolve to 0.');

// === (b) A manually-authored, item-level, matching rule WITH usage_limit ===
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 501, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null),
);
assert_same(501, invoke_resolve_rule($controller, $product, $cart, $engine), '(b) A matching manually-authored rule with usage_limit must resolve to its rule id.');

// === (c) A manually-authored rule with NEITHER cap set -> 0 ================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 502, 'promo_id' => null, 'source' => null, 'usage_limit' => null, 'limit_user' => null),
);
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(c) A rule with no usage_limit/limit_user configured must resolve to 0 -- nothing to reserve.');

// === (d) A rule with a promo_id is skipped even if it has a usage_limit ====
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 503, 'promo_id' => 42, 'source' => 'promo', 'usage_limit' => 100, 'limit_user' => null),
);
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(d) A promo-sourced rule (has promo_id) must be skipped here -- already handled via _drw_promo_id.');

// === (e) A cart-level rule is skipped =======================================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 504, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null),
);
$engine->cart_level = array(0 => true);
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(e) A cart-level rule must be skipped (not tied to a single line item).');

// === (f) A rule that does not match the cart is skipped =====================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 505, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null),
);
$engine->matched = array(0 => false);
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(f) A rule that fails is_rule_matched() must be skipped.');

// === (g) A rule that does not target this product is skipped ================
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 506, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null),
);
$engine->targeted = array(0 => false);
assert_same(0, invoke_resolve_rule($controller, $product, $cart, $engine), '(g) A rule that fails is_product_targeted_by_rule() must be skipped.');

// === (h) no_coupon_stacking skips exactly like resolve_line_item_promo_id() =
reset_test_state();
$GLOBALS['drw_test_options']['drw_global_no_coupon_stacking'] = true;
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 507, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null, 'no_coupon_stacking' => false),
);
$cart_with_coupon = new WC_Cart(array('SAVE10'));
assert_same(0, invoke_resolve_rule($controller, $product, $cart_with_coupon, $engine), '(h) The global no-coupon-stacking option must skip a rule when a coupon is applied.');
assert_same(507, invoke_resolve_rule($controller, $product, $cart, $engine), '(h) Without an applied coupon, the same rule must still resolve normally.');

reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 508, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null, 'no_coupon_stacking' => true),
);
assert_same(0, invoke_resolve_rule($controller, $product, $cart_with_coupon, $engine), '(h) A rule with its own no_coupon_stacking=true must be skipped once a coupon is applied.');

// === (i) Multiple matching manually-authored rules -> the FIRST one wins ===
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 509, 'promo_id' => null, 'source' => null, 'usage_limit' => 100, 'limit_user' => null),
    array('__i' => 1, 'id' => 510, 'promo_id' => null, 'source' => null, 'usage_limit' => 200, 'limit_user' => null),
);
assert_same(509, invoke_resolve_rule($controller, $product, $cart, $engine), '(i) The first matching manually-authored rule (priority order) must win.');

// === (j) limit_user alone (no usage_limit) is enough to qualify ============
reset_test_state();
$engine = new RulesEngineStub();
$engine->rules = array(
    array('__i' => 0, 'id' => 511, 'promo_id' => null, 'source' => null, 'usage_limit' => null, 'limit_user' => 1),
);
assert_same(511, invoke_resolve_rule($controller, $product, $cart, $engine), '(j) A rule with only limit_user set (no usage_limit) must still qualify for attribution.');

echo "CartController line-item rule attribution OK\n";
