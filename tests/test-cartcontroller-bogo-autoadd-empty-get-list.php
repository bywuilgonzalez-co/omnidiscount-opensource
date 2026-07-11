<?php
/**
 * Standalone smoke test for CartController::is_product_in_list() — the
 * BOGO auto-add-to-cart duplicate of Drw\App\Adjustments\Bogo::
 * is_product_in_list() (used by recalculate_cart_item_prices()'s
 * get_product_type='different' auto-add-gift branch, ~line 310-336, to
 * detect whether the gift product is already in the cart before adding it).
 *
 * This copy lacked the $match_all_when_empty parameter Bogo.php's sibling
 * method already has (added this session to fix the "unconfigured gift
 * collapses the whole cart to $0" bug — see
 * tests/test-bogo-different-empty-get-list.php). The auto-add call site was
 * NOT independently exploitable today (it's gated by an outer
 * `!empty($get_products)` check), but the matching function itself carried
 * the same latent bug — if that guard is ever loosened to also allow
 * category-only gift scopes (`!empty($get_products) || !empty($get_categories)`),
 * an empty/unconfigured get scope would silently match every cart line and
 * treat an arbitrary product as "already in cart", suppressing the auto-add.
 *
 * Fix: same $match_all_when_empty flag, same default (true, buy-side
 * semantics unchanged), passed false at the get-side call site only.
 *
 * is_product_in_list() is a pure private method, exercised via
 * ReflectionMethod on CartController::instance() — same harness style as
 * tests/test-cartcontroller-line-item-rule-attribution.php.
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

global $mock_product_categories;
$mock_product_categories = array();

function wc_get_product_term_ids($product_id, $taxonomy) {
    global $mock_product_categories;
    return isset($mock_product_categories[$product_id]) ? $mock_product_categories[$product_id] : array();
}

class WC_Product {
    private $id;
    private $parent_id;

    public function __construct($id, $parent_id = 0) {
        $this->id        = (int)$id;
        $this->parent_id = (int)$parent_id;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_parent_id() {
        return $this->parent_id;
    }
}

require_once dirname(__DIR__) . '/src/Controllers/CartController.php';

use Drw\App\Controllers\CartController;

$controller = CartController::instance();

function invoke_is_product_in_list($controller, $product, $product_ids, $category_ids, $match_all_when_empty = true) {
    $ref = new ReflectionMethod(CartController::class, 'is_product_in_list');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $product, $product_ids, $category_ids, $match_all_when_empty);
}

// =============================================================================
// (a) The exact scenario the task describes: an unconfigured get scope
// (get_products=[] AND get_categories=[]) must NOT match an unrelated
// product when $match_all_when_empty is passed false (the auto-add call
// site's new argument) — this is what would have wrongly reported "gift
// already in cart" for ANY product, silently suppressing a legitimate
// auto-add, if the outer !empty($get_products) guard were ever loosened.
// =============================================================================
$unrelated_product = new WC_Product(999);

assert_same(
    false,
    invoke_is_product_in_list($controller, $unrelated_product, array(), array(), false),
    'Unconfigured get scope (both lists empty) with $match_all_when_empty=false must match NOTHING, not every product.'
);

// =============================================================================
// (b) Default behavior (no 4th arg / true) is unchanged — this is the
// buy-side semantics ("Toda la tienda" / storewide) that must keep working
// exactly as before for the two buy-side call sites (lines 313, 340), which
// were not touched by this fix.
// =============================================================================
assert_same(
    true,
    invoke_is_product_in_list($controller, $unrelated_product, array(), array()),
    'Buy-side call (default $match_all_when_empty=true): empty scope must still mean "storewide", matching every product.'
);
assert_same(
    true,
    invoke_is_product_in_list($controller, $unrelated_product, array(), array(), true),
    'Explicit $match_all_when_empty=true must behave identically to the default (buy-side semantics unchanged).'
);

// =============================================================================
// (c) Regression guard: a REAL, configured get scope (product id match) must
// still correctly report "in list" regardless of $match_all_when_empty —
// that flag only changes the EMPTY-list fallback, never overrides an actual
// configured match. Proves existing configured-get-scope auto-add detection
// (e.g. the gift product genuinely already sitting in the cart) is
// unaffected by this fix.
// =============================================================================
$gift_product = new WC_Product(42);

assert_same(
    true,
    invoke_is_product_in_list($controller, $gift_product, array(42), array(), false),
    'Configured get_products containing the product id must match, even with $match_all_when_empty=false.'
);
assert_same(
    false,
    invoke_is_product_in_list($controller, $unrelated_product, array(42), array(), false),
    'Configured get_products NOT containing the product id must not match, with $match_all_when_empty=false.'
);

// Category-configured get scope, same regression guard.
$mock_product_categories[43] = array(7);
$category_gift_product = new WC_Product(43);

assert_same(
    true,
    invoke_is_product_in_list($controller, $category_gift_product, array(), array(7), false),
    'Configured get_categories matching the product\'s category must match, even with $match_all_when_empty=false.'
);
assert_same(
    false,
    invoke_is_product_in_list($controller, $unrelated_product, array(), array(7), false),
    'Configured get_categories NOT matching the product\'s category must not match, with $match_all_when_empty=false.'
);

// =============================================================================
// (d) Parent/variation matching (product_ids may match a variable product's
// parent id) must still work identically regardless of $match_all_when_empty
// — another slice of existing behavior this fix must not disturb.
// =============================================================================
$variation = new WC_Product(51, 50);

assert_same(
    true,
    invoke_is_product_in_list($controller, $variation, array(50), array(), false),
    'Variation matching via parent_id must still work with $match_all_when_empty=false.'
);

echo "CartController BOGO auto-add empty-get-list fix OK\n";
