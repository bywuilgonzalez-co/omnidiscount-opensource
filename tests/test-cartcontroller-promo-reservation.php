<?php
/**
 * Standalone smoke test for CartController's promo/rule usage reservation
 * wiring (reserve_promo_usage() / reserve_promo_usage_store_api() /
 * release_reserved_usage() / release_stale_promo_reservations(), plus the
 * confirm_usage() extension folded into track_promo_redemptions()).
 *
 * No PHPUnit, no WooCommerce, no database — same style as
 * tests/test-promo-bridge.php and tests/test-promo-migration.php: stub
 * Drw\App\Models\{PromoModel,RuleModel,CustomerIdentity} in their real
 * namespace (never requiring the real files, so every call is fully
 * controlled/recorded), minimal WC_Order/WC_Order_Item/$wpdb stand-ins, and
 * hard-failing assert helpers.
 *
 * Coverage:
 *   (a) Vía B promo, rule has limit_user only, reservation succeeds -> no
 *       exception, order flagged '_drw_promos_reserved'.
 *   (b) Idempotency: a second reserve_promo_usage() call on the same
 *       (already-flagged) order must NOT call try_reserve_usage() again.
 *   (c) Hard block: try_reserve_usage() denies a rule WITH limit_user set ->
 *       reserve_promo_usage() throws, message names the rule/promo title.
 *   (d) Soft-fail: try_reserve_usage() denies a rule with ONLY a bare
 *       usage_limit (no limit_user) -> NO exception, order still flagged.
 *   (e) Vía A (coupon) promo has no rule_id -> never reaches
 *       try_reserve_usage() at all.
 *   (f) reserve_promo_usage_store_api() adapts the 1-arg Store API hook shape
 *       into the same reservation logic.
 *   (g) release_reserved_usage() calls RuleModel::release_usage() once per
 *       distinct rule_id found for the order.
 *   (h) track_promo_redemptions(): confirm_usage() is called for a Vía B
 *       promo's rule alongside the existing increment_usage() counting, and
 *       the pre-existing '_drw_promos_counted' idempotency guard still blocks
 *       a second run entirely (regression check for the refactor extracting
 *       resolve_order_promo_ids()).
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for RuleModel. Every try_reserve_usage() /
     * release_usage() / confirm_usage() call is recorded so the test can
     * assert exactly what CartController asked for, and each rule's
     * resolution + reservation outcome is fully controllable per test case.
     */
    class RuleModel {
        /** @var array<int,array> Rule rows keyed by id: ['limit_user'=>,'usage_limit'=>,'title'=>]. */
        public static $rules = array();
        /** @var bool|array Next try_reserve_usage() return value, or a queue keyed by call index. */
        public static $reserve_result = true;
        /** @var array Recorded try_reserve_usage() calls. */
        public static $reserve_calls = array();
        /** @var array Recorded release_usage() calls. */
        public static $release_calls = array();
        /** @var array Recorded confirm_usage() calls. */
        public static $confirm_calls = array();
        /**
         * @var int Value returned by customer_redemption_count() for every
         * rule/customer, used by reserve_promo_usage() to disambiguate a
         * genuine per-customer limit_user denial from a global usage_limit
         * exhaustion / transient error (see the (j) test case below).
         * Defaults to PHP_INT_MAX ("this customer is exhausted") so every
         * pre-existing hard-block test case keeps throwing exactly as before
         * without needing to know about this stub.
         */
        public static $customer_redemption_count = PHP_INT_MAX;
        /** @var array Recorded customer_redemption_count() calls. */
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

    /**
     * In-memory stand-in for PromoModel. Promos and their promo_by_code
     * lookup are seeded directly; increment_usage() calls are recorded.
     */
    class PromoModel {
        /** @var array<int,array> Promo rows keyed by id. */
        public static $rows = array();
        /** @var array<string,int> code (already upper-cased) => promo id. */
        public static $codes = array();
        /** @var array Recorded increment_usage() calls. */
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

    /**
     * In-memory stand-in for CustomerIdentity. resolve_from_order() returns a
     * fixed, test-controlled key regardless of the order passed in.
     */
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

    // --- Minimal WP shims --------------------------------------------------
    function __($text, $domain = null) {
        return $text;
    }
    function function_exists_stub_marker() {}

    /**
     * Minimal transient store backing RateLimiter::check() (see
     * src/Controllers/RateLimiter.php), which reserve_promo_usage() now calls
     * on its customer_exhausted hard-block path (per-IP disclosure throttle,
     * added as part of the promo-usage-reservation guest-email-oracle fix).
     * Same shape as tests/test-rate-limiter.php's own stub.
     */
    $GLOBALS['__drw_test_transients'] = array();
    function get_transient($key) {
        return isset($GLOBALS['__drw_test_transients'][$key]) ? $GLOBALS['__drw_test_transients'][$key] : false;
    }
    function set_transient($key, $value, $expiration) {
        $GLOBALS['__drw_test_transients'][$key] = $value;
        return true;
    }
    /** sanitize_text_field()/wp_unslash() stubs for CartController::get_client_ip(). */
    function sanitize_text_field($value) {
        return is_string($value) ? trim($value) : $value;
    }
    function wp_unslash($value) {
        return $value;
    }

    // --- Minimal $wpdb stand-in for release_reserved_usage()/release_stale_promo_reservations() ---
    class WpdbStub {
        public $prefix = 'wp_';
        /**
         * Connection handle stand-in, read by CartController's
         * wpdb_connection_fingerprint() reconnect guard (round-10 audit
         * finding). Declared (rather than left dynamic) so PHP 8.2 doesn't
         * emit a "Creation of dynamic property" deprecation when a test
         * assigns it.
         *
         * @var object|null
         */
        public $dbh = null;
        /** @var array Queued return value for the next get_col() call. */
        public $get_col_return = array();
        /** @var array Queued return value for the next get_results() call. */
        public $get_results_return = array();
        /**
         * Return value for the next get_var() call (used by
         * order_already_holds_reservation()): either a fixed scalar, or a
         * callable( array $last_prepared_args ) for tests that need to
         * differentiate by the (order_id, rule_id) actually queried.
         *
         * @var mixed
         */
        public $get_var_return = null;
        /** @var array Recorded queries passed to prepare(). */
        public $prepared_queries = array();
        /** @var array Args from the most recent prepare() call. */
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

    /**
     * $wpdb stand-in for the reconnect-guard test case only: ->dbh returns a
     * BRAND NEW object identity on every single access, standing in for
     * wpdb's transparent "MySQL server has gone away" reconnect happening
     * between track_promo_redemptions()'s lock-fingerprint capture and its
     * later re-check of that fingerprint -- exactly the round-10 audit gap
     * (GET_LOCK()'s session-scoped mutex silently dropped mid-method).
     */
    class WpdbStubReconnecting extends WpdbStub {
        public $dbh_reads = 0;
        /**
         * @var object[] Every ->dbh ever returned, kept alive here. Without
         * this, each ephemeral stdClass would be garbage-collected the
         * instant its containing expression finishes, and PHP's allocator is
         * free to hand the NEXT stdClass the exact spl_object_id() the freed
         * one just vacated -- silently defeating the "always a new identity"
         * simulation this stub exists to provide (the very destroy-then-
         * recreate id-reuse hazard RuleModel::connection_fingerprint()'s own
         * docblock warns about for the non-mysqli/spl_object_id() fallback).
         */
        public $created = array();
        public function __construct() {
            // WpdbStub declares a real public $dbh property; a declared
            // property is always read directly, bypassing __get()/__isset(),
            // even from a subclass. unset() removes it from THIS instance's
            // property table so PHP falls through to the magic methods below
            // on every subsequent access -- the only way to make ->dbh
            // return a fresh identity on each read.
            unset($this->dbh);
        }
        public function __get($name) {
            if ($name === 'dbh') {
                $this->dbh_reads++;
                $obj = new \stdClass();
                $this->created[] = $obj;
                return $obj;
            }
            return null;
        }
        public function __isset($name) {
            return $name === 'dbh';
        }
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    $GLOBALS['wpdb'] = new WpdbStub();

    /** current_time() stub used by release_stale_promo_reservations(). */
    function current_time($type, $gmt = 0) {
        return '2026-07-10 12:00:00';
    }

    /**
     * Minimal WC_Order stand-in. Tracks meta in-memory and records save()
     * calls so idempotency flags can be asserted.
     */
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

    /** Minimal WC_Order_Item stand-in: only get_meta() is needed. */
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

    // === (a) Vía B promo, limit_user only, reservation succeeds ============
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);
    RuleModel::$rules[55] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla 55');
    RuleModel::$reserve_result = true;

    $order = new WC_Order(1001);
    $order->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));

    $threw = false;
    try {
        $controller->reserve_promo_usage(1001, array(), $order);
    } catch (\Exception $e) {
        $threw = true;
    }
    assert_true(!$threw, '(a) A successful reservation must not throw.');
    assert_same(1, count(RuleModel::$reserve_calls), '(a) try_reserve_usage() must be called exactly once.');
    assert_same(55, RuleModel::$reserve_calls[0]['rule_id'], '(a) try_reserve_usage() must target the promo\'s resolved rule_id.');
    assert_same('user:42', RuleModel::$reserve_calls[0]['customer_key'], '(a) try_reserve_usage() must receive the resolved customer key.');
    assert_same(1001, RuleModel::$reserve_calls[0]['order_id'], '(a) try_reserve_usage() must receive the order id.');
    assert_same(7, RuleModel::$reserve_calls[0]['promo_id'], '(a) try_reserve_usage() must receive the promo id.');
    assert_same(1, $order->get_meta('_drw_promos_reserved'), '(a) Order must be flagged reserved after a successful pass.');
    assert_same(1, $order->saved, '(a) Order must be saved exactly once.');

    // === (b) Idempotency: a second call on the same (flagged) order is a no-op ===
    $controller->reserve_promo_usage(1001, array(), $order);
    assert_same(1, count(RuleModel::$reserve_calls), '(b) A second call on an already-reserved order must not call try_reserve_usage() again.');
    assert_same(1, $order->saved, '(b) A second call on an already-reserved order must not save() again.');

    // === (c) Hard block: limit_user rule denies -> throws with the rule title ===
    reset_world();
    PromoModel::$rows[8] = array('id' => 8, 'name' => 'Promo Bloqueante', 'rule_id' => 60);
    RuleModel::$rules[60] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla Bloqueante');
    RuleModel::$reserve_result = false;

    $order2 = new WC_Order(1002);
    $order2->add_item(new WC_Order_Item(array('_drw_promo_id' => '8')));

    $caught = null;
    try {
        $controller->reserve_promo_usage(1002, array(), $order2);
    } catch (\Exception $e) {
        $caught = $e;
    }
    assert_true(null !== $caught, '(c) A denied limit_user reservation must throw.');
    assert_true(false !== strpos($caught->getMessage(), 'Regla Bloqueante'), '(c) The exception message must name the rule/promo title.');
    assert_same('', $order2->get_meta('_drw_promos_reserved'), '(c) A blocked order must NOT be flagged reserved (so a retry can re-attempt cleanly).');

    // === (d) Soft-fail: bare usage_limit (no limit_user) denial does not block ===
    reset_world();
    PromoModel::$rows[9] = array('id' => 9, 'name' => 'Promo Suave', 'rule_id' => 61);
    RuleModel::$rules[61] = array('limit_user' => null, 'usage_limit' => 100, 'title' => 'Regla Suave');
    RuleModel::$reserve_result = false;

    $order3 = new WC_Order(1003);
    $order3->add_item(new WC_Order_Item(array('_drw_promo_id' => '9')));

    $threw3 = false;
    try {
        $controller->reserve_promo_usage(1003, array(), $order3);
    } catch (\Exception $e) {
        $threw3 = true;
    }
    assert_true(!$threw3, '(d) A bare usage_limit denial (no limit_user) must NOT throw (soft-fail, matches pre-existing behavior).');
    assert_same(1, $order3->get_meta('_drw_promos_reserved'), '(d) The order must still be flagged reserved even after a soft-fail.');

    // === (e) Vía A (coupon) promo has no rule_id -> never reaches try_reserve_usage() ===
    reset_world();
    PromoModel::$rows[10] = array('id' => 10, 'name' => 'Promo Cupón', 'rule_id' => null);
    PromoModel::$codes['DESCUENTO10'] = 10;

    $order4 = new WC_Order(1004);
    $order4->set_coupon_codes(array('descuento10'));

    $controller->reserve_promo_usage(1004, array(), $order4);
    assert_same(0, count(RuleModel::$reserve_calls), '(e) A Vía A (coupon) promo with no rule_id must never call try_reserve_usage().');
    assert_same(1, $order4->get_meta('_drw_promos_reserved'), '(e) The order is still flagged reserved even with nothing to reserve.');

    // === (f) reserve_promo_usage_store_api() adapts the 1-arg Store API shape ===
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);
    RuleModel::$rules[55] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla 55');
    RuleModel::$reserve_result = true;

    $order5 = new WC_Order(1005);
    $order5->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));

    $controller->reserve_promo_usage_store_api($order5);
    assert_same(1, count(RuleModel::$reserve_calls), '(f) reserve_promo_usage_store_api() must drive the same reservation logic.');
    assert_same(1005, RuleModel::$reserve_calls[0]['order_id'], '(f) The order id must be pulled from the passed $order object.');
    assert_same(1, $order5->get_meta('_drw_promos_reserved'), '(f) The order must be flagged reserved via the Store API adapter too.');

    // === (g) release_reserved_usage() releases every distinct rule_id found ===
    reset_world();
    $GLOBALS['wpdb']->get_col_return = array('55', '61');
    $order6 = new WC_Order(1006);

    $controller->release_reserved_usage(1006, $order6);
    assert_same(2, count(RuleModel::$release_calls), '(g) release_usage() must be called once per distinct rule_id.');
    assert_same(array('rule_id' => 55, 'order_id' => 1006), RuleModel::$release_calls[0], '(g) First release call must match rule 55 / order 1006.');
    assert_same(array('rule_id' => 61, 'order_id' => 1006), RuleModel::$release_calls[1], '(g) Second release call must match rule 61 / order 1006.');

    // === (h) track_promo_redemptions(): confirm_usage() alongside increment_usage(), guard intact ===
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);

    $order7 = new WC_Order(2001);
    $order7->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));

    $controller->track_promo_redemptions(2001, $order7);
    assert_same(array(7), PromoModel::$increment_calls, '(h) increment_usage() must be called once for the resolved promo.');
    assert_same(1, count(RuleModel::$confirm_calls), '(h) confirm_usage() must be called once for the promo\'s resolved rule.');
    assert_same(array('rule_id' => 55, 'order_id' => 2001), RuleModel::$confirm_calls[0], '(h) confirm_usage() must target the correct rule_id/order_id pair.');
    assert_same(1, $order7->get_meta('_drw_promos_counted'), '(h) The existing counted flag must still be set.');

    // Re-run: the pre-existing idempotency guard must still block a second pass entirely.
    $controller->track_promo_redemptions(2001, $order7);
    assert_same(array(7), PromoModel::$increment_calls, '(h) A second run on an already-counted order must NOT increment again.');
    assert_same(1, count(RuleModel::$confirm_calls), '(h) A second run on an already-counted order must NOT confirm again.');

    // === (h2) Reconnect guard (round-10 audit finding): a real GET_LOCK() ===
    // === success followed by a detected connection swap (wpdb's own ========
    // === transparent reconnect) must bail out WITHOUT counting, rather ======
    // === than proceed as if still holding an exclusive mutex. ===============
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);
    $GLOBALS['wpdb'] = new WpdbStubReconnecting();
    // GET_LOCK(%s, %d) is called with 2 args; RELEASE_LOCK(%s) with 1. Report
    // a genuine lock acquisition (the literal 1) for both, matching a real
    // MySQL success reply on whichever connection answers.
    $GLOBALS['wpdb']->get_var_return = function ($args) {
        return 1;
    };

    $order8 = new WC_Order(2002);
    $order8->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));

    $controller->track_promo_redemptions(2002, $order8);
    assert_same(array(), PromoModel::$increment_calls, '(h2) A detected reconnect after lock acquisition must bail out BEFORE incrementing.');
    assert_same(0, count(RuleModel::$confirm_calls), '(h2) A detected reconnect after lock acquisition must bail out BEFORE confirming.');
    assert_same('', $order8->get_meta('_drw_promos_counted'), '(h2) The order must NOT be flagged counted after a reconnect bail-out (a later, properly-serialized retry must still be able to count it).');
    assert_true($GLOBALS['wpdb']->dbh_reads >= 2, '(h2) Sanity check: the stub\'s ->dbh must have been read at least twice (fingerprint capture + guard re-check) for this test to actually exercise the guard.');

    // === (h3) Regression: a genuinely STABLE connection across a real ========
    // === GET_LOCK() success must NOT be mistaken for a reconnect -- the ======
    // === guard must never block the ordinary, unprotected-race-free path. ===
    reset_world();
    PromoModel::$rows[7] = array('id' => 7, 'name' => 'Promo Vía B', 'rule_id' => 55);
    $GLOBALS['wpdb']->dbh = new \stdClass(); // Same object identity on every read.
    $GLOBALS['wpdb']->get_var_return = function ($args) {
        return 1; // Real GET_LOCK()/RELEASE_LOCK() success, same connection throughout.
    };

    $order9 = new WC_Order(2003);
    $order9->add_item(new WC_Order_Item(array('_drw_promo_id' => '7')));

    $controller->track_promo_redemptions(2003, $order9);
    assert_same(array(7), PromoModel::$increment_calls, '(h3) A stable connection through a real lock acquisition must count normally.');
    assert_same(1, count(RuleModel::$confirm_calls), '(h3) A stable connection through a real lock acquisition must confirm normally.');
    assert_same(1, $order9->get_meta('_drw_promos_counted'), '(h3) A stable connection through a real lock acquisition must flag the order counted.');

    // === (i) Retry after a mid-loop block must not re-block on an EARLIER ===
    // === promo this same order already legitimately reserved (Store API =====
    // === draft orders re-POST /checkout to the SAME order_id on retry). ======
    reset_world();
    PromoModel::$rows[20] = array('id' => 20, 'name' => 'Promo A', 'rule_id' => 70);
    PromoModel::$rows[21] = array('id' => 21, 'name' => 'Promo B', 'rule_id' => 71);
    RuleModel::$rules[70] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla A');
    RuleModel::$rules[71] = array('limit_user' => 1, 'usage_limit' => null, 'title' => 'Regla B');

    $order8 = new WC_Order(1008);
    $order8->add_item(new WC_Order_Item(array('_drw_promo_id' => '20')));
    $order8->add_item(new WC_Order_Item(array('_drw_promo_id' => '21')));

    // First attempt: A reserves fine, B is genuinely denied -> throws on B.
    RuleModel::$reserve_result = array(true, false);
    $caught8a = null;
    try {
        $controller->reserve_promo_usage(1008, array(), $order8);
    } catch (\Exception $e) {
        $caught8a = $e;
    }
    assert_true(null !== $caught8a, '(i) First attempt must throw on Promo B.');
    assert_same(2, count(RuleModel::$reserve_calls), '(i) Both promos must have been attempted before the throw.');
    assert_same('', $order8->get_meta('_drw_promos_reserved'), '(i) A mid-loop throw must leave the order unflagged, so a retry can re-enter.');

    // Retry (same order_id, as a Store API draft order resubmission would be):
    // try_reserve_usage() now reports false for BOTH — A because of its own
    // UNIQUE(order_id, rule_id) self-collision, B because it is still
    // genuinely denied. $wpdb->get_var() reports an existing redemption row
    // ONLY for (1008, 70), i.e. only A.
    RuleModel::$reserve_calls  = array();
    RuleModel::$reserve_result = false;
    $GLOBALS['wpdb']->get_var_return = function ($args) {
        return ((int)$args[0] === 1008 && (int)$args[1] === 70) ? '1' : null;
    };

    $caught8b = null;
    try {
        $controller->reserve_promo_usage(1008, array(), $order8);
    } catch (\Exception $e) {
        $caught8b = $e;
    }
    assert_true(null !== $caught8b, '(i) Retry must still throw, because B is genuinely still denied.');
    assert_true(false !== strpos($caught8b->getMessage(), 'Regla B'), '(i) The retry exception must be attributed to B, the genuinely-denied rule.');
    assert_true(false === strpos($caught8b->getMessage(), 'Regla A'), '(i) A must NOT be mis-reported as newly denied — it is this order\'s own pre-existing reservation.');

    // === (j) Security fix: a denial on a limit_user rule must NOT hard-block =
    // === (or leak an "account limit" message) unless THIS customer is =========
    // === actually the one exhausted. A denial caused by the rule's global =====
    // === usage_limit (or a transient try_reserve_usage() error) with this ====
    // === customer's own redemption count still under limit_user must soft- ===
    // === fail exactly like a bare usage_limit rule, closing the "probe a ======
    // === promo's global inventory with a fresh email" oracle. =================
    reset_world();
    PromoModel::$rows[30] = array('id' => 30, 'name' => 'Promo Compartida', 'rule_id' => 80);
    RuleModel::$rules[80] = array('limit_user' => 1, 'usage_limit' => 5, 'title' => 'Regla Compartida');
    RuleModel::$reserve_result = false; // Denied -- but NOT because of this customer.
    RuleModel::$customer_redemption_count = 0; // This customer has never redeemed it.

    $order9 = new WC_Order(1009);
    $order9->add_item(new WC_Order_Item(array('_drw_promo_id' => '30')));

    $threw9 = false;
    try {
        $controller->reserve_promo_usage(1009, array(), $order9);
    } catch (\Exception $e) {
        $threw9 = true;
    }
    assert_true(!$threw9, '(j) A denial NOT caused by this customer\'s own limit_user must not throw / leak an account-limit message.');
    assert_same(1, count(RuleModel::$customer_redemption_count_calls), '(j) The disambiguating recheck must be performed exactly once.');
    assert_same(80, RuleModel::$customer_redemption_count_calls[0]['rule_id'], '(j) The recheck must target the correct rule_id.');
    assert_same(1, $order9->get_meta('_drw_promos_reserved'), '(j) The order must still be flagged reserved after the soft-fail.');

    // Sanity counterpart: same setup, but THIS customer genuinely IS the one
    // exhausted -> must still hard-block exactly like before the fix.
    reset_world();
    PromoModel::$rows[30] = array('id' => 30, 'name' => 'Promo Compartida', 'rule_id' => 80);
    RuleModel::$rules[80] = array('limit_user' => 1, 'usage_limit' => 5, 'title' => 'Regla Compartida');
    RuleModel::$reserve_result = false;
    RuleModel::$customer_redemption_count = 1; // This customer already holds the one slot.

    $order10 = new WC_Order(1010);
    $order10->add_item(new WC_Order_Item(array('_drw_promo_id' => '30')));

    $caught10 = null;
    try {
        $controller->reserve_promo_usage(1010, array(), $order10);
    } catch (\Exception $e) {
        $caught10 = $e;
    }
    assert_true(null !== $caught10, '(j) A denial that IS caused by this customer\'s own exhausted limit_user must still throw.');
    assert_true(false !== strpos($caught10->getMessage(), 'Regla Compartida'), '(j) The exception message must still name the rule/promo title.');

    echo "CartController promo reservation OK\n";
}
