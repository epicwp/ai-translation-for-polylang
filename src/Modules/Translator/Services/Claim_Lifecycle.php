<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns the state transitions of a single `pllat_claims` row + the run
 * heartbeat. Pulled out of Lean_Job_Worker so the worker loop only
 * orchestrates: claim, translate, write_back, hand off here.
 *
 * Stateless — every method takes the IDs it needs.
 */
class Claim_Lifecycle {
    /**
     * Retryable-failure ceiling. After this many attempts a claim
     * transitions to 'failed' (terminal) and the claim service will
     * not re-surface it.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Delete the claim row on successful completion. The worker calls
     * this once a unit's translation has been written back.
     */
    public function complete( int $job_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'pllat_claims', array( 'id' => $job_id ), array( '%d' ) );
    }

    /**
     * Persist batch_state on the claim row and reset status to 'pending'
     * so the next claim resumes from where this batch left off.
     *
     * Does NOT increment attempts — batch continuation is normal flow,
     * not failure.
     *
     * @param array<string, mixed> $batch_state
     */
    public function persist_batch_state( int $job_id, array $batch_state ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_claims',
            array(
                'batch_state' => (string) \wp_json_encode( $batch_state ),
                'status'      => 'pending',
            ),
            array( 'id' => $job_id ),
            array( '%s', '%s' ),
            array( '%d' ),
        );
    }

    /**
     * Release the claim back to 'pending' WITHOUT incrementing attempts.
     *
     * For conditions that are not the unit's fault (circuit breaker open,
     * provider rate-limited): the recurring pipeline tick will re-claim it
     * once the provider recovers. Distinct from record_failure(), which
     * consumes a retry attempt and writes the error log.
     */
    public function release_for_retry( int $job_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_claims',
            array( 'status' => 'pending' ),
            array( 'id' => $job_id ),
            array( '%s' ),
            array( '%d' ),
        );
    }

    /**
     * Record a translation failure. Increments attempts and sets status
     * to 'pending' (retryable) or 'failed' (terminal at MAX_ATTEMPTS).
     */
    public function record_failure( int $job_id, string $issue ): void {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT attempts FROM {$claims_table} WHERE id = %d", $job_id ),
            \ARRAY_A,
        );
        if ( null === $row ) {
            return;
        }

        $next   = (int) $row['attempts'] + 1;
        $status = $next >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

        $wpdb->update(
            $claims_table,
            array(
                'attempts' => $next,
                'issue'    => $issue,
                'status'   => $status,
            ),
            array( 'id' => $job_id ),
            array( '%d', '%s', '%s' ),
            array( '%d' ),
        );

        // Surface the failure to the file-based error log too. Activity feed
        // shows it for the user; the error log file gives support engineers a
        // greppable artifact they can pull via /pllat/v1/logs/download.
        \do_action(
            'pllat_log_error',
            \sprintf(
                'Translation failure (claim #%d, attempt %d/%d, status=%s): %s',
                $job_id,
                $next,
                self::MAX_ATTEMPTS,
                $status,
                $issue,
            ),
            array(
                'attempts' => $next,
                'claim_id' => $job_id,
                'status'   => $status,
            ),
        );
    }

    /**
     * Record a permanent failure: the claim goes straight to 'failed'
     * (terminal) regardless of the attempts counter.
     *
     * For provider conditions that cannot succeed on retry — e.g. the
     * provider account is out of credits (issue #461). Burning the normal
     * MAX_ATTEMPTS retries would only re-hit the same rejected API call.
     */
    public function record_terminal_failure( int $job_id, string $issue ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_claims',
            array(
                'issue'  => $issue,
                'status' => 'failed',
            ),
            array( 'id' => $job_id ),
            array( '%s', '%s' ),
            array( '%d' ),
        );

        \do_action(
            'pllat_log_error',
            \sprintf(
                'Terminal translation failure (claim #%d): %s',
                $job_id,
                $issue,
            ),
            array(
                'claim_id' => $job_id,
                'status'   => 'failed',
            ),
        );
    }

    /**
     * Refresh the run's last_heartbeat so Pipeline_Tick_Handler does not
     * abandon it as stale. Called once per worker invocation, NOT per
     * claim — see attempt_insert docblock on Job_Claim_Service for why
     * the claim's own last_heartbeat does not need mid-claim refresh.
     */
    public function touch_run_heartbeat( int $run_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'pllat_bulk_runs',
            array( 'last_heartbeat' => \time() ),
            array( 'id' => $run_id ),
            array( '%d' ),
            array( '%d' ),
        );
    }

    /**
     * Whether the worker should exit because the user cancelled the run.
     */
    public function is_run_cancelled( int $run_id ): bool {
        return 'cancelled' === $this->get_run_status( $run_id );
    }

    /**
     * Worker exit gate: true when the run reached a state from which no new
     * claim should be created.
     *
     * Tighter than is_run_cancelled — also covers the race where another
     * worker / the pipeline tick marked the run 'completed' between this
     * worker's previous loop iteration and the next claim_next_unit call.
     * Without this gate, the sibling worker would INSERT a fresh claim for
     * a run that is already done, leaving an orphan in_progress row that
     * Pipeline_Tick_Handler will never abandon (it only sweeps 'processing'
     * runs).
     *
     * Unknown/missing status (e.g. integration tests that don't seed a row)
     * is treated as "not terminal" so the worker keeps going — that matches
     * the prior is_run_cancelled semantics.
     */
    public function is_run_terminal( int $run_id ): bool {
        $status = $this->get_run_status( $run_id );
        return \in_array( $status, array( 'cancelled', 'completed', 'abandoned', 'failed' ), true );
    }

    private function get_run_status( int $run_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}pllat_bulk_runs WHERE id = %d",
                $run_id,
            ),
        );
    }
}
