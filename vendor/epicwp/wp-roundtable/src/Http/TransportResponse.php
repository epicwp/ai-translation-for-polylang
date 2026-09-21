<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

/** An HTTP response: the status code and the raw body. */
final class TransportResponse {
    /**
     * Creates the response.
     *
     * @param int    $status The HTTP status code.
     * @param string $body   The raw response body.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
