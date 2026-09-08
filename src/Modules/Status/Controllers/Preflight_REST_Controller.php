<?php
/**
 * Preflight_REST_Controller file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Controllers
 */

declare(strict_types=1);

namespace PLLAT\Status\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Preflight\Preflight_Scope;
use PLLAT\Status\Preflight\Preflight_Service;
use PLLAT\Status\Preflight\Provider_Connectivity_Tester;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * Exposes the aggregated preflight result at POST /pllat/v1/preflight/check.
 *
 * Passing `fresh=true` forces a fresh billable connectivity test; otherwise
 * the endpoint reads the cached option and makes zero external calls.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'preflight' )]
class Preflight_REST_Controller extends \XWP_REST_Controller {

    public function __construct(
        private Preflight_Service $preflight,
        private Provider_Connectivity_Tester $connectivity_tester,
    ) {}

    #[REST_Route( route: 'check', methods: 'POST', guard: 'check_admin_access' )]
    public function check( \WP_REST_Request $request ): \WP_REST_Response {
        $scope_str = (string) $request->get_param( 'scope' );
        $fresh     = (bool) $request->get_param( 'fresh' );
        $context   = (array) ( $request->get_param( 'context' ) ?? array() );

        $scope = Preflight_Scope::tryFrom( $scope_str ) ?? Preflight_Scope::Run_Start;

        if ( $fresh ) {
            $this->connectivity_tester->test_active_provider();
        }

        $result = $this->preflight->check( $scope, $context );
        return new \WP_REST_Response( $result->to_array() );
    }

    #[REST_Route( route: 'test-connection', methods: 'POST', guard: 'check_admin_access' )]
    public function test_connection(): \WP_REST_Response {
        $result = $this->connectivity_tester->test_active_provider();
        return new \WP_REST_Response( $result );
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
