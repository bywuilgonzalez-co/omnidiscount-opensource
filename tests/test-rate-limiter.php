<?php
/**
 * Standalone smoke test for RateLimiter::check() -- the basic transient-backed,
 * defense-in-depth rate limiter used by PromosController::check_code_availability()
 * and PromosController::check_conflicts(). No PHPUnit, no WooCommerce, no
 * database -- same style as tests/test-rule-value-clamping.php: minimal WP
 * function stubs, and hard-failing assert helpers.
 *
 * get_transient()/set_transient() are stubbed with an in-memory array under
 * $GLOBALS['drw_test_transients'], the same "fake wp_options" approach
 * tests/test-promo-migration.php uses for get_option()/update_option().
 *
 * KNOWN SCOPE LIMITATION (documented deliberately, same spirit as the "no
 * PHPUnit / no WooCommerce / no database" notes above): this stub does NOT
 * enforce the transient's expiration/TTL the way real WordPress transients do
 * (WP checks the stored `_transient_timeout_*` option against the current
 * time inside get_transient()). It simply stores whatever set_transient() was
 * last called with. Consequently this test proves the *counting* and
 * *bucket-isolation* behaviour of RateLimiter::check() -- the numbered
 * max+1 request being blocked, and independent buckets not cross-contaminating
 * -- but it does NOT prove that a bucket actually clears once $window_seconds
 * of real wall-clock time has elapsed. That would require either mocking
 * time-based transient expiry or an integration test against real WordPress.
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

// --- In-memory stand-in for the transients API (no real expiry -- see the
// scope note in the file docblock above). ---
$GLOBALS['drw_test_transients'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['drw_test_transients'] )
		? $GLOBALS['drw_test_transients'][ $key ]['value']
		: false;
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['drw_test_transients'][ $key ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}

function reset_world() {
	$GLOBALS['drw_test_transients'] = array();
}

require_once dirname( __DIR__ ) . '/src/Controllers/RateLimiter.php';

use Drw\App\Controllers\RateLimiter;

// --- (a) the (max + 1)-th attempt inside the window is blocked -------------
reset_world();

$bucket = 'check-code:1';
$max    = 3;

for ( $i = 1; $i <= $max; $i++ ) {
	assert_true( RateLimiter::check( $bucket, $max, 60 ), "Attempt {$i} of {$max} must be allowed." );
}

assert_same( false, RateLimiter::check( $bucket, $max, 60 ), 'The (max + 1)-th attempt within the window must be blocked.' );

// A blocked attempt must not silently keep incrementing the stored counter
// past the max -- calling again while still blocked must still be false.
assert_same( false, RateLimiter::check( $bucket, $max, 60 ), 'A further attempt while still blocked must remain blocked.' );

// Confirm the transient key follows the documented 'drw_rl_' . md5($bucket) scheme.
$expected_key = 'drw_rl_' . md5( $bucket );
assert_true( array_key_exists( $expected_key, $GLOBALS['drw_test_transients'] ), 'RateLimiter must store its counter under drw_rl_ . md5($bucket).' );
assert_same( $max, $GLOBALS['drw_test_transients'][ $expected_key ]['value'], 'Stored count must stop at max_attempts, never exceeding it.' );

// --- (b) different bucket names never interfere with each other ------------
reset_world();

$bucket_a = 'check-code:42';
$bucket_b = 'check-conflicts:42';

// Exhaust bucket A completely.
for ( $i = 1; $i <= 2; $i++ ) {
	assert_true( RateLimiter::check( $bucket_a, 2, 60 ), "Bucket A attempt {$i} must be allowed." );
}
assert_same( false, RateLimiter::check( $bucket_a, 2, 60 ), 'Bucket A must be blocked once its own max is reached.' );

// Bucket B, with the same user id but a different action, must be completely
// unaffected -- same "check-conflicts:" vs "check-code:" separation
// PromosController uses so the two endpoints never share a counter.
assert_true( RateLimiter::check( $bucket_b, 2, 60 ), 'A distinct bucket must not be affected by another exhausted bucket.' );
assert_true( RateLimiter::check( $bucket_b, 2, 60 ), 'Bucket B must still have its own full quota available.' );
assert_same( false, RateLimiter::check( $bucket_b, 2, 60 ), 'Bucket B blocks independently once IT reaches its own max.' );

// Two buckets that only differ by user id (same action) must also stay isolated.
reset_world();
assert_true( RateLimiter::check( 'check-code:1', 1, 60 ), 'User 1 gets their first allowed attempt.' );
assert_same( false, RateLimiter::check( 'check-code:1', 1, 60 ), 'User 1 is blocked after their single allowed attempt.' );
assert_true( RateLimiter::check( 'check-code:2', 1, 60 ), 'User 2 must have an independent quota from user 1.' );

echo "RateLimiter OK\n";
