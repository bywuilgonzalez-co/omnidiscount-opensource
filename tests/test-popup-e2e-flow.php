<?php
/**
 * Standalone END-TO-END smoke test for the popup email-capture feature,
 * exercising the REAL PopupController + REAL PopupModel + REAL
 * PopupCouponBridge together (unlike tests/test-popup-controller.php, which
 * deliberately stubs PopupModel/PopupCouponBridge to isolate
 * PopupController's own request-handling logic in isolation, and unlike
 * tests/test-popup-coupon-bridge.php / tests/test-popup-model.php, which
 * each exercise exactly one class alone).
 *
 * No PHPUnit, no WooCommerce, no real database -- same $wpdb-stub convention
 * as tests/test-cartcontroller-promo-reservation.php and
 * tests/test-popup-model.php (a purpose-built in-memory stand-in modelling
 * the exact SQL shapes the real classes issue), plus the same WC_Coupon/
 * WP_Error/mailer shims tests/test-popup-coupon-bridge.php and
 * tests/test-popup-controller.php already use. Only Drw\App\Models\PromoModel
 * and Drw\App\Models\SettingsModel are stubbed here (neither is part of the
 * popup subsystem itself -- PromoModel::code_exists() is popup coupon-code
 * collision-checking against the UNRELATED promo catalogue, and
 * SettingsModel is the plugin-wide settings singleton) -- every popup-owned
 * class (PopupModel, PopupCouponBridge, PopupController) is the real,
 * production source file.
 *
 * Coverage:
 *   (1) Full INSTANT-mode flow: submit() -> the response carries a real,
 *       freshly-minted code; the row PopupModel actually persisted is
 *       status=issued with that exact coupon_code/coupon_id.
 *   (2) Full CONFIRMED-mode flow: submit() -> a token is emailed (NOT
 *       revealed in the response), the persisted row is status=pending; then
 *       resolve_confirm_redirect_url() with that emailed token -> mints,
 *       persists status=issued, and redirects with the real code in the URL.
 *   (3) Confirm-token idempotency threaded through the real stack: a SECOND
 *       resolve of the same token returns the exact same code and does NOT
 *       mint a second WC_Coupon (asserted via the WC_Coupon id sequence
 *       never advancing on the repeat call).
 *   (4) Duplicate submission of an already-issued instant-mode email NEVER
 *       re-discloses the real code to the anonymous re-submission
 *       (round-2 security-audit fix), and never mints a second coupon --
 *       proven through the real insert_claim()/get_by_email() path, not a
 *       stub.
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for PromoModel -- only code_exists() is read by the
     * REAL PopupCouponBridge's collision check. No promo ever collides in
     * this test.
     */
    class PromoModel {
        public static function reset() {}
        public static function code_exists($code, $exclude_id = null) {
            return false;
        }
    }

    /** In-memory stand-in for SettingsModel -- only get_setting() is used by the REAL PopupController. */
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

namespace Drw\App\Controllers {

    /**
     * Controllable clock for the signed dwell-time token / confirm-race
     * poll, overriding the REAL global time()/current_time()/usleep() only
     * within PopupController's own namespace -- identical technique to
     * tests/test-popup-controller.php.
     */
    // Deliberately the SAME reference instant as the global current_time()
    // override below (PopupModel's own clock) -- PopupController and
    // PopupModel are both REAL classes in this file and must agree on "now"
    // for the confirm-token expiry math (set by PopupController, checked by
    // PopupModel's CAS) to behave sanely.
    $GLOBALS['__drw_test_time'] = strtotime('2026-07-10 12:00:00');
    function time() {
        return $GLOBALS['__drw_test_time'];
    }
    function current_time($type, $gmt = 0) {
        return ('timestamp' === $type) ? $GLOBALS['__drw_test_time'] : gmdate('Y-m-d H:i:s', $GLOBALS['__drw_test_time']);
    }
    function usleep($microseconds) {
        $GLOBALS['__drw_usleep_calls']++;
    }
}

namespace {

    define('ABSPATH', dirname(__DIR__) . '/');
    define('DAY_IN_SECONDS', 86400);
    define('HOUR_IN_SECONDS', 3600);
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    function assert_same($expected, $actual, $message) {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assert_true($condition, $message) {
        assert_same(true, (bool)$condition, $message);
    }

    // --- Minimal WP / WC shims (same set as tests/test-popup-controller.php) ---
    function __($text, $domain = null) {
        return $text;
    }
    function esc_html($text) {
        return $text;
    }
    function esc_html__($text, $domain = null) {
        return $text;
    }
    function esc_url($url) {
        return $url;
    }
    function esc_url_raw($url) {
        return is_string($url) ? trim($url) : '';
    }
    function sanitize_text_field($value) {
        return is_string($value) ? trim($value) : $value;
    }
    function wp_unslash($value) {
        return $value;
    }
    function absint($value) {
        return abs((int)$value);
    }
    function sanitize_email($value) {
        return is_string($value) ? strtolower(trim($value)) : '';
    }
    function is_email($value) {
        return (bool)preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string)$value);
    }
    function wp_salt($scheme = 'auth') {
        return 'test-salt';
    }
    function wp_hash($data, $scheme = 'auth') {
        return hash('sha256', $data . '|test-secret');
    }
    function is_wp_error($thing) {
        return $thing instanceof \WP_Error;
    }

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
        public function get_error_message() {
            return $this->message;
        }
    }

    $GLOBALS['__drw_test_transients'] = array();
    function get_transient($key) {
        return isset($GLOBALS['__drw_test_transients'][$key]) ? $GLOBALS['__drw_test_transients'][$key] : false;
    }
    function set_transient($key, $value, $expiration) {
        $GLOBALS['__drw_test_transients'][$key] = $value;
        return true;
    }

    $GLOBALS['__drw_nonce_calls'] = array();
    function check_ajax_referer($action, $query_arg = false, $die = true) {
        $GLOBALS['__drw_nonce_calls'][] = array('action' => $action, 'query_arg' => $query_arg);
        return true;
    }

    function home_url($path = '/') {
        return 'https://shop.test' . $path;
    }
    function admin_url($path = '') {
        return 'https://shop.test/wp-admin/' . $path;
    }
    function add_query_arg($args, $url) {
        $sep   = (false === strpos($url, '?')) ? '?' : '&';
        $parts = array();
        foreach ($args as $k => $v) {
            $parts[] = $k . '=' . rawurlencode((string)$v);
        }
        return $url . $sep . implode('&', $parts);
    }

    class DrwTestMailer {
        public static $sent = array();
        public function wrap_message($heading, $body) {
            return '[[' . $heading . ']]' . $body;
        }
        public function send($to, $subject, $message, $headers = '', $attachments = array()) {
            self::$sent[] = array('to' => $to, 'subject' => $subject, 'message' => $message);
            return true;
        }
    }
    class DrwTestWC {
        public function mailer() {
            return new DrwTestMailer();
        }
    }
    function WC() {
        return new DrwTestWC();
    }

    $GLOBALS['wc_coupon_id_by_code'] = array();
    function wc_get_coupon_id_by_code($code) {
        return isset($GLOBALS['wc_coupon_id_by_code'][$code]) ? (int)$GLOBALS['wc_coupon_id_by_code'][$code] : 0;
    }

    /** Minimal WC_Coupon stub, same shape as tests/test-popup-coupon-bridge.php's -- $next_id lets the test prove a repeat confirm never mints a second coupon. */
    class WC_Coupon {
        public static $next_id = 900;
        public $id = 0;
        public $props = array();
        public $meta  = array();

        public function __construct($id = 0) { $this->id = (int)$id; }
        public function set_code($v)                 { $this->props['code'] = $v; }
        public function set_discount_type($v)         { $this->props['discount_type'] = $v; }
        public function set_amount($v)                { $this->props['amount'] = $v; }
        public function set_individual_use($v)        { $this->props['individual_use'] = $v; }
        public function set_usage_limit($v)            { $this->props['usage_limit'] = $v; }
        public function set_usage_limit_per_user($v)   { $this->props['usage_limit_per_user'] = $v; }
        public function set_date_expires($v)           { $this->props['date_expires'] = $v; }
        public function set_minimum_amount($v)         { $this->props['minimum_amount'] = $v; }
        public function update_meta_data($k, $v)       { $this->meta[$k] = $v; }
        public function get_meta($k)                   { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
        public function get_id()                        { return $this->id; }
        public function save() {
            if (0 === $this->id) {
                $this->id = self::$next_id++;
            }
            return $this->id;
        }
    }

    /**
     * $wpdb stand-in for PopupModel (same technique as
     * tests/test-popup-model.php: regex-matched against the literal SQL
     * PopupModel.php issues) PLUS the one extra query
     * PopupCouponBridge::popup_code_exists() issues directly against the
     * same table (`SELECT COUNT(*) ... WHERE coupon_code = %s`) -- both real
     * classes share this single stub, exactly as they share the real table
     * in production.
     */
    class WpdbStub {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public $insert_id = 0;
        public $rows_affected = 0;
        public $table = array();
        /** Round-5 audit fix: backs PopupModel::try_reserve_daily_mint_slot()'s day-keyed wp_options counter. */
        public $options_table = array();
        private $next_id = 1;

        public function prepare($query, ...$args) {
            if (1 === count($args) && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
                $arg = isset($args[$i]) ? $args[$i] : null;
                $i++;
                return ('%d' === $m[0]) ? (string)(int)$arg : ("'" . addslashes((string)$arg) . "'");
            }, $query);
        }

        public function insert($table, $data) {
            foreach ($this->table as $row) {
                if ($row['email'] === $data['email']) {
                    return false; // UNIQUE(email) collision.
                }
            }
            $id  = $this->next_id++;
            $row = array_merge($this->blank_row(), $data, array('id' => $id));
            $this->table[$id] = $row;
            $this->insert_id  = $id;
            return 1;
        }

        public function update($table, $data, $where) {
            $id = (int)$where['id'];
            if (!isset($this->table[$id])) {
                return 0;
            }
            $this->table[$id] = array_merge($this->table[$id], $data);
            return 1;
        }

        public function query($sql) {
            if (preg_match(
                "/SET\\s+status\\s*=\\s*'([^']*)',\\s*reveal_mode\\s*=\\s*'([^']*)',\\s*claimed_at\\s*=\\s*'([^']*)',\\s*ip_hash\\s*=\\s*'([^']*)',\\s*user_agent\\s*=\\s*'([^']*)',\\s*source_url\\s*=\\s*'([^']*)'\\s*WHERE\\s+email\\s*=\\s*'([^']*)'\\s+AND\\s+status\\s*=\\s*'([^']*)'\\s+AND\\s+claimed_at\\s*<\\s*'([^']*)'/s",
                $sql,
                $m
            )) {
                list(, $new_status, $new_mode, $new_claimed_at, $new_ip_hash, $new_user_agent, $new_source_url, $email, $cond_status, $cutoff) = $m;
                $affected = 0;
                foreach ($this->table as &$row) {
                    if ($row['email'] === $email && $row['status'] === $cond_status && $row['claimed_at'] < $cutoff) {
                        $row['status']      = $new_status;
                        $row['reveal_mode'] = $new_mode;
                        $row['claimed_at']  = $new_claimed_at;
                        $row['ip_hash']     = $new_ip_hash;
                        $row['user_agent']  = $new_user_agent;
                        $row['source_url']  = $new_source_url;
                        $affected++;
                    }
                }
                unset($row);
                $this->rows_affected = $affected;
                return $affected;
            }

            if (preg_match(
                "/SET\\s+status\\s*=\\s*'([^']*)'\\s*WHERE\\s+confirm_token\\s*=\\s*'([^']*)'\\s+AND\\s+status\\s*=\\s*'([^']*)'\\s+AND\\s+token_expires_at\\s*>\\s*'([^']*)'/s",
                $sql,
                $m
            )) {
                list(, $new_status, $token, $cond_status, $now) = $m;
                $affected = 0;
                foreach ($this->table as &$row) {
                    if ($row['confirm_token'] === $token && $row['status'] === $cond_status && $row['token_expires_at'] > $now) {
                        $row['status'] = $new_status;
                        $affected++;
                    }
                }
                unset($row);
                $this->rows_affected = $affected;
                return $affected;
            }

            // Round-5 audit fix: PopupModel::try_reserve_daily_mint_slot()'s
            // atomic wp_options counter -- see that method's docblock.
            if (preg_match("/^INSERT IGNORE INTO wp_options \\(option_name, option_value, autoload\\) VALUES \\('([^']*)', '0', 'no'\\)$/", $sql, $m)) {
                if (!array_key_exists($m[1], $this->options_table)) {
                    $this->options_table[$m[1]] = 0;
                }
                return 1;
            }

            if (preg_match("/^UPDATE wp_options SET option_value = option_value \\+ 1 WHERE option_name = '([^']*)' AND CAST\\(option_value AS UNSIGNED\\) < (\\d+)$/", $sql, $m)) {
                $name    = $m[1];
                $cap     = (int)$m[2];
                $current = isset($this->options_table[$name]) ? (int)$this->options_table[$name] : 0;
                if ($current < $cap) {
                    $this->options_table[$name] = $current + 1;
                    $this->rows_affected = 1;
                    return 1;
                }
                $this->rows_affected = 0;
                return 0;
            }

            // purge_stale_rows()'s DELETEs (the two submission-table ones plus
            // the daily-mint-option-row cleanup) -- not exercised by this
            // file's scenarios, but handled so an unexpected cron-path call
            // never hard-fails the run.
            if (0 === strpos($sql, 'DELETE FROM')) {
                return 0;
            }

            fwrite(STDERR, "WpdbStub::query() received an unrecognised statement: {$sql}\n");
            exit(1);
        }

        public function get_row($sql, $output = ARRAY_A) {
            if (preg_match("/WHERE email = '([^']*)'/", $sql, $m)) {
                foreach ($this->table as $row) {
                    if ($row['email'] === $m[1]) {
                        return $row;
                    }
                }
                return null;
            }
            if (preg_match('/WHERE id = (\d+)/', $sql, $m)) {
                $id = (int)$m[1];
                return isset($this->table[$id]) ? $this->table[$id] : null;
            }
            if (preg_match("/WHERE confirm_token = '([^']*)'/", $sql, $m)) {
                foreach ($this->table as $row) {
                    if ($row['confirm_token'] === $m[1]) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        /** Backs both PopupModel::get_paginated()'s COUNT(*) and PopupCouponBridge::popup_code_exists()'s per-code COUNT(*). */
        public function get_var($sql) {
            if (preg_match("/WHERE coupon_code = '([^']*)'/", $sql, $m)) {
                foreach ($this->table as $row) {
                    if ($row['coupon_code'] === $m[1]) {
                        return '1';
                    }
                }
                return '0';
            }
            if (false !== strpos($sql, 'COUNT(*)')) {
                return (string)count($this->table);
            }
            return null;
        }

        public function get_results($sql, $output = ARRAY_A) {
            $rows = array_values($this->table);
            usort($rows, function ($a, $b) {
                return $b['id'] - $a['id'];
            });
            return $rows;
        }

        private function blank_row() {
            return array(
                'id' => 0, 'email' => '', 'status' => '', 'reveal_mode' => '',
                'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null,
                'token_expires_at' => null, 'ip_hash' => null, 'user_agent' => null,
                'source_url' => null, 'claimed_at' => null, 'created_at' => null,
                'confirmed_at' => null, 'revealed_at' => null,
            );
        }
    }

    // current_time() as PopupModel itself sees it (its own namespace has no
    // override in THIS file, unlike PopupController's namespaced clock
    // above) -- a fixed, real-looking "now" is enough; no cutoff/CAS timing
    // edge cases are under test here, those live in tests/test-popup-model.php.
    function current_time($type, $gmt = 0) {
        return ('timestamp' === $type) ? strtotime('2026-07-10 12:00:00') : '2026-07-10 12:00:00';
    }

    require_once dirname(__DIR__) . '/src/Controllers/RateLimiter.php';
    require_once dirname(__DIR__) . '/src/Models/PopupModel.php';
    require_once dirname(__DIR__) . '/src/Controllers/PopupCouponBridge.php';
    require_once dirname(__DIR__) . '/src/Controllers/PopupController.php';

    use Drw\App\Controllers\PopupController;
    use Drw\App\Models\PopupModel;
    use Drw\App\Models\SettingsModel;

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    function reset_world() {
        $GLOBALS['wpdb'] = new WpdbStub();
        SettingsModel::reset();
        SettingsModel::$values['popup'] = array(
            'discount_type'        => 'percent',
            'discount_value'       => 15.0,
            'expiry_days'          => 7,
            'min_cart_amount'      => 0.0,
            'daily_mint_cap'       => 200,
            'require_confirmation' => false,
            'email_subject'        => '',
            'email_heading'        => '',
            'email_intro'          => '',
        );
        SettingsModel::$values['popup.daily_mint_cap']       = 200;
        SettingsModel::$values['popup.require_confirmation'] = false;
        $GLOBALS['__drw_test_transients'] = array();
        $GLOBALS['__drw_nonce_calls']     = array();
        $GLOBALS['__drw_usleep_calls']    = 0;
        $GLOBALS['wc_coupon_id_by_code']  = array();
        DrwTestMailer::$sent = array();
    }

    /** Builds a valid, correctly-aged dwell-time token pair via the REAL PopupController, then advances the test clock past MIN_DWELL_SECONDS. */
    function fresh_render_token_post() {
        $token = PopupController::issue_render_token();
        $GLOBALS['__drw_test_time'] += 5;
        return array('rendered_at' => $token['rendered_at'], 'render_signature' => $token['signature']);
    }

    function base_post($email) {
        $render = fresh_render_token_post();
        return array(
            'email'            => $email,
            'nonce'            => 'irrelevant-in-this-stub',
            'rendered_at'      => $render['rendered_at'],
            'render_signature' => $render['render_signature'],
            'source_url'       => 'https://shop.test/',
        );
    }

    function assert_valid_code($code, $message) {
        global $alphabet;
        assert_true(is_string($code) && 8 === strlen($code), $message . ' (must be an 8-char string)');
        for ($i = 0; $i < strlen($code); $i++) {
            assert_true(false !== strpos($alphabet, $code[$i]), $message . " (char '{$code[$i]}' must be in the documented alphabet)");
        }
    }

    // =========================================================================
    // (1) Full INSTANT-mode flow: submit -> code revealed immediately, and the
    // REAL PopupModel row landed exactly where the response says it did.
    // =========================================================================
    reset_world();
    $result_1 = PopupController::process_submit(base_post('instant-e2e@example.com'));
    assert_true($result_1['success'], '(1) A fresh instant submission must succeed.');
    assert_same('instant', $result_1['data']['mode'], '(1) Response mode must be instant.');
    assert_valid_code($result_1['data']['code'], '(1) The response code');

    $row_1 = PopupModel::get_by_email('instant-e2e@example.com');
    assert_true(null !== $row_1, '(1) The REAL PopupModel must have persisted a row for this email.');
    assert_same(PopupModel::STATUS_ISSUED, $row_1['status'], '(1) The persisted row must be status=issued.');
    assert_same($result_1['data']['code'], $row_1['coupon_code'], '(1) The persisted coupon_code must match the code returned to the visitor.');
    assert_true($row_1['coupon_id'] > 0, '(1) The persisted row must carry a real, positive coupon_id.');

    // =========================================================================
    // (2) Full CONFIRMED-mode flow: submit -> token emailed, NOT revealed;
    // resolve the emailed token -> mint + persist issued + redirect w/ code.
    // =========================================================================
    reset_world();
    SettingsModel::$values['popup']['require_confirmation'] = true;

    $result_2 = PopupController::process_submit(base_post('confirmed-e2e@example.com'));
    assert_true($result_2['success'], '(2) A fresh confirmed submission must succeed.');
    assert_same('confirmed', $result_2['data']['mode'], '(2) Response mode must be confirmed.');
    assert_same(null, $result_2['data']['code'], '(2) A confirmed-mode submit response must NEVER reveal a code synchronously.');

    $row_2_pending = PopupModel::get_by_email('confirmed-e2e@example.com');
    assert_same(PopupModel::STATUS_PENDING, $row_2_pending['status'], '(2) The persisted row must be status=pending, awaiting confirmation.');
    assert_true(!empty($row_2_pending['confirm_token']), '(2) A hashed confirm_token must have been persisted.');

    assert_same(1, count(DrwTestMailer::$sent), '(2) Exactly one confirmation email must have been sent.');
    assert_true(
        1 === preg_match('/[?&]token=([0-9a-f]{64})/', DrwTestMailer::$sent[0]['message'], $token_match),
        '(2) The emailed confirm link must contain a 64-hex-char raw token.'
    );
    $raw_token_2 = $token_match[1];

    $redirect_url_2 = PopupController::resolve_confirm_redirect_url($raw_token_2);
    assert_true(false !== strpos($redirect_url_2, 'drw_popup_confirmed=1'), '(2) A valid confirm click must redirect with confirmed=1.');
    assert_true(1 === preg_match('/[?&]code=([A-Z0-9]+)/', $redirect_url_2, $code_match), '(2) The redirect URL must carry the real minted code.');
    $revealed_code_2 = $code_match[1];
    assert_valid_code($revealed_code_2, '(2) The code revealed after confirmation');

    $row_2_issued = PopupModel::get_by_email('confirmed-e2e@example.com');
    assert_same(PopupModel::STATUS_ISSUED, $row_2_issued['status'], '(2) After confirmation the REAL PopupModel row must now be status=issued.');
    assert_same($revealed_code_2, $row_2_issued['coupon_code'], '(2) The persisted coupon_code must match the code in the redirect URL.');
    assert_true($row_2_issued['coupon_id'] > 0, '(2) The persisted row must carry a real, positive coupon_id after confirmation.');

    // =========================================================================
    // (3) Confirm-token idempotency, threaded through the REAL stack: a
    // second resolve of the SAME token must return the identical code and
    // must NOT mint a second WC_Coupon.
    // =========================================================================
    $coupon_id_sequence_before = WC_Coupon::$next_id;
    $redirect_url_2_again = PopupController::resolve_confirm_redirect_url($raw_token_2);
    assert_same($redirect_url_2, $redirect_url_2_again, '(3) A repeat resolve of the same confirmed token must land on the EXACT SAME URL.');
    assert_same($coupon_id_sequence_before, WC_Coupon::$next_id, '(3) A repeat resolve must NEVER mint a second WC_Coupon (the id sequence must not advance).');

    // =========================================================================
    // (4) Duplicate submission of an already-issued instant-mode email must
    // NEVER re-disclose the real code to the anonymous re-submission (this
    // endpoint has no ownership/session binding on the email -- round-2
    // security-audit fix), through the real insert_claim()/get_by_email()
    // path, and must never mint a second coupon. Self-contained (own
    // reset_world() + own fresh submission first) rather than reusing (1)'s
    // email/world, which reset_world() at the top of (2) already tore down.
    // =========================================================================
    reset_world();
    $result_4a = PopupController::process_submit(base_post('duplicate-e2e@example.com'));
    assert_true($result_4a['success'], '(4) Sanity: the first submission for this email must succeed.');
    assert_valid_code($result_4a['data']['code'], '(4) Sanity: the first submission');

    $coupon_id_sequence_before_4 = WC_Coupon::$next_id;
    $result_4b = PopupController::process_submit(base_post('duplicate-e2e@example.com'));
    assert_true($result_4b['success'], '(4) A duplicate already-issued email must still report success (uniform response).');
    assert_same(null, $result_4b['data']['code'], '(4) A duplicate already-issued email must NEVER disclose the real coupon code to an anonymous re-submission.');
    assert_same($coupon_id_sequence_before_4, WC_Coupon::$next_id, '(4) A duplicate already-issued email must NEVER mint a second coupon.');

    echo "Popup end-to-end flow OK\n";
}
