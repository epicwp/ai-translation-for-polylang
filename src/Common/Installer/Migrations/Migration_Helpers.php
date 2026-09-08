<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared utilities used across multiple migrations + the installer.
 *
 * Each helper is idempotent: safe to run repeatedly without side effects.
 */
final class Migration_Helpers {

	/**
	 * Whether a foreign key constraint exists on the given table.
	 */
	public static function constraint_exists( string $table_name, string $constraint_name ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
				WHERE CONSTRAINT_SCHEMA = %s
				AND TABLE_NAME = %s
				AND CONSTRAINT_NAME = %s
				AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
				DB_NAME,
				$table_name,
				$constraint_name,
			),
		);

		return $count > 0;
	}

	/**
	 * Whether an index exists on the given table.
	 */
	public static function index_exists( string $table_name, string $index_name ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND INDEX_NAME = %s',
				DB_NAME,
				$table_name,
				$index_name,
			),
		);

		return $count > 0;
	}

	/**
	 * Idempotently ensure pllat_translation_index has the outdated_at column + index.
	 *
	 * Used by Installer::install() (for new installs that may have crossed 3.9.0
	 * before this column existed) and Migrate_To_3_9_0 (for upgrades).
	 */
	public static function ensure_translation_index_outdated_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! \in_array( 'outdated_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN outdated_at DATETIME NULL DEFAULT NULL" );
		}

		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'outdated'", 0 );
		if ( \count( $indexes ) === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY outdated (outdated_at)" );
		}
	}
}
