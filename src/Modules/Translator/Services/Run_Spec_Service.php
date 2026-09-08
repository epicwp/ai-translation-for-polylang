<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Handlers\Pipeline_Tick_Handler;
use PLLAT\Translator\Models\Run_Spec;

/**
 * Creates and reads runs from runs.spec JSON.
 *
 * The lean pipeline's only path for run creation. Bulk REST controllers
 * and the single-translator REST controller both call create_run with a
 * Run_Spec. The service does NOT pre-allocate jobs (lazy claim via
 * Job_Claim_Service, Phase 3.4).
 */
class Run_Spec_Service {

    public function create_run( Run_Spec $spec ): int {
        global $wpdb;
        $now = \time();
        $wpdb->insert(
            $wpdb->prefix . 'pllat_bulk_runs',
            array(
                'status'         => 'processing',
                'spec'           => $spec->to_json(),
                'created_at'     => $now,
                'started_at'     => $now,
                'completed_at'   => 0,
                'last_heartbeat' => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%d' ),
        );
        $run_id = (int) $wpdb->insert_id;

        // Immediately schedule one initial worker so the first claim fires on
        // the next cron tick (~seconds) rather than waiting up to 60 s for the
        // pipeline tick. Steady-state concurrency is governed solely by
        // Pipeline_Tick_Handler (CONCURRENCY_TARGET = one unit at a time); the
        // initial enqueue is just a latency optimisation.
        Pipeline_Tick_Handler::enqueue_worker( $run_id );

        return $run_id;
    }

    public function get_spec( int $run_id ): ?Run_Spec {
        global $wpdb;
        $json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT spec FROM {$wpdb->prefix}pllat_bulk_runs WHERE id = %d",
                $run_id,
            ),
        );
        if ( null === $json || '' === $json ) {
            return null;
        }
        return Run_Spec::from_json( $json );
    }
}
