<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

// phpcs:disable WordPress.WP.AlternativeFunctions -- curl is required for incremental (streaming) reads; wp_remote_* buffers the entire response and cannot invoke a per-chunk callback.
/** Curl-based streaming transport: reads the SSE body incrementally and emits whole frames. */
final class CurlStreamingTransport implements StreamingTransport {
    /**
     * Converts an associative header array into curl's "Key: Value" line format.
     *
     * @param array<string, string> $headers Request headers.
     *
     * @return array<int, string> The formatted header lines.
     */
    private static function headerLines( array $headers ): array {
        $lines = array();
        foreach ( $headers as $key => $value ) {
            $lines[] = $key . ': ' . $value;
        }
        return $lines;
    }

    /**
     * Extracts and emits every complete SSE frame currently in the buffer, leaving any
     * trailing partial frame in place for the next chunk to complete.
     *
     * @param string                $buffer  The accumulated bytes read so far, passed by reference.
     * @param callable(string):void $onFrame Called with each raw frame (text between blank lines).
     */
    private static function emitFrames( string &$buffer, callable $onFrame ): void {
        // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- concise idiom for "find the next frame boundary, or stop if there isn't one".
        while ( false !== ( $pos = \strpos( $buffer, "\n\n" ) ) ) {
            $frame  = \substr( $buffer, 0, $pos );
            $buffer = \substr( $buffer, $pos + 2 );
            if ( '' === \trim( $frame ) ) {
                continue;
            }
            $onFrame( $frame );
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param string                $url            The hub URL.
     * @param array<string, string> $headers        Request headers.
     * @param string                $body           The JSON request body.
     * @param int                   $timeoutSeconds The overall timeout.
     * @param callable(string):void $onFrame        Called with each raw frame (text between blank lines).
     *
     * @throws \EpicWP\Roundtable\Http\TransportException On a connection failure.
     */
    public function stream( string $url, array $headers, string $body, int $timeoutSeconds, callable $onFrame ): int {
        $buffer = '';
        $ch     = \curl_init( $url );
        \curl_setopt_array(
            $ch,
            array(
                \CURLOPT_HTTPHEADER    => self::headerLines( $headers ),
                \CURLOPT_POST          => true,
                \CURLOPT_POSTFIELDS    => $body,
                \CURLOPT_TIMEOUT       => $timeoutSeconds,
                \CURLOPT_WRITEFUNCTION => static function ( $ch, string $chunk ) use ( &$buffer, $onFrame ): int {
                    $buffer .= $chunk;
                    self::emitFrames( $buffer, $onFrame );
                    return \strlen( $chunk );
                },
            ),
        );

        $ok     = \curl_exec( $ch );
        $status = (int) \curl_getinfo( $ch, \CURLINFO_HTTP_CODE );
        $errno  = \curl_errno( $ch );
        \curl_close( $ch );

        if ( false === $ok && 0 !== $errno ) {
            throw new \EpicWP\Roundtable\Http\TransportException( 'streaming transport failed' );
        }

        if ( '' !== \trim( $buffer ) ) {
            $onFrame( $buffer );
        }

        return $status;
    }
}
