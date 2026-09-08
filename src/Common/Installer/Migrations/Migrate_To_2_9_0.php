<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.9.0: composite index for stale job detection on legacy jobs table.
 */
final class Migrate_To_2_9_0 {
	public const VERSION = '2.9.0';

	public static function run(): void {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'pllat_jobs';

		if ( Migration_Helpers::index_exists( $jobs_table, 'idx_heartbeat_stale' ) ) {
			return;
		}

		$wpdb->query(
			"ALTER TABLE {$jobs_table}
			ADD KEY idx_heartbeat_stale (status, last_heartbeat)",
		);
	}
}
