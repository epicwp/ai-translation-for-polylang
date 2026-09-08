<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.13.0: backfill content_subtype and content_status on
 * pllat_translation_index term rows.
 *
 * Lean_Job_Worker::ensure_target_exists used to only resolve post_type +
 * post_status for the post branch; term rows were upserted with empty
 * content_subtype and content_status. Dashboard_Data_Service counts done
 * via `WHERE content_status IN ('publish','active')`, so those empty rows
 * disappeared from the dashboard's done totals even though the targets
 * existed and the translations were correct.
 *
 * The code path is fixed in 0df81bf5. This migration backfills rows
 * already written under the broken path.
 *
 * Scope:
 *   - source_kind = 'term' only. Post rows are unaffected (the bug never
 *     touched the post branch).
 *   - Only rows where content_subtype OR content_status is empty/NULL are
 *     touched. Already-correct rows are left alone (idempotent).
 *   - JOIN on wp_term_taxonomy via source_id resolves the term's taxonomy
 *     slug for content_subtype. content_status hardcoded to 'active' to
 *     match the pre-lean writes that share this filter target.
 *   - Terms whose wp_term_taxonomy entry has since been deleted (orphans)
 *     stay untouched — they would not appear in the dashboard aggregate
 *     anyway and we have no taxonomy slug to write.
 *
 * Safe to re-run: the WHERE clause filters back to zero affected rows
 * after the first successful pass.
 */
final class Migrate_To_3_13_0 {

	public const VERSION = '3.13.0';

	public static function run(): void {
		global $wpdb;

		$index_table         = $wpdb->prefix . 'pllat_translation_index';
		$term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix.
		$wpdb->query( "
			UPDATE {$index_table} i
			INNER JOIN {$term_taxonomy_table} tt ON tt.term_id = i.source_id
			SET i.content_subtype = tt.taxonomy,
			    i.content_status  = 'active'
			WHERE i.source_kind = 'term'
			  AND (
			    i.content_subtype IS NULL OR i.content_subtype = ''
			    OR i.content_status IS NULL OR i.content_status = ''
			  )
		" );
	}
}
