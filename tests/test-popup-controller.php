<?php
/**
 * Standalone smoke test for PopupController (src/Controllers/PopupController.php)
 * -- the popup email-capture feature's public admin-ajax endpoints
 * (drw_popup_submit / drw_popup_confirm) and their anti-abuse layers. No
 * PHPUnit, no WooCommerce, no database -- same style as
 * tests/test-cartcontroller-promo-reservation.php: stub
 * Drw\App\Models\PopupModel and Drw\App\Controllers\PopupCouponBridge in
 * their real namespaces (in-memory, fully controllable), the REAL
 * RateLimiter.php (backed by stubbed get_transient()/set_transient(), same
 * approach as that file), and hard-failing assert helpers.
 *
 * process_submit()/resolve_confirm_redirect_url() are the pure, testable
 * cores this file exercises directly -- the actual add_action() callbacks
 * (submit()/confirm()) are thin wrappers around them that call
 * wp_send_json_*()/wp_safe_redirect()+exit() in real WordPress, which is
 * deliberately NOT exercised here (see PopupController's own class
 * docblock for why).
 *
 * time()/current_time()/usleep() are overridden INSIDE the
 * Drw\App\Controllers namespace (same "PHP resolves an unqualified call by
 * checking the calling function's own namespace first" technique
 * tests/test-popup-coupon-bridge.php already uses for random_int()), so the
 * dwell-time clock is fully controllable without any real sleep() calls,
 * and the confirm-token race-poll loop never actually blocks the test run.
 *
 * KNOWN SCOPE LIMITATION: check_ajax_referer() failure is NOT exercised as
 * its own rejection case here. In real WordPress a failed check dies the
 * request immediately (confirmed live against the real admin-ajax path
 * during this feature's verification -- see the task report), which would
 * abort this whole test file's process if faithfully reproduced by the
 * stub. This file only proves check_ajax_referer() is CALLED, with the
 * right action name, at the right point in the sequence (after honeypot/
 * dwell-time, before rate limiting) -- the rejection behaviour itself is
 * WP core's own well-established mechanism, already used identically by
 * the plugin's pre-existing ShortcodeController::ajax_load_sale_items().
 *
 * Coverage:
 *   (a) Honeypot filled -> uniform success, NO rate-limit/DB/mint calls at all.
 *   (b) Dwell-time too fast (valid signature, 0s elapsed) -> uniform
 *       success, no DB/mint calls.
 *   (c) Tampered signature -> uniform success, no DB/mint calls.
 *   (c2)/(c3) ROUND-8 AUDIT FIX regression guard: a render token 5h old
 *       (within the new 20h ceiling) is still honored as a real claim; one
 *       21h old (past the new ceiling) still gets the silent-bot treatment.
 *   (d) check_ajax_referer() is called (right action) once honeypot+dwell pass.
 *   (e) IP rate-limit bucket exhausted -> 429 rate_limited, no insert_claim().
 *   (f) Email rate-limit bucket exhausted -> 429 rate_limited.
 *   (g) Global daily-cap bucket exhausted -> 429 rate_limited (round-6 audit
 *       fix: reserved right before the real mint, so insert_claim() DOES
 *       still run -- only the mint is blocked).
 *   (h) Invalid email format -> 400 invalid_email, no insert_claim(), and
 *       (round-6 audit fix regression guard) no daily-cap reservation either.
 *   (i) Fresh instant-mode success: insert_claim() + mint_coupon() +
 *       mark_issued() all called correctly; response carries the real code.
 *   (j) Fresh confirmed-mode success: mark_pending() called, confirmation
 *       email sent via the WC()->mailer() stub, response has code=null.
 *   (k) Duplicate email, ALREADY issued (instant mode): code is NEVER
 *       re-disclosed to an anonymous re-submission (round-2 security-audit
 *       fix -- this endpoint has no ownership/session check on the email),
 *       response is uniform success with code=null, mint_coupon() NOT
 *       called again.
 *   (l) Duplicate email, still pending/claimed (instant mode, narrow edge):
 *       uniform success with code=null, no mint call.
 *   (m) Duplicate email under CONFIRMED mode (any prior status): uniform
 *       "check your email" response, confirmation email NOT resent.
 *   (n) Abandoned-claim reclaim path: insert_claim() fails (duplicate),
 *       reclaim_abandoned_claim() succeeds -> proceeds exactly like a fresh
 *       claim (mint_coupon() IS called).
 *   (o) mint_coupon() returns a WP_Error -> 500 response with the error's
 *       own code/message, mark_issued() NOT called; ROUND-9 AUDIT FIX: the
 *       daily-cap slot reserved just before the failed mint must be released
 *       (PopupModel::release_daily_mint_slot()), never permanently burned.
 *   (p) Confirm-token idempotency: first resolve call wins the CAS and
 *       mints; second and third calls lose the CAS but resolve to the SAME
 *       code -- mint_coupon() called exactly ONCE across all three.
 *   (q) Confirm with an empty/unknown token -> failure landing URL, no DB/mint calls.
 *   (r) Confirm CAS-lost + row still mid-flight ('claimed', not yet
 *       'issued') -> the race-poll loop runs (usleep() called, no real
 *       delay) and picks up the winner's code once it appears.
 *   (t2) Round-6 audit fix: CONFIRMED mode's real mint, at confirm time via
 *        resolve_confirm_redirect_url(), consumes exactly one daily-cap slot
 *        (never at process_submit() time -- see (j)).
 *   (t3) Round-6 audit fix: an exhausted daily cap at confirm time fails
 *        closed even after winning the claim_pending_token() CAS -- never
 *        mints, never fabricates a code.
 *   (t1b) ROUND-9 AUDIT FIX: mint_coupon() returning a WP_Error on the
 *        confirm path also releases its just-reserved daily-cap slot
 *        (symmetric to (o) on the submit path).
 *   (s)/(t) ROUND-9 AUDIT FIX regression guard: a lost mark_issued() CAS
 *        (own coupon voided as an orphan) ALSO releases the losing request's
 *        own daily-cap slot on both the submit (s) and confirm (t) paths --
 *        the winner's own request already reserved and keeps its own slot
 *        for the coupon that actually got issued.
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for the real PopupModel. Rows are keyed by an
     * incrementing id; email and token-hash lookups are maintained as
     * parallel indexes, mirroring the real table's UNIQUE(email) /
     * confirm_token_idx behaviour closely enough for these tests (INSERT
     * uniqueness is proven separately, live, in the multi-process real-DB
     * concurrency run documented in the task report -- this stub is for the
     * request-handling LOGIC around that guarantee, not the guarantee itself).
     */
    class PopupModel {
        const STATUS_CLAIMED = 'claimed';
        const STATUS_PENDING = 'pending';
        const STATUS_ISSUED  = 'issued';
        const REVEAL_INSTANT   = 'instant';
        const REVEAL_CONFIRMED = 'confirmed';
        const TOKEN_TTL_SECONDS = 172800;

        public static $rows        = array();
        public static $by_email    = array();
        public static $by_token    = array();
        public static $next_id     = 1;

        public static $insert_calls          = array();
        public static $reclaim_calls         = array();
        public static $reclaim_result        = false; // bool or queue (array)
        public static $mark_pending_calls    = array();
        public static $mark_issued_calls     = array();
        public static $claim_pending_calls   = array();
        public static $claim_pending_result  = true; // bool or queue (array)

        /** Round-5 audit fix: stub for the atomic global daily-mint cap (replaces the old RateLimiter-backed GLOBAL_DAILY_BUCKET). bool or queue (array). */
        public static $daily_mint_calls  = array();
        public static $daily_mint_result = true;

        /** ROUND-9 AUDIT FIX: counts release_daily_mint_slot() calls -- see PopupController's two call sites. */
        public static $release_daily_mint_calls = 0;

        public static function reset() {
            self::$rows       = array();
            self::$by_email   = array();
            self::$by_token   = array();
            self::$next_id    = 1;
            self::$insert_calls         = array();
            self::$reclaim_calls        = array();
            self::$reclaim_result       = false;
            self::$mark_pending_calls   = array();
            self::$mark_issued_calls    = array();
            self::$mark_issued_result   = true;
            self::$claim_pending_calls  = array();
            self::$claim_pending_result = true;
            self::$daily_mint_calls     = array();
            self::$daily_mint_result    = true;
            self::$release_daily_mint_calls = 0;
        }

        public static function try_reserve_daily_mint_slot($daily_cap) {
            self::$daily_mint_calls[] = $daily_cap;
            $result = is_array(self::$daily_mint_result) ? array_shift(self::$daily_mint_result) : self::$daily_mint_result;
            return (bool)$result;
        }

        /** ROUND-9 AUDIT FIX: stub for the new release counterpart -- see PopupModel::release_daily_mint_slot()'s real docblock. */
        public static function release_daily_mint_slot() {
            self::$release_daily_mint_calls++;
        }

        public static function insert_claim($email, $reveal_mode, $ip_hash, $user_agent, $source_url) {
            self::$insert_calls[] = array(
                'email' => $email, 'reveal_mode' => $reveal_mode,
                'ip_hash' => $ip_hash, 'user_agent' => $user_agent, 'source_url' => $source_url,
            );

            if (isset(self::$by_email[$email])) {
                return false;
            }

            $id = self::$next_id++;
            self::$rows[$id] = array(
                'id' => $id, 'email' => $email, 'status' => self::STATUS_CLAIMED,
                'reveal_mode' => $reveal_mode, 'coupon_id' => null, 'coupon_code' => null,
                'confirm_token' => null, 'token_expires_at' => null,
            );
            self::$by_email[$email] = $id;

            return $id;
        }

        public static function reclaim_abandoned_claim($email, $reveal_mode, $ip_hash = '', $user_agent = '', $source_url = '') {
            self::$reclaim_calls[] = array(
                'email' => $email, 'reveal_mode' => $reveal_mode,
                'ip_hash' => $ip_hash, 'user_agent' => $user_agent, 'source_url' => $source_url,
            );

            $result = is_array(self::$reclaim_result) ? array_shift(self::$reclaim_result) : self::$reclaim_result;

            if ($result && isset(self::$by_email[$email])) {
                $id = self::$by_email[$email];
                self::$rows[$id]['status']      = self::STATUS_CLAIMED;
                self::$rows[$id]['reveal_mode'] = $reveal_mode;
                self::$rows[$id]['ip_hash']     = $ip_hash;
                self::$rows[$id]['user_agent']  = $user_agent;
                self::$rows[$id]['source_url']  = $source_url;
            }

            return (bool)$result;
        }

        public static function get_by_email($email) {
            return isset(self::$by_email[$email]) ? self::$rows[self::$by_email[$email]] : null;
        }

        public static function get_by_id($id) {
            return isset(self::$rows[$id]) ? self::$rows[$id] : null;
        }

        public static function get_by_token_hash($token_hash) {
            return isset(self::$by_token[$token_hash]) ? self::$rows[self::$by_token[$token_hash]] : null;
        }

        public static function mark_pending($id, $token_hash, $expires_at) {
            self::$mark_pending_calls[] = array('id' => $id, 'token_hash' => $token_hash, 'expires_at' => $expires_at);

            self::$rows[$id]['status']           = self::STATUS_PENDING;
            self::$rows[$id]['confirm_token']    = $token_hash;
            self::$rows[$id]['token_expires_at'] = $expires_at;
            self::$by_token[$token_hash]         = $id;

            return true;
        }

        /** bool or queue (array) -- controls whether mark_issued() "wins the CAS", same convention as $reclaim_result/$claim_pending_result. */
        public static $mark_issued_result = true;

        public static function mark_issued($id, $coupon_id, $coupon_code) {
            self::$mark_issued_calls[] = array('id' => $id, 'coupon_id' => $coupon_id, 'coupon_code' => $coupon_code);

            $result = is_array(self::$mark_issued_result) ? array_shift(self::$mark_issued_result) : self::$mark_issued_result;

            if (!$result) {
                // Lost the CAS (round-3 audit fix): a concurrent call already
                // won and stamped the row -- this call must NEVER overwrite
                // it, matching the real PopupModel::mark_issued()'s
                // 'WHERE status = claimed' guard.
                return false;
            }

            self::$rows[$id]['status']      = self::STATUS_ISSUED;
            self::$rows[$id]['coupon_id']   = $coupon_id;
            self::$rows[$id]['coupon_code'] = $coupon_code;

            return true;
        }

        public static function claim_pending_token($token_hash) {
            self::$claim_pending_calls[] = $token_hash;

            $result = is_array(self::$claim_pending_result) ? array_shift(self::$claim_pending_result) : self::$claim_pending_result;

            if ($result && isset(self::$by_token[$token_hash])) {
                $id = self::$by_token[$token_hash];
                if (self::STATUS_PENDING === self::$rows[$id]['status']) {
                    self::$rows[$id]['status'] = self::STATUS_CLAIMED;
                } else {
                    $result = false;
                }
            } else {
                $result = false;
            }

            return (bool)$result;
        }
    }

    /**
     * In-memory stand-in for SettingsModel -- only get_setting() is used by
     * PopupController.
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

namespace Drw\App\Controllers {

    /**
     * In-memory stand-in for PopupCouponBridge::mint_coupon() -- fully
     * controllable per test case via a result queue.
     */
    class PopupCouponBridge {
        public static $calls = array();
        /** @var array Queue of return values; each shifted off on a call. Falls back to an auto-incrementing default success. */
        public static $queue = array();
        public static $auto_next = 1;

        public static function reset() {
            self::$calls     = array();
            self::$queue     = array();
            self::$auto_next = 1;
        }

        public static function mint_coupon($submission_id, array $template) {
            self::$calls[] = array('submission_id' => $submission_id, 'template' => $template);

            if (!empty(self::$queue)) {
                $result = array_shift(self::$queue);
                if (is_array($result) && isset($result['coupon_id'])) {
                    // Same ownership-meta stamp the REAL mint_coupon() applies
                    // (_drw_popup_submission_id) -- see the WC_Coupon stub
                    // above / PopupController::void_orphan_coupon().
                    $GLOBALS['__drw_test_coupon_meta'][(int)$result['coupon_id']]['_drw_popup_submission_id'] = $submission_id;
                }
                return $result;
            }

            $code      = 'CODE' . self::$auto_next;
            $coupon_id = 900 + self::$auto_next;
            self::$auto_next++;

            $GLOBALS['__drw_test_coupon_meta'][$coupon_id]['_drw_popup_submission_id'] = $submission_id;

            return array('coupon_id' => $coupon_id, 'code' => $code);
        }
    }

    /**
     * Controllable clock for the signed dwell-time token, overriding the
     * REAL global time()/current_time() only within this namespace (PHP
     * resolves an unqualified call by checking the calling function's own
     * namespace first) -- see the file docblock.
     */
    $GLOBALS['__drw_test_time'] = 2_000_000;
    function time() {
        return $GLOBALS['__drw_test_time'];
    }
    function current_time($type, $gmt = 0) {
        return ('timestamp' === $type) ? $GLOBALS['__drw_test_time'] : gmdate('Y-m-d H:i:s', $GLOBALS['__drw_test_time']);
    }

    /** Records calls without ever actually sleeping -- see resolve_confirm_redirect_url()'s race-poll loop. */
    $GLOBALS['__drw_usleep_calls'] = 0;
    function usleep($microseconds) {
        $GLOBALS['__drw_usleep_calls']++;
    }
}

namespace {

    define('ABSPATH', dirname(__DIR__) . '/');
    define('DAY_IN_SECONDS', 86400);
    define('HOUR_IN_SECONDS', 3600);

    function assert_same($expected, $actual, $message) {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assert_true($condition, $message) {
        assert_same(true, (bool)$condition, $message);
    }

    // --- Minimal WP / WC shims --------------------------------------------
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

    // --- Minimal WC_Coupon -- used only by PopupController::void_orphan_coupon()
    // (round-3 audit fix, mark_issued() CAS-loss cleanup), matching the SAME
    // "load object, check '_drw_popup_submission_id' ownership meta, then
    // ->delete(true)" pattern already established by
    // PromoBridgeController::decompile_coupon(). Meta is seeded by the
    // PopupCouponBridge stub's mint_coupon() below (via
    // $GLOBALS['__drw_test_coupon_meta']), exactly mirroring how the REAL
    // PopupCouponBridge::mint_coupon() stamps that meta at mint time.
    $GLOBALS['__drw_test_coupon_meta'] = array();
    $GLOBALS['__drw_deleted_posts']    = array();
    class WC_Coupon {
        private $id;
        public function __construct($id = 0) {
            $this->id = (int)$id;
        }
        public function get_id() {
            return $this->id;
        }
        public function get_meta($key) {
            return isset($GLOBALS['__drw_test_coupon_meta'][$this->id][$key])
                ? $GLOBALS['__drw_test_coupon_meta'][$this->id][$key]
                : '';
        }
        public function delete($force = false) {
            $GLOBALS['__drw_deleted_posts'][] = array('id' => $this->id, 'force' => $force);
            return true;
        }
    }

    /** Minimal WP_Error, same shape other tests in this suite already rely on, plus get_error_message(). */
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

    // --- Transient store backing the REAL RateLimiter.php (same approach as
    // tests/test-cartcontroller-promo-reservation.php / tests/test-rate-limiter.php).
    $GLOBALS['__drw_test_transients'] = array();
    function get_transient($key) {
        return isset($GLOBALS['__drw_test_transients'][$key]) ? $GLOBALS['__drw_test_transients'][$key] : false;
    }
    function set_transient($key, $value, $expiration) {
        $GLOBALS['__drw_test_transients'][$key] = $value;
        return true;
    }

    // --- check_ajax_referer(): records calls, always "passes" (see file
    // docblock's KNOWN SCOPE LIMITATION for why rejection itself isn't tested here).
    $GLOBALS['__drw_nonce_calls'] = array();
    function check_ajax_referer($action, $query_arg = false, $die = true) {
        $GLOBALS['__drw_nonce_calls'][] = array('action' => $action, 'query_arg' => $query_arg);
        return true;
    }

    // --- URL helpers used by landing_url()/send_confirmation_email().
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

    // --- WC()->mailer() stub (this feature's first-ever transactional email).
    class DrwTestMailer {
        public static $sent = array();
        public function wrap_message($heading, $body) {
            return '[[' . $heading . ']]' . $body;
        }
        public function send($to, $subject, $message, $headers = '', $attachments = array()) {
            self::$sent[] = array('to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers);
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

    require_once dirname(__DIR__) . '/src/Controllers/RateLimiter.php';
    require_once dirname(__DIR__) . '/src/Controllers/PopupController.php';

    use Drw\App\Controllers\PopupController;
    use Drw\App\Controllers\PopupCouponBridge;
    use Drw\App\Models\PopupModel;
    use Drw\App\Models\SettingsModel;

    function reset_world() {
        PopupModel::reset();
        PopupCouponBridge::reset();
        SettingsModel::reset();
        SettingsModel::$values['popup.daily_mint_cap']      = 200;
        SettingsModel::$values['popup.require_confirmation'] = false;
        SettingsModel::$values['popup'] = array(
            'discount_type'        => 'percent',
            'discount_value'       => 10.0,
            'expiry_days'          => 7,
            'min_cart_amount'      => 0.0,
            'daily_mint_cap'       => 200,
            'require_confirmation' => false,
            'email_subject'        => '',
            'email_heading'        => '',
            'email_intro'          => '',
        );
        $GLOBALS['__drw_test_transients']   = array();
        $GLOBALS['__drw_nonce_calls']       = array();
        $GLOBALS['__drw_usleep_calls']      = 0;
        $GLOBALS['__drw_deleted_posts']     = array();
        $GLOBALS['__drw_test_coupon_meta']  = array();
        DrwTestMailer::$sent                = array();
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /** Builds a valid, correctly-aged {rendered_at, render_signature} pair using the CURRENT test clock, then advances the clock past MIN_DWELL_SECONDS. */
    function fresh_render_token_post() {
        $token = PopupController::issue_render_token();
        $GLOBALS['__drw_test_time'] += 5; // advance clock: dwell time now satisfied.
        return array('rendered_at' => $token['rendered_at'], 'render_signature' => $token['signature']);
    }

    function base_post($email, $overrides = array()) {
        $render = fresh_render_token_post();
        return array_merge(array(
            'email'            => $email,
            'nonce'            => 'irrelevant-in-this-stub',
            'rendered_at'      => $render['rendered_at'],
            'render_signature' => $render['render_signature'],
            'source_url'       => 'https://shop.test/',
        ), $overrides);
    }

    // === (a) Honeypot filled -> uniform success, nothing else runs ==========
    reset_world();
    $result_a = PopupController::process_submit(base_post('nueva@example.com', array(
        \Drw\App\Controllers\PopupController::HONEYPOT_FIELD => 'I am a bot',
    )));
    assert_true($result_a['success'], '(a) Honeypot must still report success=true.');
    assert_same(200, $result_a['status'], '(a) Honeypot must still report HTTP 200.');
    assert_same('instant', $result_a['data']['mode'], '(a) Honeypot response mode reflects the current configured mode.');
    assert_same(null, $result_a['data']['code'], '(a) Honeypot response must never include a real code.');
    assert_same(array(), PopupModel::$insert_calls, '(a) Honeypot must never touch the database.');
    assert_same(array(), PopupCouponBridge::$calls, '(a) Honeypot must never mint a coupon.');
    assert_same(array(), $GLOBALS['__drw_nonce_calls'], '(a) Honeypot must short-circuit BEFORE the nonce check.');

    // === (b) Dwell-time too fast (valid signature, 0s elapsed) ===============
    reset_world();
    $token_b = \Drw\App\Controllers\PopupController::issue_render_token();
    // Deliberately do NOT advance the clock -- 0 seconds elapsed.
    $result_b = PopupController::process_submit(array(
        'email'            => 'rapido@example.com',
        'rendered_at'      => $token_b['rendered_at'],
        'render_signature' => $token_b['signature'],
    ));
    assert_true($result_b['success'], '(b) A too-fast submission must still report a uniform success.');
    assert_same(array(), PopupModel::$insert_calls, '(b) A too-fast submission must never touch the database.');
    assert_same(array(), $GLOBALS['__drw_nonce_calls'], '(b) A too-fast submission must short-circuit BEFORE the nonce check.');

    // === (c) Tampered signature ================================================
    reset_world();
    $token_c = \Drw\App\Controllers\PopupController::issue_render_token();
    $GLOBALS['__drw_test_time'] += 5;
    $result_c = PopupController::process_submit(array(
        'email'            => 'falsificado@example.com',
        'rendered_at'      => $token_c['rendered_at'],
        'render_signature' => $token_c['signature'] . 'tampered',
    ));
    assert_true($result_c['success'], '(c) A tampered signature must still report a uniform success.');
    assert_same(array(), PopupModel::$insert_calls, '(c) A tampered signature must never touch the database.');

    // === (c2)/(c3) ROUND-8 AUDIT FIX regression guard: MAX_DWELL_SECONDS was
    // raised from 2h to 20h because a full-page-cache visitor can easily
    // submit against a render token older than 2h (the token is baked into
    // cacheable HTML at render time -- see ShortcodeController::enqueue_popup_assets()
    // and MAX_DWELL_SECONDS's own docblock) -- confirm a token in the old
    // 2h-20h "now accepted" window reaches the database as a REAL claim
    // (not the silent-bot branch), and a token past the new ceiling still
    // gets the silent-bot treatment (the upper bound must still mean
    // something). ===============================================================
    reset_world();
    $token_c2 = \Drw\App\Controllers\PopupController::issue_render_token();
    $GLOBALS['__drw_test_time'] += 5 * 3600; // 5h elapsed: past the OLD 2h ceiling, well inside the new 20h one.
    $result_c2 = PopupController::process_submit(array(
        'email'            => 'stale-cached-page@example.com',
        'rendered_at'      => $token_c2['rendered_at'],
        'render_signature' => $token_c2['signature'],
        'nonce'            => 'irrelevant-in-this-stub',
        'source_url'       => 'https://shop.test/',
    ));
    assert_true($result_c2['success'], '(c2) A 5h-old render token (would have failed under the old 2h ceiling) must still be honored.');
    assert_same(1, count(PopupModel::$insert_calls), '(c2) A 5h-old-but-still-valid render token must reach insert_claim() as a REAL claim, not the silent-bot branch.');
    assert_true(null !== $result_c2['data']['code'], '(c2) A 5h-old render token must mint and return a real code, exactly like a fresh one.');

    reset_world();
    $token_c3 = \Drw\App\Controllers\PopupController::issue_render_token();
    $GLOBALS['__drw_test_time'] += 21 * 3600; // 21h elapsed: past the NEW 20h ceiling.
    $result_c3 = PopupController::process_submit(array(
        'email'            => 'ancient-cached-page@example.com',
        'rendered_at'      => $token_c3['rendered_at'],
        'render_signature' => $token_c3['signature'],
        'nonce'            => 'irrelevant-in-this-stub',
        'source_url'       => 'https://shop.test/',
    ));
    assert_true($result_c3['success'], '(c3) A 21h-old render token must still report a uniform success (never expose the difference).');
    assert_same(array(), PopupModel::$insert_calls, '(c3) A 21h-old render token must still be rejected by verify_render_token() (upper bound is not unbounded).');

    // === (d) check_ajax_referer() called correctly once honeypot+dwell pass ===
    reset_world();
    PopupController::process_submit(base_post('nonce-check@example.com'));
    assert_same(1, count($GLOBALS['__drw_nonce_calls']), '(d) check_ajax_referer() must be called exactly once on the happy path.');
    assert_same('drw_popup_submit', $GLOBALS['__drw_nonce_calls'][0]['action'], '(d) check_ajax_referer() must use the documented action name.');
    assert_same('nonce', $GLOBALS['__drw_nonce_calls'][0]['query_arg'], "(d) check_ajax_referer() must read the 'nonce' POST field.");

    // === (e) IP rate-limit bucket exhausted ====================================
    reset_world();
    $ip_bucket_key = 'drw_rl_' . md5('drw-popup-submit-ip:' . md5('unknown'));
    $GLOBALS['__drw_test_transients'][$ip_bucket_key] = \Drw\App\Controllers\PopupController::IP_RATE_MAX;
    $result_e = PopupController::process_submit(base_post('ip-limited@example.com'));
    assert_true(!$result_e['success'], '(e) An exhausted IP bucket must fail the request.');
    assert_same(429, $result_e['status'], '(e) An exhausted IP bucket must respond 429.');
    assert_same('rate_limited', $result_e['data']['code'], '(e) An exhausted IP bucket must report the rate_limited code.');
    assert_same(array(), PopupModel::$insert_calls, '(e) An exhausted IP bucket must never reach insert_claim().');

    // === (f) Email rate-limit bucket exhausted =================================
    reset_world();
    $target_email    = 'email-limited@example.com';
    $email_bucket_key = 'drw_rl_' . md5('drw-popup-submit-email:' . md5(strtolower($target_email)));
    $GLOBALS['__drw_test_transients'][$email_bucket_key] = \Drw\App\Controllers\PopupController::EMAIL_RATE_MAX;
    $result_f = PopupController::process_submit(base_post($target_email));
    assert_true(!$result_f['success'], '(f) An exhausted email bucket must fail the request.');
    assert_same(429, $result_f['status'], '(f) An exhausted email bucket must respond 429.');
    assert_same('rate_limited', $result_f['data']['code'], '(f) An exhausted email bucket must report the rate_limited code.');
    assert_same(array(), PopupModel::$insert_calls, '(f) An exhausted email bucket must never reach insert_claim().');

    // === (g) Global daily-cap bucket exhausted (round-5 audit fix: now
    // PopupModel::try_reserve_daily_mint_slot(), not a RateLimiter transient
    // bucket -- see that method's docblock for why. Round-6 audit fix: the
    // reservation itself moved to immediately before the real mint --
    // PopupController::reserve_daily_mint_slot() -- so by the time this
    // trips, insert_claim() has ALREADY run for a genuinely new, valid,
    // non-duplicate email; only the mint itself is blocked, never the
    // claim.) ====================================================
    reset_world();
    PopupModel::$daily_mint_result = false;
    $result_g = PopupController::process_submit(base_post('global-cap@example.com'));
    assert_true(!$result_g['success'], '(g) An exhausted global daily bucket must fail the request.');
    assert_same(429, $result_g['status'], '(g) An exhausted global daily bucket must respond 429.');
    assert_same(1, count(PopupModel::$insert_calls), '(g) round-6 audit fix: insert_claim() DOES run before the daily-cap check now -- the cap gates the MINT, not the claim.');
    assert_same(array(), PopupCouponBridge::$calls, '(g) An exhausted global daily bucket must never reach mint_coupon().');
    assert_same(1, count(PopupModel::$daily_mint_calls), '(g) try_reserve_daily_mint_slot() must be called exactly once.');

    // === (g2) Global daily-cap short-circuit: an already-IP-exhausted
    // request must NEVER burn a slot out of the global daily cap (round-5
    // audit fix regression guard -- see process_submit()'s step-4 docblock
    // on why IP -> email -> global is evaluated in that exact order with ||) ===
    reset_world();
    $ip_bucket_key_g2 = 'drw_rl_' . md5('drw-popup-submit-ip:' . md5('unknown'));
    $GLOBALS['__drw_test_transients'][$ip_bucket_key_g2] = \Drw\App\Controllers\PopupController::IP_RATE_MAX;
    PopupController::process_submit(base_post('ip-blocked-before-global@example.com'));
    assert_same(array(), PopupModel::$daily_mint_calls, '(g2) An exhausted IP bucket must short-circuit BEFORE ever calling try_reserve_daily_mint_slot().');

    // === (h) Invalid email format ================================================
    reset_world();
    $result_h = PopupController::process_submit(base_post('not-an-email'));
    assert_true(!$result_h['success'], '(h) An invalid email must fail the request.');
    assert_same(400, $result_h['status'], '(h) An invalid email must respond 400.');
    assert_same('invalid_email', $result_h['data']['code'], '(h) An invalid email must report the invalid_email code.');
    assert_same('email', $result_h['data']['field'], '(h) An invalid email error must name the offending field.');
    assert_same(array(), PopupModel::$insert_calls, '(h) An invalid email must never reach insert_claim().');
    assert_same(array(), PopupModel::$daily_mint_calls, '(h) round-6 audit fix regression guard: an invalid email must NEVER reach try_reserve_daily_mint_slot() -- it used to be reserved BEFORE email validation, letting a burst of garbage emails from one IP exhaust the whole day\'s cap.');

    // === (i) Fresh instant-mode success =========================================
    reset_world();
    $result_i = PopupController::process_submit(base_post('fresh-instant@example.com'));
    assert_true($result_i['success'], '(i) A fresh instant submission must succeed.');
    assert_same(200, $result_i['status'], '(i) A fresh instant submission must respond 200.');
    assert_same('instant', $result_i['data']['mode'], '(i) A fresh instant submission must report mode=instant.');
    assert_same('CODE1', $result_i['data']['code'], '(i) A fresh instant submission must return the minted code.');
    assert_same(1, count(PopupModel::$insert_calls), '(i) insert_claim() must be called exactly once.');
    assert_same('fresh-instant@example.com', PopupModel::$insert_calls[0]['email'], '(i) insert_claim() must receive the normalized email.');
    assert_same('instant', PopupModel::$insert_calls[0]['reveal_mode'], '(i) insert_claim() must receive reveal_mode=instant per the (default) settings.');
    assert_same(64, strlen(PopupModel::$insert_calls[0]['ip_hash']), '(i) ip_hash must be a sha256 hex digest (64 chars), never the raw IP.');
    assert_same(1, count(PopupCouponBridge::$calls), '(i) mint_coupon() must be called exactly once.');
    assert_same(1, count(PopupModel::$mark_issued_calls), '(i) mark_issued() must be called exactly once.');
    assert_same('CODE1', PopupModel::$mark_issued_calls[0]['coupon_code'], '(i) mark_issued() must be stamped with the minted code.');
    assert_same(1, count(PopupModel::$daily_mint_calls), '(i) round-6 audit fix: a fresh INSTANT-mode mint must consume exactly one daily-cap slot.');

    // === (j) Fresh confirmed-mode success ========================================
    reset_world();
    SettingsModel::$values['popup']['require_confirmation'] = true;
    $result_j = PopupController::process_submit(base_post('fresh-confirm@example.com'));
    assert_true($result_j['success'], '(j) A fresh confirmed submission must succeed.');
    assert_same('confirmed', $result_j['data']['mode'], '(j) A fresh confirmed submission must report mode=confirm.');
    assert_same(null, $result_j['data']['code'], '(j) A confirmed-mode response must NEVER include a code synchronously.');
    assert_same(array(), PopupCouponBridge::$calls, '(j) Confirmed mode must NOT mint a coupon at submit time.');
    assert_same(1, count(PopupModel::$mark_pending_calls), '(j) mark_pending() must be called exactly once.');
    assert_same(1, count(DrwTestMailer::$sent), '(j) A confirmation email must be sent exactly once.');
    assert_same('fresh-confirm@example.com', DrwTestMailer::$sent[0]['to'], '(j) The confirmation email must go to the submitted address.');
    assert_true(false !== strpos(DrwTestMailer::$sent[0]['message'], 'action=drw_popup_confirm'), '(j) The confirmation email must contain the confirm link.');
    assert_same(array(), PopupModel::$daily_mint_calls, '(j) round-6 audit fix: CONFIRMED mode must NOT reserve a daily-cap slot at submit time -- no coupon is minted here yet, only later at drw_popup_confirm; reserving now would burn a slot on a confirmation that may never be clicked.');

    // === (k) Duplicate email, ALREADY issued (instant mode) =====================
    reset_world();
    $dup_email = 'ya-emitido@example.com';
    PopupController::process_submit(base_post($dup_email)); // first, real claim -> issued with CODE1.
    $before_mint_calls       = count(PopupCouponBridge::$calls);
    $before_daily_mint_calls = count(PopupModel::$daily_mint_calls);
    $result_k = PopupController::process_submit(base_post($dup_email));
    assert_true($result_k['success'], '(k) A duplicate already-issued email must still report success (uniform response).');
    assert_same(null, $result_k['data']['code'], '(k) A duplicate already-issued email must NEVER disclose the real coupon code to an anonymous re-submission (round-2 security fix).');
    assert_same($before_mint_calls, count(PopupCouponBridge::$calls), '(k) A duplicate already-issued email must NEVER mint a second coupon.');
    assert_same($before_daily_mint_calls, count(PopupModel::$daily_mint_calls), '(k) round-6 audit fix regression guard: a duplicate submission must NEVER consume a second daily-cap slot -- it never reaches the mint call at all.');

    // === (l) Duplicate email, still pending/claimed (instant mode edge case) ====
    reset_world();
    $mid_flight_email = 'a-medias@example.com';
    // Simulate a row stuck mid-flight: inserted but never reached 'issued'.
    PopupModel::$rows[1] = array('id' => 1, 'email' => $mid_flight_email, 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'instant', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email[$mid_flight_email] = 1;
    PopupModel::$next_id = 2;
    $result_l = PopupController::process_submit(base_post($mid_flight_email));
    assert_true($result_l['success'], '(l) A mid-flight duplicate must still report success (uniform response).');
    assert_same(null, $result_l['data']['code'], '(l) A mid-flight duplicate has genuinely no code to reveal yet.');
    assert_same(array(), PopupCouponBridge::$calls, '(l) A mid-flight duplicate must never trigger a mint.');

    // === (m) Duplicate email under CONFIRMED mode never resends =================
    reset_world();
    SettingsModel::$values['popup']['require_confirmation'] = true;
    $confirm_dup_email = 'doble-confirmado@example.com';
    PopupController::process_submit(base_post($confirm_dup_email)); // sends the first (only) email.
    assert_same(1, count(DrwTestMailer::$sent), 'Sanity: exactly one email sent by the first submission.');
    $result_m = PopupController::process_submit(base_post($confirm_dup_email));
    assert_true($result_m['success'], '(m) A duplicate confirmed-mode submission must still report success.');
    assert_same('confirmed', $result_m['data']['mode'], '(m) A duplicate confirmed-mode submission must still report mode=confirm.');
    assert_same(1, count(DrwTestMailer::$sent), '(m) A duplicate confirmed-mode submission must NEVER resend the confirmation email.');

    // === (n) Abandoned-claim reclaim path =========================================
    reset_world();
    $reclaim_email = 'abandonado@example.com';
    PopupModel::$rows[1] = array('id' => 1, 'email' => $reclaim_email, 'status' => PopupModel::STATUS_CLAIMED, 'reveal_mode' => 'instant', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email[$reclaim_email] = 1;
    PopupModel::$next_id = 2;
    PopupModel::$reclaim_result = true; // this attempt wins the CAS reclaim.
    $result_n = PopupController::process_submit(base_post($reclaim_email));
    assert_true($result_n['success'], '(n) A successful reclaim must proceed exactly like a fresh claim.');
    assert_same('CODE1', $result_n['data']['code'], '(n) A successful reclaim must mint and return a real code.');
    assert_same(1, count(PopupCouponBridge::$calls), '(n) A successful reclaim must call mint_coupon() exactly once.');
    assert_same(1, count(PopupModel::$reclaim_calls), '(n) reclaim_abandoned_claim() must be attempted exactly once.');
    assert_true('' !== PopupModel::$reclaim_calls[0]['ip_hash'], '(n) round-4 fix: reclaim_abandoned_claim() must receive THIS request\'s ip_hash, not be left blank.');

    // === (o) mint_coupon() returns a WP_Error =====================================
    reset_world();
    PopupCouponBridge::$queue[] = new \WP_Error('drw_popup_code_exhausted', 'No se pudo generar un código de cupón único.');
    $result_o = PopupController::process_submit(base_post('mint-fallido@example.com'));
    assert_true(!$result_o['success'], '(o) A mint failure must NOT report success.');
    assert_same(500, $result_o['status'], '(o) A mint failure must respond 500.');
    assert_same('drw_popup_code_exhausted', $result_o['data']['code'], '(o) A mint failure must surface the WP_Error\'s own code.');
    assert_same(array(), PopupModel::$mark_issued_calls, '(o) A mint failure must never call mark_issued().');
    assert_same(1, count(PopupModel::$daily_mint_calls), '(o) sanity: a slot must have been reserved before the failed mint attempt.');
    assert_same(1, PopupModel::$release_daily_mint_calls, '(o) ROUND-9 AUDIT FIX: a mint_coupon() WP_Error must release the daily-cap slot reserved just before it, never permanently burn it on a coupon that was never minted.');

    // === (p) Confirm-token idempotency: exactly ONE mint across 3 resolves ======
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'confirmando@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['confirmando@example.com'] = 1;
    PopupModel::$next_id = 2;
    $raw_token   = 'deadbeef00112233';
    $token_hash  = hash('sha256', $raw_token);
    PopupModel::mark_pending(1, $token_hash, '2099-01-01 00:00:00');
    // Reset call log (mark_pending() above is setup, not part of what's asserted).
    PopupModel::$mark_pending_calls = array();

    PopupModel::$claim_pending_result = array(true, false, false); // only the FIRST resolve() wins the CAS.

    $url_p1 = PopupController::resolve_confirm_redirect_url($raw_token);
    $url_p2 = PopupController::resolve_confirm_redirect_url($raw_token);
    $url_p3 = PopupController::resolve_confirm_redirect_url($raw_token);

    assert_same(1, count(PopupCouponBridge::$calls), '(p) mint_coupon() must be called exactly ONCE across three resolves of the same token.');
    assert_true(false !== strpos($url_p1, 'code=CODE1'), '(p) The first (winning) resolve must land with the minted code.');
    assert_same($url_p1, $url_p2, '(p) The second (losing) resolve must land on the EXACT SAME URL as the winner.');
    assert_same($url_p1, $url_p3, '(p) The third (losing) resolve must also land on the EXACT SAME URL as the winner.');

    // === (q) Confirm with an empty/unknown token ==================================
    reset_world();
    $url_q_empty   = PopupController::resolve_confirm_redirect_url('');
    $url_q_unknown = PopupController::resolve_confirm_redirect_url('this-token-does-not-exist');
    assert_true(false !== strpos($url_q_empty, 'drw_popup_confirmed=0'), '(q) An empty token must land on the failure URL.');
    assert_true(false !== strpos($url_q_unknown, 'drw_popup_confirmed=0'), '(q) An unknown token must land on the failure URL.');
    assert_same(array(), PopupCouponBridge::$calls, '(q) A bad token must never reach mint_coupon().');

    // === (r) Confirm CAS-lost + row NEVER reaches 'issued' -> the race-poll
    // loop must run its full course (CONFIRM_RACE_POLL_ATTEMPTS real usleep()
    // calls, none of them an actual delay thanks to the namespaced stub) and
    // then give up gracefully -- never mint a second time itself, never loop
    // forever.
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'carrera@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['carrera@example.com'] = 1;
    $raw_token_r  = 'racetoken123456';
    $token_hash_r = hash('sha256', $raw_token_r);
    PopupModel::mark_pending(1, $token_hash_r, '2099-01-01 00:00:00');
    // This resolve LOSES the CAS (another concurrent request already won it,
    // flipping status to 'claimed') and that other request never finishes
    // (crashed, or is simply still running) -- the row never reaches 'issued'.
    PopupModel::$claim_pending_result = false;
    PopupModel::$rows[1]['status'] = PopupModel::STATUS_CLAIMED;

    $usleep_before = $GLOBALS['__drw_usleep_calls'];
    $url_r = PopupController::resolve_confirm_redirect_url($raw_token_r);
    assert_true(false !== strpos($url_r, 'drw_popup_confirmed=0'), '(r) A CAS-lost resolve whose row never reaches issued must land on the failure URL, not hang forever.');
    assert_same(
        \Drw\App\Controllers\PopupController::CONFIRM_RACE_POLL_ATTEMPTS,
        $GLOBALS['__drw_usleep_calls'] - $usleep_before,
        '(r) The race-poll loop must run exactly CONFIRM_RACE_POLL_ATTEMPTS times before giving up.'
    );
    assert_same(array(), PopupCouponBridge::$calls, '(r) A CAS-lost resolve must NEVER call mint_coupon() itself.');

    // === (s) Instant-mode mark_issued() CAS loss (round-3 audit fix): this
    // request's OWN mint_coupon() call already created a real coupon, but
    // mark_issued() reports it lost the CAS (a concurrent request won
    // first -- exhaustively proven at the real SQL/CAS layer by
    // test-popup-model.php cases (h2)/(h3); this test proves
    // PopupController's HANDLING of that failure, not the CAS mechanism
    // itself). Must: (1) void THIS request's own just-minted, now-orphaned
    // coupon via wp_delete_post(), (2) NEVER surface that orphaned code to
    // the visitor, (3) still report a uniform success -- never leak the
    // internal race as a user-facing error. ==================================
    reset_world();
    PopupModel::$mark_issued_result = false; // every mark_issued() call in this scenario loses the CAS.

    $result_s = PopupController::process_submit(base_post('cas-loser@example.com'));
    assert_true($result_s['success'], '(s) A CAS-loss response must still report uniform success, never surface an internal race as an error.');
    assert_same(null, $result_s['data']['code'], '(s) A CAS-loss response must never return the LOSING request\'s own orphaned code.');
    assert_same(1, count(PopupModel::$mark_issued_calls), '(s) sanity: mark_issued() was actually attempted (and reported failure).');
    assert_same(1, count($GLOBALS['__drw_deleted_posts']), '(s) A lost mark_issued() CAS must void exactly the losing request\'s own just-minted coupon.');
    assert_same(
        PopupModel::$mark_issued_calls[0]['coupon_id'],
        $GLOBALS['__drw_deleted_posts'][0]['id'],
        '(s) void_orphan_coupon() must delete THIS call\'s own just-minted coupon id, not some other value.'
    );
    assert_true(true === $GLOBALS['__drw_deleted_posts'][0]['force'], '(s) void_orphan_coupon() must force-delete (never trash) the orphaned coupon.');
    assert_same(1, PopupModel::$release_daily_mint_calls, '(s) ROUND-9 AUDIT FIX: a lost mark_issued() CAS must ALSO release this request\'s own daily-cap slot -- the winner already keeps its own slot for the coupon that actually got issued, so this one must not double-charge the day\'s cap for a coupon that was voided, never delivered.');

    // === (t) Confirm-mode mark_issued() CAS loss via resolve_confirm_redirect_url():
    // same handling as (s), on the confirm-token path -- must void its own
    // just-minted coupon and fail closed (never fabricate a code) when no
    // winner's real code can be resolved. ====================================
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'confirm-cas-loser@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['confirm-cas-loser@example.com'] = 1;
    $raw_token_t  = 'confirmcaslosstoken';
    $token_hash_t = hash('sha256', $raw_token_t);
    PopupModel::mark_pending(1, $token_hash_t, '2099-01-01 00:00:00');
    PopupModel::$claim_pending_result = true; // wins the claim_pending_token() CAS...
    PopupModel::$mark_issued_result   = false; // ...but loses the mark_issued() CAS.

    $url_t = PopupController::resolve_confirm_redirect_url($raw_token_t);
    assert_same(1, count($GLOBALS['__drw_deleted_posts']), '(t) A lost mark_issued() CAS on the confirm path must void the losing request\'s own just-minted coupon.');
    // No concurrent winner's row was ever actually populated with a real
    // code in this scenario (row status stays 'claimed', not 'issued'),
    // matching the documented "should not happen in practice" fail-closed
    // branch -- must land on the failure URL, never fabricate a code.
    assert_true(false !== strpos($url_t, 'drw_popup_confirmed=0'), '(t) A lost mark_issued() CAS with no resolvable winner code must fail closed, never fabricate a code.');
    assert_same(1, PopupModel::$release_daily_mint_calls, '(t) ROUND-9 AUDIT FIX: a lost mark_issued() CAS on the confirm path must ALSO release this request\'s own daily-cap slot, same reasoning as (s).');

    // === (t1b) ROUND-9 AUDIT FIX: mint_coupon() returning a WP_Error on the
    // CONFIRM path (symmetric to (o) on the submit path) must release the
    // slot reserve_daily_mint_slot() just reserved, and fail closed. =========
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'confirm-mint-fallido@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['confirm-mint-fallido@example.com'] = 1;
    $raw_token_t1b  = 'confirmmintfallidotoken';
    $token_hash_t1b = hash('sha256', $raw_token_t1b);
    PopupModel::mark_pending(1, $token_hash_t1b, '2099-01-01 00:00:00');
    PopupModel::$claim_pending_result = true;
    PopupCouponBridge::$queue[] = new \WP_Error('drw_popup_code_exhausted', 'No se pudo generar un código de cupón único.');

    $url_t1b = PopupController::resolve_confirm_redirect_url($raw_token_t1b);
    assert_true(false !== strpos($url_t1b, 'drw_popup_confirmed=0'), '(t1b) A mint_coupon() WP_Error on the confirm path must fail closed, never fabricate a code.');
    assert_same(1, count(PopupModel::$daily_mint_calls), '(t1b) sanity: a slot must have been reserved before the failed mint attempt.');
    assert_same(1, PopupModel::$release_daily_mint_calls, '(t1b) ROUND-9 AUDIT FIX: a mint_coupon() WP_Error on the confirm path must release the daily-cap slot reserved just before it.');
    assert_same(array(), PopupModel::$mark_issued_calls, '(t1b) A mint failure must never call mark_issued().');

    // === (t2) Round-6 audit fix: CONFIRMED mode's REAL mint (at
    // drw_popup_confirm, via resolve_confirm_redirect_url() -- not at
    // process_submit() time, see (j) above) must consume exactly one
    // daily-cap slot when the cap has room. ====================================
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'confirm-daily-cap-ok@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['confirm-daily-cap-ok@example.com'] = 1;
    $raw_token_t2  = 'confirmdailycapoktoken';
    $token_hash_t2 = hash('sha256', $raw_token_t2);
    PopupModel::mark_pending(1, $token_hash_t2, '2099-01-01 00:00:00');
    PopupModel::$claim_pending_result = true;

    $url_t2 = PopupController::resolve_confirm_redirect_url($raw_token_t2);
    assert_true(false !== strpos($url_t2, 'drw_popup_confirmed=1'), '(t2) A fresh CONFIRMED-mode mint via resolve_confirm_redirect_url() must succeed when the daily cap has room.');
    assert_same(1, count(PopupModel::$daily_mint_calls), '(t2) round-6 audit fix: CONFIRMED mode\'s real mint (at confirm time, not submit time) must consume exactly one daily-cap slot.');
    assert_same(1, count(PopupCouponBridge::$calls), '(t2) sanity: exactly one mint actually happened.');

    // === (t3) Round-6 audit fix: an exhausted daily cap at confirm time must
    // fail closed even after WINNING the claim_pending_token() CAS -- never
    // mint, never fabricate a code, and never call mark_issued(). The row
    // stays 'claimed' (unminted), same fail-closed handling as any other
    // mint failure on this path (see (t) above). ================================
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'confirm-daily-cap-exhausted@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['confirm-daily-cap-exhausted@example.com'] = 1;
    $raw_token_t3  = 'confirmdailycapexhaustedtoken';
    $token_hash_t3 = hash('sha256', $raw_token_t3);
    PopupModel::mark_pending(1, $token_hash_t3, '2099-01-01 00:00:00');
    PopupModel::$claim_pending_result = true; // wins the CAS...
    PopupModel::$daily_mint_result    = false; // ...but the global daily cap is exhausted.

    $url_t3 = PopupController::resolve_confirm_redirect_url($raw_token_t3);
    assert_true(false !== strpos($url_t3, 'drw_popup_confirmed=0'), '(t3) An exhausted daily cap at confirm time must fail closed, never mint.');
    assert_same(array(), PopupCouponBridge::$calls, '(t3) An exhausted daily cap must block mint_coupon() from ever being called on the confirm path.');
    assert_same(array(), PopupModel::$mark_issued_calls, '(t3) An exhausted daily cap must never call mark_issued() -- nothing was minted.');

    // === (u) Honeypot/dwell-time silent-reject messages are internally
    // consistent (round-3 audit fix): must NEVER claim a code is "ready"
    // while code is null -- reuses the exact same "already registered"
    // message uniform_response_for_existing() uses for a genuine duplicate,
    // so the client's own buildRevealMarkup() (which picks its headline
    // purely off whether `code` is truthy) never shows a contradictory
    // headline/body pair for this response. ==================================
    reset_world();
    // round-4 audit fix: no longer tells the customer to "check their inbox"
    // -- this branch is reachable only in instant mode, which never emails.
    $already_registered_message = 'Ya tenemos un registro con este correo. El código de descuento se muestra una sola vez, en el momento del registro.';
    $result_u_honeypot = PopupController::process_submit(base_post('bot-honeypot@example.com', array(
        \Drw\App\Controllers\PopupController::HONEYPOT_FIELD => 'I am a bot',
    )));
    assert_same($already_registered_message, $result_u_honeypot['data']['message'], '(u) The honeypot silent-reject response must use the non-contradictory "already registered" message, never claim a code is ready.');

    reset_world();
    $token_u = \Drw\App\Controllers\PopupController::issue_render_token();
    // Deliberately do NOT advance the clock -- fails MIN_DWELL_SECONDS.
    $result_u_dwell = PopupController::process_submit(array(
        'email'            => 'too-fast@example.com',
        'rendered_at'      => $token_u['rendered_at'],
        'render_signature' => $token_u['signature'],
    ));
    assert_same($already_registered_message, $result_u_dwell['data']['message'], '(u) The dwell-time silent-reject response must also use the non-contradictory "already registered" message.');

    // === (v) CONFIRMED-mode timing-oracle padding (round-3 audit fix):
    // both a fresh registration and a duplicate submission in CONFIRMED mode
    // must invoke the response-time equalizer (usleep(), stubbed to a no-op
    // counter in this file) -- INSTANT mode must NEVER invoke it, since its
    // own new-vs-duplicate gap is a structural product requirement, not a
    // bug this mitigation applies to. ========================================
    reset_world();
    SettingsModel::$values['popup']['require_confirmation'] = true;
    $usleep_before_fresh_confirm = $GLOBALS['__drw_usleep_calls'];
    PopupController::process_submit(base_post('padding-fresh@example.com'));
    assert_true($GLOBALS['__drw_usleep_calls'] > $usleep_before_fresh_confirm, '(v) A fresh CONFIRMED-mode submission must invoke the response-time padding.');

    $usleep_before_dup_confirm = $GLOBALS['__drw_usleep_calls'];
    PopupController::process_submit(base_post('padding-fresh@example.com')); // same email -> duplicate.
    assert_true($GLOBALS['__drw_usleep_calls'] > $usleep_before_dup_confirm, '(v) A duplicate CONFIRMED-mode submission must ALSO invoke the response-time padding.');

    reset_world();
    SettingsModel::$values['popup']['require_confirmation'] = false;
    $usleep_before_instant = $GLOBALS['__drw_usleep_calls'];
    PopupController::process_submit(base_post('padding-instant@example.com'));
    assert_same($usleep_before_instant, $GLOBALS['__drw_usleep_calls'], '(v) An INSTANT-mode submission must NEVER invoke the CONFIRMED-mode response-time padding.');

    // === (w) CRITICAL WIRING: send_code_reveal_email() (the new
    // transactional code-reveal email) must be called EXACTLY ONCE on a
    // fresh CAS-win confirm, and ZERO additional times on any repeat/
    // already-issued confirm of the SAME token (e.g. an email client's
    // link-prefetch scanner, or the customer double-clicking) -- the exact
    // same idempotency discipline resolve_confirm_redirect_url() already
    // enforces for the coupon mint itself (see (p) above), now extended to
    // this email. Reuses this file's own DrwTestMailer::$sent log --
    // send_code_reveal_email() goes through the exact same
    // WC()->mailer()->send() this file already stubs for the confirmation
    // email.
    reset_world();
    PopupModel::$rows[1] = array('id' => 1, 'email' => 'code-reveal-wiring@example.com', 'status' => PopupModel::STATUS_PENDING, 'reveal_mode' => 'confirmed', 'coupon_id' => null, 'coupon_code' => null, 'confirm_token' => null, 'token_expires_at' => null);
    PopupModel::$by_email['code-reveal-wiring@example.com'] = 1;
    PopupModel::$next_id = 2;
    $raw_token_w  = 'coderevealwiringtoken';
    $token_hash_w = hash('sha256', $raw_token_w);
    PopupModel::mark_pending(1, $token_hash_w, '2099-01-01 00:00:00');
    PopupModel::$claim_pending_result = true; // the first resolve genuinely wins the CAS.

    $mail_count_before_fresh = count(DrwTestMailer::$sent);
    $url_w1 = PopupController::resolve_confirm_redirect_url($raw_token_w);
    assert_true(false !== strpos($url_w1, 'drw_popup_confirmed=1'), '(w) sanity: the fresh CAS-win resolve must succeed.');
    assert_same($mail_count_before_fresh + 1, count(DrwTestMailer::$sent), '(w) send_code_reveal_email() must be called EXACTLY ONCE on a fresh CAS-win confirm.');
    assert_same('code-reveal-wiring@example.com', DrwTestMailer::$sent[count(DrwTestMailer::$sent) - 1]['to'], "(w) the code-reveal email must go to the row's own email address.");
    assert_true(false !== strpos(DrwTestMailer::$sent[count(DrwTestMailer::$sent) - 1]['message'], 'CODE1'), '(w) the code-reveal email body must contain the real, freshly-minted coupon code.');

    // Second (repeat/already-issued) resolve of the SAME token --
    // claim_pending_token() now loses the CAS (the row is already 'issued'),
    // so this must resolve to the SAME already-minted code WITHOUT sending a
    // second email or minting a second coupon.
    $mail_count_before_repeat = count(DrwTestMailer::$sent);
    $mint_calls_before_repeat = count(PopupCouponBridge::$calls);
    $url_w2 = PopupController::resolve_confirm_redirect_url($raw_token_w);
    assert_same($url_w1, $url_w2, '(w) sanity: the repeat resolve must land on the exact same URL as the winner.');
    assert_same($mail_count_before_repeat, count(DrwTestMailer::$sent), '(w) send_code_reveal_email() must be called ZERO additional times on a repeat/already-issued confirm of the same token -- never spam the customer with a duplicate email.');
    assert_same($mint_calls_before_repeat, count(PopupCouponBridge::$calls), '(w) sanity: the repeat resolve must never mint a second coupon either.');

    // A third repeat resolve, for good measure -- still zero additional sends.
    PopupController::resolve_confirm_redirect_url($raw_token_w);
    assert_same($mail_count_before_repeat, count(DrwTestMailer::$sent), '(w) a third repeat resolve must also send zero additional code-reveal emails.');

    echo "PopupController OK\n";
}
