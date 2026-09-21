<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Rest\Gate;

/** REST proxy for browsing public cases: `GET /roundtable/v1/cases`. */
final class CasesController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/cases';

    /**
     * Creates the controller.
     *
     * @param Config    $config    The SDK configuration (licence gate lives on `$config->consumer`).
     * @param HubClient $hubClient The client used to list cases from the hub.
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
     * List public cases from the hub.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The cases array, or a structured error.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        try {
            $cases = $this->hubClient->listCases( $this->queryFrom( $request ) );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'cases' => $cases ), 200 );
    }

    /**
     * Sanitize hub query params from the REST request.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return array<string, int|string> The hub query params.
     */
    private function queryFrom( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): array {
        $query = array();
        $type  = (string) $request->get_param( 'type' );
        if ( \in_array( $type, array( 'question', 'bug', 'feature_request' ), true ) ) {
            $query['type'] = $type;
        }
        $q = \trim( (string) $request->get_param( 'q' ) );
        if ( '' !== $q ) {
            $query['q'] = $q;
        }
        $sort = (string) $request->get_param( 'sort' );
        if ( \in_array( $sort, array( 'newest', 'top', 'trending' ), true ) ) {
            $query['sort'] = $sort;
        }
        $limit = (int) $request->get_param( 'limit' );
        if ( $limit > 0 ) {
            $query['limit'] = \min( 100, $limit );
        }
        $offset = (int) $request->get_param( 'offset' );
        if ( $offset > 0 ) {
            $query['offset'] = $offset;
        }
        return $query;
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
