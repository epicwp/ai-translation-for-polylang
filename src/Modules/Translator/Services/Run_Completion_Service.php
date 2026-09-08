<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Models\Run_Spec;

/**
 * Single owner of run completion logic.
 *
 * Completion invariant:
 *   A run is complete ⇔ count_open_claims(run) === 0
 *                       AND has_remaining_candidates(spec) === false
 *
 * Both checks are deterministic DB queries. We never count AS actions or
 * try to detect a "quiet moment" between parallel workers — that path
 * never settled and produced a runaway loop (see
 * fix/lean-completion-deterministic).
 *
 * Two entry points:
 *
 *   handle_idle_worker()  — worker found nothing to claim. If no open
 *                           claims remain, atomically mark completed.
 *                           Otherwise return silently; the worker draining
 *                           the last claim will itself land here.
 *
 *   mark_completed_atomic() — race-safe finalize. Used by both the idle
 *                             worker and Pipeline_Tick_Handler. Only the
 *                             caller observing status='processing' wins;
 *                             losers no-op.
 */
class Run_Completion_Service {

    public function __construct(
        private Job_Claim_Service $claim_service,
    ) {
    }

    /**
     * Worker just discovered claim_next_unit() returned null.
     *
     * If sibling workers still hold open claims, those workers will end up
     * here when they finish; one of them will be the last to observe
     * open_claims === 0 and win the atomic finalize.
     */
    public function handle_idle_worker( int $run_id, Run_Spec $spec ): void {
        if ( $this->count_open_claims( $run_id ) > 0 ) {
            return;
        }
        $this->mark_completed_atomic( $run_id );
    }

    /**
     * Race-safe finalize. Returns true if this caller transitioned the run
     * from 'processing' → 'completed'; false if another caller already did.
     */
    public function mark_completed_atomic( int $run_id ): bool {
        global $wpdb;
        $affected = $wpdb->update(
            $wpdb->prefix . 'pllat_bulk_runs',
            array(
                'completed_at' => \time(),
                'status'       => 'completed',
            ),
            array(
                'id'     => $run_id,
                'status' => 'processing',
            ),
            array( '%d', '%s' ),
            array( '%d', '%s' ),
        );
        if ( $affected < 1 ) {
            return false;
        }
        $wpdb->delete(
            $wpdb->prefix . 'pllat_claims',
            array( 'run_id' => $run_id ),
            array( '%d' ),
        );
        \do_action( 'pllat_run_completed', $run_id );
        return true;
    }

    /**
     * Unconditional finalize used by abandon-stale-run and the cancel path.
     *
     * Distinct from mark_completed_atomic: skips the WHERE status='processing'
     * guard so callers that have *already decided* the run is dead (stale
     * heartbeat, user cancel, etc.) can finalize regardless of current status.
     */
    public function mark_completed( int $run_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_bulk_runs',
            array(
                'completed_at' => \time(),
                'status'       => 'completed',
            ),
            array( 'id' => $run_id ),
            array( '%d', '%s' ),
            array( '%d' ),
        );
        $wpdb->delete(
            $wpdb->prefix . 'pllat_claims',
            array( 'run_id' => $run_id ),
            array( '%d' ),
        );
        \do_action( 'pllat_run_completed', $run_id );
    }

    public function count_open_claims( int $run_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pllat_claims
                 WHERE run_id = %d AND status IN ('pending', 'in_progress')",
                $run_id,
            ),
        );
    }

    /**
     * Deterministic "can this run produce more work?" check.
     *
     * Used by Pipeline_Tick_Handler to decide whether to spawn workers or
     * finalize. Exposed through Run_Completion_Service so the tick handler
     * doesn't need a direct Job_Claim_Service dependency.
     */
    public function has_remaining_candidates( int $run_id, Run_Spec $spec ): bool {
        return $this->claim_service->has_remaining_candidates( $run_id, $spec );
    }
}
