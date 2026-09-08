<?php
/**
 * Action_Scheduler_Timeout_Manager class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;

/**
 * Manages timeout detection for Action Scheduler jobs.
 *
 * Provides proactive timeout checking to prevent jobs from being
 * killed mid-execution by PHP's max_execution_time limit.
 *
 * Usage:
 * - Call start() at beginning of job processing
 * - Call check_timeout() before each task
 * - Handles Job_Timeout_Exception in job processor
 */
class Action_Scheduler_Timeout_Manager {
    /**
     * Default desired timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 300;

    /**
     * Job processing start time (microtime).
     *
     * @var float|null
     */
    private ?float $start_time = null;

    /**
     * Safe timeout limit in seconds.
     *
     * @var int
     */
    private int $safe_limit;

    /**
     * Constructor.
     *
     * @param int $desired_timeout Desired timeout in seconds.
     */
    public function __construct( int $desired_timeout = self::DEFAULT_TIMEOUT ) {
        $this->safe_limit = $this->calculate_safe_timeout( $desired_timeout );
    }

    /**
     * Start tracking execution time.
     *
     * Call this at the beginning of job processing.
     *
     * @return void
     */
    public function start(): void {
        $this->start_time = \microtime( true );
    }

    /**
     * Check if job is approaching timeout.
     *
     * Throws exception if not enough time remains to process another task.
     *
     * @param int $completed_tasks Number of tasks completed so far.
     * @param int $total_tasks     Total number of tasks.
     * @return void
     * @throws \RuntimeException If timeout approaching.
     */
    public function check_timeout( int $completed_tasks, int $total_tasks ): void {
        if ( null === $this->start_time ) {
            return; // Not started yet.
        }

        $elapsed = $this->get_elapsed_time();

        if ( $elapsed >= $this->safe_limit ) {
            throw new \RuntimeException(
                \sprintf(
                    'Job timeout: %d seconds elapsed (%d/%d tasks completed)',
                    (int) $elapsed,
                    (int) $completed_tasks,
                    (int) $total_tasks,
                ),
            );
        }
    }

    /**
     * Get elapsed time since start.
     *
     * @return int Elapsed seconds.
     */
    public function get_elapsed_time(): int {
        if ( null === $this->start_time ) {
            return 0;
        }

        return (int) ( \microtime( true ) - $this->start_time );
    }

    /**
     * Get remaining time before timeout.
     *
     * @return int Remaining seconds.
     */
    public function get_remaining_time(): int {
        return \max( 0, $this->safe_limit - $this->get_elapsed_time() );
    }

    /**
     * Get the safe timeout limit.
     *
     * @return int Safe limit in seconds.
     */
    public function get_safe_limit(): int {
        return $this->safe_limit;
    }

    /**
     * Calculate safe timeout with buffer.
     *
     * Uses existing Helpers::set_max_execution_time() which applies
     * 20% buffer based on PHP's max_execution_time.
     *
     * @param int $desired_limit Desired timeout in seconds.
     * @return int Safe timeout limit.
     */
    private function calculate_safe_timeout( int $desired_limit ): int {
        // Reuse existing helper that handles host-specific limits.
        return Helpers::set_max_execution_time( $desired_limit, $desired_limit );
    }
}
