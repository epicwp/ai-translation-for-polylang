<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.6.0: provider health table for circuit breaker state.
 *
 * Atomic UPDATEs across concurrent AS workers require a dedicated table
 * rather than a wp_options row (last-write-wins under contention).
 */
final class Migrate_To_3_6_0 {
	public const VERSION = '3.6.0';

	public static function run(): void {
		global $wpdb;
		$table           = $wpdb->prefix . 'pllat_provider_health';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			provider VARCHAR(32) NOT NULL,
			consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
			last_failure_at INT UNSIGNED NOT NULL DEFAULT 0,
			circuit_open_until INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (provider)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}
}
