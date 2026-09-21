<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * The nonce-, capability- and licence-gated REST route that resolves the current user's
 * stored chat history.
 *
 * Registers `GET /roundtable/v1/history`. Its permission callback enforces the same three
 * gates as {@see MessageController}; `handle` resolves the user's session and returns the
 * hub's stored events for it — or an empty list, without calling the hub, when the session
 * hasn't been primed by a first turn yet.
 */
final class HistoryController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/history';

    /**
     * Creates the controller.
     *
     * @param Config    $config    The SDK configuration (licence gate lives on `$config->consumer`).
     * @param HubClient $hubClient The client used to fetch the chat's stored history.
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
     * Gate a request: valid REST nonce, the required capability, and a valid licence.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return bool True to proceed; false to refuse (403) before any hub call.
     */
    public function permission( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): bool {
        return \EpicWP\Roundtable\Rest\Gate::permits( $this->config, $request );
    }

    /**
     * Return the current user's chat history.
     *
     * Resolves the session from the current user; if it hasn't been primed by a first turn
     * yet, returns an empty events list without calling the hub.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The events payload, or a structured error.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; REST callback signature.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $session = new Session( (int) \wp_get_current_user()->ID );
        if ( ! $session->isPrimed() ) {
            return new \WP_REST_Response( array( 'events' => array() ), 200 );
        }

        try {
            $events = $this->hubClient->getHistory( $session->chatId() );
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'events' => $events ), 200 );
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
