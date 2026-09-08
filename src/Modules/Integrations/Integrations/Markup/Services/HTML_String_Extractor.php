<?php
/**
 * HTML_String_Extractor class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Extracts translatable text strings from HTML content.
 *
 * Uses PHP's DOMDocument to navigate the DOM and extract text nodes
 * while preserving structure for later replacement.
 */
class HTML_String_Extractor {
    /**
     * Tags whose content should not be translated.
     *
     * @var array<string>
     */
    private const SKIP_TAGS = array(
        'script',
        'style',
        'code',
        'pre',
        'svg',
        'math',
        'noscript',
        'template',
        'iframe',
        'video',
        'audio',
        'embed',
        'object',
    );

    /**
     * Inline tag replacer instance.
     *
     * @var Inline_Tag_Replacer
     */
    private Inline_Tag_Replacer $inline_tag_replacer;

    /**
     * Constructor.
     *
     * @param Inline_Tag_Replacer $inline_tag_replacer Inline tag replacer.
     */
    public function __construct( Inline_Tag_Replacer $inline_tag_replacer ) {
        $this->inline_tag_replacer = $inline_tag_replacer;
    }

    /**
     * Extract translatable strings from HTML.
     *
     * @param string $html HTML content.
     * @return array{strings: array<array{path: string, text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}>, html: string}
     */
    public function extract( string $html ): array {
        // Wrap in a root element to ensure valid DOM.
        $wrapped_html = '<div id="pllat-root">' . $html . '</div>';

        $doc = new \DOMDocument();
        // Suppress warnings for invalid HTML and set encoding.
        $previous_state = \libxml_use_internal_errors( true );
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped_html,
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors( $previous_state );

        $strings            = array();
        $path_counter       = 0;
        $placeholder_prefix = '{{PLLAT_TEXT_';

        // Find the root element.
        $root = $doc->getElementById( 'pllat-root' );
        if ( $root ) {
            $this->extract_from_node( $root, $strings, $path_counter, $placeholder_prefix, $doc );
        }

        // Get modified HTML without wrapper.
        $modified_html = '';
        if ( $root ) {
            foreach ( $root->childNodes as $child ) {
                $modified_html .= $doc->saveHTML( $child );
            }
        }

        return array(
            'html'    => $modified_html,
            'strings' => $strings,
        );
    }

    /**
     * Replace translated strings back into HTML.
     *
     * @param string                                                                                                               $html         HTML with placeholders.
     * @param array<array{path: string, text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $translations Array of translations with path and translated text.
     * @return string HTML with translations restored.
     */
    public function replace( string $html, array $translations ): string {
        foreach ( $translations as $item ) {
            $path       = $item['path'];
            $translated = $item['text'];
            $tag_map    = $item['tag_map'];

            // Restore inline tags if we have a tag map.
            if ( ! empty( $tag_map ) ) {
                $translated = $this->inline_tag_replacer->restore( $translated, $tag_map );
            }

            // Replace placeholder with translated text.
            $html = \str_replace( $path, $translated, $html );
        }

        return $html;
    }

    /**
     * Extract text content from an element's innerHTML.
     *
     * Simpler extraction for Gutenberg innerHTML that may contain inline tags.
     *
     * @param string $inner_html The innerHTML content.
     * @return array{text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}
     */
    public function extract_inline( string $inner_html ): array {
        return $this->inline_tag_replacer->replace( $inner_html );
    }

    /**
     * Restore inline tags to translated content.
     *
     * @param string                                                            $text    Translated text with placeholders.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Tag map from extraction.
     * @return string Content with restored tags.
     */
    public function restore_inline( string $text, array $tag_map ): string {
        return $this->inline_tag_replacer->restore( $text, $tag_map );
    }

    /**
     * Validate inline tag placeholders in translated text.
     *
     * @param string                                                            $text    Text to validate.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map Expected tag map.
     * @return bool True if valid.
     */
    public function validate_inline( string $text, array $tag_map ): bool {
        return $this->inline_tag_replacer->validate( $text, $tag_map );
    }

    /**
     * Recursively extract text from a node.
     *
     * @param \DOMNode                                                                                                             $node              Current node.
     * @param array<array{path: string, text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $strings Extracted strings (by reference).
     * @param int                                                                                                                  $path_counter      Path counter (by reference).
     * @param string                                                                                                               $placeholder_prefix Placeholder prefix.
     * @param \DOMDocument                                                                                                         $doc               DOM document for creating text nodes.
     */
    private function extract_from_node(
        \DOMNode $node,
        array &$strings,
        int &$path_counter,
        string $placeholder_prefix,
        \DOMDocument $doc,
    ): void {
        // Build list of child nodes to process (copy to avoid modification during iteration).
        $children = array();
        foreach ( $node->childNodes as $child ) {
            $children[] = $child;
        }

        foreach ( $children as $child ) {
            if ( $child instanceof \DOMText ) {
                $text = $child->textContent;

                // Skip empty or whitespace-only text.
                if ( '' === \trim( $text ) ) {
                    continue;
                }

                // Skip if text is just numeric or single characters.
                if ( \mb_strlen( \trim( $text ) ) < 2 ) {
                    continue;
                }

                // Replace inline tags in the text with placeholders.
                $replaced = $this->inline_tag_replacer->replace( $text );

                $path      = $placeholder_prefix . $path_counter . '}}';
                $strings[] = array(
                    'path'    => $path,
                    'tag_map' => $replaced['tag_map'],
                    'text'    => $replaced['text'],
                );

                // Replace text node content with placeholder.
                $new_text = $doc->createTextNode( $path );
                $node->replaceChild( $new_text, $child );
                ++$path_counter;
            } elseif ( $child instanceof \DOMElement ) {
                $tag_name = \strtolower( $child->tagName );

                // Skip non-translatable tag contents entirely.
                if ( \in_array( $tag_name, self::SKIP_TAGS, true ) ) {
                    continue;
                }

                // Recursively process child nodes.
                $this->extract_from_node( $child, $strings, $path_counter, $placeholder_prefix, $doc );
            }
        }
    }
}
