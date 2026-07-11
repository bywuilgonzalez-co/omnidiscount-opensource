<?php
/**
 * Standalone smoke test for PromoBridgeController::compile_rule() (Vía B) —
 * proves that the wp_drw_rules.exclusive column is driven by the promo's
 * OWN exclusive flag instead of the old hardcoded 0. No PHPUnit, no
 * WooCommerce, no database — same style as tests/test-promo-bridge.php,
 * which already covers the parallel Vía A behaviour (exclusive=true/false
 * flipping WC_Coupon's individual_use/exclude_sale_items). This file only
 * adds the Vía B ($wpdb 'exclusive' column) coverage that file does not
 * touch, plus a couple of narrow Vía A ("Vía A coupon path") checks reusing
 * the exact WC_Coupon stub tests/test-promo-bridge.php already established,
 * so nothing here duplicates that file's assertions.
 *
 * $wpdb is a minimal in-memory stand-in (get_var/prepare/insert/update),
 * enough for compile_rule()'s "insert or update the one row per promo" path
 * — no real database.
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

    // Controllable coupon lookup: default "no existing coupon". Only exercised
    // by the Vía A cases below.
    $GLOBALS['wc_coupon_id_by_code'] = 0;
    function wc_get_coupon_id_by_code( $code ) {
        return isset( $GLOBALS['wc_coupon_id_by_code'] ) ? (int) $GLOBALS['wc_coupon_id_by_code'] : 0;
    }

    /**
     * Minimal WC_Coupon stub, same shape as tests/test-promo-bridge.php's, so
     * the "Vía A coupon path" cases below reuse the exact stubbing approach
     * rather than inventing a new one.
     */
    class WC_Coupon {
        public static $next_id = 500;
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
     * Rows are stored in-memory keyed by an incrementing id.
     */
    class WpdbStub {
        public $prefix = 'wp_';
        public $insert_id = 0;
        /** @var array<int,array> wp_drw_rules rows keyed by id. */
        public $rows = array();
        /** @var array Recorded update($table, $data, $where) calls. */
        public $update_calls = array();
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
            // "SELECT id FROM wp_drw_rules WHERE promo_id = X AND source = 'promo' LIMIT 1"
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
            $id                 = $this->next_id++;
            $data['id']         = $id;
            $this->rows[ $id ]  = $data;
            $this->insert_id    = $id;
            return true;
        }

        public function update( $table, $data, $where ) {
            $this->update_calls[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
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
                'type'               => 'flash', // automatic/rule type -> compiles via Vía B.
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
    }

    $bridge = new PromoBridgeController();

    // =====================================================================
    // VÍA B — wp_drw_rules.exclusive must mirror promo['exclusive'], not a
    // hardcoded 0.
    // =====================================================================

    // (a) exclusive=true on a NEW rule -> exclusive column is 1.
    reset_world();
    PromoModel::$rows[10] = make_promo( array(
        'id'        => 10,
        'type'      => 'flash',
        'value'     => 20,
        'exclusive' => true,
    ) );

    $result = $bridge->compile( 10 );
    assert_same( 'B', $result['via'], 'An automatic promo type must compile through Vía B.' );

    $rule_id = $result['rule_id'];
    assert_true( isset( $GLOBALS['wpdb']->rows[ $rule_id ] ), 'compile_rule() must insert a wp_drw_rules row.' );
    assert_same( 1, $GLOBALS['wpdb']->rows[ $rule_id ]['exclusive'], "exclusive=true must compile to wp_drw_rules.exclusive = 1 (int), not the old hardcoded 0." );

    // (b) exclusive=false on a NEW rule -> exclusive column is 0.
    reset_world();
    PromoModel::$rows[11] = make_promo( array(
        'id'        => 11,
        'type'      => 'flash',
        'value'     => 20,
        'exclusive' => false,
    ) );

    $result_b = $bridge->compile( 11 );
    $rule_id_b = $result_b['rule_id'];
    assert_same( 0, $GLOBALS['wpdb']->rows[ $rule_id_b ]['exclusive'], 'exclusive=false must compile to wp_drw_rules.exclusive = 0.' );

    // (c) Toggling exclusive true -> false on the SAME promo (re-compile, the
    // idempotent "update existing row" branch) must flip the stored column
    // back to 0, not leave a stale 1 from the earlier compile.
    reset_world();
    PromoModel::$rows[12] = make_promo( array(
        'id'        => 12,
        'type'      => 'flash',
        'value'     => 20,
        'exclusive' => true,
    ) );
    $first  = $bridge->compile( 12 );
    $rule_id_c = $first['rule_id'];
    assert_same( 1, $GLOBALS['wpdb']->rows[ $rule_id_c ]['exclusive'], 'First compile with exclusive=true must set exclusive = 1.' );

    PromoModel::$rows[12] = make_promo( array(
        'id'        => 12,
        'type'      => 'flash',
        'value'     => 20,
        'exclusive' => false,
    ) );
    $second = $bridge->compile( 12 );
    assert_same( $rule_id_c, $second['rule_id'], 'Re-compiling the same promo must reuse the existing rule row (one rule per promo).' );
    assert_same( 0, $GLOBALS['wpdb']->rows[ $rule_id_c ]['exclusive'], 'Re-compiling with exclusive=false must flip the SAME row\'s exclusive column back to 0.' );

    // (d) A promo with 'exclusive' entirely absent from the array (e.g. an
    // older row predating the column) must fall back to the safe default (0),
    // via the same !empty() treatment compile_rule() already uses elsewhere.
    reset_world();
    $promo_no_key = make_promo( array( 'id' => 13, 'type' => 'flash', 'value' => 20 ) );
    unset( $promo_no_key['exclusive'] );
    PromoModel::$rows[13] = $promo_no_key;

    $result_d = $bridge->compile( 13 );
    assert_same( 0, $GLOBALS['wpdb']->rows[ $result_d['rule_id'] ]['exclusive'], 'A promo row missing the exclusive key entirely must default to 0, never a PHP notice/true.' );

    // =====================================================================
    // VÍA B — wp_drw_rules.exclude_sale_items must mirror promo's OWN
    // exclude_sale_items field, INDEPENDENTLY of exclusive (decoupled dimension
    // 2: "does this promo apply to items already on sale?").
    // =====================================================================

    // (e) exclude_sale_items=true, exclusive=false -> exclude_sale_items column
    // is 1 while exclusive stays 0 -- proves the two columns vary independently.
    reset_world();
    PromoModel::$rows[14] = make_promo( array(
        'id'                 => 14,
        'type'               => 'flash',
        'value'              => 20,
        'exclusive'          => false,
        'exclude_sale_items' => true,
    ) );
    $result_e = $bridge->compile( 14 );
    $row_e    = $GLOBALS['wpdb']->rows[ $result_e['rule_id'] ];
    assert_same( 0, $row_e['exclusive'], 'exclude_sale_items=true must NOT also flip exclusive.' );
    assert_same( 1, $row_e['exclude_sale_items'], 'exclude_sale_items=true must compile to wp_drw_rules.exclude_sale_items = 1.' );

    // (f) the mirror image: exclusive=true, exclude_sale_items=false.
    reset_world();
    PromoModel::$rows[15] = make_promo( array(
        'id'                 => 15,
        'type'               => 'flash',
        'value'              => 20,
        'exclusive'          => true,
        'exclude_sale_items' => false,
    ) );
    $result_f = $bridge->compile( 15 );
    $row_f    = $GLOBALS['wpdb']->rows[ $result_f['rule_id'] ];
    assert_same( 1, $row_f['exclusive'], 'exclusive=true must still compile to exclusive = 1.' );
    assert_same( 0, $row_f['exclude_sale_items'], 'exclusive=true must NOT also flip exclude_sale_items.' );

    // (g) a promo row missing 'exclude_sale_items' entirely must default to 0
    // (same !empty() safety as the pre-existing 'exclusive' handling).
    reset_world();
    $promo_no_esi = make_promo( array( 'id' => 16, 'type' => 'flash', 'value' => 20 ) );
    unset( $promo_no_esi['exclude_sale_items'] );
    PromoModel::$rows[16] = $promo_no_esi;

    $result_g = $bridge->compile( 16 );
    assert_same( 0, $GLOBALS['wpdb']->rows[ $result_g['rule_id'] ]['exclude_sale_items'], 'A promo row missing exclude_sale_items entirely must default to 0.' );

    // =====================================================================
    // VÍA A — coupon path sanity: reuses the WC_Coupon stub above the same
    // way tests/test-promo-bridge.php does, confirming exclusivity here is
    // scoped to Vía B and does not regress Vía A's own individual_use /
    // exclude_sale_items wiring (already covered in depth by
    // tests/test-promo-bridge.php — this is a narrow smoke check, not a
    // duplicate of that coverage).
    // =====================================================================

    // (h) exclusive=true alone (exclude_sale_items defaults false) must set
    // ONLY individual_use, never exclude_sale_items -- the two native
    // WooCommerce flags are driven by two independent promo fields now.
    reset_world();
    PromoModel::$rows[20] = make_promo( array(
        'id'        => 20,
        'type'      => 'percent', // needs_code() type -> compiles via Vía A.
        'code'      => 'VIPCODE',
        'value'     => 20,
        'exclusive' => true,
    ) );

    $coupon_result = $bridge->compile( 20 );
    assert_same( 'A', $coupon_result['via'], 'A code-based promo type must compile through Vía A, not Vía B.' );

    $coupon = $GLOBALS['last_saved_coupon'];
    assert_true( $coupon instanceof WC_Coupon, 'compile() via Vía A must produce a saved WC_Coupon.' );
    assert_same( true, $coupon->props['individual_use'], 'Vía A exclusive=true must still set individual_use(true) on the coupon.' );
    assert_same( false, $coupon->props['exclude_sale_items'], 'Vía A exclusive=true alone must NOT set exclude_sale_items -- it is now driven by its own independent field.' );

    // (i) exclude_sale_items=true alone (exclusive stays false) must set ONLY
    // exclude_sale_items on the coupon -- the mirror image of (h).
    reset_world();
    PromoModel::$rows[21] = make_promo( array(
        'id'                 => 21,
        'type'               => 'percent',
        'code'               => 'SALEEXCL',
        'value'              => 20,
        'exclusive'          => false,
        'exclude_sale_items' => true,
    ) );
    $bridge->compile( 21 );
    $esi_coupon = $GLOBALS['last_saved_coupon'];
    assert_same( false, $esi_coupon->props['individual_use'], 'exclude_sale_items=true alone must NOT set individual_use.' );
    assert_same( true, $esi_coupon->props['exclude_sale_items'], 'exclude_sale_items=true alone must set exclude_sale_items(true) on the coupon.' );

    // build_coupon_data() (the pure Vía A payload builder) never emits an
    // 'exclusive' or 'exclude_sale_items' key itself -- both flags are realised
    // on WC_Coupon's own native individual_use/exclude_sale_items properties,
    // not custom columns in the builder's payload.
    $coupon_data = $bridge->build_coupon_data( make_promo( array( 'type' => 'percent', 'value' => 20, 'exclusive' => true, 'exclude_sale_items' => true ) ) );
    assert_true( !array_key_exists( 'exclusive', $coupon_data ), 'build_coupon_data() must not emit a raw \'exclusive\' key -- Vía A realises exclusivity via native coupon flags only.' );
    assert_true( !array_key_exists( 'exclude_sale_items', $coupon_data ), 'build_coupon_data() must not emit a raw \'exclude_sale_items\' key either -- same reasoning.' );

    echo "Promo bridge exclusivity OK\n";
}
