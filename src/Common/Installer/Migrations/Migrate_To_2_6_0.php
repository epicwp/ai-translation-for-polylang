<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.6.0: drop value column from tasks (just-in-time value resolution).
 */
final class Migrate_To_2_6_0 {
	public const VERSION = '2.6.0';

	public static function run(): void {
		global $wpdb;

		$tasks_table = $wpdb->prefix . 'pllat_tasks';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'value'",
				DB_NAME,
				$tasks_table,
			),
		);

		if ( $column_exists <= 0 ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$tasks_table} DROP COLUMN value" );
	}
}
