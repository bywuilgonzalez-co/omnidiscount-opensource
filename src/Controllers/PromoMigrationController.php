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

			$new_id = PromoModel::insert( self::map_legacy_promo( $legacy ) );

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
			);
		}

		// Do NOT roll back: keep whatever landed so a retry can finish the job.
		return array(
			'status'   => 'incomplete',
			'migrated' => $migrated,
			'expected' => $expected,
		);
	}

	/**
	 * Map a single legacy promo entry to the PromoModel::insert() column shape.
	 *
	 * The legacy `scope` is a free-form string; the structured scope arrives in a
	 * later phase, so here it is preserved verbatim inside a JSON envelope
	 * { "target": "legacy", "raw": "<original text>" }. PromoModel encodes the
	 * scope / gift_config arrays to JSON on insert.
	 *
	 * @param array $legacy Legacy promo entry.
	 * @return array Column-shaped data for PromoModel::insert().
	 */
	private static function map_legacy_promo( array $legacy ) {
		$scope_raw = isset( $legacy['scope'] ) ? (string) $legacy['scope'] : '';
		$gift_text = isset( $legacy['giftText'] ) ? (string) $legacy['giftText'] : '';

		return array(
			'name'         => isset( $legacy['name'] ) ? (string) $legacy['name'] : '',
			// Empty/missing code becomes NULL so multiple codeless legacy promos
			// don't collide on the UNIQUE(code) index (MySQL allows many NULLs but
			// only one ''). Mirrors PromosController::to_columns() normalisation.
			'code'         => ( isset( $legacy['code'] ) && '' !== (string) $legacy['code'] ) ? (string) $legacy['code'] : null,
			'type'         => isset( $legacy['type'] ) ? (string) $legacy['type'] : '',
			// Coerce numerics to the real column types (mirrors the REST path in
			// PromosController::to_columns()); a blank legacy field ('') must not
			// reach a DECIMAL/INT column, or a strict-mode INSERT fails (error 1366).
			'value'        => isset( $legacy['value'] ) ? (float) $legacy['value'] : 0,
			'scope'        => array(
				'target' => 'legacy',
				'raw'    => $scope_raw,
			),
			'min_amount'   => ( isset( $legacy['minAmount'] ) && '' !== (string) $legacy['minAmount'] ) ? (float) $legacy['minAmount'] : null,
			'limit_global' => ! empty( $legacy['limitGlobal'] ) ? (int) $legacy['limitGlobal'] : null,
			'limit_user'   => ! empty( $legacy['limitUser'] ) ? (int) $legacy['limitUser'] : null,
			'uses'         => isset( $legacy['uses'] ) ? (int) $legacy['uses'] : 0,
			'date_from'    => ( isset( $legacy['start'] ) && '' !== $legacy['start'] ) ? (string) $legacy['start'] : null,
			'date_to'      => ( isset( $legacy['end'] ) && '' !== $legacy['end'] ) ? (string) $legacy['end'] : null,
			'active'       => ( ! isset( $legacy['active'] ) || $legacy['active'] ) ? 1 : 0,
			'home'         => ! empty( $legacy['home'] ) ? 1 : 0,
			'cart_message' => isset( $legacy['cartMessage'] ) ? (string) $legacy['cartMessage'] : '',
			'gift_config'  => array(
				'text' => $gift_text,
			),
		);
	}
}
