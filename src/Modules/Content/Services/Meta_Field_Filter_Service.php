<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Filters meta keys to identify which ones need AI classification.
 * Removes WP internals, integration-owned fields, and already-classified keys.
 */
class Meta_Field_Filter_Service {

	/**
	 * WP internal key prefixes to always exclude.
	 *
	 * Public so Meta_Field_Handler can reuse this list to strip the same
	 * keys from Polylang's `pll_copy_post_metas` filter (Polylang Pro's
	 * default copies ALL custom fields except _edit_last/_edit_lock).
	 */
	public const WP_INTERNAL_PREFIXES = array(
		'_edit_',
		'_wp_old_',
		'_oembed_',
		'_transient_',
		'_wp_trash_',
	);

	/**
	 * WP internal exact matches to always exclude.
	 *
	 * Public so Meta_Field_Handler can reuse this list — see WP_INTERNAL_PREFIXES.
	 */
	public const WP_INTERNAL_EXACT = array(
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_wp_page_template',
		'_thumbnail_id',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_is_custom_header',
	);

	/**
	 * Page-builder meta key prefixes.
	 *
	 * Public so Meta_Field_Handler copies every key under them as-is when
	 * Polylang creates a translation (the builder integrations, pro, overwrite
	 * the content key with its translation afterwards; free keeps the copy).
	 * Never AI-classified in either edition.
	 */
	public const BUILDER_PREFIXES = array(
		'_elementor_',
		'_bricks_',
	);

	/**
	 * Integration-owned exact keys to exclude: translated from the allowlist by
	 * their module (pro) or skipped (free), never AI-classified.
	 */
	private const INTEGRATION_EXACT = array(
		// WooCommerce (WooCommerce_Meta_Handler).
		'_variation_description',
		'_purchase_note',
		'_button_text',
	);

	/**
	 * Integration-owned key prefixes to exclude (handled by dedicated integrations).
	 */
	private const INTEGRATION_PREFIXES = array(
		// ACF field reference keys.
		'field_',
		'_field_',

		// Polylang internals.
		'_pll_',
		'pll_',

		// PLLAT internals.
		'_pllat_',
		'pllat_',
	);

	/**
	 * SEO plugin key prefixes, excluded from the AI scan by default.
	 *
	 * Public so the SEO module can lift the exclusion through
	 * `pllat_meta_scan_excluded_prefixes`: with the module the scan
	 * classifies the SEO keys that are not on the allowlist as before,
	 * without it (free) SEO meta is never offered.
	 */
	public const SEO_PREFIXES = array(
		'_yoast_wpseo_',
		'wpseo_',
		'rank_math_',
		'_aioseo_',
		'_seopress_',
		'slim_seo_',
		'_sq_',
		'_genesis_',
		'_open_graph_',
		'_twitter_',
		'_tsf_',
		'_kiss_seo_',
		'_mwseo_',
	);

	/**
	 * Filter a list of meta keys down to only unclassified, non-internal, non-integration keys.
	 *
	 * @param string[] $all_keys       All meta keys from the database.
	 * @param string[] $classified     Keys already in translate/copy/ignore lists.
	 * @param string[] $builtin_defaults Keys from Translation_Post_Meta::get_available_fields().
	 * @param string[] $user_custom    Keys manually added by user in settings.
	 * @return string[] Keys that need AI classification.
	 */
	public function filter_unclassified(
		array $all_keys,
		array $classified,
		array $builtin_defaults,
		array $user_custom
	): array {
		$exclude_set       = \array_flip(
			\array_merge( $classified, $builtin_defaults, $user_custom, self::WP_INTERNAL_EXACT, self::INTEGRATION_EXACT ),
		);
		$excluded_prefixes = $this->excluded_prefixes();

		$result = array();

		foreach ( $all_keys as $key ) {
			if ( isset( $exclude_set[ $key ] ) ) {
				continue;
			}

			if ( $this->matches_prefix( $key, self::WP_INTERNAL_PREFIXES ) ) {
				continue;
			}

			if ( $this->matches_prefix( $key, $excluded_prefixes ) ) {
				continue;
			}

			$result[] = $key;
		}

		return \array_values( $result );
	}

	/**
	 * Key prefixes the AI scan skips: the page-builder and integration-owned
	 * ones and, unless a module lifts them, the SEO plugin ones.
	 *
	 * @return string[]
	 */
	public function excluded_prefixes(): array {
		/**
		 * Filters the meta key prefixes the AI meta scan never offers for classification.
		 *
		 * @param string[] $prefixes Key prefixes to exclude.
		 */
		return \apply_filters(
			'pllat_meta_scan_excluded_prefixes',
			\array_merge( self::BUILDER_PREFIXES, self::INTEGRATION_PREFIXES, self::SEO_PREFIXES ),
		);
	}

	private function matches_prefix( string $key, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( \str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
