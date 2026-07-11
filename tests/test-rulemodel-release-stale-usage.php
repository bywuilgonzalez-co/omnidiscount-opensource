<?php
/**
 * Standalone smoke test for RuleModel::release_stale_usage() -- the
 * cron-only sibling of release_usage() added to close a TOCTOU race
 * reported in round 6 of the promo-exclusivity/atomic-reservation audit:
 *
 *   CartController::release_stale_promo_reservations() (the daily
 *   drw_release_stale_promo_reservations cron) takes a
 *   "SELECT ... WHERE status = 'reserved' AND created_at < cutoff" snapshot,
 *   then loops releasing each row. If the underlying order gets paid (and
 *   thus confirm_usage()'d to 'confirmed') by a concurrent request between
 *   the snapshot and this row's turn in the loop, a release scoped to
 *   status IN ('reserved','confirmed') -- i.e. plain release_usage() --
 *   would still match and erase a now-legitimate paid order's redemption
 *   row, wrongly decrementing used_count. release_stale_usage() scopes its
 *   DELETE to status = 'reserved' only, so it safely no-ops against a row
 *   that has since become 'confirmed'.
 *
 * Same $wpdb stand-in approach as tests/test-promo-usage-reservation.php:
 * the real RuleModel.php is required directly (not stubbed), so these
 * assertions exercise the actual transaction/locking logic.
 *
 * Coverage:
 *   (a) A genuinely still-'reserved' row is released normally: row deleted,
 *       used_count decremented, returns true.
 *   (b) THE RACE: a row that has since transitioned to 'confirmed' (order
 *       paid by a concurrent request after the cron's snapshot) must NOT be
 *       deleted and must NOT decrement used_count -- release_stale_usage()
 *       returns false and the confirmed row survives untouched.
 *   (c) Idempotent: releasing an already-released (nonexistent) row is a
 *       safe no-op, returns false.
 *   (d) Sanity contrast: release_usage() (the pre-existing, unmodified
 *       method) DOES still delete a 'confirmed' row -- confirming this is
 *       genuinely still needed for its own callers (cancelled/failed order
 *       release), and that release_stale_usage() is a deliberately narrower
 *       sibling, not a behavior change to release_usage() itself.
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

/**
 * $wpdb stand-in, extended from tests/test-promo-usage-reservation.php's
 * WpdbStub with a query() DELETE branch that distinguishes
 * "status IN ('reserved','confirmed')" (release_usage()) from
 * "status = 'reserved'" (release_stale_usage()) -- exactly the two SQL
 * shapes under test here.
 */
class WpdbStub {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows_affected = 0;
    public $insert_id = 0;

    public $rules = array();
    public $redemptions = array();
    private $next_redemption_id = 1;

    public function seed_redemption($id, $rule_id, $order_id, $status) {
        $this->redemptions[$id] = array(
            'id'           => $id,
            'rule_id'      => $rule_id,
            'order_id'     => $order_id,
            'customer_key' => 'user:1',
            'status'       => $status,
            'created_at'   => '2026-07-01 00:00:00',
        );
        $this->next_redemption_id = max($this->next_redemption_id, $id + 1);
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
        $this->last_error = '';
        return null;
    }

    public function query($sql) {
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

        // release_usage(): status IN ('reserved','confirmed') -- must be
        // checked BEFORE the "status = 'reserved'" branch below since the
        // latter's regex would otherwise also match inside this longer SQL
        // string.
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

        // release_stale_usage(): status = 'reserved' only.
        if (preg_match("/DELETE FROM\\s+(\\S+)\\s+WHERE rule_id = (\\d+) AND order_id = (\\d+) AND status = 'reserved'/", $sql, $m)) {
            $rule_id  = (int)$m[2];
            $order_id = (int)$m[3];
            $deleted  = 0;
            foreach ($this->redemptions as $id => $row) {
                if ((int)$row['rule_id'] === $rule_id
                    && (int)$row['order_id'] === $order_id
                    && $row['status'] === 'reserved'
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
        return false;
    }
}

require_once dirname(__DIR__) . '/src/Models/RuleModel.php';

use Drw\App\Models\RuleModel;

function reset_world() {
    $GLOBALS['wpdb'] = new WpdbStub();
}

function seed_rule($id, $usage_limit, $used_count, $limit_user) {
    $GLOBALS['wpdb']->rules[$id] = array(
        'usage_limit' => $usage_limit,
        'used_count'  => $used_count,
        'limit_user'  => $limit_user,
    );
}

// === (a) A genuinely still-'reserved' row is released normally =============
reset_world();
seed_rule(300, null, 1, null);
$GLOBALS['wpdb']->seed_redemption(1, 300, 9101, 'reserved');

$released_a = RuleModel::release_stale_usage(300, 9101);
assert_true($released_a, "(a) A still-'reserved' row must be released.");
assert_same(0, $GLOBALS['wpdb']->rules[300]['used_count'], '(a) used_count must be decremented.');
assert_same(0, count($GLOBALS['wpdb']->redemptions), '(a) The reserved redemption row must be deleted.');

// === (b) THE RACE: a row that became 'confirmed' after the cron's snapshot =
// === must survive untouched, and used_count must NOT be decremented ========
reset_world();
seed_rule(301, null, 1, null);
$GLOBALS['wpdb']->seed_redemption(2, 301, 9102, 'confirmed');

$released_b = RuleModel::release_stale_usage(301, 9102);
assert_same(false, $released_b, "(b) A 'confirmed' row must NOT be released by release_stale_usage() -- it only matches 'reserved'.");
assert_same(1, $GLOBALS['wpdb']->rules[301]['used_count'], "(b) used_count for a paid order's rule must NOT be decremented by the stale sweep.");
assert_same(1, count($GLOBALS['wpdb']->redemptions), "(b) The confirmed redemption row must survive the stale sweep untouched.");
$row_b = reset($GLOBALS['wpdb']->redemptions);
assert_same('confirmed', $row_b['status'], "(b) The surviving row's status must still be 'confirmed'.");

// === (c) Idempotent: releasing an already-released (nonexistent) row is a ==
// === safe no-op ==============================================================
reset_world();
seed_rule(302, null, 0, null);

$released_c = RuleModel::release_stale_usage(302, 9103);
assert_same(false, $released_c, '(c) Releasing a nonexistent reservation must report false.');
assert_same(0, $GLOBALS['wpdb']->rules[302]['used_count'], '(c) used_count must stay untouched (never driven negative).');

// === (d) Sanity contrast: release_usage() (unmodified) DOES still release ==
// === a 'confirmed' row -- it genuinely needs to, for release_reserved_usage()'s
// === own cancelled/failed-order callers ======================================
reset_world();
seed_rule(303, null, 1, null);
$GLOBALS['wpdb']->seed_redemption(3, 303, 9104, 'confirmed');

$released_d = RuleModel::release_usage(303, 9104);
assert_true($released_d, "(d) Sanity: release_usage() (unmodified) must still release a 'confirmed' row -- its own callers depend on this.");
assert_same(0, $GLOBALS['wpdb']->rules[303]['used_count'], '(d) release_usage() must still decrement used_count for a confirmed row it released.');

echo "RuleModel release_stale_usage OK\n";
