<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.16.0: add three performance indexes to pllat_translation_index.
 *
 * All three serve hot paths that previously full-scanned the index table:
 * - last_synced (last_synced, target_lang): Job_Claim_Service::limit_reached()
 *   filters synced source_ids by last_synced on every claim/tick of a limited run.
 * - target_ref (source_kind, target_id): delete_target_references() deletes by
 *   target_id, which no existing index leads with — a full scan per hard-delete.
 * - done_agg (source_lang, content_status, source_kind, content_subtype, target_lang):
 *   the dashboard done-aggregate filters on source_lang first; no index led with it,
 *   forcing a full scan + filesort on every 1.5s poll during a run.
 *
 * Idempotent: each ADD KEY is guarded by index_exists(), so re-running is a no-op
 * and existing installs gain the keys without a full rebuild.
 */
final class Migrate_To_3_16_0 {
	public const VERSION = '3.16.0';

	public static function run(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';

		$indexes = array(
			'last_synced' => 'last_synced (last_synced, target_lang)',
			'target_ref'  => 'target_ref (source_kind, target_id)',
			'done_agg'    => 'done_agg (source_lang, content_status, source_kind, content_subtype, target_lang)',
		);

		foreach ( $indexes as $name => $definition ) {
			if ( Migration_Helpers::index_exists( $table, $name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table + index defs are code constants, no user input.
			$wpdb->query( "ALTER TABLE {$table} ADD KEY {$definition}" );
		}
	}
}
