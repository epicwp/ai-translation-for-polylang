<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

/** An HTTP POST over an injectable seam, so the core is testable without WordPress. */
interface Transport {
    /**
     * POST a request and return the response.
     *
     * @param string                $url            The absolute URL.
     * @param array<string, string> $headers        Request headers.
     * @param string                $body           The request body (already encoded).
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @return TransportResponse The HTTP status + raw body.
     *
     * @throws TransportException On a transport-level failure (no HTTP response).
     */
    public function post( string $url, array $headers, string $body, int $timeoutSeconds ): TransportResponse;

    /**
     * GET a resource and return the response.
     *
     * @param string                $url            The absolute URL (including any query string).
     * @param array<string, string> $headers        Request headers.
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @return TransportResponse The HTTP status + raw body.
     *
     * @throws TransportException On a transport-level failure (no HTTP response).
     */
    public function get( string $url, array $headers, int $timeoutSeconds ): TransportResponse;

    /**
     * DELETE a resource and return the response.
     *
     * @param string                $url            The absolute URL (including any query string).
     * @param array<string, string> $headers        Request headers.
     * @param int                   $timeoutSeconds The request timeout.
     *
     * @return TransportResponse The HTTP status + raw body.
     *
     * @throws TransportException On a transport-level failure (no HTTP response).
     */
    public function delete( string $url, array $headers, int $timeoutSeconds ): TransportResponse;
}
