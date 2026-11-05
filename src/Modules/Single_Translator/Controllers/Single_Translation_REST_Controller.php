<?php
/**
 * Single_Translation_REST_Controller class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Single_Translator\Services\Single_Translation_Service;
use PLLAT\Single_Translator\Services\Single_Translation_Start_Service;
use PLLAT\Translator\Exceptions\Translation_Refused_Exception;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST controller for single item translation operations.
 *
 * Provides endpoints for translating individual posts and terms
 * from their edit pages.
 *
 * Status comes from Translation_Index_Repository via Single_Translation_Service.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'single-translator' )]
class Single_Translation_REST_Controller extends \XWP_REST_Controller {
    /**
     * Constructor.
     *
     * @param Single_Translation_Service       $translation_service The single translation service.
     * @param Single_Translation_Start_Service $start_service       The start service.
     */
    public function __construct(
        protected Single_Translation_Service $translation_service,
        protected Single_Translation_Start_Service $start_service,
    ) {
    }

    /**
     * Permission check for endpoints (must be able to edit the content).
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function get_status_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for translate endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function translate_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for exclusion endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function set_exclusion_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for cancel endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function cancel_translation_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Get translation status for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'status/(?P<type>post|term)/(?P<id>\d+)', methods: 'GET', guard: 'get_status_permissions_check' )]
    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        try {
            $status = $this->translation_service->get_translation_status( $type, $id );

            return $this->success_response( $status );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 500 );
        }
    }

    /**
     * Start translation for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'translate/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'translate_permissions_check' )]
    public function translate( \WP_REST_Request $request ): \WP_REST_Response {
        $type                  = $request->get_param( 'type' );
        $id                    = (int) $request->get_param( 'id' );
        $target_languages      = $request->get_param( 'target_languages' );
        $force                 = (bool) $request->get_param( 'force' );
        $selected_fields       = $request->get_param( 'selected_fields' );
        $acknowledge_preflight = (bool) $request->get_param( 'acknowledge_preflight' );
        $instructions          = (string) ( $request->get_param( 'instructions' ) ?? '' );

        if ( ! \is_array( $selected_fields ) ) {
            $selected_fields = array();
        }

        // Validate target languages.
        if ( ! \is_array( $target_languages ) || 0 === \count( $target_languages ) ) {
            return $this->error_response( \__( 'Target languages are required.', 'epicwp-ai-translation-for-polylang' ), 400 );
        }

        try {
            $run_id = $this->start_service->start( $type, $id, $target_languages, $force, $selected_fields, $instructions, $acknowledge_preflight );

            return $this->success_response(
                array(
                    'message'  => \__(
                        'Translation started successfully.',
                        'epicwp-ai-translation-for-polylang',
                    ),
                    'run_id'   => $run_id,
                    'trace_id' => null,
                ),
            );
        } catch ( Translation_Refused_Exception $e ) {
            return new \WP_REST_Response(
                array(
                    'code'    => $e->code(),
                    'message' => $e->getMessage(),
                    'success' => false,
                ) + $e->data(),
                $e->http_status(),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 400 );
        }
    }

    /**
     * Set exclusion status for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'exclusion/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'set_exclusion_permissions_check' )]
    public function set_exclusion( \WP_REST_Request $request ): \WP_REST_Response {
        $type     = $request->get_param( 'type' );
        $id       = (int) $request->get_param( 'id' );
        // filter_var with FILTER_VALIDATE_BOOLEAN handles the 'false' string
        // correctly (returns false), unlike PHP's (bool) cast which coerces
        // any non-empty string to true. Avoids the rest_sanitize_boolean
        // PHPStan stub template-inference issue.
        $excluded = \filter_var( $request->get_param( 'excluded' ), \FILTER_VALIDATE_BOOLEAN );

        try {
            $this->translation_service->set_exclusion( $type, $id, $excluded );

            return $this->success_response(
                array(
                    'message' => $excluded
                        ? \__( 'Content excluded from AI translation.', 'epicwp-ai-translation-for-polylang' )
                        : \__( 'Content included in AI translation.', 'epicwp-ai-translation-for-polylang' ),
                ),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 500 );
        }
    }

    /**
     * Cancel active translation for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'cancel/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'cancel_translation_permissions_check' )]
    public function cancel_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        try {
            $run_id = $this->translation_service->cancel_active_translation( $type, $id );

            return $this->success_response(
                array(
                    'message' => \__(
                        'Translation cancelled successfully.',
                        'epicwp-ai-translation-for-polylang',
                    ),
                    'run_id'  => $run_id,
                ),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 400 );
        }
    }

    /**
     * Check if the user can edit the content.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return bool Whether the user can edit.
     */
    private function can_edit_content( string $type, int $id ): bool {
        return Single_Translation_Service::user_can_edit( $type, $id );
    }

    /**
     * Return an error response.
     *
     * @param string $message The error message.
     * @param int    $code    The HTTP status code.
     * @return \WP_REST_Response The error response.
     */
    private function error_response( string $message, int $code ): \WP_REST_Response {
        return new \WP_REST_Response( array('message' => $message, 'success' => false), $code);
    }

    /**
     * Return a success response.
     *
     * @param array<string, mixed> $data The response data.
     */
    private function success_response( array $data ): \WP_REST_Response {
        return new \WP_REST_Response( \array_merge( array( 'success' => true ), $data ), 200 );
    }
}
