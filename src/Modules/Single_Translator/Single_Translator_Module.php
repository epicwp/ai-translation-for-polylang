<?php
/**
 * Single_Translator_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Single_Translator\Controllers\Single_Translation_REST_Controller;
use PLLAT\Single_Translator\Handlers\Meta_Box_Handler;
use PLLAT\Single_Translator\Services\Single_Translation_Service;
use XWP\DI\Decorators\Module;

/**
 * Single Translator Module.
 *
 * Provides functionality for translating individual posts and terms.
 * Licensed users can choose processing method (self-hosted or external).
 * Free users: no bulk translation available.
 */
#[Module(
    hook: 'init',
    priority: 5,
    handlers: array(
        Meta_Box_Handler::class,
        Single_Translation_REST_Controller::class,
    ),
    services: array(
        Single_Translation_Service::class,
    ),
)]
class Single_Translator_Module {
}
