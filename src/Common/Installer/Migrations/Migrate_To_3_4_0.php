<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.4.0: add trace_id columns for log correlation.
 *
 * Enables cross-service correlation via a stable UUID per run. Existing
 * rows keep NULL.
 */
final class Migrate_To_3_4_0 {
	public const VERSION = '3.4.0';

	public static function run(): void {
		global $wpdb;

		$runs_table         = $wpdb->prefix . 'pllat_bulk_runs';
		$activity_log_table = $wpdb->prefix . 'pllat_activity_log';

		$runs_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$runs_table}", 0 );
		if ( ! \in_array( 'trace_id', $runs_columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$runs_table} ADD COLUMN trace_id CHAR(36) NULL AFTER id" );
		}

		$activity_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$activity_log_table}", 0 );
		if ( ! \in_array( 'trace_id', $activity_columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$activity_log_table} ADD COLUMN trace_id CHAR(36) NULL AFTER run_id" );
		}
	}
}
