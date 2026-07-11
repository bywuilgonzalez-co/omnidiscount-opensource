<?php
/**
 * Standalone smoke test for RuleModel::try_reserve_usage()/release_usage()'s
 * defence against wpdb's own transparent "MySQL server has gone away"
 * reconnect-and-retry (wp-includes/class-wpdb.php check_connection())
 * silently swapping the live connection handle mid-transaction -- see
 * connection_fingerprint()/connection_still_open() in RuleModel.php.
 *
 * Extends the WpdbStub from tests/test-promo-usage-reservation.php with a
 * public $dbh object (a real handle identity RuleModel's fingerprint check
 * can read via spl_object_id()) and a way to script WHEN a reconnect
 * happens: $reconnect_after, a query-count threshold after which every
 * subsequent query() call first swaps $dbh for a brand-new object (exactly
 * what check_connection() does) before running normally -- so the stub can
 * still report an apparently-successful COMMIT/UPDATE/INSERT AFTER the
 * silent swap, forcing the fingerprint check (not $last_error) to be what
 * catches it.
 *
 * Coverage:
 *   (a) No reconnect at all -> reservation succeeds exactly as before this
 *       defence was added (regression check).
 *   (b) Reconnect between START TRANSACTION and the FOR UPDATE SELECT ->
 *       try_reserve_usage() must return false, and must NOT have run the
 *       used_count UPDATE or the redemption INSERT (the race the FOR UPDATE
 *       lock was supposed to close never got a chance to reopen).
 *   (c) Reconnect exactly at COMMIT (the UPDATE/INSERT already "succeeded"
 *       on the stub's model of the old connection, then COMMIT itself
 *       triggers the swap and reports success as a no-op on the new one) ->
 *       try_reserve_usage() must still return false, even though every
 *       individual $wpdb call reported success and $last_error was empty
 *       throughout.
 *   (d) release_usage(): the same COMMIT-time reconnect must make it return
 *       false rather than falsely report a successful release.
 */

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

function current_time($type, $gmt = 0) {
    return '2026-07-10 12:00:00';
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Trivial stand-in for a mysqli connection object -- only its identity matters. */
class FakeDbHandle {
}

/**
 * Same transactional behaviour as test-promo-usage-reservation.php's
 * WpdbStub, plus a scriptable mid-transaction reconnect: after
 * $reconnect_after query()/get_row()/get_var() calls have been made (across
 * this call and all earlier ones on this instance), $dbh is swapped for a
 * FRESH FakeDbHandle before the call's own logic runs -- mirroring
 * check_connection() opening a new connection and THEN retrying the
 * statement on it.
 */
class ReconnectingWpdbStub {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows_affected = 0;
    public $insert_id = 0;
    public $dbh;

    /** @var int|null Call count at which to swap $dbh; null = never. */
    public $reconnect_after = null;
    private $call_count = 0;

    public $rules = array();
    public $redemptions = array();
    private $next_redemption_id = 1;

    public function __construct() {
        $this->dbh = new FakeDbHandle();
    }

    private function maybe_reconnect() {
        $this->call_count++;
        if (null !== $this->reconnect_after && $this->call_count === $this->reconnect_after) {
            $this->dbh = new FakeDbHandle();
        }
    }

    public function prepare($query, ...$args) {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback(
            '/%[dsf]/',
            function ($m) use (&$i, $args) {
                $arg = isset($args[$i]) ? $args[$i] : null;
                $i++;
                if ('%d' === $m[0]) {
                    return (string)(int)$arg;
                }
                if ('%f' === $m[0]) {
                    return (string)(float)$arg;
                }
                return "'" . addslashes((string)$arg) . "'";
            },
            $query
        );
    }

    public function get_row($query, $output = ARRAY_A) {
        $this->maybe_reconnect();
        $this->last_error = '';
        if (preg_match('/FROM\s+(\S+)\s+WHERE id = (\d+) AND deleted = 0 FOR UPDATE/', $query, $m)) {
            $id = (int)$m[2];
            if (!isset($this->rules[$id])) {
                return null;
            }
            $r = $this->rules[$id];
            return array(
                'usage_limit' => $r['usage_limit'],
                'used_count'  => $r['used_count'],
                'limit_user'  => $r['limit_user'],
            );
        }
        return null;
    }

    public function get_var($query) {
        $this->maybe_reconnect();
        $this->last_error = '';
        if (preg_match("/FROM\\s+(\\S+)\\s+WHERE rule_id = (\\d+) AND customer_key = '([^']*)' AND status IN/", $query, $m)) {
            $rule_id      = (int)$m[2];
            $customer_key = stripslashes($m[3]);
            $count        = 0;
            foreach ($this->redemptions as $row) {
                if ((int)$row['rule_id'] === $rule_id
                    && $row['customer_key'] === $customer_key
                    && in_array($row['status'], array('reserved', 'confirmed'), true)
                ) {
                    $count++;
                }
            }
            return (string)$count;
        }
        return null;
    }

    public function query($sql) {
        $this->maybe_reconnect();
        $this->last_error    = '';
        $this->rows_affected = 0;
        $trimmed              = trim($sql);

        if ('START TRANSACTION' === $trimmed || 'COMMIT' === $trimmed || 'ROLLBACK' === $trimmed) {
            return true;
        }

        if (preg_match('/UPDATE\s+(\S+)\s+SET used_count = used_count \+ 1 WHERE id = (\d+)/', $sql, $m)) {
            $id = (int)$m[2];
            if (isset($this->rules[$id])) {
                $this->rules[$id]['used_count']++;
                $this->rows_affected = 1;
            }
            return true;
        }

        if (preg_match('/UPDATE\s+(\S+)\s+SET used_count = GREATEST\(0, used_count - 1\) WHERE id = (\d+)/', $sql, $m)) {
            $id = (int)$m[2];
            if (isset($this->rules[$id])) {
                $this->rules[$id]['used_count'] = max(0, $this->rules[$id]['used_count'] - 1);
                $this->rows_affected            = 1;
            }
            return true;
        }

        if (preg_match('/DELETE FROM\s+(\S+)\s+WHERE rule_id = (\d+) AND order_id = (\d+) AND status IN/', $sql, $m)) {
            $rule_id  = (int)$m[2];
            $order_id = (int)$m[3];
            $deleted  = 0;
            foreach ($this->redemptions as $id => $row) {
                if ((int)$row['rule_id'] === $rule_id
                    && (int)$row['order_id'] === $order_id
                    && in_array($row['status'], array('reserved', 'confirmed'), true)
                ) {
                    unset($this->redemptions[$id]);
                    $deleted++;
                }
            }
            $this->rows_affected = $deleted;
            return true;
        }

        return true;
    }

    public function insert($table, $data) {
        $this->maybe_reconnect();
        $this->last_error = '';
        if (false !== strpos($table, 'drw_promo_redemptions')) {
            foreach ($this->redemptions as $row) {
                if ((int)$row['order_id'] === (int)$data['order_id'] && (int)$row['rule_id'] === (int)$data['rule_id']) {
                    $this->last_error    = 'Duplicate entry for key order_id_rule_id';
                    $this->rows_affected = 0;
                    return false;
                }
            }
            $id                        = $this->next_redemption_id++;
            $data['id']                = $id;
            $this->redemptions[$id]    = $data;
            $this->insert_id           = $id;
            $this->rows_affected       = 1;
            return true;
        }
        return false;
    }
}

require_once dirname(__DIR__) . '/src/Models/RuleModel.php';

use Drw\App\Models\RuleModel;

function seed_rule($id, $usage_limit, $used_count, $limit_user) {
    $GLOBALS['wpdb']->rules[$id] = array(
        'usage_limit' => $usage_limit,
        'used_count'  => $used_count,
        'limit_user'  => $limit_user,
    );
}

// === (a) No reconnect at all -> succeeds exactly as before ==================
$GLOBALS['wpdb'] = new ReconnectingWpdbStub();
seed_rule(200, 5, 0, 2);

$ok_a = RuleModel::try_reserve_usage(200, 'user:1', 9001, 1);
assert_true($ok_a, '(a) With no reconnect, a reservation under both limits must still succeed.');
assert_same(1, $GLOBALS['wpdb']->rules[200]['used_count'], '(a) used_count must increment normally with no reconnect.');
assert_same(1, count($GLOBALS['wpdb']->redemptions), '(a) A redemption row must be inserted normally with no reconnect.');

// === (b) Reconnect between START TRANSACTION and the FOR UPDATE SELECT ======
// query('START TRANSACTION') is call #1; get_row(...FOR UPDATE) is call #2.
// Swapping $dbh exactly at call #2 means the fingerprint captured right
// after START TRANSACTION no longer matches by the time the SELECT (which
// itself still "succeeds" against the stub's rule data) returns.
$GLOBALS['wpdb'] = new ReconnectingWpdbStub();
seed_rule(201, 5, 0, 2);
$GLOBALS['wpdb']->reconnect_after = 2;

$ok_b = RuleModel::try_reserve_usage(201, 'user:1', 9002, 1);
assert_same(false, $ok_b, '(b) A reconnect between START TRANSACTION and the FOR UPDATE SELECT must be treated as a denial.');
assert_same(0, $GLOBALS['wpdb']->rules[201]['used_count'], '(b) used_count must NOT be incremented when the reconnect is caught before the UPDATE.');
assert_same(0, count($GLOBALS['wpdb']->redemptions), '(b) No redemption row must be inserted when the reconnect is caught before the INSERT.');

// === (c) Reconnect exactly at COMMIT =========================================
// Calls: 1=START TRANSACTION, 2=SELECT FOR UPDATE, 3=UPDATE used_count,
// 4=INSERT redemption, 5=COMMIT. Swap at call #5: every earlier call
// (including the UPDATE/INSERT) reports genuine success against the OLD
// $dbh's data, then COMMIT itself triggers the swap and still returns true
// (the stub's query() always returns true for COMMIT) -- exactly mirroring
// a real reconnect's no-op-success COMMIT retry. Only the fingerprint
// mismatch can catch this; $last_error is empty throughout.
$GLOBALS['wpdb'] = new ReconnectingWpdbStub();
seed_rule(202, 5, 0, 2);
$GLOBALS['wpdb']->reconnect_after = 5;

$ok_c = RuleModel::try_reserve_usage(202, 'user:1', 9003, 1);
assert_same(false, $ok_c, '(c) A reconnect exactly at COMMIT must be reported as a failed reservation, not a false success.');
assert_same('', $GLOBALS['wpdb']->last_error, '(c) Sanity: $wpdb->last_error stays empty throughout -- only the fingerprint check catches this.');

// === (d) release_usage(): same COMMIT-time reconnect must not report a =====
// === false successful release ===============================================
$GLOBALS['wpdb'] = new ReconnectingWpdbStub();
seed_rule(203, null, 1, 5);
$GLOBALS['wpdb']->redemptions[1] = array(
    'promo_id'     => 1,
    'rule_id'      => 203,
    'order_id'     => 9004,
    'customer_key' => 'user:1',
    'status'       => 'reserved',
    'created_at'   => '2026-07-10 12:00:00',
);
// Calls: 1=START TRANSACTION, 2=DELETE redemption, 3=UPDATE used_count,
// 4=COMMIT. Swap at call #4.
$GLOBALS['wpdb']->reconnect_after = 4;

$released_d = RuleModel::release_usage(203, 9004);
assert_same(false, $released_d, '(d) A reconnect exactly at COMMIT in release_usage() must be reported as a failed release, not a false success.');

// === (e) Reconnect exactly at the used_count UPDATE (round-4 audit finding) =
// Calls: 1=START TRANSACTION, 2=SELECT FOR UPDATE, 3=UPDATE used_count.
// Swap at call #3: every earlier checkpoint (START TRANSACTION, the FOR
// UPDATE SELECT) already passed on the connection captured in $conn_id, so
// this is specifically the "the UPDATE itself was the retried statement"
// case -- the stub applies the increment (against $dbh at the time, then
// swaps), reports success, and the fingerprint check right after it must
// catch the mismatch. Unlike case (b), simply returning false is NOT enough
// here: the increment already landed, standalone, on the stub's rule data
// (mirroring a real durable auto-commit on the reconnected handle), so
// try_reserve_usage() must also issue a compensating decrement before
// reporting failure -- otherwise used_count leaks permanently with no
// redemption row for the stale-reservation cron to key a fix off.
$GLOBALS['wpdb'] = new ReconnectingWpdbStub();
seed_rule(204, 5, 0, 2);
$GLOBALS['wpdb']->reconnect_after = 3;

$ok_e = RuleModel::try_reserve_usage(204, 'user:1', 9005, 1);
assert_same(false, $ok_e, '(e) A reconnect exactly at the used_count UPDATE must be reported as a failed reservation.');
assert_same(0, $GLOBALS['wpdb']->rules[204]['used_count'], '(e) The stray durable increment from the retried UPDATE must be compensated back down, not leaked.');
assert_same(0, count($GLOBALS['wpdb']->redemptions), '(e) No redemption row must be inserted when the reconnect is caught before the INSERT.');

echo "RuleModel reconnect guard OK\n";
