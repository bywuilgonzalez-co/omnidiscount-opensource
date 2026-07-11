<?php
/**
 * Standalone smoke test for the manual-rule overlap detection that extends
 * PromosController::check_conflicts() (POST /drw/v1/promos/check-conflicts) to
 * hand-authored wp_drw_rules rows, not just promos.
 *
 * Same style as tests/test-rate-limiter.php: no PHPUnit, no WooCommerce, no
 * database. It exercises the PURE, DB-free helpers the endpoint delegates to --
 * rule_row_overlaps_draft() and rule_scope_envelope() -- via reflection, since
 * they are private. Those helpers only ever call native PHP (gmdate, json_decode,
 * array_intersect...) plus the existing dates_overlap()/scopes_overlap() the
 * promo path already uses, so no WordPress stubs are required.
 *
 * KNOWN SCOPE LIMITATION (deliberate, same spirit as test-rate-limiter.php's
 * note): this does NOT cover find_rule_overlaps() itself, which runs the real
 * $wpdb query. That wrapper is a thin loop over rule_row_overlaps_draft(); the
 * per-row decision -- the part with real logic -- is what is proven here.
 */

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

function assert_false( $condition, $message ) {
	assert_same( false, (bool) $condition, $message );
}

require_once dirname( __DIR__ ) . '/src/Controllers/PromosController.php';

use Drw\App\Controllers\PromosController;

$controller = PromosController::instance();

/**
 * Invoke a private PromosController method by name.
 *
 * @param object $obj    Controller instance.
 * @param string $method Method name.
 * @param array  $args   Positional args.
 * @return mixed
 */
function call_private( $obj, $method, array $args ) {
	$m = new ReflectionMethod( $obj, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $obj, $args );
}

// A fixed June-2026 window for the stored rule, expressed as UNIX timestamps
// the way wp_drw_rules stores date_from/date_to. gmmktime + gmdate keep the
// test timezone-independent (mirrors the endpoint's gmdate('Y-m-d', ts)).
$row_from = gmmktime( 0, 0, 0, 6, 1, 2026 );  // 2026-06-01
$row_to   = gmmktime( 0, 0, 0, 6, 30, 2026 ); // 2026-06-30

// --- rule_scope_envelope() mapping ---------------------------------------

$env_products = call_private(
	$controller,
	'rule_scope_envelope',
	array( array( 'apply_to' => 'specific_products', 'filters' => array( 'product_ids' => array( 10, 20 ), 'category_ids' => array() ) ) )
);
assert_same( 'products', $env_products['target'], 'specific_products maps to target "products"' );
assert_same( array( 10, 20 ), $env_products['product_ids'], 'product ids carried through' );
assert_same( array(), $env_products['category_ids'], 'category ids empty for a products rule' );

$env_categories = call_private(
	$controller,
	'rule_scope_envelope',
	array( array( 'apply_to' => 'specific_categories', 'filters' => array( 'category_ids' => array( 5, 6 ) ) ) )
);
assert_same( 'categories', $env_categories['target'], 'specific_categories maps to target "categories"' );
assert_same( array( 5, 6 ), $env_categories['category_ids'], 'category ids carried through' );

$env_all = call_private(
	$controller,
	'rule_scope_envelope',
	array( array( 'apply_to' => 'all_products', 'filters' => array() ) )
);
assert_same( 'all', $env_all['target'], 'all_products maps to target "all"' );

// filters as a raw JSON string (the un-decoded DB column shape) still maps.
$env_json = call_private(
	$controller,
	'rule_scope_envelope',
	array( array( 'apply_to' => 'specific_products', 'filters' => '{"product_ids":[42],"category_ids":[]}' ) )
);
assert_same( array( 42 ), $env_json['product_ids'], 'JSON-string filters are decoded before mapping' );

// --- rule_row_overlaps_draft() decisions ---------------------------------

$row = array(
	'id'        => 7,
	'title'     => 'Regla verano',
	'apply_to'  => 'specific_products',
	'filters'   => array( 'product_ids' => array( 10, 20 ) ),
	'date_from' => $row_from,
	'date_to'   => $row_to,
);

// (1) Shared product (20) + overlapping dates -> overlap.
assert_true(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, array( 'target' => 'products', 'ids' => array( 20, 30 ) ), '2026-06-15', '2026-07-15', null ) ),
	'shared product within an overlapping date window overlaps'
);

// (2) Shared product but the draft window is entirely after the rule -> no overlap.
assert_false(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, array( 'target' => 'products', 'ids' => array( 20 ) ), '2026-08-01', '2026-08-31', null ) ),
	'a non-overlapping date window is not a conflict even with a shared product'
);

// (3) Overlapping dates but disjoint product sets -> no overlap.
assert_false(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, array( 'target' => 'products', 'ids' => array( 30, 40 ) ), '2026-06-10', '2026-06-20', null ) ),
	'disjoint product sets do not overlap'
);

// (4) Draft targets "all" -> overlaps any scoped rule (within dates).
assert_true(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, array( 'target' => 'all', 'ids' => array() ), '2026-06-10', '', null ) ),
	'a sitewide draft overlaps a scoped rule'
);

// (5) exclude_rule_id matching the row is skipped (editing that very rule).
assert_false(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, array( 'target' => 'products', 'ids' => array( 20 ) ), '2026-06-15', '2026-07-15', 7 ) ),
	'the rule being edited never conflicts with itself'
);

// (6) Sitewide rule (all_products) overlaps a product-scoped draft.
$row_all = array( 'id' => 8, 'title' => '', 'apply_to' => 'all_products', 'filters' => array(), 'date_from' => null, 'date_to' => null );
assert_true(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row_all, array( 'target' => 'products', 'ids' => array( 999 ) ), '', '', null ) ),
	'an open-ended sitewide rule overlaps everything'
);

// (7) Legacy free-form (non-array) draft scope is skipped, not fatal.
assert_false(
	call_private( $controller, 'rule_row_overlaps_draft', array( $row, 'legacy string scope', '2026-06-15', '2026-07-15', null ) ),
	'a non-structural draft scope is safely ignored'
);

fwrite( STDOUT, "PASS: test-conflict-checker-rules.php (" . 14 . " assertions)\n" );
