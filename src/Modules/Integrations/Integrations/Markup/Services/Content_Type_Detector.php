<?php
/**
 * Content_Type_Detector class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Detects content type for post_content field.
 *
 * Determines whether content is Gutenberg blocks, HTML, or plain text.
 * Used to route content to appropriate translation strategy.
 */
class Content_Type_Detector {
    /**
     * Content type constants.
     */
    public const TYPE_GUTENBERG = 'gutenberg';
    public const TYPE_HTML      = 'html';
    public const TYPE_PLAIN     = 'plain';

    /**
     * Detect content type.
     *
     * @param string $content The post_content value.
     * @return string Content type: 'gutenberg', 'html', or 'plain'.
     */
    public function detect( string $content ): string {
        // Empty content is plain.
        if ( '' === \trim( $content ) ) {
            return self::TYPE_PLAIN;
        }

        // Check for Gutenberg blocks first (most specific).
        if ( $this->has_gutenberg_blocks( $content ) ) {
            return self::TYPE_GUTENBERG;
        }

        // Check for HTML tags.
        if ( $this->has_html_markup( $content ) ) {
            return self::TYPE_HTML;
        }

        return self::TYPE_PLAIN;
    }

    /**
     * Check if content contains shortcodes.
     *
     * Useful for future Divi/WPBakery integration detection.
     *
     * @param string $content Content to check.
     * @return bool True if content has shortcodes.
     */
    public function has_shortcodes( string $content ): bool {
        // Check for shortcode pattern [shortcode] or [shortcode attr="value"].
        return 1 === \preg_match( '/\[[a-zA-Z_][a-zA-Z0-9_-]*(?:\s[^\]]+)?\]/', $content );
    }

    /**
     * Check if content has Gutenberg blocks.
     *
     * Uses WordPress native has_blocks() plus validation.
     *
     * @param string $content Content to check.
     * @return bool True if content has Gutenberg blocks.
     */
    private function has_gutenberg_blocks( string $content ): bool {
        // WordPress native block detection.
        if ( ! \has_blocks( $content ) ) {
            return false;
        }

        // Additional validation: ensure parse_blocks returns valid blocks.
        $blocks = \parse_blocks( $content );

        foreach ( $blocks as $block ) {
            // Skip empty/whitespace-only blocks (null blockName = classic/freeform content).
            if ( null !== $block['blockName'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if content has HTML markup.
     *
     * Excludes Gutenberg comments which aren't traditional HTML.
     *
     * @param string $content Content to check.
     * @return bool True if content has HTML markup.
     */
    private function has_html_markup( string $content ): bool {
        // Strip Gutenberg comments for accurate HTML detection.
        $stripped = \preg_replace( '/<!--.*?-->/s', '', $content );

        // Check for HTML tags.
        return 1 === \preg_match( '/<[a-z][\s\S]*>/i', $stripped );
    }
}
