<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.14.0: add `as_action_id` to pllat_claims.
 *
 * Claim liveness is no longer guessed from a wall-clock heartbeat. A claim
 * records the Action Scheduler action that owns it; liveness is derived
 * from that action's status (AS is the system of record for "is the worker
 * alive" and is a hard plugin requirement). A claim whose owning action is
 * no longer live is reclaimed back to 'pending' without burning an attempt.
 *
 * Beta line, no production data — a plain idempotent ADD COLUMN is safe.
 * Safe to re-run: the SHOW COLUMNS guard filters back to a no-op.
 */
final class Migrate_To_3_14_0 {
    public const VERSION = '3.14.0';

    /**
     * Add the as_action_id column + index to pllat_claims if absent.
     *
     * @return void
     */
    public static function run(): void {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'pllat_claims';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
        $column = $wpdb->get_results( "SHOW COLUMNS FROM {$claims_table} LIKE 'as_action_id'" );
        if ( \is_array( $column ) && \count( $column ) > 0 ) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
        $wpdb->query(
            "ALTER TABLE {$claims_table}
             ADD COLUMN as_action_id BIGINT UNSIGNED NULL DEFAULT NULL,
             ADD KEY as_action_id (as_action_id)",
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
