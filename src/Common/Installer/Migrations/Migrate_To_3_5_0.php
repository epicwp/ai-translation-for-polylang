<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.5.0: support access TTL backfill + audit table.
 *
 * Sets expires_at = now + 24h on legacy sessions without it. Originally
 * also swapped the support user to a scoped diagnostic role; that role
 * was removed in 3.8.0 so this migration now only handles TTL backfill
 * and audit table creation.
 */
final class Migrate_To_3_5_0 {
	public const VERSION = '3.5.0';

	public static function run(): void {
		$option = \get_option( 'pllat_support_access' );
		if ( \is_array( $option ) && isset( $option['user_id'] ) && ! isset( $option['expires_at'] ) ) {
			$option['expires_at'] = \time() + \DAY_IN_SECONDS;
			\update_option( 'pllat_support_access', $option );
		}

		global $wpdb;
		$audit_table     = $wpdb->prefix . 'pllat_support_access_audit';
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$audit_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			endpoint VARCHAR(255) NOT NULL,
			method VARCHAR(10) NOT NULL,
			ip VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			response_code SMALLINT UNSIGNED NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_user_endpoint_time (user_id, endpoint, timestamp)
		) {$charset_collate};";

		\dbDelta( $sql );
	}
}
