<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.9.0: translation index + field state tables for the lean pipeline.
 *
 * - pllat_translation_index: polymorphic over posts/terms. One row per
 *   (source_kind, source_id, target_lang). UNIQUE constraint enforces one
 *   translation pair per language; source_lang denormalised so workers can
 *   filter without joining Polylang's term taxonomy.
 * - pllat_translation_field_state: per-field signature ledger. Written on
 *   translation success; reconciliation reads it to short-circuit already-
 *   done work. Loss costs LLM money, not correctness.
 * - runs.spec column (LONGTEXT JSON) added via dbDelta diff.
 *
 * The claim_unit UNIQUE KEY is intentionally omitted here — pre-upgrade
 * jobs may have conflicting tuples; 3.11.0 adds it cleanly on the renamed
 * claims table.
 */
final class Migrate_To_3_9_0 {
	public const VERSION = '3.9.0';

	public static function run(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$index_table = $wpdb->prefix . 'pllat_translation_index';

		$sql = "CREATE TABLE {$index_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_lang VARCHAR(20) NOT NULL,
			target_lang VARCHAR(20) NOT NULL,
			target_id BIGINT UNSIGNED NULL,
			content_subtype VARCHAR(40) NOT NULL,
			content_status VARCHAR(20) NOT NULL,
			last_synced DATETIME NOT NULL,
			outdated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY src_target (source_kind, source_id, target_lang),
			KEY type_lang_pair (source_kind, content_subtype, source_lang, target_lang, target_id),
			KEY content_status (content_status),
			KEY outdated (outdated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );

		$field_state_table = $wpdb->prefix . 'pllat_translation_field_state';

		$field_state_sql = "CREATE TABLE {$field_state_table} (
			source_kind VARCHAR(8) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			target_lang VARCHAR(20) NOT NULL,
			reference VARCHAR(191) NOT NULL,
			source_hash CHAR(16) NOT NULL,
			translated_at DATETIME NOT NULL,
			PRIMARY KEY  (source_kind, source_id, target_lang, reference),
			KEY idx_translated_at (translated_at)
		) {$charset_collate};";

		\dbDelta( $field_state_sql );

		// Extend existing runs table with lean-pipeline columns (spec).
		$runs_table_extension = "CREATE TABLE {$wpdb->prefix}pllat_bulk_runs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trace_id CHAR(36) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			config LONGTEXT NOT NULL,
			spec LONGTEXT NULL,
			created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			completed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_heartbeat BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		\dbDelta( $runs_table_extension );

		// Schedule the translation_index prime so existing customer sites
		// get their Polylang translations projected into the new table.
		if ( ! \get_option( 'pllat_translation_index_primed' ) && \function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action(
				'pllat_translation_index_prime',
				array( 'post', 0 ),
				'pllat-prime',
			);
		}

		// Idempotently add outdated_at for sites already on 3.9.0.
		Migration_Helpers::ensure_translation_index_outdated_column();
	}
}
