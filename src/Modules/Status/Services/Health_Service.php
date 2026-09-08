<?php
/**
 * Health_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status
 */

declare(strict_types=1);

namespace PLLAT\Status\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler health probe for the lean pipeline.
 *
 * Only callers: Settings_Form (status badge) and Single_Translation_Service
 * (preflight). Both consume the array returned by get_scheduler_health.
 */
class Health_Service {
    /**
     * Hook the lean pipeline uses to invoke a worker. Must match
     * Pipeline_Tick_Handler::HOOK_PROCESS_RUN.
     *
     * @var string
     */
    private const HOOK_PROCESS_RUN = 'pllat_process_run';

    /**
     * Threshold in seconds to consider actions as stuck (5 minutes).
     *
     * @var int
     */
    private const STUCK_THRESHOLD_SECONDS = 300;

    /**
     * Get Action Scheduler health status for the worker hook.
     *
     * @return array{has_stuck_actions: bool, oldest_pending_age: int|null, pending_count: int}
     */
    public function get_scheduler_health(): array {
        $oldest_age = $this->get_oldest_pending_age();

        return array(
            'has_stuck_actions'  => null !== $oldest_age && $oldest_age > self::STUCK_THRESHOLD_SECONDS,
            'oldest_pending_age' => $oldest_age,
            'pending_count'      => $this->get_pending_count(),
        );
    }

    private function get_oldest_pending_age(): ?int {
        if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
            return null;
        }

        $oldest_pending = \as_get_scheduled_actions(
            array(
                'hook'     => self::HOOK_PROCESS_RUN,
                'order'    => 'ASC',
                'orderby'  => 'date',
                'per_page' => 1,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
            ),
            'objects',
        );

        if ( array() === $oldest_pending ) {
            return null;
        }

        $action    = \reset( $oldest_pending );
        $scheduled = $action->get_schedule()->get_date();
        if ( null === $scheduled ) {
            return null;
        }

        return \time() - $scheduled->getTimestamp();
    }

    private function get_pending_count(): int {
        if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
            return 0;
        }

        return (int) \as_get_scheduled_actions(
            array(
                'hook'   => self::HOOK_PROCESS_RUN,
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ),
            'count',
        );
    }
}
