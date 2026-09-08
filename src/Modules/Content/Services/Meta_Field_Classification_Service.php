<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves AI-explored meta field classifications per post type.
 *
 * Three classification types:
 * - translate: human-readable text, sent to AI for translation
 * - copy: structural/config field, copied as-is via Polylang's pll_copy_post_metas hook
 * - ignore: internal/technical data, not relevant for translation
 */
class Meta_Field_Classification_Service {

	private const TAXONOMY_PREFIX = 'tax__';

	private const OPTION_TRANSLATE = 'pllat_meta_translate_keys';
	private const OPTION_COPY      = 'pllat_meta_copy_keys';
	private const OPTION_IGNORE    = 'pllat_meta_ignore_keys';

	private const TYPE_MAP = array(
		'translate' => self::OPTION_TRANSLATE,
		'copy'      => self::OPTION_COPY,
		'ignore'    => self::OPTION_IGNORE,
	);

	/**
	 * Build the namespaced storage key for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string Prefixed key safe for sanitize_key().
	 */
	public static function taxonomy_key( string $taxonomy ): string {
		return self::TAXONOMY_PREFIX . $taxonomy;
	}

	/**
	 * Check whether a storage key represents a taxonomy (vs. a post type).
	 *
	 * @param string $key Entity key from classification storage.
	 * @return bool
	 */
	public static function is_taxonomy_key( string $key ): bool {
		return \str_starts_with( $key, self::TAXONOMY_PREFIX );
	}

	/**
	 * Strip the taxonomy prefix to recover the raw taxonomy slug.
	 *
	 * @param string $key Prefixed taxonomy key.
	 * @return string Raw taxonomy slug.
	 */
	public static function strip_taxonomy_prefix( string $key ): string {
		return \substr( $key, \strlen( self::TAXONOMY_PREFIX ) );
	}

	/**
	 * Get all translate-classified keys for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Meta key names.
	 */
	public function get_translate_keys( string $post_type ): array {
		return $this->get_keys_for_type( 'translate', $post_type );
	}

	/**
	 * Get all copy-classified keys for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Meta key names.
	 */
	public function get_copy_keys( string $post_type ): array {
		return $this->get_keys_for_type( 'copy', $post_type );
	}

	/**
	 * Get all ignore-classified keys for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Meta key names.
	 */
	public function get_ignore_keys( string $post_type ): array {
		return $this->get_keys_for_type( 'ignore', $post_type );
	}

	/**
	 * Store a batch of classifications for a post type. Merges with existing.
	 *
	 * @param string              $post_type       Post type slug.
	 * @param array<string,string> $classifications Map of meta_key => 'translate'|'copy'|'ignore'.
	 */
	public function store_classifications( string $post_type, array $classifications ): void {
		foreach ( self::TYPE_MAP as $type => $option ) {
			$keys_to_add = \array_keys( \array_filter( $classifications, fn( $c ) => $c === $type ) );

			if ( \count( $keys_to_add ) === 0 ) {
				continue;
			}

			$existing = $this->get_option_data( $option );
			$current  = $existing[ $post_type ] ?? array();
			$merged   = \array_values( \array_unique( \array_merge( $current, $keys_to_add ) ) );

			$existing[ $post_type ] = $merged;
			\update_option( $option, $existing, false );
		}
	}

	/**
	 * Check if a key has been classified (in any list) for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $meta_key  Meta key name.
	 * @return bool
	 */
	public function is_classified( string $post_type, string $meta_key ): bool {
		return \in_array( $meta_key, $this->get_all_classified_keys( $post_type ), true );
	}

	/**
	 * Get union of all classified keys for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	public function get_all_classified_keys( string $post_type ): array {
		return \array_values( \array_unique( \array_merge(
			$this->get_translate_keys( $post_type ),
			$this->get_copy_keys( $post_type ),
			$this->get_ignore_keys( $post_type ),
		) ) );
	}

	/**
	 * Move a key from its current classification to a new one.
	 *
	 * @param string $post_type          Post type slug.
	 * @param string $meta_key           Meta key name.
	 * @param string $new_classification 'translate', 'copy', or 'ignore'.
	 */
	public function reclassify( string $post_type, string $meta_key, string $new_classification ): void {
		$this->remove_key( $post_type, $meta_key );
		$this->store_classifications( $post_type, array( $meta_key => $new_classification ) );
	}

	/**
	 * Remove a key from all classification lists.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $meta_key  Meta key name.
	 */
	public function remove_key( string $post_type, string $meta_key ): void {
		foreach ( self::TYPE_MAP as $option ) {
			$data = $this->get_option_data( $option );

			if ( ! isset( $data[ $post_type ] ) ) {
				continue;
			}

			$data[ $post_type ] = \array_values( \array_filter(
				$data[ $post_type ],
				fn( $k ) => $k !== $meta_key,
			) );

			\update_option( $option, $data, false );
		}
	}

	/**
	 * Get all copy keys across all post types (for pll_copy_post_metas filter).
	 *
	 * @return string[] Flat array of unique copy keys.
	 */
	public function get_all_copy_keys(): array {
		$data = $this->get_option_data( self::OPTION_COPY );
		$all  = array();

		foreach ( $data as $keys ) {
			$all = \array_merge( $all, $keys );
		}

		return \array_values( \array_unique( $all ) );
	}

	/**
	 * Get all translate keys across all post types (for allowed fields filter).
	 *
	 * @return string[] Flat array of unique translate keys.
	 */
	public function get_all_translate_keys(): array {
		$data = $this->get_option_data( self::OPTION_TRANSLATE );
		$all  = array();

		foreach ( $data as $keys ) {
			$all = \array_merge( $all, $keys );
		}

		return \array_values( \array_unique( $all ) );
	}

	/**
	 * Get all ignore keys across all post types.
	 *
	 * @return string[] Flat array of unique ignore keys.
	 */
	public function get_all_ignore_keys(): array {
		$data = $this->get_option_data( self::OPTION_IGNORE );
		$all  = array();

		foreach ( $data as $keys ) {
			$all = \array_merge( $all, $keys );
		}

		return \array_values( \array_unique( $all ) );
	}

	/**
	 * Get all classifications grouped by post type.
	 *
	 * @return array<string, array{translate: string[], copy: string[], ignore: string[]}>
	 */
	public function get_grouped_classifications(): array {
		$translate = $this->get_option_data( self::OPTION_TRANSLATE );
		$copy      = $this->get_option_data( self::OPTION_COPY );
		$ignore    = $this->get_option_data( self::OPTION_IGNORE );

		$post_types = \array_unique( \array_merge(
			\array_keys( $translate ),
			\array_keys( $copy ),
			\array_keys( $ignore ),
		) );

		\sort( $post_types );

		$grouped = array();
		foreach ( $post_types as $pt ) {
			$grouped[ $pt ] = array(
				'translate' => $translate[ $pt ] ?? array(),
				'copy'      => $copy[ $pt ] ?? array(),
				'ignore'    => $ignore[ $pt ] ?? array(),
			);
		}

		return $grouped;
	}

	/**
	 * @return string[]
	 */
	private function get_keys_for_type( string $type, string $post_type ): array {
		$data = $this->get_option_data( self::TYPE_MAP[ $type ] );
		return $data[ $post_type ] ?? array();
	}

	/**
	 * @return array<string, string[]>
	 */
	private function get_option_data( string $option ): array {
		$data = \get_option( $option, array() );
		return \is_array( $data ) ? $data : array();
	}
}
