<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * The nonce-, capability- and licence-gated REST proxy the (later) chat UI calls.
 *
 * Registers `POST /roundtable/v1/message`. Its permission callback enforces the three
 * gates before any hub call; `handle` resolves the user's session, runs one turn through
 * {@see HubClient}, and returns the events as JSON (or a structured error).
 */
final class MessageController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/message';

    /**
     * Creates the controller.
     *
     * @param Config    $config    The SDK configuration (licence gate lives on `$config->consumer`).
     * @param HubClient $hubClient The client used to run a chat turn.
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
     * Run one chat turn and return its events.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response The events payload, or a structured error.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $message = (string) $request->get_param( 'message' );
        $trigger = $this->triggerFrom( $request );
        $session = new Session( (int) \wp_get_current_user()->ID );

        try {
            $isFirstTurn = ! $session->isPrimed();
            $result      = $this->hubClient->postMessage(
                $session->chatId(),
                $message,
                $isFirstTurn,
                $trigger,
            );
            if ( $isFirstTurn ) {
                $session->markPrimed();
            }
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            return new \WP_REST_Response(
                array( 'error' => array( 'kind' => $e->kind() ) ),
                $this->statusFor( $e ),
            );
        }

        return new \WP_REST_Response( array( 'events' => $this->serialize( $result ) ), 200 );
    }

    /**
     * Read the optional `trigger` param from the request (E12: "create_topic" or absent).
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return string|null The trigger value, or null if not provided or not a non-empty string.
     */
    private function triggerFrom( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): ?string {
        $trigger = $request->get_param( 'trigger' );
        return \is_string( $trigger ) && '' !== $trigger ? $trigger : null;
    }

    /**
     * Serializes a turn's events into the REST response shape.
     *
     * @param TurnResult $result The turn's parsed events.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function serialize( TurnResult $result ): array {
        return \array_map(
            static fn( Event $e ): array => array(
                'data' => $e->data,
                'type' => $e->type,
            ),
            $result->events,
        );
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
            \EpicWP\Roundtable\HubException::CHAT_DISABLED => 403,
            \EpicWP\Roundtable\HubException::OVER_QUOTA => 429,
            default => 502,
        };
    }
}
