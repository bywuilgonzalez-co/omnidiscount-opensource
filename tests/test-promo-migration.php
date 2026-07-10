<?php
/**
 * Focused smoke test for PromoMigrationController::migrate_legacy_promos():
 * legacy wp_options('drw_promos') -> PromoModel::insert() row-by-row, now GATED
 * by the exact same PromosController::validate_promo() the REST create/update
 * path uses, so migrated data can no longer land in the live table unvalidated
 * (the real exploit: an imported promo with a bad type / percent > 100 / inverted
 * dates could be activated later and compile into a negative price). Valid
 * entries are stored through PromosController::to_columns(); invalid ones are
 * collected under the new 'rejected' key and never inserted.
 *
 * Same standalone style as tests/test-promo-validation.php: no PHPUnit, an
 * in-memory wp_options store via $GLOBALS, minimal WP function stubs, and
 * hard-failing assert helpers. The real PromoModel is replaced by an in-memory
 * stand-in that both accumulates every insert() (so we can count the rows the
 * migration wrote) AND answers code_exists() from those accumulated rows (so the
 * uniqueness check validate_promo() performs sees rows inserted earlier in the
 * same run, exactly like the real indexed COUNT query).
 */

namespace Drw\App\Models {

	/**
	 * In-memory stand-in for the real PromoModel. Every successful insert() is
	 * recorded so the test can assert how many rows were written and inspect the
	 * mapping. insert() also mirrors the real table's UNIQUE(code) constraint
	 * (two non-null equal codes collide -> insert() returns 0), and code_exists()
	 * answers uniqueness from the same accumulated rows so validate_promo() can
	 * detect a duplicate code between two legacy entries within one run.
	 */
	class PromoModel {
		/** @var array<int,array> Accumulated insert() payloads that succeeded. */
		public static $inserted = array();

		public static function reset() {
			self::$inserted = array();
		}

		public static function insert( $data ) {
			$code = isset( $data['code'] ) ? $data['code'] : null;
			if ( null !== $code ) {
				foreach ( self::$inserted as $row ) {
					if ( isset( $row['code'] ) && $row['code'] === $code ) {
						return 0; // Simulates a UNIQUE(code) constraint violation.
					}
				}
			}
			self::$inserted[] = $data;
			return count( self::$inserted ); // Fake auto-increment id (>0 == success).
		}

		/**
		 * Mirrors the real signature: case-sensitive match on the already
		 * upper-cased code against rows inserted so far in this run, honouring
		 * exclude_id. The migration always calls validate_promo() with the
		 * default exclude_id (null), so a code collides with any prior insert.
		 */
		public static function code_exists( $code, $exclude_id = null ) {
			foreach ( self::$inserted as $index => $row ) {
				if ( null !== $exclude_id && ( $index + 1 ) === (int) $exclude_id ) {
					continue;
				}
				if ( isset( $row['code'] ) && null !== $row['code'] && $row['code'] === $code ) {
					return true;
				}
			}
			return false;
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

	// --- WP function stubs the validation path needs (mirroring test-promo-validation.php) ---
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

	/**
	 * Minimal WP_Error stand-in, matching the subset of the API this controller
	 * actually uses (get_error_message/get_error_code/get_error_data).
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

	// No native WooCommerce coupons in these scenarios.
	function wc_get_coupon_id_by_code( $code ) {
		return 0;
	}

	// In-memory stand-in for the wp_options-backed store.
	$GLOBALS['wp_options'] = array();
	function get_option( $key, $default = false ) {
		return isset( $GLOBALS['wp_options'][ $key ] ) ? $GLOBALS['wp_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['wp_options'][ $key ] = $value;
		return true;
	}
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	require_once dirname( __DIR__ ) . '/src/Models/PromoTypeRegistry.php';
	require_once dirname( __DIR__ ) . '/src/Controllers/PromosController.php';
	require_once dirname( __DIR__ ) . '/src/Controllers/PromoMigrationController.php';

	use Drw\App\Controllers\PromoMigrationController;
	use Drw\App\Models\PromoModel;

	function reset_world() {
		$GLOBALS['wp_options'] = array();
		PromoModel::reset();
	}

	/**
	 * Legacy entry factory. Defaults to a valid coded `percent` promo; override
	 * `type => '2x1', code => '', value => 0` for a valid automatic (codeless)
	 * promo, or override individual fields to build the rejection cases.
	 */
	function legacy_promo( $overrides = array() ) {
		return array_merge(
			array(
				'id'          => 1,
				'name'        => 'Promo legacy',
				'code'        => 'LEGACY10',
				'type'        => 'percent',
				'value'       => 10,
				'scope'       => 'Categoría: Zapatos',
				'minAmount'   => 50,
				'limitGlobal' => 100,
				'limitUser'   => 1,
				'uses'        => 7,
				'start'       => '2026-01-01',
				'end'         => '2026-12-31',
				'active'      => true,
				'home'        => false,
				'cartMessage' => '¡Aprovecha!',
				'giftText'    => 'Regalo sorpresa',
			),
			$overrides
		);
	}

	// --- (a) normal migration: 3 valid legacy promos -> status ok, migrated = 3 -----
	reset_world();
	$three = array(
		legacy_promo( array( 'id' => 1, 'code' => 'A1', 'name' => 'Promo A' ) ),
		legacy_promo( array( 'id' => 2, 'code' => 'B2', 'name' => 'Promo B' ) ),
		legacy_promo( array( 'id' => 3, 'code' => 'C3', 'name' => 'Promo C' ) ),
	);
	$raw_three                           = wp_json_encode( $three );
	$GLOBALS['wp_options']['drw_promos'] = $raw_three;

	$result = PromoMigrationController::migrate_legacy_promos();

	assert_same( 'ok', $result['status'], '3 valid legacy promos should migrate with status ok.' );
	assert_same( 3, $result['migrated'], 'All 3 legacy promos should be reported as migrated.' );
	assert_same( array(), $result['rejected'], 'No entry should be rejected when every legacy promo is valid.' );
	assert_same( 3, count( PromoModel::$inserted ), 'PromoModel::insert() should have been called exactly 3 times.' );

	// Backup written verbatim, once.
	assert_same( $raw_three, get_option( 'drw_promos_legacy_backup' ), 'The original JSON must be backed up verbatim before inserting.' );

	// Field mapping sanity on the first inserted row: rows now flow through
	// validate_promo() + to_columns(), so the shape matches a real create_promo().
	$first = PromoModel::$inserted[0];
	assert_same( 'Promo A', $first['name'], 'name maps straight across.' );
	assert_same( 'A1', $first['code'], 'code maps straight across (upper-cased).' );
	// to_columns()/scope_to_storage() wraps a legacy free-form scope string as
	// { raw: <original> } (no synthetic target key), and to_rest() unwraps it back.
	assert_same( array( 'raw' => 'Categoría: Zapatos' ), $first['scope'], 'legacy scope string must be wrapped as { raw: <original> } by to_columns().' );
	// min_amount is a DECIMAL column; validate_promo() casts via floatval(), so the
	// correct expectation is the float 50.0 (assert_same is a strict === check).
	assert_same( 50.0, $first['min_amount'], 'minAmount maps to min_amount.' );
	assert_same( 100, $first['limit_global'], 'limitGlobal maps to limit_global.' );
	assert_same( 1, $first['limit_user'], 'limitUser maps to limit_user.' );
	assert_same( '2026-01-01', $first['date_from'], 'start maps to date_from.' );
	assert_same( '2026-12-31', $first['date_to'], 'end maps to date_to.' );
	assert_same( 1, $first['active'], 'active true maps to 1.' );
	assert_same( 0, $first['home'], 'home false maps to 0.' );
	assert_same( '¡Aprovecha!', $first['cart_message'], 'cartMessage maps to cart_message.' );
	assert_same( array( 'text' => 'Regalo sorpresa' ), $first['gift_config'], 'giftText must be wrapped inside gift_config as { text: <value> }.' );
	// to_columns() deliberately omits the uses counter (model-managed), matching
	// the REST create path — the legacy `uses` is not carried into the row.
	assert_true( ! array_key_exists( 'uses', $first ), 'to_columns() must not carry a uses column (usage is model-managed).' );

	// --- (b) empty wp_options -> status skipped, nothing touched --------------------
	reset_world();
	// No 'drw_promos' option set at all: get_option returns the '[]' default.
	$result = PromoMigrationController::migrate_legacy_promos();

	assert_same( 'skipped', $result['status'], 'An empty store must yield status skipped.' );
	assert_same( 0, $result['migrated'], 'Nothing should be migrated from an empty store.' );
	assert_same( 0, count( PromoModel::$inserted ), 'No insert() calls should happen when the store is empty.' );
	assert_same( false, get_option( 'drw_promos_legacy_backup' ), 'A skipped run must not create a backup.' );

	// Also cover an explicit empty JSON array literal.
	reset_world();
	$GLOBALS['wp_options']['drw_promos'] = '[]';
	$result                              = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'skipped', $result['status'], 'An explicit "[]" must also yield status skipped.' );

	// --- (c) an existing backup with different content must NOT be overwritten -------
	reset_world();
	$GLOBALS['wp_options']['drw_promos_legacy_backup'] = 'PREVIOUS_BACKUP_DO_NOT_TOUCH';
	$two                                               = array(
		legacy_promo( array( 'id' => 1, 'code' => 'X1' ) ),
		legacy_promo( array( 'id' => 2, 'code' => 'Y2' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $two );

	$result = PromoMigrationController::migrate_legacy_promos();

	assert_same( 'ok', $result['status'], 'Migration still runs even when a prior backup exists.' );
	assert_same( 2, $result['migrated'], 'Both promos should migrate.' );
	assert_same( 'PREVIOUS_BACKUP_DO_NOT_TOUCH', get_option( 'drw_promos_legacy_backup' ), 'A pre-existing backup must never be overwritten on repeat runs.' );

	// --- (d) re-running an already-complete migration must NEVER duplicate rows ------
	// Regression test for a real bug found by running migrate_legacy_promos()
	// against an actual MySQL database twice: codeless (NULL) legacy promos don't
	// collide on UNIQUE(code) the way coded ones do, so without MIGRATED_IDS_KEY
	// tracking a second run silently duplicated them. Uses the automatic (2x1)
	// type so codeless entries pass validation (percent would require a code).
	reset_world();
	$auto = array(
		legacy_promo( array( 'id' => 1, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Auto A' ) ),
		legacy_promo( array( 'id' => 2, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Auto B' ) ),
		legacy_promo( array( 'id' => 3, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Auto C' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $auto );

	$first_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $first_run['status'], 'First run of a clean 3-promo set should be ok.' );
	assert_same( 3, $first_run['migrated'], 'First run should migrate all 3.' );
	assert_same( 3, count( PromoModel::$inserted ), 'Exactly 3 rows should exist after the first run.' );
	// Codeless automatic promos store code = NULL.
	assert_same( null, PromoModel::$inserted[0]['code'], 'A codeless automatic promo must store code = NULL.' );

	$second_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $second_run['status'], 'Re-running an already-complete migration must still report ok.' );
	assert_same( 3, $second_run['migrated'], 'Re-running must report the same total, not double it.' );
	assert_same( 3, count( PromoModel::$inserted ), 'A second run must NOT insert any new rows — this is the duplicate-row regression check.' );

	$third_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 3, count( PromoModel::$inserted ), 'A third run must also leave the row count untouched.' );

	// --- (e) invalid legacy entries are REJECTED, never inserted ---------------------
	// This is the core of the security fix: legacy blobs written by an older,
	// laxer editor are now held to validate_promo() before they can reach the
	// live table. Each rejected entry is reported under 'rejected' with its
	// legacy_id, name and the human-readable reason.
	reset_world();
	$mixed = array(
		legacy_promo( array( 'id' => 1, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Promo automática' ) ),
		legacy_promo( array( 'id' => 2, 'type' => 'nope', 'name' => 'Tipo inválido' ) ),
		legacy_promo( array( 'id' => 3, 'type' => 'percent', 'code' => 'P500', 'value' => 500, 'name' => 'Porcentaje excesivo' ) ),
		legacy_promo( array( 'id' => 4, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Fechas al revés', 'start' => '2026-08-01', 'end' => '2026-07-01' ) ),
		legacy_promo( array( 'id' => 5, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => '<script>alert(1)</script>' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $mixed );

	$result = PromoMigrationController::migrate_legacy_promos();

	// Only the two valid entries (ids 1 and 5) were inserted.
	assert_same( 2, count( PromoModel::$inserted ), 'Only the two valid legacy entries should have been inserted.' );
	assert_same( 'incomplete', $result['status'], 'A batch with rejected entries cannot report ok.' );
	assert_same( 2, $result['migrated'], 'migrated must count only the inserted (valid) rows.' );
	assert_same( 5, $result['expected'], 'expected reflects the full legacy count including rejected entries.' );

	// The three invalid entries are reported under 'rejected', in encounter order.
	$rejected = $result['rejected'];
	assert_same( 3, count( $rejected ), 'Exactly three legacy entries should be rejected.' );

	assert_same( '2', $rejected[0]['legacy_id'], 'The invalid-type entry (id 2) should be rejected first.' );
	assert_same( 'Tipo inválido', $rejected[0]['name'], 'A rejected entry carries its legacy name for the admin notice.' );
	assert_true( false !== strpos( $rejected[0]['reason'], 'Invalid type' ), 'An unknown type must be rejected with the invalid-type reason.' );

	assert_same( '3', $rejected[1]['legacy_id'], 'The percent > 100 entry (id 3) should be rejected.' );
	assert_same( 'Percentage value cannot exceed 100.', $rejected[1]['reason'], 'value = 500 on a percent promo must be rejected as an over-100 percentage.' );

	assert_same( '4', $rejected[2]['legacy_id'], 'The inverted-dates entry (id 4) should be rejected.' );
	assert_same( 'End date must be on or after the start date.', $rejected[2]['reason'], 'end before start must be rejected as an invalid date range.' );

	// The valid <script> name is sanitised in the inserted row (tags stripped).
	$script_row = PromoModel::$inserted[1];
	assert_same( 'alert(1)', $script_row['name'], 'The <script> tags must be stripped from the stored name.' );
	assert_true( false === strpos( $script_row['name'], '<script>' ), 'No <script> tag may survive into the inserted row.' );

	// --- (f) a partial batch (one rejected) can be retried to completion -------------
	// The valid rows are tracked in MIGRATED_IDS_KEY so a retry never duplicates
	// them, while the rejected row is NOT tracked and is re-attempted — and, once
	// the underlying data is fixed, finally migrates.
	reset_world();
	$batch = array(
		legacy_promo( array( 'id' => 1, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Promo A' ) ),
		legacy_promo( array( 'id' => 2, 'type' => 'percent', 'code' => 'BAD', 'value' => 500, 'name' => 'Promo B' ) ),
		legacy_promo( array( 'id' => 3, 'type' => '2x1', 'code' => '', 'value' => 0, 'name' => 'Promo C' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $batch );

	$partial = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'incomplete', $partial['status'], 'A batch with one invalid entry must report incomplete.' );
	assert_same( 2, $partial['migrated'], 'Only A and C should have been inserted (B is invalid).' );
	assert_same( 1, count( $partial['rejected'] ), 'B should be the single rejected entry.' );
	assert_same( '2', $partial['rejected'][0]['legacy_id'], 'The rejected entry must be B (id 2).' );
	assert_same( 2, count( PromoModel::$inserted ), 'Exactly A and C should be in the table after the first run.' );

	$retry_without_fix = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'incomplete', $retry_without_fix['status'], 'Retrying without fixing B must still report incomplete.' );
	assert_same( 2, count( PromoModel::$inserted ), 'Retrying without fixing must not duplicate A or C.' );
	assert_same( 1, count( $retry_without_fix['rejected'] ), 'B must still be rejected on the retry.' );

	// Fix B's data (as an admin would via a "reintentar" flow) and confirm it completes.
	$batch[1]['value']                   = 20;
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $batch );

	$completed = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $completed['status'], 'After fixing B, a retry must complete successfully.' );
	assert_same( 3, $completed['migrated'], 'All 3 should be migrated once B is fixed.' );
	assert_same( array(), $completed['rejected'], 'No entry should remain rejected after the fix.' );
	assert_same( 3, count( PromoModel::$inserted ), 'Only the previously-rejected B should be inserted by the retry — A and C must not be duplicated.' );

	echo "Promo migration OK\n";
}
