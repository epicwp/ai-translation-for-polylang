<?php
/**
 * Provider_Connectivity_Check file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight\Checks
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight\Checks;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Preflight\Preflight_Check;
use PLLAT\Status\Preflight\Preflight_Check_Result;
use PLLAT\Status\Preflight\Preflight_Level;
use PLLAT\Status\Preflight\Preflight_Scope;
use PLLAT\Status\Preflight\Provider_Validation_Gateway;

/**
 * Reads the cached provider connectivity result stored by the connectivity
 * tester. Never triggers an API call itself — that would be billable and
 * surprise-charge users every time preflight runs.
 */
class Provider_Connectivity_Check implements Preflight_Check {

    private const STALE_AFTER_SECONDS = 86400;
    public const OPTION_PREFIX        = 'pllat_provider_connectivity_';

    public function __construct( private Provider_Validation_Gateway $gateway ) {}

    public function get_name(): string {
        return 'provider_connectivity';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $provider = $this->gateway->get_active_provider();
        if ( '' === $provider ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'No active provider to verify.',
                null,
            );
        }

        $admin_link       = \admin_url( 'admin.php?page=pllat-settings&tab=general#pllat_' . $provider . '_api_key' );
        $admin_link_label = \__( 'Open AI provider settings', 'ai-translation-for-polylang' );

        $option = \get_option( self::OPTION_PREFIX . $provider, false );
        if ( false === $option || ! \is_array( $option ) ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Warn,
                \sprintf( 'We have not yet verified that we can reach %s with your API key.', $provider ),
                'Open the AI provider settings, click "Save Settings" to trigger a connection test, then return here and click "Re-test now" below.',
                null,
                $admin_link,
                $admin_link_label,
            );
        }

        $success   = (bool) ( $option['success'] ?? false );
        $tested_at = (int) ( $option['tested_at'] ?? 0 );
        $age       = \time() - $tested_at;

        if ( ! $success ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                \sprintf(
                    'We could not reach %s with your API key. Provider response: %s',
                    $provider,
                    (string) ( $option['error'] ?? 'unknown error' ),
                ),
                'Open the AI provider settings, paste a valid API key for your provider, click "Save Settings", then return here and click "Re-test now" below to verify.',
                null,
                $admin_link,
                $admin_link_label,
            );
        }

        if ( $age > self::STALE_AFTER_SECONDS ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Warn,
                \sprintf(
                    'Last connectivity test for %s was %d hours ago.',
                    $provider,
                    (int) ( $age / 3600 ),
                ),
                'Click "Re-test" below to confirm the provider is still reachable.',
                null,
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Pass,
            \sprintf( 'Provider %s reachable (tested %d min ago).', $provider, (int) ( $age / 60 ) ),
            null,
        );
    }
}
