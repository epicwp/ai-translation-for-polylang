<?php
/**
 * Job_Cleanup_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Deletes failed claims older than the retention period.
 *
 * In the lean pipeline, claims are deleted on successful completion.
 * Only failed claims (terminal, not re-claimed) linger and need periodic pruning.
 * Uses created_at as the age anchor since completed_at is not tracked on claims.
 *
 * Prevents unbounded growth of wp_pllat_claims.
 */
class Job_Cleanup_Service {

    /**
     * Default retention period in days.
     */
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Maximum claims to delete per batch (avoid long-running queries).
     */
    private const BATCH_SIZE = 500;

    /**
     * Claims table name.
     */
    private string $claims_table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->claims_table = $wpdb->prefix . 'pllat_claims';
    }

    /**
     * Delete failed claims older than the retention period.
     *
     * @return int Number of claims deleted.
     */
    public function cleanup(): int {
        global $wpdb;

        $retention_days = $this->get_retention_days();
        $cutoff         = \time() - ( $retention_days * DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claim_ids = \array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$this->claims_table} WHERE status = 'failed' AND created_at < %d AND created_at > 0 LIMIT %d",
                    $cutoff,
                    self::BATCH_SIZE,
                ),
            ),
        );

        if ( 0 === \count( $claim_ids ) ) {
            return 0;
        }

        $placeholders = \implode( ',', \array_fill( 0, \count( $claim_ids ), '%d' ) );

        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from prefix; one %d per claim id, ids prepared.
                "DELETE FROM {$this->claims_table} WHERE id IN ({$placeholders})",
                ...$claim_ids,
            ),
        );

        return $wpdb->rows_affected;
    }

    /**
     * Get job retention period in days.
     *
     * @return int Number of days to retain jobs.
     */
    private function get_retention_days(): int {
        /**
         * Filter the job retention period.
         *
         * @param int $days Number of days to retain completed/failed jobs (default: 30).
         */
        $days = \apply_filters( 'pllat_job_retention_days', self::DEFAULT_RETENTION_DAYS );

        // Minimum 7 days to prevent accidental data loss.
        return \max( 7, (int) $days );
    }
}
