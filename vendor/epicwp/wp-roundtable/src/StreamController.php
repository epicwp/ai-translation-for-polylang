<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * The streaming counterpart to {@see MessageController}: pumps hub SSE frames to the browser.
 *
 * Registers `POST /roundtable/v1/message/stream`, gated by the shared {@see Rest\Gate} (the
 * same nonce + capability + licence checks as the buffered endpoint). `handle` takes over the
 * REST response via `rest_pre_serve_request`: it sends `text/event-stream` headers, drains
 * WordPress's output buffers, then streams each hub event to the client as an SSE `data:` frame
 * with an explicit {@see flush()}. The session is marked primed only after the turn completes
 * without throwing, so a failed first turn re-sends its context on the next attempt.
 */
final class StreamController {
    /** The REST route namespace. */
    public const ROUTE_NAMESPACE = 'roundtable/v1';
    /** The REST route path (under the namespace). */
    public const ROUTE = '/message/stream';

    /**
     * Creates the controller.
     *
     * @param Config                  $config    The SDK configuration (licence gate on `$config->consumer`).
     * @param HubClient               $hubClient The client used to stream a chat turn.
     * @param Http\StreamingTransport $transport The streaming transport used to reach the hub.
     */
    public function __construct(
        private Config $config,
        private HubClient $hubClient,
        private Http\StreamingTransport $transport,
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
     * Take over the REST response and stream the turn as `text/event-stream`.
     *
     * Hooks `rest_pre_serve_request` so the body is emitted by this handler rather than
     * serialized by the REST server. The filter closure is thin transport glue (headers,
     * buffer drain, per-frame echo + flush); the security-critical turn logic lives in
     * {@see self::streamTurn()}.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return \WP_REST_Response A 200 response; its body is streamed by the pre-serve filter.
     */
    public function handle( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        \WP_REST_Request $request,
    ): \WP_REST_Response {
        $message     = (string) $request->get_param( 'message' );
        $trigger     = $this->triggerFrom( $request );
        $session     = new Session( (int) \wp_get_current_user()->ID );
        $chatId      = $session->chatId();
        $isFirstTurn = ! $session->isPrimed();

        \add_filter(
            'rest_pre_serve_request',
            function () use ( $session, $chatId, $message, $isFirstTurn, $trigger ): bool {
                \header( 'Content-Type: text/event-stream' );
                \header( 'Cache-Control: no-cache' );
                \header( 'X-Accel-Buffering: no' );
                while ( \ob_get_level() > 0 ) {
                    \ob_end_flush();
                }
                $this->streamTurn(
                    $session,
                    $chatId,
                    $message,
                    $isFirstTurn,
                    static function ( array $payload ): void {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an SSE `data:` frame, not HTML; `wp_json_encode()` already produces safe JSON.
                        echo 'data: ' . \wp_json_encode( $payload ) . "\n\n";
                        \flush();
                    },
                    $trigger,
                );
                return true;
            },
        );

        return new \WP_REST_Response( null, 200 );
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
     * Run one streaming turn, invoking `$write` once per SSE payload.
     *
     * Streams the turn through {@see HubClient::streamMessage}; each hub {@see Event} becomes a
     * payload array (`type` plus the event's data) handed to `$write`. The session is marked
     * primed ONLY after `streamMessage` returns without throwing — a first-turn failure leaves it
     * un-primed so the next turn re-sends its context. A {@see HubException} is reported as a
     * single `error` payload rather than aborting the response mid-frame.
     *
     * @param Session                              $session     The user's chat session.
     * @param string                               $chatId      The chat/session id.
     * @param string                               $message     The user's message.
     * @param bool                                 $isFirstTurn Whether this is the chat's first turn.
     * @param callable(array<string, mixed>): void $write       Sink for each SSE payload array.
     * @param string|null                          $trigger     E12: `"create_topic"` when this turn is the
     *                                                           user's "Create topic" click; null otherwise.
     */
    private function streamTurn( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `callable(array<string, mixed>): void` is a PHPStan callable-signature; the native param type stays `callable`.
        Session $session,
        string $chatId,
        string $message,
        bool $isFirstTurn,
        callable $write,
        ?string $trigger = null,
    ): void {
        try {
            $this->hubClient->streamMessage(
                $chatId,
                $message,
                $isFirstTurn,
                $this->transport,
                static function ( Event $event ) use ( $write ): void {
                    $write( \array_merge( array( 'type' => $event->type ), $event->data ) );
                },
                $trigger,
            );
            if ( $isFirstTurn ) {
                $session->markPrimed();
            }
        } catch ( \EpicWP\Roundtable\HubException $e ) {
            $write( \array_merge( array( 'type' => 'error' ), array( 'message' => $e->kind() ) ) );
        }
    }
}
