<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Http;

/** Streams a hub SSE response frame-by-frame (wp_remote_post buffers; this does not). */
interface StreamingTransport {
    /**
     * POST and invoke $onFrame for each complete SSE frame as it arrives.
     *
     * @param string                $url            The hub URL.
     * @param array<string, string> $headers        Request headers.
     * @param string                $body           The JSON request body.
     * @param int                   $timeoutSeconds The overall timeout.
     * @param callable(string):void $onFrame        Called with each raw frame (text between blank lines).
     *
     * @return int The HTTP status code.
     * @throws TransportException On a connection failure.
     */
    public function stream( string $url, array $headers, string $body, int $timeoutSeconds, callable $onFrame ): int;
}
