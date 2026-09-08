<?php
/**
 * Logging_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Logging
 */

declare(strict_types=1);

namespace PLLAT\Common\Logging;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Module;

/**
 * Logging foundation module.
 *
 * Registers the singleton services that implement trace correlation
 * and sensitive-data scrubbing. Must load before any module that
 * depends on Debug_Logger_Service or Error_Logger_Service.
 */
#[Module(
	hook: 'init',
	priority: -30,
	services: array(
		Trace_Context_Service::class,
		Trace_Context_Processor::class,
		Sensitive_Data_Scrubber::class,
	),
)]
class Logging_Module {}
