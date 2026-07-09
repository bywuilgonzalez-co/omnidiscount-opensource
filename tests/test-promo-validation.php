<?php
/**
 * Focused smoke test for PromosController::validate_promo() hardening:
 * code uniqueness (vs. other promos and vs. native WC coupons), needsCode
 * enforcement per PromoTypeRegistry, and end >= start.
 */
define('ABSPATH', dirname(__DIR__) . '/');

function __($text, $domain = null) {
    return $text;
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function absint($value) {
    return abs((int) $value);
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

/**
 * Minimal WP_Error stand-in, matching the subset of the API this
 * controller actually uses (get_error_message/get_error_code/get_error_data).
 */
class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = array()) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_data() {
        return $this->data;
    }
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

// Stub, overridden per-scenario below via $GLOBALS['wc_coupon_codes'].
$GLOBALS['wc_coupon_codes'] = array();
function wc_get_coupon_id_by_code($code) {
    return isset($GLOBALS['wc_coupon_codes'][$code]) ? $GLOBALS['wc_coupon_codes'][$code] : 0;
}

// In-memory stand-in for the wp_options-backed promo store.
$GLOBALS['wp_options'] = array();
function get_option($key, $default = false) {
    return isset($GLOBALS['wp_options'][$key]) ? $GLOBALS['wp_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['wp_options'][$key] = $value;
    return true;
}
function wp_json_encode($value) {
    return json_encode($value);
}

require_once dirname(__DIR__) . '/src/Models/PromoTypeRegistry.php';
require_once dirname(__DIR__) . '/src/Controllers/PromosController.php';

$controller = new ReflectionClass('Drw\App\Controllers\PromosController');
$instance   = $controller->newInstanceWithoutConstructor();

function call_validate($controller, $instance, $data, $is_update = false, $exclude_id = null) {
    $method = $controller->getMethod('validate_promo');
    $method->setAccessible(true);
    return $method->invoke($instance, $data, $is_update, $exclude_id);
}

function seed_promos($controller, $instance, $promos) {
    $prop = $controller->getMethod('save_promos');
    $prop->setAccessible(true);
    $prop->invoke($instance, $promos);
}

// --- needsCode is enforced from the catalogue, not hardcoded ------------------
$result = call_validate($controller, $instance, array('name' => 'Sin código', 'type' => 'percent', 'value' => 10));
assert_true(is_wp_error($result), 'percent requires a code (PromoTypeRegistry::needs_code) and must fail without one.');
assert_same('code_required', $result->get_error_code(), 'Missing required code should fail with code_required.');
assert_same('code', $result->get_error_data()['field'], 'code_required error should be attributed to the code field.');

$result = call_validate($controller, $instance, array('name' => '2x1 automático', 'type' => '2x1', 'value' => 0));
assert_true(!is_wp_error($result), '2x1 is an automatic (Vía B) type and must not require a code.');

// --- end >= start ---------------------------------------------------------------
$result = call_validate($controller, $instance, array(
    'name' => 'Fechas invertidas', 'type' => '2x1', 'value' => 0,
    'start' => '2026-08-01', 'end' => '2026-07-01',
));
assert_true(is_wp_error($result), 'end before start must be rejected.');
assert_same('invalid_date_range', $result->get_error_code(), 'Inverted dates should fail with invalid_date_range.');

$result = call_validate($controller, $instance, array(
    'name' => 'Fechas iguales', 'type' => '2x1', 'value' => 0,
    'start' => '2026-08-01', 'end' => '2026-08-01',
));
assert_true(!is_wp_error($result), 'end == start must be allowed (end >= start).');

// --- code uniqueness against other promos ----------------------------------------
seed_promos($controller, $instance, array(
    array('id' => 1, 'code' => 'VERANO10', 'name' => 'Existente'),
));

$result = call_validate($controller, $instance, array('name' => 'Duplicado', 'type' => 'percent', 'value' => 10, 'code' => 'verano10'));
assert_true(is_wp_error($result), 'A code already used by another promo must be rejected (case-insensitive, code is upper-cased).');
assert_same('duplicate_code', $result->get_error_code(), 'Duplicate promo code should fail with duplicate_code.');

// Updating the same promo that already owns the code must not collide with itself.
$result = call_validate($controller, $instance, array('name' => 'Editar promo 1', 'type' => 'percent', 'value' => 10, 'code' => 'VERANO10'), true, 1);
assert_true(!is_wp_error($result), 'A promo must be allowed to keep its own code when updated.');

// --- code uniqueness against native WooCommerce coupons --------------------------
seed_promos($controller, $instance, array());
$GLOBALS['wc_coupon_codes'] = array('FALLNATIVE' => 55);

$result = call_validate($controller, $instance, array('name' => 'Choca con WC_Coupon', 'type' => 'percent', 'value' => 10, 'code' => 'FALLNATIVE'));
assert_true(is_wp_error($result), 'A code already used by a native WC_Coupon (from any plugin) must be rejected.');
assert_same('duplicate_code', $result->get_error_code(), 'WC coupon collision should also fail with duplicate_code.');

echo "Promo validation hardening OK\n";
