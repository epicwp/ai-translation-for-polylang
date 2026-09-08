<?php
declare(strict_types=1);

namespace PLLAT\Logs\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use PLLAT\Debug\Services\Error_Logger_Service;
use PLLAT\Settings\Services\Settings_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

/**
 * REST API controller for translation log file operations.
 * Provides endpoints for downloading log files.
 *
 * Note: Log viewing in the dashboard is handled by the Activity module.
 * This controller only handles file-level operations.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'logs' )]
class Logs_REST_Controller extends \XWP_REST_Controller {
    /**
     * Constructor.
     *
     * @param Error_Logger_Service $error_logger     The error logger service.
     * @param Debug_Logger_Service $debug_logger     The debug logger service.
     * @param Settings_Service     $settings_service The settings service.
     */
    public function __construct(
        private Error_Logger_Service $error_logger,
        private Debug_Logger_Service $debug_logger,
        private Settings_Service $settings_service,
    ) {
    }

    /**
     * Download a log file.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The request object.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'download', methods: 'GET', guard: 'check_permission' )]
    public function download_log( \WP_REST_Request $request ): \WP_REST_Response {
        $source = $request->get_param( 'source' ) ?: 'error';
        $date   = $request->get_param( 'date' ) ?: \gmdate( 'Y-m-d' );

        // Check debug mode for debug logs.
        if ( 'debug' === $source && ! $this->settings_service->is_debug_mode() ) {
            return new \WP_REST_Response(
                array(
                    'error'   => 'Debug mode is not enabled',
                    'success' => false,
                ),
                403,
            );
        }

        // Get file content based on source.
        $content  = null;
        $filename = '';

        switch ( $source ) {
            case 'error':
                $content  = $this->error_logger->get_log_content( $date );
                $filename = "error-{$date}.log";
                break;
            case 'debug':
                $log_file = $this->debug_logger->get_log_file_path( $date );
                if ( \file_exists( $log_file ) ) {
                    $content  = \file_get_contents( $log_file );
                    $filename = "debug-{$date}.log";
                }
                break;
            default:
                return new \WP_REST_Response(
                    array(
                        'error'   => 'Invalid log source',
                        'success' => false,
                    ),
                    400,
                );
        }

        if ( null === $content || false === $content ) {
            return new \WP_REST_Response(
                array(
                    'error'   => 'Log file not found',
                    'success' => false,
                ),
                404,
            );
        }

        return new \WP_REST_Response(
            array(
                'content'  => $content,
                'filename' => $filename,
                'success'  => true,
            ),
        );
    }

    /**
     * Check if the user has permission to view logs.
     *
     * @return bool True if the user has permission.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }

}

