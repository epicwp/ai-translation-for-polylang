<?php
/**
 * Upsell_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Upsell
 */

declare(strict_types=1);

namespace PLLAT\Upsell;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Upsell\Handlers\Upsell_Handler;
use PLLAT\Upsell\Services\Upsell_Service;
use XWP\DI\Decorators\Module;

/**
 * The free edition's pointers to the Pro edition: the Pro tab on the settings
 * page, the provider note on the General tab and the upgrade URL the
 * dashboard and the meta box link to. Shared by both editions; the pro-only
 * Pro_Settings_Handler removes the tab and the note through the same filters.
 */
#[Module(
    hook: 'plugins_loaded',
    priority: 10,
    context: Module::CTX_ADMIN,
    handlers: array(
        Upsell_Handler::class,
    ),
    services: array(
        Upsell_Service::class,
    ),
)]
class Upsell_Module {
}
