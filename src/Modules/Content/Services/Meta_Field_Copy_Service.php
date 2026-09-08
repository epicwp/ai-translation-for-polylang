<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Copies "copy" classified meta fields from source to target post
 * when they are missing on the target (backfill for existing translations).
 */
class Meta_Field_Copy_Service {

	public function __construct(
		private readonly Meta_Field_Classification_Service $classification_service,
	) {}

	/**
	 * Backfill missing copy fields on a target post from the source.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target (translation) post ID.
	 * @param string $post_type Post type slug.
	 */
	public function backfill_copy_fields( int $source_id, int $target_id, string $post_type ): void {
		$copy_keys = $this->classification_service->get_copy_keys( $post_type );

		if ( \count( $copy_keys ) === 0 ) {
			return;
		}

		foreach ( $copy_keys as $key ) {
			$target_value = \get_post_meta( $target_id, $key, true );

			// Only copy if missing on target (empty string means not set or explicitly empty).
			if ( $target_value !== '' && $target_value !== false ) {
				continue;
			}

			$source_value = \get_post_meta( $source_id, $key, true );

			if ( $source_value === '' || $source_value === false ) {
				continue;
			}

			\update_post_meta( $target_id, $key, $source_value );
		}
	}

	/**
	 * Backfill missing copy fields on a target term from the source.
	 *
	 * @param int    $source_id Source term ID.
	 * @param int    $target_id Target (translation) term ID.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public function backfill_term_copy_fields( int $source_id, int $target_id, string $taxonomy ): void {
		$entity_key = Meta_Field_Classification_Service::taxonomy_key( $taxonomy );
		$copy_keys  = $this->classification_service->get_copy_keys( $entity_key );

		if ( \count( $copy_keys ) === 0 ) {
			return;
		}

		foreach ( $copy_keys as $key ) {
			$target_value = \get_term_meta( $target_id, $key, true );

			// Only copy if missing on target.
			if ( $target_value !== '' && $target_value !== false ) {
				continue;
			}

			$source_value = \get_term_meta( $source_id, $key, true );

			if ( $source_value === '' || $source_value === false ) {
				continue;
			}

			\update_term_meta( $target_id, $key, $source_value );
		}
	}
}
