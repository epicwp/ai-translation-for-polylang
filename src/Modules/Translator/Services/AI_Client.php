<?php
/**
 * AI_Client class file.
 *
 * @package Polylang AI Automatic Translation
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Logging\Event_Codes;
use PLLAT\Translator\Exceptions\Provider_Quota_Exhausted_Exception;
use PLLAT\Translator\Exceptions\Provider_Rate_Limited_Exception;
use PLLAT\Translator\Exceptions\Provider_Unavailable_Exception;
use PLLAT\Translator\Exceptions\Response_Truncated_Exception;

/**
 * Simple AI Client for OpenAI API interactions using WordPress HTTP API.
 */
class AI_Client {

    private ?Provider_Health_Service $health_service = null;
    private string $health_provider_slug = '';

    /**
     * Additional request headers (provider-specific).
     *
     * @var array
     */
    protected array $additional_headers;

    /**
     * Model-specific API parameters (e.g., reasoning effort).
     *
     * @var array
     */
    protected array $model_params;

    /**
     * Parse the JSON response content.
     *
     * - Remove ```json and ``` from the content.
     * - Handle empty strings and invalid JSON gracefully.
     *
     * @param string $content The content to parse.
     * @return array The parsed content, or empty array if invalid/empty.
     */
    public static function parse_json_response_content( string $content ): array {
        $content = \trim( $content );

        if ( '' === $content ) {
            return array();
        }

        $content = \preg_replace( '/```json\s*(.*?)\s*```/s', '$1', $content );
        $decoded = \json_decode( $content, true );

        return \is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Maximum output tokens for API response.
     *
     * @var int
     */
    protected int $max_tokens;

    /**
     * Constructor.
     *
     * @param string $api_key The API key.
     * @param string $base_url The base URL.
     * @param string $model The model.
     * @param array  $additional_headers Optional provider-specific headers.
     * @param array  $model_params Optional model-specific API parameters.
     * @param int    $max_tokens Maximum output tokens (default: 16000).
     */
    public function __construct(
        protected string $api_key,
        protected string $base_url,
        protected string $model,
        array $additional_headers = array(),
        array $model_params = array(),
        int $max_tokens = 16000,
    ) {
        $this->additional_headers = $additional_headers;
        $this->model_params       = $model_params;
        $this->max_tokens         = $max_tokens;
    }

    /**
     * Execute chat completion with optional tool calling.
     *
     * @param array $messages          Chat messages.
     * @param array $additional_params Additional parameters for the chat completion.
     *                                 - temperature: The temperature for the chat completion.
     *                                 - response_format: The response format for the chat completion.
     *                                 - tools: Array of tool definitions for function calling.
     *                                 - tool_choice: Tool choice strategy ('auto', 'none', 'required', or specific function).
     * @return array Response data.
     * @throws \Exception If the API request fails.
     */
    public function chat_completion( array $messages, array $additional_params = array() ): array {
        // Refuse the call when the circuit breaker is open. Carries the
        // breaker's actual remaining cooldown so Lean_Job_Worker can
        // reschedule for exactly when the breaker is set to close, instead
        // of polling on a fixed interval.
        if ( null !== $this->health_service && '' !== $this->health_provider_slug
            && $this->health_service->is_open( $this->health_provider_slug ) ) {
            throw new Provider_Unavailable_Exception(
                \esc_html( $this->health_provider_slug ),
                (int) \max( 1, $this->health_service->seconds_until_close( $this->health_provider_slug ) ),
            );
        }

        $params = $this->build_request_params( $messages, $additional_params );

        \do_action(
            'pllat_log_event',
            Event_Codes::AI_REQUEST_SENT,
            array(
                'base_url'      => $this->base_url,
                'message_count' => isset( $params['messages'] ) && \is_array( $params['messages'] ) ? \count( $params['messages'] ) : 0,
                'model'         => $params['model'] ?? 'unknown',
                'temperature'   => $params['temperature'] ?? null,
            ),
        );

        try {
            $response_array = $this->validate_and_parse_response( $this->send_api_request( $params ) );
        } catch ( Provider_Rate_Limited_Exception $e ) {
            // Rate limit is the provider asking us to wait, not a health failure.
            throw $e;
        } catch ( Provider_Quota_Exhausted_Exception $e ) {
            // Out-of-credits is a billing state on the customer's provider
            // account, not a provider-health failure. Skip the circuit
            // breaker: opening it would make subsequent workers release
            // claims for retry (Provider_Unavailable), delaying the
            // terminal failure this condition requires (issue #461).
            throw $e;
        } catch ( Response_Truncated_Exception $e ) {
            // Truncation is our config issue (max_tokens too low), not a
            // provider fault — the API responded successfully. Skip the health
            // failure record so this does not contribute to the circuit
            // breaker. Still propagate so the claim surfaces the message and
            // fails normally after MAX_ATTEMPTS.
            throw $e;
        } catch ( \Throwable $e ) {
            $this->record_health_failure();
            throw $e;
        }

        $this->record_health_success();

        $content_preview = isset( $response_array['choices'][0]['message']['content'] )
            ? \mb_substr( (string) $response_array['choices'][0]['message']['content'], 0, 200 )
            : '';
        \do_action(
            'pllat_log_event',
            Event_Codes::AI_RESPONSE_RECEIVED,
            array(
                'base_url'          => $this->base_url,
                'choices_count'     => isset( $response_array['choices'] ) && \is_array( $response_array['choices'] ) ? \count( $response_array['choices'] ) : 0,
                'content_preview'   => $content_preview,
                'finish_reason'     => $response_array['choices'][0]['finish_reason'] ?? 'unknown',
                'model'             => $response_array['model'] ?? 'unknown',
                'prompt_tokens'     => $response_array['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $response_array['usage']['completion_tokens'] ?? null,
                'total_tokens'      => $response_array['usage']['total_tokens'] ?? null,
                // Prompt-cache hit size, to measure whether provider prefix
                // caching is actually firing (prompt-caching feasibility, audit
                // Part B). OpenAI reports it under
                // usage.prompt_tokens_details.cached_tokens; null when the
                // provider does not report cache stats. A non-zero value here is
                // the signal that enlarging the stable prefix would pay off.
                'cached_tokens'     => $response_array['usage']['prompt_tokens_details']['cached_tokens'] ?? null,
            ),
        );

        return $response_array;
    }

    /**
     * Get the configured model.
     *
     * @return string The model.
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * Build request parameters from messages and additional params.
     *
     * @param array $messages          Chat messages.
     * @param array $additional_params Additional parameters.
     * @return array Request parameters.
     */
    protected function build_request_params( array $messages, array $additional_params ): array {
        $params = array(
            'messages' => $messages,
            'model'    => $this->model,
        );

        // Only add temperature if not a reasoning model (reasoning models don't support it).
        if ( ! isset( $this->model_params['reasoning_effort'] ) ) {
            $params['temperature'] = $additional_params['temperature'] ?? 0.7;
        }

        // Merge model-specific params (e.g., reasoning effort for GPT-5).
        $params = \array_merge( $params, $this->model_params );

        if ( isset( $additional_params['response_format'] ) ) {
            $params['response_format'] = array(
                'json_schema' => $additional_params['response_format'],
                'type'        => 'json_schema',
            );
        }

        if ( isset( $additional_params['tools'] ) ) {
            $params['tools'] = $additional_params['tools'];
        }

        if ( isset( $additional_params['tool_choice'] ) ) {
            $params['tool_choice'] = $additional_params['tool_choice'];
        }

        // Set max tokens using the appropriate parameter name for the model.
        $max_tokens_param            = $this->model_params['max_tokens_param'] ?? 'max_tokens';
        $params[ $max_tokens_param ] = $this->max_tokens;

        // Remove internal param so it's not sent to API.
        unset( $params['max_tokens_param'] );

        return $params;
    }

    /**
     * Build request headers for API call.
     *
     * @return array Request headers.
     */
    protected function build_request_headers(): array {
        return \array_merge(
            array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            $this->additional_headers,
        );
    }

    /**
     * Build the full endpoint URL the request is POSTed to.
     *
     * Providers that do not speak the OpenAI chat/completions wire format
     * (e.g. Gemini's native generateContent endpoint) override this.
     *
     * @return string The request URL.
     */
    protected function get_endpoint_url(): string {
        return \trailingslashit( $this->base_url ) . 'chat/completions';
    }

    /**
     * Normalise a decoded API response into the OpenAI chat/completions shape
     * the rest of the pipeline consumes (choices[].message.content,
     * finish_reason, usage). The default is a pass-through for providers that
     * already return that shape; native (non-OpenAI) clients override this.
     *
     * @param array<string, mixed> $decoded Decoded JSON response body.
     * @return array<string, mixed> Response in OpenAI chat/completions shape.
     */
    protected function normalize_response( array $decoded ): array {
        return $decoded;
    }

    /**
     * Send API request via WordPress HTTP API.
     *
     * @param array $params Request parameters.
     * @return array|WP_Error Response from wp_remote_post.
     * @throws \Exception If the request fails.
     */
    private function send_api_request( array $params ) {
        $body    = \wp_json_encode( $params );
        $timeout = (int) \apply_filters(
            'pllat_ai_client_timeout',
            300,
            array(
                'provider'     => $this->base_url,
                'model'        => $this->model,
                'payload_size' => \strlen( $body ),
            ),
        );

        // WP's Requests library hard-codes a 10s CONNECT timeout that
        // wp_remote_post args cannot change; slow TLS/DNS on some hosts trips
        // it on every attempt (curl error 28 after ~10s) regardless of the
        // total 'timeout' above. Raise it for our own request only.
        $connect_timeout = (int) \apply_filters(
            'pllat_ai_client_connect_timeout',
            30,
            array(
                'provider' => $this->base_url,
                'model'    => $this->model,
            ),
        );

        $raise_connect_timeout = static function ( $handle ) use ( $connect_timeout ): void {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- http_api_curl callback: the only way to raise the connect timeout on the WP HTTP API's own curl handle.
            \curl_setopt( $handle, \CURLOPT_CONNECTTIMEOUT, $connect_timeout );
        };
        \add_action( 'http_api_curl', $raise_connect_timeout );

        try {
            $response = \wp_remote_post(
                $this->get_endpoint_url(),
                array(
                    'body'    => $body,
                    'headers' => $this->build_request_headers(),
                    'timeout' => $timeout,
                ),
            );
        } finally {
            \remove_action( 'http_api_curl', $raise_connect_timeout );
        }

        if ( \is_wp_error( $response ) ) {
            throw new \Exception( 'API request failed: ' . \esc_html( $response->get_error_message() ) );
        }

        return $response;
    }

    /**
     * Validate HTTP response and parse JSON body.
     *
     * @param array $response Response from wp_remote_post.
     * @return array Parsed response array.
     * @throws \Exception If validation or parsing fails.
     */
    private function validate_and_parse_response( array $response ): array {
        $status_code = (int) \wp_remote_retrieve_response_code( $response );

        $this->maybe_throw_quota_exhausted( $response, $status_code );

        if ( 429 === $status_code ) {
            throw new \PLLAT\Translator\Exceptions\Provider_Rate_Limited_Exception(
                \esc_html( $this->base_url ),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- nullable int; an (int) cast would turn "unknown" (null) into 0.
                $this->parse_retry_after( \wp_remote_retrieve_header( $response, 'retry-after' ) ),
            );
        }

        if ( 200 !== $status_code ) {
            $this->handle_error_response( $response, $status_code );
        }

        $response_array = \json_decode( \wp_remote_retrieve_body( $response ), true );

        if ( null === $response_array ) {
            throw new \Exception( 'Failed to parse API response JSON' );
        }

        $response_array = $this->normalize_response( $response_array );

        $this->check_finish_reason( $response_array );

        return $response_array;
    }

    /**
     * Detect permanent quota exhaustion hiding behind a retryable-looking
     * status code, and throw the dedicated exception (issue #461).
     *
     * OpenAI reports "account out of credits" with the same HTTP 429 it uses
     * for transient rate limiting; only the body distinguishes them
     * (error.type=insufficient_quota / error.code=credit_balance_exhausted).
     * OpenRouter (OpenAI-compatible, served by this same client) signals a
     * no-credits account with HTTP 402. Mapping these to the rate-limit
     * exception sent them down the silent release-for-retry path, so runs
     * spun on "processing" forever with zero user-visible feedback.
     *
     * @param array<string, mixed> $response    Response from wp_remote_post.
     * @param int                  $status_code HTTP status code.
     * @return void
     * @throws Provider_Quota_Exhausted_Exception When the account is out of credits.
     */
    private function maybe_throw_quota_exhausted( array $response, int $status_code ): void {
        if ( 402 !== $status_code && 429 !== $status_code ) {
            return;
        }

        $body       = \json_decode( \wp_remote_retrieve_body( $response ), true );
        $error      = \is_array( $body ) && \is_array( $body['error'] ?? null ) ? $body['error'] : array();
        $error_type = \is_string( $error['type'] ?? null ) ? $error['type'] : '';
        $error_code = \is_string( $error['code'] ?? null ) ? $error['code'] : '';

        $is_quota = 402 === $status_code
            || 'insufficient_quota' === $error_type
            || 'credit_balance_exhausted' === $error_code;
        if ( ! $is_quota ) {
            return;
        }

        throw new \PLLAT\Translator\Exceptions\Provider_Quota_Exhausted_Exception(
            \esc_html( $this->base_url ),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- provider error payload kept verbatim; stored and shown as plain text.
            \is_string( $error['message'] ?? null ) ? $error['message'] : '',
        );
    }

    /**
     * Check for truncated responses and provide meaningful error.
     *
     * When finish_reason is 'length', the model hit max_tokens and the response
     * is incomplete. This would otherwise manifest as a cryptic JSON parse failure
     * downstream when callers try to parse the partial output.
     *
     * @param array $response_array Parsed API response.
     * @return void
     * @throws \Exception If response was truncated due to token limit.
     */
    private function check_finish_reason( array $response_array ): void {
        $finish_reason = $response_array['choices'][0]['finish_reason'] ?? null;

        if ( 'length' !== $finish_reason ) {
            return;
        }

        $content_length = \strlen( $response_array['choices'][0]['message']['content'] ?? '' );

        throw new Response_Truncated_Exception(
            \esc_html( $this->health_provider_slug ),
            (int) $this->max_tokens,
            (int) $content_length,
        );
    }

    /**
     * Handle error response from API.
     *
     * @param array $response    Response from wp_remote_post.
     * @param int   $status_code HTTP status code.
     * @return void
     * @throws \Exception Always throws with error details.
     */
    private function handle_error_response( array $response, int $status_code ): void {
        $body       = \json_decode( \wp_remote_retrieve_body( $response ), true );
        $error_msg  = $body['error']['message'] ?? 'Unknown error';
        $error_type = $body['error']['type'] ?? 'api_error';

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- provider error payload kept verbatim; stored and shown as plain text.
        throw new \Exception( "API error ($status_code - $error_type): $error_msg" );
    }

    /**
     * Wire the circuit breaker so this client records failures/successes
     * against the given provider slug.
     *
     * Setter injection because AI_Client is built via the provider
     * factory chain, not through the DI container.
     */
    public function set_health_service( Provider_Health_Service $service, string $provider_slug ): void {
        $this->health_service       = $service;
        $this->health_provider_slug = $provider_slug;
    }

    private function record_health_success(): void {
        if ( null === $this->health_service || '' === $this->health_provider_slug ) {
            return;
        }
        $this->health_service->record_success( $this->health_provider_slug );
    }

    private function record_health_failure(): void {
        if ( null === $this->health_service || '' === $this->health_provider_slug ) {
            return;
        }
        $this->health_service->record_failure( $this->health_provider_slug );
    }

    /**
     * Parse RFC 7231 Retry-After header value.
     *
     * Accepts either a delay-seconds integer or an HTTP-date.
     *
     * @param string|array|null $header Raw header value from wp_remote_retrieve_header.
     * @return int|null Delay in seconds, or null if header missing/unparseable.
     */
    private function parse_retry_after( string|array|null $header ): ?int {
        if ( null === $header || '' === $header ) {
            return null;
        }
        $value = \is_array( $header ) ? (string) \reset( $header ) : $header;

        if ( \ctype_digit( $value ) ) {
            return (int) $value;
        }

        $timestamp = \strtotime( $value );
        if ( false === $timestamp ) {
            return null;
        }
        $delta = $timestamp - \time();
        return $delta > 0 ? $delta : 0;
    }
}
