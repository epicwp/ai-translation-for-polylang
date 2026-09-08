<?php
/**
 * Field_Validator_Interface file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services\Field_Validators;

\defined( 'ABSPATH' ) || exit;

/**
 * Interface for field validators.
 *
 * Field validators determine which fields in a JSON structure should be translated
 * based on key patterns and value content.
 */
interface Field_Validator_Interface {
	/**
	 * Determine if a field should be translated.
	 *
	 * @param string $key The field key/name.
	 * @param mixed  $value The field value.
	 * @return bool True if the field should be translated, false otherwise.
	 */
	public function should_translate( string $key, mixed $value ): bool;
}
