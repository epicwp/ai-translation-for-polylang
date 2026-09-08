<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.7.0: batch_state column + drop string-specific tables.
 */
final class Migrate_To_2_7_0 {
	public const VERSION = '2.7.0';

	public static function run(): void {
		global $wpdb;

		$tasks_table = $wpdb->prefix . 'pllat_tasks';

		$batch_state_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'batch_state'",
				DB_NAME,
				$tasks_table,
			),
		);

		if ( ! $batch_state_exists ) {
			$wpdb->query(
				"ALTER TABLE {$tasks_table}
				ADD COLUMN batch_state LONGTEXT NULL AFTER issue",
			);
		}

		// Drop string-specific tables from failed attempt.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pllat_string_jobs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pllat_string_runs" );
	}
}
