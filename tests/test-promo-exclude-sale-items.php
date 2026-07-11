<?php
/**
 * Standalone smoke test dedicated to the independent exclude_sale_items
 * field in PromoBridgeController — one place proving the FULL 2x2 matrix
 * of {exclusive, exclude_sale_items} x {true, false} for compile_coupon()
 * (Vía A) and the exclude_sale_items copy into wp_drw_rules for
 * compile_rule() (Vía B). No PHPUnit, no WooCommerce, no database — same
 * style as tests/test-promo-bridge-exclusivity.php.
 *
 * Individual pieces of this matrix are already exercised elsewhere
 * (tests/test-promo-bridge.php covers the Vía A 2x2 matrix in more detail
 * with revert/reuse scenarios; tests/test-promo-bridge-exclusivity.php
 * covers the Vía B copy plus a couple of Vía A smoke cases) — this file
 * does not re-derive those broader scenarios, it only asserts the same
 * 2x2 matrix compactly in one dedicated place per the exclude_sale_items
 * task spec, plus the Vía B copy check, so a reader looking specifically
 * for "does exclude_sale_items behave independently of exclusive" has one
 * file that answers it end-to-end for both compilation paths.
 *
 * $wpdb is a minimal in-memory stand-in (get_var/prepare/insert/update),
 * enough for compile_rule()'s "insert or update the one row per promo"
 * path — no real database.
 */

namespace Drw\App\Models {

    /**
     * In-memory stand-in for the real PromoModel used by compile()/compile_rule().
     */
    class PromoModel {
        /** @var array<int,array> Promo rows keyed by id. */
        public static $rows = array();
        /** @var array<int,array> Recorded update() payloads: [id => data]. */
        public static $updated = array();

        public static function reset() {
            self::$rows    = array();
            self::$updated = array();
        }

        public static function get_promo( $id ) {
            $id = (int) $id;
            return isset( self::$rows[ $id ] ) ? self::$rows[ $id ] : null;
        }

        public static function update( $id, $data ) {
            self::$updated[ (int) $id ] = $data;
            return 1;
        }
    }
}

namespace {

    define( 'ABSPATH', dirname( __DIR__ ) . '/' );

    function assert_same( $expected, $actual, $message ) {
        if ( $expected !== $actual ) {
            fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
            exit( 1 );
        }
    }

    function assert_true( $condition, $message ) {
        assert_same( true, (bool) $condition, $message );
    }

    // --- Minimal WP / WC shims -------------------------------------------------
    function __( $text, $domain = null ) { return $text; }
    function sanitize_text_field( $text ) { return is_string( $text ) ? trim( $text ) : $text; }
    function current_time( $type ) { return '2026-07-10 00:00:00'; }
    function wp_json_encode( $value ) { return json_encode( $value ); }

    // Controllable coupon lookup: default "no existing coupon".
    $GLOBALS['wc_coupon_id_by_code'] = 0;
    function wc_get_coupon_id_by_code( $code ) {
        return isset( $GLOBALS['wc_coupon_id_by_code'] ) ? (int) $GLOBALS['wc_coupon_id_by_code'] : 0;
    }

    /**
     * Minimal WC_Coupon stub, same shape as tests/test-promo-bridge.php's.
     */
    class WC_Coupon {
        public static $next_id = 700;
        public $id = 0;
        public $props = array();
        public $meta  = array();

        public function __construct( $id = 0 ) { $this->id = (int) $id; }

        public function set_code( $v )                 { $this->props['code'] = $v; }
        public function set_discount_type( $v )        { $this->props['discount_type'] = $v; }
        public function set_amount( $v )               { $this->props['amount'] = $v; }
        public function set_free_shipping( $v )        { $this->props['free_shipping'] = $v; }
        public function set_date_expires( $v )         { $this->props['date_expires'] = $v; }
        public function set_usage_limit( $v )          { $this->props['usage_limit'] = $v; }
        public function set_usage_limit_per_user( $v ) { $this->props['usage_limit_per_user'] = $v; }
        public function set_minimum_amount( $v )       { $this->props['minimum_amount'] = $v; }
        public function set_product_ids( $v )          { $this->props['product_ids'] = $v; }
        public function set_product_categories( $v )   { $this->props['product_categories'] = $v; }
        public function set_individual_use( $v )       { $this->props['individual_use'] = $v; }
        public function set_exclude_sale_items( $v )   { $this->props['exclude_sale_items'] = $v; }
        public function update_meta_data( $k, $v )     { $this->meta[ $k ] = $v; }
        public function get_meta( $k )                 { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
        public function get_id()                       { return $this->id; }
        public function save() {
            if ( 0 === $this->id ) {
                $this->id = self::$next_id++;
            }
            $GLOBALS['last_saved_coupon'] = $this;
            return $this->id;
        }
    }

    /**
     * Minimal $wpdb stand-in for compile_rule()'s "one row per promo"
     * idempotency check (SELECT id ... LIMIT 1) plus insert()/update().
     */
    class WpdbStub {
        public $prefix = 'wp_';
        public $insert_id = 0;
        /** @var array<int,array> wp_drw_rules rows keyed by id. */
        public $rows = array();
        private $next_id = 1;

        public function prepare( $query, ...$args ) {
            if ( 1 === count( $args ) && is_array( $args[0] ) ) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback(
                '/%[dsf]/',
                function ( $m ) use ( &$i, $args ) {
                    $arg = isset( $args[ $i ] ) ? $args[ $i ] : null;
                    $i++;
                    if ( '%d' === $m[0] ) {
                        return (string) (int) $arg;
                    }
                    if ( '%f' === $m[0] ) {
                        return (string) (float) $arg;
                    }
                    return "'" . addslashes( (string) $arg ) . "'";
                },
                $query
            );
        }

        public function get_var( $query ) {
            if ( preg_match( "/WHERE promo_id = (\\d+) AND source = 'promo'/", $query, $m ) ) {
                $promo_id = (int) $m[1];
                foreach ( $this->rows as $id => $row ) {
                    if ( (int) $row['promo_id'] === $promo_id ) {
                        return (string) $id;
                    }
                }
                return null;
            }
            return null;
        }

        public function insert( $table, $data ) {
            $id                = $this->next_id++;
            $data['id']        = $id;
            $this->rows[ $id ] = $data;
            $this->insert_id   = $id;
            return true;
        }

        public function update( $table, $data, $where ) {
            $id = (int) $where['id'];
            if ( isset( $this->rows[ $id ] ) ) {
                $this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
            }
            return 1;
        }
    }

    require_once dirname( __DIR__ ) . '/src/Models/PromoTypeRegistry.php';
    require_once dirname( __DIR__ ) . '/src/Models/RuleModel.php';
    require_once dirname( __DIR__ ) . '/src/Controllers/PromoBridgeController.php';

    use Drw\App\Controllers\PromoBridgeController;
    use Drw\App\Models\PromoModel;

    function make_promo( $overrides = array() ) {
        return array_merge(
            array(
                'id'                 => 1,
                'name'               => 'Promo de prueba',
                'code'               => '',
                'type'               => 'flash',
                'value'              => 15,
                'scope'              => null,
                'min_amount'         => 0,
                'limit_global'       => null,
                'limit_user'         => null,
                'date_from'          => null,
                'date_to'            => null,
                'active'             => true,
                'exclusive'          => false,
                'exclude_sale_items' => false,
                'gift_config'        => null,
                'tier_config'        => null,
                'wc_coupon_id'       => null,
            ),
            $overrides
        );
    }

    function reset_world() {
        PromoModel::reset();
        $GLOBALS['wpdb']                 = new WpdbStub();
        $GLOBALS['wc_coupon_id_by_code'] = 0;
        unset( $GLOBALS['last_saved_coupon'] );
    }

    $bridge = new PromoBridgeController();

    // =====================================================================
    // VÍA A (compile_coupon) — full 2x2 matrix of
    // {exclusive, exclude_sale_items} x {true, false}. individual_use tracks
    // ONLY exclusive; exclude_sale_items (the coupon flag) tracks ONLY
    // exclude_sale_items -- the two must vary independently in every
    // combination, never leaking into each other.
    // =====================================================================

    $matrix = array(
        array( 'exclusive' => false, 'exclude_sale_items' => false, 'code' => 'M_FF' ),
        array( 'exclusive' => true,  'exclude_sale_items' => false, 'code' => 'M_TF' ),
        array( 'exclusive' => false, 'exclude_sale_items' => true,  'code' => 'M_FT' ),
        array( 'exclusive' => true,  'exclude_sale_items' => true,  'code' => 'M_TT' ),
    );

    $promo_id = 100;
    foreach ( $matrix as $case ) {
        reset_world();
        $promo_id++;
        PromoModel::$rows[ $promo_id ] = make_promo( array(
            'id'                 => $promo_id,
            'type'               => 'percent', // needs_code() type -> compiles via Vía A.
            'code'               => $case['code'],
            'value'              => 20,
            'exclusive'          => $case['exclusive'],
            'exclude_sale_items' => $case['exclude_sale_items'],
        ) );

        $result = $bridge->compile( $promo_id );
        assert_same( 'A', $result['via'], "Promo {$case['code']} must compile through Vía A." );

        $coupon = $GLOBALS['last_saved_coupon'];
        assert_true( $coupon instanceof WC_Coupon, "Promo {$case['code']} must produce a saved WC_Coupon." );

        assert_same(
            $case['exclusive'],
            $coupon->props['individual_use'],
            "Promo {$case['code']}: individual_use must equal exclusive ({$case['exclusive']}) regardless of exclude_sale_items."
        );
        assert_same(
            $case['exclude_sale_items'],
            $coupon->props['exclude_sale_items'],
            "Promo {$case['code']}: exclude_sale_items must equal its own field ({$case['exclude_sale_items']}) regardless of exclusive."
        );
    }

    // =====================================================================
    // VÍA B (compile_rule) — exclude_sale_items must be copied verbatim into
    // wp_drw_rules.exclude_sale_items, independently of exclusive, and must
    // survive an idempotent re-compile (update path) too.
    // =====================================================================

    // (true, false)
    reset_world();
    PromoModel::$rows[200] = make_promo( array(
        'id'                 => 200,
        'type'               => 'flash',
        'value'              => 20,
        'exclusive'          => false,
        'exclude_sale_items' => true,
    ) );
    $result_rule_1 = $bridge->compile( 200 );
    assert_same( 'B', $result_rule_1['via'], 'An automatic promo type must compile through Vía B.' );
    $row_1 = $GLOBALS['wpdb']->rows[ $result_rule_1['rule_id'] ];
    assert_same( 0, $row_1['exclusive'], 'Vía B: exclusive must stay 0 when the promo is not exclusive.' );
    assert_same( 1, $row_1['exclude_sale_items'], 'Vía B: exclude_sale_items=true must copy to wp_drw_rules.exclude_sale_items = 1.' );

    // Re-compile the SAME promo with exclude_sale_items flipped off -> the
    // existing row's column must flip back to 0, not stay stuck at 1.
    PromoModel::$rows[200] = make_promo( array(
        'id'                 => 200,
        'type'               => 'flash',
        'value'              => 20,
        'exclusive'          => false,
        'exclude_sale_items' => false,
    ) );
    $result_rule_1b = $bridge->compile( 200 );
    assert_same( $result_rule_1['rule_id'], $result_rule_1b['rule_id'], 'Re-compiling the same promo must reuse the existing rule row.' );
    assert_same( 0, $GLOBALS['wpdb']->rows[ $result_rule_1['rule_id'] ]['exclude_sale_items'], 'Re-compiling with exclude_sale_items=false must flip the SAME row back to 0.' );

    // (true, true) -- both dimensions on at once, Vía B.
    reset_world();
    PromoModel::$rows[201] = make_promo( array(
        'id'                 => 201,
        'type'               => 'flash',
        'value'              => 20,
        'exclusive'          => true,
        'exclude_sale_items' => true,
    ) );
    $result_rule_2 = $bridge->compile( 201 );
    $row_2         = $GLOBALS['wpdb']->rows[ $result_rule_2['rule_id'] ];
    assert_same( 1, $row_2['exclusive'], 'Vía B: exclusive=true must copy to exclusive = 1.' );
    assert_same( 1, $row_2['exclude_sale_items'], 'Vía B: exclude_sale_items=true must copy to exclude_sale_items = 1, alongside exclusive=1.' );

    echo "Promo exclude_sale_items OK\n";
}
