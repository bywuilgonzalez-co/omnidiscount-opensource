<?php
/**
 * Standalone smoke test for Bogo::calculate() — proves that a BOGO adjustment
 * with an EMPTY get_products/get_categories (the compiled shape of every
 * 'gift'-type promo today, since there is no UI to configure an actual gift
 * product — see PromoBridgeController::gift_products()) matches NO items on
 * the get-side, instead of falling back to "matches every product in the
 * cart" and collapsing/discounting lines that were never configured as the
 * get-side scope. This holds for every get_product_type that reads
 * $get_items ('different', 'cheapest', 'cheapest_in_cart') — 'same' never
 * reads $get_items at all and is unaffected.
 *
 * No PHPUnit, no WooCommerce, no database — same hard-failing-assert style and
 * WC_Product/WC_Cart stub shape as tests/test-rulesengine-percentage-floor.php.
 *
 * Root cause: is_product_in_list()'s "both lists empty => match everything"
 * shortcut is correct for the BUY side (empty buy scope legitimately means
 * "storewide") but wrong for the GET side, for ANY get_product_type (empty
 * get scope means "no gift/get product configured", i.e. nothing should be
 * eligible on the get side). The fix threads a $match_all_when_empty flag
 * through calculate()'s get-side is_product_in_list() call, always false —
 * the buy-side call keeps the default (true).
 */

define('ABSPATH', dirname(__DIR__) . '/');

global $mock_product_categories;
$mock_product_categories = [];

class WC_Product {}

class Drw_Bogo_Test_Product extends WC_Product {
    private $id;
    private $parent_id;
    private $regular_price;

    public function __construct($id, $regular_price, $parent_id = 0) {
        $this->id            = (int)$id;
        $this->parent_id     = (int)$parent_id;
        $this->regular_price = (float)$regular_price;
    }

    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent_id; }
    public function get_regular_price() { return $this->regular_price; }
}

/** Minimal cart stub: only the surface Bogo::calculate() touches. */
class Drw_Bogo_Test_Cart {
    private $items;
    public function __construct($items) { $this->items = $items; }
    public function get_cart() { return $this->items; }
    public function is_empty() { return empty($this->items); }
}

function wc_get_product_term_ids($product_id, $taxonomy) {
    global $mock_product_categories;
    return isset($mock_product_categories[$product_id]) ? $mock_product_categories[$product_id] : [];
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

require_once dirname(__DIR__) . '/src/Adjustments/Bogo.php';

use Drw\App\Adjustments\Bogo;

$bogo = new Bogo();

// =============================================================================
// Case 1 — the exact repro: get_product_type='different', get_products and
// get_categories BOTH empty, buy_products/buy_categories ALSO empty
// (storewide "Toda la tienda" scope), cart with 12x one $100,000,000 product.
// Must return an EMPTY results array -- no discount at all, not $0 for
// everything.
// =============================================================================
$product_a = new Drw_Bogo_Test_Product(1, 100000000.0);
$cart_1 = new Drw_Bogo_Test_Cart([
    'key_a' => ['data' => $product_a, 'quantity' => 12],
]);

$gift_adjustments = [
    'buy_qty'          => 1,
    'get_qty'          => 1,
    'get_product_type' => 'different',
    'discount_type'    => 'free',
    'buy_products'     => [],
    'buy_categories'   => [],
    'get_products'     => [],
    'get_categories'   => [],
];

$result_1 = $bogo->calculate($gift_adjustments, $cart_1);
assert_same([], $result_1, 'Repro: unconfigured gift-type BOGO (empty get_products/get_categories) must produce NO discount, not collapse the line to $0.');

// =============================================================================
// Case 2 — same broken config, but with a SECOND distinct product also in the
// cart. Neither product's price may collapse.
// =============================================================================
$product_b = new Drw_Bogo_Test_Product(2, 50000000.0);
$cart_2 = new Drw_Bogo_Test_Cart([
    'key_a' => ['data' => $product_a, 'quantity' => 12],
    'key_b' => ['data' => $product_b, 'quantity' => 3],
]);

$result_2 = $bogo->calculate($gift_adjustments, $cart_2);
assert_same([], $result_2, 'Repro with a second distinct product in cart: unconfigured gift-type BOGO must still produce NO discount for either product.');

// =============================================================================
// Case 3 — regression guard: a WELL-FORMED 'different' case with REAL
// get_products configured (genuine "buy shirts, get pants free") must still
// work exactly as before this fix.
// =============================================================================
$shirt = new Drw_Bogo_Test_Product(10, 40.0);   // buy item
$pants = new Drw_Bogo_Test_Product(20, 60.0);   // get item (the only pants in cart)

$cart_3 = new Drw_Bogo_Test_Cart([
    'shirt_key' => ['data' => $shirt, 'quantity' => 2], // buy_qty=2 -> 1x get_qty=1 unlocked
    'pants_key' => ['data' => $pants, 'quantity' => 1],
]);

$wellformed_adjustments = [
    'buy_qty'          => 2,
    'get_qty'          => 1,
    'get_product_type' => 'different',
    'discount_type'    => 'free',
    'buy_products'     => [10],
    'buy_categories'   => [],
    'get_products'     => [20],
    'get_categories'   => [],
];

$result_3 = $bogo->calculate($wellformed_adjustments, $cart_3);
assert_true(isset($result_3['pants_key']), 'Well-formed different case: the configured get item (pants) must receive a discount.');
assert_same(0.0, $result_3['pants_key'], 'Well-formed different case: buy 2 shirts unlocks 1 free pair of pants (discount_type=free -> unit price 0).');
assert_true(!isset($result_3['shirt_key']), 'Well-formed different case: the buy item (shirts) itself must not be discounted.');

// =============================================================================
// Case 4 — get_product_type='same' with EMPTY buy_products/buy_categories
// (storewide 2x1, the ALREADY-WORKING case from the repro's step 1). Must be
// completely unaffected: byte-for-byte the same result as before this fix,
// since 'same' never reads $get_items at all.
// =============================================================================
$product_same = new Drw_Bogo_Test_Product(30, 100000000.0);
$cart_4 = new Drw_Bogo_Test_Cart([
    'same_key' => ['data' => $product_same, 'quantity' => 12],
]);

$twoforone_adjustments = [
    'buy_qty'          => 1,
    'get_qty'          => 1,
    'get_product_type' => 'same',
    'discount_type'    => 'free',
    'buy_products'     => [],
    'buy_categories'   => [],
    'get_products'     => [],
    'get_categories'   => [],
];

$result_4 = $bogo->calculate($twoforone_adjustments, $cart_4);
// group_size = 2, times = floor(12/2) = 6, discounted_qty = 6 free units out of 12.
assert_true(isset($result_4['same_key']), 'Storewide 2x1 (same) must still discount the item.');
assert_same(50000000.0, $result_4['same_key'], 'Storewide 2x1 (same): 6 of 12 units free -> average unit price is exactly half, unaffected by the different-branch fix.');

// =============================================================================
// Case 5 — get_product_type='cheapest'/'cheapest_in_cart' with EMPTY
// get_products/get_categories AND empty buy_products/buy_categories
// (storewide on both sides): $get_items is empty (get side always matches
// nothing when unconfigured, post-fix), so candidate_pool's own intentional
// fallback (candidate_pool = !empty($get_items) ? $get_items : $buy_items)
// kicks in and falls back to $buy_items, which -- since the buy side is ALSO
// storewide/empty -- is the whole cart. Net observable result is identical to
// the fallback's original intent.
// =============================================================================
$cheap_a = new Drw_Bogo_Test_Product(40, 10.0);
$cheap_b = new Drw_Bogo_Test_Product(41, 20.0);

foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    $cart_5 = new Drw_Bogo_Test_Cart([
        'cheap_a_key' => ['data' => $cheap_a, 'quantity' => 2],
        'cheap_b_key' => ['data' => $cheap_b, 'quantity' => 1],
    ]);

    $cheapest_adjustments = [
        'buy_qty'          => 2,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [],
        'buy_categories'   => [],
        'get_products'     => [],
        'get_categories'   => [],
    ];

    $result_5 = $bogo->calculate($cheapest_adjustments, $cart_5);
    // candidate_pool falls back to buy_items (storewide, so = whole cart),
    // total qty = 3, group_size = 3, times = 1, max_discount_qty = 1 ->
    // cheapest (cheap_a @ 10.0) gets one unit free.
    assert_true(isset($result_5['cheap_a_key']), "cheapest_in_cart fallback ({$cheapest_type}): cheapest item must be discounted, fallback-to-buy_items untouched.");
    assert_true(!isset($result_5['cheap_b_key']), "cheapest_in_cart fallback ({$cheapest_type}): the more expensive item must not be discounted.");
}

// =============================================================================
// Case 6 — round-1 audit finding: get_product_type='cheapest'/'cheapest_in_cart'
// with a NARROW buy_products scope and an unconfigured (empty) get scope. Pre-
// fix, is_product_in_list() defaulted the get-side match to "match everything"
// for any get_product_type other than 'different', so $get_items became the
// ENTIRE cart (non-empty), and candidate_pool = !empty($get_items) ? $get_items
// : $buy_items picked $get_items instead of falling back to $buy_items --
// letting a completely unrelated, unconfigured product (99) receive the "free"
// unit. Post-fix, $get_items must be empty (nothing configured on the get
// side), so candidate_pool must fall back to $buy_items (product 10 only) and
// the unrelated product must never be touched.
// =============================================================================
$related_product   = new Drw_Bogo_Test_Product(10, 100.0);
$unrelated_product = new Drw_Bogo_Test_Product(99, 5.0); // cheaper -> would be picked first if candidate_pool wrongly included it

foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    $cart_6 = new Drw_Bogo_Test_Cart([
        'related_key'   => ['data' => $related_product, 'quantity' => 2],
        'unrelated_key' => ['data' => $unrelated_product, 'quantity' => 5],
    ]);

    $narrow_buy_adjustments = [
        'buy_qty'          => 1,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [10],
        'buy_categories'   => [],
        'get_products'     => [],
        'get_categories'   => [],
    ];

    $result_6 = $bogo->calculate($narrow_buy_adjustments, $cart_6);
    // candidate_pool must fall back to buy_items (product 10 only, qty 2) since
    // get_items is empty (unconfigured get scope). group_size = 2, times =
    // floor(2/2) = 1, max_discount_qty = 1 -> 1 of the 2 related units is free.
    // The unrelated (cheaper) product must NEVER appear in the pool/result,
    // even though it would sort first by price if it were wrongly included.
    assert_true(!isset($result_6['unrelated_key']), "Narrow buy scope + unconfigured get scope ({$cheapest_type}): an unrelated, unconfigured (and cheaper) product must NEVER be discounted.");
    assert_true(isset($result_6['related_key']), "Narrow buy scope + unconfigured get scope ({$cheapest_type}): the actual buy-scope product must receive the discount via the buy_items fallback.");
    assert_same(50.0, $result_6['related_key'], "Narrow buy scope + unconfigured get scope ({$cheapest_type}): 1 of 2 units free at price 100.0 -> average unit price 50.0.");
    assert_same(['related_key' => 50.0], $result_6, "Narrow buy scope + unconfigured get scope ({$cheapest_type}): result must contain ONLY the related item.");
}

// =============================================================================
// Case 7 — round-2 audit finding: get_product_type='cheapest'/'cheapest_in_cart'
// with a CONFIGURED get scope (get_products or get_categories non-empty) that
// simply doesn't match anything currently in the cart. Pre-fix,
// $candidate_pool = !empty($get_items) ? $get_items : $buy_items conflated
// "get scope unconfigured" with "get scope configured but zero cart matches" --
// both produce an empty $get_items, so both wrongly fell back to $buy_items,
// silently discounting the buy item itself even though the merchant explicitly
// restricted the reward to a specific product/category that was never added to
// the cart. Post-fix, the fallback is keyed on whether the get scope was
// actually configured, not on whether $get_items happens to be empty -- so a
// configured-but-unmatched get scope must yield NO discount at all.
// =============================================================================
$buy_product_only = new Drw_Bogo_Test_Product(50, 100.0);

foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    // 7a: get_products configured, product not in cart.
    $cart_7a = new Drw_Bogo_Test_Cart([
        'buy_key' => ['data' => $buy_product_only, 'quantity' => 2],
    ]);
    $adjustments_7a = [
        'buy_qty'          => 1,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [50],
        'buy_categories'   => [],
        'get_products'     => [60], // not in cart
        'get_categories'   => [],
    ];
    $result_7a = $bogo->calculate($adjustments_7a, $cart_7a);
    assert_same([], $result_7a, "Configured get_products with no cart match ({$cheapest_type}): must produce NO discount, not fall back to discounting the buy item.");

    // 7b: get_categories configured, category not present on any cart item.
    $cart_7b = new Drw_Bogo_Test_Cart([
        'buy_key' => ['data' => $buy_product_only, 'quantity' => 2],
    ]);
    $adjustments_7b = [
        'buy_qty'          => 1,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [],
        'buy_categories'   => [],
        'get_products'     => [],
        'get_categories'   => [999], // not present on any cart item
    ];
    $result_7b = $bogo->calculate($adjustments_7b, $cart_7b);
    assert_same([], $result_7b, "Configured get_categories with no cart match ({$cheapest_type}): must produce NO discount, not fall back to discounting the buy item.");
}

// =============================================================================
// Case 8 — round-4 audit finding: get_product_type='cheapest'/'cheapest_in_cart'
// with a CONFIGURED and MATCHED get scope that differs from the buy scope, but
// the buy scope's own quantity requirement (buy_qty) is NOT met in the cart
// (or the buy product is entirely absent). Pre-fix, $total_candidate_qty and
// $group_size were derived solely from $candidate_pool (== $get_items when the
// get scope is configured), so $buy_items was computed but never consulted
// again -- silently bypassing the buy_qty requirement whenever the buy scope
// differs from the get scope. Post-fix, when the get scope is configured, the
// buy_qty requirement is checked against $buy_items independently (mirroring
// the 'different' branch), so an unmet/absent buy scope must yield NO
// discount even though the get scope is fully configured and stocked.
// =============================================================================
$buy_scope_product = new Drw_Bogo_Test_Product(70, 40.0);
$get_scope_product  = new Drw_Bogo_Test_Product(80, 50.0);

foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    // 8a: buy scope product entirely absent from the cart.
    $cart_8a = new Drw_Bogo_Test_Cart([
        'get_key' => ['data' => $get_scope_product, 'quantity' => 3],
    ]);
    $adjustments_8a = [
        'buy_qty'          => 2,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [70], // not in cart at all
        'buy_categories'   => [],
        'get_products'     => [80], // configured AND matched, plenty of stock
        'get_categories'   => [],
    ];
    $result_8a = $bogo->calculate($adjustments_8a, $cart_8a);
    assert_same([], $result_8a, "Configured+matched get scope but ABSENT buy scope ({$cheapest_type}): buy_qty requirement must still be enforced -> NO discount.");

    // 8b: buy scope present but below buy_qty threshold (1 unit, needs 2).
    $cart_8b = new Drw_Bogo_Test_Cart([
        'buy_key' => ['data' => $buy_scope_product, 'quantity' => 1],
        'get_key' => ['data' => $get_scope_product, 'quantity' => 3],
    ]);
    $result_8b = $bogo->calculate($adjustments_8a, $cart_8b);
    assert_same([], $result_8b, "Configured+matched get scope but UNDER-threshold buy scope ({$cheapest_type}): 1 of 2 required buy units -> NO discount.");

    // 8c: buy scope threshold MET (4 units -> buy_qty=2 twice) -> discount must
    // apply to the cheapest get-scope units, capped at max_discount_qty=2.
    $cart_8c = new Drw_Bogo_Test_Cart([
        'buy_key' => ['data' => $buy_scope_product, 'quantity' => 4],
        'get_key' => ['data' => $get_scope_product, 'quantity' => 3],
    ]);
    $result_8c = $bogo->calculate($adjustments_8a, $cart_8c);
    assert_true(isset($result_8c['get_key']), "Configured+matched get scope with buy threshold MET ({$cheapest_type}): the get-scope item must receive a discount.");
    // times = floor(4/2) = 2, max_discount_qty = 2 -> 2 of 3 get_key units free.
    // total_price = (1 * 50.0) + (2 * 0.0) = 50.0; average = 50.0 / 3.
    assert_same(50.0 / 3, $result_8c['get_key'], "Configured+matched get scope with buy threshold MET ({$cheapest_type}): 2 of 3 units free -> average unit price 50/3.");
    assert_true(!isset($result_8c['buy_key']), "Configured+matched get scope with buy threshold MET ({$cheapest_type}): the buy-scope item itself must not be discounted.");
}

// =============================================================================
// Case 9 — round-7 audit finding: get_product_type='cheapest'/'cheapest_in_cart'
// with a CONFIGURED get scope that OVERLAPS (here: is IDENTICAL to) the buy
// scope -- an ordinary "Buy 1 Get 1 Free within Category X" promo, a very
// natural way to configure 'cheapest' (it's meant to mean "cheapest eligible
// item", not necessarily a disjoint product group). Pre-fix, the round-4
// formula computed the buy_qty gate as total_buy_qty/buy_qty using
// $buy_items, which -- when buy and get scopes overlap -- includes the exact
// same physical cart units that also populate $candidate_pool (== $get_items)
// as discountable stock. That double-counts: the same units get spent to
// satisfy buy_qty AND handed out for free. Post-fix, overlapping units are
// split into a shared pool that can serve only ONE role (buy quota OR
// discount) each.
// =============================================================================
$overlap_x = new Drw_Bogo_Test_Product(90, 10.0);
$overlap_y = new Drw_Bogo_Test_Product(91, 20.0);
$mock_product_categories[90] = [5];
$mock_product_categories[91] = [5];

foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    // 9a: buy_qty=1, get_qty=1, identical category scope on both sides.
    // Cart: 2 units @10.0 + 2 units @20.0, all category 5 (4 units total).
    // Correct: group_size = buy_qty + get_qty = 2, times = floor(4/2) = 2,
    // max_discount_qty = 2 -> exactly 2 of the 4 units (the 2 cheapest, i.e.
    // both units of the $10 product) become free. Pre-fix this wrongly
    // returned ALL 4 units free (both cart lines collapsed to 0.0).
    $cart_9a = new Drw_Bogo_Test_Cart([
        'overlap_x_key' => ['data' => $overlap_x, 'quantity' => 2],
        'overlap_y_key' => ['data' => $overlap_y, 'quantity' => 2],
    ]);
    $adjustments_9a = [
        'buy_qty'          => 1,
        'get_qty'          => 1,
        'get_product_type' => $cheapest_type,
        'discount_type'    => 'free',
        'buy_products'     => [],
        'buy_categories'   => [5],
        'get_products'     => [],
        'get_categories'   => [5],
    ];
    $result_9a = $bogo->calculate($adjustments_9a, $cart_9a);
    assert_true(isset($result_9a['overlap_x_key']), "Overlapping buy/get scope ({$cheapest_type}): the cheapest matching product must receive a discount.");
    // total_price = (0 * 10.0) + (2 * 0.0) = 0.0 -> average 0.0 (both $10 units fully free).
    assert_same(0.0, $result_9a['overlap_x_key'], "Overlapping buy/get scope ({$cheapest_type}): exactly 2 of 4 units free (the cheaper product) -> average unit price 0.0.");
    assert_true(!isset($result_9a['overlap_y_key']), "Overlapping buy/get scope ({$cheapest_type}): must NOT double-count units into discounting the more expensive product too -- only 2 of 4 units total are free.");

    // 9b: same scope, buy_qty=2. Correct: group_size = 3, times = floor(4/3) = 1,
    // max_discount_qty = 1 -> exactly 1 of 4 units free. Pre-fix this wrongly
    // returned 2 of 4 free (total_buy_qty=4, times=floor(4/2)=2).
    $cart_9b = new Drw_Bogo_Test_Cart([
        'overlap_x_key' => ['data' => $overlap_x, 'quantity' => 2],
        'overlap_y_key' => ['data' => $overlap_y, 'quantity' => 2],
    ]);
    $adjustments_9b = $adjustments_9a;
    $adjustments_9b['buy_qty'] = 2;
    $result_9b = $bogo->calculate($adjustments_9b, $cart_9b);
    assert_true(isset($result_9b['overlap_x_key']), "Overlapping buy/get scope, buy_qty=2 ({$cheapest_type}): the cheapest matching product must receive a discount.");
    // total_price = (1 * 10.0) + (1 * 0.0) = 10.0; average = 10.0 / 2 = 5.0.
    assert_same(5.0, $result_9b['overlap_x_key'], "Overlapping buy/get scope, buy_qty=2 ({$cheapest_type}): exactly 1 of 4 units free -> average unit price 5.0 on the 2-unit line.");
    assert_true(!isset($result_9b['overlap_y_key']), "Overlapping buy/get scope, buy_qty=2 ({$cheapest_type}): the more expensive product must not be touched.");
}

// =============================================================================
// Case 10 — round-8 audit finding: get_product_type='different' with a
// CONFIGURED get scope that OVERLAPS (here: is IDENTICAL to) the buy scope --
// an ordinary "Buy 1 Get 1 Free within Category X" promo, a realistic and
// common way to configure 'different' (same category on both sides is the
// natural way to express "buy one item, get a different item in the same
// category"). Pre-fix, the 'different' branch computed the buy_qty gate as
// total_buy_qty/buy_qty using $buy_items, which -- when buy and get scopes
// overlap -- includes the exact same physical cart units that also populate
// $get_items as discountable stock. That double-counts: the same units get
// spent to satisfy buy_qty AND handed out for free/discounted. This is the
// same failure mode round-7 already fixed for the 'cheapest'/
// 'cheapest_in_cart' branch (see Case 9 above); round-8 ports that fix to
// 'different'.
// =============================================================================
$diff_overlap_x = new Drw_Bogo_Test_Product(90, 10.0);
$diff_overlap_y = new Drw_Bogo_Test_Product(91, 20.0);
$mock_product_categories[90] = [5];
$mock_product_categories[91] = [5];

// 10a: buy_qty=1, get_qty=1, identical category scope on both sides.
// Cart: 2 units @10.0 + 2 units @20.0, all category 5 (4 units total).
// Correct: group_size = buy_qty + get_qty = 2, times = floor(4/2) = 2,
// max_discount_qty = 2 -> exactly 2 of the 4 units (the 2 cheapest, i.e. both
// units of the $10 product) become free. Pre-fix this wrongly returned ALL 4
// units free (both cart lines collapsed to 0.0).
$cart_10a = new Drw_Bogo_Test_Cart([
    'overlap_x_key' => ['data' => $diff_overlap_x, 'quantity' => 2],
    'overlap_y_key' => ['data' => $diff_overlap_y, 'quantity' => 2],
]);
$adjustments_10a = [
    'buy_qty'          => 1,
    'get_qty'          => 1,
    'get_product_type' => 'different',
    'discount_type'    => 'free',
    'buy_products'     => [],
    'buy_categories'   => [5],
    'get_products'     => [],
    'get_categories'   => [5],
];
$result_10a = $bogo->calculate($adjustments_10a, $cart_10a);
assert_true(isset($result_10a['overlap_x_key']), "Overlapping buy/get scope ('different'): the cheapest matching product must receive a discount.");
// total_price = (0 * 10.0) + (2 * 0.0) = 0.0 -> average 0.0 (both $10 units fully free).
assert_same(0.0, $result_10a['overlap_x_key'], "Overlapping buy/get scope ('different'): exactly 2 of 4 units free (the cheaper product) -> average unit price 0.0.");
assert_true(!isset($result_10a['overlap_y_key']), "Overlapping buy/get scope ('different'): must NOT double-count units into discounting the more expensive product too -- only 2 of 4 units total are free.");

// 10b: same scope, buy_qty=2. Correct: group_size = 3, times = floor(4/3) = 1,
// max_discount_qty = 1 -> exactly 1 of 4 units free. Pre-fix this wrongly
// returned 2 of 4 free (total_buy_qty=4, times=floor(4/2)=2, and the whole
// overlap_x_key line -- 2 units -- collapsed to 0.0 instead of averaging 5.0).
$cart_10b = new Drw_Bogo_Test_Cart([
    'overlap_x_key' => ['data' => $diff_overlap_x, 'quantity' => 2],
    'overlap_y_key' => ['data' => $diff_overlap_y, 'quantity' => 2],
]);
$adjustments_10b = $adjustments_10a;
$adjustments_10b['buy_qty'] = 2;
$result_10b = $bogo->calculate($adjustments_10b, $cart_10b);
assert_true(isset($result_10b['overlap_x_key']), "Overlapping buy/get scope, buy_qty=2 ('different'): the cheapest matching product must receive a discount.");
// total_price = (1 * 10.0) + (1 * 0.0) = 10.0; average = 10.0 / 2 = 5.0.
assert_same(5.0, $result_10b['overlap_x_key'], "Overlapping buy/get scope, buy_qty=2 ('different'): exactly 1 of 4 units free -> average unit price 5.0 on the 2-unit line.");
assert_true(!isset($result_10b['overlap_y_key']), "Overlapping buy/get scope, buy_qty=2 ('different'): the more expensive product must not be touched.");

// 10c: regression guard -- disjoint scopes (Case 3's shirt/pants config) must
// remain byte-for-byte unaffected by the overlap-split rewrite, since
// $overlap_qty is always 0 when no cart line matches both scopes.
$disjoint_shirt = new Drw_Bogo_Test_Product(10, 40.0);
$disjoint_pants = new Drw_Bogo_Test_Product(20, 60.0);
$cart_10c = new Drw_Bogo_Test_Cart([
    'shirt_key' => ['data' => $disjoint_shirt, 'quantity' => 2],
    'pants_key' => ['data' => $disjoint_pants, 'quantity' => 1],
]);
$adjustments_10c = [
    'buy_qty'          => 2,
    'get_qty'          => 1,
    'get_product_type' => 'different',
    'discount_type'    => 'free',
    'buy_products'     => [10],
    'buy_categories'   => [],
    'get_products'     => [20],
    'get_categories'   => [],
];
$result_10c = $bogo->calculate($adjustments_10c, $cart_10c);
assert_true(isset($result_10c['pants_key']), "Disjoint scopes regression guard ('different'): the configured get item (pants) must receive a discount.");
assert_same(0.0, $result_10c['pants_key'], "Disjoint scopes regression guard ('different'): buy 2 shirts unlocks 1 free pair of pants.");
assert_true(!isset($result_10c['shirt_key']), "Disjoint scopes regression guard ('different'): the buy item (shirts) itself must not be discounted.");

echo "Bogo different-branch empty get-list fix OK\n";
