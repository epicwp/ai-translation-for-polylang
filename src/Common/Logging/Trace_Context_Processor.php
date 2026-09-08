<?php
/**
 * Trace_Context_Processor class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Logging
 */

declare(strict_types=1);

namespace PLLAT\Common\Logging;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Dependencies\Monolog\LogRecord;
use PLLAT\Dependencies\Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that injects Trace_Context_Service fields into each log record's `extra`.
 *
 * Registered by Debug_Logger_Service and Error_Logger_Service via
 * pushProcessor() so every log record automatically gets trace_id/run_id/job_id.
 */
class Trace_Context_Processor implements ProcessorInterface {

	public function __construct( private Trace_Context_Service $context ) {}

	public function __invoke( LogRecord $record ): LogRecord {
		$context_fields = $this->context->to_array();
		if ( array() === $context_fields ) {
			return $record;
		}
		return $record->with( extra: \array_merge( $record->extra, $context_fields ) );
	}
}
