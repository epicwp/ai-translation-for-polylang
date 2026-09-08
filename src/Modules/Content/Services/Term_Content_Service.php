<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Services\Traits\Reference_Parsing_Trait;
use PLLAT\Translator\Models\Translatables\Translatable_Term;

/**
 * Handles updates for term content.
 */
class Term_Content_Service {
	use Reference_Parsing_Trait;

	/**
	 * Constructor.
	 *
	 * @param Language_Manager $language_manager Language manager.
	 */
	public function __construct(
		private Language_Manager $language_manager,
	) {
	}

	/**
	 * Update a term field based on a reference key.
	 * Delegates to core/meta/custom handlers.
	 *
	 * @param int    $term_id     Term ID.
	 * @param string $reference   Reference key (core|_meta|*_custom_data*).
	 * @param string $translation Translated value.
	 * @return mixed Result from update operation (term data array, meta ID, WP_Error, or null on failure).
	 */
	public function update_term_field( int $term_id, string $reference, string $translation ) {
		$reference_info = $this->parse_reference( $reference );

		/**
		 * Fires before updating a term field with translated content.
		 *
		 * @param int    $term_id     Term ID being updated.
		 * @param string $reference   Full reference key (e.g., "core|name", "_meta|_yoast_wpseo_title").
		 * @param string $translation Translated value.
		 * @param string $field_type  Type of field: 'core', 'meta', 'custom_data', or 'unknown'.
		 * @param string $field_name  Field name (e.g., "name", "_yoast_wpseo_title").
		 */
		\do_action(
			'pllat_before_update_term_field',
			$term_id,
			$reference,
			$translation,
			$reference_info['type'],
			$reference_info['field'],
		);

		$result = null;

		switch ( $reference_info['type'] ) {
			case 'core':
				$result = $this->update_core_term_field( $term_id, $reference_info['field'], $translation );
				break;
			case 'meta':
				/**
				 * Filters the callable used to update term meta.
				 *
				 * This allows integrations to use their own meta update functions
				 * (e.g., update_field for ACF) while maintaining consistent parameter order.
				 *
				 * The callable must accept: ($term_id, $meta_key, $value)
				 *
				 * @param callable $callable    The update function (default: update_term_meta wrapper).
				 * @param int      $term_id     Term ID.
				 * @param string   $meta_key    Meta key being updated.
				 * @param mixed    $translation The translated value.
				 */
				$meta_updater = \apply_filters(
					'pllat_term_meta_update_function',
					static fn( $term_id, $meta_key, $value ) => \update_term_meta(
						$term_id,
						$meta_key,
						$value,
					),
					$term_id,
					$reference_info['field'],
					$translation,
				);

				// Call the update function (update_term_meta or integration-specific function).
				// Unserialize so WordPress can properly handle array storage.
				// Translations are stored serialized in task table (TEXT column), but update_term_meta()
				// expects the actual value type - it handles serialization internally.
				$result = $meta_updater( $term_id, $reference_info['field'], \maybe_unserialize( $translation ) );
				break;
			case 'custom_data':
				\do_action(
					'pllat_update_custom_term_field',
					$term_id,
					$reference_info['field'],
					$translation,
				);
				break;
			default:
				\do_action( 'pllat_update_unknown_term_field', $term_id, $reference, $translation );
		}

		/**
		 * Fires after updating a term field with translated content.
		 *
		 * @param int    $term_id     Term ID that was updated.
		 * @param string $reference   Full reference key.
		 * @param string $translation Translated value.
		 * @param string $field_type  Type of field: 'core', 'meta', 'custom_data', or 'unknown'.
		 * @param string $field_name  Field name.
		 * @param mixed  $result      Result from update operation (term ID, meta ID, WP_Error, or null).
		 */
		\do_action(
			'pllat_after_update_term_field',
			$term_id,
			$reference,
			$translation,
			$reference_info['type'],
			$reference_info['field'],
			$result,
		);

		return $result;
	}

	/**
	 * Get the value of a term field by reference.
	 * Mirror of update_term_field() but for reading.
	 *
	 * @param int    $term_id   The term ID.
	 * @param string $reference The field reference.
	 * @return string|null The field value, or null if not found.
	 * @throws \Exception If term not found.
	 */
	public function get_term_field( int $term_id, string $reference ): ?string {
		$term = \get_term( $term_id );

		if ( \is_wp_error( $term ) || ! $term ) {
			throw new \Exception( \sprintf( 'Term %d no longer exists', (int) $term_id ) );
		}

		$parsed = $this->parse_reference( $reference );

		// Core fields.
		if ( 'core' === $parsed['type'] ) {
			$field = $parsed['field'];
			if ( \property_exists( $term, $field ) ) {
				return (string) $term->$field;
			}
		}

		// Meta fields.
		if ( 'meta' === $parsed['type'] ) {
			$translatable = Translatable_Term::get_instance( $term_id );
			$value        = $translatable->get_meta( $parsed['field'], true );
			if ( ! $value ) {
				return null;
			}
			return \maybe_serialize( $value );
		}

		/**
		 * Allow integrations to provide custom field values.
		 *
		 * @param string|null $value     The resolved value.
		 * @param int         $term_id   Term ID.
		 * @param string      $reference Field reference.
		 * @param array       $parsed    Parsed reference (type, field).
		 */
		return \apply_filters( 'pllat_get_term_field', null, $term_id, $reference, $parsed );
	}

	/**
	 * Update one of the supported core term fields.
	 *
	 * Uses Polylang's PLL()->model->term->update() instead of raw wp_update_term().
	 * This is critical because Polylang's wrapper temporarily registers the
	 * `pll_inserted_term_language` and `pll_inserted_term_parent` filters that
	 * PLL_Share_Term_Slug needs to suffix the slug during uniqueness checks.
	 * Without these filters (e.g. in Action Scheduler context), wp_update_term()
	 * fails with `duplicate_term_slug` when source and target terms share a slug.
	 *
	 * @param int    $term_id     Term ID.
	 * @param string $field       Core field name.
	 * @param string $translation Translated value.
	 * @return array|null|\WP_Error Array of term ID and taxonomy on success, WP_Error on failure, null if skipped.
	 */
	public function update_core_term_field( int $term_id, string $field, string $translation ) {
		$update_data = array();
		switch ( $field ) {
			case 'name':
				$update_data['name'] = $translation;
				break;
			case 'description':
				$update_data['description'] = $translation;
				break;
			case 'slug':
				$update_data['slug'] = \sanitize_title( $translation );
				break;
			default:
				\do_action( 'pllat_update_unknown_core_term_field', $term_id, $field, $translation );
				return null;
		}
		if ( \count( $update_data ) <= 0 ) {
			return null;
		}

		// Polylang's update() calls get_language() internally, which can return false
		// if the term has no language in the object cache (e.g. Action Scheduler context).
		// When that happens, toggle_inserted_term_filters() receives false instead of
		// PLL_Language, causing a TypeError. Pre-check and bail with a clear error.
		$language = \PLL()->model->term->get_language( $term_id );
		if ( ! $language instanceof \PLL_Language ) {
			$error = new \WP_Error(
				'pllat_term_no_language',
				\sprintf( 'Term #%d has no language assigned — cannot update via Polylang API.', $term_id ),
			);
			\do_action( 'pllat_term_update_failed', $term_id, $field, $error );
			return $error;
		}

		$result = \PLL()->model->term->update( $term_id, $update_data );

		if ( \is_wp_error( $result ) ) {
			\do_action( 'pllat_term_update_failed', $term_id, $field, $result );
		}

		return $result;
	}

	/**
	 * Resolve or infer a target term ID for the given language.
	 * Creates a new term if translation doesn't exist (same behavior as posts).
	 *
	 * @param int    $source_id   Source term ID.
	 * @param string $target_lang Target language.
	 * @return int Target term ID.
	 */
	public function resolve_target_id( int $source_id, string $target_lang ): int {
		$target_id = \pll_get_term( $source_id, $target_lang );
		if ( $target_id ) {
			return (int) $target_id;
		}

		// Create term if it doesn't exist.
		$new_target = (int) $this->language_manager->copy_term( $source_id, $target_lang );

		if ( $new_target <= 0 ) {
			return 0;
		}

		// Verify the translation link was established.
		$linked = \pll_get_term( $source_id, $target_lang );

		if ( ! $linked || (int) $linked !== $new_target ) {
			\wp_delete_term( $new_target, \get_term( $new_target )->taxonomy ?? '' );
			\do_action(
				'pllat_log_error',
				\sprintf(
					'Deleted orphaned translation term #%d: Polylang linking failed for source #%d → %s.',
					$new_target,
					$source_id,
					$target_lang,
				),
			);
			return 0;
		}

		return $new_target;
	}
}
