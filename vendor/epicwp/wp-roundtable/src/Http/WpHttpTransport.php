<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

/** The production {@see Transport}, backed by the WordPress HTTP API. */
final class WpHttpTransport implements Transport {
    /**
     * {@inheritDoc}
     *
     * @param string                $url            The absolute URL.
     * @param array<string, string> $headers        Request headers.
     * @param string                $body           The request body (already encoded).
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @throws \EpicWP\Roundtable\Http\TransportException On a transport-level failure (no HTTP response).
     */
    public function post( string $url, array $headers, string $body, int $timeoutSeconds ): TransportResponse {
        $response = \wp_remote_post(
            $url,
            array(
                'body'    => $body,
                'headers' => $headers,
                'timeout' => $timeoutSeconds,
            ),
        );

        if ( \is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (WP_Error string), never rendered as HTML.
            throw new \EpicWP\Roundtable\Http\TransportException( $response->get_error_message() );
        }

        return new TransportResponse(
            (int) \wp_remote_retrieve_response_code( $response ),
            (string) \wp_remote_retrieve_body( $response ),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string                $url            The absolute URL (including any query string).
     * @param array<string, string> $headers        Request headers.
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @throws \EpicWP\Roundtable\Http\TransportException On a transport-level failure (no HTTP response).
     */
    public function get( string $url, array $headers, int $timeoutSeconds ): TransportResponse {
        // phpcs:ignore SlevomatCodingStandard.Functions.RequireSingleLineCall.RequiredSingleLineCall -- mirrors post(); multi-line args are clearer than a 120+ char line.
        $response = \wp_remote_get(
            $url,
            array(
                'headers' => $headers,
                'timeout' => $timeoutSeconds,
            ),
        );

        if ( \is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (WP_Error string), never rendered as HTML.
            throw new \EpicWP\Roundtable\Http\TransportException( $response->get_error_message() );
        }

        return new TransportResponse(
            (int) \wp_remote_retrieve_response_code( $response ),
            (string) \wp_remote_retrieve_body( $response ),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string                $url            The absolute URL (including any query string).
     * @param array<string, string> $headers        Request headers.
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @throws \EpicWP\Roundtable\Http\TransportException On a transport-level failure (no HTTP response).
     */
    public function delete( string $url, array $headers, int $timeoutSeconds ): TransportResponse {
        // phpcs:ignore SlevomatCodingStandard.Functions.RequireSingleLineCall.RequiredSingleLineCall -- mirrors get(); multi-line args are clearer than a 120+ char line.
        $response = \wp_remote_request(
            $url,
            array(
                'headers' => $headers,
                'method'  => 'DELETE',
                'timeout' => $timeoutSeconds,
            ),
        );

        if ( \is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal RuntimeException message (WP_Error string), never rendered as HTML.
            throw new \EpicWP\Roundtable\Http\TransportException( $response->get_error_message() );
        }

        return new TransportResponse(
            (int) \wp_remote_retrieve_response_code( $response ),
            (string) \wp_remote_retrieve_body( $response ),
        );
    }
}
