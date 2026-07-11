<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct CRUD over wp_drw_popup_submissions, same "thin model, raw $wpdb"
 * pattern as PromoModel.php/RuleModel.php. Every write path here is designed
 * around ONE non-negotiable invariant (see the plan's "Anti-abuso" §2/§6):
 * the UNIQUE(email) key is the real concurrency guard for "one coupon per
 * person", not an app-level SELECT-then-INSERT — see insert_claim()'s
 * docblock. PopupController is the only caller; this class never touches
 * PromoModel/PromoTypeRegistry/wp_drw_promos.
 *
 * Time base: every DATETIME column this class writes or compares
 * (claimed_at/created_at/confirmed_at/revealed_at/token_expires_at) uses
 * `current_time('mysql', true)` / `current_time('timestamp', true)` — GMT,
 * never site-local. `token_expires_at` is written by PopupController in GMT
 * too, so mixing local writes with GMT cutoff comparisons here would silently
 * defeat ABANDONED_CLAIM_SECONDS/DEAD_CLAIM_SECONDS/token expiry on any site
 * whose `gmt_offset` isn't exactly 0 — keep every new write in this class on
 * GMT to match.
 */
class PopupModel
{
    /** Row statuses, matching the plan's schema exactly. */
    const STATUS_CLAIMED = 'claimed';
    const STATUS_PENDING = 'pending';
    const STATUS_ISSUED  = 'issued';

    /**
     * reveal_mode column values -- deliberately distinct constants from the
     * STATUS_* row-lifecycle ones above (a 'confirmed' reveal_mode row still
     * passes through STATUS_CLAIMED -> STATUS_PENDING -> STATUS_ISSUED, the
     * two dimensions are independent).
     */
    const REVEAL_INSTANT   = 'instant';
    const REVEAL_CONFIRMED = 'confirmed';

    /**
     * A 'claimed' row with no terminal state reached within this many
     * seconds is considered abandoned (the process that inserted it crashed
     * or errored before minting/emailing) and safe to atomically re-claim
     * for a fresh attempt by the same email — see reclaim_abandoned_claim().
     */
    const ABANDONED_CLAIM_SECONDS = 60;

    /** Confirmation token lifetime for the double opt-in flow. */
    const TOKEN_TTL_SECONDS = 48 * HOUR_IN_SECONDS;

    /**
     * A 'claimed' row this old was never even reclaimed by a same-email
     * retry (the visitor never came back) — the daily cleanup cron
     * (purge_stale_rows()) deletes these outright so the email is not
     * blocked forever. Deliberately much longer than
     * ABANDONED_CLAIM_SECONDS, which only governs the immediate
     * same-request reclaim race, not general janitorial cleanup.
     */
    const DEAD_CLAIM_SECONDS = DAY_IN_SECONDS;

    /**
     * @return string
     */
    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'drw_popup_submissions';
    }

    /**
     * Insert a brand-new 'claimed' row for $email.
     *
     * This is the ACTUAL concurrency guard for "one welcome coupon per
     * person" (plan's Anti-abuso §2/PopupController step 6): a plain
     * $wpdb->insert() straight into a column with a UNIQUE(email) index,
     * never a SELECT-count-then-INSERT (which would reopen the exact TOCTOU
     * race RuleModel::try_reserve_usage() exists to close for rule usage
     * limits). Of two concurrent inserts for the same email, InnoDB
     * guarantees exactly one succeeds; the other gets a duplicate-key error
     * and $wpdb->insert() returns false, which the caller
     * (PopupController::submit()) turns into a reclaim_abandoned_claim()
     * attempt instead of a second row.
     *
     * @param string $email      Already validated/sanitized (is_email() + sanitize_email()).
     * @param string $reveal_mode 'instant' | 'confirmed'.
     * @param string $ip_hash    Salted hash, never the raw IP (see PopupController::hash_ip()).
     * @param string $user_agent Truncated to the column width by the caller.
     * @param string $source_url Truncated to the column width by the caller.
     * @return int|false New row id, or false on failure (including — expected,
     *                    not exceptional — a duplicate-email collision).
     */
    public static function insert_claim($email, $reveal_mode, $ip_hash, $user_agent, $source_url)
    {
        global $wpdb;
        // GMT, not site-local -- see the class docblock note on time bases:
        // every DATETIME column in this table (claimed_at/created_at/
        // confirmed_at/revealed_at/token_expires_at) is written and compared
        // in GMT so that reclaim_abandoned_claim()/claim_pending_token()/
        // purge_stale_rows()'s cutoff comparisons are meaningful regardless
        // of the site's configured gmt_offset.
        $now = current_time('mysql', true);

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'email'        => $email,
                'status'       => self::STATUS_CLAIMED,
                'reveal_mode'  => $reveal_mode,
                'ip_hash'      => $ip_hash,
                'user_agent'   => $user_agent,
                'source_url'   => $source_url,
                'claimed_at'   => $now,
                'created_at'   => $now,
            )
        );

        return $inserted ? (int)$wpdb->insert_id : false;
    }

    /**
     * Atomically re-claim an abandoned 'claimed' row for $email (plan's
     * PopupController step 7): a process that inserted the row previously
     * crashed/errored before ever reaching a terminal state (pending/issued),
     * so the email would otherwise be locked out forever by its own earlier,
     * half-finished attempt.
     *
     * CAS via the UPDATE's own WHERE clause + $wpdb->rows_affected, exactly
     * the same pattern RuleModel's reservation methods use: only proceed if
     * this UPDATE is the one that actually changed a row (===1), never a
     * separate SELECT-then-UPDATE that could itself race against a second
     * concurrent reclaim attempt.
     *
     * Also re-stamps reveal_mode to $reveal_mode: a reclaim IS a fresh
     * attempt from the visitor's point of view, and the plan's schema notes
     * reveal_mode is recorded "at submission time" precisely so the admin
     * history stays accurate even if the merchant flips the global setting
     * later — a reclaim deserves the same treatment as a brand-new insert.
     *
     * Same reasoning extends to ip_hash/user_agent/source_url (round-4 audit
     * fix): a reclaim is a fresh attempt, possibly by a completely different
     * visitor than whoever originally inserted the abandoned row (e.g. a
     * different person re-registering the same email address after the
     * original claimant's attempt crashed and the 60s window lapsed). Only
     * re-stamping status/reveal_mode/claimed_at while leaving the ORIGINAL
     * submitter's audit-trail columns in place would misattribute who
     * actually completed the registration in the admin "Registros del
     * popup" table and CSV export.
     *
     * @param string $email
     * @param string $reveal_mode PopupModel::REVEAL_INSTANT|REVEAL_CONFIRMED.
     * @param string $ip_hash     Salted hash for THIS request, not the
     *                             original claimant's.
     * @param string $user_agent  Already truncated by the caller.
     * @param string $source_url  Already truncated by the caller.
     * @return bool True if this call is the one that reclaimed the row.
     */
    public static function reclaim_abandoned_claim($email, $reveal_mode, $ip_hash, $user_agent, $source_url)
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - self::ABANDONED_CLAIM_SECONDS);
        $now    = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . "
             SET status = %s, reveal_mode = %s, claimed_at = %s, ip_hash = %s, user_agent = %s, source_url = %s
             WHERE email = %s AND status = %s AND claimed_at < %s",
            self::STATUS_CLAIMED,
            $reveal_mode,
            $now,
            $ip_hash,
            $user_agent,
            $source_url,
            $email,
            self::STATUS_CLAIMED,
            $cutoff
        ));

        return 1 === (int)$wpdb->rows_affected;
    }

    /**
     * @param string $email
     * @return array|null
     */
    public static function get_by_email($email)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE email = %s LIMIT 1',
            $email
        ), ARRAY_A);

        return $row ? self::format_row($row) : null;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function get_by_id($id)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
            (int)$id
        ), ARRAY_A);

        return $row ? self::format_row($row) : null;
    }

    /**
     * @param string $token_hash sha256 hex digest of the raw emailed token.
     * @return array|null
     */
    public static function get_by_token_hash($token_hash)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE confirm_token = %s LIMIT 1',
            $token_hash
        ), ARRAY_A);

        return $row ? self::format_row($row) : null;
    }

    /**
     * Move a 'claimed' row into 'pending' (double opt-in path): stamps the
     * hashed confirmation token + its expiry.
     *
     * @param int    $id
     * @param string $token_hash sha256 hex digest — the raw token itself is
     *                            never persisted, only ever lives in the
     *                            emailed link (same "store a hash, not the
     *                            secret" principle as a password reset key).
     * @param string $expires_at_mysql
     * @return bool
     */
    public static function mark_pending($id, $token_hash, $expires_at_mysql)
    {
        global $wpdb;
        $updated = $wpdb->update(
            self::table(),
            array(
                'status'           => self::STATUS_PENDING,
                'confirm_token'    => $token_hash,
                'token_expires_at' => $expires_at_mysql,
            ),
            array('id' => (int)$id)
        );

        return false !== $updated;
    }

    /**
     * Move a row into its terminal 'issued' state once a real WC_Coupon has
     * been minted for it (both the instant-reveal path and the confirmed
     * double opt-in path land here).
     *
     * CAS via the UPDATE's own WHERE clause (round-3 audit fix): only
     * transitions a row that is STILL 'claimed', exactly the same
     * CAS-via-UPDATE-WHERE-clause pattern already used by
     * reclaim_abandoned_claim()/claim_pending_token() in this same class.
     * Without this guard, two concurrent winners for the SAME row (e.g. the
     * original request that inserted the claim, merely slow rather than
     * dead, racing a second request that legitimately reclaimed the row via
     * reclaim_abandoned_claim() after ABANDONED_CLAIM_SECONDS) would each
     * mint their OWN real WC_Coupon and then both call this method
     * unconditionally: the loser's write would silently succeed anyway
     * (plain $wpdb->update() by id has no state check), overwriting the
     * winner's coupon_id/coupon_code with its own and leaving the winner's
     * coupon a live, redeemable, single-use code with no row pointing back
     * to it -- invisible to the admin submissions list and CSV export. With
     * the guard, only the FIRST caller to reach this method for a given row
     * wins; the caller (PopupController) is responsible for voiding its own
     * just-minted coupon when this returns false -- see
     * PopupController::void_orphan_coupon().
     *
     * @param int    $id
     * @param int    $coupon_id
     * @param string $coupon_code
     * @return bool True only if THIS call is the one that transitioned the
     *              row from 'claimed' to 'issued'.
     */
    public static function mark_issued($id, $coupon_id, $coupon_code)
    {
        global $wpdb;
        $now = current_time('mysql', true);

        $updated = $wpdb->update(
            self::table(),
            array(
                'status'       => self::STATUS_ISSUED,
                'coupon_id'    => (int)$coupon_id,
                'coupon_code'  => $coupon_code,
                'revealed_at'  => $now,
                // confirmed_at is only meaningful for the double opt-in
                // path; harmless (and correct) to also stamp it for the
                // instant path, where "confirmed" and "revealed" are the
                // same instant by definition.
                'confirmed_at' => $now,
            ),
            array('id' => (int)$id, 'status' => self::STATUS_CLAIMED)
        );

        return 1 === (int)$updated;
    }

    /**
     * Atomically flip a 'pending' row (with a still-valid token) to
     * 'claimed', i.e. "this exact request won the right to mint the coupon".
     * Same CAS-via-UPDATE-WHERE-clause pattern as reclaim_abandoned_claim():
     * mint_coupon() is only ever called by the ONE request for which this
     * returns true, closing the "email link-tracker GETs it first" race
     * described in the plan (PopupController::confirm() step).
     *
     * @param string $token_hash
     * @return bool True if THIS call is the one that won the claim.
     */
    public static function claim_pending_token($token_hash)
    {
        global $wpdb;
        $now = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . "
             SET status = %s
             WHERE confirm_token = %s AND status = %s AND token_expires_at > %s",
            self::STATUS_CLAIMED,
            $token_hash,
            self::STATUS_PENDING,
            $now
        ));

        return 1 === (int)$wpdb->rows_affected;
    }

    /**
     * Daily janitorial cleanup (WP-Cron 'drw_popup_release_stale_claims',
     * scheduled by Router::run_migrations()/cleared by
     * Router::deactivate_plugin(), handled here — see
     * PopupController::register_hooks()):
     *
     *  - 'pending' rows whose confirmation token has expired (the visitor
     *    never clicked the email link) are deleted outright — nothing was
     *    ever minted for them, so there is no coupon to reconcile, and
     *    deleting frees the email for a fresh attempt instead of leaving it
     *    permanently blocked by the UNIQUE(email) key.
     *  - 'claimed' rows abandoned long past ABANDONED_CLAIM_SECONDS (the
     *    per-request reclaim window) AND past DEAD_CLAIM_SECONDS are also
     *    deleted — same reasoning, for a visitor who crashed out of the
     *    instant-reveal path and never returned to retry at all.
     *
     * @return void
     */
    public static function purge_stale_rows()
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $dead_claim_cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - self::DEAD_CLAIM_SECONDS);

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE status = %s AND token_expires_at IS NOT NULL AND token_expires_at < %s',
            self::STATUS_PENDING,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE status = %s AND claimed_at < %s',
            self::STATUS_CLAIMED,
            $dead_claim_cutoff
        ));

        // Round-5 audit fix (see try_reserve_daily_mint_slot() below): the
        // day-keyed wp_options counter rows that method creates accumulate
        // one new row per day forever if never cleaned up (harmless to
        // correctness -- each day's cap check only ever reads/writes ITS
        // OWN day's key -- but still unbounded row growth). Piggybacks on
        // this same daily cron rather than adding a second scheduled event
        // for one cheap DELETE.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
            $wpdb->esc_like(self::DAILY_MINT_OPTION_PREFIX) . '%',
            self::DAILY_MINT_OPTION_PREFIX . gmdate('Y-m-d', current_time('timestamp', true) - 7 * DAY_IN_SECONDS)
        ));
    }

    /**
     * wp_options option_name prefix for try_reserve_daily_mint_slot()'s
     * day-keyed atomic counter -- see that method's docblock. A real
     * WordPress core table, not a new one, so this needs no schema-change
     * approval; the day-key suffix (Y-m-d) is appended by
     * daily_mint_option_name() below.
     */
    const DAILY_MINT_OPTION_PREFIX = 'drw_popup_daily_mint_';

    /**
     * Atomically reserve one slot in the global daily coupon-mint cap
     * (round-5 audit fix -- plan's Anti-abuso §2 / PopupController's
     * GLOBAL_DAILY_BUCKET; round-6 audit fix moved the CALL SITE to
     * immediately before each real PopupCouponBridge::mint_coupon() call --
     * see PopupController::reserve_daily_mint_slot()'s docblock -- so this
     * now counts coupons actually about to be minted, not mere submission
     * attempts). RateLimiter::check() is a plain
     * get_transient()+set_transient() read-modify-write (see that class's
     * own "Not atomic" docblock note) -- an accepted trade-off for the IP/
     * email buckets, which are inherently evadable by rotating IPs or
     * disposable domains regardless of atomicity, but NOT acceptable for
     * this bucket: its entire stated purpose (plan's Anti-abuso §2) is to be
     * a hard, site-wide emergency brake against exactly a burst of many
     * concurrent, distinct-IP/distinct-email requests -- the one scenario a
     * read-modify-write counter cannot cap correctly (a burst near the
     * boundary can all read the same pre-increment count and all be
     * admitted, overshooting the cap by a multiple of the burst size).
     *
     * Uses wp_options as a day-keyed counter, incremented via a single
     * UPDATE ... WHERE <value> < $cap statement. A single UPDATE statement
     * is atomic under InnoDB row-level locking: two concurrent UPDATEs
     * against the SAME row serialize (the second blocks until the first's
     * row lock releases at statement commit), so the "< cap" condition and
     * the increment are evaluated as one indivisible unit for every caller
     * -- same CAS-via-UPDATE-WHERE-clause-plus-rows_affected idiom already
     * used by mark_issued()/claim_pending_token() above, just applied to a
     * counter row instead of a status column.
     *
     * @param int $daily_cap
     * @return bool True if a slot was reserved (the request may proceed),
     *              false if today's cap is already reached.
     */
    public static function try_reserve_daily_mint_slot($daily_cap)
    {
        global $wpdb;
        $daily_cap   = max(1, (int)$daily_cap);
        $option_name = self::daily_mint_option_name();

        // Ensure today's counter row exists. Safe no-op if a concurrent
        // request already created it a moment ago (wp_options.option_name
        // has its own UNIQUE index -- that, not this PHP-level check, is
        // what makes this race-free): INSERT IGNORE simply does nothing on
        // a duplicate-key collision instead of erroring.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
            $option_name
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            $option_name,
            $daily_cap
        ));

        return 1 === (int)$wpdb->rows_affected;
    }

    /**
     * @return string Today's (GMT) counter option name for
     *                 try_reserve_daily_mint_slot().
     */
    private static function daily_mint_option_name()
    {
        return self::DAILY_MINT_OPTION_PREFIX . gmdate('Y-m-d', current_time('timestamp', true));
    }

    /**
     * ROUND-9 AUDIT FIX: release a previously-reserved slot in the global
     * daily-mint cap (try_reserve_daily_mint_slot()'s counter). Both call
     * sites in PopupController (the INSTANT branch of process_submit() and
     * the CONFIRMED branch of resolve_confirm_redirect_url()) reserve a slot
     * BEFORE calling PopupCouponBridge::mint_coupon(), but mint_coupon() can
     * still fail afterwards -- either by returning a WP_Error (its
     * generate_unique_code() exhausting MAX_ATTEMPTS collisions -- vanishingly
     * unlikely given the 32^8 code space, but a real, reachable path,
     * especially with an unusually low merchant-configured daily_mint_cap),
     * or by succeeding but then losing the mark_issued() CAS to a concurrent
     * winner, in which case PopupController::void_orphan_coupon() deletes
     * this request's own just-minted coupon (see mark_issued()'s docblock for
     * that race). Without this method, EITHER failure mode permanently burns
     * one unit of that day's cap for a coupon that was never actually
     * delivered to a customer -- silently shrinking the merchant-configured
     * "Máximo de cupones emitidos por día" for the rest of the day. Prior to
     * this fix there was no decrement counterpart to
     * try_reserve_daily_mint_slot() at all -- the counter only ever reset at
     * the next day's purge_stale_rows() cron cleanup.
     *
     * Same day-keyed wp_options counter, decremented via a single atomic
     * UPDATE ... WHERE <value> > 0 statement -- the "> 0" guard is a
     * belt-and-suspenders floor so the counter can never go negative even
     * under a pathological sequence of concurrent releases (e.g. a release
     * for a reservation made just before GMT midnight arriving just after --
     * daily_mint_option_name() is recomputed fresh here, so a release that
     * lands on the wrong side of the day boundary from its own reservation
     * simply targets a DIFFERENT (already-zero or non-existent) day's key
     * and is a safe no-op, never an incorrect decrement of the new day's
     * counter). This is the same CAS-via-UPDATE-WHERE-clause idiom used
     * throughout this class, just applied as a floor instead of a ceiling.
     *
     * Best-effort by design, like void_orphan_coupon() itself: called only
     * from an already-failing code path that has its own fallback response
     * to return regardless of whether this succeeds, so no return value is
     * needed -- a lost race against a concurrent purge_stale_rows() cleanup
     * of a stale option row, though not expected in practice, would at worst
     * leave the counter one slot short of fully reclaimed, never corrupt it.
     *
     * @return void
     */
    public static function release_daily_mint_slot()
    {
        global $wpdb;
        $option_name = self::daily_mint_option_name();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = option_value - 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) > 0",
            $option_name
        ));
    }

    /**
     * Hard cap on a single CSV export — a "just in case" ceiling, not an
     * expected real-world row count for a single-site popup, matching the
     * spirit of the other admin-facing hard caps in this codebase (e.g.
     * ProductCategoryPicker's per_page cap). A store that genuinely exceeds
     * this can re-export after narrowing the underlying data (there is no
     * date-range filter on the admin list today — out of scope for this
     * phase, flagged in the delivery report).
     */
    const EXPORT_LIMIT = 10000;

    /**
     * Page of rows for the admin "Registros" table
     * (PopupController::get_submissions()/drw-popup-submissions.js), newest
     * first.
     *
     * @param int $page     1-based.
     * @param int $per_page Clamped to [1,100].
     * @return array{items:array,total:int,page:int,per_page:int}
     */
    public static function get_paginated($page, $per_page)
    {
        global $wpdb;

        $page     = max(1, (int)$page);
        $per_page = max(1, min(100, (int)$per_page));
        $offset   = ($page - 1) * $per_page;

        $total = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
            $per_page,
            $offset
        ), ARRAY_A);

        return array(
            'items'    => is_array($rows) ? array_map(array(__CLASS__, 'format_row'), $rows) : array(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }

    /**
     * All rows for the CSV export (PopupController::handle_csv_export()),
     * newest first, capped at EXPORT_LIMIT.
     *
     * @return array
     */
    public static function get_all_for_export()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC, id DESC LIMIT %d',
            self::EXPORT_LIMIT
        ), ARRAY_A);

        return is_array($rows) ? array_map(array(__CLASS__, 'format_row'), $rows) : array();
    }

    /**
     * @param array $row
     * @return array
     */
    private static function format_row($row)
    {
        $row['id']         = (int)$row['id'];
        $row['coupon_id']  = !empty($row['coupon_id']) ? (int)$row['coupon_id'] : null;

        return $row;
    }
}
