<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.12.0: recreate pllat_activity_log + abandon stuck runs.
 *
 * - pllat_activity_log re-introduced (was dropped in 3.11.0) as an
 *   append-only per-field log with new shape.
 * - Abandon processing runs older than 2h with no in-flight claims —
 *   they stalled and will never complete on their own.
 */
final class Migrate_To_3_12_0 {
	public const VERSION = '3.12.0';

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$activity_log_table = $wpdb->prefix . 'pllat_activity_log';
		$sql_activity_log   = "CREATE TABLE {$activity_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED DEFAULT NULL,
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			target_lang VARCHAR(20) NOT NULL,
			reference VARCHAR(191) DEFAULT NULL,
			status VARCHAR(20) NOT NULL,
			error_message VARCHAR(500) DEFAULT NULL,
			logged_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_lookup (source_kind, source_id, target_lang, logged_at),
			KEY idx_run (run_id),
			KEY idx_date (logged_at)
		) {$charset_collate};";
		\dbDelta( $sql_activity_log );

		$runs_table   = $wpdb->prefix . 'pllat_bulk_runs';
		$claims_table = $wpdb->prefix . 'pllat_claims';
		$wpdb->query( "
			UPDATE {$runs_table}
			SET status = 'abandoned'
			WHERE status = 'processing'
			  AND started_at < UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR)
			  AND id NOT IN (SELECT DISTINCT run_id FROM {$claims_table} WHERE run_id IS NOT NULL)
		" );
	}
}
