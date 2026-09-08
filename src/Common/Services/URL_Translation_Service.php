<?php

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;

/**
 * Service for translating internal URLs in JSON data.
 *
 * Finds URL fields in JSON structures and translates them to point to
 * the correct language version of posts/pages/terms.
 */
class URL_Translation_Service {
    /**
     * Language manager for translation lookups.
     *
     * @var Language_Manager
     */
    private Language_Manager $language_manager;

    /**
     * Constructor.
     *
     * @param Language_Manager $language_manager Language manager instance
     */
    public function __construct( Language_Manager $language_manager ) {
        $this->language_manager = $language_manager;
    }

    /**
     * Find and translate all internal URLs in JSON.
     *
     * @param string $json            JSON string to process
     * @param string $target_language Target language code
     * @return array RFC 6902 patches for URL translations
     */
    public function find_and_translate_urls( string $json, string $target_language ): array {
        $data = \json_decode( $json, true );

        if ( ! $data ) {
            return array();
        }

        $patches = array();
        $this->find_urls_recursive( $data, '', $target_language, $patches );

        return $patches;
    }

    /**
     * Recursively find URL fields and generate translation patches.
     *
     * @param mixed  $data            Current data node
     * @param string $path            Current JSON pointer path
     * @param string $target_language Target language code
     * @param array  &$patches        Patches array (passed by reference)
     */
    private function find_urls_recursive( $data, string $path, string $target_language, array &$patches ): void {
        if ( ! \is_array( $data ) ) {
            return;
        }

        foreach ( $data as $key => $value ) {
            $current_path = '' === $path ? "/{$key}" : "{$path}/{$key}";

            // Check if this is a URL field.
            if ( $this->is_url_field( $key, $value ) ) {
                $translated_url = $this->translate_url( $value, $target_language );

                if ( $translated_url !== $value ) {
                    $patches[] = array(
                        'op'    => 'replace',
                        'path'  => $current_path,
                        'value' => $translated_url,
                    );
                }
            } else {
                // Recurse into nested structures.
                $this->find_urls_recursive( $value, $current_path, $target_language, $patches );
            }
        }
    }

    /**
     * Check if field is a URL field.
     *
     * @param string|int $key   Field key
     * @param mixed      $value Field value
     * @return bool True if field contains a URL
     */
    private function is_url_field( $key, $value ): bool {
        // Must be a string.
        if ( ! \is_string( $value ) || '' === \trim( $value ) ) {
            return false;
        }

        // Key must be 'url' (common in Elementor, Gutenberg, etc.).
        if ( 'url' !== $key ) {
            return false;
        }

        // Value must look like a URL.
        return $this->is_url_value( $value );
    }

    /**
     * Check if value looks like a URL.
     *
     * @param string $value Value to check
     * @return bool True if looks like a URL
     */
    private function is_url_value( string $value ): bool {
        $value = \trim( $value );

        if ( '' === $value ) {
            return false;
        }

        // Absolute or relative URL.
        return \str_starts_with( $value, 'http://' )
            || \str_starts_with( $value, 'https://' )
            || \str_starts_with( $value, '/' );
    }

    /**
     * Translate a single URL.
     *
     * @param string $url             Original URL
     * @param string $target_language Target language code
     * @return string Translated URL or original if not translatable
     */
    private function translate_url( string $url, string $target_language ): string {
        // Only translate internal URLs.
        if ( ! $this->is_internal_url( $url ) ) {
            return $url;
        }

        // Try to extract post ID.
        $post_id = $this->extract_post_id( $url );
        if ( $post_id ) {
            return $this->translate_post_url( $url, $post_id, $target_language );
        }

        // Try to extract term ID.
        $term_data = $this->extract_term_id( $url );
        if ( $term_data ) {
            return $this->translate_term_url(
                $url,
                $term_data['term_id'],
                $term_data['taxonomy'],
                $target_language,
            );
        }

        // Not a translatable URL.
        return $url;
    }

    /**
     * Check if URL is internal.
     *
     * @param string $url URL to check
     * @return bool True if internal
     */
    private function is_internal_url( string $url ): bool {
        // Relative URLs are always internal.
        if ( \str_starts_with( $url, '/' ) && ! \str_starts_with( $url, '//' ) ) {
            return true;
        }

        // Check if absolute URL matches site domain.
        $site_url = \home_url();
        $parsed   = \wp_parse_url( $site_url );
        $domain   = $parsed['host'] ?? '';

        if ( '' === $domain ) {
            return false;
        }

        // Check if URL contains site domain.
        return \str_contains( $url, $domain );
    }

    /**
     * Extract post ID from URL.
     *
     * @param string $url URL to extract from
     * @return int|null Post ID or null if not found
     */
    private function extract_post_id( string $url ): ?int {
        // Remove fragments and query params for ID extraction.
        $clean_url = \strtok( $url, '#?' );

        // Try WordPress's built-in function first.
        $post_id = \url_to_postid( $clean_url );

        if ( $post_id > 0 ) {
            return $post_id;
        }

        // If that didn't work, try as relative URL.
        if ( ! \str_starts_with( $url, 'http' ) ) {
            $home_url = \trailingslashit( \home_url() );
            $full_url = $home_url . \ltrim( $url, '/' );
            $post_id  = \url_to_postid( $full_url );

            if ( $post_id > 0 ) {
                return $post_id;
            }
        }

        return null;
    }

    /**
     * Extract term ID and taxonomy from URL.
     *
     * @param string $url URL to extract from
     * @return array|null Array with 'term_id' and 'taxonomy' or null if not found
     */
    private function extract_term_id( string $url ): ?array {
        // Remove fragments and query params for ID extraction.
        $clean_url = \strtok( $url, '#?' );

        // Parse URL to get path.
        $parsed = \wp_parse_url( $clean_url );
        $path   = $parsed['path'] ?? '';

        if ( '' === $path ) {
            return null;
        }

        // Remove leading/trailing slashes.
        $path = \trim( $path, '/' );

        // Get all public taxonomies.
        $taxonomies = \get_taxonomies( array( 'public' => true ), 'objects' );

        foreach ( $taxonomies as $taxonomy ) {
            // Get the rewrite slug for this taxonomy.
            $rewrite_slug = $taxonomy->rewrite['slug'] ?? $taxonomy->name;

            // Check if URL path starts with this taxonomy slug.
            if ( ! \str_starts_with( $path, $rewrite_slug . '/' ) ) {
                continue;
            }

            // Extract term slug from path.
            $term_slug = \substr( $path, \strlen( $rewrite_slug ) + 1 );
            $term_slug = \trim( $term_slug, '/' );

            // Handle nested categories (e.g., /category/parent/child/).
            $slug_parts = \explode( '/', $term_slug );
            $term_slug  = \end( $slug_parts );

            if ( '' === $term_slug ) {
                continue;
            }

            // Use get_terms with lang => '' to find term in any language (Polylang compatible).
            $terms = \get_terms(
                array(
                    'hide_empty' => false,
                    'lang'       => '', // Empty string tells Polylang to return terms from all languages.
                    'slug'       => $term_slug,
                    'taxonomy'   => $taxonomy->name,
                ),
            );

            $term = ! \is_wp_error( $terms ) && \count( $terms ) > 0 ? $terms[0] : false;

            if ( $term ) {
                return array(
                    'taxonomy' => $taxonomy->name,
                    'term_id'  => $term->term_id,
                );
            }
        }

        return null;
    }

    /**
     * Translate a post URL to target language.
     *
     * @param string $original_url    Original URL
     * @param int    $post_id         Post ID
     * @param string $target_language Target language code
     * @return string Translated URL or original if no translation found
     */
    private function translate_post_url( string $original_url, int $post_id, string $target_language ): string {
        // Look up translation using Language Manager.
        $translated_post_id = $this->get_translated_post_id( $post_id, $target_language );

        if ( ! $translated_post_id ) {
            return $original_url;
        }

        // Get permalink for translated post.
        $translated_url = \get_permalink( $translated_post_id );

        if ( ! $translated_url ) {
            return $original_url;
        }

        // Preserve URL fragments and query parameters.
        return $this->preserve_url_components( $original_url, $translated_url );
    }

    /**
     * Translate a term URL to target language.
     *
     * @param string $original_url    Original URL
     * @param int    $term_id         Term ID
     * @param string $taxonomy        Taxonomy name
     * @param string $target_language Target language code
     * @return string Translated URL or original if no translation found
     */
    private function translate_term_url( string $original_url, int $term_id, string $taxonomy, string $target_language ): string {
        // Look up translation using Language Manager.
        $translated_term_id = $this->get_translated_term_id( $term_id, $target_language );

        if ( ! $translated_term_id ) {
            return $original_url;
        }

        // Get term link for translated term.
        $translated_url = \get_term_link( $translated_term_id, $taxonomy );

        if ( \is_wp_error( $translated_url ) || ! $translated_url ) {
            return $original_url;
        }

        // Preserve URL fragments and query parameters.
        return $this->preserve_url_components( $original_url, $translated_url );
    }

    /**
     * Get translated post ID using Language Manager.
     *
     * @param int    $post_id         Original post ID
     * @param string $target_language Target language code
     * @return int|null Translated post ID or null if not found
     */
    private function get_translated_post_id( int $post_id, string $target_language ): ?int {
        $translated_id = $this->language_manager->get_post_by_language( $post_id, $target_language );
        return $translated_id > 0 ? $translated_id : null;
    }

    /**
     * Get translated term ID using Language Manager.
     *
     * @param int    $term_id         Original term ID
     * @param string $target_language Target language code
     * @return int|null Translated term ID or null if not found
     */
    private function get_translated_term_id( int $term_id, string $target_language ): ?int {
        $translated_id = $this->language_manager->get_term_by_language( $term_id, $target_language );
        return $translated_id > 0 ? $translated_id : null;
    }

    /**
     * Preserve URL fragments and query parameters from original URL.
     *
     * @param string $original_url   Original URL with possible fragments/params
     * @param string $translated_url Base translated URL
     * @return string Translated URL with preserved components
     */
    private function preserve_url_components( string $original_url, string $translated_url ): string {
        $parsed = \wp_parse_url( $original_url );

        // Add query string if present.
        if ( isset( $parsed['query'] ) && '' !== $parsed['query'] ) {
            $translated_url .= '?' . $parsed['query'];
        }

        // Add fragment if present.
        if ( isset( $parsed['fragment'] ) && '' !== $parsed['fragment'] ) {
            $translated_url .= '#' . $parsed['fragment'];
        }

        return $translated_url;
    }
}
