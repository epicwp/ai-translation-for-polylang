<?php
/**
 * Provider_Key_Check file.
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
 * Verifies the AI provider is configured well enough to potentially run —
 * active provider selected, API key present, model valid.
 */
class Provider_Key_Check implements Preflight_Check {

    public function __construct( private Provider_Validation_Gateway $gateway ) {}

    public function get_name(): string {
        return 'provider_key';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $errors = $this->gateway->get_validation_errors();
        if ( array() === $errors ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'AI provider key and model configured.',
                null,
            );
        }

        $provider   = $this->gateway->get_active_provider();
        $admin_link = '' !== $provider
            ? \admin_url( 'admin.php?page=pllat-settings&tab=general#pllat_' . $provider . '_api_key' )
            : \admin_url( 'admin.php?page=pllat-settings&tab=general' );

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Fail,
            'Your AI provider is not fully configured: ' . \implode( '; ', $errors ),
            'Open Settings, paste your API key, and save. Then come back and click "Re-test" below.',
            null,
            $admin_link,
            \__( 'Open AI provider settings', 'ai-translation-for-polylang' ),
        );
    }
}
