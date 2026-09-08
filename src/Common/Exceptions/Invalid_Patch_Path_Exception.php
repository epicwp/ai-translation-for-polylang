<?php
/**
 * Invalid_Patch_Path_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a JSON patch op references a path that does not resolve
 * against the target document. Carries the offending path so debug
 * logs are actionable.
 */
class Invalid_Patch_Path_Exception extends \RuntimeException {

	public function __construct( public readonly string $path, string $reason = '' ) {
		parent::__construct(
			\sprintf(
				'Invalid JSON patch path "%s"%s',
				$path,
				'' === $reason ? '.' : ': ' . $reason,
			),
		);
	}
}
