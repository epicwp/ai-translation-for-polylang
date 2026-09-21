<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Chat;

use EpicWP\Roundtable\Event;

/**
 * Parses a *buffered* Server-Sent-Events body into ordered {@see Event}s.
 *
 * The hub streams `data: <json>\n\n` frames; because the SDK reads the whole response at
 * once (no live streaming), this splits the buffer on the blank-line frame boundary and
 * decodes each `data:` payload. Malformed or typeless frames are skipped defensively — a
 * bad frame never aborts the turn.
 */
final class SseParser {
    /**
     * Parses the buffered SSE body into events.
     *
     * @param string $body The raw, buffered SSE response body.
     *
     * @return list<Event> The decoded events, in order.
     */
    public static function parse( string $body ): array {
        $events = array();
        foreach ( \preg_split( '/\R\R/', $body ) ?: array() as $frame ) {
            $event = self::decodeFrame( $frame );
            if ( null === $event ) {
                continue;
            }

            $events[] = $event;
        }
        return $events;
    }

    /**
     * Decodes a single `data:` frame into an event.
     *
     * @param string $frame One raw frame from the buffer.
     *
     * @return Event|null The decoded event, or null if the frame is malformed or typeless.
     */
    private static function decodeFrame( string $frame ): ?Event {
        $frame = \trim( $frame );
        if ( '' === $frame || 0 !== \strncmp( $frame, 'data:', 5 ) ) {
            return null;
        }

        $json = \trim( \substr( $frame, 5 ) );
        /**
         * Decoded JSON payload.
         *
         * @var mixed $decoded
         */
        $decoded = \json_decode( $json, true );
        if ( ! \is_array( $decoded ) || ! \is_string( $decoded['type'] ?? null ) ) {
            return null;
        }

        $type = $decoded['type'];
        unset( $decoded['type'] );
        /**
         * Payload with the type key removed.
         *
         * @var array<string, mixed> $decoded
         */
        return new Event( $type, $decoded );
    }
}
