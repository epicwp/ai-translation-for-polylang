<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.2.0: add id_to column.
 *
 * Stores the target content ID for bidirectional job cleanup. Truncates
 * existing data — plugin was pre-production at this point.
 */
final class Migrate_To_2_2_0 {
	public const VERSION = '2.2.0';

	public static function run(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'pllat_tasks' );
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'pllat_jobs' );
	}
}
