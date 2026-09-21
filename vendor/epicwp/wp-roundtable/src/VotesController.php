<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Rest\Gate;

/** REST proxy for case votes: `GET|POST|DELETE /roundtable/v1/cases/{case_id}/votes`. */
final class VotesController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/cases/(?P<case_id>[a-zA-Z0-9_-]+)/votes';

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
                'callback'            => array( $this, 'handleGet' ),
                'methods'             => 'GET',
                'permission_callback' => array( $this, 'permission' ),
            ),
        );
        \register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            array(
                'callback'            => array( $this, 'handleCast' ),
                'methods'             => 'POST',
                'permission_callback' => array( $this, 'permission' ),
            ),
        );
        \register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            array(
                'callback'            => array( $this, 'handleRetract' ),
                'methods'             => 'DELETE',
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
     * Fetch the vote tally for a public case.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The tally, or a structured error.
     */
    public function handleGet( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; REST callback signature.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $caseId = (string) $request->get_param( 'case_id' );
        if ( '' === $caseId ) {
            return new \WP_REST_Response( array( 'error' => array( 'kind' => 'bad_request' ) ), 400 );
        }

        try {
            $tally = $this->hubClient->getVoteTally( $caseId );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'tally' => $tally ), 200 );
    }

    /**
     * Cast or change a vote on a public case.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The tally, or a structured error.
     */
    public function handleCast( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $caseId = (string) $request->get_param( 'case_id' );
        $value  = (int) $request->get_param( 'value' );
        if ( '' === $caseId || ( 1 !== $value && -1 !== $value ) ) {
            return new \WP_REST_Response( array( 'error' => array( 'kind' => 'invalid_request' ) ), 400 );
        }

        try {
            $tally = $this->hubClient->castVote( $caseId, $value );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'tally' => $tally ), 200 );
    }

    /**
     * Retract the current subject's vote on a public case.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The tally, or a structured error.
     */
    public function handleRetract( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; REST callback signature.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $caseId = (string) $request->get_param( 'case_id' );
        if ( '' === $caseId ) {
            return new \WP_REST_Response( array( 'error' => array( 'kind' => 'bad_request' ) ), 400 );
        }

        try {
            $tally = $this->hubClient->retractVote( $caseId );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'tally' => $tally ), 200 );
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
            \EpicWP\Roundtable\HubException::LICENCE_INVALID => 402,
            \EpicWP\Roundtable\HubException::BLOCKED => 403,
            \EpicWP\Roundtable\HubException::OVER_QUOTA => 429,
            default => 502,
        };
    }
}
