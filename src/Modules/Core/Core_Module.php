<?php
/**
 * Core_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Core
 */

namespace PLLAT\Core;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\URL_Translation_Service;
use PLLAT\Core\Services\Polylang_Language_Manager;
use PLLAT\Translator\Providers\OpenAI_Provider;
use PLLAT\Translator\Services\AI_Provider_Registry;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\Can_Initialize;
use XWP\DI\Interfaces\On_Initialize;

/**
 * Core module for shared services and infrastructure.
 */
#[Module(
    hook: 'plugins_loaded',
    priority: 5,
    services: array(
        Language_Manager::class,
        URL_Translation_Service::class,
    ),
)]
class Core_Module implements Can_Initialize, On_Initialize {
    /**
     * Check if the module can be initialized.
     *
     * @return bool
     */
    public static function can_initialize(): bool {
        return true; // Core module should always initialize.
    }

    /**
     * Module configuration.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array(
            Language_Manager::class => \DI\autowire( Polylang_Language_Manager::class ),
        );
    }

    /**
     * Initialize the module and register AI providers.
     *
     * @return void
     */
    public function on_initialize(): void {
        $this->register_ai_providers();
    }

    /**
     * Register the shared AI provider and allow extensions via the action.
     *
     * OpenAI is the one provider every edition ships; the other providers
     * register themselves from Pro_Providers_Module.
     *
     * @return void
     */
    private function register_ai_providers(): void {
        AI_Provider_Registry::register_provider( new OpenAI_Provider() );

        /**
         * Filter to allow registration of additional AI providers.
         *
         * @param string $registry The provider registry class name.
         */
        \do_action( 'pllat_register_ai_providers', AI_Provider_Registry::class );

        AI_Provider_Registry::mark_initialized();
    }
}
