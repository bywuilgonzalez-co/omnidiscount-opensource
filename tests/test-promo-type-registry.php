<?php
/**
 * Focused smoke test for the unified promo type catalogue.
 *
 * Guards the fix for Hallazgo #3 (drw-cupones-promociones plan): PHP and JS
 * used to keep two independent, drifting type catalogues. This test locks
 * down the single source of truth (PromoTypeRegistry) so a future edit
 * cannot silently reintroduce a divergence.
 */
define('ABSPATH', dirname(__DIR__) . '/');

function __($text, $domain = null) {
    return $text;
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true($condition, $message) {
    assert_same(true, (bool) $condition, $message);
}

require_once dirname(__DIR__) . '/src/Models/PromoTypeRegistry.php';

use Drw\App\Models\PromoTypeRegistry;

$expected_ids = [
    'percent', 'fixed', 'launch', '2x1', '3x2', 'second_unit', 'tiered',
    'bundle', 'free_ship_threshold', 'free_ship', 'welcome', 'gift',
    'cashback', 'flash', 'data_capture',
];

assert_same($expected_ids, PromoTypeRegistry::ids(), 'Registry should expose exactly the 15 known type ids, in catalogue order.');
assert_same(count($expected_ids), count(PromoTypeRegistry::all()), 'all() should return one definition per known id.');

foreach (PromoTypeRegistry::all() as $type) {
    foreach (['id', 'label', 'short', 'icon', 'color', 'needsCode', 'valueType'] as $field) {
        assert_true(array_key_exists($field, $type), "Type '{$type['id']}' should define the '{$field}' field.");
    }
    assert_true(
        in_array($type['valueType'], ['percent', 'currency', 'none', 'text'], true),
        "Type '{$type['id']}' should have a recognised valueType, got '{$type['valueType']}'."
    );
}

assert_true(PromoTypeRegistry::exists('percent'), 'exists() should be true for a known type id.');
assert_true(!PromoTypeRegistry::exists('not-a-real-type'), 'exists() should be false for an unknown type id.');
assert_same(null, PromoTypeRegistry::get('not-a-real-type'), 'get() should return null for an unknown type id.');

// Regression guard: PHP and JS used to disagree on these exact fields
// (see Hallazgo #3). Unifying the catalogue means picking one answer;
// these assertions document and lock in the resolved values.
assert_same(false, PromoTypeRegistry::needs_code('second_unit'), 'second_unit is an automatic Vía B mechanic (plan §2) and must not require a code.');
assert_same('currency', PromoTypeRegistry::get('launch')['valueType'], 'launch collects a launch price (COP), not a percentage.');
assert_same('currency', PromoTypeRegistry::get('bundle')['valueType'], 'bundle collects a set price (COP), not a percentage.');
assert_same('text', PromoTypeRegistry::get('gift')['valueType'], 'gift collects free-text gift copy, not a numeric value.');

echo "Promo type registry OK\n";
