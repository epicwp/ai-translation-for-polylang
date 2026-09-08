<?php
/**
 * Logs_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Logs
 */

namespace PLLAT\Logs;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Logs\Controllers\Logs_REST_Controller;
use PLLAT\Logs\Handlers\Logging_Handler;
use XWP\DI\Decorators\Module;

/**
 * Logs module definition.
 * Handles all logging functionality including log writing, reading, and REST API endpoints.
 */
#[Module(
    hook: 'init',
    priority: -10,
    handlers: array(
        Logs_REST_Controller::class,
        Logging_Handler::class,
    ),
)]
class Logs_Module {
}
