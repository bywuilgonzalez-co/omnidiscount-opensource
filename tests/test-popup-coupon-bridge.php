<?php
/**
 * Standalone smoke test for PopupCouponBridge (src/Controllers/PopupCouponBridge.php)
 * -- the popup's coupon-minting subsystem ("Generación del cupón único" in
 * the popup-capture plan). No PHPUnit, no WooCommerce, no database -- same
 * style as tests/test-promo-bridge-exclusivity.php: stub
 * Drw\App\Models\PromoModel in its real namespace, a minimal WC_Coupon and
 * $wpdb stand-in, and hard-failing assert helpers.
 *
 * Coverage:
 *   (a) generate_unique_code() produces an 8-char code drawn only from the
 *       documented 32-symbol alphabet (no 0/O/1/I/L).
 *   (b) Collision retry: the first random_int() draw collides against
 *       PromoModel::code_exists() (or wc_get_coupon_id_by_code(), or the
 *       popup table itself) -- generate_unique_code() must retry and
 *       return a DIFFERENT, non-colliding code, not fail or reuse it.
 *   (c) 5-attempt exhaustion: every attempt collides -> WP_Error returned,
 *       exactly MAX_ATTEMPTS (5) random_int() draws made, no 6th attempt.
 *   (d) mint_coupon(): a percent discount_value of 150 clamps to 100 on the
 *       saved WC_Coupon.
 *   (e) mint_coupon(): expiry_days=0 clamps to 1 (set_date_expires() is
 *       called with a timestamp ~1 day out, not "already expired"/0).
 *   (f) mint_coupon(): individual_use/usage_limit/usage_limit_per_user are
 *       ALWAYS true/1/1 on the saved coupon, regardless of what (bogus)
 *       values might be lurking in the template array -- they are fixed in
 *       code, never read from $template at all.
 *   (g) mint_coupon(): fixed discount_type maps to WooCommerce's
 *       'fixed_cart' discount_type (not 'fixed', which is not a valid
 *       WC_Coupon discount type) and a negative discount_value clamps to 0.
 *   (h) mint_coupon(): _drw_popup_submission_id meta is stamped with the
 *       submission id, and the coupon's own code matches the code the
 *       generator produced.
 *   (i) mint_coupon(): min_cart_amount<=0 never calls set_minimum_amount()
 *       at all; a positive min_cart_amount calls it with the clamped value.
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for the real PromoModel. Only code_exists() is
     * used by PopupCouponBridge -- fully controllable per test case.
     */
    class PromoModel {
        /** @var array<string,bool> code (uppercase, as generated) => collides? */
        public static $colliding_codes = array();
        /** @var string[] Every code passed to code_exists(), in call order. */
        public static $checked_codes = array();

        public static function reset() {
            self::$colliding_codes = array();
            self::$checked_codes   = array();
        }

        public static function code_exists($code, $exclude_id = null) {
            self::$checked_codes[] = $code;
            return !empty(self::$colliding_codes[$code]);
        }
    }
}

namespace Drw\App\Controllers {

    /**
     * Deterministic random_int() override for PopupCouponBridge's
     * random_code(). PHP resolves an UNQUALIFIED function call at RUNTIME by
     * first checking for a same-namespace function and only falling back to
     * the global one if none exists -- so defining random_int() here (in
     * PopupCouponBridge's own namespace) transparently intercepts every call
     * it makes, without touching the class itself. When the queue is empty,
     * falls through to the real CSPRNG (\random_int()) so any call this test
     * suite does not care to control still behaves normally.
     */
    $GLOBALS['__drw_random_int_queue'] = array();
    function random_int($min, $max) {
        if (!empty($GLOBALS['__drw_random_int_queue'])) {
            return array_shift($GLOBALS['__drw_random_int_queue']);
        }
        return \random_int($min, $max);
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

    // --- Minimal WP / WC shims -------------------------------------------------
    function __($text, $domain = null) {
        return $text;
    }
    function is_wp_error($thing) {
        return $thing instanceof \WP_Error;
    }
    function current_time($type) {
        // Fixed reference instant so expiry-day math is deterministic.
        return strtotime('2026-07-10 00:00:00');
    }

    /** Minimal WP_Error, same shape PromosController/ImportExportController already rely on. */
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = array()) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() {
            return $this->code;
        }
    }

    $GLOBALS['wc_coupon_id_by_code'] = array(); // code => coupon id (0/absent = no collision)
    function wc_get_coupon_id_by_code($code) {
        return isset($GLOBALS['wc_coupon_id_by_code'][$code]) ? (int)$GLOBALS['wc_coupon_id_by_code'][$code] : 0;
    }

    /** Minimal WC_Coupon stub, same shape as tests/test-promo-bridge-exclusivity.php's. */
    class WC_Coupon {
        public static $next_id = 900;
        public $id = 0;
        public $props = array();
        public $meta  = array();
        public $calls_minimum_amount = 0;

        public function __construct($id = 0) { $this->id = (int)$id; }

        public function set_code($v)                 { $this->props['code'] = $v; }
        public function set_discount_type($v)         { $this->props['discount_type'] = $v; }
        public function set_amount($v)                { $this->props['amount'] = $v; }
        public function set_individual_use($v)        { $this->props['individual_use'] = $v; }
        public function set_usage_limit($v)            { $this->props['usage_limit'] = $v; }
        public function set_usage_limit_per_user($v)   { $this->props['usage_limit_per_user'] = $v; }
        public function set_date_expires($v)           { $this->props['date_expires'] = $v; }
        public function set_minimum_amount($v)         { $this->props['minimum_amount'] = $v; $this->calls_minimum_amount++; }
        public function update_meta_data($k, $v)       { $this->meta[$k] = $v; }
        public function get_meta($k)                   { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
        public function get_id()                        { return $this->id; }
        public function save() {
            if (0 === $this->id) {
                $this->id = self::$next_id++;
            }
            $GLOBALS['last_saved_coupon'] = $this;
            return $this->id;
        }
    }

    /**
     * $wpdb stand-in for popup_code_exists() (SELECT COUNT(*) FROM
     * wp_drw_popup_submissions WHERE coupon_code = %s).
     */
    class WpdbStub {
        public $prefix = 'wp_';
        /** @var array<string,bool> code => already recorded in the popup table? */
        public $popup_codes = array();
        /** @var string[] Every code checked, in call order. */
        public $checked_codes = array();

        public function prepare($query, ...$args) {
            if (1 === count($args) && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
                $arg = isset($args[$i]) ? $args[$i] : null;
                $i++;
                return "'" . addslashes((string)$arg) . "'";
            }, $query);
        }

        public function get_var($query) {
            if (preg_match("/WHERE coupon_code = '([^']*)'/", $query, $m)) {
                $code = $m[1];
                $this->checked_codes[] = $code;
                return !empty($this->popup_codes[$code]) ? '1' : '0';
            }
            return '0';
        }
    }

    require_once dirname(__DIR__) . '/src/Controllers/PopupCouponBridge.php';

    use Drw\App\Controllers\PopupCouponBridge;
    use Drw\App\Models\PromoModel;

    function reset_world() {
        PromoModel::reset();
        $GLOBALS['wc_coupon_id_by_code'] = array();
        $GLOBALS['wpdb'] = new WpdbStub();
        $GLOBALS['last_saved_coupon'] = null;
        $GLOBALS['__drw_random_int_queue'] = array();
    }

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Queue the exact alphabet-index draws needed for random_code() to
     * produce $code, via the Drw\App\Controllers\random_int() override above.
     */
    function queue_code($code) {
        global $alphabet;
        foreach (str_split($code) as $ch) {
            $GLOBALS['__drw_random_int_queue'][] = strpos($alphabet, $ch);
        }
    }

    // =====================================================================
    // (a) generate_unique_code(): 8-char code, alphabet-only.
    // =====================================================================
    reset_world();
    $code = PopupCouponBridge::generate_unique_code();
    assert_true(is_string($code), '(a) generate_unique_code() must return a string when no collision occurs.');
    assert_same(8, strlen($code), '(a) The generated code must be exactly 8 characters long.');
    for ($i = 0; $i < strlen($code); $i++) {
        assert_true(false !== strpos($alphabet, $code[$i]), "(a) Character '{$code[$i]}' at position $i must belong to the documented alphabet (no 0/O/1/I/L).");
    }

    // =====================================================================
    // (b) Collision retry: force the FIRST attempt's EXACT code to collide
    // (via PromoModel::code_exists()), confirm a retry happens and the
    // second, non-colliding queued code is the one actually returned.
    // =====================================================================
    reset_world();
    queue_code('AAAAAAAA'); // attempt 1: forced to collide.
    queue_code('BBBBBBBB'); // attempt 2: not colliding, must be the result.
    PromoModel::$colliding_codes['AAAAAAAA'] = true;

    $retried = PopupCouponBridge::generate_unique_code();
    assert_true(is_string($retried), '(b) A single first-attempt collision must still resolve to a string, not a WP_Error.');
    assert_same(2, count(PromoModel::$checked_codes), '(b) A first-attempt collision must trigger exactly one retry (code_exists() checked exactly twice).');
    assert_same('AAAAAAAA', PromoModel::$checked_codes[0], '(b) The first attempt must be the code we forced to collide.');
    assert_same('BBBBBBBB', PromoModel::$checked_codes[1], '(b) The retry must check the second queued (non-colliding) code.');
    assert_same('BBBBBBBB', $retried, '(b) The FINAL returned code must be the second, non-colliding attempt -- never the forced-colliding first one.');

    // =====================================================================
    // (c) 5-attempt exhaustion: every attempt collides -> WP_Error, exactly
    // 5 code_exists() checks, no 6th attempt.
    // =====================================================================
    reset_world();
    $exhaustion_codes = array('CCCCCCC2', 'CCCCCCC3', 'CCCCCCC4', 'CCCCCCC5', 'CCCCCCC6');
    foreach ($exhaustion_codes as $ec) {
        queue_code($ec);
        PromoModel::$colliding_codes[$ec] = true;
    }

    $exhausted = PopupCouponBridge::generate_unique_code();
    assert_true(is_wp_error($exhausted), '(c) 5 consecutive collisions must return a WP_Error.');
    assert_same('drw_popup_code_exhausted', $exhausted->get_error_code(), '(c) The WP_Error code must be drw_popup_code_exhausted.');
    assert_same(5, count(PromoModel::$checked_codes), '(c) Exactly MAX_ATTEMPTS (5) codes must have been checked, no 6th attempt.');
    assert_same($exhaustion_codes, PromoModel::$checked_codes, '(c) Every one of the 5 distinct queued colliding codes must have been tried, in order.');

    // =====================================================================
    // (d) mint_coupon(): percent discount_value=150 clamps to 100.
    // =====================================================================
    reset_world();
    $result_d = PopupCouponBridge::mint_coupon(42, array(
        'discount_type'   => 'percent',
        'discount_value'  => 150,
        'expiry_days'     => 7,
        'min_cart_amount' => 0,
    ));
    assert_true(!is_wp_error($result_d), '(d) mint_coupon() must succeed with no collisions configured.');
    $coupon_d = $GLOBALS['last_saved_coupon'];
    assert_same(100.0, $coupon_d->props['amount'], '(d) A percent discount_value of 150 must clamp to 100.0 on the saved coupon.');
    assert_same('percent', $coupon_d->props['discount_type'], '(d) discount_type=percent must map to WC_Coupon discount_type percent.');

    // =====================================================================
    // (e) mint_coupon(): expiry_days=0 clamps to 1 (date_expires ~1 day out).
    // =====================================================================
    reset_world();
    $result_e = PopupCouponBridge::mint_coupon(43, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 0,
        'min_cart_amount' => 0,
    ));
    assert_true(!is_wp_error($result_e), '(e) mint_coupon() must succeed with no collisions configured.');
    $coupon_e = $GLOBALS['last_saved_coupon'];
    $now = current_time('timestamp');
    $expected_expiry = strtotime('+1 days', $now);
    assert_same($expected_expiry, $coupon_e->props['date_expires'], '(e) expiry_days=0 must clamp to 1 day, matching strtotime(\'+1 days\', now) exactly.');

    // A large expiry (400 days) must clamp down to 365.
    reset_world();
    $result_e2 = PopupCouponBridge::mint_coupon(44, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 400,
        'min_cart_amount' => 0,
    ));
    $coupon_e2 = $GLOBALS['last_saved_coupon'];
    assert_same(strtotime('+365 days', $now), $coupon_e2->props['date_expires'], '(e) expiry_days=400 must clamp down to 365 days.');

    // =====================================================================
    // (f) mint_coupon(): individual_use/usage_limit/usage_limit_per_user
    // always true/1/1, regardless of bogus template values.
    // =====================================================================
    reset_world();
    $result_f = PopupCouponBridge::mint_coupon(45, array(
        'discount_type'         => 'percent',
        'discount_value'        => 10,
        'expiry_days'           => 7,
        'min_cart_amount'       => 0,
        // Bogus/attacker-controlled-looking keys that must be IGNORED --
        // the class never reads these from $template at all.
        'individual_use'        => false,
        'usage_limit'           => 999,
        'usage_limit_per_user'  => 999,
    ));
    $coupon_f = $GLOBALS['last_saved_coupon'];
    assert_same(true, $coupon_f->props['individual_use'], '(f) individual_use must always be true, regardless of template input.');
    assert_same(1, $coupon_f->props['usage_limit'], '(f) usage_limit must always be 1, regardless of template input.');
    assert_same(1, $coupon_f->props['usage_limit_per_user'], '(f) usage_limit_per_user must always be 1, regardless of template input.');

    // =====================================================================
    // (g) mint_coupon(): fixed -> 'fixed_cart', negative value clamps to 0.
    // =====================================================================
    reset_world();
    $result_g = PopupCouponBridge::mint_coupon(46, array(
        'discount_type'   => 'fixed',
        'discount_value'  => -25,
        'expiry_days'     => 7,
        'min_cart_amount' => 0,
    ));
    $coupon_g = $GLOBALS['last_saved_coupon'];
    assert_same('fixed_cart', $coupon_g->props['discount_type'], "(g) discount_type=fixed must map to WooCommerce's fixed_cart, not the invalid 'fixed'.");
    assert_same(0.0, $coupon_g->props['amount'], '(g) A negative fixed discount_value must clamp to 0.0, never go negative.');

    // =====================================================================
    // (h) mint_coupon(): _drw_popup_submission_id meta + code match.
    // =====================================================================
    reset_world();
    $result_h = PopupCouponBridge::mint_coupon(777, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 7,
        'min_cart_amount' => 0,
    ));
    $coupon_h = $GLOBALS['last_saved_coupon'];
    assert_same(777, $coupon_h->get_meta('_drw_popup_submission_id'), '(h) _drw_popup_submission_id meta must be stamped with the submission id.');
    assert_true(!$coupon_h->get_meta('_drw_welcome_coupon_used'), '(h) _drw_welcome_coupon_used must NOT be set at mint time -- that is the identity-check phase\'s job at redemption.');
    assert_same($result_h['code'], $coupon_h->props['code'], "(h) mint_coupon()'s returned code must match the coupon's own set_code() value.");
    assert_same($coupon_h->get_id(), $result_h['coupon_id'], "(h) mint_coupon()'s returned coupon_id must match the saved coupon's real id.");

    // =====================================================================
    // (i) mint_coupon(): min_cart_amount<=0 never calls set_minimum_amount();
    // a positive value does, with the clamped amount.
    // =====================================================================
    reset_world();
    $result_i1 = PopupCouponBridge::mint_coupon(48, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 7,
        'min_cart_amount' => 0,
    ));
    $coupon_i1 = $GLOBALS['last_saved_coupon'];
    assert_true(!array_key_exists('minimum_amount', $coupon_i1->props), '(i) min_cart_amount=0 must never call set_minimum_amount() at all.');

    reset_world();
    $result_i2 = PopupCouponBridge::mint_coupon(49, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 7,
        'min_cart_amount' => -50, // negative must clamp to 0 and therefore also skip.
    ));
    $coupon_i2 = $GLOBALS['last_saved_coupon'];
    assert_true(!array_key_exists('minimum_amount', $coupon_i2->props), '(i) A negative min_cart_amount must clamp to 0 and also skip set_minimum_amount().');

    reset_world();
    $result_i3 = PopupCouponBridge::mint_coupon(50, array(
        'discount_type'   => 'percent',
        'discount_value'  => 10,
        'expiry_days'     => 7,
        'min_cart_amount' => 75.5,
    ));
    $coupon_i3 = $GLOBALS['last_saved_coupon'];
    assert_same(75.5, $coupon_i3->props['minimum_amount'], '(i) A positive min_cart_amount must call set_minimum_amount() with the (clamped) value.');

    echo "Popup coupon bridge OK\n";
}
