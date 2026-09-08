<?php
declare(strict_types=1);

namespace PLLAT\Admin\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Admin\Services\Dashboard_Data_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

/**
 * REST API controller for dashboard statistics.
 * Provides endpoints for real-time translation statistics updates.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'dashboard' )]
class Dashboard_REST_Controller extends \XWP_REST_Controller {
    /**
     * Constructor.
     *
     * @param Dashboard_Data_Service $dashboard_data_service The dashboard data service.
     */
    public function __construct(
        private Dashboard_Data_Service $dashboard_data_service,
    ) {
    }

    /**
     * Handle REST API request for real-time stats updates.
     *
     * @return array Dashboard data.
     */
    #[REST_Route( route: '', methods: 'GET', guard: 'check_permission' )]
    public function handle_rest_request(): \WP_REST_Response {
        $data = $this->dashboard_data_service->get_dashboard_data();
        return new \WP_REST_Response( $data );
    }

    /**
     * Check if current user has permission to access dashboard data.
     *
     * @return bool True if user has permission.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }
}
