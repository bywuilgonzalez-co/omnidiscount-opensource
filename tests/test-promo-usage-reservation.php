<?php
/**
 * Standalone smoke test for RuleModel::try_reserve_usage() / release_usage() /
 * customer_redemption_count() -- the atomic-reservation system that closes
 * the TOCTOU race a plain "check COUNT then INSERT" would leave open under
 * concurrent checkouts. No PHPUnit, no WooCommerce, no real database -- same
 * hard-failing-assert style as tests/test-cartcontroller-promo-reservation.php,
 * whose $wpdb stand-in (WpdbStub, with prepare()/get_col()/get_results()/
 * get_var()) this file extends rather than inventing a new one from scratch:
 * the extension adds get_row() (for the SELECT ... FOR UPDATE row lock),
 * query() (for START TRANSACTION/COMMIT/ROLLBACK and the used_count
 * UPDATE/DELETE statements try_reserve_usage()/release_usage() issue), and
 * insert() (for the wp_drw_promo_redemptions row, enforcing the real
 * UNIQUE(order_id, rule_id) constraint in-memory).
 *
 * The real RuleModel.php is required directly (not stubbed) so these
 * assertions exercise the ACTUAL transaction/locking/limit logic, not a mock
 * of it -- matching the approach tests/test-promo-bridge.php uses for
 * RuleModel::sanitize_adjustments()/sanitize_conditions() via reflection.
 *
 * Coverage:
 *   (a) Reservation succeeds when under both usage_limit and limit_user.
 *   (b) Reservation fails once usage_limit is already exhausted (global cap).
 *   (c) Reservation fails once limit_user is exhausted for ONE customer_key,
 *       but a DIFFERENT customer_key can still reserve against the same rule.
 *   (d) release_usage() decrements used_count and frees the redemption row,
 *       so the SAME customer can reserve again afterwards.
 *   (e) release_usage() is idempotent: a second call for the same rule/order
 *       neither double-decrements used_count nor drives it negative.
 *   (f) customer_redemption_count() reports the live reserved/confirmed
 *       count directly, independent of try_reserve_usage()'s own bookkeeping.
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

// --- Minimal WP shims --------------------------------------------------
function current_time($type, $gmt = 0) {
    return '2026-07-10 12:00:00';
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * $wpdb stand-in extending the WpdbStub approach from
 * tests/test-cartcontroller-promo-reservation.php with the transactional
 * surface try_reserve_usage()/release_usage()/customer_redemption_count()
 * actually use: prepare() does real %d/%s/%f substitution (so the resulting
 * SQL strings can be pattern-matched), get_row() serves the "FOR UPDATE" rule
 * lookup, get_var() serves the redemption COUNT(*) lookup, query() handles
 * START TRANSACTION/COMMIT/ROLLBACK plus the used_count UPDATE and
 * redemptions DELETE, and insert() enforces the real
 * UNIQUE(order_id, rule_id) constraint on wp_drw_promo_redemptions.
 */
class WpdbStub {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows_affected = 0;
    public $insert_id = 0;

    /** @var array<int,array> wp_drw_rules rows keyed by id: usage_limit/used_count/limit_user. */
    public $rules = array();
    /** @var array<int,array> wp_drw_promo_redemptions rows keyed by an incrementing id. */
    public $redemptions = array();
    private $next_redemption_id = 1;

    public function reset() {
        $this->last_error         = '';
        $this->rows_affected      = 0;
        $this->insert_id          = 0;
        $this->rules              = array();
        $this->redemptions        = array();
        $this->next_redemption_id = 1;
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
        // "SELECT usage_limit, used_count, limit_user FROM wp_drw_rules WHERE id = X AND deleted = 0 FOR UPDATE"
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
        // "SELECT COUNT(*) FROM wp_drw_promo_redemptions WHERE rule_id = X AND customer_key = 'Y' AND status IN ('reserved','confirmed')"
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
        $this->last_error = '';
        if (false !== strpos($table, 'drw_promo_redemptions')) {
            foreach ($this->redemptions as $row) {
                if ((int)$row['order_id'] === (int)$data['order_id'] && (int)$row['rule_id'] === (int)$data['rule_id']) {
                    // Real UNIQUE(order_id, rule_id) collision.
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

$GLOBALS['wpdb'] = new WpdbStub();

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

// === (a) Reservation succeeds when under both usage_limit and limit_user ===
reset_world();
seed_rule(100, 5, 0, 2);

$ok_a = RuleModel::try_reserve_usage(100, 'user:1', 5001, 7);
assert_true($ok_a, '(a) A reservation under both limits must succeed.');
assert_same(1, $GLOBALS['wpdb']->rules[100]['used_count'], '(a) used_count must increment by exactly 1 on a successful reservation.');
assert_same(1, count($GLOBALS['wpdb']->redemptions), '(a) A successful reservation must insert exactly one redemption row.');
$row_a = reset($GLOBALS['wpdb']->redemptions);
assert_same('reserved', $row_a['status'], "(a) The inserted redemption row's status must be 'reserved'.");
assert_same(7, $row_a['promo_id'], '(a) The redemption row must carry the promo_id passed in.');
assert_same(5001, $row_a['order_id'], '(a) The redemption row must carry the order_id passed in.');
assert_same('user:1', $row_a['customer_key'], '(a) The redemption row must carry the customer_key passed in.');

// === (b) Reservation fails once usage_limit (global cap) is exhausted ======
reset_world();
seed_rule(101, 2, 2, null); // usage_limit=2, already used_count=2 -> exhausted.

$ok_b = RuleModel::try_reserve_usage(101, 'user:9', 5002, 8);
assert_same(false, $ok_b, '(b) A reservation against an exhausted usage_limit must be denied.');
assert_same(2, $GLOBALS['wpdb']->rules[101]['used_count'], '(b) A denied reservation must NOT increment used_count.');
assert_same(0, count($GLOBALS['wpdb']->redemptions), '(b) A denied reservation must NOT insert a redemption row.');

// === (c) limit_user exhausted for ONE customer_key does not block ANOTHER ==
reset_world();
seed_rule(102, null, 0, 1); // no global cap, limit_user=1 per customer.

$ok_c1 = RuleModel::try_reserve_usage(102, 'user:A', 5003, 9);
assert_true($ok_c1, "(c) Customer A's first reservation against limit_user=1 must succeed.");

$ok_c2 = RuleModel::try_reserve_usage(102, 'user:A', 5004, 9);
assert_same(false, $ok_c2, "(c) Customer A's SECOND reservation must be denied once their own limit_user is exhausted.");
assert_same(1, $GLOBALS['wpdb']->rules[102]['used_count'], '(c) A limit_user denial must NOT increment used_count.');

$ok_c3 = RuleModel::try_reserve_usage(102, 'user:B', 5005, 9);
assert_true($ok_c3, '(c) A DIFFERENT customer_key must still be able to reserve -- limit_user is per-customer, not global.');
assert_same(2, $GLOBALS['wpdb']->rules[102]['used_count'], "(c) Customer B's successful reservation must still increment used_count.");
assert_same(2, count($GLOBALS['wpdb']->redemptions), '(c) Exactly two redemption rows must exist: A\'s first success and B\'s success (the denied attempt inserted nothing).');

// === (d) release_usage() decrements used_count; the SAME customer can then =
// === reserve again ==========================================================
reset_world();
seed_rule(103, null, 0, 1);

$ok_d1 = RuleModel::try_reserve_usage(103, 'user:C', 6001, 10);
assert_true($ok_d1, '(d) Initial reservation must succeed.');
assert_same(1, $GLOBALS['wpdb']->rules[103]['used_count'], '(d) used_count must be 1 after the initial reservation.');

$ok_d2 = RuleModel::try_reserve_usage(103, 'user:C', 6002, 10);
assert_same(false, $ok_d2, '(d) A second reservation attempt for the SAME customer, before any release, must still be denied.');

$released = RuleModel::release_usage(103, 6001);
assert_true($released, '(d) release_usage() must report success when a matching reservation exists.');
assert_same(0, $GLOBALS['wpdb']->rules[103]['used_count'], '(d) release_usage() must decrement used_count back down.');
assert_same(0, count($GLOBALS['wpdb']->redemptions), '(d) release_usage() must remove the redemption row it released.');

$ok_d3 = RuleModel::try_reserve_usage(103, 'user:C', 6003, 10);
assert_true($ok_d3, '(d) After release_usage(), the SAME customer must be able to reserve again.');
assert_same(1, $GLOBALS['wpdb']->rules[103]['used_count'], '(d) used_count must be back to 1 after the post-release reservation.');

// === (e) release_usage() is idempotent: a second call neither double- ======
// === decrements nor drives used_count negative ==============================
reset_world();
seed_rule(104, null, 0, 5);

RuleModel::try_reserve_usage(104, 'user:D', 7001, 11);
assert_same(1, $GLOBALS['wpdb']->rules[104]['used_count'], '(e) Sanity: used_count is 1 after the single reservation.');

$release1 = RuleModel::release_usage(104, 7001);
assert_true($release1, '(e) The FIRST release_usage() call must succeed and report true.');
assert_same(0, $GLOBALS['wpdb']->rules[104]['used_count'], '(e) used_count must be 0 after the first release.');

$release2 = RuleModel::release_usage(104, 7001);
assert_same(false, $release2, '(e) A SECOND release_usage() call for the same rule/order (nothing left to release) must report false.');
assert_same(0, $GLOBALS['wpdb']->rules[104]['used_count'], '(e) The second, idempotent release_usage() call must NOT drive used_count negative or decrement further.');

// A third call, for good measure, must remain just as inert.
$release3 = RuleModel::release_usage(104, 7001);
assert_same(false, $release3, '(e) A THIRD release_usage() call must still be a safe no-op.');
assert_same(0, $GLOBALS['wpdb']->rules[104]['used_count'], '(e) used_count must still be 0 after a third redundant release call.');

// === (f) customer_redemption_count() reports the live reserved/confirmed ===
// === count directly =========================================================
reset_world();
seed_rule(105, null, 0, 10);

assert_same(0, RuleModel::customer_redemption_count(105, 'user:E'), '(f) customer_redemption_count() must be 0 before any reservation.');

RuleModel::try_reserve_usage(105, 'user:E', 8001, 12);
assert_same(1, RuleModel::customer_redemption_count(105, 'user:E'), '(f) customer_redemption_count() must reflect a single reserved redemption.');

RuleModel::try_reserve_usage(105, 'user:E', 8002, 12);
assert_same(2, RuleModel::customer_redemption_count(105, 'user:E'), '(f) customer_redemption_count() must reflect multiple reserved redemptions for the same customer.');

assert_same(0, RuleModel::customer_redemption_count(105, 'user:F'), '(f) customer_redemption_count() must stay 0 for an unrelated customer_key.');

RuleModel::release_usage(105, 8001);
assert_same(1, RuleModel::customer_redemption_count(105, 'user:E'), '(f) customer_redemption_count() must drop back down after a release.');

echo "Promo usage reservation OK\n";
