<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Lean cancel: flip runs.status to 'cancelled', reap all claims, and
 * unschedule any pending Action Scheduler workers for this run.
 *
 * Deletes EVERY claim for the run, including in_progress ones. Earlier
 * iterations of this service kept in_progress rows alive on the assumption
 * that "the worker is mid-flight and will exit cleanly on the next
 * run-status check" — but that assumption breaks when the worker has
 * already died (AS timeout, PHP fatal, container OOM). Without cleanup
 * those rows survive the cancel and Translation_Status_Builder_Service
 * keeps showing them as "translating" forever.
 *
 * Mirrors Run_Completion_Service::mark_completed which also DELETEs all
 * claims for the run on completion. Race with a still-alive mid-flight
 * worker: the worker's later claim_lifecycle->complete() call hits an
 * already-gone row (wpdb DELETE on missing id = no-op, no error).
 *
 * Also unschedules pending `pllat_process_run` actions in the run's group
 * so they don't drift in the AS queue. Workers that have already started
 * still exit cleanly via is_run_cancelled() — the unschedule is for the
 * queued-but-not-yet-fired tail.
 */
class Cancel_Service {

    public function cancel( int $run_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_bulk_runs',
            array( 'status' => 'cancelled', 'completed_at' => \time() ),
            array( 'id' => $run_id ),
            array( '%s', '%d' ),
            array( '%d' ),
        );
        $wpdb->delete(
            $wpdb->prefix . 'pllat_claims',
            array( 'run_id' => $run_id ),
            array( '%d' ),
        );

        // Drop pending workers from AS so they don't fire after cancel.
        // Workers already mid-execution will hit is_run_cancelled() on
        // their next loop iteration and exit; this only targets the
        // queued tail that hasn't started yet.
        //
        // Call with empty $hook so AS takes the cancel_actions_by_group
        // fast path. With both $hook and $group set AND $args = [],
        // as_unschedule_all_actions falls through to a per-action
        // as_unschedule_action() loop that requires exact $args match,
        // and our actions were enqueued with array( $run_id ) which we
        // don't try to mirror here. The group is run-specific
        // ('pllat-run-<id>') so only this run's process_run actions get
        // cancelled — pipeline-tick actions live in 'pllat-pipeline' and
        // are unaffected.
        if ( \function_exists( 'as_unschedule_all_actions' ) ) {
            \as_unschedule_all_actions( '', array(), 'pllat-run-' . $run_id );
        }
    }
}
