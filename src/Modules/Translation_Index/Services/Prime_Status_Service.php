<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Handlers\Translation_Index_Prime_Handler;

/**
 * Public API for prime state: is_primed, ensure_primed, prime_now, progress.
 *
 * REST controller (Phase C) and module init (Phase E) call this service.
 * Chunk execution lives in Translation_Index_Prime_Service; the Handler's
 * HOOK constant is referenced statically for AS scheduling.
 */
class Prime_Status_Service {

    private const STUCK_THRESHOLD_SECONDS = 300;

    public function __construct(
        protected Translation_Index_Prime_Service $service,
    ) {}

    public function is_primed(): bool {
        return (bool) \get_option( 'pllat_translation_index_primed' );
    }

    public function ensure_primed(): void {
        if ( $this->is_primed() ) {
            return;
        }
        if ( \function_exists( 'as_has_scheduled_action' )
            && \as_has_scheduled_action( Translation_Index_Prime_Handler::HOOK ) ) {
            return;
        }
        if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
            return;
        }
        \as_enqueue_async_action(
            Translation_Index_Prime_Handler::HOOK,
            array( 'post', 0 ),
            'pllat-prime',
        );
    }

    public function prime_now(): void {
        $this->mark_prime_started();
        $this->run_full_kind( 'post' );
        $this->run_full_kind( 'term' );
        $this->mark_prime_completed();
        \update_option( 'pllat_translation_index_primed', 1, false );
    }

    public function progress(): int {
        if ( $this->is_primed() ) {
            return 100;
        }
        return (int) \get_option( 'pllat_translation_index_prime_progress', 0 );
    }

    /**
     * Record that a prime walk has started (called on the first chunk).
     *
     * Clears the previous run's completion so timing reflects the current run.
     * Until this session, a prime that took ~15 min over 173k posts left no
     * timing trace — diagnosis meant polling Action Scheduler and COUNT(*)ing
     * the index by hand.
     */
    public function mark_prime_started(): void {
        \update_option( 'pllat_prime_started_at', \time(), false );
        \delete_option( 'pllat_prime_completed_at' );
        \delete_option( 'pllat_prime_rows_indexed' );
    }

    /**
     * Record prime completion + the resulting index row count.
     */
    public function mark_prime_completed(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
        $rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pllat_translation_index" );
        \update_option( 'pllat_prime_completed_at', \time(), false );
        \update_option( 'pllat_prime_rows_indexed', $rows, false );
    }

    /**
     * Timing + progress snapshot of the most recent prime walk.
     *
     * @return array{
     *   started_at:int|null, completed_at:int|null, duration_seconds:int|null,
     *   rows_indexed:int|null, running:bool, progress:int, primed:bool
     * }
     */
    public function get_timing(): array {
        $started   = (int) \get_option( 'pllat_prime_started_at', 0 );
        $completed = (int) \get_option( 'pllat_prime_completed_at', 0 );
        $rows      = (int) \get_option( 'pllat_prime_rows_indexed', 0 );

        $running  = $started > 0 && 0 === $completed;
        $duration = ( $started > 0 && $completed >= $started ) ? $completed - $started : null;

        return array(
            'started_at'       => $started > 0 ? $started : null,
            'completed_at'     => $completed > 0 ? $completed : null,
            'duration_seconds' => $duration,
            'rows_indexed'     => $completed > 0 ? $rows : null,
            'running'          => $running,
            'progress'         => $this->progress(),
            'primed'           => $this->is_primed(),
        );
    }

    /**
     * True if a pllat_translation_index_prime action is pending or in-progress in AS.
     */
    public function is_currently_priming(): bool {
        if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
            return false;
        }
        $hits = \as_get_scheduled_actions(
            array(
                'hook'     => Translation_Index_Prime_Handler::HOOK,
                'status'   => array(
                    \ActionScheduler_Store::STATUS_PENDING,
                    \ActionScheduler_Store::STATUS_RUNNING,
                ),
                'per_page' => 1,
            ),
            'ids',
        );
        return array() !== $hits;
    }

    /**
     * True if an in-progress prime action has been running for >= STUCK_THRESHOLD seconds.
     *
     * Uses last_attempt_gmt (when the worker claimed the action) rather than the
     * scheduled/enqueue time so that actions waiting in PENDING for >300 s in a
     * backed-up cron queue do not appear "stuck" the moment they transition to RUNNING.
     */
    public function has_stuck_prime(): bool {
        if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
            return false;
        }
        $oldest_ids = \as_get_scheduled_actions(
            array(
                'hook'     => Translation_Index_Prime_Handler::HOOK,
                'status'   => \ActionScheduler_Store::STATUS_RUNNING,
                'orderby'  => 'date',
                'order'    => 'ASC',
                'per_page' => 1,
            ),
            'ids',
        );
        if ( array() === $oldest_ids ) {
            return false;
        }
        $action_id    = (int) \reset( $oldest_ids );
        $store        = \ActionScheduler::store();
        $last_attempt = $store->get_date_gmt( $action_id );
        if ( ! ( $last_attempt instanceof \DateTime ) ) {
            return false;
        }
        return ( \time() - $last_attempt->getTimestamp() ) >= self::STUCK_THRESHOLD_SECONDS;
    }

    /**
     * Reset primed state and enqueue an async prime walk.
     *
     * Used by the Re-run prime recovery flow from the PreflightFailedDialog.
     *
     * Known limitation: as_unschedule_all_actions cancels STATUS_PENDING actions
     * only — a worker mid-execution will continue running its current chunk. If
     * the running worker completes between this call and the new action firing,
     * it may briefly set primed=true before the fresh walk runs. The fresh walk
     * is idempotent (upsert-based) so data correctness is preserved; the only
     * observable effect is a possible flicker of the primed flag during the
     * overlap window.
     *
     * @return bool True if a fresh prime action was enqueued, false if AS is
     *              unavailable or the enqueue was rejected.
     */
    public function schedule_prime(): bool {
        \delete_option( 'pllat_translation_index_primed' );
        \update_option( 'pllat_translation_index_prime_progress', 0, false );
        if ( \function_exists( 'as_unschedule_all_actions' ) ) {
            \as_unschedule_all_actions( Translation_Index_Prime_Handler::HOOK );
        }
        if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
            return false;
        }
        $action_id = \as_enqueue_async_action(
            Translation_Index_Prime_Handler::HOOK,
            array( 'post', 0 ),
            'pllat-prime',
        );
        return $action_id > 0;
    }

    private function run_full_kind( string $kind ): void {
        $offset = 0;
        do {
            $result = $this->service->run_chunk( $kind, $offset, 500 );
            $offset = (int) $result['next_offset'];
        } while ( ! $result['done'] );
    }
}
