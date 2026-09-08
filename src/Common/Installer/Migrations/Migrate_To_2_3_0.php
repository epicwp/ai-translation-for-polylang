<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.3.0: foreign keys + unique indexes on legacy jobs/tasks.
 */
final class Migrate_To_2_3_0 {
	public const VERSION = '2.3.0';

	public static function run(): void {
		global $wpdb;

		$runs_table  = $wpdb->prefix . 'pllat_bulk_runs';
		$jobs_table  = $wpdb->prefix . 'pllat_jobs';
		$tasks_table = $wpdb->prefix . 'pllat_tasks';

		self::cleanup_orphaned_records( $runs_table, $jobs_table, $tasks_table );
		self::add_foreign_key_constraints( $runs_table, $jobs_table, $tasks_table );
		self::add_unique_indexes( $jobs_table );
	}

	private static function cleanup_orphaned_records( string $runs_table, string $jobs_table, string $tasks_table ): void {
		global $wpdb;

		// Orphaned tasks (no parent job).
		$wpdb->query(
			"DELETE t FROM {$tasks_table} t
			LEFT JOIN {$jobs_table} j ON t.job_id = j.id
			WHERE j.id IS NULL",
		);

		// Orphaned jobs (no parent run, only if run_id is set).
		$wpdb->query(
			"DELETE j FROM {$jobs_table} j
			LEFT JOIN {$runs_table} r ON j.run_id = r.id
			WHERE j.run_id IS NOT NULL AND r.id IS NULL",
		);
	}

	private static function add_foreign_key_constraints( string $runs_table, string $jobs_table, string $tasks_table ): void {
		global $wpdb;

		// FK: tasks.job_id -> jobs.id (CASCADE DELETE).
		if ( ! Migration_Helpers::constraint_exists( $tasks_table, 'fk_tasks_job_id' ) ) {
			$wpdb->query(
				"ALTER TABLE {$tasks_table}
				ADD CONSTRAINT fk_tasks_job_id
				FOREIGN KEY (job_id) REFERENCES {$jobs_table}(id)
				ON DELETE CASCADE",
			);
		}

		// FK: jobs.run_id -> runs.id (SET NULL for history preservation).
		if ( Migration_Helpers::constraint_exists( $jobs_table, 'fk_jobs_run_id' ) ) {
			return;
		}

		$wpdb->query(
			"ALTER TABLE {$jobs_table}
			ADD CONSTRAINT fk_jobs_run_id
			FOREIGN KEY (run_id) REFERENCES {$runs_table}(id)
			ON DELETE SET NULL",
		);
	}

	private static function add_unique_indexes( string $jobs_table ): void {
		global $wpdb;

		if ( Migration_Helpers::index_exists( $jobs_table, 'idx_unique_active_job' ) ) {
			return;
		}

		$wpdb->query(
			"ALTER TABLE {$jobs_table}
			ADD UNIQUE KEY idx_unique_active_job (type, id_from, lang_to, status, run_id)",
		);
	}
}
