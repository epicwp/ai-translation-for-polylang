<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Rest;

use EpicWP\Roundtable\Config;

/** The shared REST permission gate: valid nonce, the `read` capability, and a valid licence. */
final class Gate {
    /**
     * Whether the request may proceed to a hub call.
     *
     * @param Config                                 $config  The SDK configuration (licence gate on `$config->consumer`).
     * @param \WP_REST_Request<array<string, mixed>> $request The incoming request.
     *
     * @return bool True to proceed; false to refuse (403) before any hub call.
     */
    public static function permits( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `\WP_REST_Request<array<string, mixed>>` is a PHPStan generic; the native param type stays `\WP_REST_Request`.
        Config $config,
        \WP_REST_Request $request,
    ): bool {
        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( false === \wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return false;
        }
        if ( ! \current_user_can( 'read' ) ) {
            return false;
        }
        return $config->consumer->isUserAllowed();
    }
}
