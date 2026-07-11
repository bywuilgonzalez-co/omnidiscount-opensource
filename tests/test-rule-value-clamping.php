<?php
/**
 * Standalone smoke test for RuleModel::sanitize_adjustments() clamping
 * percentage values to [0, 100] at the persistence boundary. This closes the
 * negative-price hole for hand-authored "Modo experto" rules (not just migrated
 * data). No PHPUnit, no WooCommerce, no database — same style as
 * tests/test-rule-payload-normalizer.php. The private method is reached via
 * reflection, exactly like tests/test-promo-bridge.php.
 */

define('ABSPATH', dirname(__DIR__) . '/');

function sanitize_text_field($value) {
    return trim(strip_tags((string)$value));
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Models/RuleModel.php';

use Drw\App\Models\RuleModel;

function sanitize_adjustments_via_model($payload) {
    $ref = new ReflectionMethod(RuleModel::class, 'sanitize_adjustments');
    $ref->setAccessible(true);
    return $ref->invoke(null, $payload);
}

// Percentage overshoot (value = 500) clamps to 100.0.
$adj = sanitize_adjustments_via_model(['type' => 'percentage', 'value' => 500]);
assert_same('percentage', $adj['type'], 'Type stays percentage.');
assert_same(100.0, $adj['value'], 'Percentage value 500 must clamp to 100.0.');

// Negative percentage clamps up to 0.0.
$neg = sanitize_adjustments_via_model(['type' => 'percentage', 'value' => -25]);
assert_same(0.0, $neg['value'], 'Negative percentage clamps to 0.0.');

// In-range value is preserved (as a float).
$mid = sanitize_adjustments_via_model(['type' => 'percentage', 'value' => 30]);
assert_same(30.0, $mid['value'], 'In-range percentage is preserved as float.');

// Bulk percentage tiers are clamped too; fixed tiers stay untouched.
$bulk = sanitize_adjustments_via_model([
    'type'  => 'bulk',
    'tiers' => [
        ['min' => 1, 'max' => 3, 'type' => 'percentage', 'value' => 500],
        ['min' => 4, 'max' => '', 'type' => 'fixed', 'value' => 999],
    ],
]);
assert_same('bulk', $bulk['type'], 'Type stays bulk.');
assert_same(100.0, $bulk['tiers'][0]['value'], 'Bulk percentage tier 500 clamps to 100.0.');
assert_same(999, $bulk['tiers'][1]['value'], 'Bulk fixed tier value is untouched by the percentage clamp.');

echo "Rule value clamping OK\n";
