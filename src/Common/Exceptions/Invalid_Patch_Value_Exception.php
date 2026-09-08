<?php
/**
 * Invalid_Patch_Value_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a JSON patch op carries a value of the wrong type
 * (AI translations must always be strings).
 */
class Invalid_Patch_Value_Exception extends \RuntimeException {

	public function __construct( public readonly string $path, public readonly string $expected_type ) {
		parent::__construct(
			\sprintf(
				'Invalid JSON patch value at "%s": expected %s.',
				$path,
				$expected_type,
			),
		);
	}
}
