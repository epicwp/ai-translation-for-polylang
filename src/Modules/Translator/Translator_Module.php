<?php
/**
 * Translator_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

namespace PLLAT\Translator;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\Rate_Limiter_Service;
use PLLAT\Content\Services\Content_Service;
use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Handlers\Translation_Worker_Handler;
use PLLAT\Translator\Handlers\Translator_Handler;
use PLLAT\Translator\Repositories\Provider_Health_Repository;
use PLLAT\Translator\Repositories\Run_Spec_Repository;
use PLLAT\Translator\Services\Activity_Writer;
use PLLAT\Translator\Services\AI_Client;
use PLLAT\Translator\Services\AI_Provider_Factory;
use PLLAT\Translator\Services\AI_Provider_Gate;
use PLLAT\Translator\Services\Cancel_Service;
use PLLAT\Translator\Services\Claim_Lifecycle;
use PLLAT\Translator\Services\Field_Translator;
use PLLAT\Translator\Services\Job_Claim_Service;
use PLLAT\Translator\Services\Lean_Job_Worker;
use PLLAT\Translator\Services\Provider_Health_Service;
use PLLAT\Translator\Services\Run_Completion_Service;
use PLLAT\Translator\Services\Run_Spec_Service;
use PLLAT\Translator\Services\System_Prompt_Enricher;
use PLLAT\Translator\Services\Translator;
use PLLAT\Translator\Services\Translator_JSON;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\Can_Initialize;

/**
 * Translator module definition.
 *
 * Note: Cascade, Recovery, and Completion logic moved to Sync module.
 */
#[Module(
    hook: 'init',
    priority: 2,
    handlers: array(
        Translator_Handler::class,
        Translation_Worker_Handler::class,
    ),
    services: array(
        Rate_Limiter_Service::class,
        Content_Service::class,
        Provider_Health_Repository::class,
        Provider_Health_Service::class,
        AI_Provider_Gate::class,
        Run_Spec_Service::class,
        Run_Spec_Repository::class,
        Job_Claim_Service::class,
        Cancel_Service::class,
        Run_Completion_Service::class,
        Claim_Lifecycle::class,
        Activity_Writer::class,
        Lean_Job_Worker::class,
        System_Prompt_Enricher::class,
    ),
)]
class Translator_Module implements Can_Initialize {
    /**
     * Check if the module can be initialized.
     *
     * Module must always load for both BYOK and Credits modes.
     * - BYOK mode: needs AI services (Translator, AI_Client) + REST endpoints
     * - Credits mode: foundation preserved for future external processor rebuild
     *
     * @return bool Always true to ensure module loads in both modes.
     */
    public static function can_initialize(): bool {
        // Only initialize if AI API settings are properly configured.
        $settings_service = new Settings_Service();
        return AI_Provider_Factory::can_create_from_settings( $settings_service );
    }

    /**
     * Module definition.
     *
     * Conditionally register AI services based on translation mode:
     * - BYOK mode: Register AI_Client, Translator, Translator_JSON, Field_Translator
     * - Credits mode: No AI services needed (external processor handles translation)
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        $config           = array();
        $settings_service = new Settings_Service();

        // Only register AI services in BYOK mode.
        if ( $settings_service->is_byok_mode() ) {
            // AI Client factory — routed through AI_Provider_Gate so the
            // resulting client carries circuit-breaker bookkeeping.
            $config[ AI_Client::class ] = \DI\factory(
                static function ( Settings_Service $settings_service, AI_Provider_Gate $gate ) {
                    if ( ! AI_Provider_Factory::can_create_from_settings( $settings_service ) ) {
                        throw new \Exception(
                            'AI provider cannot be created: settings not configured or invalid API key',
                        );
                    }
                    return $gate->create_client();
                },
            );

        }

        return $config;
    }
}
