<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-shot migrator from the legacy wp_options('drw_promos') JSON blob into the
 * per-row `wp_drw_promos` table via PromoModel::insert().
 *
 * IMPORTANT: this controller is deliberately INERT. It registers no hooks and
 * is not wired into activation, rest_api_init, or any bootstrap. The single
 * entry point migrate_legacy_promos() must be invoked explicitly (WP-CLI, an
 * admin button, etc.). Nothing here runs on its own.
 */
class PromoMigrationController {

	/**
	 * wp_options key holding the legacy promos JSON array.
	 *
	 * @var string
	 */
	const LEGACY_KEY = 'drw_promos';

	/**
	 * wp_options key where the untouched legacy JSON is backed up before any write.
	 *
	 * @var string
	 */
	const BACKUP_KEY = 'drw_promos_legacy_backup';

	/**
	 * wp_options key tracking which legacy entries (by their legacy `id`) have
	 * already been inserted, so re-running this method is safe: it will never
	 * insert the same legacy promo twice. Without this, a legacy entry with no
	 * `code` (NULL is allowed multiple times by the `code_unique` index, unlike
	 * a real duplicate code) would silently duplicate on every re-run.
	 *
	 * @var string
	 */
	const MIGRATED_IDS_KEY = 'drw_promos_migrated_legacy_ids';

	/**
	 * Migrate legacy promos from wp_options into the drw_promos table.
	 *
	 * Behaviour contract:
	 *  - No legacy data          -> array( 'status' => 'skipped', 'migrated' => 0 ), nothing else touched.
	 *  - All rows inserted       -> array( 'status' => 'ok', 'migrated' => N ).
	 *  - Partial insert          -> array( 'status' => 'incomplete', 'migrated' => N, 'expected' => M );
	 *                               inserted rows are kept so a future admin notice can offer "retry".
	 *  - Safe to call repeatedly -> a legacy entry already migrated (tracked by its
	 *                               legacy `id` in self::MIGRATED_IDS_KEY) is never
	 *                               inserted again; a retry only attempts the ones
	 *                               still missing, so it can never create duplicates.
	 *
	 * The raw legacy JSON is backed up verbatim (self::BACKUP_KEY) exactly once,
	 * before the first insert, and never overwrites a pre-existing backup.
	 *
	 * @return array Result descriptor (see contract above).
	 */
	public static function migrate_legacy_promos() {
		$raw     = get_option( self::LEGACY_KEY, '[]' );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || 0 === count( $decoded ) ) {
			return array(
				'status'   => 'skipped',
				'migrated' => 0,
			);
		}

		// Back up the original blob verbatim, but never clobber an existing backup
		// (repeat runs must preserve the very first snapshot).
		if ( null === get_option( self::BACKUP_KEY, null ) ) {
			update_option( self::BACKUP_KEY, $raw, false );
		}

		$already_migrated = get_option( self::MIGRATED_IDS_KEY, array() );
		if ( ! is_array( $already_migrated ) ) {
			$already_migrated = array();
		}

		$expected      = count( $decoded );
		$migrated      = count( $already_migrated );
		$wrote_new_ids = false;
		$rejected      = array();

		foreach ( $decoded as $legacy ) {
			if ( ! is_array( $legacy ) ) {
				continue;
			}

			// Legacy entries always carry the id PromosController::next_id()
			// assigned them. Without one there is no way to track it across
			// runs, so it is migrated best-effort and cannot be de-duplicated
			// on a retry -- in practice this never happens for real data.
			$legacy_id = isset( $legacy['id'] ) ? (string) $legacy['id'] : null;

			if ( null !== $legacy_id && in_array( $legacy_id, $already_migrated, true ) ) {
				continue; // Already inserted by a previous run -- skip, never duplicate.
			}

			// Re-validate every legacy entry through the SAME gate the REST
			// create/update path uses BEFORE it can land in the live table.
			// Legacy blobs were written by an older, laxer editor and may hold
			// values (bad type, percent > 100, inverted dates, unescaped HTML)
			// that would otherwise be imported unvalidated and, once activated,
			// compile into an invalid / negative price in production. Invalid
			// entries are collected in $rejected and NEVER inserted; valid ones
			// are stored through to_columns() so the persisted row is
			// byte-for-byte what a real create_promo() would have written.
			$camel     = self::legacy_to_camel( $legacy );
			$validated = \Drw\App\Controllers\PromosController::instance()->validate_promo( $camel );

			if ( is_wp_error( $validated ) ) {
				$rejected[] = array(
					'legacy_id' => $legacy_id,
					'name'      => isset( $legacy['name'] ) ? (string) $legacy['name'] : '',
					'reason'    => $validated->get_error_message(),
				);
				continue;
			}

			$columns = \Drw\App\Controllers\PromosController::instance()->to_columns( $validated );
			$new_id  = PromoModel::insert( $columns );

			if ( $new_id ) {
				$migrated++;
				if ( null !== $legacy_id ) {
					$already_migrated[] = $legacy_id;
					$wrote_new_ids       = true;
				}
			}
		}

		if ( $wrote_new_ids ) {
			update_option( self::MIGRATED_IDS_KEY, array_values( array_unique( $already_migrated ) ), false );
		}

		if ( $migrated === $expected ) {
			return array(
				'status'   => 'ok',
				'migrated' => $migrated,
				'rejected' => $rejected,
			);
		}

		// Do NOT roll back: keep whatever landed so a retry can finish the job.
		// $rejected explains which legacy entries were dropped for failing
		// validation (an empty array keeps the result shape identical for any
		// caller that does not inspect it).
		return array(
			'status'   => 'incomplete',
			'migrated' => $migrated,
			'expected' => $expected,
			'rejected' => $rejected,
		);
	}

	/**
	 * Translate a single legacy promo entry into the camelCase REST shape that
	 * PromosController::validate_promo() consumes.
	 *
	 * This mirrors the field mapping map_legacy_promo() performs, but stops one
	 * step earlier: it returns the public camelCase contract (name, code, type,
	 * value, scope, minAmount, limitGlobal, limitUser, start, end, active, home,
	 * cartMessage, giftText) instead of the final snake_case columns. Passing the
	 * result through validate_promo() + to_columns() then yields exactly the row
	 * a real create_promo() would persist, so migrated data is held to the same
	 * validation and sanitisation as data entered through the REST API.
	 *
	 * The legacy `scope` is a free-form string; it is forwarded verbatim so
	 * validate_promo()/sanitize_scope() keeps it as a sanitised legacy string
	 * (later wrapped in the historical { raw: "<text>" } envelope by
	 * to_columns()/scope_to_storage()). The legacy `uses` counter is intentionally
	 * not carried here: to_columns() omits it by design (usage is model-managed),
	 * matching the REST create path.
	 *
	 * @param array $legacy Legacy promo entry.
	 * @return array camelCase promo payload for PromosController::validate_promo().
	 */
	private static function legacy_to_camel( array $legacy ) {
		return array(
			'name'        => isset( $legacy['name'] ) ? (string) $legacy['name'] : '',
			'code'        => isset( $legacy['code'] ) ? (string) $legacy['code'] : '',
			'type'        => isset( $legacy['type'] ) ? (string) $legacy['type'] : '',
			'value'       => isset( $legacy['value'] ) ? $legacy['value'] : 0,
			'scope'       => isset( $legacy['scope'] ) ? (string) $legacy['scope'] : '',
			'minAmount'   => isset( $legacy['minAmount'] ) ? $legacy['minAmount'] : 0,
			'limitGlobal' => isset( $legacy['limitGlobal'] ) ? $legacy['limitGlobal'] : 0,
			'limitUser'   => isset( $legacy['limitUser'] ) ? $legacy['limitUser'] : 0,
			'start'       => isset( $legacy['start'] ) ? (string) $legacy['start'] : '',
			'end'         => isset( $legacy['end'] ) ? (string) $legacy['end'] : '',
			'active'      => ! isset( $legacy['active'] ) || (bool) $legacy['active'],
			'home'        => ! empty( $legacy['home'] ),
			'cartMessage' => isset( $legacy['cartMessage'] ) ? (string) $legacy['cartMessage'] : '',
			'giftText'    => isset( $legacy['giftText'] ) ? (string) $legacy['giftText'] : '',
		);
	}
}
