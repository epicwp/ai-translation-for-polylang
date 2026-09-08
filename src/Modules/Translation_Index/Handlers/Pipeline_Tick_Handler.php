<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\Run_Completion_Service;
use PLLAT\Translator\Services\Run_Spec_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Single recurring AS hook that drives the lean pipeline.
 *
 * On each tick, for each run with status='processing':
 *   - if 0 open claims AND no remaining candidates → finalize.
 *   - else spawn workers up to CONCURRENCY_TARGET (only when work exists
 *     to claim — never speculatively).
 *
 * There is no stale-run sweep. A worker that died mid-claim is recovered
 * by AS-derived claim liveness (Job_Claim_Service reclaims a claim whose
 * owning Action Scheduler action is no longer live); a run with no work
 * left finalizes via the 0-open-claims path above. Liveness lives in one
 * place — the claim lease — not in a parallel run-heartbeat timeout.
 */
#[Handler( tag: 'init', priority: 20 )]
class Pipeline_Tick_Handler {

    public const HOOK_TICK          = 'pllat_pipeline_tick';
    public const HOOK_PROCESS_RUN   = 'pllat_process_run';
    public const TICK_INTERVAL      = 60;
    // One unit at a time: a started post is driven to completion (its
    // parked batch-continuations resumed first, see Job_Claim_Service::
    // claim_next_unit) before a new post is opened. Avoids fanning out
    // across many posts with no visible progress, and keeps request
    // volume gentle on low provider tiers.
    public const CONCURRENCY_TARGET = 1;

    /**
     * Schedule one worker action for a run, immediately (async), unless one
     * is already queued. Single source of truth for "spawn a worker for
     * this run": used for the initial enqueue (Run_Spec_Service) and by the
     * worker itself when it parks a batch-continuation, so the next batch
     * starts within seconds instead of waiting up to TICK_INTERVAL (60s)
     * for the recurring tick. The idempotency guard keeps it from piling
     * duplicates, so it cannot push concurrency past one in-flight worker.
     *
     * @param int $run_id Run to enqueue a worker for.
     */
    public static function enqueue_worker( int $run_id ): void {
        $group = 'pllat-run-' . $run_id;
        if ( \as_has_scheduled_action( self::HOOK_PROCESS_RUN, array( $run_id ), $group ) ) {
            return;
        }
        \as_enqueue_async_action( self::HOOK_PROCESS_RUN, array( $run_id ), $group );
    }

    public function __construct(
        protected Run_Spec_Service $run_service,
        protected Run_Completion_Service $completion_service,
    ) {}

    #[Action( tag: self::HOOK_TICK, priority: 10 )]
    public function handle_tick(): void {
        global $wpdb;

        // Sweep claims whose owning run is gone or no longer processing. A
        // heavy-pipeline run is 'processing' from creation and never returns
        // to it once terminal (completed/cancelled/abandoned), so such a
        // claim can never complete; left behind it renders forever as a
        // phantom "Translating … In progress" active event. This enforces
        // the leaked-claim invariant and self-heals legacy orphans.
        $claims_table = $wpdb->prefix . 'pllat_claims';
        $runs_table   = $wpdb->prefix . 'pllat_bulk_runs';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix; no user input.
        $wpdb->query(
            "DELETE c FROM {$claims_table} c
             LEFT JOIN {$runs_table} r ON r.id = c.run_id
             WHERE r.id IS NULL OR r.status <> 'processing'",
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}pllat_bulk_runs WHERE status = 'processing' ORDER BY id ASC",
            \ARRAY_A,
        );
        if ( null === $rows ) {
            return;
        }
        foreach ( $rows as $row ) {
            $this->ensure_workers_for_run( (int) $row['id'] );
        }
    }

    public function ensure_scheduled(): void {
        if ( \as_has_scheduled_action( self::HOOK_TICK ) ) {
            return;
        }
        \as_schedule_recurring_action(
            \time() + self::TICK_INTERVAL,
            self::TICK_INTERVAL,
            self::HOOK_TICK,
            array(),
            'pllat-pipeline',
        );
    }

    private function ensure_workers_for_run( int $run_id ): void {
        $spec = $this->run_service->get_spec( $run_id );
        if ( null === $spec ) {
            return;
        }

        $open_claims = $this->completion_service->count_open_claims( $run_id );
        $group       = 'pllat-run-' . $run_id;
        $current     = $this->count_active_workers( $group );

        // Fast path: work is already in flight. A running worker drives claiming
        // (it claims fresh candidates itself and self-finalizes on its idle
        // iteration), so the tick only needs to keep up to CONCURRENCY_TARGET
        // workers alive — it does NOT need the heavy candidate JOIN. Skipping
        // has_remaining_candidates() here removes a near-full-table scan that
        // otherwise ran every tick for the whole life of a draining run. The
        // spawn decision below is identical to the previous code for open>0.
        if ( $open_claims > 0 ) {
            $this->spawn_workers( $run_id, $group, $this->concurrency_target() - $current );
            return;
        }

        // No claims in flight: now it is worth the candidate JOIN to decide
        // whether the run is finished or there is fresh work to claim.
        $has_candidates = $this->completion_service->has_remaining_candidates( $run_id, $spec );
        if ( ! $has_candidates ) {
            // No work in flight, no work left to claim — provably done.
            $this->completion_service->mark_completed_atomic( $run_id );
            return;
        }

        $this->spawn_workers( $run_id, $group, self::CONCURRENCY_TARGET - $current );
    }

    /**
     * Number of workers to keep running per active run. Default 1 (serial —
     * safe for low-tier API plans); large-site operators can opt into 2-4
     * parallel workers via the `pllat_pipeline_concurrency` filter. Claiming is
     * race-safe (INSERT ... ON DUPLICATE KEY — a lost race yields no claim), so
     * raising this only adds throughput, never double-translation. Floored at 1.
     */
    private function concurrency_target(): int {
        return \max( 1, (int) \apply_filters( 'pllat_pipeline_concurrency', self::CONCURRENCY_TARGET ) );
    }

    private function spawn_workers( int $run_id, string $group, int $needed ): void {
        for ( $i = 0; $i < $needed; $i++ ) {
            \as_enqueue_async_action(
                self::HOOK_PROCESS_RUN,
                array( $run_id ),
                $group,
            );
        }
    }

    private function count_active_workers( string $group ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->actionscheduler_actions} a
                 INNER JOIN {$wpdb->actionscheduler_groups} g ON a.group_id = g.group_id
                 WHERE a.hook = %s AND g.slug = %s AND a.status IN ('pending', 'in-progress')",
                self::HOOK_PROCESS_RUN,
                $group,
            ),
        );
    }
}
