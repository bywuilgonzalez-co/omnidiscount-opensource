<?php
/**
 * Standalone smoke test for CartController's "first purchase" welcome-coupon
 * anti-fraud extension (identity document / email cross-check):
 *   - order_has_welcome_coupon() — detects popup-minted coupons (via
 *     '_drw_popup_submission_id' coupon meta) AND merchant-authored promos of
 *     type='welcome' (via '_drw_promo_id' coupon meta -> PromoModel::get_promo()),
 *     but NOT any other coupon.
 *   - enforce_first_purchase_welcome_coupon() / prior_welcome_coupon_redemption_exists()
 *     — called from reserve_promo_usage(), blocks checkout when another order
 *     already carries '_drw_welcome_coupon_used' with a matching billing email
 *     OR a matching '_billing_documento_identidad'.
 *   - track_promo_redemptions() — marks '_drw_welcome_coupon_used' on the order
 *     the moment real usage is counted (order reaches processing/completed).
 *
 * No PHPUnit, no WooCommerce, no database — same style as
 * tests/test-cartcontroller-promo-reservation.php: stub Drw\App\Models\PromoModel
 * in its real namespace, minimal WC_Order/wc_get_coupon_id_by_code()/WC_Coupon/
 * wc_get_orders() stand-ins, hard-failing assert helpers.
 *
 * Coverage (per the task's exact scenario list):
 *   (a) A first order with a welcome coupon + document X + email A succeeds
 *       (reserve_promo_usage() does not throw) and gets marked
 *       '_drw_welcome_coupon_used' by track_promo_redemptions().
 *   (b) A second order with document X (same as (a)) + a DIFFERENT email B +
 *       a DIFFERENT welcome coupon is blocked.
 *   (c) A second order with the SAME email A (as (a)) but a different
 *       document Y is also blocked.
 *   (d) A non-welcome coupon is never subject to this check at all — the
 *       check must not even query wc_get_orders().
 *   (e) The block message never reveals which of email/document matched —
 *       (b) and (c) throw the exact same generic message.
 *   (f) order_has_welcome_coupon() distinguishes a popup-minted coupon, a
 *       merchant promo type='welcome', and a merchant promo of a different
 *       type (e.g. 'percent') attached via the SAME '_drw_promo_id' mechanism.
 *   (g) Regression guard: a genuinely first-time customer (no prior record
 *       for either signal) is never blocked.
 *
 * ALSO covers the field-visibility gate added on top of the above
 * (should_show_identity_document_field() / welcome_promo_exists()):
 *   (k) The gate is true when popup.enabled=true (even with zero welcome
 *       promos, and WITHOUT ever querying wp_drw_promos -- short-circuit),
 *       true when popup.enabled=false but an active/non-deleted
 *       type='welcome' wp_drw_promos row exists, false when neither holds
 *       (including when popup.enabled is entirely unset), and the
 *       wp_drw_promos existence check is itself transient-cached (a second
 *       call does not re-hit the DB).
 *   (l) add_identity_document_checkout_field() (classic) reads the field
 *       out of $fields entirely when the gate is false, and adds it when
 *       true -- never a CSS-only hide.
 *   (m) register_identity_document_block_field() (Blocks) never calls
 *       woocommerce_register_additional_checkout_field() when the gate is
 *       false, and calls it exactly once when true.
 *   (n) Regression guard: the enforcement logic
 *       (enforce_first_purchase_welcome_coupon(), via reserve_promo_usage())
 *       is completely unaffected by the gate -- it still blocks a genuine
 *       repeat-document welcome-coupon order even while the gate itself is
 *       false (field hidden for this checkout).
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for PromoModel — only get_promo() is needed by
     * order_has_welcome_coupon()'s '_drw_promo_id' resolution path.
     */
    class PromoModel {
        /** @var array<int,array> Promo rows keyed by id. */
        public static $rows = array();

        public static function reset() {
            self::$rows = array();
        }

        public static function get_promo($id) {
            $id = (int) $id;
            return isset(self::$rows[$id]) ? self::$rows[$id] : null;
        }

        /**
         * resolve_order_promo_ids() (called unconditionally by
         * reserve_promo_usage()/track_promo_redemptions() after the welcome
         * check) looks up every applied coupon code against this -- none of
         * this test's coupon codes are registered as a wp_drw_promos code,
         * so this always returns null, keeping resolve_order_promo_ids()
         * empty and RuleModel entirely out of scope for this test.
         */
        public static function get_promo_by_code($code) {
            return null;
        }

        public static function increment_usage($id) {
            // No-op stand-in: resolve_order_promo_ids() always resolves
            // empty in this test (see get_promo_by_code() above), so this is
            // never actually invoked -- present only so the call site
            // resolves if that ever changes.
        }
    }

    /**
     * In-memory stand-in for SettingsModel -- only get_setting() is used by
     * should_show_identity_document_field() (same shape/convention as
     * tests/test-popup-controller.php's own SettingsModel stub).
     */
    class SettingsModel {
        public static $values = array();

        public static function reset() {
            self::$values = array();
        }

        public static function get_setting($key, $fallback = null) {
            return array_key_exists($key, self::$values) ? self::$values[$key] : $fallback;
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
    function sanitize_text_field($value) {
        return is_string($value) ? trim($value) : $value;
    }
    function wp_unslash($value) {
        return $value;
    }
    function current_time($type, $gmt = 0) {
        return '2026-07-10 12:00:00';
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    // --- Minimal $wpdb stand-in (degrades track_promo_redemptions()' GET_LOCK() ---
    // mutex to its documented no-lock fallback -- get_var() returning NULL for
    // everything, exactly like the "unsupported environment" branch already
    // exercised by tests/test-cartcontroller-promo-reservation.php).
    class WpdbStub {
        public $prefix = 'wp_';
        public $dbh = null;
        public function prepare($query, ...$args) {
            return $query;
        }
        public function get_var($query) {
            // welcome_promo_exists()'s EXISTS-style query against
            // wp_drw_promos -- controllable per test case via
            // $GLOBALS['__drw_test_welcome_promo_exists'], with a call
            // counter to prove the transient cache actually short-circuits
            // repeat calls. Every OTHER get_var() caller in CartController
            // (the GET_LOCK()/RELEASE_LOCK() mutex helpers) keeps degrading
            // to its documented "unsupported environment" no-lock fallback
            // by falling through to the final `return null;` below.
            if (false !== strpos($query, 'drw_promos') && false !== strpos($query, "type = 'welcome'")) {
                $GLOBALS['__drw_wpdb_promos_queries'] = (isset($GLOBALS['__drw_wpdb_promos_queries']) ? $GLOBALS['__drw_wpdb_promos_queries'] : 0) + 1;
                return !empty($GLOBALS['__drw_test_welcome_promo_exists']) ? '1' : null;
            }
            return null;
        }
        public function get_col($query) {
            return array();
        }
        public function get_results($query, $output = ARRAY_A) {
            return array();
        }
    }
    $GLOBALS['wpdb'] = new WpdbStub();

    // --- Transient store backing welcome_promo_exists()'s cache (same
    // approach as tests/test-popup-controller.php's get_transient()/
    // set_transient() stand-ins for the real RateLimiter.php).
    $GLOBALS['__drw_test_transients'] = array();
    function get_transient($key) {
        return isset($GLOBALS['__drw_test_transients'][$key]) ? $GLOBALS['__drw_test_transients'][$key] : false;
    }
    function set_transient($key, $value, $expiration) {
        $GLOBALS['__drw_test_transients'][$key] = $value;
        return true;
    }

    // --- Records every woocommerce_register_additional_checkout_field()
    // call for register_identity_document_block_field() (Blocks gate)
    // assertions -- function_exists() gates that method's early return, so
    // simply defining this makes the method proceed to the gate check.
    $GLOBALS['__drw_block_field_registrations'] = array();
    function woocommerce_register_additional_checkout_field($args) {
        $GLOBALS['__drw_block_field_registrations'][] = $args;
    }

    /**
     * Coupon registry backing wc_get_coupon_id_by_code() / WC_Coupon below.
     * code => coupon_id, and coupon_id => meta array.
     */
    $GLOBALS['__drw_coupon_by_code'] = array();
    $GLOBALS['__drw_coupon_meta']    = array();

    function wc_get_coupon_id_by_code($code) {
        $code = (string) $code;
        return isset($GLOBALS['__drw_coupon_by_code'][$code]) ? $GLOBALS['__drw_coupon_by_code'][$code] : 0;
    }

    /** Minimal WC_Coupon stand-in: only get_meta() is needed. */
    class WC_Coupon {
        private $id;
        public function __construct($id) {
            $this->id = (int) $id;
        }
        public function get_meta($key, $single = true) {
            $meta = isset($GLOBALS['__drw_coupon_meta'][$this->id]) ? $GLOBALS['__drw_coupon_meta'][$this->id] : array();
            return isset($meta[$key]) ? $meta[$key] : '';
        }
    }

    /**
     * wc_get_orders() stand-in for prior_welcome_coupon_redemption_exists().
     * Records every call's args and returns a per-call-index queued result
     * (same "queue keyed by call index" convention as
     * tests/test-cartcontroller-promo-reservation.php's RuleModel::$reserve_result).
     */
    $GLOBALS['__wc_get_orders_calls'] = array();
    $GLOBALS['__wc_get_orders_queue'] = array();

    function wc_get_orders($args) {
        $GLOBALS['__wc_get_orders_calls'][] = $args;
        $idx = count($GLOBALS['__wc_get_orders_calls']) - 1;
        return isset($GLOBALS['__wc_get_orders_queue'][$idx]) ? $GLOBALS['__wc_get_orders_queue'][$idx] : array();
    }

    /**
     * wc_get_order() stand-in for release_stale_welcome_coupon_reservations()
     * / release_reserved_usage()'s re-resolution-by-id path (round-5 audit
     * fix). A simple registry the test seeds explicitly -- unlike
     * wc_get_orders() above, callers here always want a specific, already-
     * constructed WC_Order back.
     */
    $GLOBALS['__wc_orders_by_id'] = array();
    function wc_get_order($id) {
        $id = (int) $id;
        return isset($GLOBALS['__wc_orders_by_id'][$id]) ? $GLOBALS['__wc_orders_by_id'][$id] : false;
    }

    /**
     * Minimal WC_Order stand-in. Tracks meta/coupons in-memory and records
     * save() calls, plus get_billing_email()/get_coupon_codes()/get_items()
     * needed by the methods under test.
     */
    class WC_Order {
        private $id;
        private $meta = array();
        private $coupon_codes = array();
        private $items = array();
        private $billing_email = '';
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
        public function delete_meta_data($key) {
            unset($this->meta[$key]);
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
        public function set_billing_email($email) {
            $this->billing_email = $email;
        }
        public function get_billing_email() {
            return $this->billing_email;
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
    use Drw\App\Models\SettingsModel;

    function reset_world() {
        PromoModel::reset();
        SettingsModel::reset();
        $GLOBALS['__drw_coupon_by_code']   = array();
        $GLOBALS['__drw_coupon_meta']      = array();
        $GLOBALS['__wc_get_orders_calls']  = array();
        $GLOBALS['__wc_get_orders_queue']  = array();
        $GLOBALS['__wc_orders_by_id']      = array();
        $GLOBALS['__drw_test_transients']  = array();
        $GLOBALS['__drw_test_welcome_promo_exists'] = false;
        $GLOBALS['__drw_wpdb_promos_queries'] = 0;
        $GLOBALS['__drw_block_field_registrations'] = array();
        $GLOBALS['wpdb'] = new WpdbStub();
    }

    /** Registers a popup-minted welcome coupon (has '_drw_popup_submission_id', no '_drw_promo_id'). */
    function register_popup_welcome_coupon($code, $coupon_id, $submission_id) {
        $GLOBALS['__drw_coupon_by_code'][$code] = $coupon_id;
        $GLOBALS['__drw_coupon_meta'][$coupon_id] = array('_drw_popup_submission_id' => $submission_id);
    }

    /** Registers a merchant-authored promo-backed coupon of an arbitrary PromoModel type. */
    function register_promo_coupon($code, $coupon_id, $promo_id, $promo_type) {
        $GLOBALS['__drw_coupon_by_code'][$code] = $coupon_id;
        $GLOBALS['__drw_coupon_meta'][$coupon_id] = array('_drw_promo_id' => $promo_id);
        PromoModel::$rows[$promo_id] = array('id' => $promo_id, 'type' => $promo_type);
    }

    $controller = CartController::instance();

    // === (a) First order: welcome coupon (popup-minted) + document X + email A ===
    // === succeeds, and gets marked by track_promo_redemptions(). ==================
    reset_world();
    register_popup_welcome_coupon('WELCOME1', 501, 77);

    $order1 = new WC_Order(3001);
    $order1->set_coupon_codes(array('WELCOME1'));
    $order1->set_billing_email('a@example.com');
    $order1->update_meta_data('_billing_documento_identidad', 'DOC-X');

    $threw_a = false;
    try {
        $controller->reserve_promo_usage(3001, array(), $order1);
    } catch (\Exception $e) {
        $threw_a = true;
    }
    assert_true(!$threw_a, '(a) A first-time welcome-coupon checkout must not be blocked.');
    assert_same(2, count($GLOBALS['__wc_get_orders_calls']), '(a) Both the document and email queries must have been attempted (both queued results are empty -- no match).');

    $controller->track_promo_redemptions(3001, $order1);
    assert_same(1, $order1->get_meta('_drw_welcome_coupon_used'), '(a) A paid order with a welcome (popup-minted) coupon must be marked _drw_welcome_coupon_used.');

    // === (b) Second order: SAME document X, DIFFERENT email B, a DIFFERENT ========
    // === welcome coupon (merchant promo type='welcome') -> must be blocked. =======
    reset_world();
    register_promo_coupon('WELCOME2', 502, 9, 'welcome');
    // Document query (called first) reports order 3001 as a prior match.
    $GLOBALS['__wc_get_orders_queue'][0] = array(3001);

    $order2 = new WC_Order(3002);
    $order2->set_coupon_codes(array('WELCOME2'));
    $order2->set_billing_email('b@example.com');
    $order2->update_meta_data('_billing_documento_identidad', 'DOC-X');

    $caught_b = null;
    try {
        $controller->reserve_promo_usage(3002, array(), $order2);
    } catch (\Exception $e) {
        $caught_b = $e;
    }
    assert_true(null !== $caught_b, '(b) A repeat document with a different email/coupon must be blocked.');
    // Round-2 security-audit fix ("Gap A" timing side-channel): the document
    // match must NOT short-circuit the email query anymore -- both queries
    // always run whenever both signals are present, so response TIMING never
    // leaks which signal actually matched.
    assert_same(2, count($GLOBALS['__wc_get_orders_calls']), '(b) Both the document and email queries must run even though the document alone already matched (no timing short-circuit).');
    assert_same(array(3002), $GLOBALS['__wc_get_orders_calls'][0]['exclude'], '(b) The current order id must be excluded from the search.');

    // === (c) Third order: SAME email A (as (a)), DIFFERENT document Y -> blocked. ==
    reset_world();
    register_promo_coupon('WELCOME3', 503, 9, 'welcome');
    PromoModel::$rows[9] = array('id' => 9, 'type' => 'welcome');
    // Document query (new document Y) finds nothing; email query then matches order 3001.
    $GLOBALS['__wc_get_orders_queue'][0] = array();
    $GLOBALS['__wc_get_orders_queue'][1] = array(3001);

    $order3 = new WC_Order(3003);
    $order3->set_coupon_codes(array('WELCOME3'));
    $order3->set_billing_email('a@example.com');
    $order3->update_meta_data('_billing_documento_identidad', 'DOC-Y');

    $caught_c = null;
    try {
        $controller->reserve_promo_usage(3003, array(), $order3);
    } catch (\Exception $e) {
        $caught_c = $e;
    }
    assert_true(null !== $caught_c, '(c) A repeat email with a different document/coupon must be blocked.');
    assert_same(2, count($GLOBALS['__wc_get_orders_calls']), '(c) Both the document and email queries must have run (document alone found nothing).');
    assert_same('a@example.com', $GLOBALS['__wc_get_orders_calls'][1]['billing_email'], '(c) The email query must target this order\'s own billing email.');

    // === (d) A non-welcome coupon (merchant promo type='percent') is never subject =
    // === to this check at all -- wc_get_orders() must not even be called. ==========
    reset_world();
    register_promo_coupon('REGULAR1', 504, 10, 'percent');

    $order4 = new WC_Order(3004);
    $order4->set_coupon_codes(array('REGULAR1'));
    $order4->set_billing_email('c@example.com');
    $order4->update_meta_data('_billing_documento_identidad', 'DOC-Z');

    $threw_d = false;
    try {
        $controller->reserve_promo_usage(3004, array(), $order4);
    } catch (\Exception $e) {
        $threw_d = true;
    }
    assert_true(!$threw_d, '(d) A non-welcome coupon must never be blocked by this check.');
    assert_same(0, count($GLOBALS['__wc_get_orders_calls']), '(d) A non-welcome coupon must never even trigger the duplicate-redemption query.');

    // === (e) The block message never reveals which of email/document matched: =====
    // === (b) and (c) must carry the EXACT same generic text. =======================
    $expected_message = 'Este código promocional es válido únicamente para tu primera compra.';
    assert_same($expected_message, $caught_b->getMessage(), '(e) The document-match block message must be the exact generic wording.');
    assert_same($expected_message, $caught_c->getMessage(), '(e) The email-match block message must be the exact generic wording.');
    assert_same($caught_b->getMessage(), $caught_c->getMessage(), '(e) Both block paths must be textually identical -- no signal about which field matched.');

    // === (f) order_has_welcome_coupon() type discrimination (via reflection, ======
    // === isolated from the reservation/blocking flow above). =======================
    reset_world();
    $reflection = new \ReflectionMethod(CartController::class, 'order_has_welcome_coupon');
    $reflection->setAccessible(true);

    register_popup_welcome_coupon('POPUPCODE', 601, 88);
    $order_popup = new WC_Order(4001);
    $order_popup->set_coupon_codes(array('POPUPCODE'));
    assert_true($reflection->invoke($controller, $order_popup), '(f) A popup-minted coupon must be recognised as a welcome coupon.');

    register_promo_coupon('WELCOMEPROMO', 602, 20, 'welcome');
    $order_welcome_promo = new WC_Order(4002);
    $order_welcome_promo->set_coupon_codes(array('WELCOMEPROMO'));
    assert_true($reflection->invoke($controller, $order_welcome_promo), '(f) A merchant promo of type="welcome" must be recognised as a welcome coupon.');

    register_promo_coupon('PERCENTPROMO', 603, 21, 'percent');
    $order_percent_promo = new WC_Order(4003);
    $order_percent_promo->set_coupon_codes(array('PERCENTPROMO'));
    assert_true(!$reflection->invoke($controller, $order_percent_promo), '(f) A merchant promo of a non-welcome type must NOT be recognised as a welcome coupon.');

    $order_no_coupon = new WC_Order(4004);
    assert_true(!$reflection->invoke($controller, $order_no_coupon), '(f) An order with no coupons at all must NOT be recognised as having a welcome coupon.');

    // === (g) Regression guard: a genuinely first-time customer (no prior record ===
    // === for either signal) is never blocked, even with both queries returning ====
    // === real (but non-matching) results. ===========================================
    reset_world();
    register_popup_welcome_coupon('WELCOME4', 505, 99);
    $GLOBALS['__wc_get_orders_queue'][0] = array();
    $GLOBALS['__wc_get_orders_queue'][1] = array();

    $order5 = new WC_Order(3005);
    $order5->set_coupon_codes(array('WELCOME4'));
    $order5->set_billing_email('first-timer@example.com');
    $order5->update_meta_data('_billing_documento_identidad', 'DOC-NEW');

    $threw_g = false;
    try {
        $controller->reserve_promo_usage(3005, array(), $order5);
    } catch (\Exception $e) {
        $threw_g = true;
    }
    assert_true(!$threw_g, '(g) A genuinely first-time customer must never be blocked.');

    // === (h) ROUND-5 AUDIT FIX -- TOCTOU close: a passing check must ==============
    // === IMMEDIATELY stamp '_drw_welcome_coupon_reserved' on the order, before ====
    // === any payment confirmation, so a SECOND (not-yet-paid) order created ========
    // === right after cannot slip through the same gap that '_drw_welcome_coupon_used' ===
    // === (only set at payment-confirmation time by track_promo_redemptions()) left open. ===
    reset_world();
    register_popup_welcome_coupon('WELCOME5', 506, 111);
    $GLOBALS['__wc_get_orders_queue'][0] = array();
    $GLOBALS['__wc_get_orders_queue'][1] = array();

    $order_h = new WC_Order(3006);
    $order_h->set_coupon_codes(array('WELCOME5'));
    $order_h->set_billing_email('toctou@example.com');
    $order_h->update_meta_data('_billing_documento_identidad', 'DOC-TOCTOU');

    $controller->reserve_promo_usage(3006, array(), $order_h);
    assert_same(1, $order_h->get_meta('_drw_welcome_coupon_reserved'), '(h) A passing check must stamp _drw_welcome_coupon_reserved immediately, at order-creation time -- BEFORE any payment confirmation.');
    assert_true($order_h->saved > 0, '(h) The reservation stamp must be persisted via save(), not just held in memory.');
    assert_same('', $order_h->get_meta('_drw_welcome_coupon_used'), '(h) The PROVISIONAL reservation mark must be distinct from the PERMANENT _drw_welcome_coupon_used mark -- the latter is only ever set by track_promo_redemptions() on actual payment.');

    // === (h2) The prior-redemption query itself must now check BOTH marks ==========
    // === (used OR reserved), not just the confirmed one -- this is the actual =====
    // === TOCTOU fix: a SECOND order sharing (h)'s document, before (h)'s order =====
    // === is ever paid, must be blocked because of the RESERVED mark alone. =========
    $GLOBALS['__wc_get_orders_calls'] = array();
    $GLOBALS['__wc_get_orders_queue'] = array(array(3006)); // simulates a real query finding order_h via ITS reserved mark.
    register_promo_coupon('WELCOME6', 507, 12, 'welcome');

    $order_h2 = new WC_Order(3007);
    $order_h2->set_coupon_codes(array('WELCOME6'));
    $order_h2->set_billing_email('toctou2@example.com');
    $order_h2->update_meta_data('_billing_documento_identidad', 'DOC-TOCTOU');

    $caught_h2 = null;
    try {
        $controller->reserve_promo_usage(3007, array(), $order_h2);
    } catch (\Exception $e) {
        $caught_h2 = $e;
    }
    assert_true(null !== $caught_h2, '(h2) A second, not-yet-paid order sharing a document with an order that only holds the PROVISIONAL reservation must still be blocked -- this is the actual TOCTOU race closed.');
    assert_same($expected_message, $caught_h2->getMessage(), '(h2) The block message must stay the exact same generic wording for a reservation-based match too.');

    // Verify the ACTUAL query shape production code sent -- not just the
    // mock's queued return value -- carries the OR clause for both marks
    // (round-5 audit fix), proving the fix is really wired in, not just
    // coincidentally passing because the mock ignores query shape.
    $document_call_meta_query = $GLOBALS['__wc_get_orders_calls'][0]['meta_query'];
    assert_true(is_array($document_call_meta_query), '(h2) The document query must carry a meta_query array.');
    $or_clause = null;
    foreach ($document_call_meta_query as $clause) {
        if (is_array($clause) && isset($clause['relation']) && 'OR' === $clause['relation']) {
            $or_clause = $clause;
        }
    }
    assert_true(null !== $or_clause, '(h2) The document query\'s meta_query must contain an OR-relation sub-clause (used vs. reserved).');
    $or_keys = array();
    foreach ($or_clause as $entry) {
        if (is_array($entry) && isset($entry['key'])) {
            $or_keys[] = $entry['key'];
        }
    }
    sort($or_keys);
    assert_same(array('_drw_welcome_coupon_reserved', '_drw_welcome_coupon_used'), $or_keys, '(h2) The OR clause must check BOTH _drw_welcome_coupon_used AND _drw_welcome_coupon_reserved.');

    // === (i) release_reserved_usage(), hooked to order cancelled/failed, must ======
    // === clear ONLY the provisional reservation, NEVER the permanent used mark. ====
    reset_world();
    $order_i = new WC_Order(3008);
    $order_i->update_meta_data('_drw_welcome_coupon_reserved', 1);
    $controller->release_reserved_usage(3008, $order_i);
    assert_same('', $order_i->get_meta('_drw_welcome_coupon_reserved'), '(i) A cancelled/failed order\'s provisional reservation must be released.');
    assert_true($order_i->saved > 0, '(i) Releasing the reservation must persist via save().');

    reset_world();
    $order_i2 = new WC_Order(3009);
    $order_i2->update_meta_data('_drw_welcome_coupon_used', 1); // genuinely confirmed on an EARLIER paid order somehow re-entering this hook.
    $saved_before = $order_i2->saved;
    $controller->release_reserved_usage(3009, $order_i2);
    assert_same(1, $order_i2->get_meta('_drw_welcome_coupon_used'), '(i) release_reserved_usage() must NEVER touch the permanent _drw_welcome_coupon_used mark, only the provisional reservation.');
    assert_same($saved_before, $order_i2->saved, '(i) An order with no reservation to release must not trigger a needless save() call.');

    // === (j) release_stale_promo_reservations() (WP-Cron safety net) must reap =====
    // === a reservation abandoned by an order that never reaches ANY terminal =======
    // === status at all (e.g. abandoned pending payment, gateway never redirected ===
    // === back) -- the case release_reserved_usage()'s status hooks never fire for. =
    reset_world();
    $order_j = new WC_Order(3010);
    $order_j->update_meta_data('_drw_welcome_coupon_reserved', 1);
    $GLOBALS['__wc_orders_by_id'][3010] = $order_j;
    $GLOBALS['__wc_get_orders_queue'][0] = array(3010); // release_stale_welcome_coupon_reservations()'s own query.

    $controller->release_stale_promo_reservations();
    assert_same('', $order_j->get_meta('_drw_welcome_coupon_reserved'), '(j) A stale (never-terminal) reservation must be released by the WP-Cron safety net.');
    assert_true($order_j->saved > 0, '(j) Releasing a stale reservation must persist via save().');

    // === (k) FIELD-VISIBILITY GATE -- should_show_identity_document_field() =======
    // === via reflection, isolated from the reservation/blocking flow above. =======
    $gate_reflection = new \ReflectionMethod(CartController::class, 'should_show_identity_document_field');
    $gate_reflection->setAccessible(true);

    // (k1) popup.enabled=true shows the field even with ZERO welcome promos, and
    // must short-circuit BEFORE ever querying wp_drw_promos.
    reset_world();
    SettingsModel::$values['popup.enabled'] = true;
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    assert_true($gate_reflection->invoke($controller), '(k1) popup.enabled=true must show the field even with zero welcome promos.');
    assert_same(0, $GLOBALS['__drw_wpdb_promos_queries'], '(k1) popup.enabled=true must short-circuit before ever querying wp_drw_promos.');

    // (k2) popup.enabled=false but an active, non-deleted type='welcome' promo
    // row exists -> field still shows.
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = true;
    assert_true($gate_reflection->invoke($controller), '(k2) popup.enabled=false with an active welcome promo must still show the field.');

    // (k3) popup.enabled=false and no welcome promo -> field must NOT show.
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    assert_true(!$gate_reflection->invoke($controller), '(k3) popup.enabled=false with no welcome promo must NOT show the field.');

    // (k4) 'popup.enabled' entirely unset (fresh-install default) behaves the
    // same as an explicit false -- SettingsModel's own fallback covers this.
    reset_world();
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    assert_true(!$gate_reflection->invoke($controller), '(k4) An absent popup.enabled setting must fall back to false and not show the field with no welcome promo either.');

    // (k5) The wp_drw_promos existence check is itself transient-cached: a
    // second gate call within the TTL window must NOT re-hit the DB.
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = true;
    assert_true($gate_reflection->invoke($controller), '(k5) First call with a welcome promo present must show the field.');
    assert_same(1, $GLOBALS['__drw_wpdb_promos_queries'], '(k5) First call must query the DB exactly once.');
    $gate_reflection->invoke($controller);
    assert_same(1, $GLOBALS['__drw_wpdb_promos_queries'], '(k5) A second call must be served from the transient cache, not a second DB query.');

    // === (l) add_identity_document_checkout_field() (classic) must read the ======
    // === field in/out of $fields entirely based on the gate -- never a ============
    // === CSS-only hide. =============================================================
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    $fields_hidden = $controller->add_identity_document_checkout_field(array('billing' => array()));
    assert_true(!isset($fields_hidden['billing']['billing_documento_identidad']), '(l) The classic field must not be added to $fields when the gate is false.');

    reset_world();
    SettingsModel::$values['popup.enabled'] = true;
    $fields_shown = $controller->add_identity_document_checkout_field(array('billing' => array()));
    assert_true(isset($fields_shown['billing']['billing_documento_identidad']), '(l) The classic field must be added to $fields when the gate is true.');

    // === (m) register_identity_document_block_field() (Blocks) must never call ====
    // === woocommerce_register_additional_checkout_field() when the gate is =======
    // === false, and must call it exactly once when the gate is true. ==============
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    $controller->register_identity_document_block_field();
    assert_same(0, count($GLOBALS['__drw_block_field_registrations']), '(m) The Blocks field must not be registered when the gate is false.');

    reset_world();
    SettingsModel::$values['popup.enabled'] = true;
    $controller->register_identity_document_block_field();
    assert_same(1, count($GLOBALS['__drw_block_field_registrations']), '(m) The Blocks field must be registered when the gate is true.');
    assert_same(CartController::IDENTITY_DOCUMENT_FIELD_ID, $GLOBALS['__drw_block_field_registrations'][0]['id'], '(m) The registered Blocks field must use the documented namespaced id.');

    // === (n) REGRESSION GUARD -- the enforcement logic itself ======================
    // === (enforce_first_purchase_welcome_coupon(), via reserve_promo_usage()) =====
    // === is completely unaffected by the field-visibility gate: it must still =====
    // === block a genuine repeat-document welcome-coupon order even while the ======
    // === gate is false (the field would have been HIDDEN for this checkout). ======
    reset_world();
    SettingsModel::$values['popup.enabled'] = false;
    $GLOBALS['__drw_test_welcome_promo_exists'] = false;
    assert_true(!$gate_reflection->invoke($controller), '(n) Sanity check: the field-visibility gate is indeed false for this scenario.');

    register_popup_welcome_coupon('WELCOMEGATE1', 508, 200);
    $order_n1 = new WC_Order(3011);
    $order_n1->set_coupon_codes(array('WELCOMEGATE1'));
    $order_n1->set_billing_email('gate-first@example.com');
    $order_n1->update_meta_data('_billing_documento_identidad', 'DOC-GATE');

    $threw_n1 = false;
    try {
        $controller->reserve_promo_usage(3011, array(), $order_n1);
    } catch (\Exception $e) {
        $threw_n1 = true;
    }
    assert_true(!$threw_n1, '(n) A first-time welcome-coupon checkout must still succeed normally while the gate is false.');
    $controller->track_promo_redemptions(3011, $order_n1);
    assert_same(1, $order_n1->get_meta('_drw_welcome_coupon_used'), '(n) track_promo_redemptions() must still mark _drw_welcome_coupon_used while the gate is false.');

    // A second order sharing the SAME document, gate still false throughout.
    $GLOBALS['__wc_get_orders_calls'] = array();
    $GLOBALS['__wc_get_orders_queue'] = array(array(3011));
    register_popup_welcome_coupon('WELCOMEGATE2', 509, 201);

    $order_n2 = new WC_Order(3012);
    $order_n2->set_coupon_codes(array('WELCOMEGATE2'));
    $order_n2->set_billing_email('gate-second@example.com');
    $order_n2->update_meta_data('_billing_documento_identidad', 'DOC-GATE');

    $caught_n2 = null;
    try {
        $controller->reserve_promo_usage(3012, array(), $order_n2);
    } catch (\Exception $e) {
        $caught_n2 = $e;
    }
    assert_true(null !== $caught_n2, '(n) Enforcement must still block a genuine repeat-document welcome-coupon order even while the checkout field is hidden by the gate.');
    assert_same($expected_message, $caught_n2->getMessage(), '(n) The block message must stay the exact generic wording regardless of the gate state.');
    assert_true(!$gate_reflection->invoke($controller), '(n) The gate itself must still read false after the enforcement check ran -- the two concerns never interact.');

    echo "CartController welcome-coupon first-purchase verification OK\n";
}
