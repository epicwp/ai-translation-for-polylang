<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.4.0: add completed_at column to runs table.
 */
final class Migrate_To_2_4_0 {
	public const VERSION = '2.4.0';

	public static function run(): void {
		global $wpdb;

		$runs_table = $wpdb->prefix . 'pllat_bulk_runs';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'completed_at'",
				DB_NAME,
				$runs_table,
			),
		);

		if ( $column_exists ) {
			return;
		}

		$wpdb->query(
			"ALTER TABLE {$runs_table}
			ADD COLUMN completed_at BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER started_at",
		);
	}
}
