<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.10.0: drop pllat_source_hashes + cancel pre-3.10 in-flight runs.
 *
 * Plan G's hash storage is replaced architecturally by translation_field_state.
 * In-flight cancellation: pre-3.10 runs reference pre-allocated jobs/tasks that
 * the lean worker doesn't understand; they're cancelled so the lean pipeline
 * starts clean. Customers can retrigger; field_state backfill ensures retriggers
 * only re-translate remaining work.
 */
final class Migrate_To_3_10_0 {
	public const VERSION = '3.10.0';

	public static function run(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_source_hashes';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$runs_table = $wpdb->prefix . 'pllat_bulk_runs';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$runs_table}
					SET status = 'cancelled', completed_at = %d
				  WHERE status IN ('pending', 'running')",
				\time(),
			),
		);

		// Drop pending jobs for cancelled runs. in_progress jobs are kept —
		// they may still be in mid-flight; they'll see cancelled and exit
		// on their next claim attempt.
		$jobs_table = $wpdb->prefix . 'pllat_jobs';
		$wpdb->query(
			"DELETE FROM {$jobs_table}
			  WHERE run_id IN (
				SELECT id FROM (
				  SELECT id FROM {$runs_table} WHERE status = 'cancelled'
				) AS r
			  )
			  AND status = 'pending'",
		);
	}
}
