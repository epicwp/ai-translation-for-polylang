<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.8.0: widen tasks.reference from VARCHAR(191) to TEXT for
 * JSON-encoded chunked string batches.
 */
final class Migrate_To_2_8_0 {
	public const VERSION = '2.8.0';

	public static function run(): void {
		global $wpdb;
		$tasks_table = $wpdb->prefix . 'pllat_tasks';
		$wpdb->query( "ALTER TABLE {$tasks_table} MODIFY reference TEXT NOT NULL" );
	}
}
