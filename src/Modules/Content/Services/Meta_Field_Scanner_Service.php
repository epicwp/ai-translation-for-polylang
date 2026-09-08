<?php
/**
 * Meta_Field_Scanner_Service class file.
 *
 * Scans meta fields for a post type, sends unclassified keys to AI for classification,
 * and stores the results via Meta_Field_Classification_Service.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Models\Translations\Translation_Post_Meta;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;
use PLLAT\Translator\Services\AI_Client;
use PLLAT\Translator\Services\AI_Provider_Factory;

/**
 * Orchestrates AI-powered meta field classification scanning.
 */
class Meta_Field_Scanner_Service {

	private const SYSTEM_PROMPT = 'You are a WordPress meta field classifier. For each meta field, classify it as:' . "\n"
		. '- "translate": human-readable text that should be translated (titles, descriptions, labels, content)' . "\n"
		. '- "copy": everything else — structural values, config, IDs, booleans, settings, timestamps, serialized data' . "\n"
		. "\n"
		. 'Fields whose values indicate a rendering mode or content format (e.g., "text", "html", "block", "video", "image", "shortcode") should be "copy" — they control how translated content is displayed.' . "\n"
		. 'When unsure: prefer "copy" over "translate" (translating config values corrupts data). Only classify as "translate" when the value is clearly human-readable text.' . "\n"
		. 'Respond with ONLY a JSON object: { "meta_key": "classification", ... }';

	private const VALID_CLASSIFICATIONS = array( 'translate', 'copy' );

	/**
	 * Constructor.
	 *
	 * @param Meta_Field_Classification_Service $classification Classification storage service.
	 * @param Meta_Field_Filter_Service         $filter         Filter service for excluding known keys.
	 * @param Settings_Service                  $settings       Plugin settings.
	 */
	public function __construct(
		private readonly Meta_Field_Classification_Service $classification,
		private readonly Meta_Field_Filter_Service $filter,
		private readonly Settings_Service $settings,
	) {}

	/**
	 * Get the classification service instance.
	 *
	 * @return Meta_Field_Classification_Service
	 */
	public function get_classification_service(): Meta_Field_Classification_Service {
		return $this->classification;
	}

	/**
	 * Run a full scan for a post type. Fetches all meta keys, filters to unclassified,
	 * sends to AI for classification, and stores results.
	 *
	 * @param string $post_type Post type slug.
	 * @param bool   $force     If true, re-scan even if no new unclassified keys exist.
	 * @return Meta_Field_Scan_Result Scan results.
	 */
	public function scan( string $post_type, bool $force = false ): Meta_Field_Scan_Result {
		$unclassified = $this->get_unclassified_keys( $post_type );

		if ( \count( $unclassified ) === 0 && ! $force ) {
			return $this->build_empty_result( $post_type );
		}

		// If force but no unclassified keys, nothing to classify.
		if ( \count( $unclassified ) === 0 ) {
			return $this->build_empty_result( $post_type );
		}

		$client = $this->create_ai_client();
		if ( null === $client ) {
			return $this->build_empty_result( $post_type );
		}

		$samples         = $this->fetch_sample_values( $post_type, $unclassified );
		$messages        = $this->build_classification_prompt( $samples );
		$classifications = $this->execute_classification( $client, $messages );

		if ( \count( $classifications ) === 0 ) {
			return $this->build_empty_result( $post_type );
		}

		$this->classification->store_classifications( $post_type, $classifications );

		return $this->build_result( $post_type, $classifications );
	}

	/**
	 * Quick check: only scan if there are new unclassified keys for the post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return Meta_Field_Scan_Result Scan results (empty if no new keys).
	 */
	public function scan_if_new_keys( string $post_type ): Meta_Field_Scan_Result {
		$unclassified = $this->get_unclassified_keys( $post_type );

		if ( \count( $unclassified ) === 0 ) {
			return $this->build_empty_result( $post_type );
		}

		return $this->scan( $post_type );
	}

	/**
	 * Run a full scan for a taxonomy. Fetches all term meta keys, filters to unclassified,
	 * sends to AI for classification, and stores results under the taxonomy-prefixed key.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param bool   $force    If true, re-scan even if no new unclassified keys exist.
	 * @return Meta_Field_Scan_Result Scan results.
	 */
	public function scan_taxonomy( string $taxonomy, bool $force = false ): Meta_Field_Scan_Result {
		$entity_key   = Meta_Field_Classification_Service::taxonomy_key( $taxonomy );
		$unclassified = $this->get_unclassified_term_keys( $taxonomy );

		if ( \count( $unclassified ) === 0 ) {
			return $this->build_empty_result( $entity_key );
		}

		$client = $this->create_ai_client();
		if ( null === $client ) {
			return $this->build_empty_result( $entity_key );
		}

		$samples         = $this->fetch_term_meta_sample_values( $taxonomy, $unclassified );
		$messages        = $this->build_classification_prompt( $samples );
		$classifications = $this->execute_classification( $client, $messages );

		if ( \count( $classifications ) === 0 ) {
			return $this->build_empty_result( $entity_key );
		}

		$this->classification->store_classifications( $entity_key, $classifications );

		return $this->build_result( $entity_key, $classifications );
	}

	/**
	 * Fetch all distinct meta keys for a post type from the database.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] All meta key names.
	 */
	private function fetch_meta_keys( string $post_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s
				ORDER BY pm.meta_key",
				$post_type,
			),
		);

		return \is_array( $results ) ? $results : array();
	}

	/**
	 * Fetch sample values for each meta key to help AI classification.
	 *
	 * @param string   $post_type Post type slug.
	 * @param string[] $keys      Meta keys to sample.
	 * @param int      $samples   Number of sample values per key.
	 * @return array<string, string[]> Map of meta_key => array of sample values.
	 */
	private function fetch_sample_values( string $post_type, array $keys, int $samples = 3 ): array {
		global $wpdb;

		$result = array();

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_value
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value != ''
					LIMIT %d",
					$post_type,
					$key,
					$samples,
				),
			);

			$result[ $key ] = \is_array( $values ) ? $values : array();
		}

		return $result;
	}

	/**
	 * Fetch all distinct meta keys for a taxonomy from the term meta table.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[] All term meta key names.
	 */
	private function fetch_term_meta_keys( string $taxonomy ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tm.meta_key
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				WHERE tt.taxonomy = %s
				ORDER BY tm.meta_key",
				$taxonomy,
			),
		);

		return \is_array( $results ) ? $results : array();
	}

	/**
	 * Fetch sample values for each term meta key to help AI classification.
	 *
	 * @param string   $taxonomy Taxonomy slug.
	 * @param string[] $keys     Meta keys to sample.
	 * @param int      $samples  Number of sample values per key.
	 * @return array<string, string[]> Map of meta_key => array of sample values.
	 */
	private function fetch_term_meta_sample_values( string $taxonomy, array $keys, int $samples = 3 ): array {
		global $wpdb;

		$result = array();

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$values = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT tm.meta_value
					FROM {$wpdb->termmeta} tm
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
					WHERE tt.taxonomy = %s AND tm.meta_key = %s AND tm.meta_value != ''
					LIMIT %d",
					$taxonomy,
					$key,
					$samples,
				),
			);

			$result[ $key ] = \is_array( $values ) ? $values : array();
		}

		return $result;
	}

	/**
	 * Get the list of unclassified term meta keys for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[] Unclassified meta keys needing AI classification.
	 */
	private function get_unclassified_term_keys( string $taxonomy ): array {
		$entity_key  = Meta_Field_Classification_Service::taxonomy_key( $taxonomy );
		$all_keys    = $this->fetch_term_meta_keys( $taxonomy );
		$classified  = $this->classification->get_all_classified_keys( $entity_key );
		$builtins    = Translation_Term_Meta::get_available_fields();
		$user_custom = $this->classification->get_translate_keys( $entity_key );

		return $this->filter->filter_unclassified( $all_keys, $classified, $builtins, $user_custom );
	}

	/**
	 * Build chat messages array for the AI classification request.
	 *
	 * @param array<string, string[]> $keys_with_samples Map of meta_key => sample values.
	 * @return array Chat messages for AI_Client::chat_completion().
	 */
	private function build_classification_prompt( array $keys_with_samples ): array {
		$lines = array();

		foreach ( $keys_with_samples as $key => $samples ) {
			$sample_text = \count( $samples ) > 0
				? \implode( ' | ', \array_map( fn( $v ) => \mb_substr( $v, 0, 200 ), $samples ) )
				: '(no values)';

			$lines[] = "- {$key}: {$sample_text}";
		}

		$user_content = "Classify these WordPress meta fields:\n\n" . \implode( "\n", $lines );

		return array(
			array(
				'role'    => 'system',
				'content' => self::SYSTEM_PROMPT,
			),
			array(
				'role'    => 'user',
				'content' => $user_content,
			),
		);
	}

	/**
	 * Parse and validate the AI classification response.
	 *
	 * @param string $content Raw response content from AI.
	 * @return array<string, string> Validated map of meta_key => classification.
	 */
	private function parse_classification_response( string $content ): array {
		$parsed = AI_Client::parse_json_response_content( $content );

		if ( \count( $parsed ) === 0 ) {
			return array();
		}

		$validated = array();

		foreach ( $parsed as $key => $classification ) {
			if ( ! \is_string( $key ) || ! \is_string( $classification ) ) {
				continue;
			}

			// Remap "ignore" to "copy" — missing fields break translations, extra copies are harmless.
			if ( 'ignore' === $classification ) {
				$classification = 'copy';
			}

			if ( ! \in_array( $classification, self::VALID_CLASSIFICATIONS, true ) ) {
				continue;
			}

			$validated[ $key ] = $classification;
		}

		return $validated;
	}

	/**
	 * Get builtin default meta keys that are already handled.
	 *
	 * @return string[] Known meta field keys.
	 */
	private function get_builtin_defaults(): array {
		return Translation_Post_Meta::get_available_fields();
	}

	/**
	 * Get the list of unclassified meta keys for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[] Unclassified meta keys needing AI classification.
	 */
	private function get_unclassified_keys( string $post_type ): array {
		$all_keys    = $this->fetch_meta_keys( $post_type );
		$classified  = $this->classification->get_all_classified_keys( $post_type );
		$builtins    = $this->get_builtin_defaults();
		$user_custom = $this->classification->get_translate_keys( $post_type );

		return $this->filter->filter_unclassified( $all_keys, $classified, $builtins, $user_custom );
	}

	/**
	 * Create an AI client from current settings. Returns null if AI is not configured.
	 *
	 * @return AI_Client|null The AI client, or null if not available.
	 */
	private function create_ai_client(): ?AI_Client {
		if ( ! AI_Provider_Factory::can_create_from_settings( $this->settings ) ) {
			return null;
		}

		try {
			$provider = AI_Provider_Factory::create_from_settings( $this->settings );
			return $provider->get_client();
		} catch ( \Exception ) {
			return null;
		}
	}

	/**
	 * Execute the AI classification call and parse the response.
	 *
	 * @param AI_Client $client   The AI client.
	 * @param array     $messages Chat messages.
	 * @return array<string, string> Validated classifications.
	 */
	private function execute_classification( AI_Client $client, array $messages ): array {
		try {
			$response = $client->chat_completion( $messages, array( 'temperature' => 0.1 ) );
			$content  = $response['choices'][0]['message']['content'] ?? '';

			return $this->parse_classification_response( $content );
		} catch ( \Exception ) {
			return array();
		}
	}

	/**
	 * Build an empty scan result for when no classification was performed.
	 *
	 * @param string $post_type Post type slug.
	 * @return Meta_Field_Scan_Result
	 */
	private function build_empty_result( string $post_type ): Meta_Field_Scan_Result {
		return new Meta_Field_Scan_Result(
			translate_count: 0,
			copy_count: 0,
			ignore_count: 0,
			classifications: array(),
			post_type: $post_type,
		);
	}

	/**
	 * Build a scan result from classifications.
	 *
	 * @param string               $post_type       Post type slug.
	 * @param array<string,string> $classifications Validated classifications.
	 * @return Meta_Field_Scan_Result
	 */
	private function build_result( string $post_type, array $classifications ): Meta_Field_Scan_Result {
		$counts = \array_count_values( $classifications );

		return new Meta_Field_Scan_Result(
			translate_count: $counts['translate'] ?? 0,
			copy_count: $counts['copy'] ?? 0,
			ignore_count: $counts['ignore'] ?? 0,
			classifications: $classifications,
			post_type: $post_type,
		);
	}
}
