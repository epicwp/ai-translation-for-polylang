<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Rest\Gate;

/** REST route that starts a fresh chat: `POST /roundtable/v1/reset` clears the user's session. */
final class SessionController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/reset';

    /**
     * Creates the controller.
     *
     * @param Config $config The SDK configuration (licence gate lives on `$config->consumer`).
     */
    public function __construct( private Config $config ) {
    }

    /** Register the REST route. Call on the `rest_api_init` action. */
    public function register(): void {
        \register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            array(
                'callback'            => array( $this, 'handle' ),
                'methods'             => 'POST',
                'permission_callback' => array( $this, 'permission' ),
            ),
        );
    }

    /**
     * Gate the request with the shared nonce+cap+licence gate.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return bool True to proceed; false to refuse (403).
     */
    public function permission( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): bool {
        return Gate::permits( $this->config, $request );
    }

    /**
     * Reset the current user's chat session.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request (unused).
     *
     * @return \WP_REST_Response A `{ "ok": true }` acknowledgement.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        unset( $request );
        ( new Session( (int) \wp_get_current_user()->ID ) )->reset();
        return new \WP_REST_Response( array( 'ok' => true ), 200 );
    }
}
