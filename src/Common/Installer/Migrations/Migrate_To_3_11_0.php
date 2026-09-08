<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.11.0: rename pllat_jobs → pllat_claims with lean schema.
 *
 * - Create pllat_claims (lean columns: source_kind, source_id, source_lang,
 *   target_lang, content_subtype).
 * - Migrate in-flight pending/in_progress rows from pllat_jobs, then drop
 *   pllat_jobs.
 * - Drop pllat_tasks (no per-field rows in lean pipeline).
 * - Drop pllat_activity_log (will be re-introduced with different shape in 3.12.0).
 * - Drop pllat_bulk_runs.config and trace_id (replaced by spec).
 */
final class Migrate_To_3_11_0 {
	public const VERSION = '3.11.0';

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$claims_table = $wpdb->prefix . 'pllat_claims';
		$sql_claims   = "CREATE TABLE {$claims_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NULL,
			source_kind VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_lang VARCHAR(10) NOT NULL,
			target_lang VARCHAR(10) NOT NULL,
			content_subtype VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			issue TEXT NULL,
			batch_state LONGTEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_heartbeat BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY claim_unit (run_id, source_kind, source_id, target_lang),
			KEY run_id (run_id),
			KEY status (status),
			KEY heartbeat_stale (status, last_heartbeat)
		) {$charset_collate};";
		\dbDelta( $sql_claims );

		// Migrate in-flight claims from legacy pllat_jobs.
		// Exclude NULL-run jobs — those were Sync_Service markers, not real in-flight work.
		$jobs_table = $wpdb->prefix . 'pllat_jobs';
		if ( \in_array( $jobs_table, $wpdb->get_col( "SHOW TABLES LIKE '{$jobs_table}'" ), true ) ) {
			$wpdb->query( "
				INSERT IGNORE INTO {$claims_table}
					(run_id, source_kind, source_id, source_lang, target_lang, content_subtype,
					 status, attempts, issue, batch_state, created_at, started_at, last_heartbeat)
				SELECT
					run_id, type, id_from, lang_from, lang_to, content_type,
					status, attempts, issue, batch_state, created_at, started_at, last_heartbeat
				FROM {$jobs_table}
				WHERE status IN ('pending', 'in_progress')
				  AND run_id IS NOT NULL
			" );
			$wpdb->query( "DROP TABLE {$jobs_table}" );
		}

		$tasks_table = $wpdb->prefix . 'pllat_tasks';
		if ( \in_array( $tasks_table, $wpdb->get_col( "SHOW TABLES LIKE '{$tasks_table}'" ), true ) ) {
			$wpdb->query( "DROP TABLE {$tasks_table}" );
		}

		$activity_table = $wpdb->prefix . 'pllat_activity_log';
		if ( \in_array( $activity_table, $wpdb->get_col( "SHOW TABLES LIKE '{$activity_table}'" ), true ) ) {
			$wpdb->query( "DROP TABLE {$activity_table}" );
		}

		// Drop pllat_bulk_runs.config (replaced by spec) and trace_id.
		$runs_table = $wpdb->prefix . 'pllat_bulk_runs';
		$columns    = $wpdb->get_col( "SHOW COLUMNS FROM {$runs_table}", 0 );
		if ( \in_array( 'config', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$runs_table} DROP COLUMN config" );
		}
		if ( \in_array( 'trace_id', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$runs_table} DROP COLUMN trace_id" );
		}
	}
}
