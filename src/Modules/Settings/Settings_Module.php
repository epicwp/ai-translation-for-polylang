<?php
/**
 * Settings_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Settings
 */

namespace PLLAT\Settings;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Handlers\Settings_Page_Handler;
use PLLAT\Settings\Services\Settings_Field_Renderer;
use PLLAT\Settings\Services\Settings_Form;
use PLLAT\Settings\Services\Settings_Input_Sanitizer;
use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Settings\Services\Settings_Support_Tab_Renderer;
use XWP\DI\Decorators\Module;

/**
 * Settings module for managing plugin configuration.
 */
#[Module(
    hook: 'plugins_loaded',
    priority: 10,
    context: Module::CTX_ADMIN | Module::CTX_AJAX | Module::CTX_REST,
    handlers: array(
        Settings_Page_Handler::class,
    ),
    services: array(
        Settings_Service::class,
        Settings_Form::class,
        Settings_Field_Renderer::class,
        Settings_Input_Sanitizer::class,
        Settings_Support_Tab_Renderer::class,
    ),
)]
class Settings_Module {
    /**
     * Module configuration.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array();
    }
}
