<?php
declare(strict_types=1);

namespace PLLAT\Debug\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Bridges the canonical pllat_log_event hook to Debug_Logger_Service.
 *
 * The pre-v3.12 design exposed 15 bespoke do_action hooks, one per event,
 * with this handler holding 15 thin #[Action] wrappers. v3.12 collapsed
 * the surface to a single hook with (string $code, array $context).
 *
 * Firers use Event_Codes constants for $code and pass a flat, already-
 * structured context array. The logger does scrubbing + level mapping.
 */
#[Handler( tag: 'init', priority: 10 )]
class Debug_Logging_Handler {

	public function __construct(
		private Debug_Logger_Service $logger,
	) {}

	/**
	 * @param string               $code    One of the Event_Codes constants.
	 * @param array<string, mixed> $context Structured context for the log record.
	 */
	#[Action( tag: 'pllat_log_event', priority: 10 )]
	public function log_event( string $code, array $context = array() ): void {
		try {
			$this->logger->log_event( $code, $context );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- logging must never break the request; a failing debug logger has no safer sink to report to.
		}
	}
}
