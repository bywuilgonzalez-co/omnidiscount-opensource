<?php
/**
 * Focused smoke test for PromoMigrationController::migrate_legacy_promos():
 * legacy wp_options('drw_promos') -> PromoModel::insert() row-by-row, with a
 * one-time verbatim backup and the ok / skipped / incomplete status contract.
 *
 * Same standalone style as tests/test-promo-validation.php: no PHPUnit, an
 * in-memory wp_options store via $GLOBALS, minimal WP function stubs, and
 * hard-failing assert helpers. The real PromoModel is replaced by an in-memory
 * stand-in that accumulates every insert() instead of touching $wpdb, so we can
 * count exactly how many rows the migration wrote.
 */

namespace Drw\App\Models {

	/**
	 * In-memory stand-in for the real PromoModel. Every successful insert() is
	 * recorded so the test can assert how many rows were written and inspect
	 * the mapping. Mirrors the real table's UNIQUE(code) constraint: two
	 * inserts with the same non-null code collide (insert() returns 0, like a
	 * failed $wpdb->insert()); NULL codes never collide with each other, same
	 * as MySQL's UNIQUE index semantics. This is what actually reproduces the
	 * duplicate-row bug found by running the real migration against a real
	 * MySQL database twice in a row (fixed by MIGRATED_IDS_KEY tracking).
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

	require_once dirname( __DIR__ ) . '/src/Controllers/PromoMigrationController.php';

	use Drw\App\Controllers\PromoMigrationController;
	use Drw\App\Models\PromoModel;

	function reset_world() {
		$GLOBALS['wp_options'] = array();
		PromoModel::reset();
	}

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

	// --- (a) normal migration: 3 legacy promos -> status ok, migrated = 3 -----------
	reset_world();
	$three = array(
		legacy_promo( array( 'id' => 1, 'code' => 'A1', 'name' => 'Promo A' ) ),
		legacy_promo( array( 'id' => 2, 'code' => 'B2', 'name' => 'Promo B' ) ),
		legacy_promo( array( 'id' => 3, 'code' => 'C3', 'name' => 'Promo C' ) ),
	);
	$raw_three                              = wp_json_encode( $three );
	$GLOBALS['wp_options']['drw_promos']    = $raw_three;

	$result = PromoMigrationController::migrate_legacy_promos();

	assert_same( 'ok', $result['status'], '3 valid legacy promos should migrate with status ok.' );
	assert_same( 3, $result['migrated'], 'All 3 legacy promos should be reported as migrated.' );
	assert_same( 3, count( PromoModel::$inserted ), 'PromoModel::insert() should have been called exactly 3 times.' );

	// Backup written verbatim, once.
	assert_same( $raw_three, get_option( 'drw_promos_legacy_backup' ), 'The original JSON must be backed up verbatim before inserting.' );

	// Field mapping sanity on the first inserted row.
	$first = PromoModel::$inserted[0];
	assert_same( 'Promo A', $first['name'], 'name maps straight across.' );
	assert_same( 'A1', $first['code'], 'code maps straight across.' );
	assert_same( array( 'target' => 'legacy', 'raw' => 'Categoría: Zapatos' ), $first['scope'], 'legacy scope string must be wrapped as {target:legacy, raw:<original>}.' );
	// min_amount is a DECIMAL column; map_legacy_promo() casts to (float) on
	// purpose (same as 'value'), so the correct expectation is 50.0, not the
	// int 50 — assert_same is a strict === check and PHP treats int/float as
	// distinct there even though 50 == 50.0.
	assert_same( 50.0, $first['min_amount'], 'minAmount maps to min_amount.' );
	assert_same( 100, $first['limit_global'], 'limitGlobal maps to limit_global.' );
	assert_same( 1, $first['limit_user'], 'limitUser maps to limit_user.' );
	assert_same( '2026-01-01', $first['date_from'], 'start maps to date_from.' );
	assert_same( '2026-12-31', $first['date_to'], 'end maps to date_to.' );
	assert_same( 1, $first['active'], 'active true maps to 1.' );
	assert_same( 0, $first['home'], 'home false maps to 0.' );
	assert_same( '¡Aprovecha!', $first['cart_message'], 'cartMessage maps to cart_message.' );
	assert_same( array( 'text' => 'Regalo sorpresa' ), $first['gift_config'], 'giftText must be wrapped inside gift_config as {text:<value>}.' );

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
	// against an actual MySQL database twice: codeless (NULL) legacy promos
	// don't collide on UNIQUE(code) the way coded ones do, so without
	// MIGRATED_IDS_KEY tracking a second run silently duplicated them.
	reset_world();
	$mixed = array(
		legacy_promo( array( 'id' => 1, 'code' => 'HASCODE', 'name' => 'Con código' ) ),
		legacy_promo( array( 'id' => 2, 'code' => '', 'name' => 'Sin código A' ) ),
		legacy_promo( array( 'id' => 3, 'code' => '', 'name' => 'Sin código B' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $mixed );

	$first_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $first_run['status'], 'First run of a clean 3-promo set should be ok.' );
	assert_same( 3, $first_run['migrated'], 'First run should migrate all 3.' );
	assert_same( 3, count( PromoModel::$inserted ), 'Exactly 3 rows should exist after the first run.' );

	$second_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $second_run['status'], 'Re-running an already-complete migration must still report ok.' );
	assert_same( 3, $second_run['migrated'], 'Re-running must report the same total, not double it.' );
	assert_same( 3, count( PromoModel::$inserted ), 'A second run must NOT insert any new rows — this is the duplicate-row regression check.' );

	$third_run = PromoMigrationController::migrate_legacy_promos();
	assert_same( 3, count( PromoModel::$inserted ), 'A third run must also leave the row count untouched.' );

	// --- (e) a genuinely partial migration can still be retried to completion --------
	reset_world();
	$broken = array(
		legacy_promo( array( 'id' => 1, 'code' => 'SHARED', 'name' => 'Promo A' ) ),
		legacy_promo( array( 'id' => 2, 'code' => 'SHARED', 'name' => 'Promo B (choca con A)' ) ),
		legacy_promo( array( 'id' => 3, 'code' => '', 'name' => 'Promo C' ) ),
	);
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $broken );

	$partial = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'incomplete', $partial['status'], 'A genuine code collision between two legacy entries must report incomplete.' );
	assert_same( 2, $partial['migrated'], 'Only A and C should have made it in (B collided with A).' );
	assert_same( 3, $partial['expected'], 'Expected must reflect the full legacy count.' );

	$retry_without_fix = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'incomplete', $retry_without_fix['status'], 'Retrying without fixing the data must still report incomplete.' );
	assert_same( 2, count( PromoModel::$inserted ), 'Retrying without fixing the data must not duplicate A or C.' );

	// Fix the legacy data (as an admin would, e.g. via a "reintentar" flow) and confirm it completes.
	$broken[1]['code']                   = 'SHARED-FIXED';
	$GLOBALS['wp_options']['drw_promos'] = wp_json_encode( $broken );

	$completed = PromoMigrationController::migrate_legacy_promos();
	assert_same( 'ok', $completed['status'], 'After fixing the collision, a retry must complete successfully.' );
	assert_same( 3, $completed['migrated'], 'All 3 should be migrated once the data is fixed.' );
	assert_same( 3, count( PromoModel::$inserted ), 'Only the missing promo (B) should have been inserted by the retry — A and C must not be duplicated.' );

	echo "Promo migration OK\n";
}
