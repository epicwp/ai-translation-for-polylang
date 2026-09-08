<?php
/**
 * Markup_Integration class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Integrations\Core\Interfaces\Integration;

/**
 * Markup-aware translation integration.
 *
 * Handles post_content with HTML or Gutenberg blocks.
 * Defers to Elementor integration for Elementor-managed posts.
 */
class Markup_Integration implements Integration {
    /**
     * Translation threshold in characters.
     *
     * Content above this threshold uses string extraction.
     */
    public const THRESHOLD = 10000;

    /**
     * Content type detector.
     *
     * @var Content_Type_Detector
     */
    private Content_Type_Detector $content_type_detector;

    /**
     * Constructor.
     *
     * @param Content_Type_Detector $content_type_detector Content type detector.
     */
    public function __construct( Content_Type_Detector $content_type_detector ) {
        $this->content_type_detector = $content_type_detector;
    }

    /**
     * Get integration key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'markup';
    }

    /**
     * Get integration name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'Markup (HTML/Gutenberg)';
    }

    /**
     * Get plugin file.
     *
     * This is a core integration, not a plugin.
     *
     * @return string
     */
    public function get_plugin_file(): string {
        return '';
    }

    /**
     * Get integration type.
     *
     * @return string
     */
    public function get_type(): string {
        return 'content_handler';
    }

    /**
     * Check if integration is active.
     *
     * Always active - this is a core feature.
     *
     * @return bool
     */
    public function is_active(): bool {
        return true;
    }

    /**
     * Check if this integration handles a specific post.
     *
     * Returns false for Elementor posts to let that integration handle them.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function handles_post( int $post_id ): bool {
        // Defer to Elementor for Elementor-managed posts.
        if ( $this->is_elementor_post( $post_id ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check if this integration handles a term.
     *
     * @param int $term_id Term ID.
     * @return bool
     */
    public function handles_term( int $term_id ): bool {
        return false;
    }

    /**
     * Check if content should use string extraction.
     *
     * @param string $content Content to check.
     * @return bool True if content is above threshold.
     */
    public function should_extract_strings( string $content ): bool {
        $threshold = $this->get_threshold();
        return \mb_strlen( $content ) > $threshold;
    }

    /**
     * Get the content type.
     *
     * @param string $content Content to analyze.
     * @return string Content type: 'gutenberg', 'html', or 'plain'.
     */
    public function get_content_type( string $content ): string {
        return $this->content_type_detector->detect( $content );
    }

    /**
     * Check if content has shortcodes.
     *
     * @param string $content Content to check.
     * @return bool
     */
    public function has_shortcodes( string $content ): bool {
        return $this->content_type_detector->has_shortcodes( $content );
    }

    /**
     * Get the extraction threshold.
     *
     * @return int Threshold in characters.
     */
    public function get_threshold(): int {
        /**
         * Filter the markup translation threshold.
         *
         * @param int $threshold Threshold in characters.
         */
        return (int) \apply_filters( 'pllat_markup_translation_threshold', self::THRESHOLD );
    }

    /**
     * Check if a post is managed by Elementor.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private function is_elementor_post( int $post_id ): bool {
        return 'builder' === \get_post_meta( $post_id, '_elementor_edit_mode', true );
    }
}
