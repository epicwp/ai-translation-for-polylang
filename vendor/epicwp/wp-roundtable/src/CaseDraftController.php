<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Rest\Gate;

/** REST proxy to draft a Case from the current chat: `POST /roundtable/v1/case`. */
final class CaseDraftController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/case';

    /**
     * Creates the controller.
     *
     * @param Config    $config    The SDK configuration.
     * @param HubClient $hubClient The hub client.
     */
    public function __construct(
        private Config $config,
        private HubClient $hubClient,
    ) {
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
     * Draft a Case from the posted conversation transcript.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The drafted case, or a structured error.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $conversation = \trim( (string) $request->get_param( 'conversation' ) );
        if ( '' === $conversation ) {
            return new \WP_REST_Response( array( 'error' => array( 'kind' => 'invalid_request' ) ), 400 );
        }

        $session = new Session( (int) \wp_get_current_user()->ID );

        try {
            $case = $this->hubClient->createCase(
                $session->chatId(),
                $conversation,
                $this->draftOverridesFrom( $request ),
            );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'case' => $case ), 200 );
    }

    /**
     * Read optional draft overrides from the REST request.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return array<string, mixed> Optional `title`, `summary`, `type`.
     */
    private function draftOverridesFrom( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): array {
        $overrides = array();
        foreach ( array( 'title', 'summary', 'type' ) as $key ) {
            $value = $request->get_param( $key );
            if ( null === $value || '' === (string) $value ) {
                continue;
            }

            $overrides[ $key ] = $value;
        }
        return $overrides;
    }

    /**
     * Maps a hub exception's kind to the HTTP status the response should carry.
     *
     * @param \EpicWP\Roundtable\HubException $error The caught exception.
     *
     * @return int The HTTP status code.
     */
    private function statusFor( \EpicWP\Roundtable\HubException $error ): int {
        return match ( $error->kind() ) {
            \EpicWP\Roundtable\HubException::BLOCKED => 403,
            \EpicWP\Roundtable\HubException::OVER_QUOTA => 429,
            default => 502,
        };
    }
}
