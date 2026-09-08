<?php
/**
 * Debug_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Debug
 */

namespace PLLAT\Debug;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Handlers\Debug_Cleanup_Handler;
use PLLAT\Debug\Handlers\Debug_Logging_Handler;
use PLLAT\Debug\Handlers\Error_Handler;
use PLLAT\Debug\Services\Debug_Logger_Service;
use PLLAT\Debug\Services\Error_Logger_Service;
use XWP\DI\Decorators\Module;

/**
 * Debug module definition.
 * Handles debug logging for translation processes, AI interactions, and error tracking.
 */
#[Module(
	hook: 'init',
	priority: -20,
	handlers: array(
		Debug_Logging_Handler::class,
		Error_Handler::class,
		Debug_Cleanup_Handler::class,
	),
	services: array(
		Debug_Logger_Service::class,
		Error_Logger_Service::class,
	),
)]
class Debug_Module {
	/**
	 * Module definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function configure(): array {
		return array(
			Debug_Logger_Service::class => \DI\autowire(),
			Error_Logger_Service::class => \DI\autowire(),
		);
	}
}
