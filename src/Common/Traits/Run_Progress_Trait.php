<?php
declare(strict_types=1);

namespace PLLAT\Common\Traits;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared trait for building standardized runProgress data arrays.
 *
 * Used by Dashboard_Data_Service (content) and Strings_Service (strings).
 * Consumers call the appropriate repo method for counts (job-level or task-level)
 * then pass the result here for uniform shape + timing computation.
 */
trait Run_Progress_Trait {
    /**
     * Build standardized runProgress data from raw counts and timing.
     *
     * @param array{total: int, completed: int, pending: int, in_progress: int, failed: int} $counts Raw counts.
     * @param int $started_at Unix timestamp when the run started.
     * @return array The runProgress array for the frontend.
     */
    private function build_run_progress_data( array $counts, int $started_at ): array {
        $total     = $counts['total'];
        $completed = $counts['completed'];
        $failed    = $counts['failed'];
        $pending   = $counts['pending'];

        $percentage     = $total > 0 ? \round( ( $completed / $total ) * 100, 1 ) : 0.0;
        $elapsed        = $started_at > 0 ? \time() - $started_at : 0;
        $jobs_processed = $completed + $failed;
        $jobs_remaining = $pending + $counts['in_progress'];

        $estimated = null;
        if ( $jobs_processed > 0 && $jobs_remaining > 0 ) {
            $estimated = (int) \round( ( $elapsed / $jobs_processed ) * $jobs_remaining );
        }

        return array(
            'completed'     => $completed,
            'elapsedTime'   => $elapsed,
            'estimatedTime' => $estimated,
            'failed'        => $failed,
            'inProgress'    => $counts['in_progress'],
            'pending'       => $pending,
            'percentage'    => $percentage,
            'startedAt'     => $started_at,
            'total'         => $total,
        );
    }
}
