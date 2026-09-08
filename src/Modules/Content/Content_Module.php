<?php
namespace PLLAT\Content;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Controllers\Content_Fields_REST_Controller;
use PLLAT\Content\Controllers\Meta_Field_Scan_Controller;
use PLLAT\Content\Handlers\Content_Change_Handler;
use PLLAT\Content\Handlers\Meta_Field_Handler;
use PLLAT\Content\Handlers\Meta_Field_Scan_Handler;
use XWP\DI\Decorators\Module;

/**
 * Content Module - Manages content operations.
 *
 * Note: Cleanup logic moved to Sync module.
 */
#[Module(
    hook: 'init',
    priority: 10,
    handlers: array(
        Content_Change_Handler::class,
        Content_Fields_REST_Controller::class,
        Meta_Field_Scan_Controller::class,
        Meta_Field_Handler::class,
        Meta_Field_Scan_Handler::class,
    ),
    services: array(
        Language_Manager::class,
    ),
)]
class Content_Module {
    /**
     * Module definition.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array();
    }
}
