<?php
/**
 * Focused smoke test for PromosController::toggle_promo() re-validation.
 *
 * Closes a real exploit path: a promo row that was imported/written before the
 * current validation rules (e.g. value = 500 on a `percent` type) could be
 * flipped active via the toggle endpoint and then compiled into an invalid /
 * negative price. toggle_promo() now re-runs validate_promo() on the stored row
 * BEFORE publishing it to the engine, and returns the standard 400
 * { message, code, field } error instead of compiling.
 *
 * Same standalone style as tests/test-promo-validation.php: no PHPUnit, Reflection
 * to build the singleton without its private constructor, minimal WP stubs, and
 * hard-failing assert helpers. The real PromoModel is replaced by an in-memory
 * stand-in: get_promo() returns the single seeded row, and update() records its
 * calls so the test can prove that a rejected toggle never mutates the row or
 * reaches the compile step (sync_bridge() runs after update()).
 */

namespace Drw\App\Models {

	/**
	 * In-memory stand-in for the real PromoModel. Only the surface toggle_promo()
	 * and validate_promo() touch is implemented.
	 */
	class PromoModel {
		/** @var array|null The single stored (formatted) promo row get_promo() returns. */
		public static $promo = null;

		/** @var array<int,array> Recorded update() calls: each { id, data }. */
		public static $updates = array();

		/** @var bool What code_exists() answers (no other promos in these scenarios). */
		public static $code_exists_return = false;

		public static function reset() {
			self::$promo              = null;
			self::$updates            = array();
			self::$code_exists_return = false;
		}

		public static function get_promo( $id ) {
			return self::$promo;
		}

		public static function update( $id, $data ) {
			self::$updates[] = array(
				'id'   => $id,
				'data' => $data,
			);
			return true;
		}

		public static function code_exists( $code, $exclude_id = null ) {
			return self::$code_exists_return;
		}
	}
}

namespace {

	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	function __( $text, $domain = null ) {
		return $text;
	}

	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $value ) );
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function wc_get_coupon_id_by_code( $code ) {
		return 0;
	}

	function assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	function assert_true( $condition, $message ) {
		assert_same( true, (bool) $condition, $message );
	}

	/**
	 * Minimal WP_Error stand-in (get_error_message/get_error_code/get_error_data).
	 */
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	/**
	 * Minimal WP_REST_Response stand-in capturing the body and status code that
	 * toggle_promo() / validation_error_response() emit.
	 */
	class WP_REST_Response {
		private $data;
		private $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}

	require_once dirname( __DIR__ ) . '/src/Models/PromoTypeRegistry.php';
	require_once dirname( __DIR__ ) . '/src/Controllers/PromosController.php';

	use Drw\App\Models\PromoModel;

	// Build the controller without invoking its private constructor (same trick
	// tests/test-promo-validation.php uses to reach otherwise-guarded methods).
	$reflection = new \ReflectionClass( 'Drw\App\Controllers\PromosController' );
	$controller = $reflection->newInstanceWithoutConstructor();

	/**
	 * A formatted PromoModel row (snake_case columns, JSON already decoded), the
	 * exact shape PromoModel::get_promo() returns and to_rest() consumes.
	 */
	function stored_row( $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 7,
				'name'         => 'Promo heredada',
				'code'         => 'OLD500',
				'type'         => 'percent',
				'value'        => 500,
				'min_amount'   => 0,
				'limit_global' => null,
				'limit_user'   => null,
				'uses'         => 0,
				'date_from'    => null,
				'date_to'      => null,
				'active'       => 0,
				'home'         => 0,
				'cart_message' => '',
			),
			$overrides
		);
	}

	// --- (a) activating an INVALID stored promo is rejected 400, never compiled -----
	// The row (percent, value 500) predates the "percent <= 100" rule. It is
	// currently inactive, so toggling means ACTIVATE -> the compile path -> it must
	// be re-validated and rejected instead of published to the engine.
	PromoModel::reset();
	PromoModel::$promo = stored_row( array( 'active' => 0, 'value' => 500 ) );

	$response = $controller->toggle_promo( array( 'id' => 7 ) );

	assert_true( $response instanceof WP_REST_Response, 'toggle_promo() must return a WP_REST_Response.' );
	assert_same( 400, $response->get_status(), 'Activating an invalid stored promo must return HTTP 400, not compile it.' );

	$body = $response->get_data();
	assert_true( is_array( $body ), 'The 400 body must be an array.' );
	assert_true( isset( $body['message'] ) && isset( $body['code'] ) && array_key_exists( 'field', $body ), 'The error body must follow the { message, code, field } shape.' );
	assert_same( 'invalid_percent', $body['code'], 'value = 500 on a percent promo must fail with invalid_percent.' );
	assert_same( 'value', $body['field'], 'The invalid_percent error must be attributed to the value field.' );
	assert_true( is_string( $body['message'] ) && '' !== $body['message'], 'The error must carry a non-empty human-readable message.' );

	// Crucially: the row must NOT have been flipped active, and (since sync_bridge
	// compile runs only AFTER update) the promo was never compiled.
	assert_same( 0, count( PromoModel::$updates ), 'A rejected toggle must not update the row (so it is never compiled/activated).' );

	// --- (b) activating a VALID stored promo proceeds normally ----------------------
	// The same shape but a legal percentage: the re-validation gate must let it
	// through and flip it active (200). sync_bridge()'s compile is exercised but
	// harmless here — it swallows the missing-bridge Throwable and only logs.
	PromoModel::reset();
	PromoModel::$promo = stored_row( array( 'active' => 0, 'code' => 'GOOD20', 'value' => 20 ) );

	$response = $controller->toggle_promo( array( 'id' => 7 ) );

	assert_same( 200, $response->get_status(), 'Activating a valid stored promo must succeed with 200.' );
	assert_same( 1, count( PromoModel::$updates ), 'A valid toggle must update the row exactly once.' );
	assert_same( 1, PromoModel::$updates[0]['data']['active'], 'Activating must set active = 1.' );

	// --- (c) DEACTIVATING an invalid stored promo is never blocked ------------------
	// Retracting a bad promo must always be possible: when the row is already
	// active, toggling means DEACTIVATE (decompile), so the validation gate is
	// skipped and the toggle succeeds even though the stored data is invalid.
	PromoModel::reset();
	PromoModel::$promo = stored_row( array( 'active' => 1, 'value' => 500 ) );

	$response = $controller->toggle_promo( array( 'id' => 7 ) );

	assert_same( 200, $response->get_status(), 'Deactivating an invalid promo must never be blocked by validation.' );
	assert_same( 1, count( PromoModel::$updates ), 'Deactivating must still update the row.' );
	assert_same( 0, PromoModel::$updates[0]['data']['active'], 'Deactivating must set active = 0.' );

	// --- (d) a missing promo still returns 404 --------------------------------------
	PromoModel::reset();
	PromoModel::$promo = null;

	$response = $controller->toggle_promo( array( 'id' => 999 ) );
	assert_same( 404, $response->get_status(), 'Toggling a non-existent promo must return 404.' );
	assert_same( 0, count( PromoModel::$updates ), 'A 404 toggle must not update anything.' );

	echo "Promo toggle validation OK\n";
}
