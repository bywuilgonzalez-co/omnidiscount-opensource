<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PopupModel;
use Drw\App\Models\SettingsModel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public, anonymous-traffic endpoints for the email-capture popup (plan's
 * "Endpoints públicos" + "Anti-abuso" sections) — admin-ajax, same as the
 * only pre-existing anonymous endpoint (ShortcodeController::ajax_load_sale_items(),
 * a read-only precedent). This is the FIRST public endpoint that creates
 * real state (a wp_drw_popup_submissions row) and mints real WC_Coupon
 * objects from anonymous traffic, so most of this file is anti-abuse
 * plumbing, not the "happy path" itself.
 *
 * The only file in this feature with register_hooks() — PopupModel (data)
 * and PopupCouponBridge (coupon minting) are both called-internally-only,
 * exactly like PromoModel/PromoBridgeController.
 *
 * Every handler's real logic lives in a static, side-effect-testable method
 * (process_submit()/resolve_confirm_redirect_url()) that a standalone test
 * can call directly with a plain array/string and inspect the return value
 * of — the actual add_action() callbacks (submit()/confirm()) are thin
 * wrappers that read the superglobals and hand off to wp_send_json_*()/
 * wp_safe_redirect()+exit(), which is where this class's real WP-runtime
 * side effects (and process termination) live.
 */
class PopupController
{
    private static $instance = null;

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /** wp_nonce_field()/wp_create_nonce() action name for the submit form. */
    const NONCE_ACTION = 'drw_popup_submit';

    /**
     * wp_hash() payload namespace for the signed render/dwell-time token
     * (issue_render_token()/verify_render_token() below) — same technique
     * as PromoBridgeController's sandbox cookie
     * (payload string + wp_hash($payload), compared with hash_equals()),
     * just applied to "prove this exact request followed a real page render"
     * instead of "prove this cookie was issued by us for this user".
     */
    const RENDER_TOKEN_ACTION = 'drw_popup_render';

    /**
     * Minimum seconds that must elapse between the popup rendering (i.e.
     * issue_render_token() being called) and the submit request reaching
     * the server. A script POSTing straight to admin-ajax.php without ever
     * fetching this page's HTML at all fails this even with a
     * stolen/replayed valid signature, unless it also waits out the delay.
     *
     * ROUND-7 AUDIT FIX (docblock accuracy, no behavior change): the prior
     * wording here and at the two call sites below implied this "proves a
     * real page render" in the sense of a browser executing JS. It does
     * not: issue_render_token() runs server-side, in PHP, at PAGE-RENDER
     * (HTTP response) time (ShortcodeController::enqueue_popup_assets()),
     * for EVERY request that fetches the shop page's HTML — including a
     * plain `curl` with no JS engine, which gets the valid, embedded
     * drwPopupData.renderedAt/renderSignature pair for free. What this
     * mechanism actually proves is "an HTTP client fetched this page's HTML
     * and then waited out MIN_DWELL_SECONDS before POSTing" — real, useful
     * friction against generic spam-bot toolkits that POST straight to
     * admin-ajax.php without ever touching the origin page, but NOT proof
     * of JS execution or an actual popup display, and not a defense against
     * a targeted scraper that specifically fetches the page first. See the
     * plan's own "reto adaptativo tipo Turnstile" note for the documented
     * next step if a stronger guarantee is needed.
     */
    const MIN_DWELL_SECONDS = 2;

    /**
     * Maximum age, in seconds, a signed render/dwell-time token is honored
     * for. build_render_signature() is a pure, stateless HMAC of
     * $rendered_at — a value the server itself already handed the client —
     * so without an upper bound it would remain valid forever and could be
     * harvested once (a single real page load) and replayed indefinitely by
     * a script, defeating MIN_DWELL_SECONDS as a real "a human actually
     * rendered this page recently" signal.
     *
     * ROUND-8 AUDIT FIX: this used to be 2 hours, which is generous for a
     * lone real visitor but breaks on ANY site running a full-page cache
     * (WP Rocket/LiteSpeed/host-level caches, all common, several enabled by
     * default) with a cache lifetime longer than 2 hours — this token is
     * baked into the cached HTML by ShortcodeController::enqueue_popup_assets()
     * at render time (see that method's docblock), so every visitor served
     * the SAME cached page inherits the SAME, now-stale rendered_at/signature
     * pair once it ages past this constant. Past that point
     * verify_render_token() fails and process_submit() takes the SAME
     * "silent bot" branch a real bot would (self::uniform_success(...,
     * null, true)) — a brand-new, never-before-seen email is told "ya
     * tenemos un registro con este correo" and gets no code, with no
     * server-side error and no way to recover in that browser (the
     * frequency-cap localStorage flag is still set on any success:true
     * response). Raised to comfortably outlast typical full-page-cache TTLs
     * (commonly a handful of hours up to ~24h) while staying under
     * wp_create_nonce()'s own ~24h default lifetime — check_ajax_referer()
     * (step 3, right after this check) already fails open with an honest
     * generic error past that point, so there is no benefit to extending
     * this constant further; doing so would just let a harvested token be
     * replayed for longer without fixing anything, since the nonce check
     * would reject it anyway. This narrows, but (per the finding's own
     * verdict) does not fully eliminate, the caching interaction for sites
     * with unusually long cache lifetimes — the complete fix is to stop
     * baking rendered_at/render_signature into cacheable HTML at all (e.g.
     * fetch them via a lightweight nopriv AJAX call on DOMContentLoaded),
     * which is a bigger architectural change left for a future round.
     */
    const MAX_DWELL_SECONDS = 20 * HOUR_IN_SECONDS;

    /**
     * Name of the hidden honeypot field the public form must render
     * off-screen (display:none, tabindex="-1") — see drw-popup.js (a later
     * phase). A human never fills this in; a bot that blindly fills every
     * input it finds does.
     */
    const HONEYPOT_FIELD = 'drw_popup_hp';

    /**
     * Per-IP bucket: 5 submit attempts per 10 minutes. Deliberately generous
     * (shared IPs — offices, campuses, carrier-grade NAT — are common) since
     * the email bucket and the UNIQUE(email) constraint are the primary
     * defenses; this is a coarse circuit breaker against a single scripted
     * client hammering the endpoint from one address.
     */
    const IP_RATE_MAX    = 5;
    const IP_RATE_WINDOW = 600;

    /**
     * Per-(raw, pre-validation) email-string bucket: 3 attempts per hour.
     * Deliberately keyed on the RAW string, not the validated/normalized
     * address (validation happens AFTER rate limiting — step 5 follows step
     * 4 in the plan) — a garbage string still gets its own throttled bucket
     * instead of bypassing rate limiting entirely by failing validation.
     * Exhaustion timing depends only on attempt COUNT for that exact string,
     * never on whether the email is already registered, so this bucket
     * cannot be used as the email-oracle the uniform-response requirement
     * (step 9) exists to close.
     */
    const EMAIL_RATE_MAX    = 3;
    const EMAIL_RATE_WINDOW = 3600;

    /**
     * Global daily-mint circuit breaker, independent of the IP/email
     * buckets above (both of which a botnet with rotating proxies and
     * disposable domains can evade — see plan's Anti-abuso §2).
     *
     * ROUND-5 AUDIT FIX: this bucket used to be a third RateLimiter::check()
     * call, same as the IP/email buckets — but RateLimiter is explicitly
     * documented as non-atomic (plain get_transient()+set_transient()
     * read-modify-write), which is an acceptable trade-off for the IP/email
     * buckets (inherently evadable by rotating IPs/disposable domains
     * regardless of atomicity) but defeats THIS bucket's entire stated
     * purpose: a hard, site-wide emergency brake against exactly a burst of
     * many concurrent, distinct-IP/distinct-email requests, which a
     * read-modify-write counter cannot cap correctly (a burst near the
     * boundary can all read the same pre-increment count and all be
     * admitted). Now enforced via PopupModel::try_reserve_daily_mint_slot(),
     * a real atomic UPDATE ... WHERE <count> < $cap against a day-keyed
     * wp_options row — see that method's docblock for the full mechanism.
     * No transient bucket key/constant is needed here any more.
     */

    /**
     * Confirm-token idempotency race window: when this request's
     * claim_pending_token() CAS loses to a concurrent winner (e.g. an email
     * link-tracker's automated GET racing the human's real click), poll
     * briefly for that winner to finish minting rather than surface a false
     * failure. 10 * 150ms = up to 1.5s worst case, only ever exercised on
     * this narrow concurrent-GET edge, never on the ordinary path.
     */
    const CONFIRM_RACE_POLL_ATTEMPTS    = 10;
    const CONFIRM_RACE_POLL_INTERVAL_US = 150000;

    /**
     * Minimum wall-clock seconds a CONFIRMED-mode drw_popup_submit response
     * is padded up to — see equalize_confirmed_mode_response_time(). Chosen
     * to comfortably cover a real DB write + a real outbound SMTP send
     * under normal conditions without meaningfully hurting a genuine
     * registrant's perceived responsiveness.
     */
    const CONFIRMED_MODE_RESPONSE_FLOOR_SECONDS = 0.35;

    /**
     * Register the two public admin-ajax actions plus the daily stale-row
     * cleanup cron handler (scheduled by Router::run_migrations(), cleared
     * by Router::deactivate_plugin() — same split as
     * 'drw_release_stale_promo_reservations').
     */
    public function register_hooks()
    {
        add_action('wp_ajax_drw_popup_submit', array($this, 'submit'));
        add_action('wp_ajax_nopriv_drw_popup_submit', array($this, 'submit'));
        add_action('wp_ajax_drw_popup_confirm', array($this, 'confirm'));
        add_action('wp_ajax_nopriv_drw_popup_confirm', array($this, 'confirm'));
        add_action('drw_popup_release_stale_claims', array($this, 'release_stale_claims'));

        // Admin-facing surface (plan's "Panel de administración" -> "Ver
        // registros capturados"): its own submenu (same reasoning as
        // AnalyticsController::add_analytics_submenu() — a data table does
        // not fit as a Configuración tab), an admin-gated REST GET route,
        // and a plain admin_post CSV export action.
        add_action('admin_menu', array($this, 'add_submissions_submenu'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_submissions_assets'));
        add_action('admin_post_drw_export_popup_submissions', array($this, 'handle_csv_export'));
    }

    /** check_admin_referer()/wp_nonce_url() action name for the CSV export link. */
    const EXPORT_NONCE_ACTION = 'drw_export_popup_submissions';

    /** Rows per page for the admin submissions table. */
    const SUBMISSIONS_PER_PAGE = 20;

    /**
     * WP-Cron callback: see PopupModel::purge_stale_rows() for what/why.
     */
    public function release_stale_claims()
    {
        PopupModel::purge_stale_rows();
    }

    // ------------------------------------------------------------------
    // drw_popup_submit
    // ------------------------------------------------------------------

    /**
     * wp_ajax_drw_popup_submit / wp_ajax_nopriv_drw_popup_submit hook
     * callback. Thin wrapper: real logic is process_submit(), which is pure
     * enough to unit test directly.
     */
    public function submit()
    {
        $result = self::process_submit($_POST);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['data'], $result['status']);
        }
    }

    /**
     * The full drw_popup_submit request/response cycle, step-by-step per
     * the plan:
     *  1. Honeypot.
     *  2. Signed dwell-time check.
     *  3. Nonce.
     *  4. Double RateLimiter bucket (IP / email attempt-throttling).
     *  5. Email validation.
     *  6. Insert-first (UNIQUE(email) is the real concurrency guard).
     *  7. CAS re-claim of an abandoned 'claimed' row on duplicate-key.
     *  8. Global daily-mint cap (round-6 audit fix: moved here, right before
     *     the real mint, instead of step 4 — see that step's docblock) +
     *     mint (instant) or token+email (confirmed), per reveal_mode.
     *  9. Uniform response shape regardless of new/pending/issued.
     *
     * @param array $post Raw $_POST-shaped array (superglobal in production,
     *                     a plain array in tests).
     * @return array{success:bool,status:int,data:array} 'data' is exactly
     *         what gets handed to wp_send_json_success()/wp_send_json_error().
     */
    public static function process_submit(array $post)
    {
        // Captured up front, used ONLY by equalize_confirmed_mode_response_time()
        // near the two CONFIRMED-mode return points below (round-3 audit
        // fix, timing side-channel) -- see that method's docblock.
        $request_started_at = microtime(true);

        // 1. Honeypot — a filled-in hidden field means a bot that blindly
        // fills every input it finds. Respond with the SAME uniform success
        // shape a real submission would get, doing nothing, so the bot's
        // own success/failure signal never tells it its cover was blown.
        // Deliberately NOT !empty($post[...]) -- PHP's empty() treats the
        // STRING "0" as empty, so a bot that fills every field with "0"
        // would silently slip past a !empty() check.
        // Deliberately routed through the SAME "already_registered" message
        // branch uniform_response_for_existing() uses (round-3 audit fix):
        // the un-guarded uniform_success($mode) call this used to make
        // always claimed "tu código de descuento de bienvenida está listo"
        // with code:null -- an internally-contradictory response (the
        // client's own buildRevealMarkup() picks the "Ya tenemos tu
        // registro" headline whenever code is falsy, so the headline and
        // body text openly disagreed) for the ONE real-world case that can
        // legitimately reach this branch without being a bot: a genuine
        // visitor who leaves the popup open past MAX_DWELL_SECONDS before
        // submitting. That visitor's browser still permanently records
        // drw_popup_submitted=1 on any success:true response (by design --
        // the response must stay indistinguishable from a real one to a
        // bot), so the wording is the only piece of this edge case that can
        // be made honest without reopening the anti-bot design itself.
        if (isset($post[self::HONEYPOT_FIELD]) && '' !== trim((string)$post[self::HONEYPOT_FIELD])) {
            return self::uniform_success(self::current_configured_mode(), null, true);
        }

        // 2. Signed minimum-dwell-time check. A script POSTing straight to
        // this endpoint without ever fetching this page's HTML at all (so
        // issue_render_token() was never called for it server-side) cannot
        // produce a valid signature; one that replays a stolen/harvested
        // signature still has to wait out the real delay. See
        // MIN_DWELL_SECONDS's docblock (round-7 audit fix) for the precise,
        // non-overstated scope of what this actually proves.
        $rendered_at = isset($post['rendered_at']) ? absint($post['rendered_at']) : 0;
        $signature   = isset($post['render_signature']) ? sanitize_text_field((string)$post['render_signature']) : '';
        if (!self::verify_render_token($rendered_at, $signature)) {
            return self::uniform_success(self::current_configured_mode(), null, true);
        }

        // 3. Nonce — same check_ajax_referer() convention as the plugin's
        // only other public endpoint (ShortcodeController::ajax_load_sale_items()).
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // 4. Double bucket: IP and email attempt-throttling. Any one of the
        // two tripping is a generic, distinct "try later" response — unlike
        // honeypot/dwell, this is not an email-oracle risk (bucket
        // exhaustion depends only on attempt COUNT, identical for a
        // brand-new or already-registered email), so there is no reason to
        // disguise it as a fake success.
        //
        // Deliberately short-circuited with ||, evaluated IP -> email, so an
        // already-IP-blocked request never even mutates the email bucket.
        //
        // Round-6 audit fix: the global daily-mint circuit breaker
        // ('popup.daily_mint_cap', PopupModel::try_reserve_daily_mint_slot())
        // USED to be reserved right here, as a third bucket in this same ||
        // chain — i.e. BEFORE email format validation (step 5) and
        // duplicate-email detection (steps 6-7) below. That let a burst of
        // syntactically-invalid or already-registered emails from a single
        // IP (still comfortably inside its own IP_RATE_MAX/EMAIL_RATE_WINDOW
        // bucket) exhaust the ENTIRE day's cap without ever minting a single
        // real coupon — after which every legitimate first-time visitor for
        // the rest of the day got a 429. It also meant CONFIRMED mode's
        // actual mint (which happens later, at drw_popup_confirm ->
        // resolve_confirm_redirect_url(), not here) never consumed this
        // counter at all, so the setting's own merchant label ("Máximo de
        // cupones emitidos por día") was never actually true for that mode.
        // The reservation now happens ONLY immediately before each of the
        // two real PopupCouponBridge::mint_coupon() call sites — see
        // reserve_daily_mint_slot() below, called from the INSTANT-mode
        // branch of this method and from resolve_confirm_redirect_url() —
        // so the counter genuinely tracks coupons about to be minted, not
        // mere submission attempts.
        $ip               = self::get_client_ip();
        $email_raw        = isset($post['email']) ? (string)$post['email'] : '';
        $email_bucket_key = strtolower(trim($email_raw));

        $rate_limited = !RateLimiter::check('drw-popup-submit-ip:' . md5($ip), self::IP_RATE_MAX, self::IP_RATE_WINDOW)
            || !RateLimiter::check('drw-popup-submit-email:' . md5($email_bucket_key), self::EMAIL_RATE_MAX, self::EMAIL_RATE_WINDOW);

        if ($rate_limited) {
            return array(
                'success' => false,
                'status'  => 429,
                'data'    => array(
                    'message' => __('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 'discount-rules-woo'),
                    'code'    => 'rate_limited',
                ),
            );
        }

        // 5. Validate email (deliberately AFTER rate limiting -- see the
        // EMAIL_RATE_MAX docblock above for why the bucket is keyed on the
        // raw string).
        $email = sanitize_email($email_raw);
        if ('' === $email || !is_email($email) || strlen($email) > 191) {
            return array(
                'success' => false,
                'status'  => 400,
                'data'    => array(
                    'message' => __('Ingresa un correo electrónico válido.', 'discount-rules-woo'),
                    'code'    => 'invalid_email',
                    'field'   => 'email',
                ),
            );
        }

        $settings              = self::popup_settings();
        $require_confirmation  = !empty($settings['require_confirmation']);
        $reveal_mode           = $require_confirmation ? PopupModel::REVEAL_CONFIRMED : PopupModel::REVEAL_INSTANT;

        $ip_hash    = hash('sha256', $ip . wp_salt('auth'));
        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255)
            : '';
        $source_url = isset($post['source_url']) ? substr(esc_url_raw((string)$post['source_url']), 0, 255) : '';

        // 6. Insert-first — see PopupModel::insert_claim()'s docblock: the
        // UNIQUE(email) key, not this PHP-level check, is what actually
        // makes "one coupon per person" safe under concurrency.
        $submission_id = PopupModel::insert_claim($email, $reveal_mode, $ip_hash, $user_agent, $source_url);
        $is_new_claim  = false !== $submission_id;

        // 7. Duplicate email: try to atomically re-claim an abandoned
        // 'claimed' row (a previous request that crashed before reaching a
        // terminal state). Never a second row, never a plain re-check.
        if (!$is_new_claim) {
            if (PopupModel::reclaim_abandoned_claim($email, $reveal_mode, $ip_hash, $user_agent, $source_url)) {
                $row = PopupModel::get_by_email($email);
                if ($row) {
                    $submission_id = $row['id'];
                    $is_new_claim  = true;
                }
            }
        }

        if (!$is_new_claim) {
            // Genuinely still pending/issued from an earlier submission (or
            // this request lost a reclaim race to a concurrent duplicate).
            // Per Anti-abuso §5: never mint/email again here, uniform
            // response only. No need to fetch the existing row at all any
            // more -- see uniform_response_for_existing()'s docblock: every
            // instant-mode duplicate gets the exact same code:null response
            // regardless of that row's actual status, since round-2's
            // security fix.
            //
            // CONFIRMED mode only (round-3 audit fix, timing side-channel):
            // a fresh registration below does real work here (mark_pending()
            // + a real outbound WC()->mailer()->send() call) that this
            // duplicate branch skips entirely -- padding this branch up to
            // the same floor closes the resulting latency gap an attacker
            // could otherwise use to probe whether a candidate address is
            // already registered, purely by timing the response. See
            // equalize_confirmed_mode_response_time()'s own docblock for
            // this mitigation's real limits.
            if (PopupModel::REVEAL_CONFIRMED === $reveal_mode) {
                self::equalize_confirmed_mode_response_time($request_started_at);
            }

            return self::uniform_response_for_existing($reveal_mode);
        }

        // 8. Mint (instant) or issue+email a confirmation token (confirmed).
        if (PopupModel::REVEAL_INSTANT === $reveal_mode) {
            // Global daily-mint cap, reserved HERE (round-6 audit fix) —
            // immediately before the real mint, not back in step 4 — so only
            // a submission that has already passed email validation and
            // duplicate detection, and is genuinely about to produce a real
            // WC_Coupon, can consume a slot. See reserve_daily_mint_slot()'s
            // docblock and step 4's comment above for the full rationale.
            // The row inserted by insert_claim() above stays 'claimed'
            // (unminted) if this trips — same as any other mint failure
            // below — and becomes reclaimable after ABANDONED_CLAIM_SECONDS,
            // same as the is_wp_error($mint) branch just below handles.
            if (!self::reserve_daily_mint_slot()) {
                return array(
                    'success' => false,
                    'status'  => 429,
                    'data'    => array(
                        'message' => __('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 'discount-rules-woo'),
                        'code'    => 'rate_limited',
                    ),
                );
            }

            $mint = PopupCouponBridge::mint_coupon($submission_id, $settings);
            if (is_wp_error($mint)) {
                // ROUND-9 AUDIT FIX: the slot reserve_daily_mint_slot() just
                // reserved above would otherwise be permanently burned for a
                // coupon that was never actually minted -- see
                // PopupModel::release_daily_mint_slot()'s docblock.
                self::release_daily_mint_slot();

                return array(
                    'success' => false,
                    'status'  => 500,
                    'data'    => array(
                        'message' => $mint->get_error_message(),
                        'code'    => $mint->get_error_code(),
                    ),
                );
            }

            $issued = PopupModel::mark_issued($submission_id, $mint['coupon_id'], $mint['code']);

            if (!$issued) {
                // Lost the mark_issued() CAS (round-3 audit fix): a
                // concurrent request already recorded a DIFFERENT coupon for
                // this exact row first -- see PopupModel::mark_issued()'s
                // docblock for the full race (a merely-slow, not actually
                // dead, original request racing a legitimate
                // reclaim_abandoned_claim() winner). The mint_coupon() call
                // just above already created a real, live WC_Coupon that no
                // row now points back to -- void it immediately rather than
                // leave an orphaned, redeemable, admin-invisible duplicate
                // code behind, and resolve to whatever the actual winner
                // already produced.
                //
                // ROUND-9 AUDIT FIX: this request's own reserve_daily_mint_slot()
                // call above consumed a slot for a coupon that is now being
                // deleted, never delivered to any customer -- the WINNER's
                // own request already reserved (and rightfully keeps) its
                // own slot for the coupon that actually got issued, so
                // releasing THIS one avoids double-charging the day's cap
                // for a single real coupon. See release_daily_mint_slot()'s
                // docblock.
                self::release_daily_mint_slot();
                self::void_orphan_coupon($mint['coupon_id']);

                $winner = PopupModel::get_by_id($submission_id);
                if ($winner && PopupModel::STATUS_ISSUED === $winner['status'] && !empty($winner['coupon_code'])) {
                    return self::uniform_success(PopupModel::REVEAL_INSTANT, $winner['coupon_code']);
                }

                // Should not happen in practice -- the winner's own
                // mark_issued() call necessarily already committed a code.
                // Fail closed rather than fabricate one.
                return self::uniform_response_for_existing(PopupModel::REVEAL_INSTANT);
            }

            return self::uniform_success(PopupModel::REVEAL_INSTANT, $mint['code']);
        }

        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);
        $expires_at = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + PopupModel::TOKEN_TTL_SECONDS);

        PopupModel::mark_pending($submission_id, $token_hash, $expires_at);
        self::send_confirmation_email($email, $raw_token, $settings);

        // 9. Uniform response (padded -- see the duplicate branch above for
        // why this specific mode needs it).
        self::equalize_confirmed_mode_response_time($request_started_at);

        return self::uniform_success(PopupModel::REVEAL_CONFIRMED);
    }

    /**
     * Reserve one slot in the global daily-mint circuit breaker
     * ('popup.daily_mint_cap', merchant label "Máximo de cupones emitidos
     * por día") — the plan's Anti-abuso §2 emergency brake, independent of
     * the IP/email buckets a botnet with rotating proxies/disposable domains
     * can evade.
     *
     * Round-6 audit fix: this reservation used to happen once, early, in
     * step 4's triple-bucket check — BEFORE email validation and duplicate
     * detection — so it counted submission ATTEMPTS, not coupons actually
     * minted, and CONFIRMED mode's real mint (which happens later, at
     * drw_popup_confirm) never consumed it at all. It is now called ONLY
     * from the two places that actually call
     * PopupCouponBridge::mint_coupon(): the INSTANT-mode branch of
     * process_submit(), and resolve_confirm_redirect_url()'s CAS-won branch
     * — so the counter genuinely tracks coupons about to be issued, matching
     * the setting's own label.
     *
     * @return bool True if a slot was reserved (minting may proceed), false
     *              if today's cap is already reached.
     */
    private static function reserve_daily_mint_slot()
    {
        $daily_cap = max(1, (int)SettingsModel::get_setting('popup.daily_mint_cap', 200));
        return PopupModel::try_reserve_daily_mint_slot($daily_cap);
    }

    /**
     * ROUND-9 AUDIT FIX: release a slot reserved by reserve_daily_mint_slot()
     * moments earlier in the SAME request, once it is known that request will
     * NOT end up delivering a real coupon to a customer after all. See
     * PopupModel::release_daily_mint_slot()'s docblock for the full
     * rationale and the two failure modes this closes (mint_coupon()
     * returning a WP_Error, and a lost mark_issued() CAS whose own
     * just-minted coupon gets voided by void_orphan_coupon()) — both call
     * sites below invoke this in exactly those two situations, immediately
     * after the reservation they are undoing.
     *
     * @return void
     */
    private static function release_daily_mint_slot()
    {
        PopupModel::release_daily_mint_slot();
    }

    /**
     * Best-effort timing-oracle mitigation for CONFIRMED (double opt-in)
     * mode only (round-3 audit finding): a genuinely new registration in
     * this mode performs a real DB write (PopupModel::mark_pending()) plus a
     * real outbound SMTP call (send_confirmation_email(), via
     * WC()->mailer()->send()), both of which a duplicate/already-registered
     * submission skips entirely, returning near-instantly. That latency gap
     * is the same class of email-existence oracle the round-2 "Gap A" fix
     * already closes for CartController::prior_welcome_coupon_redemption_exists()
     * (see that method's docblock) -- an attacker probing a candidate
     * address could otherwise infer "already registered" purely by timing
     * this endpoint's response, even though the JSON body itself is
     * byte-for-byte identical either way.
     *
     * Pads the response so both paths take AT LEAST
     * CONFIRMED_MODE_RESPONSE_FLOOR_SECONDS of wall-clock time. NOT a
     * perfect fix: a real mail transport slower than the floor still stands
     * out on the slow side, and this narrows rather than eliminates the
     * signal -- but it closes the common, cheap case (today, a duplicate
     * returns essentially instantly) at negligible cost to a genuine
     * registrant's perceived latency.
     *
     * Deliberately scoped to CONFIRMED mode only. INSTANT mode has its own,
     * structural new-vs-duplicate response difference (a real code vs.
     * code:null) that is the actual point of instant mode (revealing the
     * code immediately) and cannot be closed this way without breaking the
     * feature -- see the round-3 security audit's finding 5 for why that
     * gap is an accepted product trade-off, not a bug.
     *
     * @param float $request_started_at microtime(true) captured at the top of process_submit().
     */
    private static function equalize_confirmed_mode_response_time($request_started_at)
    {
        $elapsed   = microtime(true) - $request_started_at;
        $remaining = self::CONFIRMED_MODE_RESPONSE_FLOOR_SECONDS - $elapsed;
        if ($remaining > 0) {
            usleep((int)round($remaining * 1000000));
        }
    }

    /**
     * Build the uniform response for a submission that did NOT result in a
     * fresh claim (email already 'pending'/'issued', or this request lost a
     * reclaim race). Never triggers a mint or an email — see callers. Takes
     * no row/status input at all (deliberately, since round-2's security
     * fix): every instant-mode duplicate gets the exact same code:null
     * response no matter what the existing row's real status is, so there
     * is nothing left for this method to branch on besides the mode.
     *
     * @param string $reveal_mode_now Mode a FRESH submission would get right now.
     * @return array{success:bool,status:int,data:array}
     */
    private static function uniform_response_for_existing($reveal_mode_now)
    {
        if (PopupModel::REVEAL_CONFIRMED === $reveal_mode_now) {
            return self::uniform_success(PopupModel::REVEAL_CONFIRMED);
        }

        // Instant mode: NEVER re-surface an already-issued email's real,
        // live, redeemable coupon code here — this endpoint is anonymous
        // and unauthenticated (bare $_POST handler, no session/cookie/
        // ownership binding on the email at all). Doing so would let anyone
        // who merely knows or guesses a victim's address re-submit it and
        // receive that victim's single-use welcome code verbatim, then
        // redeem it themselves before the real owner ever does — this is a
        // confirmed critical finding from the round-2 security audit, not a
        // hypothetical. A row still 'pending'/'claimed' here is the same
        // narrow edge as before (merchant flipped confirmed->instant after
        // that row's original submission, or a same-instant concurrent
        // duplicate); either way the response is now IDENTICAL — uniform
        // success, code always null, a distinct "already registered"
        // message that does not claim a code is ready — regardless of
        // whether this email's row happens to be 'issued' or still
        // mid-flight, closing the disclosure without reopening the
        // email-existence oracle the uniform-response requirement (step 9)
        // exists to close.
        return self::uniform_success(PopupModel::REVEAL_INSTANT, null, true);
    }

    /**
     * ROUND-8 AUDIT NOTE (considered, not applied): the round-8 Spanish
     * security audit flagged that INSTANT mode's two possible $message
     * strings ("Gracias por registrarte..." vs "Ya tenemos un registro...")
     * differ by content even though the JSON shape is identical, calling
     * this a residual email-existence oracle beyond what $code's
     * presence/absence already reveals. Deliberately left as-is after
     * re-checking: the two messages are 1:1 correlated with $code
     * (non-null code -> always the first message, null code -> always the
     * second — see the two call sites), so unifying the wording would not
     * hide any information a caller can't already read off $code itself,
     * which the plan's own Anti-abuso §5 discussion accepts must differ in
     * INSTANT mode (revealing the code once, instantly, IS the feature).
     * Worse, drw-popup.js's buildRevealMarkup() independently derives its
     * HEADLINE from $code too (not from $message) — forcing $message to be
     * identical regardless of $already_registered would reintroduce the
     * exact "headline and body text openly disagreed" bug the round-3/4
     * audits already fixed (see the $already_registered branch below).
     * No code change; noted here so a future audit round doesn't re-flag
     * this as unaddressed.
     *
     * @param string      $mode               PopupModel::REVEAL_INSTANT|REVEAL_CONFIRMED.
     * @param string|null $code
     * @param bool        $already_registered True only for uniform_response_for_existing()'s
     *                                        instant-mode branch above — selects a message
     *                                        that never implies a code is ready to show,
     *                                        since $code is always null in that case.
     * @return array{success:bool,status:int,data:array}
     */
    private static function uniform_success($mode, $code = null, $already_registered = false)
    {
        if (PopupModel::REVEAL_CONFIRMED === $mode) {
            $message = __('Revisa tu correo para confirmar tu registro y obtener tu código de descuento de bienvenida.', 'discount-rules-woo');
        } elseif ($already_registered) {
            // Deliberately does NOT say "revisa tu bandeja de entrada" (round-4
            // audit fix): this branch is reachable only when $mode === 'instant'
            // (the CONFIRMED branch above returns first), i.e. exactly the mode
            // that never sends an email -- the code is revealed directly in the
            // popup at registration time, never mailed. Telling a customer to
            // check an inbox that will never contain this message is a real
            // support-burden bug, not wording polish: it's also the only honest
            // response for the narrow case of a genuine retry within
            // ABANDONED_CLAIM_SECONDS after PopupCouponBridge::mint_coupon()
            // returned a WP_Error (no code was ever produced for that row yet).
            $message = __('Ya tenemos un registro con este correo. El código de descuento se muestra una sola vez, en el momento del registro.', 'discount-rules-woo');
        } else {
            $message = __('Gracias por registrarte. Tu código de descuento de bienvenida está listo.', 'discount-rules-woo');
        }

        return array(
            'success' => true,
            'status'  => 200,
            'data'    => array(
                'mode'    => $mode,
                'code'    => $code,
                'message' => $message,
            ),
        );
    }

    /**
     * The reveal_mode a brand-new submission would get right now, per the
     * live 'popup' settings — used only by the honeypot/dwell-time silent-
     * success paths (which never touch the database, so there is no row to
     * read a mode from).
     *
     * @return string
     */
    private static function current_configured_mode()
    {
        return !empty(SettingsModel::get_setting('popup.require_confirmation', false))
            ? PopupModel::REVEAL_CONFIRMED
            : PopupModel::REVEAL_INSTANT;
    }

    /**
     * @return array The 'popup' settings sub-array, always an array.
     */
    private static function popup_settings()
    {
        $settings = SettingsModel::get_setting('popup', array());
        return is_array($settings) ? $settings : array();
    }

    // ------------------------------------------------------------------
    // Signed render/dwell-time token
    // ------------------------------------------------------------------

    /**
     * Issue a freshly-signed dwell-time token, to be localized into the
     * public popup script's data (drwPopupData) at PAGE-RENDER time by a
     * later phase's ShortcodeController::enqueue_public_assets() wiring —
     * deliberately NOT fetchable on demand via a separate AJAX call, since
     * that would let a bot grab a valid signature instantly and defeat the
     * whole point of a minimum-dwell-time check.
     *
     * @return array{rendered_at:int,signature:string}
     */
    public static function issue_render_token()
    {
        $rendered_at = time();

        return array(
            'rendered_at' => $rendered_at,
            'signature'   => self::build_render_signature($rendered_at),
        );
    }

    /**
     * @param int $rendered_at
     * @param string $signature
     * @return bool
     */
    private static function verify_render_token($rendered_at, $signature)
    {
        if ($rendered_at <= 0 || '' === $signature) {
            return false;
        }

        $expected = self::build_render_signature($rendered_at);
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $elapsed = time() - $rendered_at;

        // Lower bound: proves a real render happened at least
        // MIN_DWELL_SECONDS ago. Upper bound: caps how long a harvested
        // (rendered_at, signature) pair can be replayed for — see
        // MAX_DWELL_SECONDS's docblock. A negative $elapsed (clock skew /
        // forged future rendered_at) is rejected by the lower-bound check
        // on its own, so no separate guard is needed for it.
        return $elapsed >= self::MIN_DWELL_SECONDS && $elapsed <= self::MAX_DWELL_SECONDS;
    }

    /**
     * @param int $rendered_at
     * @return string
     */
    private static function build_render_signature($rendered_at)
    {
        return wp_hash(self::RENDER_TOKEN_ACTION . ':' . (int)$rendered_at);
    }

    // ------------------------------------------------------------------
    // drw_popup_confirm
    // ------------------------------------------------------------------

    /**
     * wp_ajax_drw_popup_confirm / wp_ajax_nopriv_drw_popup_confirm hook
     * callback. Thin wrapper: real logic is resolve_confirm_redirect_url().
     */
    public function confirm()
    {
        $raw_token = isset($_GET['token']) ? (string)wp_unslash($_GET['token']) : '';
        wp_safe_redirect(self::resolve_confirm_redirect_url($raw_token));
        exit;
    }

    /**
     * Resolve a drw_popup_confirm token click into the URL the visitor
     * should land on. The 256-bit token IS the entire security perimeter
     * (an emailed link carries no nonce — see plan). MUST be idempotent:
     * link-tracker bots (Gmail/Outlook) auto-GET links inside emails before
     * a human ever clicks, so a naive "first GET wins the coupon" would burn
     * the single-use code on the tracker and the real visitor would never
     * see it. claim_pending_token()'s CAS ensures only the ONE request that
     * actually flips pending->claimed ever calls mint_coupon(); every other
     * GET (repeat click, double-fire, tracker) resolves to the SAME
     * already-minted code, never a second one.
     *
     * @param string $raw_token
     * @return string Absolute URL to wp_safe_redirect() to.
     */
    public static function resolve_confirm_redirect_url($raw_token)
    {
        $raw_token = sanitize_text_field($raw_token);
        if ('' === $raw_token) {
            return self::landing_url(false);
        }

        $token_hash = hash('sha256', $raw_token);

        if (PopupModel::claim_pending_token($token_hash)) {
            $row = PopupModel::get_by_token_hash($token_hash);
            if (!$row) {
                return self::landing_url(false);
            }

            // Global daily-mint cap (round-6 audit fix): this is CONFIRMED
            // mode's ONE real mint point — process_submit() deliberately
            // does NOT reserve a slot at submission time any more, since a
            // pending confirmation may never be clicked and would otherwise
            // burn a slot for a coupon that's never actually issued. See
            // reserve_daily_mint_slot()'s docblock. If the cap is exhausted
            // here, this row stays 'claimed' (the claim_pending_token() CAS
            // above already committed) without ever minting — same
            // fail-closed handling as the is_wp_error($mint) branch just
            // below — and becomes reclaimable again after
            // PopupModel::ABANDONED_CLAIM_SECONDS.
            if (!self::reserve_daily_mint_slot()) {
                return self::landing_url(false);
            }

            $settings = self::popup_settings();
            $mint     = PopupCouponBridge::mint_coupon($row['id'], $settings);
            if (is_wp_error($mint)) {
                // ROUND-9 AUDIT FIX: release the slot reserve_daily_mint_slot()
                // just reserved above -- otherwise it is permanently burned
                // for a coupon that was never actually minted. See
                // PopupModel::release_daily_mint_slot()'s docblock.
                self::release_daily_mint_slot();

                return self::landing_url(false);
            }

            $issued = PopupModel::mark_issued($row['id'], $mint['coupon_id'], $mint['code']);

            if (!$issued) {
                // Lost the mark_issued() CAS (round-3 audit fix): despite
                // THIS request being the one that won the claim_pending_token()
                // CAS above, a concurrent request already recorded a
                // DIFFERENT coupon for this row first -- reachable because
                // claim_pending_token() does not touch claimed_at, so a
                // re-submission of the same (still-unconfirmed) email can
                // pass reclaim_abandoned_claim()'s own age check and race
                // this confirm click for the same row. See
                // PopupModel::mark_issued()'s docblock for the full
                // scenario. The mint_coupon() call just above already
                // created a real, live WC_Coupon that no row now points
                // back to -- void it rather than leave an orphaned,
                // redeemable, admin-invisible duplicate code behind, and
                // land on whatever the actual winner already produced.
                //
                // ROUND-9 AUDIT FIX: also release this request's own
                // daily-mint slot -- the winner's own request already
                // reserved (and rightfully keeps) its own slot for the
                // coupon that actually got issued, so releasing this one
                // avoids double-charging the day's cap for a single real
                // coupon. See release_daily_mint_slot()'s docblock.
                self::release_daily_mint_slot();
                self::void_orphan_coupon($mint['coupon_id']);

                $winner = PopupModel::get_by_id($row['id']);
                if ($winner && PopupModel::STATUS_ISSUED === $winner['status'] && !empty($winner['coupon_code'])) {
                    return self::landing_url(true, $winner['coupon_code']);
                }

                // Should not happen in practice -- fail closed rather than
                // fabricate a code.
                return self::landing_url(false);
            }

            // The ONE call site of send_code_reveal_email(): this branch is
            // reached only when THIS request's own claim_pending_token() CAS
            // genuinely won (pending -> claimed) AND its own mark_issued()
            // CAS also won (claimed -> issued) -- i.e. a FRESH mint really
            // just happened, right now, for the first time. Every other exit
            // from this method (the two "resolve to the winner's already-
            // minted code" fallbacks above, and the entire "lost the CAS"
            // branch below for a repeat GET/link-tracker re-hit on an
            // already-issued token) deliberately does NOT reach this line --
            // same idempotency discipline already enforced for the coupon
            // mint itself (see this method's own docblock), now extended to
            // this email so a link-prefetch scanner or a duplicate click
            // never spams the customer with a second copy.
            self::send_code_reveal_email($row['email'], $mint['code'], $settings);

            return self::landing_url(true, $mint['code']);
        }

        // Lost the CAS: already 'issued' (repeat GET/link-tracker), the
        // token expired/is unknown, or a concurrent request just won the
        // claim and is minting right now. Never mint a second time here --
        // resolve to whatever the winning request already produced.
        $row = PopupModel::get_by_token_hash($token_hash);
        if (!$row) {
            return self::landing_url(false);
        }

        if (PopupModel::STATUS_ISSUED === $row['status'] && !empty($row['coupon_code'])) {
            return self::landing_url(true, $row['coupon_code']);
        }

        for ($i = 0; $i < self::CONFIRM_RACE_POLL_ATTEMPTS; $i++) {
            usleep(self::CONFIRM_RACE_POLL_INTERVAL_US);
            $row = PopupModel::get_by_token_hash($token_hash);
            if ($row && PopupModel::STATUS_ISSUED === $row['status'] && !empty($row['coupon_code'])) {
                return self::landing_url(true, $row['coupon_code']);
            }
        }

        return self::landing_url(false);
    }

    /**
     * @param bool        $success
     * @param string|null $code
     * @return string
     */
    private static function landing_url($success, $code = null)
    {
        $args = array('drw_popup_confirmed' => $success ? 1 : 0);
        if ($success && $code) {
            $args['code'] = $code;
        }

        return add_query_arg($args, home_url('/'));
    }

    // ------------------------------------------------------------------
    // Confirmation email (WC()->mailer(), NOT a WC_Email subclass -- see
    // plan's reasoning: this is the plugin's first-ever transactional
    // email, and using the mailer's own send()/wrap_message() means the
    // merchant's existing WooCommerce -> Settings -> Emails header/footer/
    // colours apply automatically, with no new settings surface to
    // fragment out of OmniDiscount's own Configuración screen.)
    // ------------------------------------------------------------------

    /**
     * @param string $email
     * @param string $raw_token Unhashed 256-bit token -- only ever lives here and in the emailed link.
     * @param array  $settings  The 'popup' settings sub-array.
     */
    private static function send_confirmation_email($email, $raw_token, array $settings)
    {
        $confirm_url = add_query_arg(
            array(
                'action' => 'drw_popup_confirm',
                'token'  => $raw_token,
            ),
            admin_url('admin-ajax.php')
        );

        $message = self::build_confirmation_email_html($settings, $confirm_url, $email);
        if (null === $message) {
            return;
        }

        // build_confirmation_email_html() already proved WC()->mailer() is
        // reachable (that's the only way it returns non-null) -- re-fetch
        // rather than thread the instance through, keeping the builder a
        // pure function of ($settings, $confirm_url) that the REST preview
        // endpoint can call with no side effects of its own.
        $mailer = WC()->mailer();

        $texts = self::resolve_confirmation_email_texts($settings);
        $mailer->send($email, $texts['subject'], $message, "Content-Type: text/html\r\n", array());
    }

    /**
     * Resolves the subject/heading/intro texts from the 'popup' settings
     * sub-array, falling back to the same hardcoded Spanish defaults a real
     * email uses when a field is blank. Shared by send_confirmation_email()
     * and the admin preview REST endpoint (preview_email()) so the two can
     * never drift apart.
     *
     * @param array $settings The 'popup' settings sub-array (or a
     *                        draft-merged variant for preview).
     * @return array{subject:string,heading:string,intro:string}
     */
    private static function resolve_confirmation_email_texts(array $settings)
    {
        return array(
            'subject' => !empty($settings['email_subject'])
                ? (string)$settings['email_subject']
                : __('Confirma tu correo y obtén tu descuento de bienvenida', 'discount-rules-woo'),
            'heading' => !empty($settings['email_heading'])
                ? (string)$settings['email_heading']
                : __('Ya casi tienes tu descuento', 'discount-rules-woo'),
            'intro'   => !empty($settings['email_intro'])
                ? (string)$settings['email_intro']
                : __('Haz clic en el botón para confirmar tu correo y ver tu código de descuento de bienvenida.', 'discount-rules-woo'),
        );
    }

    /**
     * Resolves the subject/heading/intro texts for the CODE-REVEAL email
     * (build_code_reveal_email_html()'s simple-mode path) from the 'popup'
     * settings sub-array, falling back to hardcoded Spanish defaults when a
     * field is blank -- same convention as resolve_confirmation_email_texts()
     * above, just for the 'email_code_*' settings keys instead of 'email_*'.
     *
     * @param array $settings The 'popup' settings sub-array (or a
     *                        draft-merged variant for preview).
     * @return array{subject:string,heading:string,intro:string}
     */
    private static function resolve_code_reveal_email_texts(array $settings)
    {
        return array(
            'subject' => !empty($settings['email_code_subject'])
                ? (string)$settings['email_code_subject']
                : __('Tu código de descuento de bienvenida', 'discount-rules-woo'),
            'heading' => !empty($settings['email_code_heading'])
                ? (string)$settings['email_code_heading']
                : __('¡Aquí está tu código!', 'discount-rules-woo'),
            'intro'   => !empty($settings['email_code_intro'])
                ? (string)$settings['email_code_intro']
                : __('Tu código de descuento de bienvenida ya está listo. Úsalo en tu próxima compra antes de que expire.', 'discount-rules-woo'),
        );
    }

    /**
     * Pure {{token}} substitution against a caller-supplied template string.
     * Shared by the custom-HTML branches of build_confirmation_email_html()
     * and build_code_reveal_email_html().
     *
     * Uses strtr() rather than str_replace() deliberately: strtr() with an
     * array replaces every occurrence in a SINGLE pass over the original
     * string, so replacement text is never re-scanned for further token
     * matches. str_replace() with parallel search/replace arrays instead
     * chains -- it runs one search/replace pair at a time over the
     * (already partially substituted) string, so text inserted by an
     * earlier pair can accidentally be "found" and mangled by a later
     * pair's search term (e.g. a value that happens to contain the literal
     * substring "{{tienda}}" gets that substring replaced again when the
     * {{tienda}} pair runs). All values passed into $vars today are already
     * esc_html()/esc_url()-escaped by the caller, so this couldn't yet be
     * turned into an injection -- but it could still silently garble
     * displayed text (e.g. an email local-part containing literal `{`/`}`
     * characters, which are valid per RFC 5322), and would become a real
     * risk the moment a future caller ever passed an unescaped value. Using
     * strtr() removes the whole class of bug rather than relying on every
     * future caller remembering not to trigger it.
     *
     * CONTRACT (matches this codebase's established "pure builder, caller's
     * job to sanitize" convention -- see PopupCouponBridge's clamp-before-
     * construct pattern): this method does NO escaping of any kind. Every
     * value in $vars MUST already be pre-escaped by the CALLER before being
     * passed in -- esc_html() for plain text (store name, discount value,
     * recipient email), esc_url() for links. Passing an unescaped value here
     * is the caller's bug, not this method's.
     *
     * Any {{token}} present in $template with no matching key in $vars is
     * left untouched, as literal text -- deliberately NOT silently removed.
     * A merchant who mistypes a token (e.g. {{codigo_descuentos}}) sees the
     * literal, broken-looking placeholder in their own preview and can fix
     * it, rather than having it silently vanish with no trace.
     *
     * @param string               $template Raw HTML containing zero or more {{token}} placeholders.
     * @param array<string,string> $vars     Map of token name (WITHOUT the surrounding {{ }}) => pre-escaped replacement value.
     * @return string
     */
    private static function render_email_template($template, array $vars)
    {
        $replace_pairs = array();
        foreach ($vars as $key => $value) {
            $replace_pairs['{{' . $key . '}}'] = (string)$value;
        }

        return strtr((string)$template, $replace_pairs);
    }

    /**
     * Human-readable discount value string for the popup's 'discount_type'/
     * 'discount_value' settings -- e.g. "25%" or "$10.000" -- used to fill
     * the {{descuento}} template token in both custom-HTML emails below.
     * Not escaped itself -- callers esc_html() it same as every other value
     * passed to render_email_template().
     *
     * @param array $settings The 'popup' settings sub-array.
     * @return string
     */
    private static function format_discount_value_text(array $settings)
    {
        $type  = isset($settings['discount_type']) ? (string)$settings['discount_type'] : 'percent';
        $value = isset($settings['discount_value']) ? (float)$settings['discount_value'] : 0.0;

        if ('fixed' === $type) {
            return '$' . number_format($value, 0, ',', '.');
        }

        $rounded = round($value, 2);
        $text    = ((float)(int)$rounded === $rounded)
            ? (string)(int)$rounded
            : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

        return $text . '%';
    }

    /**
     * Builds the fully wrapped confirmation-email HTML (post
     * $mailer->wrap_message() -- i.e. with the merchant's WooCommerce ->
     * Settings -> Emails header/footer/colours already applied) for the
     * given settings + confirm URL. Pure builder: no DB writes, no outbound
     * mail. This is the ONLY place that builds the email body, so both the
     * real send path (send_confirmation_email()) and the admin "Correo de
     * confirmación" live preview (preview_email()) always render byte-for-
     * byte the same markup for the same inputs.
     *
     * Branches on $settings['email_confirm_use_custom_html']:
     *  - Falsy (default): the original simple heading/intro path below,
     *    UNCHANGED -- byte-for-byte identical to the pre-custom-HTML
     *    behaviour, since real customers get this today.
     *  - Truthy: delegates to build_custom_confirmation_email_html().
     *
     * @param array  $settings        The 'popup' settings sub-array (or a
     *                                draft-merged variant for preview).
     * @param string $confirm_url     Real token link for a live send; a fake/
     *                                placeholder link for a preview.
     * @param string $recipient_email Recipient address, for the {{correo}}
     *                                token in custom-HTML mode only --
     *                                unused (and safe to omit) in simple
     *                                mode. Optional so existing 2-arg callers
     *                                (and tests) are unaffected.
     * @return string|null Wrapped HTML, or null if WC()/mailer() is
     *                      unavailable (mirrors send_confirmation_email()'s
     *                      own pre-refactor early-return guards).
     */
    private static function build_confirmation_email_html(array $settings, $confirm_url, $recipient_email = '')
    {
        if (!function_exists('WC')) {
            return null;
        }

        $wc = WC();
        if (!$wc || !method_exists($wc, 'mailer')) {
            return null;
        }

        $mailer = $wc->mailer();
        if (!$mailer) {
            return null;
        }

        if (!empty($settings['email_confirm_use_custom_html'])) {
            return self::build_custom_confirmation_email_html($settings, $confirm_url, $recipient_email, $mailer);
        }

        $texts = self::resolve_confirmation_email_texts($settings);

        $body  = '<p>' . esc_html($texts['intro']) . '</p>';
        $body .= '<p style="text-align:center;margin:24px 0;">';
        $body .= '<a href="' . esc_url($confirm_url) . '" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">';
        $body .= esc_html__('Confirmar y ver mi código', 'discount-rules-woo');
        $body .= '</a></p>';
        $body .= '<p style="font-size:12px;color:#666666;">';
        $body .= esc_html__('Si el botón no funciona, copia y pega este enlace en tu navegador:', 'discount-rules-woo');
        $body .= '<br>' . esc_html($confirm_url) . '</p>';

        return $mailer->wrap_message($texts['heading'], $body);
    }

    /**
     * Custom-HTML branch of build_confirmation_email_html() -- takes the
     * merchant's own $settings['email_confirm_html'] template and substitutes
     * {{tokens}} via render_email_template().
     *
     * $settings['email_confirm_html'] is already wp_kses_post()-sanitized at
     * SAVE time by SettingsModel (see its $html_paths whitelist), but is
     * re-run through wp_kses_post() here too, at RENDER time, as
     * defense-in-depth against any future code path that might write to this
     * setting outside SettingsModel's own sanitizer.
     *
     * @param array    $settings        The 'popup' settings sub-array.
     * @param string   $confirm_url
     * @param string   $recipient_email
     * @param \WC_Emails|object $mailer Already-resolved WC()->mailer() instance.
     * @return string
     */
    private static function build_custom_confirmation_email_html(array $settings, $confirm_url, $recipient_email, $mailer)
    {
        $template = isset($settings['email_confirm_html']) ? (string)$settings['email_confirm_html'] : '';
        $template = wp_kses_post($template);

        $body = self::render_email_template($template, array(
            'enlace_confirmacion' => esc_url($confirm_url),
            'correo'              => esc_html($recipient_email),
            'tienda'              => esc_html(get_bloginfo('name')),
            'descuento'           => esc_html(self::format_discount_value_text($settings)),
            'vigencia_dias'       => esc_html((string)(isset($settings['expiry_days']) ? $settings['expiry_days'] : '')),
        ));

        if (empty($settings['email_confirm_wrap_wc_chrome'])) {
            return $body;
        }

        $texts = self::resolve_confirmation_email_texts($settings);
        return $mailer->wrap_message($texts['heading'], $body);
    }

    /**
     * Builds the fully wrapped CODE-REVEAL email HTML -- the new
     * transactional email sent exactly once, at the moment a CONFIRMED-mode
     * popup registration's confirm link genuinely wins the mint (see
     * send_code_reveal_email()'s call site in resolve_confirm_redirect_url()
     * for the idempotency discipline this depends on). Pure builder, same
     * "no DB writes, no outbound mail" contract as build_confirmation_email_html(),
     * shared by the real send path and the admin preview REST endpoint.
     *
     * Branches on $settings['email_code_use_custom_html'], same pattern as
     * build_confirmation_email_html() above.
     *
     * @param array  $settings        The 'popup' settings sub-array (or a
     *                                draft-merged variant for preview).
     * @param string $code            The real (or, for a preview, sample)
     *                                coupon code.
     * @param string $store_url       Link back to the store.
     * @param string $recipient_email Recipient address, for the {{correo}}
     *                                token in custom-HTML mode only.
     * @return string|null Wrapped HTML, or null if WC()/mailer() is unavailable.
     */
    private static function build_code_reveal_email_html(array $settings, $code, $store_url, $recipient_email = '')
    {
        if (!function_exists('WC')) {
            return null;
        }

        $wc = WC();
        if (!$wc || !method_exists($wc, 'mailer')) {
            return null;
        }

        $mailer = $wc->mailer();
        if (!$mailer) {
            return null;
        }

        if (!empty($settings['email_code_use_custom_html'])) {
            return self::build_custom_code_reveal_email_html($settings, $code, $store_url, $recipient_email, $mailer);
        }

        $texts = self::resolve_code_reveal_email_texts($settings);

        $body  = '<p>' . esc_html($texts['intro']) . '</p>';
        $body .= '<p style="text-align:center;margin:24px 0;font-size:24px;font-weight:700;letter-spacing:2px;">' . esc_html($code) . '</p>';
        $body .= '<p style="text-align:center;margin:24px 0;">';
        $body .= '<a href="' . esc_url($store_url) . '" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">';
        $body .= esc_html__('Ir a la tienda', 'discount-rules-woo');
        $body .= '</a></p>';

        return $mailer->wrap_message($texts['heading'], $body);
    }

    /**
     * Custom-HTML branch of build_code_reveal_email_html() -- same pattern
     * as build_custom_confirmation_email_html() above, just for
     * $settings['email_code_html'] and the code-reveal token set.
     *
     * @param array  $settings
     * @param string $code
     * @param string $store_url
     * @param string $recipient_email
     * @param \WC_Emails|object $mailer
     * @return string
     */
    private static function build_custom_code_reveal_email_html(array $settings, $code, $store_url, $recipient_email, $mailer)
    {
        $template = isset($settings['email_code_html']) ? (string)$settings['email_code_html'] : '';
        $template = wp_kses_post($template);

        $body = self::render_email_template($template, array(
            'codigo_descuento' => esc_html($code),
            'enlace_tienda'    => esc_url($store_url),
            'correo'           => esc_html($recipient_email),
            'tienda'           => esc_html(get_bloginfo('name')),
            'descuento'        => esc_html(self::format_discount_value_text($settings)),
            'vigencia_dias'    => esc_html((string)(isset($settings['expiry_days']) ? $settings['expiry_days'] : '')),
        ));

        if (empty($settings['email_code_wrap_wc_chrome'])) {
            return $body;
        }

        $texts = self::resolve_code_reveal_email_texts($settings);
        return $mailer->wrap_message($texts['heading'], $body);
    }

    /**
     * Sends the code-reveal email -- mirrors send_confirmation_email()'s
     * exact WC()->mailer() guard/send pattern. MUST be called exactly once
     * per real confirmation -- see the docblock at its one call site in
     * resolve_confirm_redirect_url() for the idempotency discipline this
     * depends on (never call this from the repeat-GET/already-issued branch).
     *
     * @param string $email
     * @param string $code     Real, live, single-use coupon code.
     * @param array  $settings The 'popup' settings sub-array.
     */
    private static function send_code_reveal_email($email, $code, array $settings)
    {
        $store_url = home_url('/');
        $message   = self::build_code_reveal_email_html($settings, $code, $store_url, $email);
        if (null === $message) {
            return;
        }

        $mailer = WC()->mailer();
        $texts  = self::resolve_code_reveal_email_texts($settings);
        $mailer->send($email, $texts['subject'], $message, "Content-Type: text/html\r\n", array());
    }

    // ------------------------------------------------------------------
    // Admin: submissions submenu, REST list, CSV export
    // ------------------------------------------------------------------

    /**
     * Registers the "Registros del popup" submenu, matching
     * AnalyticsController::add_analytics_submenu()'s pattern exactly
     * (consolidated under the 'drw-discount-rules' parent, same
     * 'manage_woocommerce' capability).
     */
    public function add_submissions_submenu()
    {
        add_submenu_page(
            'drw-discount-rules',
            __('OmniDiscount — Registros del popup', 'discount-rules-woo'),
            __('Registros del popup', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-popup-submissions',
            array($this, 'render_submissions_page')
        );
    }

    /**
     * GET /drw/v1/popup/submissions — admin-gated, paginated list backing
     * drw-popup-submissions.js. Never exposes the raw client IP (only the
     * already-salted ip_hash column, itself truncated further client-side
     * for display — see the plan's Anti-abuso §7).
     */
    public function register_rest_routes()
    {
        register_rest_route('drw/v1', '/popup/submissions', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_submissions'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'args' => array(
                'page'     => array('default' => 1, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => self::SUBMISSIONS_PER_PAGE, 'sanitize_callback' => 'absint'),
            ),
        ));

        // Admin-only live preview for the popup's two transactional-email
        // settings sections (Configuración -> Popup): "Correo de
        // confirmación" (template=confirm, the original/default) and "Correo
        // con el código" (template=code, new). Accepts the DRAFT field
        // values straight out of the still-unsaved controls -- never the
        // persisted settings -- so the merchant sees their in-progress
        // edits, not last save. Renders through the exact same
        // build_confirmation_email_html()/build_code_reveal_email_html() the
        // real sends use, so there is no separate preview template to drift
        // out of sync. 'html'/'use_custom_html'/'wrap_wc_chrome' are the
        // custom-HTML-mode fields; unused (safe to omit) when previewing
        // simple mode.
        register_rest_route('drw/v1', '/popup/preview-email', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'preview_email'),
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'args' => array(
                'template'        => array('default' => 'confirm', 'sanitize_callback' => 'sanitize_key'),
                'subject'         => array('default' => '', 'sanitize_callback' => 'sanitize_text_field'),
                'heading'         => array('default' => '', 'sanitize_callback' => 'sanitize_text_field'),
                'intro'           => array('default' => '', 'sanitize_callback' => 'sanitize_text_field'),
                'html'            => array('default' => '', 'sanitize_callback' => 'wp_kses_post'),
                'use_custom_html' => array('default' => false, 'sanitize_callback' => 'rest_sanitize_boolean'),
                'wrap_wc_chrome'  => array('default' => true, 'sanitize_callback' => 'rest_sanitize_boolean'),
            ),
        ));
    }

    /**
     * POST /drw/v1/popup/preview-email -- renders either the confirmation
     * email (default, template=confirm) or the new code-reveal email
     * (template=code) for the given draft field values (layered over the
     * real saved popup.* settings for every other field the builders might
     * read), using clearly-fake sample values -- a confirm link ending in an
     * obviously-fake token, a sample coupon code -- so nothing returned here
     * could be mistaken for a real, working confirmation URL or a real,
     * redeemable coupon. The real store name and the real configured
     * discount type/value/expiry_days ARE used (not faked), so a
     * custom-HTML preview's {{tienda}}/{{descuento}}/{{vigencia_dias}}
     * substitutions show accurate values, not placeholders.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function preview_email($request)
    {
        $saved_popup = SettingsModel::get_setting('popup', array());
        $saved_popup = is_array($saved_popup) ? $saved_popup : array();

        $sample_email = 'cliente@ejemplo.com';
        $template     = (string)$request->get_param('template');

        if ('code' === $template) {
            $draft_settings = array_merge($saved_popup, array(
                'email_code_subject'         => (string)$request->get_param('subject'),
                'email_code_heading'         => (string)$request->get_param('heading'),
                'email_code_intro'           => (string)$request->get_param('intro'),
                'email_code_use_custom_html' => (bool)$request->get_param('use_custom_html'),
                'email_code_html'            => (string)$request->get_param('html'),
                'email_code_wrap_wc_chrome'  => (bool)$request->get_param('wrap_wc_chrome'),
            ));

            $html = self::build_code_reveal_email_html($draft_settings, 'EJEMPLO1234', home_url('/'), $sample_email);
            if (null === $html) {
                return self::preview_unavailable_error();
            }

            return rest_ensure_response(array(
                'html'    => $html,
                'subject' => self::resolve_code_reveal_email_texts($draft_settings)['subject'],
            ));
        }

        $draft_settings = array_merge($saved_popup, array(
            'email_subject'                 => (string)$request->get_param('subject'),
            'email_heading'                 => (string)$request->get_param('heading'),
            'email_intro'                   => (string)$request->get_param('intro'),
            'email_confirm_use_custom_html' => (bool)$request->get_param('use_custom_html'),
            'email_confirm_html'            => (string)$request->get_param('html'),
            'email_confirm_wrap_wc_chrome'  => (bool)$request->get_param('wrap_wc_chrome'),
        ));

        $preview_confirm_url = add_query_arg(
            array(
                'action' => 'drw_popup_confirm',
                'token'  => 'PREVIEW-NOT-A-REAL-TOKEN',
            ),
            admin_url('admin-ajax.php')
        );

        $html = self::build_confirmation_email_html($draft_settings, $preview_confirm_url, $sample_email);
        if (null === $html) {
            return self::preview_unavailable_error();
        }

        return rest_ensure_response(array(
            'html'    => $html,
            'subject' => self::resolve_confirmation_email_texts($draft_settings)['subject'],
        ));
    }

    /**
     * Shared 503 WP_Error for preview_email()'s two "WC()/mailer()
     * unavailable" early returns.
     *
     * @return \WP_Error
     */
    private static function preview_unavailable_error()
    {
        return new \WP_Error(
            'drw_preview_unavailable',
            __('No se pudo generar la vista previa: WooCommerce no está disponible.', 'discount-rules-woo'),
            array('status' => 503)
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_submissions($request)
    {
        $page     = max(1, (int)$request->get_param('page'));
        $per_page = max(1, min(100, (int)$request->get_param('per_page')));

        $result = PopupModel::get_paginated($page, $per_page);

        return rest_ensure_response(array(
            'items'      => array_map(array($this, 'format_submission_for_rest'), $result['items']),
            'total'      => $result['total'],
            'page'       => $result['page'],
            'per_page'   => $result['per_page'],
            'total_pages' => $result['per_page'] > 0 ? (int)ceil($result['total'] / $result['per_page']) : 0,
        ));
    }

    /**
     * Shape one PopupModel row for the REST response — every field the
     * admin table needs, nothing it does not (no confirm_token, even
     * hashed; no raw ip_hash beyond what the table truncates for display).
     *
     * @param array $row
     * @return array
     */
    private function format_submission_for_rest(array $row)
    {
        return array(
            'id'           => (int)$row['id'],
            'email'        => (string)$row['email'],
            'status'       => (string)$row['status'],
            'reveal_mode'  => (string)$row['reveal_mode'],
            'coupon_id'    => $row['coupon_id'],
            'coupon_code'  => $row['coupon_code'],
            'ip_hash'      => $row['ip_hash'],
            'created_at'   => $row['created_at'],
            'claimed_at'   => $row['claimed_at'],
            'confirmed_at' => $row['confirmed_at'],
            'revealed_at'  => $row['revealed_at'],
        );
    }

    /**
     * Bare PHP shell — drw-popup-submissions.js (wp.element, mounted by
     * enqueue_submissions_assets()) renders the actual table, matching
     * AnalyticsController::render_analytics_page()'s pattern.
     */
    public function render_submissions_page()
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OmniDiscount — Registros del popup', 'discount-rules-woo'); ?></h1>
            <div id="drw-popup-submissions-app">
                <p><?php esc_html_e('Cargando registros...', 'discount-rules-woo'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue the submissions table script, matching
     * AnalyticsController::enqueue_analytics_assets()'s pattern (gate on
     * $_GET['page'] rather than a captured hook suffix — this is the only
     * OmniDiscount screen PopupController itself owns, so it has no
     * AdminController-style $hook_suffixes array to check against).
     *
     * @param string $hook
     */
    public function enqueue_submissions_assets($hook)
    {
        $drw_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ('drw-popup-submissions' !== $drw_page) {
            return;
        }

        wp_enqueue_style(
            'drw-admin-style',
            DRW_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            DRW_VERSION
        );

        wp_enqueue_script(
            'drw-popup-submissions',
            DRW_PLUGIN_URL . 'assets/js/drw-popup-submissions.js',
            array('wp-element', 'wp-api-fetch'),
            DRW_VERSION,
            true
        );

        wp_localize_script('drw-popup-submissions', 'drwPopupSubmissionsData', array(
            'apiRoot'    => esc_url_raw(rest_url('drw/v1/popup/submissions')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'perPage'    => self::SUBMISSIONS_PER_PAGE,
            'exportUrl'  => wp_nonce_url(
                admin_url('admin-post.php?action=drw_export_popup_submissions'),
                self::EXPORT_NONCE_ACTION
            ),
            'couponEditUrlBase' => admin_url('post.php?action=edit&post='),
        ));
    }

    /**
     * admin_post_drw_export_popup_submissions — plain admin-post action,
     * standard WP idiom (current_user_can() + check_admin_referer()), same
     * pattern as ImportExportController::handle_export(). Streams a CSV,
     * never a redirect/JSON response.
     */
    public function handle_csv_export()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permiso denegado.', 'discount-rules-woo'));
        }
        check_admin_referer(self::EXPORT_NONCE_ACTION);

        $rows = PopupModel::get_all_for_export();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="drw-popup-submissions-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        fputcsv($out, array(
            __('Correo', 'discount-rules-woo'),
            __('Estado', 'discount-rules-woo'),
            __('Modo', 'discount-rules-woo'),
            __('Código', 'discount-rules-woo'),
            __('IP (hash truncado)', 'discount-rules-woo'),
            __('Registrado', 'discount-rules-woo'),
            __('Confirmado', 'discount-rules-woo'),
            __('Revelado', 'discount-rules-woo'),
        ));

        foreach ($rows as $row) {
            fputcsv($out, array(
                self::csv_safe($row['email']),
                self::csv_safe($row['status']),
                self::csv_safe($row['reveal_mode']),
                self::csv_safe($row['coupon_code'] ? $row['coupon_code'] : ''),
                self::csv_safe($row['ip_hash'] ? substr((string)$row['ip_hash'], 0, 12) : ''),
                self::csv_safe($row['created_at']),
                self::csv_safe($row['confirmed_at'] ? $row['confirmed_at'] : ''),
                self::csv_safe($row['revealed_at'] ? $row['revealed_at'] : ''),
            ));
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Neutralize CSV/formula injection (round-3 audit fix): $row['email']
     * is fully attacker-controlled (is_email() + WordPress's own local-part
     * regex both permit a leading =, +, -, or @), and a spreadsheet app
     * (Excel/Google Sheets/LibreOffice) that opens the exported CSV treats
     * any cell starting with one of those four characters as a formula --
     * classic CSV/DDE injection, capable of remote-content pulls or, on
     * older Excel builds, command execution, the moment the merchant opens
     * the export. Prefixing with a single quote is the standard mitigation
     * (OWASP's documented technique for this exact class of bug): every
     * major spreadsheet app renders a leading `'` as "this cell is text",
     * stripping it from display, while fputcsv()'s own quoting/escaping
     * already handles the CSV-syntax layer separately -- this guards the
     * SPREADSHEET-interpretation layer on top of that, which is a distinct
     * concern fputcsv() has no way to know about.
     *
     * Applied to every column, not just email: status/reveal_mode/dates are
     * plugin-controlled constants today, but coupon_code is a merchant/
     * plugin-generated value too, and defending only the one column known
     * to be attacker-controlled RIGHT NOW is exactly the kind of narrow fix
     * that quietly breaks the next time a new column is added.
     *
     * @param string $value
     * @return string
     */
    private static function csv_safe($value)
    {
        $value = (string)$value;
        if ('' !== $value && false !== strpos('=+-@', $value[0])) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Delete a WC_Coupon minted by the LOSING side of a mark_issued() CAS
     * race (round-3 audit fix — see the two call sites above and
     * PopupModel::mark_issued()'s docblock). Same pattern already
     * established by PromoBridgeController::decompile_coupon() for deleting
     * a coupon this plugin owns: load the real WC_Coupon object, confirm
     * ownership via its OWN '_drw_popup_submission_id' meta before touching
     * it (cheap, harmless insurance against ever deleting an unrelated
     * coupon, even though $coupon_id here is always a value this same
     * request's own mint_coupon() call just returned), then $coupon->delete(true) --
     * never a raw wp_delete_post() bypassing WC_Coupon's own data-layer
     * cleanup. Best-effort: this is cleanup for an already-narrow race
     * window, never something either caller can afford to block its own
     * response on, so any failure here (including the coupon simply not
     * loading) is silently swallowed -- the caller already has a safe
     * fallback response to return regardless of whether this succeeds.
     *
     * @param int $coupon_id
     */
    private static function void_orphan_coupon($coupon_id)
    {
        $coupon_id = (int)$coupon_id;
        if ($coupon_id <= 0 || !class_exists('WC_Coupon')) {
            return;
        }

        try {
            $coupon = new \WC_Coupon($coupon_id);
        } catch (\Throwable $e) {
            return;
        }

        if ($coupon->get_id() !== $coupon_id || !$coupon->get_meta('_drw_popup_submission_id')) {
            return;
        }

        $coupon->delete(true);
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Best-effort client IP, used as a transient RateLimiter bucket key for
     * the lifetime of this one request (never persisted raw — see
     * hash('sha256', $ip . wp_salt('auth')) at the ip_hash call site above)
     * AND as the salted ip_hash input for the submissions table/CSV export.
     *
     * ROUND-7 AUDIT FIX: this used to prefer WC_Geolocation::get_ip_address()
     * before falling back to REMOTE_ADDR. Verified against the real
     * installed WooCommerce core that WC_Geolocation unconditionally trusts
     * $_SERVER['HTTP_X_REAL_IP']/'HTTP_X_FORWARDED_FOR' ahead of
     * REMOTE_ADDR, with no trusted-proxy allowlist. That made the
     * IP_RATE_MAX bucket trivially bypassable — a single machine sending a
     * different (even fabricated) X-Forwarded-For value on every POST gets
     * a fresh, unthrottled bucket key each time, no botnet/proxy rotation
     * required, undermining the "triple bucket" design's IP leg — and made
     * the persisted ip_hash equally forgeable as an admin audit signal
     * (the sha256+salt technique itself was always sound; only the INPUT
     * value was attacker-controlled). This plugin has no trusted-proxy
     * allowlist of its own, so REMOTE_ADDR (the actual TCP peer PHP saw,
     * which a client cannot forge) is used directly instead, matching the
     * same fix applied to CartController::get_client_ip(). A real
     * reverse-proxy deployment in front of this site would need its own
     * trusted-proxy configuration to recover per-visitor granularity here —
     * out of scope for this fix.
     *
     * Deliberately duplicated from CartController::get_client_ip() rather
     * than shared/imported, matching this codebase's established convention
     * for small glue code around shared utilities like RateLimiter (see
     * PopupCouponBridge's own docblock for the same "separate, self-
     * contained subsystem" reasoning).
     *
     * @return string
     */
    private static function get_client_ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    }
}
