<?php
/**
 * Cleanup_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Cleanup
 */

declare(strict_types=1);

namespace PLLAT\Cleanup\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Handles cleanup of claims when source content is deleted.
 *
 * Responsibilities:
 * - Remove claims when source content is deleted (source_id).
 *   Target-side cleanup is not needed — target lives in pllat_translation_index,
 *   which is maintained separately.
 */
class Cleanup_Service {
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
     * Clean up all claims related to deleted source content.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Source content ID being deleted.
     * @return int Number of claims deleted.
     */
    public function cleanup_jobs_for_content( string $type, int $id ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->claims_table} WHERE source_kind = %s AND source_id = %d",
                $type,
                $id,
            ),
        );

        return (int) $deleted;
    }
}
