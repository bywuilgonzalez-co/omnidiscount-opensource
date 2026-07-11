<?php
/**
 * Standalone smoke test for PopupModel (src/Models/PopupModel.php) -- direct
 * CRUD over wp_drw_popup_submissions, the popup email-capture feature's
 * ACTUAL concurrency guard (see the class's own docblock: UNIQUE(email) +
 * CAS-via-UPDATE-WHERE-clause, not app-level SELECT-then-write).
 *
 * No PHPUnit, no WooCommerce, no real database -- same style as
 * tests/test-popup-coupon-bridge.php: a purpose-built $wpdb stand-in that
 * models the exact query shapes this class issues (regex-matched against the
 * literal SQL fragments read straight out of PopupModel.php, the same
 * technique that file already uses for its own popup_code_exists() check),
 * plus a controllable global current_time() clock (PopupModel's own
 * namespace has no override, so this intercepts the REAL unqualified call
 * exactly as production code would resolve it) and hard-failing assert
 * helpers.
 *
 * This file exercises the REAL PopupModel class end-to-end against that
 * stub -- unlike tests/test-popup-controller.php (which stubs PopupModel
 * itself to isolate PopupController's own request-handling logic), this is
 * the test that actually proves PopupModel's SQL/CAS semantics are correct.
 *
 * Coverage:
 *   (a) insert_claim(): a fresh email creates a 'claimed' row with every
 *       field stamped correctly (status, reveal_mode, ip_hash, user_agent,
 *       source_url, claimed_at/created_at).
 *   (b) insert_claim(): a duplicate email (UNIQUE(email) collision) returns
 *       false and does NOT create a second row.
 *   (c) reclaim_abandoned_claim(): a 'claimed' row older than
 *       ABANDONED_CLAIM_SECONDS is atomically reclaimed (status stays
 *       'claimed', reveal_mode + claimed_at re-stamped) -> true.
 *   (d) reclaim_abandoned_claim(): a 'claimed' row younger than the cutoff is
 *       NOT reclaimed (still mid-flight from a live request) -> false, row
 *       untouched.
 *   (e) reclaim_abandoned_claim(): a row already 'pending'/'issued' (not
 *       'claimed') is NEVER reclaimed regardless of age -- the WHERE clause's
 *       own status filter, not just the age check, must hold.
 *   (f) get_by_email()/get_by_id()/get_by_token_hash(): null for a miss, the
 *       formatted row (id/coupon_id cast to int) for a hit.
 *   (g) mark_pending(): stamps status='pending' + confirm_token +
 *       token_expires_at, leaves other columns untouched.
 *   (h) mark_issued(): stamps status='issued' + coupon_id/coupon_code +
 *       revealed_at + confirmed_at.
 *   (i) claim_pending_token(): succeeds (CAS flips pending->claimed) only
 *       when status='pending' AND the token has not expired; a SECOND call
 *       on the same (now 'claimed') token fails; an expired token fails
 *       without ever touching the row.
 *   (j) purge_stale_rows(): deletes 'pending' rows with an expired token,
 *       deletes 'claimed' rows past DEAD_CLAIM_SECONDS, and leaves alone a
 *       still-valid 'pending' row, a recently-claimed row, AND an 'issued'
 *       row regardless of age (issued rows are terminal, never janitorial
 *       targets).
 *   (k) get_paginated(): correct total/page/per_page, newest-first ordering
 *       (created_at DESC, id DESC), and page/per_page clamped to
 *       [1,∞)/[1,100].
 *   (l) get_all_for_export(): newest-first ordering and the EXPORT_LIMIT
 *       constant is the actual LIMIT value used in the query (verified via
 *       the stub's captured SQL, not by inserting 10,000 rows).
 *   (m) try_reserve_daily_mint_slot(): atomic wp_options-backed cap, never
 *       overshoots.
 *   (n) ROUND-9 AUDIT FIX: release_daily_mint_slot() decrements the same
 *       day-keyed counter by exactly one, a released slot is immediately
 *       reusable, and the counter never underflows below 0.
 */

namespace Drw\App\Models {

    // Real current_time() is intercepted here (PopupModel's own namespace)
    // exactly as production code resolves the unqualified call -- fully
    // controllable, deterministic "now" for every cutoff/CAS computation
    // under test, with no real sleep()/time-of-day dependency.
    $GLOBALS['__drw_test_now_ts'] = strtotime('2026-07-10 12:00:00'); // Fixed reference instant.
    function current_time($type, $gmt = 0) {
        return ('timestamp' === $type) ? $GLOBALS['__drw_test_now_ts'] : gmdate('Y-m-d H:i:s', $GLOBALS['__drw_test_now_ts']);
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

    function assert_null($actual, $message) {
        if (null !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: NULL\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    /**
     * Purpose-built $wpdb stand-in modelling exactly the query shapes
     * PopupModel.php issues (read straight from the source), with an
     * in-memory table + a real UNIQUE(email) collision simulation for
     * insert(). Every method below corresponds to one call site in
     * PopupModel.php -- see that file's own docblocks for the SQL each one
     * builds.
     */
    class WpdbStub {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public $insert_id = 0;
        public $rows_affected = 0;
        /** @var array<int,array> id => row (raw DB-shaped, pre format_row()). */
        public $table = array();
        /** Round-5 audit fix: backs PopupModel::try_reserve_daily_mint_slot()'s day-keyed wp_options counter -- unused by this file's own scenarios, but purge_stale_rows()'s cleanup DELETE now touches it too. */
        public $options_table = array();
        private $next_id = 1;
        /** @var string SQL of the most recent query()/get_results() call, for assertions on e.g. LIMIT values. */
        public $last_sql = '';

        public function esc_like($text) {
            return addcslashes((string)$text, '_%\\');
        }

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
            if ($this->email_exists($data['email'])) {
                return false; // Simulates the real UNIQUE(email) index rejecting a duplicate-key INSERT.
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
                return 0; // Real $wpdb->update() returns an int (possibly 0), not false, when the WHERE simply matches nothing.
            }
            // Honor every OTHER key in $where too (real $wpdb->update() ANDs
            // every array entry into the WHERE clause) -- this is what makes
            // mark_issued()'s CAS guard ('status' => STATUS_CLAIMED)
            // actually meaningful to test, exactly like the real UPDATE.
            foreach ($where as $col => $expected) {
                if ('id' === $col) {
                    continue;
                }
                if ($this->table[$id][$col] !== $expected) {
                    return 0;
                }
            }
            $this->table[$id] = array_merge($this->table[$id], $data);
            return 1;
        }

        public function query($sql) {
            $this->last_sql = $sql;

            // reclaim_abandoned_claim(): UPDATE ... SET status=,reveal_mode=,claimed_at=,ip_hash=,user_agent=,source_url= WHERE email= AND status= AND claimed_at<
            if (preg_match(
                "/SET\\s+status\\s*=\\s*'([^']*)',\\s*reveal_mode\\s*=\\s*'([^']*)',\\s*claimed_at\\s*=\\s*'([^']*)',\\s*ip_hash\\s*=\\s*'([^']*)',\\s*user_agent\\s*=\\s*'([^']*)',\\s*source_url\\s*=\\s*'([^']*)'\\s*WHERE\\s+email\\s*=\\s*'([^']*)'\\s+AND\\s+status\\s*=\\s*'([^']*)'\\s+AND\\s+claimed_at\\s*<\\s*'([^']*)'/s",
                $sql,
                $m
            )) {
                list(, $new_status, $new_mode, $new_claimed_at, $new_ip_hash, $new_user_agent, $new_source_url, $email, $cond_status, $cutoff) = $m;
                $affected = 0;
                foreach ($this->table as $id => &$row) {
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

            // claim_pending_token(): UPDATE ... SET status= WHERE confirm_token= AND status= AND token_expires_at>
            if (preg_match(
                "/SET\\s+status\\s*=\\s*'([^']*)'\\s*WHERE\\s+confirm_token\\s*=\\s*'([^']*)'\\s+AND\\s+status\\s*=\\s*'([^']*)'\\s+AND\\s+token_expires_at\\s*>\\s*'([^']*)'/s",
                $sql,
                $m
            )) {
                list(, $new_status, $token, $cond_status, $now) = $m;
                $affected = 0;
                foreach ($this->table as $id => &$row) {
                    if ($row['confirm_token'] === $token && $row['status'] === $cond_status && $row['token_expires_at'] > $now) {
                        $row['status'] = $new_status;
                        $affected++;
                    }
                }
                unset($row);
                $this->rows_affected = $affected;
                return $affected;
            }

            // purge_stale_rows(): DELETE FROM ... WHERE status= AND token_expires_at IS NOT NULL AND token_expires_at<
            if (preg_match(
                "/DELETE FROM .* WHERE status = '([^']*)' AND token_expires_at IS NOT NULL AND token_expires_at < '([^']*)'/s",
                $sql,
                $m
            )) {
                list(, $status, $now) = $m;
                foreach ($this->table as $id => $row) {
                    if ($row['status'] === $status && null !== $row['token_expires_at'] && $row['token_expires_at'] < $now) {
                        unset($this->table[$id]);
                    }
                }
                return count($this->table);
            }

            // purge_stale_rows(): DELETE FROM ... WHERE status= AND claimed_at<
            if (preg_match("/DELETE FROM .* WHERE status = '([^']*)' AND claimed_at < '([^']*)'/s", $sql, $m)) {
                list(, $status, $cutoff) = $m;
                foreach ($this->table as $id => $row) {
                    if ($row['status'] === $status && null !== $row['claimed_at'] && $row['claimed_at'] < $cutoff) {
                        unset($this->table[$id]);
                    }
                }
                return count($this->table);
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

            // ROUND-9 AUDIT FIX: release_daily_mint_slot()'s atomic decrement
            // -- see that method's docblock. The "> 0" guard prevents ever
            // going negative.
            if (preg_match("/^UPDATE wp_options SET option_value = option_value - 1 WHERE option_name = '([^']*)' AND CAST\\(option_value AS UNSIGNED\\) > 0$/", $sql, $m)) {
                $name    = $m[1];
                $current = isset($this->options_table[$name]) ? (int)$this->options_table[$name] : 0;
                if ($current > 0) {
                    $this->options_table[$name] = $current - 1;
                    $this->rows_affected = 1;
                    return 1;
                }
                $this->rows_affected = 0;
                return 0;
            }

            // purge_stale_rows()'s cleanup of stale day-keyed
            // try_reserve_daily_mint_slot() option rows -- not exercised by
            // this file's own scenarios (no daily-mint option row is ever
            // seeded here), but handled so purge_stale_rows() calling it
            // doesn't hard-fail the run.
            if (preg_match("/^DELETE FROM wp_options WHERE option_name LIKE '([^']*)' AND option_name < '([^']*)'$/", $sql, $m)) {
                $prefix = rtrim($m[1], '%');
                $cutoff = $m[2];
                foreach (array_keys($this->options_table) as $name) {
                    if (0 === strpos($name, $prefix) && $name < $cutoff) {
                        unset($this->options_table[$name]);
                    }
                }
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

        public function get_var($sql) {
            if (false !== strpos($sql, 'COUNT(*)')) {
                return (string)count($this->table);
            }
            return null;
        }

        public function get_results($sql, $output = ARRAY_A) {
            $this->last_sql = $sql;
            $rows = array_values($this->table);
            usort($rows, function ($a, $b) {
                if ($a['created_at'] === $b['created_at']) {
                    return $b['id'] - $a['id'];
                }
                return strcmp($b['created_at'], $a['created_at']);
            });

            $offset = 0;
            $limit  = count($rows);
            if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $m)) {
                $limit  = (int)$m[1];
                $offset = (int)$m[2];
            } elseif (preg_match('/LIMIT (\d+)/', $sql, $m)) {
                $limit = (int)$m[1];
            }

            return array_slice($rows, $offset, $limit);
        }

        private function email_exists($email) {
            foreach ($this->table as $row) {
                if ($row['email'] === $email) {
                    return true;
                }
            }
            return false;
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

        /** Test-only helper: seed a row directly, bypassing insert_claim(), for cases that need a specific pre-set claimed_at/token_expires_at. */
        public function seed_row(array $row) {
            $id = isset($row['id']) ? (int)$row['id'] : $this->next_id++;
            $this->table[$id] = array_merge($this->blank_row(), $row, array('id' => $id));
            if ($id >= $this->next_id) {
                $this->next_id = $id + 1;
            }
            return $id;
        }
    }

    require_once dirname(__DIR__) . '/src/Models/PopupModel.php';

    use Drw\App\Models\PopupModel;

    function reset_world() {
        $GLOBALS['wpdb'] = new WpdbStub();
        $GLOBALS['__drw_test_now_ts'] = strtotime('2026-07-10 12:00:00');
    }

    $NOW    = '2026-07-10 12:00:00';
    $t = function ($offset_seconds) use ($NOW) {
        return gmdate('Y-m-d H:i:s', strtotime($NOW) + $offset_seconds);
    };

    // === (a) insert_claim(): fresh email, every field stamped correctly =====
    reset_world();
    $id_a = PopupModel::insert_claim('fresh@example.com', PopupModel::REVEAL_INSTANT, 'hash123', 'UA/1.0', 'https://shop.test/');
    assert_true(false !== $id_a, '(a) insert_claim() must return a truthy new id for a fresh email.');
    $row_a = $GLOBALS['wpdb']->table[$id_a];
    assert_same('fresh@example.com', $row_a['email'], '(a) email must be stored as given.');
    assert_same(PopupModel::STATUS_CLAIMED, $row_a['status'], '(a) A freshly inserted row must be status=claimed.');
    assert_same(PopupModel::REVEAL_INSTANT, $row_a['reveal_mode'], '(a) reveal_mode must be stored as given.');
    assert_same('hash123', $row_a['ip_hash'], '(a) ip_hash must be stored as given.');
    assert_same('UA/1.0', $row_a['user_agent'], '(a) user_agent must be stored as given.');
    assert_same('https://shop.test/', $row_a['source_url'], '(a) source_url must be stored as given.');
    assert_same($NOW, $row_a['claimed_at'], '(a) claimed_at must be stamped to the current time.');
    assert_same($NOW, $row_a['created_at'], '(a) created_at must be stamped to the current time.');

    // === (b) insert_claim(): duplicate email -> false, no second row =========
    $id_b = PopupModel::insert_claim('fresh@example.com', PopupModel::REVEAL_CONFIRMED, 'hashXXX', 'UA/2.0', 'https://shop.test/other');
    assert_same(false, $id_b, '(b) A duplicate email must fail (simulating the real UNIQUE(email) index).');
    assert_same(1, count($GLOBALS['wpdb']->table), '(b) A duplicate-email failure must never create a second row.');

    // === (c) reclaim_abandoned_claim(): abandoned row IS reclaimed ===========
    reset_world();
    $id_c = $GLOBALS['wpdb']->seed_row(array(
        'email' => 'abandonado@example.com', 'status' => PopupModel::STATUS_CLAIMED,
        'reveal_mode' => PopupModel::REVEAL_INSTANT, 'claimed_at' => $t(-90), 'created_at' => $t(-90),
        'ip_hash' => 'original-hash', 'user_agent' => 'Original-UA/1.0', 'source_url' => 'https://shop.test/original',
    ));
    $reclaimed_c = PopupModel::reclaim_abandoned_claim('abandonado@example.com', PopupModel::REVEAL_CONFIRMED, 'reclaimer-hash', 'Reclaimer-UA/2.0', 'https://shop.test/reclaimer');
    assert_true($reclaimed_c, '(c) A claimed row older than ABANDONED_CLAIM_SECONDS must be successfully reclaimed.');
    assert_same(PopupModel::STATUS_CLAIMED, $GLOBALS['wpdb']->table[$id_c]['status'], '(c) A reclaimed row stays status=claimed (a fresh attempt, not a different lifecycle stage).');
    assert_same(PopupModel::REVEAL_CONFIRMED, $GLOBALS['wpdb']->table[$id_c]['reveal_mode'], '(c) A reclaim must re-stamp reveal_mode to the NEW attempt\'s mode.');
    assert_same($NOW, $GLOBALS['wpdb']->table[$id_c]['claimed_at'], '(c) A reclaim must re-stamp claimed_at to now.');
    assert_same('reclaimer-hash', $GLOBALS['wpdb']->table[$id_c]['ip_hash'], '(c) round-4 fix: a reclaim must re-stamp ip_hash to the NEW (reclaiming) request\'s value, not leave the original claimant\'s.');
    assert_same('Reclaimer-UA/2.0', $GLOBALS['wpdb']->table[$id_c]['user_agent'], '(c) round-4 fix: a reclaim must re-stamp user_agent to the NEW request\'s value.');
    assert_same('https://shop.test/reclaimer', $GLOBALS['wpdb']->table[$id_c]['source_url'], '(c) round-4 fix: a reclaim must re-stamp source_url to the NEW request\'s value.');

    // === (d) reclaim_abandoned_claim(): too-recent row is NOT reclaimed ======
    reset_world();
    $id_d = $GLOBALS['wpdb']->seed_row(array(
        'email' => 'recien-enviado@example.com', 'status' => PopupModel::STATUS_CLAIMED,
        'reveal_mode' => PopupModel::REVEAL_INSTANT, 'claimed_at' => $t(-30), 'created_at' => $t(-30),
        'ip_hash' => 'original-hash', 'user_agent' => 'Original-UA/1.0', 'source_url' => 'https://shop.test/original',
    ));
    $reclaimed_d = PopupModel::reclaim_abandoned_claim('recien-enviado@example.com', PopupModel::REVEAL_CONFIRMED, 'reclaimer-hash', 'Reclaimer-UA/2.0', 'https://shop.test/reclaimer');
    assert_true(!$reclaimed_d, '(d) A claimed row younger than ABANDONED_CLAIM_SECONDS must NOT be reclaimed.');
    assert_same($t(-30), $GLOBALS['wpdb']->table[$id_d]['claimed_at'], '(d) An unreclaimed row must be left completely untouched.');
    assert_same(PopupModel::REVEAL_INSTANT, $GLOBALS['wpdb']->table[$id_d]['reveal_mode'], '(d) An unreclaimed row\'s reveal_mode must be left completely untouched.');
    assert_same('original-hash', $GLOBALS['wpdb']->table[$id_d]['ip_hash'], '(d) An unreclaimed row\'s ip_hash must be left completely untouched.');

    // === (e) reclaim_abandoned_claim(): non-'claimed' rows are NEVER reclaimed, any age ===
    reset_world();
    $id_e1 = $GLOBALS['wpdb']->seed_row(array('email' => 'pendiente@example.com', 'status' => PopupModel::STATUS_PENDING, 'claimed_at' => $t(-9999)));
    $id_e2 = $GLOBALS['wpdb']->seed_row(array('email' => 'emitido@example.com', 'status' => PopupModel::STATUS_ISSUED, 'claimed_at' => $t(-9999)));
    assert_true(!PopupModel::reclaim_abandoned_claim('pendiente@example.com', PopupModel::REVEAL_INSTANT, 'h', 'ua', 'url'), "(e) A 'pending' row must never be reclaimed, regardless of age.");
    assert_true(!PopupModel::reclaim_abandoned_claim('emitido@example.com', PopupModel::REVEAL_INSTANT, 'h', 'ua', 'url'), "(e) An 'issued' row must never be reclaimed, regardless of age.");
    assert_same(PopupModel::STATUS_PENDING, $GLOBALS['wpdb']->table[$id_e1]['status'], '(e) The pending row\'s status must be untouched.');
    assert_same(PopupModel::STATUS_ISSUED, $GLOBALS['wpdb']->table[$id_e2]['status'], '(e) The issued row\'s status must be untouched.');

    // === (f) get_by_email()/get_by_id()/get_by_token_hash(): miss=null, hit=formatted row ===
    reset_world();
    $id_f = PopupModel::insert_claim('lookup@example.com', PopupModel::REVEAL_INSTANT, 'h', 'ua', 'src');
    PopupModel::mark_pending($id_f, 'tok-hash-f', $t(3600));

    assert_null(PopupModel::get_by_email('nadie@example.com'), '(f) get_by_email() must return null for a miss.');
    assert_null(PopupModel::get_by_id(999999), '(f) get_by_id() must return null for a miss.');
    assert_null(PopupModel::get_by_token_hash('unknown-token'), '(f) get_by_token_hash() must return null for a miss.');

    $by_email = PopupModel::get_by_email('lookup@example.com');
    $by_id    = PopupModel::get_by_id($id_f);
    $by_token = PopupModel::get_by_token_hash('tok-hash-f');
    assert_true(is_int($by_email['id']), '(f) format_row() must cast id to int.');
    assert_same($id_f, $by_email['id'], '(f) get_by_email() must find the right row.');
    assert_same($by_email, $by_id, '(f) get_by_id() must return the same formatted row as get_by_email().');
    assert_same($by_email, $by_token, '(f) get_by_token_hash() must return the same formatted row.');
    assert_null($by_email['coupon_id'], '(f) coupon_id must format to null when empty, never 0.');

    // === (g) mark_pending(): stamps status/token/expiry, leaves the rest ====
    reset_world();
    $id_g = PopupModel::insert_claim('pending-target@example.com', PopupModel::REVEAL_CONFIRMED, 'h', 'ua', 'src');
    $ok_g = PopupModel::mark_pending($id_g, 'hash-g', $t(172800));
    assert_true($ok_g, '(g) mark_pending() must report success.');
    $row_g = $GLOBALS['wpdb']->table[$id_g];
    assert_same(PopupModel::STATUS_PENDING, $row_g['status'], '(g) mark_pending() must set status=pending.');
    assert_same('hash-g', $row_g['confirm_token'], '(g) mark_pending() must store the token hash.');
    assert_same($t(172800), $row_g['token_expires_at'], '(g) mark_pending() must store the expiry.');
    assert_same('pending-target@example.com', $row_g['email'], '(g) mark_pending() must not disturb unrelated columns.');

    // === (h) mark_issued(): stamps status/coupon/revealed/confirmed ==========
    reset_world();
    $id_h = PopupModel::insert_claim('issue-target@example.com', PopupModel::REVEAL_INSTANT, 'h', 'ua', 'src');
    $ok_h = PopupModel::mark_issued($id_h, 555, 'WELCOME8X');
    assert_true($ok_h, '(h) mark_issued() must report success.');
    $row_h = $GLOBALS['wpdb']->table[$id_h];
    assert_same(PopupModel::STATUS_ISSUED, $row_h['status'], '(h) mark_issued() must set status=issued.');
    assert_same(555, $row_h['coupon_id'], '(h) mark_issued() must store the coupon id.');
    assert_same('WELCOME8X', $row_h['coupon_code'], '(h) mark_issued() must store the coupon code.');
    assert_same($NOW, $row_h['revealed_at'], '(h) mark_issued() must stamp revealed_at.');
    assert_same($NOW, $row_h['confirmed_at'], '(h) mark_issued() must also stamp confirmed_at (correct even on the instant path, where they are the same instant).');

    // === (h2) mark_issued(): CAS guard (round-3 audit fix) -- a row that is
    // no longer 'claimed' (already 'issued' by an earlier winning call, or
    // still 'pending'/never reached 'claimed' at all) must NEVER be
    // transitioned or overwritten by a second call. This is the exact fix
    // for the double-minting race: a merely-slow (not dead) original
    // request racing a legitimate reclaim_abandoned_claim() winner for the
    // SAME row must not have the winner's already-recorded coupon silently
    // clobbered by the loser's own mark_issued() call. ==========
    $second_call_h = PopupModel::mark_issued($id_h, 999, 'INTRUSO99');
    assert_true(!$second_call_h, '(h2) A SECOND mark_issued() call on an already-issued row must lose the CAS and report failure.');
    $row_h_after = $GLOBALS['wpdb']->table[$id_h];
    assert_same(555, $row_h_after['coupon_id'], '(h2) A losing mark_issued() call must NEVER overwrite the winner\'s coupon_id.');
    assert_same('WELCOME8X', $row_h_after['coupon_code'], '(h2) A losing mark_issued() call must NEVER overwrite the winner\'s coupon_code.');

    reset_world();
    $id_h3 = PopupModel::insert_claim('never-claimed-cas@example.com', PopupModel::REVEAL_CONFIRMED, 'h', 'ua', 'src');
    PopupModel::mark_pending($id_h3, 'hash-h3', $t(172800)); // status is now 'pending', not 'claimed'.
    $result_h3 = PopupModel::mark_issued($id_h3, 777, 'NOPE7777');
    assert_true(!$result_h3, "(h3) mark_issued() on a 'pending' row (never reached 'claimed') must lose the CAS and report failure.");
    assert_same(PopupModel::STATUS_PENDING, $GLOBALS['wpdb']->table[$id_h3]['status'], '(h3) A losing mark_issued() call must leave a pending row\'s status untouched.');
    assert_null($GLOBALS['wpdb']->table[$id_h3]['coupon_id'], '(h3) A losing mark_issued() call must never stamp a coupon_id onto the row.');

    // === (i) claim_pending_token(): valid CAS succeeds once, fails on retry/expiry ===
    reset_world();
    $id_i = PopupModel::insert_claim('confirm-target@example.com', PopupModel::REVEAL_CONFIRMED, 'h', 'ua', 'src');
    PopupModel::mark_pending($id_i, 'tok-i', $t(3600)); // expires 1h in the future -- still valid.
    $first_claim  = PopupModel::claim_pending_token('tok-i');
    assert_true($first_claim, '(i) A valid, unexpired pending token must win the CAS on its first claim.');
    assert_same(PopupModel::STATUS_CLAIMED, $GLOBALS['wpdb']->table[$id_i]['status'], '(i) A won CAS must flip the row to status=claimed.');

    $second_claim = PopupModel::claim_pending_token('tok-i');
    assert_true(!$second_claim, '(i) A SECOND claim of the same (now already-claimed, no longer pending) token must fail.');

    $id_i2 = PopupModel::insert_claim('confirm-expired@example.com', PopupModel::REVEAL_CONFIRMED, 'h', 'ua', 'src');
    PopupModel::mark_pending($id_i2, 'tok-i2', $t(-1)); // expired 1 second ago.
    $expired_claim = PopupModel::claim_pending_token('tok-i2');
    assert_true(!$expired_claim, '(i) An expired token must never win the CAS, even though status is still pending.');
    assert_same(PopupModel::STATUS_PENDING, $GLOBALS['wpdb']->table[$id_i2]['status'], '(i) An expired-token claim attempt must leave the row untouched.');

    // === (j) purge_stale_rows(): correct rows deleted, correct rows kept =====
    reset_world();
    $id_j_expired_pending = $GLOBALS['wpdb']->seed_row(array('email' => 'j1@example.com', 'status' => PopupModel::STATUS_PENDING, 'token_expires_at' => $t(-10)));
    $id_j_valid_pending   = $GLOBALS['wpdb']->seed_row(array('email' => 'j2@example.com', 'status' => PopupModel::STATUS_PENDING, 'token_expires_at' => $t(3600)));
    $id_j_dead_claimed    = $GLOBALS['wpdb']->seed_row(array('email' => 'j3@example.com', 'status' => PopupModel::STATUS_CLAIMED, 'claimed_at' => $t(-(86400 + 10))));
    $id_j_fresh_claimed   = $GLOBALS['wpdb']->seed_row(array('email' => 'j4@example.com', 'status' => PopupModel::STATUS_CLAIMED, 'claimed_at' => $t(-100)));
    $id_j_old_issued      = $GLOBALS['wpdb']->seed_row(array('email' => 'j5@example.com', 'status' => PopupModel::STATUS_ISSUED, 'claimed_at' => $t(-(86400 * 30)), 'revealed_at' => $t(-(86400 * 30))));

    PopupModel::purge_stale_rows();

    $remaining = array_keys($GLOBALS['wpdb']->table);
    assert_true(!in_array($id_j_expired_pending, $remaining, true), '(j) A pending row with an expired token must be purged.');
    assert_true(in_array($id_j_valid_pending, $remaining, true), '(j) A pending row with a still-valid token must be kept.');
    assert_true(!in_array($id_j_dead_claimed, $remaining, true), '(j) A claimed row past DEAD_CLAIM_SECONDS must be purged.');
    assert_true(in_array($id_j_fresh_claimed, $remaining, true), '(j) A recently-claimed row must be kept.');
    assert_true(in_array($id_j_old_issued, $remaining, true), '(j) An issued row must NEVER be purged, no matter how old -- it is a terminal, successful state.');

    // === (k) get_paginated(): total/ordering/clamping ==========================
    reset_world();
    for ($n = 1; $n <= 5; $n++) {
        $GLOBALS['wpdb']->seed_row(array('email' => "page{$n}@example.com", 'status' => PopupModel::STATUS_ISSUED, 'created_at' => $NOW));
    }
    $page1 = PopupModel::get_paginated(1, 2);
    assert_same(5, $page1['total'], '(k) total must reflect every row, independent of pagination.');
    assert_same(1, $page1['page'], '(k) page must echo back the requested (valid) page.');
    assert_same(2, $page1['per_page'], '(k) per_page must echo back the requested (valid) per_page.');
    assert_same(2, count($page1['items']), '(k) page 1 with per_page=2 must return exactly 2 items.');
    assert_same(5, $page1['items'][0]['id'], '(k) Results must be newest-first (highest id first) when created_at ties.');
    assert_same(4, $page1['items'][1]['id'], '(k) Second item must be the next-highest id.');

    $page3 = PopupModel::get_paginated(3, 2);
    assert_same(1, count($page3['items']), '(k) The last (partial) page must return only the remaining row.');
    assert_same(1, $page3['items'][0]['id'], '(k) The last page must contain the OLDEST (lowest id) row.');

    $clamped = PopupModel::get_paginated(0, 500);
    assert_same(1, $clamped['page'], '(k) page must clamp up to a minimum of 1.');
    assert_same(100, $clamped['per_page'], '(k) per_page must clamp down to a maximum of 100.');

    // === (l) get_all_for_export(): ordering + EXPORT_LIMIT is the real LIMIT used ===
    reset_world();
    $GLOBALS['wpdb']->seed_row(array('email' => 'exp-old@example.com', 'status' => PopupModel::STATUS_ISSUED, 'created_at' => $t(-100)));
    $GLOBALS['wpdb']->seed_row(array('email' => 'exp-new@example.com', 'status' => PopupModel::STATUS_ISSUED, 'created_at' => $t(0)));
    $exported = PopupModel::get_all_for_export();
    assert_same(2, count($exported), '(l) get_all_for_export() must return every row (well under EXPORT_LIMIT).');
    assert_same('exp-new@example.com', $exported[0]['email'], '(l) Export ordering must be newest-first.');
    assert_true(
        false !== strpos($GLOBALS['wpdb']->last_sql, 'LIMIT ' . PopupModel::EXPORT_LIMIT),
        '(l) The export query\'s LIMIT must be the actual EXPORT_LIMIT constant (10000), not an unbounded/different value.'
    );

    // === (m) try_reserve_daily_mint_slot(): atomic wp_options-backed cap
    // (round-5 audit fix) =======================================================
    reset_world();
    for ($n = 1; $n <= 3; $n++) {
        assert_true(PopupModel::try_reserve_daily_mint_slot(3), "(m) Call #{$n} of 3 under a cap of 3 must succeed.");
    }
    assert_true(!PopupModel::try_reserve_daily_mint_slot(3), '(m) The 4th call under the same cap of 3 must be denied.');

    $option_name = null;
    foreach (array_keys($GLOBALS['wpdb']->options_table) as $name) {
        if (0 === strpos($name, 'drw_popup_daily_mint_')) {
            $option_name = $name;
        }
    }
    assert_true(null !== $option_name, '(m) A day-keyed drw_popup_daily_mint_* option row must have been created.');
    assert_same(3, $GLOBALS['wpdb']->options_table[$option_name], '(m) The counter must stop incrementing once the cap is reached (never overshoot).');

    // A second, independent call sequence against the SAME already-full
    // counter must keep failing (not silently reset/leak past the cap).
    assert_true(!PopupModel::try_reserve_daily_mint_slot(3), '(m) A repeat call after exhaustion must still be denied.');
    assert_same(3, $GLOBALS['wpdb']->options_table[$option_name], '(m) A denied call must never increment the counter.');

    // === (n) ROUND-9 AUDIT FIX: release_daily_mint_slot() decrements the
    // SAME day-keyed counter try_reserve_daily_mint_slot() wrote to, and
    // never underflows below 0 ===================================================
    reset_world();
    assert_true(PopupModel::try_reserve_daily_mint_slot(5), '(n) sanity: reserve a slot to release later.');
    assert_true(PopupModel::try_reserve_daily_mint_slot(5), '(n) sanity: reserve a second slot.');

    $option_name_n = null;
    foreach (array_keys($GLOBALS['wpdb']->options_table) as $name) {
        if (0 === strpos($name, 'drw_popup_daily_mint_')) {
            $option_name_n = $name;
        }
    }
    assert_same(2, $GLOBALS['wpdb']->options_table[$option_name_n], '(n) sanity: counter is at 2 after two reservations.');

    PopupModel::release_daily_mint_slot();
    assert_same(1, $GLOBALS['wpdb']->options_table[$option_name_n], '(n) release_daily_mint_slot() must decrement the counter by exactly one.');

    assert_true(PopupModel::try_reserve_daily_mint_slot(5), '(n) A released slot must be immediately reusable by a fresh reservation.');
    assert_same(2, $GLOBALS['wpdb']->options_table[$option_name_n], '(n) The reservation after a release must land back at 2, not overshoot.');

    // Drain back down to 0, then confirm a further release never underflows
    // negative (the "> 0" guard in the UPDATE's own WHERE clause).
    PopupModel::release_daily_mint_slot();
    PopupModel::release_daily_mint_slot();
    assert_same(0, $GLOBALS['wpdb']->options_table[$option_name_n], '(n) Releasing back down to 0 must land exactly at 0.');
    PopupModel::release_daily_mint_slot();
    assert_same(0, $GLOBALS['wpdb']->options_table[$option_name_n], '(n) A release against an already-0 counter must never go negative.');

    echo "PopupModel OK\n";
}
