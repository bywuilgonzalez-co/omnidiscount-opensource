<?php
/**
 * Standalone smoke test for CartController::render_minicart_promos_html(), the
 * shared renderer behind the classic mini-cart promos
 * (woocommerce_widget_shopping_cart_before_buttons) and their AJAX fragment.
 *
 * No PHPUnit, no WooCommerce, no database — same hard-failing-assert style as
 * tests/test-cart-fee-stacking-cap.php. render_minicart_promos_html() is a
 * private method, so it is exercised via ReflectionMethod exactly like that
 * test does. Its only collaborators are stubbed here:
 *   - Drw\App\Models\PromoBadgeHelper::collect() is replaced by an in-memory
 *     stand-in that returns whatever badge fixture the test seeds.
 *   - esc_html()/esc_attr() are minimal stand-ins so output escaping is real.
 *
 * Coverage:
 *   - No applicable badge (empty set, threshold already unlocked, non-applied
 *     non-threshold promo, empty copy) => '' (zero DOM footprint bail-out).
 *   - An applied non-threshold promo renders an .is-applied pill with its copy.
 *   - A still-locked threshold promo renders an .is-progress nudge.
 *   - An applied promo with no cart_message falls back to its rule title.
 *   - HTML in the copy is escaped (no raw <script> reaches the markup).
 */

namespace {

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

function assert_contains($needle, $haystack, $message) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\nExpected to find: {$needle}\nIn: {$haystack}\n");
        exit(1);
    }
}

function assert_not_contains($needle, $haystack, $message) {
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\nExpected NOT to find: {$needle}\nIn: {$haystack}\n");
        exit(1);
    }
}

// --- WordPress escaping stand-ins (match core semantics closely enough) ---
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

}

// --- In-memory stand-in for the real PromoBadgeHelper. render_minicart_promos_html()
//     only consumes collect()'s return, so the cart argument is irrelevant here. ---
namespace Drw\App\Models {
    class PromoBadgeHelper {
        public static $badges = [];
        public static function collect($cart) {
            return self::$badges;
        }
    }
}

namespace {

require_once dirname(__DIR__) . '/src/Controllers/CartController.php';

use Drw\App\Controllers\CartController;
use Drw\App\Models\PromoBadgeHelper;

$controller = CartController::instance();

$render = function ($cart) use ($controller) {
    $ref = new ReflectionMethod(CartController::class, 'render_minicart_promos_html');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $cart);
};

// A truthy dummy cart — the stubbed collect() ignores it.
$cart = new stdClass();

// Badge shapes mirror PromoBadgeHelper::collect() output.
$make_progress = function ($remaining) {
    return ['current' => '0', 'target' => '100', 'remaining' => (string) $remaining, 'percent' => 0];
};

// === Case 1: null cart bails out ===
assert_same('', $render(null), 'A null cart must render nothing.');

// === Case 2: no badges bails out ===
PromoBadgeHelper::$badges = [];
assert_same('', $render($cart), 'No badges must render an empty string (zero DOM footprint).');

// === Case 3: applied non-threshold promo renders an .is-applied pill ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 1, 'rule_id' => 10, 'type' => 'percentage',
    'title' => 'Cyber Week', 'message' => '15% off applied', 'applied' => true, 'progress' => null,
]];
$html = $render($cart);
assert_contains('<div class="drw-minicart-promos">', $html, 'Applied promo must be wrapped in the .drw-minicart-promos node.');
assert_contains('drw-minicart-promo is-applied', $html, 'An applied promo must carry the is-applied state class.');
assert_contains('15% off applied', $html, 'Applied promo copy must be rendered.');
assert_contains('drw-minicart-promo__mark', $html, 'Pill must include the status mark element.');

// === Case 4: non-applied non-threshold promo is skipped ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 2, 'rule_id' => 20, 'type' => 'percentage',
    'title' => 'Hidden', 'message' => 'Not yet', 'applied' => false, 'progress' => null,
]];
assert_same('', $render($cart), 'A non-applied non-threshold promo must not render.');

// === Case 5: still-locked threshold promo renders an .is-progress nudge ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 3, 'rule_id' => 30, 'type' => 'free_ship_threshold',
    'title' => 'Free shipping', 'message' => 'Spend $10 more for free shipping',
    'applied' => false, 'progress' => $make_progress(10),
]];
$html = $render($cart);
assert_contains('drw-minicart-promo is-progress', $html, 'A locked threshold promo must carry the is-progress state class.');
assert_contains('Spend $10 more for free shipping', $html, 'Threshold nudge copy must be rendered.');

// === Case 6: an unlocked threshold promo (applied) is NOT nudged ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 3, 'rule_id' => 30, 'type' => 'free_ship_threshold',
    'title' => 'Free shipping', 'message' => 'Spend $0 more for free shipping',
    'applied' => true, 'progress' => $make_progress(0),
]];
assert_same('', $render($cart), 'An already-unlocked threshold promo must not render a nudge.');

// === Case 7: applied promo with empty message falls back to the rule title ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 4, 'rule_id' => 40, 'type' => 'bogo',
    'title' => 'Buy one get one', 'message' => '', 'applied' => true, 'progress' => null,
]];
$html = $render($cart);
assert_contains('Buy one get one', $html, 'Applied promo with no cart_message must fall back to the rule title.');

// === Case 8: applied promo with neither message nor title is skipped ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 5, 'rule_id' => 50, 'type' => 'bogo',
    'title' => '', 'message' => '', 'applied' => true, 'progress' => null,
]];
assert_same('', $render($cart), 'An applied promo with no copy at all must render nothing (no empty pill).');

// === Case 9: HTML in the copy is escaped ===
PromoBadgeHelper::$badges = [[
    'promo_id' => 6, 'rule_id' => 60, 'type' => 'percentage',
    'title' => 'X', 'message' => '<script>alert(1)</script>', 'applied' => true, 'progress' => null,
]];
$html = $render($cart);
assert_not_contains('<script>', $html, 'Promo copy must be escaped — no raw <script> tag may reach the markup.');
assert_contains('&lt;script&gt;', $html, 'Escaped promo copy must appear HTML-entity encoded.');

// === Case 10: mixed set renders only the applicable rows, in order ===
PromoBadgeHelper::$badges = [
    ['promo_id' => 7, 'rule_id' => 70, 'type' => 'percentage', 'title' => 'A', 'message' => 'Applied A', 'applied' => true, 'progress' => null],
    ['promo_id' => 8, 'rule_id' => 80, 'type' => 'percentage', 'title' => 'B', 'message' => 'Skipped B', 'applied' => false, 'progress' => null],
    ['promo_id' => 9, 'rule_id' => 90, 'type' => 'free_ship_threshold', 'title' => 'C', 'message' => 'Nudge C', 'applied' => false, 'progress' => $make_progress(5)],
];
$html = $render($cart);
assert_contains('Applied A', $html, 'Mixed set must render the applied promo.');
assert_not_contains('Skipped B', $html, 'Mixed set must skip the non-applied non-threshold promo.');
assert_contains('Nudge C', $html, 'Mixed set must render the locked threshold nudge.');
assert_true(strpos($html, 'Applied A') < strpos($html, 'Nudge C'), 'Rows must keep collect() order.');

echo "CartController mini-cart promos render OK\n";

}
