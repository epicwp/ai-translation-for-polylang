<?php
/**
 * Provider_Connectivity_Tester file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Status\Preflight\Checks\Provider_Connectivity_Check;
use PLLAT\Translator\Services\AI_Provider_Factory;

/**
 * Performs a minimal billable ping against the configured AI provider and
 * persists the result to wp_options for the Provider_Connectivity_Check to
 * read. Triggered on settings save, a manual "Test connection" REST call,
 * or the preflight endpoint's `?fresh=true` parameter — NEVER by preflight
 * itself. That is the contract: preflight is free.
 */
class Provider_Connectivity_Tester {

    public function __construct( private Settings_Service $settings ) {}

    /**
     * Perform a small billable ping against the configured provider and store the result.
     *
     * @return array{success:bool,tested_at:int,provider:string,error?:string}
     */
    public function test_active_provider(): array {
        $provider = (string) $this->settings->get_active_translation_api();
        if ( '' === $provider ) {
            $result = array(
                'success'   => false,
                'tested_at' => \time(),
                'provider'  => '',
                'error'     => 'no_active_provider',
            );
            return $result;
        }

        try {
            if ( ! AI_Provider_Factory::can_create_from_settings( $this->settings ) ) {
                throw new \RuntimeException( 'Provider settings incomplete.' );
            }
            $client = AI_Provider_Factory::create_from_settings( $this->settings )->get_client();
            $client->chat_completion(
                array( array( 'role' => 'user', 'content' => 'ok' ) ),
                array( 'temperature' => 0, 'max_tokens' => 1 ),
            );
            $result = array(
                'success'   => true,
                'tested_at' => \time(),
                'provider'  => $provider,
            );
        } catch ( \Throwable $e ) {
            $result = array(
                'success'   => false,
                'tested_at' => \time(),
                'provider'  => $provider,
                'error'     => $e->getMessage(),
            );
        }

        \update_option( Provider_Connectivity_Check::OPTION_PREFIX . $provider, $result, false );
        return $result;
    }
}
