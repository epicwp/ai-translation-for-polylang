<?php
/**
 * Provider_Unavailable_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the circuit breaker is open for a provider.
 *
 * Caught separately from generic failures and from rate-limit so the job
 * can be re-enqueued with the breaker's actual remaining cooldown without
 * burning a retry attempt — circuit state is not the task's fault.
 */
class Provider_Unavailable_Exception extends \RuntimeException {

	/**
	 * @param string $provider            Provider slug (e.g. "openai", "anthropic").
	 * @param int    $retry_after_seconds Seconds until the breaker is scheduled to close.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly int $retry_after_seconds,
	) {
		parent::__construct(
			\sprintf(
				'Provider %s unavailable: circuit breaker open for %ds',
				$provider,
				$retry_after_seconds,
			),
		);
	}
}
