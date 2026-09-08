<?php
/**
 * Sensitive_Data_Scrubber class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Logging
 */

declare(strict_types=1);

namespace PLLAT\Common\Logging;

\defined( 'ABSPATH' ) || exit;

/**
 * Masks sensitive data in log context arrays before writing to log files.
 *
 * Scrubs:
 * - Keys matching /api_key|authorization|bearer|token/i -> [REDACTED]
 * - OpenAI key pattern /sk-[a-zA-Z0-9]{20,}/ in string values -> sk-***
 * - Anthropic key pattern /sk-ant-[a-zA-Z0-9-]{20,}/ in string values -> sk-ant-***
 * - String values > 4096 bytes -> "first 2KB [TRUNCATED N bytes] last 2KB"
 */
class Sensitive_Data_Scrubber {

	private const REDACT_KEY_PATTERN    = '/api[-_]?key|authorization|bearer|token/i';
	private const OPENAI_KEY_PATTERN    = '/sk-[a-zA-Z0-9]{20,}/';
	private const ANTHROPIC_KEY_PATTERN = '/sk-ant-[a-zA-Z0-9-]{20,}/';
	private const MAX_VALUE_BYTES       = 4096;
	private const TRUNCATION_HEAD_BYTES = 2048;
	private const TRUNCATION_TAIL_BYTES = 2048;

	/**
	 * Scrub a context array recursively.
	 *
	 * @param array<string,mixed> $context Input context.
	 * @return array<string,mixed> Scrubbed context.
	 */
	public function scrub( array $context ): array {
		$output = array();

		foreach ( $context as $key => $value ) {
			if ( \is_string( $key ) && 1 === \preg_match( self::REDACT_KEY_PATTERN, $key ) ) {
				$output[ $key ] = '[REDACTED]';
				continue;
			}

			if ( \is_array( $value ) ) {
				$output[ $key ] = $this->scrub( $value );
				continue;
			}

			if ( \is_string( $value ) ) {
				$output[ $key ] = $this->scrub_string_value( $value );
				continue;
			}

			$output[ $key ] = $value;
		}

		return $output;
	}

	/**
	 * Apply string-level scrubs: key patterns + oversize truncation.
	 *
	 * @param string $value Input string.
	 * @return string Scrubbed string.
	 */
	private function scrub_string_value( string $value ): string {
		$value = \preg_replace( self::ANTHROPIC_KEY_PATTERN, 'sk-ant-***', $value );
		$value = \preg_replace( self::OPENAI_KEY_PATTERN, 'sk-***', $value );

		if ( \strlen( $value ) > self::MAX_VALUE_BYTES ) {
			$head    = \substr( $value, 0, self::TRUNCATION_HEAD_BYTES );
			$tail    = \substr( $value, -self::TRUNCATION_TAIL_BYTES );
			$dropped = \strlen( $value ) - self::TRUNCATION_HEAD_BYTES - self::TRUNCATION_TAIL_BYTES;
			$value   = $head . ' [TRUNCATED ' . $dropped . ' bytes] ' . $tail;
		}

		return $value;
	}
}
