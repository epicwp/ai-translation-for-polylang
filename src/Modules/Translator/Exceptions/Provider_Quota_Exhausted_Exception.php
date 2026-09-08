<?php
/**
 * Provider_Quota_Exhausted_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the AI provider rejects a request because the account is out
 * of credits (OpenAI: HTTP 429 with error.type=insufficient_quota /
 * error.code=credit_balance_exhausted; OpenRouter: HTTP 402).
 *
 * Deliberately distinct from Provider_Rate_Limited_Exception: a rate limit
 * is transient and released for retry without burning an attempt, but quota
 * exhaustion is permanent until the customer adds credits — retrying can
 * never succeed, so the claim must fail terminally with a visible reason
 * (issue #461: runs stuck on "processing" forever with no feedback).
 */
class Provider_Quota_Exhausted_Exception extends \RuntimeException {

	/**
	 * @param string $provider         Provider identifier (base URL or slug).
	 * @param string $provider_message The provider's own error message; may be empty.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $provider_message,
	) {
		$detail = '' !== $provider_message
			? $provider_message
			: 'The provider rejected the request because the account has no remaining credits.';

		parent::__construct(
			\sprintf(
				'Provider account is out of credits (%s): %s',
				$provider,
				$detail,
			),
		);
	}
}
