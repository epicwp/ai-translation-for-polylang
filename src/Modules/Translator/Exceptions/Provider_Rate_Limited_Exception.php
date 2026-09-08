<?php
/**
 * Provider_Rate_Limited_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an AI provider returns HTTP 429.
 *
 * Caught separately from generic failures so the job can be re-enqueued
 * with the provider's own Retry-After delay without burning a retry
 * attempt — rate limiting isn't a bug on our side.
 */
class Provider_Rate_Limited_Exception extends \RuntimeException {

	/**
	 * @param string   $provider             Provider slug (e.g. "openai", "anthropic").
	 * @param int|null $retry_after_seconds  Server-provided Retry-After. Null if header missing or unparseable.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly ?int $retry_after_seconds,
	) {
		parent::__construct(
			\sprintf(
				'Provider %s rate-limited. Retry-After: %s',
				$provider,
				null === $retry_after_seconds ? 'unknown' : $retry_after_seconds . 's',
			),
		);
	}
}
