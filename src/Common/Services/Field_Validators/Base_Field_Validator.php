<?php
/**
 * Base_Field_Validator class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services\Field_Validators;

\defined( 'ABSPATH' ) || exit;

/**
 * Base implementation of field validator with pattern-based validation.
 *
 * Contains COMMON validation rules shared across all page builders (Elementor, Bricks, etc.).
 * Subclasses can extend these with builder-specific additions.
 *
 * Implements the validation flow:
 * 1. Check meta fields (underscore prefix) → skip
 * 2. Check exact translatable keys → translate
 * 3. Check exact non-translatable keys → skip
 * 4. Check non-translatable patterns → skip
 * 5. Check translatable patterns → translate
 * 6. Default → skip
 *
 * Additionally validates values to exclude:
 * - Empty strings
 * - URLs (http://, https://)
 * - Hex colors (#)
 */
class Base_Field_Validator implements Field_Validator_Interface {
	/**
	 * Keys that should always be translated (exact match).
	 * Common across all page builders.
	 *
	 * @var array<string>
	 */
	protected array $translatable_keys = array(
		'editor', // WYSIWYG editor field (common in WP page builders).
	);

	/**
	 * Keys that should never be translated (exact match).
	 * Technical/structural fields common across all page builders.
	 *
	 * @var array<string>
	 */
	protected array $non_translatable_keys = array(
		'type',
		'id',
		'className',
		'style',
		'version',
		'size',
		'unit',
	);

	/**
	 * Patterns that if found in the key indicate non-translatable content.
	 * Common styling/layout patterns across all page builders.
	 *
	 * @var array<string>
	 */
	protected array $non_translatable_patterns = array(
		'align',
		'position',
		'width',
		'height',
		'spacing',
		'padding',
		'margin',
		'border',
		'color',
		'background',
		'layout',
		'style',
		'url',
		'link',
		'href',
		'animation',
		'transition',
		'transform',
		'gradient',
		'opacity',
		'shadow',
		'radius',
		'weight',
		'font',
		'typography',
		'order',
		'size',
		'orientation',
		'direction',
		'flex',
		'grid',
		'gap',
		'offset',
		'columns',
		'motion',
		'custom',
		'default',
		'active',
		'hover',
		'focus',
		'disabled',
		'selected',
		'html_tag',
		'separator',
	);

	/**
	 * Patterns that if found in the key indicate translatable content.
	 * Common user-facing text patterns across all page builders.
	 *
	 * @var array<string>
	 */
	protected array $translatable_patterns = array(
		'text',
		'title',
		'heading',
		'content',
		'description',
		'subtitle',
		'caption',
		'excerpt',
		'label',
		'placeholder',
		'button',
		'message',
		'author',
		'name',
		'quote',
		'alt',
		'value',
		'question',
		'answer',
		'link',
		'testimonial',
		'info',
		'instructions',
		'review',
		'cta',
	);

	/**
	 * Constructor allows subclasses to extend the base arrays with builder-specific fields.
	 *
	 * @param array<string> $additional_translatable_keys Builder-specific translatable keys to add.
	 * @param array<string> $additional_non_translatable_keys Builder-specific non-translatable keys to add.
	 * @param array<string> $additional_non_translatable_patterns Builder-specific non-translatable patterns to add.
	 * @param array<string> $additional_translatable_patterns Builder-specific translatable patterns to add.
	 */
	public function __construct(
		array $additional_translatable_keys = array(),
		array $additional_non_translatable_keys = array(),
		array $additional_non_translatable_patterns = array(),
		array $additional_translatable_patterns = array()
	) {
		// Merge base arrays with subclass additions.
		if ( \count( $additional_translatable_keys ) > 0 ) {
			$this->translatable_keys = \array_merge( $this->translatable_keys, $additional_translatable_keys );
		}
		if ( \count( $additional_non_translatable_keys ) > 0 ) {
			$this->non_translatable_keys = \array_merge( $this->non_translatable_keys, $additional_non_translatable_keys );
		}
		if ( \count( $additional_non_translatable_patterns ) > 0 ) {
			$this->non_translatable_patterns = \array_merge( $this->non_translatable_patterns, $additional_non_translatable_patterns );
		}
		if ( \count( $additional_translatable_patterns ) > 0 ) {
			$this->translatable_patterns = \array_merge( $this->translatable_patterns, $additional_translatable_patterns );
		}
	}

	/**
	 * Determines if a field should be translated based on key validation rules.
	 *
	 * @param string $key The field key.
	 * @param mixed  $value The field value.
	 * @return bool True if should be translated.
	 */
	public function should_translate( string $key, mixed $value ): bool {
		// Must be a non-empty string.
		if ( ! \is_string( $value ) || '' === \trim( $value ) ) {
			return false;
		}

		// Skip URLs.
		if ( \str_starts_with( $value, 'http://' ) || \str_starts_with( $value, 'https://' ) ) {
			return false;
		}

		// Skip hex colors.
		if ( \str_starts_with( $value, '#' ) ) {
			return false;
		}

		// Skip namespace identifiers (e.g., acf/hero, core/paragraph, woocommerce/product-grid).
		if ( \preg_match( '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $value ) ) {
			return false;
		}

		// 1. Check meta fields (underscore prefix) — use original key.
		if ( $this->is_meta_field( $key ) ) {
			return false;
		}

		// Extract leaf key for ACF repeater fields (e.g. columns_0_heading → heading).
		$effective_key = $this->get_effective_key( $key );

		// 2. Check exact translatable keys.
		if ( $this->is_translatable_key( $effective_key ) ) {
			return true;
		}

		// 3. Check exact non-translatable keys.
		if ( $this->is_non_translatable_key( $effective_key ) ) {
			return false;
		}

		// 4. Check non-translatable patterns.
		if ( $this->matches_non_translatable_pattern( $effective_key ) ) {
			return false;
		}

		// 5. Check translatable patterns.
		if ( $this->matches_translatable_pattern( $effective_key ) ) {
			return true;
		}

		// 6. Default: do not translate.
		return false;
	}

	/**
	 * Extract the leaf field name from ACF repeater-style keys.
	 *
	 * ACF repeater/flexible content fields are stored as flat keys in block
	 * attributes using the pattern {parent}_{index}_{field}. This method
	 * iteratively strips these prefixes to get the actual field name.
	 *
	 * Examples:
	 * - columns_0_heading → heading
	 * - sections_0_columns_1_heading → heading
	 * - background_populate → background_populate (no match, returned as-is)
	 *
	 * @param string $key The field key.
	 * @return string The leaf field name.
	 */
	private function get_effective_key( string $key ): string {
		while ( \preg_match( '/^.+?_(\d+)_(.+)$/', $key, $matches ) ) {
			$key = $matches[2];
		}

		return $key;
	}

	/**
	 * Check if the key is a meta field (starts with underscore).
	 *
	 * @param string $key The field key.
	 * @return bool True if meta field.
	 */
	private function is_meta_field( string $key ): bool {
		return \str_starts_with( $key, '_' );
	}

	/**
	 * Check if the key is in the exact translatable keys list.
	 *
	 * @param string $key The field key.
	 * @return bool True if translatable.
	 */
	private function is_translatable_key( string $key ): bool {
		return \in_array( $key, $this->translatable_keys, true );
	}

	/**
	 * Check if the key is in the exact non-translatable keys list.
	 *
	 * @param string $key The field key.
	 * @return bool True if non-translatable.
	 */
	private function is_non_translatable_key( string $key ): bool {
		return \in_array( $key, $this->non_translatable_keys, true );
	}

	/**
	 * Check if the key matches any non-translatable pattern (case-insensitive substring).
	 *
	 * @param string $key The field key.
	 * @return bool True if matches non-translatable pattern.
	 */
	private function matches_non_translatable_pattern( string $key ): bool {
		$lower_key = \strtolower( $key );
		foreach ( $this->non_translatable_patterns as $pattern ) {
			if ( \str_contains( $lower_key, \strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the key matches any translatable pattern (case-insensitive substring).
	 *
	 * @param string $key The field key.
	 * @return bool True if matches translatable pattern.
	 */
	private function matches_translatable_pattern( string $key ): bool {
		$lower_key = \strtolower( $key );
		foreach ( $this->translatable_patterns as $pattern ) {
			if ( \str_contains( $lower_key, \strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}
}
