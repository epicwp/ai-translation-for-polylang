<?php
/**
 * Response_Truncated_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when an AI provider response is cut off because we asked for too few
 * output tokens (finish_reason=length). The provider call itself succeeded —
 * this is a configuration issue on our side, not a provider fault, so it must
 * NOT be recorded as a circuit-breaker health failure.
 */
class Response_Truncated_Exception extends \RuntimeException {

	public function __construct(
		public readonly string $provider,
		public readonly int $max_tokens,
		public readonly int $content_length,
	) {
		parent::__construct(
			\sprintf(
				'AI response truncated (finish_reason=length, max_tokens=%d, response_length=%d). '
				. 'Increase max output tokens in settings or reduce content size.',
				$max_tokens,
				$content_length,
			),
		);
	}
}
