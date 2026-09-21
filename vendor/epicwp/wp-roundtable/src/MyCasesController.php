<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Rest\Gate;

/** REST proxy for the Started tab: `GET /roundtable/v1/cases/mine`. */
final class MyCasesController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/cases/mine';

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
                'methods'             => 'GET',
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
     * List the current subject's cases from the hub.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The cases array, or a structured error.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; REST callback signature.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        try {
            $cases = $this->hubClient->listMyCases();
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'cases' => $cases ), 200 );
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
