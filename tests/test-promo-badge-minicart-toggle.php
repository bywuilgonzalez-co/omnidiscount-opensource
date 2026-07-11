<?php
/**
 * Standalone smoke test for PromoBadgeHelper::collect()'s new opt-in gate:
 * wp_drw_promos.show_in_minicart (default OFF). Only a promo-compiled rule
 * whose promo has show_in_minicart=true may produce a mini-cart badge; every
 * other collect() behaviour (threshold progress, is_rule_matched() fallback,
 * cart_message templating) is already covered by
 * tests/test-minicart-promos-render.php (which stubs PromoBadgeHelper
 * entirely) and is not re-tested here.
 *
 * No PHPUnit, no WooCommerce, no database — same style as
 * tests/test-promo-bridge-exclusivity.php: stub
 * Drw\App\Controllers\RulesEngine and Drw\App\Models\PromoModel in their real
 * namespaces (never requiring the real files), a minimal WC_Cart stand-in,
 * and hard-failing assert helpers.
 *
 * Coverage:
 *   (a) promo_id>0, source='promo', promo.show_in_minicart=true, rule
 *       matched -> badge included.
 *   (b) Same rule, but promo.show_in_minicart=false (the default) -> badge
 *       excluded even though the rule is otherwise applied.
 *   (c) A promo row missing the show_in_minicart key entirely (a row that
 *       predates the column) must default to HIDDEN, never shown — proves
 *       the opt-in default is safe against older rows too, not just
 *       explicit false.
 *   (d) A hand-authored "Regla avanzada" (source !== 'promo', no promo_id)
 *       is skipped for the SAME reason it always was (no promo to look up),
 *       entirely unaffected by the new gate — regression check that the new
 *       show_in_minicart branch sits AFTER, not instead of, the existing
 *       "no promo" continue.
 *   (e) Multiple rules: only the promo with show_in_minicart=true survives,
 *       proving the gate is per-promo, not global.
 */

namespace Drw\App\Controllers {

    /**
     * In-memory stand-in for RulesEngine. PromoBadgeHelper::collect() only
     * calls instance()->get_active_rules() and ->is_rule_matched(), both
     * fully controlled here.
     */
    class RulesEngine {
        private static $instance;

        /** @var array List of rule rows returned by get_active_rules(). */
        public static $rules = array();
        /** @var bool Value returned by is_rule_matched() for every rule. */
        public static $matched = true;

        public static function reset() {
            self::$rules   = array();
            self::$matched = true;
            self::$instance = null;
        }

        public static function instance() {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function get_active_rules() {
            return self::$rules;
        }

        public function is_rule_matched($rule, $cart) {
            return self::$matched;
        }
    }
}

namespace Drw\App\Models {

    /**
     * In-memory stand-in for PromoModel. get_promo() is the only method
     * PromoBadgeHelper::collect() calls.
     */
    class PromoModel {
        /** @var array<int,array|null> Promo rows keyed by id. */
        public static $rows = array();

        public static function reset() {
            self::$rows = array();
        }

        public static function get_promo($id) {
            $id = (int) $id;
            return isset(self::$rows[$id]) ? self::$rows[$id] : null;
        }
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
        assert_same(true, (bool) $condition, $message);
    }

    // --- Minimal WP/WC shims used by collect() on the "applied" path ---
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
    function wc_price($amount) { return (string) $amount; }
    function wc_format_decimal($number, $decimals = 2) { return number_format((float) $number, $decimals, '.', ''); }

    /**
     * Minimal WC_Cart stand-in: collect() only calls is_empty() up front
     * (none of these test rules exercise the free_shipping/min_subtotal
     * progress branch, so get_subtotal() is never reached). get_cart() is
     * only reached by the new exclude_sale_items best-effort re-check, so it
     * defaults to empty and is only populated by the cases that need it.
     */
    class WC_Cart {
        private $items;
        public function __construct($items = array()) { $this->items = $items; }
        public function is_empty() { return false; }
        public function get_cart() { return $this->items; }
    }

    /** Minimal WC_Product stand-in: only is_on_sale() is read. */
    class WC_Product {
        private $on_sale;
        public function __construct($on_sale) { $this->on_sale = (bool) $on_sale; }
        public function is_on_sale() { return $this->on_sale; }
    }

    require_once dirname(__DIR__) . '/src/Models/PromoBadgeHelper.php';

    use Drw\App\Controllers\RulesEngine;
    use Drw\App\Models\PromoModel;
    use Drw\App\Models\PromoBadgeHelper;

    function reset_world() {
        RulesEngine::reset();
        PromoModel::reset();
    }

    function make_rule($overrides = array()) {
        return array_merge(
            array(
                'id'          => 1,
                'promo_id'    => 10,
                'source'      => 'promo',
                'title'       => 'Cyber Week',
                'adjustments' => array('type' => 'percentage', 'value' => 15),
            ),
            $overrides
        );
    }

    function make_promo($overrides = array()) {
        return array_merge(
            array(
                'id'                => 10,
                'type'              => 'percentage',
                'cart_message'      => '15% off applied',
                'show_in_minicart'  => true,
            ),
            $overrides
        );
    }

    $cart = new WC_Cart();

    // =====================================================================
    // (a) show_in_minicart=true, rule matched -> badge included.
    // =====================================================================
    reset_world();
    RulesEngine::$rules   = array(make_rule());
    RulesEngine::$matched = true;
    PromoModel::$rows[10] = make_promo(array('show_in_minicart' => true));

    $badges = PromoBadgeHelper::collect($cart);
    assert_same(1, count($badges), 'show_in_minicart=true with a matched rule must produce exactly one badge.');
    assert_same(10, $badges[0]['promo_id'], 'The one badge must be for the promo that opted in.');

    // =====================================================================
    // (b) show_in_minicart=false (the default) -> badge excluded even though
    // the rule is otherwise applied.
    // =====================================================================
    reset_world();
    RulesEngine::$rules   = array(make_rule());
    RulesEngine::$matched = true;
    PromoModel::$rows[10] = make_promo(array('show_in_minicart' => false));

    $badges = PromoBadgeHelper::collect($cart);
    assert_same(0, count($badges), 'show_in_minicart=false must suppress the badge even for an applied promo.');

    // =====================================================================
    // (c) A promo row missing show_in_minicart entirely (predates the
    // column) must default to HIDDEN, not shown.
    // =====================================================================
    reset_world();
    RulesEngine::$rules   = array(make_rule());
    RulesEngine::$matched = true;
    $promo_no_key = make_promo();
    unset($promo_no_key['show_in_minicart']);
    PromoModel::$rows[10] = $promo_no_key;

    $badges = PromoBadgeHelper::collect($cart);
    assert_same(0, count($badges), 'A promo row missing show_in_minicart entirely must default to hidden, never a PHP notice/shown badge.');

    // =====================================================================
    // (d) A hand-authored "Regla avanzada" (source !== 'promo', no promo_id)
    // is skipped for the pre-existing "no promo to look up" reason, entirely
    // unaffected by the new show_in_minicart gate.
    // =====================================================================
    reset_world();
    RulesEngine::$rules = array(make_rule(array(
        'id'       => 2,
        'promo_id' => 0,
        'source'   => null,
        'title'    => 'Regla manual 10% categoría X',
    )));
    RulesEngine::$matched = true;
    // Deliberately no PromoModel row at all — a manual rule has none.

    $badges = PromoBadgeHelper::collect($cart);
    assert_same(0, count($badges), 'A hand-authored rule with no promo must still be skipped, same as before this change.');

    // =====================================================================
    // (e) Multiple rules: only the promo with show_in_minicart=true
    // survives — proves the gate is evaluated per-promo, not once globally.
    // =====================================================================
    reset_world();
    RulesEngine::$rules = array(
        make_rule(array('id' => 1, 'promo_id' => 10, 'title' => 'Opted in')),
        make_rule(array('id' => 2, 'promo_id' => 11, 'title' => 'Opted out')),
    );
    RulesEngine::$matched = true;
    PromoModel::$rows[10] = make_promo(array('id' => 10, 'show_in_minicart' => true, 'cart_message' => 'Shown'));
    PromoModel::$rows[11] = make_promo(array('id' => 11, 'show_in_minicart' => false, 'cart_message' => 'Hidden'));

    $badges = PromoBadgeHelper::collect($cart);
    assert_same(1, count($badges), 'Only the promo that opted in must produce a badge.');
    assert_same(10, $badges[0]['promo_id'], 'The surviving badge must be the opted-in promo, not the opted-out one.');

    // =====================================================================
    // (f) Round-10 audit finding: exclude_sale_items=true rules must not
    // report applied=true when the entire cart is on sale (is_rule_matched()
    // only checks conditions, not the sale-item guard). A cart with at least
    // one non-sale item must still report applied=true.
    // =====================================================================
    reset_world();
    // exclude_sale_items lives on the rule row itself (RuleModel's compiled
    // shape), not inside adjustments.
    RulesEngine::$rules   = array(make_rule(array('exclude_sale_items' => true)));
    RulesEngine::$matched = true;
    PromoModel::$rows[10] = make_promo(array('show_in_minicart' => true));

    $all_on_sale_cart = new WC_Cart(array(
        array('data' => new WC_Product(true)),
    ));
    $badges = PromoBadgeHelper::collect($all_on_sale_cart);
    assert_same(1, count($badges), 'A cart with only on-sale items still produces a badge entry (visible, but not "applied").');
    assert_same(false, $badges[0]['applied'], 'exclude_sale_items=true + an all-on-sale cart must report applied=false, not a false "applied: true".');

    $mixed_cart = new WC_Cart(array(
        array('data' => new WC_Product(true)),
        array('data' => new WC_Product(false)),
    ));
    $badges = PromoBadgeHelper::collect($mixed_cart);
    assert_same(true, $badges[0]['applied'], 'exclude_sale_items=true + a cart with at least one non-sale item must still report applied=true.');

    // Same rule shape, exclude_sale_items=false: an all-on-sale cart must
    // still report applied=true, unchanged (default) behaviour.
    reset_world();
    RulesEngine::$rules   = array(make_rule());
    RulesEngine::$matched = true;
    PromoModel::$rows[10] = make_promo(array('show_in_minicart' => true));
    $badges = PromoBadgeHelper::collect($all_on_sale_cart);
    assert_same(true, $badges[0]['applied'], 'exclude_sale_items absent/false: applied must be unaffected by sale status, unchanged behaviour.');

    echo "PromoBadgeHelper mini-cart opt-in toggle OK\n";
}
