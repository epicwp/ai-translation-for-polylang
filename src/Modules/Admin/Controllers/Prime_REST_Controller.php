<?php
declare(strict_types=1);

namespace PLLAT\Admin\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Services\Prime_Status_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST API controller for prime status and on-demand priming.
 *
 * Prime is the one-time walk of Polylang's translation map that fills
 * pllat_translation_index + pllat_translation_field_state for existing
 * translation pairs, so pre-existing customers benefit from smart
 * edit-detection from day one.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'admin' )]
class Prime_REST_Controller extends \XWP_REST_Controller {

    public function __construct(
        protected Prime_Status_Service $status,
    ) {}

    #[REST_Route( route: 'prime/status', methods: 'GET', guard: 'check_permission' )]
    public function status(): \WP_REST_Response {
        return new \WP_REST_Response( array(
            'primed'   => $this->status->is_primed(),
            'progress' => $this->status->progress(),
        ) );
    }

    /**
     * Schedule an async prime walk and return immediately.
     *
     * Delegates to Prime_Status_Service::schedule_prime() which cancels any
     * in-flight prime action, resets the primed flag and progress counter,
     * then enqueues a fresh async Action Scheduler job. The frontend polls
     * /admin/prime/status until the background job finishes.
     *
     * The synchronous prime_now() method remains on Prime_Status_Service as
     * the future entry point for WP-CLI.
     *
     * @return \WP_REST_Response Response with `{scheduled: true}` body on success,
     *                           or 500 with `pllat_prime_enqueue_failed` code on failure.
     */
    #[REST_Route( route: 'prime/run', methods: 'POST', guard: 'check_permission' )]
    public function run_now(): \WP_REST_Response {
        $scheduled = $this->status->schedule_prime();
        if ( ! $scheduled ) {
            return new \WP_REST_Response(
                array(
                    'code'    => 'pllat_prime_enqueue_failed',
                    'message' => \__( 'Could not schedule the priming background job. Please try again or check Action Scheduler.', 'ai-translation-for-polylang' ),
                    'success' => false,
                ),
                500,
            );
        }
        return new \WP_REST_Response( array( 'scheduled' => true ) );
    }

    /**
     * Permission guard for the prime endpoints.
     *
     * Every REST_Route here declares guard: 'check_permission'; XWP binds
     * that to this method. Without it the guard string is treated as a
     * bare global callback and the endpoint fatals (500) on every call —
     * which is why the prime status never reached the dashboard.
     *
     * @return bool True if the current user may manage translations.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }
}
