<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.15.0: add `source_lang` to pllat_activity_log.
 *
 * The activity feed used to derive a row's source language from a
 * pllat_translation_index JOIN. A unit that only ever failed (or was
 * never translated) has no index row, so failed/error feed entries lost
 * their "EN ->" prefix. Storing the source language on the log row makes
 * every entry self-describing and queryable, independent of the index.
 *
 * Beta line, no production data — a plain idempotent ADD COLUMN is safe.
 * Safe to re-run: the SHOW COLUMNS guard filters back to a no-op.
 */
final class Migrate_To_3_15_0 {
    public const VERSION = '3.15.0';

    /**
     * Add the source_lang column to pllat_activity_log if absent.
     *
     * @return void
     */
    public static function run(): void {
        global $wpdb;

        $log_table = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
        $column = $wpdb->get_results( "SHOW COLUMNS FROM {$log_table} LIKE 'source_lang'" );
        if ( \is_array( $column ) && \count( $column ) > 0 ) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
        $wpdb->query(
            "ALTER TABLE {$log_table}
             ADD COLUMN source_lang VARCHAR(10) NOT NULL DEFAULT '' AFTER source_id",
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
