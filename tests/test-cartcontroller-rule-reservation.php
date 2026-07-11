<?php
/**
 * Standalone smoke test for CartController's reservation/confirmation wiring
 * for MANUALLY-AUTHORED rules (source IS NULL, created outside the Promos
 * wizard) with their own usage_limit/limit_user — the round-3 audit finding
 * fix: resolve_order_rule_ids() / attempt_rule_reservation() /
 * resolve_line_item_rule_id(), companions to the promo-only
 * (resolve_order_promo_ids()) path already covered by
 * tests/test-cartcontroller-promo-reservation.php.
 *
 * Same harness/style as that file: stub Drw\App\Models\{PromoModel,RuleModel,
 * CustomerIdentity} in their real namespace, minimal WC_Order/WC_Order_Item/
 * $wpdb stand-ins, hard-failing assert helpers.
 *
 * Coverage:
 *   (a) A manually-authored rule (item carries '_drw_rule_id', no
 *       '_drw_promo_id') with limit_user reserves successfully -> no
 *       exception, try_reserve_usage() called with promo_id=null, order
 *       flagged '_drw_promos_reserved'.
 *   (b) Hard block: a denied manually-authored rule WITH limit_user ->
 *       reserve_promo_usage() throws, message names the rule title (no promo
 *       row exists to fall back to).
 *   (c) Soft-fail: a denied manually-authored rule with ONLY a bare
 *       usage_limit -> no exception, order still flagged.
 *   (d) Mixed order: one item attributed to a Vía B promo, another to a
 *       manually-authored rule -> BOTH are reserved independently, each with
 *       the correct promo_id (7 vs null).
 *   (e) track_promo_redemptions(): confirm_usage() is called for a manually-
 *       authored rule's own reservation, WITHOUT any
 *       PromoModel::increment_usage() call (no promo row exists for it).
 *   (f) A manually-authored rule with NEITHER usage_limit nor limit_user
 *       configured -> resolve_order_rule_ids() still returns it (attribution
 *       is independent of the cap check), but try_reserve_usage() is never
 *       called (attempt_rule_reservation()'s own gate).
 */

namespace Drw\App\Models {

    class RuleModel {
        public static $rules = array();
        public static $reserve_result = true;
        public static $reserve_calls = array();
        public static $release_calls = array();
        public static $confirm_calls = array();
        public static $customer_redemption_count = PHP_INT_MAX;
        public static $customer_redemption_count_calls = array();

        public static function reset() {
            self::$rules          = array();
            self::$reserve_result = true;
            self::$reserve_calls  = array();
            self::$release_calls  = array();
            self::$confirm_calls  = array();
            self::$customer_redemption_count = PHP_INT_MAX;
            self::$customer_redemption_count_calls = array();
        }

        public static function get_rule($id) {
            $id = (int) $id;
            return isset(self::$rules[$id]) ? self::$rules[$id] : null;
        }

        public static function customer_redemption_count($rule_id, $customer_key) {
            self::$customer_redemption_count_calls[] = array(
                'rule_id'      => $rule_id,
                'customer_key' => $customer_key,
            );
            return self::$customer_redemption_count;
        }

        public static function try_reserve_usage($rule_id, $customer_key, $order_id, $promo_id = null) {
            self::$reserve_calls[] = array(
                'rule_id'      => $rule_id,
                'customer_key' => $customer_key,
                'order_id'     => $order_id,
                'promo_id'     => $promo_id,
            );
            if (is_array(self::$reserve_result)) {
                $idx = count(self::$reserve_calls) - 1;
                return isset(self::$reserve_result[$idx]) ? self::$reserve_result[$idx] : true;
            }
            return self::$reserve_result;
        }

        public static function release_usage($rule_id, $order_id) {
            self::$release_calls[] = array('rule_id' => $rule_id, 'order_id' => $order_id);
            return true;
        }

        public static function confirm_usage($rule_id, $order_id) {
            self::$confirm_calls[] = array('rule_id' => $rule_id, 'order_id' => $order_id);
            return true;
        }
    }

    class PromoModel {
        public static $rows = array();
        public static $codes = array();
        public static $increment_calls = array();

        public static function reset() {
            self::$rows            = array();
            self::$codes           = array();
            self::$increment_calls = array();
        }

        public static function get_promo($id) {
            $id = (int) $id;
            return isset(self::$rows[$id]) ? self::$rows[$id] : null;
        }

        public static function get_promo_by_code($code) {
            $code = (string) $code;
            return isset(self::$codes[$code]) ? self::$rows[self::$codes[$code]] : null;
        }

        public static function increment_usage($id) {
            self::$increment_calls[] = (int) $id;
        }
    }

    class CustomerIdentity {
        public static $key = 'user:42';

        public static function reset() {
            self::$key = 'user:42';
        }

        public static function resolve_from_order($order) {
            return self::$key;
        }
    }
}

namespace {

    define('ABSPATH', dirname(__DIR__) . '/');

    function assert_same($expected, $actual, $message) {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assert_true($condition, $message) {
        assert_same(true, (bool)$condition, $message);
    }

    function __($text, $domain = null) {
        return $text;
    }

    $GLOBALS['__drw_test_transients'] = array();
    function get_transient($key) {
        return isset($GLOBALS['__drw_test_transients'][$key]) ? $GLOBALS['__drw_test_transients'][$key] : false;
    }
    function set_transient($key, $value, $expiration) {
        $GLOBALS['__drw_test_transients'][$key] = $value;
        return true;
    }
    function sanitize_text_field($value) {
        return is_string($value) ? trim($value) : $value;
    }
    function wp_unslash($value) {
        return $value;
    }

    class WpdbStub {
        public $prefix = 'wp_';
        public $get_col_return = array();
        public $get_results_return = array();
        public $get_var_return = null;
        public $prepared_queries = array();
        public $last_prepared_args = array();

        public function prepare($query, ...$args) {
            $this->prepared_queries[]  = array('query' => $query, 'args' => $args);
            $this->last_prepared_args = $args;
            return $query;
        }
        public function get_col($query) {
            return $this->get_col_return;
        }
        public function get_results($query, $output = ARRAY_A) {
            return $this->get_results_return;
        }
        public function get_var($query) {
            if (is_callable($this->get_var_return)) {
                return call_user_func($this->get_var_return, $this->last_prepared_args);
            }
            return $this->get_var_return;
        }
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    $GLOBALS['wpdb'] = new WpdbStub();

    function current_time($type, $gmt = 0) {
        return '2026-07-10 12:00:00';
    }

    class WC_Order {
        private $id;
        private $meta = array();
        private $coupon_codes = array();
        private $items = array();
        public $saved = 0;

        public function __construct($id) {
            $this->id = (int) $id;
        }
        public function get_id() {
            return $this->id;
        }
        public function get_meta($key, $single = true) {
            return isset($this->meta[$key]) ? $this->meta[$key] : '';
        }
        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
        }
        public function save() {
            $this->saved++;
        }
        public function set_coupon_codes(array $codes) {
            $this->coupon_codes = $codes;
        }
        public function get_coupon_codes() {
            return $this->coupon_codes;
        }
        public function add_item(WC_Order_Item $item) {
            $this->items[] = $item;
        }
        public function get_items() {
            return $this->items;
        }
    }

    class WC_Order_Item {
        private $meta;
        public function __construct(array $meta = array()) {
            $this->meta = $meta;
        }
        public function get_meta($key, $single = true) {
            return isset($this->meta[$key]) ? $this->meta[$key] : '';
        }
    }

    require_once dirname(__DIR__) . '/src/Controllers/RateLimiter.php';
    require_once dirname(__DIR__) . '/src/Controllers/CartController.php';

    use Drw\App\Controllers\CartController;
    use Drw\App\Models\PromoModel;
    use Drw\App\Models\RuleModel;
    use Drw\App\Models\CustomerIdentity;

    function reset_world() {
        PromoModel::reset();
        RuleModel::reset();
        CustomerIdentity::reset();
        $GLOBALS['wpdb'] = new WpdbStub();
    }

    $controller = CartController::instance();

    // === (a) Manually-authored rule, limit_user, reserves successfully =====
    reset_world();
    RuleModel::$rules[201] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Manual');
    RuleModel::$reserve_result = true;

    $order = new WC_Order(3001);
    $order->add_item(new WC_Order_Item(array('_drw_rule_id' => '201')));

    $threw = false;
    try {
        $controller->reserve_promo_usage(3001, array(), $order);
    } catch (\Exception $e) {
        $threw = true;
    }
    assert_true(!$threw, '(a) A successful manually-authored reservation must not throw.');
    assert_same(1, count(RuleModel::$reserve_calls), '(a) try_reserve_usage() must be called exactly once.');
    assert_same(201, RuleModel::$reserve_calls[0]['rule_id'], '(a) try_reserve_usage() must target the resolved rule_id.');
    assert_same(null, RuleModel::$reserve_calls[0]['promo_id'], '(a) A manually-authored rule must reserve with promo_id=null.');
    assert_same(1, $order->get_meta('_drw_promos_reserved'), '(a) Order must be flagged reserved after a successful pass.');

    // === (b) Hard block: manually-authored rule WITH limit_user denies =====
    reset_world();
    RuleModel::$rules[202] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Manual Bloqueante');
    RuleModel::$reserve_result = false;

    $order2 = new WC_Order(3002);
    $order2->add_item(new WC_Order_Item(array('_drw_rule_id' => '202')));

    $caught = null;
    try {
        $controller->reserve_promo_usage(3002, array(), $order2);
    } catch (\Exception $e) {
        $caught = $e;
    }
    assert_true(null !== $caught, '(b) A denied manually-authored limit_user reservation must throw.');
    assert_true(false !== strpos($caught->getMessage(), 'Regla Manual Bloqueante'), '(b) The exception message must name the rule title (no promo row to fall back to).');
    assert_same('', $order2->get_meta('_drw_promos_reserved'), '(b) A blocked order must NOT be flagged reserved.');

    // === (c) Soft-fail: manually-authored rule with ONLY bare usage_limit ===
    reset_world();
    RuleModel::$rules[203] = array('limit_user' => null, 'usage_limit' => 50, 'title' => 'Regla Manual Suave');
    RuleModel::$reserve_result = false;

    $order3 = new WC_Order(3003);
    $order3->add_item(new WC_Order_Item(array('_drw_rule_id' => '203')));

    $threw3 = false;
    try {
        $controller->reserve_promo_usage(3003, array(), $order3);
    } catch (\Exception $e) {
        $threw3 = true;
    }
    assert_true(!$threw3, '(c) A bare usage_limit denial on a manually-authored rule must NOT throw.');
    assert_same(1, $order3->get_meta('_drw_promos_reserved'), '(c) The order must still be flagged reserved even after a soft-fail.');

    // === (d) Mixed order: one Vía B promo item + one manually-authored item =
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);
    RuleModel::$rules[55]  = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Promo');
    RuleModel::$rules[204] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Manual Mixta');
    RuleModel::$reserve_result = true;

    $order4 = new WC_Order(3004);
    $order4->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));
    $order4->add_item(new WC_Order_Item(array('_drw_rule_id' => '204')));

    $controller->reserve_promo_usage(3004, array(), $order4);
    assert_same(2, count(RuleModel::$reserve_calls), '(d) Both the promo-backed rule and the manually-authored rule must be reserved.');
    $by_rule = array();
    foreach (RuleModel::$reserve_calls as $call) {
        $by_rule[$call['rule_id']] = $call;
    }
    assert_same(7, $by_rule[55]['promo_id'], "(d) The promo-backed rule's call must carry promo_id=7.");
    assert_same(null, $by_rule[204]['promo_id'], "(d) The manually-authored rule's call must carry promo_id=null.");
    assert_same(1, $order4->get_meta('_drw_promos_reserved'), '(d) The mixed order must be flagged reserved after both succeed.');

    // === (e) track_promo_redemptions(): confirm_usage() without increment_usage() ===
    reset_world();
    RuleModel::$rules[205] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Manual Confirmada');

    $order5 = new WC_Order(4001);
    $order5->add_item(new WC_Order_Item(array('_drw_rule_id' => '205')));

    $controller->track_promo_redemptions(4001, $order5);
    assert_same(array(), PromoModel::$increment_calls, '(e) No PromoModel::increment_usage() call for a manually-authored rule -- there is no promo row.');
    assert_same(1, count(RuleModel::$confirm_calls), '(e) confirm_usage() must be called once for the manually-authored rule.');
    assert_same(array('rule_id' => 205, 'order_id' => 4001), RuleModel::$confirm_calls[0], '(e) confirm_usage() must target the correct rule_id/order_id pair.');
    assert_same(1, $order5->get_meta('_drw_promos_counted'), '(e) The counted flag must be set.');

    // === (f) A rule with no caps configured is attributed but never reserved =
    reset_world();
    RuleModel::$rules[206] = array('limit_user' => null, 'usage_limit' => null, 'title' => 'Regla Manual Sin Límite');

    $order6 = new WC_Order(3006);
    $order6->add_item(new WC_Order_Item(array('_drw_rule_id' => '206')));

    $controller->reserve_promo_usage(3006, array(), $order6);
    assert_same(0, count(RuleModel::$reserve_calls), '(f) A rule with neither usage_limit nor limit_user must never call try_reserve_usage().');
    assert_same(1, $order6->get_meta('_drw_promos_reserved'), '(f) The order must still be flagged reserved.');

    echo "CartController manually-authored rule reservation OK\n";
}
