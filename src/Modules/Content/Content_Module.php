<?php
namespace PLLAT\Content;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Controllers\Content_Fields_REST_Controller;
use PLLAT\Content\Handlers\Content_Change_Handler;
use PLLAT\Content\Handlers\Meta_Copy_Handler;
use XWP\DI\Decorators\Module;

/**
 * Content Module - Manages content operations.
 *
 * Field discovery, write-back, edit detection and the page-builder meta copy
 * rule, in every edition. Custom field management (the AI meta scan and the
 * classified keys) is Meta_Fields_Module, pro.
 *
 * Note: Cleanup logic moved to Sync module.
 */
#[Module(
    hook: 'init',
    priority: 10,
    handlers: array(
        Content_Change_Handler::class,
        Content_Fields_REST_Controller::class,
        Meta_Copy_Handler::class,
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
