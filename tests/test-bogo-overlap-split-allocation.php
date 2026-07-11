<?php
/**
 * Standalone smoke test for Bogo::calculate() — proves that the overlap-split
 * feasibility computation added in rounds 7/8 (which proves a given number of
 * buy/get cycles is achievable ONLY under one exact split of the overlapping
 * units between the buy role and the get role) is actually ENFORCED by the
 * discount-application loop that follows it, for both the 'different' branch
 * and the 'cheapest'/'cheapest_in_cart' branch.
 *
 * No PHPUnit, no WooCommerce, no database — same hard-failing-assert style and
 * WC_Product/WC_Cart stub shape as tests/test-bogo-different-empty-get-list.php.
 *
 * Root cause (round-10 audit finding): $times/$max_discount_qty were computed
 * correctly (aggregate quantity only), but the application loop right after
 * ignored which overlap units the feasibility proof required for the buy
 * side. It just sorted $get_items/$candidate_pool by price ascending and
 * greedily granted the discount to whichever line was cheapest, including
 * overlap lines, up to $max_discount_qty units total -- with no cap on how
 * many of those units came from the overlap. Since overlap lines are often
 * the cheapest (that's the whole point of "cheapest" discounting), this let
 * the discount drain entirely from the overlap line while leaving a genuinely
 * get-only line completely untouched, even though the feasibility proof
 * required units from BOTH tolines to reach $times cycles -- i.e. it silently
 * over-spent overlap units that the same computation had already earmarked
 * for the buy side.
 *
 * Fix: track $overlap_for_buy_at_times (the overlap units the feasibility
 * proof reserved for the buy role at the chosen $times) and cap how much of
 * the discount the application loop may draw from overlap lines to
 * $overlap_budget = $overlap_qty - $overlap_for_buy_at_times. Get-only lines
 * are never capped.
 */

define('ABSPATH', dirname(__DIR__) . '/');

global $mock_product_categories;
$mock_product_categories = [];

class WC_Product {}

class Drw_Bogo_Overlap_Test_Product extends WC_Product {
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
class Drw_Bogo_Overlap_Test_Cart {
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
// Shared scenario for both branches: buy_categories=[5,6], get_categories=[5,7]
// -> category 5 is the overlap. buy_qty=2, get_qty=1.
//   - 'ov' line: category 5 (overlap), 10 units @ $1.0 (cheapest -- would be
//     drained first by naive "cheapest first" ordering).
//   - 'bo' line: category 6 (buy-only), 8 units @ $100.0.
//   - 'go' line: category 7 (get-only), 4 units @ $50.0.
//
// Hand-derived feasibility: buy_only_qty=8, overlap_qty=10, get_only_qty=4.
// max_times_by_buy = floor((8+10)/2) = 9.
// t=9: needed_buy=max(0,18-8)=10, needed_get=max(0,9-4)=5, sum=15 > 10 -> infeasible.
// t=8: needed_buy=8, needed_get=4, sum=12 > 10 -> infeasible.
// t=7: needed_buy=6, needed_get=3, sum=9 <= 10 -> feasible. times=7.
// overlap_for_buy_at_times = 6 -> overlap_budget = 10 - 6 = 4.
// max_discount_qty = 7.
//
// Application (sorted cheapest first: ov $1.0, go $50.0):
//   ov: eligible = min(10, overlap_budget=4) = 4 -> apply 4, remaining = 3.
//       total_price = (10-4)*1.0 + 4*0.0 = 6.0 -> average = 6.0/10 = 0.6.
//   go: eligible = 4 (uncapped, get-only) -> apply min(4,3) = 3, remaining = 0.
//       total_price = (4-3)*50.0 + 3*0.0 = 50.0 -> average = 50.0/4 = 12.5.
//
// Pre-fix (round-10 bug): the loop had no overlap cap, so it drained all 7
// free units from 'ov' (the cheapest line) -- total_price = (10-7)*1.0 +
// 7*0.0 = 3.0 -> average 0.3 -- and 'go' was never touched at all, even
// though the feasibility proof required get-only units to reach times=7.
// =============================================================================
$overlap_product = new Drw_Bogo_Overlap_Test_Product(1, 1.0);
$buy_only_product = new Drw_Bogo_Overlap_Test_Product(2, 100.0);
$get_only_product = new Drw_Bogo_Overlap_Test_Product(3, 50.0);
$mock_product_categories[1] = [5];
$mock_product_categories[2] = [6];
$mock_product_categories[3] = [7];

$adjustments = [
    'buy_qty'          => 2,
    'get_qty'          => 1,
    'get_product_type' => 'different',
    'discount_type'    => 'free',
    'buy_products'     => [],
    'buy_categories'   => [5, 6],
    'get_products'     => [],
    'get_categories'   => [5, 7],
];

// -----------------------------------------------------------------------
// Case 1 — 'different' branch: overlap-line discount must be capped, the
// get-only line must receive its fair share instead of being skipped.
// -----------------------------------------------------------------------
$cart_1 = new Drw_Bogo_Overlap_Test_Cart([
    'ov' => ['data' => $overlap_product, 'quantity' => 10],
    'bo' => ['data' => $buy_only_product, 'quantity' => 8],
    'go' => ['data' => $get_only_product, 'quantity' => 4],
]);

$result_1 = $bogo->calculate($adjustments, $cart_1);
assert_true(isset($result_1['ov']), "'different' overlap split: the overlap line must still receive some discount.");
assert_same(0.6, $result_1['ov'], "'different' overlap split: overlap line discount must be capped to its overlap_budget (4 of 10 units free) -> average unit price 0.6, not 0.3.");
assert_true(isset($result_1['go']), "'different' overlap split: the get-only line must receive its fair share of the discount instead of being skipped entirely.");
assert_same(12.5, $result_1['go'], "'different' overlap split: get-only line gets 3 of 4 units free -> average unit price 12.5.");
assert_true(!isset($result_1['bo']), "'different' overlap split: the buy-only line must never be discounted.");

// -----------------------------------------------------------------------
// Case 2 — same scenario, 'cheapest' branch. Must match Case 1 exactly: the
// overlap-split accounting and cap are identical to the 'different' branch.
// -----------------------------------------------------------------------
foreach (['cheapest', 'cheapest_in_cart'] as $cheapest_type) {
    $cart_2 = new Drw_Bogo_Overlap_Test_Cart([
        'ov' => ['data' => $overlap_product, 'quantity' => 10],
        'bo' => ['data' => $buy_only_product, 'quantity' => 8],
        'go' => ['data' => $get_only_product, 'quantity' => 4],
    ]);
    $adjustments_2 = $adjustments;
    $adjustments_2['get_product_type'] = $cheapest_type;

    $result_2 = $bogo->calculate($adjustments_2, $cart_2);
    assert_same(0.6, $result_2['ov'], "'{$cheapest_type}' overlap split: overlap line capped to 4 of 10 units free -> average unit price 0.6.");
    assert_same(12.5, $result_2['go'], "'{$cheapest_type}' overlap split: get-only line gets 3 of 4 units free -> average unit price 12.5.");
    assert_true(!isset($result_2['bo']), "'{$cheapest_type}' overlap split: the buy-only line must never be discounted.");
}

// =============================================================================
// Case 3 — regression guard: when overlap_qty is 0 (fully disjoint buy/get
// scopes, tests/test-bogo-different-empty-get-list.php Case 3/10c's shape),
// $overlap_budget is always 0 and no line is ever identified as an overlap
// line, so the new cap must be a complete no-op -- byte-for-byte identical to
// the pre-fix result.
// =============================================================================
$shirt = new Drw_Bogo_Overlap_Test_Product(10, 40.0);
$pants = new Drw_Bogo_Overlap_Test_Product(20, 60.0);

foreach (['different', 'cheapest', 'cheapest_in_cart'] as $type) {
    $cart_3 = new Drw_Bogo_Overlap_Test_Cart([
        'shirt_key' => ['data' => $shirt, 'quantity' => 2],
        'pants_key' => ['data' => $pants, 'quantity' => 1],
    ]);
    $adjustments_3 = [
        'buy_qty'          => 2,
        'get_qty'          => 1,
        'get_product_type' => $type,
        'discount_type'    => 'free',
        'buy_products'     => [10],
        'buy_categories'   => [],
        'get_products'     => [20],
        'get_categories'   => [],
    ];
    $result_3 = $bogo->calculate($adjustments_3, $cart_3);
    assert_same(0.0, $result_3['pants_key'], "Disjoint scopes regression guard ('{$type}'): overlap cap must be a no-op -- buy 2 shirts unlocks 1 free pair of pants, unchanged.");
    assert_true(!isset($result_3['shirt_key']), "Disjoint scopes regression guard ('{$type}'): the buy item itself must not be discounted.");
}

echo "Bogo overlap-split allocation fix OK\n";
