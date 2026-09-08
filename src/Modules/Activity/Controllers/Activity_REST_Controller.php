<?php
declare(strict_types=1);

namespace PLLAT\Activity\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Activity\Services\Activity_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST API controller for translation activity.
 * Backed by lean tables: field_state (done events) and jobs (active claims).
 * No dependency on Activity_Log, Job model, Task model, or Run model.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'activity' )]
class Activity_REST_Controller extends \XWP_REST_Controller {

    /**
     * Constructor.
     *
     * @param Activity_Service $activity_service The lean activity service.
     */
    public function __construct(
        private Activity_Service $activity_service,
    ) {
    }

    /**
     * Get paginated activity items with optional filtering.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The request object.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: '', methods: 'GET', guard: 'check_permission' )]
    public function get_activity( \WP_REST_Request $request ): \WP_REST_Response {
        $date     = $request->get_param( 'date' ) ?: \gmdate( 'Y-m-d' );
        $page     = \max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = \min( 100, \max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
        $status   = $request->get_param( 'status' ) ?: 'all';

        if ( ! \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new \WP_REST_Response(
                array( 'message' => \__( 'Invalid date format.', 'ai-translation-for-polylang' ) ),
                400,
            );
        }

        $allowed_statuses = array( 'all', 'active', 'done', 'errors' );

        if ( ! \in_array( $status, $allowed_statuses, true ) ) {
            return new \WP_REST_Response(
                array( 'message' => \__( 'Invalid status filter.', 'ai-translation-for-polylang' ) ),
                400,
            );
        }

        $result = $this->activity_service->get_activity( $date, $page, $per_page, $status );

        return new \WP_REST_Response( $result );
    }

    /**
     * Get available dates that have field_state activity.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The request object.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'dates', methods: 'GET', guard: 'check_permission' )]
    public function get_dates( \WP_REST_Request $request ): \WP_REST_Response {
        $dates = $this->activity_service->get_available_dates();

        return new \WP_REST_Response( array( 'dates' => $dates ) );
    }

    /**
     * Check if the user has permission to view activity.
     *
     * @return bool True if the user has permission.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }
}
