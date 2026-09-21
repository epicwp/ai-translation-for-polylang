<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

use EpicWP\Roundtable\Chat\SseParser;
use EpicWP\Roundtable\Http\Transport;

/**
 * Talks to the Roundtable hub over an injected {@see Transport}.
 *
 * The only unit that performs I/O to the hub. `postMessage` sends one chat turn, reads the
 * buffered SSE response, and returns typed events. HTTP error statuses and transport
 * failures become a typed {@see HubException}; the project key never appears in an error.
 */
final class HubClient {
    /** EpicWP's hosted multi-tenant hub. Overridable per {@see Config::$hubBaseUrl} for dev/staging. */
    public const HUB_URL = 'https://hub.roundtable.epicwp.com';

    /**
     * Creates the client.
     *
     * @param Config    $config    The SDK configuration (project key, consumer, hub URL override).
     * @param Transport $transport The injected transport seam used to reach the hub.
     */
    public function __construct(
        private Config $config,
        private Transport $transport,
    ) {
    }

    /**
     * Post one chat message and return the turn's parsed events.
     *
     * @param string      $chatId      The consumer-supplied chat/session id (resumes the hub session).
     * @param string      $message     The user's message (markdown; sent verbatim).
     * @param bool        $isFirstTurn Whether this is the first turn of the chat — only then are the
     *                                 health-report `metadata` and `client_version` included.
     * @param string|null $trigger     E12: `"create_topic"` when this turn is the user's
     *                                 "Create topic" click rather than an ordinary message; null
     *                                 otherwise.
     *
     * @return TurnResult The ordered events.
     *
     * @throws \EpicWP\Roundtable\HubException On a gate refusal (403/429), a server error (5xx), a
     *                  malformed response, or a network failure. The project key is never in the message.
     */
    public function postMessage( string $chatId, string $message, bool $isFirstTurn, ?string $trigger = null ): TurnResult {
        $url  = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/chats/' . $chatId . '/messages';
        $body = $this->buildBody( $message, $isFirstTurn, $trigger );

        try {
            $response = $this->transport->post(
                $url,
                array(
                    'Accept'        => 'text/event-stream',
                    'Authorization' => 'Bearer ' . $this->config->projectApiKey,
                    'Content-Type'  => 'application/json',
                ),
                $body,
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        $this->guardMessageStatus( $response->status, $response->body );

        return new TurnResult( SseParser::parse( $response->body ) );
    }

    /**
     * Stream a chat turn, invoking $onEvent per decoded hub event.
     *
     * @param string                                     $chatId      The chat/session id.
     * @param string                                     $message     The user's message.
     * @param bool                                       $isFirstTurn Whether to send first-turn context.
     * @param \EpicWP\Roundtable\Http\StreamingTransport $transport The streaming transport.
     * @param callable(\EpicWP\Roundtable\Event):void    $onEvent   Per-event callback.
     * @param string|null                                $trigger     E12: `"create_topic"` when this turn is the user's
     *                                                                "Create topic" click rather than an ordinary message; null
     *                                                                otherwise.
     *
     * @throws \EpicWP\Roundtable\HubException On a gate refusal, server error, or network failure.
     */
    public function streamMessage( string $chatId, string $message, bool $isFirstTurn, \EpicWP\Roundtable\Http\StreamingTransport $transport, callable $onEvent, ?string $trigger = null ): void {
        $url  = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/chats/' . $chatId . '/messages';
        $body = $this->buildBody( $message, $isFirstTurn, $trigger );
        $raw  = '';
        try {
            $status = $transport->stream(
                $url,
                array(
                    'Accept'        => 'text/event-stream',
                    'Authorization' => 'Bearer ' . $this->config->projectApiKey,
                    'Content-Type'  => 'application/json',
                ),
                $body,
                $this->config->timeoutSeconds,
                static function ( string $frame ) use ( $onEvent, &$raw ): void {
                    // A refused turn's JSON error body arrives through the frame
                    // callback too; keep it so guardMessageStatus can read its code.
                    $raw  .= $frame;
                    $event = \EpicWP\Roundtable\Chat\SseParser::parse( $frame );
                    foreach ( $event as $e ) {
                        $onEvent( $e );
                    }
                },
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal transport error string, never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }
        $this->guardMessageStatus( $status, $raw );
    }

    /**
     * Fetch this project's display config: agent name, project name, initial chat message.
     *
     * Fills the `GET /project/config` gap flagged in the SDK-M1 design (§9); the hub applies
     * its own default-fallback for any field the vendor left unset (E11 spec §7).
     *
     * @return array<string, mixed> The hub's ProjectConfigResponse object (`agent_name`,
     *                 `project_name`, `initial_message`).
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function getProjectConfig(): array {
        $url = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/project/config';
        return $this->getJsonObject( $url );
    }

    /**
     * Fetch a chat's stored history for rehydrate.
     *
     * @param string $chatId The consumer chat id (resumes/reads the hub chat).
     *
     * @return list<array{type: string, data: array<string, mixed>}> The events, in order.
     *
     * @throws \EpicWP\Roundtable\HubException On transport failure or a non-2xx response.
     */
    public function getHistory( string $chatId ): array {
        $url    = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/chats/' . $chatId . '/messages';
        $body   = $this->getJsonObject( $url );
        $events = $body['events'] ?? array();
        return \is_array( $events ) ? \array_values( $events ) : array();
    }

    /**
     * List public cases from the hub browse API.
     *
     * @param array<string, int|string> $query Optional hub query params: `type`, `q`, `sort`, `limit`, `offset`.
     *
     * @return list<array<string, mixed>> The hub's CaseListItem array.
     *
     * @throws \EpicWP\Roundtable\HubException On a gate refusal (403/429), a server error (5xx), a
     *                  malformed response, or a network failure. The project key is never in the message.
     */
    public function listCases( array $query = array() ): array {
        $url = $this->casesUrl( $query );

        try {
            $response = $this->transport->get(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        return $this->decodeJsonArray( $response, 'cases list was not a JSON array' );
    }

    /**
     * List cases authored by the current consumer subject (drafts + published).
     *
     * @return list<array<string, mixed>> The hub's SubjectCaseListItem array.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function listMyCases(): array {
        $subjectId = $this->config->consumer->subjectId();
        $url       = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/subjects/' . \rawurlencode(
            $subjectId,
        ) . '/cases';

        try {
            $response = $this->transport->get(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        return $this->decodeJsonArray( $response, 'my cases list was not a JSON array' );
    }

    /**
     * List public cases the current subject voted on or commented on.
     *
     * @return list<array<string, mixed>> The hub's CaseListItem array.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function listParticipatingCases(): array {
        $subjectId = $this->config->consumer->subjectId();
        $url       = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/subjects/' . \rawurlencode(
            $subjectId,
        ) . '/participating';

        try {
            $response = $this->transport->get(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        return $this->decodeJsonArray( $response, 'participating cases list was not a JSON array' );
    }

    /**
     * List comments on a public case.
     *
     * @param string $caseId The Case id.
     *
     * @return list<array<string, mixed>> The hub's CommentResponse array.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function listComments( string $caseId ): array {
        $url = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . $caseId . '/comments';

        try {
            $response = $this->transport->get(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        return $this->decodeJsonArray( $response, 'comments list was not a JSON array' );
    }

    /**
     * Post a new comment on a public case.
     *
     * @param string $caseId The Case id.
     * @param string $body   The comment body (markdown).
     *
     * @return array<string, mixed> The hub CommentResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On a licence refusal (402), a gate refusal
     *                  (403/429), a server error (5xx), a malformed response, or a network
     *                  failure. The project key is never in the message.
     */
    public function createComment( string $caseId, string $body ): array {
        $url     = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . $caseId . '/comments';
        $payload = \array_merge(
            array(
                'body'       => $body,
                'subject_id' => $this->config->consumer->subjectId(),
            ),
            $this->emailField(),
            $this->licenceField(),
        );

        return $this->postJsonObject( $url, $this->encodeJson( $payload ) );
    }

    /**
     * Cast or change a vote on a public case.
     *
     * @param string $caseId The Case id.
     * @param int    $value  `1` (up) or `-1` (down).
     *
     * @return array<string, mixed> The hub TallyResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On a licence refusal (402), a gate refusal
     *                  (403/429), a server error (5xx), a malformed response, or a network
     *                  failure. The project key is never in the message.
     */
    public function castVote( string $caseId, int $value ): array {
        $url     = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . \rawurlencode(
            $caseId,
        ) . '/votes';
        $payload = \array_merge(
            array(
                'subject_id' => $this->config->consumer->subjectId(),
                'value'      => $value,
            ),
            $this->emailField(),
            $this->licenceField(),
        );

        return $this->postJsonObject( $url, $this->encodeJson( $payload ) );
    }

    /**
     * Retract the current subject's vote on a public case.
     *
     * @param string $caseId The Case id.
     *
     * @return array<string, mixed> The hub TallyResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function retractVote( string $caseId ): array {
        $subjectId = $this->config->consumer->subjectId();
        $url       = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . \rawurlencode( $caseId )
            . '/votes?subject_id=' . \rawurlencode( $subjectId );

        return $this->deleteJsonObject( $url );
    }

    /**
     * Fetch a public case's vote tally, including the current subject's vote.
     *
     * @param string $caseId The Case id.
     *
     * @return array<string, mixed> The hub TallyResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function getVoteTally( string $caseId ): array {
        $subjectId = $this->config->consumer->subjectId();
        $url       = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . \rawurlencode( $caseId )
            . '/votes?subject_id=' . \rawurlencode( $subjectId );

        return $this->getJsonObject( $url );
    }

    /**
     * Distill a chat conversation into a private Case draft.
     *
     * @param string               $chatId       The chat/session id.
     * @param string               $conversation The transcript to summarize.
     * @param array<string, mixed> $overrides    Optional `title`, `summary`, `type`, `make_public`.
     *
     * @return array<string, mixed> The hub CaseResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function createCase( string $chatId, string $conversation, array $overrides = array() ): array {
        $url  = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/chats/' . $chatId . '/case';
        $body = $this->encodeJson(
            \array_merge(
                array(
                    'conversation' => $conversation,
                    'make_public'  => false,
                    'subject_id'   => $this->config->consumer->subjectId(),
                ),
                $this->clientVersionField(),
                $this->pickOverrides( $overrides, array( 'title', 'summary', 'type', 'make_public' ) ),
            ),
        );

        return $this->postJsonObject( $url, $body );
    }

    /**
     * Publish a private Case, with optional last-minute edits.
     *
     * @param string               $caseId    The Case id.
     * @param array<string, mixed> $overrides Optional `title`, `summary`, `type`.
     *
     * @return array<string, mixed> The hub CaseResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    public function publishCase( string $caseId, array $overrides = array() ): array {
        $url  = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/' . $caseId . '/publish';
        $body = $this->encodeJson(
            \array_merge(
                array( 'subject_id' => $this->config->consumer->subjectId() ),
                $this->pickOverrides( $overrides, array( 'title', 'summary', 'type' ) ),
            ),
        );

        return $this->postJsonObject( $url, $body );
    }

    /**
     * Submit a topic directly to the hub's moderation queue — no chat, no draft/publish
     * step. The created case lands with `visibility=pending` until a human approves it.
     *
     * @param string $type  One of `question`, `bug`, `feature_request`.
     * @param string $title The topic title (1..200 chars).
     * @param string $body  The topic body (1..10000 chars, free text).
     *
     * @return array<string, mixed> The hub CaseResponse object.
     *
     * @throws \EpicWP\Roundtable\HubException On a licence refusal (402), a gate refusal
     *                  (403/429), a server error (5xx), a malformed response, or a network
     *                  failure. The project key is never in the message.
     */
    public function submitTopic( string $type, string $title, string $body ): array {
        $url     = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases/direct';
        $payload = \array_merge(
            array(
                'body'       => $body,
                'subject_id' => $this->config->consumer->subjectId(),
                'title'      => $title,
                'type'       => $type,
            ),
            $this->emailField(),
            $this->metadataField(),
            $this->clientVersionField(),
            $this->licenceField(),
        );

        return $this->postJsonObject( $url, $this->encodeJson( $payload ) );
    }

    /**
     * Decode a hub GET response body as a JSON array.
     *
     * @param \EpicWP\Roundtable\Http\TransportResponse $response  The transport response.
     * @param string                                    $errorHint The bad-response hint.
     *
     * @return list<array<string, mixed>> The decoded array.
     *
     * @throws \EpicWP\Roundtable\HubException When the body is not a JSON array.
     */
    private function decodeJsonArray( \EpicWP\Roundtable\Http\TransportResponse $response, string $errorHint ): array {
        $this->guardStatus( $response->status );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- pure-PHP core class, no WordPress dependency by design.
        $decoded = \json_decode( $response->body, true );
        if ( ! \is_array( $decoded ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception hint, never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::badResponse( $errorHint );
        }

        return $decoded;
    }

    /**
     * Build the hub browse URL for `GET /cases`.
     *
     * @param array<string, int|string> $query Optional hub query params.
     *
     * @return string The absolute URL (with query string when params are present).
     */
    private function casesUrl( array $query ): string {
        $base   = ( $this->config->hubBaseUrl ?? self::HUB_URL ) . '/cases';
        $params = $this->casesQueryParams( $query );
        if ( array() === $params ) {
            return $base;
        }
        return $base . '?' . \http_build_query( $params );
    }

    /**
     * Keep only the hub-allowed, non-empty browse query params.
     *
     * @param array<string, int|string> $query The raw query params.
     *
     * @return array<string, int|string> The filtered params.
     */
    private function casesQueryParams( array $query ): array {
        $params = array();
        foreach ( array( 'type', 'q', 'sort', 'limit', 'offset' ) as $key ) {
            if ( ! isset( $query[ $key ] ) || '' === (string) $query[ $key ] ) {
                continue;
            }
            $params[ $key ] = $query[ $key ];
        }
        return $params;
    }

    /**
     * Guards the response status, throwing on any non-2xx result.
     *
     * @param int $status The HTTP status code returned by the transport.
     *
     * @throws \EpicWP\Roundtable\HubException When the status is not a success.
     */
    private function guardStatus( int $status ): void {
        if ( $status >= 200 && $status < 300 ) {
            return;
        }
        throw match ( $status ) {
            402 => \EpicWP\Roundtable\HubException::licenceInvalid(),
            403 => \EpicWP\Roundtable\HubException::blocked(),
            429 => \EpicWP\Roundtable\HubException::overQuota(),
            default => \EpicWP\Roundtable\HubException::server( $status ),
        };
    }

    /**
     * Guards a chat-message response status. The hub refuses a paused chat with a
     * 403 whose body carries the `chat_disabled` code — surface that as its own
     * kind so the UI can show a calm "paused" notice instead of a generic error.
     *
     * @param int    $status The HTTP status code returned by the transport.
     * @param string $body   The raw response body (the refusal's JSON error body).
     *
     * @throws \EpicWP\Roundtable\HubException When the status is not a success.
     */
    private function guardMessageStatus( int $status, string $body ): void {
        if ( 403 === $status && $this->bodyCarriesChatDisabled( $body ) ) {
            throw \EpicWP\Roundtable\HubException::chatDisabled();
        }
        $this->guardStatus( $status );
    }

    /**
     * Whether a refusal body carries the hub's `chat_disabled` code — at the top
     * level, as the FastAPI `detail` string, or nested under `detail`.
     *
     * @param string $body The raw response body.
     */
    private function bodyCarriesChatDisabled( string $body ): bool {
        $decoded = \json_decode( $body, true );
        if ( ! \is_array( $decoded ) ) {
            return false;
        }
        $detail = $decoded['detail'] ?? null;
        $code   = $decoded['code'] ?? ( \is_array( $detail ) ? ( $detail['code'] ?? null ) : $detail );
        return \EpicWP\Roundtable\HubException::CHAT_DISABLED === $code;
    }

    /**
     * POST JSON to the hub and decode a single object response.
     *
     * @param string $url  The absolute URL.
     * @param string $body The JSON-encoded request body.
     *
     * @return array<string, mixed> The decoded object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    private function postJsonObject( string $url, string $body ): array {
        try {
            $response = $this->transport->post(
                $url,
                array(
                    'Authorization' => 'Bearer ' . $this->config->projectApiKey,
                    'Content-Type'  => 'application/json',
                ),
                $body,
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        $this->guardStatus( $response->status );

        return $this->decodeJsonObject( $response->body );
    }

    /**
     * GET a hub resource and decode a single object response.
     *
     * @param string $url The absolute URL.
     *
     * @return array<string, mixed> The decoded object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    private function getJsonObject( string $url ): array {
        try {
            $response = $this->transport->get(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        $this->guardStatus( $response->status );

        return $this->decodeJsonObject( $response->body );
    }

    /**
     * DELETE a hub resource and decode a single object response.
     *
     * @param string $url The absolute URL.
     *
     * @return array<string, mixed> The decoded object.
     *
     * @throws \EpicWP\Roundtable\HubException On hub or transport failure.
     */
    private function deleteJsonObject( string $url ): array {
        try {
            $response = $this->transport->delete(
                $url,
                array( 'Authorization' => 'Bearer ' . $this->config->projectApiKey ),
                $this->config->timeoutSeconds,
            );
        } catch ( \EpicWP\Roundtable\Http\TransportException $e ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (transport error string), never rendered as HTML.
            throw \EpicWP\Roundtable\HubException::network( $e->getMessage() );
        }

        $this->guardStatus( $response->status );

        return $this->decodeJsonObject( $response->body );
    }

    /**
     * Decode a hub response body as a JSON object.
     *
     * An empty JSON object (`{}`) and an empty JSON array (`[]`) both decode to `[]` via
     * `json_decode(..., true)`, so `array_is_list()` alone can't tell them apart; the raw
     * body's first non-whitespace character disambiguates the empty case.
     *
     * @param string $body The raw response body.
     *
     * @return array<string, mixed> The decoded object.
     *
     * @throws \EpicWP\Roundtable\HubException When the body is not a JSON object.
     */
    private function decodeJsonObject( string $body ): array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- pure-PHP core class, no WordPress dependency by design.
        $decoded  = \json_decode( $body, true );
        $isObject = \is_array( $decoded )
            && ( ! \array_is_list( $decoded ) || \str_starts_with( \ltrim( $body ), '{' ) );
        if ( ! $isObject ) {
            throw \EpicWP\Roundtable\HubException::badResponse( 'hub response was not a JSON object' );
        }

        return $decoded;
    }

    /**
     * Optional `email` field from the consumer, when set.
     *
     * @return array<string, string> Zero or one keyed entry.
     */
    private function emailField(): array {
        $email = $this->config->consumer->email();
        if ( null === $email ) {
            return array();
        }
        return array( 'email' => $email );
    }

    /**
     * Optional `metadata` field from the consumer, when set.
     *
     * @return array<string, string> Zero or one keyed entry.
     */
    private function metadataField(): array {
        $metadata = $this->config->consumer->metadata();
        if ( null === $metadata ) {
            return array();
        }
        return array( 'metadata' => $metadata );
    }

    /**
     * Optional `client_version` field from the consumer, when set.
     *
     * @return array<string, string> Zero or one keyed entry.
     */
    private function clientVersionField(): array {
        $clientVersion = $this->config->consumer->clientVersion();
        if ( null === $clientVersion ) {
            return array();
        }
        return array( 'client_version' => $clientVersion );
    }

    /**
     * Pick allowed override keys from a params array.
     *
     * @param array<string, mixed> $source  The incoming overrides.
     * @param array<int, string>   $allowed The permitted keys.
     *
     * @return array<string, mixed> The filtered overrides.
     */
    private function pickOverrides( array $source, array $allowed ): array {
        $picked = array();
        foreach ( $allowed as $key ) {
            if ( ! \array_key_exists( $key, $source ) || null === $source[ $key ] || '' === $source[ $key ] ) {
                continue;
            }
            $picked[ $key ] = $source[ $key ];
        }
        return $picked;
    }

    /**
     * JSON-encode a payload for hub POST bodies.
     *
     * @param array<string, mixed> $payload The body array.
     *
     * @return string The encoded JSON.
     */
    private function encodeJson( array $payload ): string {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure-PHP core class, no WordPress dependency by design.
        return (string) \json_encode( $payload );
    }

    /**
     * Builds the JSON-encoded request body for a chat turn.
     *
     * @param string      $message     The user's message.
     * @param bool        $isFirstTurn Whether to include the first-turn `metadata`/`client_version` context.
     * @param string|null $trigger     E12: `"create_topic"` when this is the user's "Create
     *                                 topic" click; null for an ordinary message.
     *
     * @return string The JSON-encoded body.
     */
    private function buildBody( string $message, bool $isFirstTurn, ?string $trigger = null ): string {
        $payload = \array_merge(
            array(
                'message'    => $message,
                'subject_id' => $this->config->consumer->subjectId(),
            ),
            $this->triggerField( $trigger ),
            $this->licenceField(),
        );
        if ( $isFirstTurn ) {
            $metadata = $this->config->consumer->metadata();
            if ( null !== $metadata ) {
                $payload['metadata'] = $metadata;
            }
            $clientVersion = $this->config->consumer->clientVersion();
            if ( null !== $clientVersion ) {
                $payload['client_version'] = $clientVersion;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure-PHP core class, no WordPress dependency by design; wp_json_encode() is unavailable here.
        return (string) \json_encode( $payload );
    }

    /**
     * Optional `trigger` field for a chat-turn body, when set.
     *
     * @param string|null $trigger E12: `"create_topic"` when this is the user's "Create topic"
     *                             click; null for an ordinary message.
     *
     * @return array<string, string> Zero or one keyed entry.
     */
    private function triggerField( ?string $trigger ): array {
        if ( null === $trigger ) {
            return array();
        }
        return array( 'trigger' => $trigger );
    }

    /**
     * Optional `licence` field for a chat-turn body, when the consumer provides one.
     *
     * @return array<string, array{key: string, activation_id: string}> Zero or one keyed entry.
     */
    private function licenceField(): array {
        $licence = $this->config->consumer->licence();
        if ( null === $licence ) {
            return array();
        }
        return array( 'licence' => $licence );
    }
}
