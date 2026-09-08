<?php
/**
 * CLI_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage CLI
 */

declare(strict_types=1);

namespace PLLAT\CLI;

\defined( 'ABSPATH' ) || exit;

use PLLAT\CLI\Handlers\Content_CLI_Handler;
use PLLAT\CLI\Handlers\Tables_CLI_Handler;
use PLLAT\CLI\Handlers\Test_CLI_Handler;
use PLLAT\CLI\Services\Block_Test_CLI_Service;
use PLLAT\CLI\Services\Content_CLI_Service;
use PLLAT\CLI\Services\Tables_CLI_Service;
use XWP\DI\Decorators\Module;

#[Module(
    hook: 'init',
    priority: 1,
    handlers: array(
        Tables_CLI_Handler::class,
        Content_CLI_Handler::class,
        Test_CLI_Handler::class,
    ),
    services: array(
        Tables_CLI_Service::class,
        Content_CLI_Service::class,
        Block_Test_CLI_Service::class,
    ),
)]
class CLI_Module {
}
