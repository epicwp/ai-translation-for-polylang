<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.7.0 (Plan G): source hash table for drift detection.
 *
 * Hashes captured at task creation time allowed detecting edits that
 * bypassed save_post hooks. Table itself is dropped in 3.10.0 once
 * translation_field_state replaces it architecturally.
 */
final class Migrate_To_3_7_0 {
	public const VERSION = '3.7.0';

	public static function run(): void {
		global $wpdb;
		$table           = $wpdb->prefix . 'pllat_source_hashes';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			entity_type VARCHAR(10) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			reference VARCHAR(191) NOT NULL,
			hash CHAR(32) NOT NULL,
			updated_at INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (entity_type, entity_id, reference),
			KEY idx_updated_at (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}
}
