<?php
/**
 * Standalone smoke test for CartController::scale_fees_to_subtotal(), the
 * safety net that stops several stacked, non-exclusive automatic cart promos
 * from combining into a fee total larger than the cart subtotal (an
 * unintended free/negative checkout).
 *
 * No PHPUnit, no WooCommerce, no database — same hard-failing-assert style as
 * tests/test-rulesengine-percentage-floor.php. scale_fees_to_subtotal() is a
 * pure private method, so it is exercised via ReflectionMethod, exactly like
 * that test does with the private apply_rule_adjustments().
 *
 * Coverage:
 *   - Fees summing to 150% of the subtotal are scaled down to sum to exactly
 *     the subtotal, preserving the relative proportion between fees.
 *   - Fees summing to less than the subtotal are returned bit-for-bit
 *     unchanged (the array is returned identical, === to the input).
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

/** Fees are scaled by a repeating fraction (2/3), so compare within tolerance. */
function assert_close($expected, $actual, $message, $epsilon = 1e-9) {
    if (abs((float)$expected - (float)$actual) > $epsilon) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Controllers/CartController.php';

use Drw\App\Controllers\CartController;

$controller = CartController::instance();

$invoke_scale = function(array $fees, $subtotal) use ($controller) {
    $ref = new ReflectionMethod(CartController::class, 'scale_fees_to_subtotal');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $fees, $subtotal);
};

// Helper: sum the absolute magnitude of a fee set's amounts.
$sum_abs = function(array $fees) {
    $total = 0.0;
    foreach ($fees as $fee) {
        $total += abs((float)$fee['amount']);
    }
    return $total;
};

// === Case 1: fees summing to 150% of the subtotal are scaled to exactly 100% ===
// Amounts are negative, exactly as RulesEngine::apply_rule_adjustments() emits.
$subtotal = 100.0;
$fees = [
    ['name' => 'Promo A', 'amount' => -90.0],
    ['name' => 'Promo B', 'amount' => -60.0],
];
// Combined magnitude 150 == 150% of the 100 subtotal.
assert_close(150.0, $sum_abs($fees), 'Fixture must sum to 150% of the subtotal before scaling.');

$scaled = $invoke_scale($fees, $subtotal);

assert_close($subtotal, $sum_abs($scaled), 'Fees at 150% must be scaled to sum to exactly the subtotal.');
assert_true($scaled[0]['amount'] < 0 && $scaled[1]['amount'] < 0, 'Scaled fees must stay negative (discounts).');
// Proportion preserved: both fees scaled by the same factor, so 90:60 (=1.5) holds exactly.
assert_close(
    90.0 / 60.0,
    $scaled[0]['amount'] / $scaled[1]['amount'],
    'Scaling must preserve the relative proportion (1.5) between the two fees.'
);
// Names are carried through untouched.
assert_same('Promo A', $scaled[0]['name'], 'Fee name must be preserved through scaling.');
assert_same('Promo B', $scaled[1]['name'], 'Fee name must be preserved through scaling.');
// Each fee lands on its exact proportional share: 90*(100/150)=60, 60*(100/150)=40.
assert_close(-60.0, $scaled[0]['amount'], 'Promo A must scale to its 60/100 share of the subtotal.');
assert_close(-40.0, $scaled[1]['amount'], 'Promo B must scale to its 40/100 share of the subtotal.');

// === Case 2: fees summing to LESS than the subtotal are left untouched ===
$subtotal = 100.0;
$fees = [
    ['name' => 'Promo A', 'amount' => -30.0],
    ['name' => 'Promo B', 'amount' => -20.0],
];
// Combined magnitude 50 < 100 subtotal.
assert_close(50.0, $sum_abs($fees), 'Fixture must sum to less than the subtotal.');

$scaled = $invoke_scale($fees, $subtotal);

// Returned bit-for-bit unchanged: same values, same order, same types.
assert_same($fees, $scaled, 'Fees summing below the subtotal must be returned unchanged.');
assert_close(50.0, $sum_abs($scaled), 'Under-subtotal fees keep their original combined magnitude.');

// === Case 3: exact-boundary sanity — sum equal to subtotal is not scaled ===
$subtotal = 100.0;
$fees = [
    ['name' => 'Promo A', 'amount' => -100.0],
];
$scaled = $invoke_scale($fees, $subtotal);
assert_same($fees, $scaled, 'Fees summing to exactly the subtotal must be left untouched.');

// === Case 4: zero real subtotal (fully item-discounted cart) zeroes fees ===
// Guards against a negative checkout when items are already free but stacked
// cart fees still carry a discount.
$fees = [
    ['name' => 'Promo A', 'amount' => -30.0],
    ['name' => 'Promo B', 'amount' => -20.0],
];
$scaled = $invoke_scale($fees, 0.0);
assert_close(0.0, $sum_abs($scaled), 'Fees against a zero subtotal must scale to sum to exactly zero.');

echo "CartController fee stacking cap OK\n";
