<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

class RuleModel
{
    /**
     * Get all active and enabled rules, sorted by priority.
     *
     * @return array Array of formatted rules
     */
    public static function get_active_rules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $now = time();

        $query = "SELECT * FROM $table 
                  WHERE enabled = 1 AND deleted = 0 
                  ORDER BY priority ASC, id ASC";

        $results = $wpdb->get_results($query, ARRAY_A);
        $active_rules = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                // Check date limits if set
                if (!empty($row['date_from']) && $now < (int)$row['date_from']) {
                    continue;
                }
                if (!empty($row['date_to']) && $now > (int)$row['date_to']) {
                    continue;
                }

                // Check usage limit
                if (!empty($row['usage_limit']) && (int)$row['used_count'] >= (int)$row['usage_limit']) {
                    continue;
                }

                $active_rules[] = self::format_rule($row);
            }
        }

        return $active_rules;
    }

    /**
     * Get all rules (active, inactive, deleted=0) for Admin display.
     */
    public static function get_all_rules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        $query = "SELECT * FROM $table WHERE deleted = 0 ORDER BY priority ASC, id ASC";
        $results = $wpdb->get_results($query, ARRAY_A);
        $rules = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                $rules[] = self::format_rule($row);
            }
        }

        return $rules;
    }

    /**
     * Find a single rule by ID.
     */
    public static function get_rule($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND deleted = 0", $id), ARRAY_A);

        return $row ? self::format_rule($row) : null;
    }

    /**
     * Save or update a rule.
     */
    public static function save_rule($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $data = self::sanitize_rule_payload($data);

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        $db_data = [
            'enabled'            => isset($data['enabled']) ? (int)$data['enabled'] : 1,
            'deleted'            => 0,
            'exclusive'          => isset($data['exclusive']) ? (int)$data['exclusive'] : 0,
            'exclude_sale_items' => isset($data['exclude_sale_items']) ? (int)$data['exclude_sale_items'] : 0,
            'title'              => sanitize_text_field($data['title']),
            'priority'           => isset($data['priority']) ? (int)$data['priority'] : 10,
            'apply_to'           => sanitize_text_field($data['apply_to']),
            'filters'            => $json_encode($data['filters']),
            'conditions'         => $json_encode($data['conditions']),
            'adjustments'        => $json_encode($data['adjustments']),
            'date_from'          => !empty($data['date_from']) ? (int)$data['date_from'] : null,
            'date_to'            => !empty($data['date_to']) ? (int)$data['date_to'] : null,
            'usage_limit'        => !empty($data['usage_limit']) ? (int)$data['usage_limit'] : null,
            'limit_user'         => !empty($data['limit_user']) ? (int)$data['limit_user'] : null,
            'modified_at'        => current_time('mysql'),
        ];

        if ($id) {
            $wpdb->update($table, $db_data, ['id' => $id]);
            return $id;
        } else {
            $db_data['created_at'] = current_time('mysql');
            $db_data['used_count'] = 0;
            $wpdb->insert($table, $db_data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Normalize rule payloads before persistence.
     */
    public static function sanitize_rule_payload($data)
    {
        $data = is_array($data) ? $data : [];
        $allowed_apply_to = ['all_products', 'specific_products', 'specific_categories'];
        $apply_to = !empty($data['apply_to']) ? sanitize_text_field($data['apply_to']) : 'all_products';
        if (!in_array($apply_to, $allowed_apply_to, true)) {
            $apply_to = 'all_products';
        }

        $data['title'] = !empty($data['title']) ? sanitize_text_field($data['title']) : '';
        $data['apply_to'] = $apply_to;
        $data['filters'] = self::sanitize_filters(!empty($data['filters']) && is_array($data['filters']) ? $data['filters'] : []);
        $data['conditions'] = self::sanitize_conditions(!empty($data['conditions']) && is_array($data['conditions']) ? $data['conditions'] : []);
        $data['adjustments'] = self::sanitize_adjustments(!empty($data['adjustments']) && is_array($data['adjustments']) ? $data['adjustments'] : []);

        return $data;
    }

    /**
     * Mark a rule as deleted (soft delete).
     */
    public static function delete_rule($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        return $wpdb->update($table, ['deleted' => 1], ['id' => (int)$id]);
    }

    /**
     * Increment usage limit counter for a rule.
     *
     * NOTE: not called by the reservation system (try_reserve_usage() itself
     * increments used_count as part of its own atomic transaction for any
     * rule with usage_limit/limit_user configured — see CartController's
     * reserve_promo_usage(), which now covers both promo-compiled rules and
     * manually-authored ones). Kept as a standalone utility for any future
     * non-reservation caller; not currently invoked anywhere in this plugin.
     */
    public static function increment_usage($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $wpdb->query($wpdb->prepare("UPDATE $table SET used_count = used_count + 1 WHERE id = %d", $id));
    }

    /**
     * Count how many active (reserved or confirmed) redemptions a given
     * customer already holds against a rule. Used to enforce a rule's
     * per-customer limit_user column.
     *
     * @param int    $rule_id
     * @param string $customer_key 'user:<id>' or 'email:<normalized email>', see CustomerIdentity.
     * @return int
     */
    public static function customer_redemption_count($rule_id, $customer_key)
    {
        global $wpdb;
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $redemptions_table WHERE rule_id = %d AND customer_key = %s AND status IN ('reserved','confirmed')",
            $rule_id,
            $customer_key
        ));
    }

    /**
     * Atomically reserve one usage slot for a rule against a given order and
     * customer, enforcing both the rule's global usage_limit and its
     * per-customer limit_user column. Closes the TOCTOU race that a plain
     * "check COUNT then INSERT" would leave open under concurrent checkouts.
     *
     * Uses a real SQL transaction with SELECT ... FOR UPDATE row locking on
     * the rule row so the usage_limit/limit_user check and the used_count
     * increment + redemption insert happen as one atomic unit. Requires
     * InnoDB (WordPress's dbDelta creates InnoDB tables by default, see
     * $wpdb->get_charset_collate() usage in Database::create_tables()).
     *
     * The UNIQUE KEY (order_id, rule_id) on wp_drw_promo_redemptions makes a
     * second reservation attempt for the same order+rule combo a safe no-op
     * failure (idempotent re-processing of the same order), reported via the
     * $inserted check below rather than a distinct code path.
     *
     * NOTE on transaction nesting: this assumes no caller already has an open
     * transaction on this connection when try_reserve_usage() is invoked.
     * WooCommerce's HPOS (Custom Order Tables) order data store does wrap its
     * own order CRUD in START TRANSACTION/COMMIT; the checkout-hooks caller
     * (part 2 of this work) must be wired to run outside of that window (e.g.
     * on 'woocommerce_checkout_order_processed' / 'woocommerce_new_order'
     * after the order row is fully persisted) rather than from inside an
     * order data-store save. A plain "START TRANSACTION" issued while another
     * transaction is already open on the same connection causes MySQL to
     * implicitly COMMIT the outer transaction first, which would be
     * incorrect here.
     *
     * @param int      $rule_id
     * @param string   $customer_key 'user:<id>' or 'email:<normalized email>', see CustomerIdentity.
     * @param int      $order_id
     * @param int|null $promo_id Nullable: manually-authored rules (source IS NULL) have no promo_id.
     * @return bool True if the slot was reserved, false if denied or on error.
     */
    public static function try_reserve_usage($rule_id, $customer_key, $order_id, $promo_id = null)
    {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'drw_rules';
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        try {
            $started = $wpdb->query('START TRANSACTION');

            // If the transaction itself never opened, every statement below
            // would auto-commit independently and the FOR UPDATE lock would
            // release the instant its own SELECT completes -- reopening the
            // exact TOCTOU race this method exists to close, with a later
            // ROLLBACK becoming a silent no-op on top of that. Bail out
            // before issuing anything else rather than proceed unprotected.
            if (false === $started) {
                return false;
            }

            // Fingerprint of the connection this transaction actually opened
            // on. wpdb's own "MySQL server has gone away" handling
            // (check_connection() in wp-includes/class-wpdb.php) transparently
            // opens a NEW connection and retries the failed statement on it
            // when the server drops the link mid-request -- silently, with
            // $wpdb->last_error left empty if the retry itself succeeds. If
            // that happens between here and COMMIT, every statement below
            // would then be running on a connection that never opened this
            // transaction: the FOR UPDATE lock (if retried) auto-commits and
            // releases instantly instead of holding until our COMMIT, and a
            // retried COMMIT lands as a harmless no-op on a connection with
            // nothing open, reporting false success. connection_fingerprint()
            // is re-checked at every step below so a reconnect is treated as
            // a denial rather than trusted to have preserved atomicity.
            $conn_id = self::connection_fingerprint($wpdb);

            // Re-filters deleted = 0 here too, not just relying on the
            // caller's own get_rule() gate (RuleModel::get_rule() already
            // scopes to deleted = 0 -- see e.g. CartController's
            // reserve_promo_usage()/attempt_rule_reservation() callers): a
            // rule soft-deleted in the narrow window between that earlier
            // check and this method's own row lock must not have a slot
            // reserved/counted against it (round-7 audit finding). A rule
            // deleted in that window now falls through the "!$rule" branch
            // below exactly like a genuinely nonexistent id.
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT usage_limit, used_count, limit_user FROM $rules_table WHERE id = %d AND deleted = 0 FOR UPDATE",
                $rule_id
            ), ARRAY_A);

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!$rule) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if ($rule['usage_limit'] !== null && (int) $rule['used_count'] >= (int) $rule['usage_limit']) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!empty($rule['limit_user'])) {
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $redemptions_table WHERE rule_id = %d AND customer_key = %s AND status IN ('reserved','confirmed')",
                    $rule_id,
                    $customer_key
                ));

                if ($wpdb->last_error) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }

                if (!self::connection_still_open($wpdb, $conn_id)) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }

                if ($existing >= (int) $rule['limit_user']) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
            }

            $wpdb->query($wpdb->prepare("UPDATE $rules_table SET used_count = used_count + 1 WHERE id = %d", $rule_id));

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                // Unlike a reconnect caught at any earlier checkpoint (SELECT
                // ... FOR UPDATE, the limit_user COUNT) -- where the whole
                // not-yet-committed transaction simply dies with the dropped
                // connection, so nothing leaks -- reaching THIS checkpoint
                // with a fingerprint mismatch means the used_count UPDATE
                // itself was the statement wpdb's check_connection() silently
                // retried after the drop. Every checkpoint before it already
                // passed on the connection $conn_id was captured from, and
                // $wpdb->last_error is empty here, so the retried UPDATE
                // genuinely executed -- but on a brand-new, auto-commit
                // connection that never opened our transaction. That
                // increment is now durable and permanent on its own,
                // completely outside this (dead) transaction; the ROLLBACK
                // below is a no-op against it. No redemption row exists yet
                // (we return before the INSERT), so the 48h stale-reservation
                // cron has no row to key a compensation off, either --
                // without action here this is a silent, untraceable
                // used_count leak. Issue a best-effort compensating decrement
                // on the now-current connection before reporting failure.
                $wpdb->query($wpdb->prepare(
                    "UPDATE $rules_table SET used_count = GREATEST(0, used_count - 1) WHERE id = %d",
                    $rule_id
                ));
                $wpdb->query('ROLLBACK');
                return false;
            }

            $inserted = $wpdb->insert($redemptions_table, [
                'promo_id'     => $promo_id,
                'rule_id'      => $rule_id,
                'order_id'     => $order_id,
                'customer_key' => $customer_key,
                'status'       => 'reserved',
                'created_at'   => current_time('mysql'),
            ]);

            if (!$inserted || $wpdb->last_error) {
                // Most likely the UNIQUE(order_id, rule_id) collision from a
                // re-processed order -- treat as "already reserved", not an error.
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                // The INSERT itself reported success, but on a fresh
                // reconnected (auto-commit) handle rather than inside our
                // transaction -- it already landed durably and outside our
                // control, uncoupled from the UPDATE above. Roll back what we
                // can on THIS (stale) handle -- a no-op, since the real work
                // already escaped it -- and report failure so the caller
                // never treats this as a trustworthy atomic reservation.
                $wpdb->query('ROLLBACK');
                return false;
            }

            $committed = $wpdb->query('COMMIT');

            if (false === $committed || $wpdb->last_error) {
                // COMMIT itself failed (lock-wait timeout, connection drop,
                // etc.) -- the transaction's actual fate at the server is now
                // uncertain, so this must NOT report success back to the
                // caller (which would otherwise mark the order as reserved
                // and let checkout proceed believing the slot durably held).
                // A defensive ROLLBACK is issued: MySQL treats it as a no-op
                // if COMMIT actually landed server-side despite the client
                // seeing an error, and correctly discards anything left open
                // if it did not.
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                // The rare case Finding round 3 flagged: the connection
                // dropped and reconnected exactly at COMMIT. wpdb's retry
                // re-issues "COMMIT" on the fresh connection, where it has
                // nothing open and MySQL treats it as a harmless no-op
                // success -- while the UPDATE/INSERT this method just ran
                // were rolled back server-side along with the torn-down
                // connection that held them. $committed/$wpdb->last_error
                // alone cannot see this; only the fingerprint mismatch can.
                // Report failure rather than a false "reserved".
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        } catch (\Throwable $e) {
            // PHP 7+: catch fatal-ish errors too (e.g. \Error) so a bug here
            // can never leave a hanging transaction on the connection.
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Release a previously reserved/confirmed usage slot for a rule+order,
     * decrementing the rule's used_count back down. Idempotent: calling this
     * twice for the same order_id only decrements once, because the second
     * DELETE removes zero rows and the used_count decrement is skipped.
     *
     * Wrapped in the same START TRANSACTION/COMMIT/ROLLBACK pattern as
     * try_reserve_usage(), for the same reason: the DELETE and the UPDATE
     * must land together. Without a transaction, a worker killed/timed-out/
     * OOM'd between the two (both realistic here -- this runs from order
     * status-transition hooks during payment-gateway webhook handling, and
     * from a daily cron sweep) would permanently leave the redemption row
     * gone but used_count never decremented, ratcheting the rule's apparent
     * usage upward forever (a slow-burn false "exhausted" state for future
     * customers). See the same transaction-nesting caveat documented on
     * try_reserve_usage(): callers must not invoke this from inside another
     * already-open transaction on this connection.
     *
     * @param int $rule_id
     * @param int $order_id
     * @return bool True if a reservation was found and released, false otherwise.
     */
    public static function release_usage($rule_id, $order_id)
    {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'drw_rules';
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        try {
            $started = $wpdb->query('START TRANSACTION');

            if (false === $started) {
                return false;
            }

            // Same reconnect-mid-transaction defence as try_reserve_usage() --
            // see that method's docblock/comments for the full explanation.
            $conn_id = self::connection_fingerprint($wpdb);

            $wpdb->query($wpdb->prepare(
                "DELETE FROM $redemptions_table WHERE rule_id = %d AND order_id = %d AND status IN ('reserved','confirmed')",
                $rule_id,
                $order_id
            ));

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if ((int) $wpdb->rows_affected === 0) {
                // Nothing to release -- either never reserved, or already
                // released by a prior call. Never decrement used_count in
                // that case; nothing was changed, so ROLLBACK is just a
                // clean way to close the transaction we opened above.
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE $rules_table SET used_count = GREATEST(0, used_count - 1) WHERE id = %d",
                $rule_id
            ));

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $committed = $wpdb->query('COMMIT');

            if (false === $committed || $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Release a STILL-reserved (never confirmed) usage slot for a rule+order,
     * for the stale-reservation cron sweep only. Identical transaction shape
     * to release_usage() above, except the DELETE is scoped to
     * status = 'reserved' rather than status IN ('reserved','confirmed').
     *
     * Why this exists as a separate method instead of reusing release_usage():
     * the cron (CartController::release_stale_promo_reservations()) takes a
     * SELECT ... WHERE status = 'reserved' AND created_at < cutoff snapshot,
     * then iterates it calling a release for each row. If the underlying
     * order is paid (and thus confirm_usage()'d to 'confirmed') by a
     * concurrent request between that snapshot and this row's turn in the
     * loop, release_usage()'s DELETE would still match it (it also accepts
     * 'confirmed', which it genuinely needs for its OWN callers --
     * release_reserved_usage() on cancelled/failed orders must release a
     * confirmed row too, e.g. a payment that was captured then reversed) and
     * silently erase a now-legitimate paid order's redemption row, wrongly
     * decrementing used_count and under-counting the rule's
     * usage_limit/limit_user. Scoping this DELETE to 'reserved' only makes it
     * a safe no-op (0 rows affected, used_count left untouched) against a row
     * that has since become 'confirmed' -- the query itself simply no longer
     * matches it, closing the race without needing a separate re-check step
     * that would still leave its own smaller TOCTOU gap.
     *
     * @param int $rule_id
     * @param int $order_id
     * @return bool True if a still-reserved row was found and released, false otherwise.
     */
    public static function release_stale_usage($rule_id, $order_id)
    {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'drw_rules';
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        try {
            $started = $wpdb->query('START TRANSACTION');

            if (false === $started) {
                return false;
            }

            // Same reconnect-mid-transaction defence as try_reserve_usage() --
            // see that method's docblock/comments for the full explanation.
            $conn_id = self::connection_fingerprint($wpdb);

            $wpdb->query($wpdb->prepare(
                "DELETE FROM $redemptions_table WHERE rule_id = %d AND order_id = %d AND status = 'reserved'",
                $rule_id,
                $order_id
            ));

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if ((int) $wpdb->rows_affected === 0) {
                // Nothing to release -- either never reserved, already
                // released, or (the race this method exists to close)
                // already confirmed by a concurrent paid order. Never
                // decrement used_count in that case.
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE $rules_table SET used_count = GREATEST(0, used_count - 1) WHERE id = %d",
                $rule_id
            ));

            if ($wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $committed = $wpdb->query('COMMIT');

            if (false === $committed || $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            if (!self::connection_still_open($wpdb, $conn_id)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Mark a reserved redemption as confirmed once the order it belongs to
     * is genuinely paid. Called by the checkout-hooks integration (not wired
     * here); a no-op if no matching 'reserved' row exists (e.g. already
     * confirmed, or already released).
     *
     * Accepted tradeoff (round-7 audit finding, kept documented rather than
     * "fixed" -- see release_stale_usage()'s docblock for the ordering this
     * DOES already close): if the 48h stale-reservation cron's DELETE for
     * this exact rule_id/order_id row commits in the narrow window BEFORE a
     * concurrently-running confirm here reaches it, this UPDATE's own
     * `WHERE ... status = 'reserved'` simply matches zero rows -- there is no
     * row left to tell "never reserved" apart from "reserved, then swept out
     * from under a genuine same-instant confirm". The caller
     * (CartController::track_promo_redemptions()) does not gate on this
     * method's return value, so the order is still correctly flagged
     * '_drw_promos_counted'; the only effect is a rule's used_count staying
     * one lower than it should, silently granting one extra slot of headroom
     * to a FUTURE customer -- not a checkout-time security bug, not
     * attacker-controllable (the "beneficiary" is an unrelated later
     * customer), and bounded to the exact 48h cutoff instant. Closing it for
     * real would require the cron to durably record which redemption rows it
     * swept so a losing confirm_usage() could tell the two cases apart and
     * compensate -- more machinery than this narrow, non-exploitable edge
     * case currently justifies; revisit if the cron's cutoff window is ever
     * shortened enough to make the collision non-negligible in practice.
     *
     * @param int $rule_id
     * @param int $order_id
     * @return bool True if a row was updated.
     */
    public static function confirm_usage($rule_id, $order_id)
    {
        global $wpdb;
        $redemptions_table = $wpdb->prefix . 'drw_promo_redemptions';

        $wpdb->query($wpdb->prepare(
            "UPDATE $redemptions_table SET status = 'confirmed' WHERE rule_id = %d AND order_id = %d AND status = 'reserved'",
            $rule_id,
            $order_id
        ));

        return (int) $wpdb->rows_affected > 0;
    }

    /**
     * Identity fingerprint of $wpdb's CURRENT live connection handle, used by
     * try_reserve_usage()/release_usage() to detect wpdb's own transparent
     * "MySQL server has gone away" reconnect-and-retry
     * (wp-includes/class-wpdb.php check_connection()) happening mid-
     * transaction. A reconnect swaps $wpdb->dbh for a brand-new
     * (auto-commit) connection object and retries the failed statement on
     * it -- silently, with $wpdb->last_error left empty if the retry itself
     * succeeds -- which would otherwise let a transaction opened on the OLD
     * handle be reported as committed/rolled-back on a handle that never
     * held it.
     *
     * Returns null (never matches a real fingerprint, so
     * connection_still_open() only reports true against another null) when
     * $wpdb->dbh isn't a live object -- e.g. a test stub with no such
     * property, or a legacy non-mysqli driver -- so this defence degrades to
     * a safe no-op rather than a false positive on setups it can't inspect.
     *
     * For a genuine mysqli handle, the MySQL server-assigned thread_id is
     * used in preference to PHP's own spl_object_id(). spl_object_id()
     * identifies an object SLOT, not a connection: check_connection()'s
     * reconnect does `$this->dbh = null;` (dropping the only reference, so
     * the old object is destroyed and its slot freed immediately) followed
     * essentially at once by `$this->dbh = mysqli_init();` (allocating a
     * fresh object) -- exactly the destroy-then-immediately-recreate pattern
     * under which PHP is free to hand the new object the very slot ID the
     * old one just vacated. If that happens, spl_object_id() alone would
     * false-negative a genuine reconnect as "still the same connection",
     * silently defeating this whole defence. mysqli's public $thread_id
     * property is instead assigned by the MySQL server itself per
     * connection, so it is guaranteed to differ across any two distinct
     * connections regardless of what PHP does with object slot reuse, and
     * reading it (a plain property access) cannot throw even against a
     * closed/dropped handle the way calling a mysqli method could.
     *
     * @param object $wpdb
     * @return string|int|null
     */
    private static function connection_fingerprint($wpdb)
    {
        if (!isset($wpdb->dbh) || !is_object($wpdb->dbh)) {
            return null;
        }

        if ($wpdb->dbh instanceof \mysqli) {
            return 'mysqli:' . $wpdb->dbh->thread_id;
        }

        return spl_object_id($wpdb->dbh);
    }

    /**
     * Whether $wpdb is still running on the SAME connection handle whose
     * fingerprint was captured right after START TRANSACTION. See
     * connection_fingerprint() for the reconnect scenario this guards
     * against.
     *
     * @param object   $wpdb
     * @param int|null $expected_conn_id Fingerprint captured at transaction start.
     * @return bool
     */
    private static function connection_still_open($wpdb, $expected_conn_id)
    {
        return self::connection_fingerprint($wpdb) === $expected_conn_id;
    }

    /**
     * Format DB row values into PHP arrays/objects.
     */
    private static function format_rule($row)
    {
        $row['id']          = (int)$row['id'];
        $row['enabled']     = (int)$row['enabled'] === 1;
        $row['deleted']     = (int)$row['deleted'] === 1;
        $row['exclusive']   = (int)$row['exclusive'] === 1;
        $row['exclude_sale_items'] = (int)$row['exclude_sale_items'] === 1;
        $row['priority']    = (int)$row['priority'];
        $row['usage_limit'] = !empty($row['usage_limit']) ? (int)$row['usage_limit'] : null;
        $row['used_count']  = (int)$row['used_count'];
        $row['date_from']   = !empty($row['date_from']) ? (int)$row['date_from'] : null;
        $row['date_to']     = !empty($row['date_to']) ? (int)$row['date_to'] : null;
        $row['limit_user']  = !empty($row['limit_user']) ? (int)$row['limit_user'] : null;
        $row['promo_id']    = !empty($row['promo_id']) ? (int)$row['promo_id'] : null;

        $row['filters']     = !empty($row['filters']) ? json_decode($row['filters'], true) : [];
        $row['conditions']   = !empty($row['conditions']) ? json_decode($row['conditions'], true) : [];
        $row['adjustments']  = !empty($row['adjustments']) ? json_decode($row['adjustments'], true) : [];

        return $row;
    }

    /**
     * Sanitize target filters.
     */
    private static function sanitize_filters($filters)
    {
        $filters['product_ids'] = self::normalize_id_list(isset($filters['product_ids']) ? $filters['product_ids'] : []);
        $filters['category_ids'] = self::normalize_id_list(isset($filters['category_ids']) ? $filters['category_ids'] : []);
        $filters['exclude_product_ids'] = self::normalize_id_list(isset($filters['exclude_product_ids']) ? $filters['exclude_product_ids'] : []);
        $filters['exclude_category_ids'] = self::normalize_id_list(isset($filters['exclude_category_ids']) ? $filters['exclude_category_ids'] : []);

        return $filters;
    }

    /**
     * Sanitize condition rows while preserving supported condition-specific fields.
     */
    private static function sanitize_conditions($conditions)
    {
        $normalized = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $condition = self::sanitize_deep($condition);

            if (isset($condition['product_ids'])) {
                $condition['product_ids'] = self::normalize_id_list($condition['product_ids']);
            }
            if (isset($condition['category_ids'])) {
                $condition['category_ids'] = self::normalize_id_list($condition['category_ids']);
            }
            $condition_type = !empty($condition['type']) ? $condition['type'] : '';
            $history_metric = !empty($condition['history_metric']) ? $condition['history_metric'] : '';
            $value_is_product_ids = in_array($condition_type, ['products', 'product_combination', 'cart_item_product_combination'], true)
                || ($condition_type === 'purchase_history' && in_array($history_metric, ['products_bought', 'previous_purchase_products'], true));

            if (isset($condition['value']) && is_array($condition['value']) && $value_is_product_ids) {
                $condition['value'] = self::normalize_id_list($condition['value']);
            }

            $normalized[] = $condition;
        }

        return $normalized;
    }

    /**
     * Sanitize adjustment payloads and normalize legacy admin field names.
     */
    private static function sanitize_adjustments($adjustments)
    {
        $adjustments = self::sanitize_deep($adjustments);
        $type = !empty($adjustments['type']) ? sanitize_text_field($adjustments['type']) : 'percentage';
        if ($type === 'bundle') {
            $type = 'bundle_set';
        }

        $allowed_types = ['percentage', 'fixed', 'bulk', 'bogo', 'free_shipping', 'bundle_set'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'percentage';
        }
        $adjustments['type'] = $type;

        if ($type === 'bogo') {
            if (!empty($adjustments['get_product_id'])) {
                $adjustments['get_products'] = self::normalize_id_list([$adjustments['get_product_id']]);
                unset($adjustments['get_product_id']);
            } elseif (isset($adjustments['get_products'])) {
                $adjustments['get_products'] = self::normalize_id_list($adjustments['get_products']);
            }

            if (isset($adjustments['buy_products'])) {
                $adjustments['buy_products'] = self::normalize_id_list($adjustments['buy_products']);
            }
            if (isset($adjustments['buy_categories'])) {
                $adjustments['buy_categories'] = self::normalize_id_list($adjustments['buy_categories']);
            }
            if (isset($adjustments['get_categories'])) {
                $adjustments['get_categories'] = self::normalize_id_list($adjustments['get_categories']);
            }
            if (!empty($adjustments['bogo_discount_type']) && empty($adjustments['discount_type'])) {
                $adjustments['discount_type'] = sanitize_text_field($adjustments['bogo_discount_type']);
                unset($adjustments['bogo_discount_type']);
            }
            if (isset($adjustments['bogo_value']) && !isset($adjustments['discount_value'])) {
                $adjustments['discount_value'] = (float)$adjustments['bogo_value'];
                unset($adjustments['bogo_value']);
            }
        }

        if ($type === 'bundle_set') {
            if (isset($adjustments['set_price']) && !isset($adjustments['bundle_price'])) {
                $adjustments['bundle_price'] = (float)$adjustments['set_price'];
                unset($adjustments['set_price']);
            }
            if (isset($adjustments['bundle_items']) && is_array($adjustments['bundle_items'])) {
                foreach ($adjustments['bundle_items'] as $index => $item) {
                    if (isset($item['id'])) {
                        $adjustments['bundle_items'][$index]['id'] = (int)$item['id'];
                    }
                    if (isset($item['product_id'])) {
                        $adjustments['bundle_items'][$index]['product_id'] = (int)$item['product_id'];
                    }
                    if (isset($item['qty'])) {
                        $adjustments['bundle_items'][$index]['qty'] = max(1, (int)$item['qty']);
                    }
                }
            }
        }

        // Clamp percentage discounts to [0, 100] so a hand-authored "Modo experto"
        // rule (or migrated data) can never drive a price below zero in RulesEngine.
        if ($type === 'percentage' && isset($adjustments['value'])) {
            $adjustments['value'] = max(0.0, min(100.0, (float)$adjustments['value']));
        }

        if ($type === 'bulk' && isset($adjustments['tiers']) && is_array($adjustments['tiers'])) {
            foreach ($adjustments['tiers'] as $index => $tier) {
                if (!is_array($tier)) {
                    continue;
                }
                $tier_type = !empty($tier['type']) ? $tier['type'] : 'percentage';
                if ($tier_type === 'percentage' && isset($tier['value'])) {
                    $adjustments['tiers'][$index]['value'] = max(0.0, min(100.0, (float)$tier['value']));
                }
            }
        }

        return $adjustments;
    }

    /**
     * Recursively sanitize scalar values inside rule JSON payloads.
     */
    private static function sanitize_deep($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[sanitize_text_field((string)$key)] = self::sanitize_deep($item);
            }
            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return sanitize_text_field((string)$value);
    }

    /**
     * Normalize mixed ID lists into unique positive integers.
     */
    private static function normalize_id_list($ids)
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
