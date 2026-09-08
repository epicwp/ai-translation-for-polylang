<?php
/**
 * System_Report_REST_Controller file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Controllers
 */

declare(strict_types=1);

namespace PLLAT\Status\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Services\System_Report_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * Exposes the read-only diagnostic blob at GET /pllat/v1/system-report.
 *
 * Reuses the same `manage_options` guard as the preflight controller, so a
 * Support Access session (temporary administrator + Application Password) can
 * pull it remotely — and every pull is automatically captured by the existing
 * Support Access audit trail. The endpoint performs no writes, no billable
 * calls, and no outbound HTTP.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'system-report' )]
class System_Report_REST_Controller extends \XWP_REST_Controller {

    public function __construct(
        private System_Report_Service $report,
    ) {}

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request The request object.
     */
    #[REST_Route( route: '', methods: 'GET', guard: 'check_admin_access' )]
    public function get_report( \WP_REST_Request $request ): \WP_REST_Response {
        // ?include=coverage[,...] opts into heavier, scan-based sections that
        // are left out of the default report to keep it fast on large sites.
        $include  = (string) ( $request->get_param( 'include' ) ?? '' );
        $includes = \array_filter( \array_map( 'trim', \explode( ',', $include ) ) );

        return new \WP_REST_Response( $this->report->generate( $includes ) );
    }

    public function check_admin_access(): bool|\WP_Error {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                \__( 'Admin access required.', 'ai-translation-for-polylang' ),
                array( 'status' => 403 ),
            );
        }
        return true;
    }
}
