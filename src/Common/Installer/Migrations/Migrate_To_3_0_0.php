<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Installer\Installer;

/**
 * Migration to 3.0.0: create activity_log table and migrate completed/failed tasks.
 *
 * Tasks become ephemeral work items — permanent history moves to activity_log.
 */
final class Migrate_To_3_0_0 {
	public const VERSION = '3.0.0';

	public static function run(): void {
		global $wpdb;

		// Re-run install() so the activity_log table is created.
		Installer::install();

		$activity_table = $wpdb->prefix . 'pllat_activity_log';
		$tasks_table    = $wpdb->prefix . 'pllat_tasks';
		$jobs_table     = $wpdb->prefix . 'pllat_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"INSERT INTO {$activity_table} (run_id, job_id, reference, status, issue, attempts, completed_at)
			 SELECT COALESCE(j.run_id, 0), t.job_id, t.reference, t.status, t.issue, t.attempts, COALESCE(FROM_UNIXTIME(j.completed_at), NOW())
			 FROM {$tasks_table} t
			 INNER JOIN {$jobs_table} j ON t.job_id = j.id
			 WHERE t.status IN ('completed', 'failed')",
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$tasks_table} WHERE status IN ('completed', 'failed')",
		);
	}
}
