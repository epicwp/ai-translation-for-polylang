<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 2.0.0: backfill content_type column on legacy pllat_jobs.
 */
final class Migrate_To_2_0_0 {
	public const VERSION = '2.0.0';

	public static function run(): void {
		global $wpdb;

		$jobs_table = $wpdb->prefix . 'pllat_jobs';

		// Backfill content_type for posts.
		$wpdb->query(
			"UPDATE {$jobs_table} j
			INNER JOIN {$wpdb->posts} p ON j.type = 'post' AND j.id_from = p.ID
			SET j.content_type = p.post_type
			WHERE j.type = 'post' AND j.content_type = ''",
		);

		// Backfill content_type for terms.
		$wpdb->query(
			"UPDATE {$jobs_table} j
			INNER JOIN {$wpdb->term_taxonomy} tt ON j.type = 'term' AND j.id_from = tt.term_id
			SET j.content_type = tt.taxonomy
			WHERE j.type = 'term' AND j.content_type = ''",
		);
	}
}
