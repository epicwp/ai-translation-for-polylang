<?php
/**
 * Gutenberg_Patch_Extractor class file.
 *
 * Extracts translatable patches from Gutenberg blocks.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Services\Field_Validators\Base_Field_Validator;
use PLLAT\Common\Services\Field_Validators\Field_Validator_Interface;
use PLLAT\Integrations\Integrations\Markup\Services\Inline_Tag_Replacer;

/**
 * Extracts translatable patches from Gutenberg block structures.
 *
 * Handles:
 * - Block attributes (using field validator)
 * - innerHTML (for blocks without inner blocks)
 * - innerContent wrapper fragments (for blocks with inner blocks)
 *
 * Returns patches with JSON Pointer paths for later application.
 */
class Gutenberg_Patch_Extractor {
    /**
     * Blocks whose innerHTML should NOT be extracted for translation.
     *
     * These blocks contain media embeds, code, or other non-translatable content
     * that would be corrupted if sent to the LLM.
     *
     * @var array<string>
     */
    private const SKIP_INNER_HTML_BLOCKS = array(
        'core/embed',
        'core/video',
        'core/audio',
        'core/html',
        'core/code',
        'core/preformatted',
        'core/file',
    );

    /**
     * Constructor.
     *
     * @param Inline_Tag_Replacer  $inline_tag_replacer  Inline tag replacer for HTML.
     * @param Json_Pointer_Service $json_pointer_service JSON pointer utilities.
     */
    public function __construct(
        private Inline_Tag_Replacer $inline_tag_replacer,
        private Json_Pointer_Service $json_pointer_service,
    ) {
    }

    /**
     * Extract translatable patches from parsed Gutenberg blocks.
     *
     * @param array<array<string, mixed>> $blocks  Parsed blocks from parse_blocks().
     * @param array<string, mixed>        $context Translation context.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    public function extract( array $blocks, array $context = array() ): array {
        $validator = $this->get_field_validator( $context );
        return $this->extract_from_blocks( $blocks, '', $validator );
    }

    /**
     * Recursively extract patches from blocks.
     *
     * @param array<array<string, mixed>> $blocks    Blocks to process.
     * @param string                      $prefix    Path prefix.
     * @param Field_Validator_Interface   $validator Field validator.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_from_blocks( array $blocks, string $prefix, Field_Validator_Interface $validator ): array {
        $patches = array();

        foreach ( $blocks as $index => $block ) {
            $block_path    = $prefix . '/' . $index;
            $block_patches = $this->extract_from_block( $block, $block_path, $validator );
            $patches       = \array_merge( $patches, $block_patches );
        }

        return $patches;
    }

    /**
     * Extract patches from a single block.
     *
     * @param array<string, mixed>      $block      Block data.
     * @param string                    $block_path Path to this block.
     * @param Field_Validator_Interface $validator  Field validator.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_from_block( array $block, string $block_path, Field_Validator_Interface $validator ): array {
        $patches = array();

        // Skip reusable blocks (they translate separately).
        if ( isset( $block['attrs']['ref'] ) ) {
            return $patches;
        }

        // Handle classic/freeform content (null blockName).
        if ( null === $block['blockName'] ) {
            return $this->extract_classic_block( $block, $block_path );
        }

        // Extract from attributes.
        if ( isset( $block['attrs'] ) && \count( $block['attrs'] ) > 0 ) {
            /**
             * Filter to provide a per-block field validator.
             *
             * Allows integrations (e.g. ACF) to override field validation
             * for specific block types using block-level context.
             *
             * @param Field_Validator_Interface $validator Current validator.
             * @param array                     $block     The Gutenberg block data.
             */
            $block_validator = \apply_filters( 'pllat_gutenberg_block_field_validator', $validator, $block );
            $attr_patches    = $this->extract_attrs_patches(
                $block['attrs'],
                $block_path . '/attrs',
                $block_validator,
            );
            $patches         = \array_merge( $patches, $attr_patches );
        }

        // Extract from innerHTML or innerContent wrappers.
        $html_patches = $this->extract_html_patches( $block, $block_path );
        $patches      = \array_merge( $patches, $html_patches );

        // Recurse into inner blocks.
        if ( isset( $block['innerBlocks'] ) && \count( $block['innerBlocks'] ) > 0 ) {
            $inner_patches = $this->extract_from_blocks(
                $block['innerBlocks'],
                $block_path . '/innerBlocks',
                $validator,
            );
            $patches       = \array_merge( $patches, $inner_patches );
        }

        return $patches;
    }

    /**
     * Extract patches from classic/freeform block.
     *
     * @param array<string, mixed> $block      Block data.
     * @param string               $block_path Path to this block.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_classic_block( array $block, string $block_path ): array {
        $patches = array();

        if ( ! isset( $block['innerHTML'] ) || '' === $block['innerHTML'] ) {
            return $patches;
        }

        $html_text = \trim( $block['innerHTML'] );

        if ( '' === $html_text || \mb_strlen( $html_text ) < 2 ) {
            return $patches;
        }

        $replaced  = $this->inline_tag_replacer->replace( $html_text );
        $patches[] = array(
            'path'    => $block_path . '/innerHTML',
            'tag_map' => $replaced['tag_map'],
            'value'   => $replaced['text'],
        );

        return $patches;
    }

    /**
     * Extract patches from innerHTML or innerContent wrappers.
     *
     * For blocks WITHOUT innerBlocks: extract entire innerHTML.
     * For blocks WITH innerBlocks: extract from wrapper fragments only.
     *
     * @param array<string, mixed> $block      Block data.
     * @param string               $block_path Path to this block.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_html_patches( array $block, string $block_path ): array {
        // Skip media/embed blocks.
        /**
         * Filter the list of block types whose innerHTML is skipped during translation.
         *
         * Remove block types from this list to enable their translation.
         * Example: array_diff( $types, [ 'core/html' ] ) to translate HTML blocks.
         *
         * @param array<string> $block_types Block type names to skip (e.g. 'core/html').
         */
        $skip_blocks = \apply_filters( 'pllat_skip_inner_html_block_types', self::SKIP_INNER_HTML_BLOCKS );

        if ( \in_array( $block['blockName'], $skip_blocks, true ) ) {
            return array();
        }

        // No innerHTML to extract.
        if ( ! isset( $block['innerHTML'] ) || '' === $block['innerHTML'] ) {
            return array();
        }

        $has_inner_blocks = \count( $block['innerBlocks'] ?? array() ) > 0;

        if ( ! $has_inner_blocks ) {
            return $this->extract_inner_html_patch( $block, $block_path );
        }

        return $this->extract_wrapper_patches( $block, $block_path );
    }

    /**
     * Extract patch from innerHTML for simple blocks (no inner blocks).
     *
     * @param array<string, mixed> $block      Block data.
     * @param string               $block_path Path to this block.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_inner_html_patch( array $block, string $block_path ): array {
        $patches   = array();
        $html_text = \trim( $block['innerHTML'] );

        if ( '' === $html_text || \mb_strlen( $html_text ) < 2 ) {
            return $patches;
        }

        $replaced  = $this->inline_tag_replacer->replace( $html_text );
        $patches[] = array(
            'path'    => $block_path . '/innerHTML',
            'tag_map' => $replaced['tag_map'],
            'value'   => $replaced['text'],
        );

        return $patches;
    }

    /**
     * Extract patches from innerContent wrapper fragments.
     *
     * For blocks with innerBlocks, innerContent contains both wrapper HTML
     * (strings) and inner block slots (null). This extracts only the wrapper
     * fragments using Inline_Tag_Replacer to preserve structure.
     *
     * @param array<string, mixed> $block      Block data.
     * @param string               $block_path Path to this block.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_wrapper_patches( array $block, string $block_path ): array {
        if ( ! isset( $block['innerContent'] ) || ! \is_array( $block['innerContent'] ) ) {
            return array();
        }

        $patches = array();

        foreach ( $block['innerContent'] as $index => $content ) {
            $patch = $this->extract_single_wrapper_patch( $content, $block_path, $index );

            if ( null === $patch ) {
                continue;
            }

            $patches[] = $patch;
        }

        return $patches;
    }

    /**
     * Extract patch from a single innerContent wrapper fragment.
     *
     * @param mixed  $content    Content from innerContent array.
     * @param string $block_path Path to the block.
     * @param int    $index      Index in innerContent array.
     * @return array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}|null Patch or null.
     */
    private function extract_single_wrapper_patch( mixed $content, string $block_path, int $index ): ?array {
        if ( ! $this->is_translatable_wrapper_content( $content ) ) {
            return null;
        }

        $html_text = \trim( (string) $content );
        $replaced  = $this->inline_tag_replacer->replace( $html_text );

        return array(
            'path'    => $block_path . '/innerContent/' . $index,
            'tag_map' => $replaced['tag_map'],
            'value'   => $replaced['text'],
        );
    }

    /**
     * Check if wrapper content is translatable.
     *
     * @param mixed $content Content from innerContent array.
     * @return bool True if translatable.
     */
    private function is_translatable_wrapper_content( mixed $content ): bool {
        // Skip inner block slots (null) and non-strings.
        if ( null === $content || ! \is_string( $content ) ) {
            return false;
        }

        $html_text = \trim( $content );

        // Skip empty or too short content.
        if ( '' === $html_text || \mb_strlen( $html_text ) < 2 ) {
            return false;
        }

        // Check if fragment contains translatable text (not just HTML tags).
        $text_only = \wp_strip_all_tags( $html_text );

        return '' !== \trim( $text_only );
    }

    /**
     * Extract patches from block attributes using field validator.
     *
     * @param array<string, mixed>      $attrs     Block attributes.
     * @param string                    $prefix    Path prefix.
     * @param Field_Validator_Interface $validator Field validator.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function extract_attrs_patches( array $attrs, string $prefix, Field_Validator_Interface $validator ): array {
        $patches = array();

        foreach ( $attrs as $key => $value ) {
            $path = $prefix . '/' . $this->json_pointer_service->escape( (string) $key );

            if ( \is_array( $value ) ) {
                // Recurse into nested arrays.
                $nested_patches = $this->extract_attrs_patches( $value, $path, $validator );
                $patches        = \array_merge( $patches, $nested_patches );
                continue;
            }

            if ( ! \is_string( $value ) ) {
                continue;
            }

            // Skip slug-like {label,value} option-pair values (e.g. select/dropdown
            // controls); prose in a "value" attribute beside a "label" still translates.
            if ( $this->is_option_pair_slug_value( (string) $key, $value, $attrs ) ) {
                continue;
            }

            if ( ! $validator->should_translate( (string) $key, $value ) ) {
                continue;
            }

            $replaced  = $this->inline_tag_replacer->replace( $value );
            $patches[] = array(
                'path'    => $path,
                'tag_map' => $replaced['tag_map'],
                'value'   => $replaced['text'],
            );
        }

        return $patches;
    }

    /**
     * Whether a "value" attribute is a slug-like {label,value} option-pair value.
     *
     * Protects select/dropdown control values (e.g. value:"post") from
     * translation, while leaving prose held in a "value" attribute beside an
     * unrelated "label" translatable.
     *
     * @param string               $key   Attribute key.
     * @param string               $value Attribute value (already string-typed).
     * @param array<string, mixed> $attrs Sibling attributes at the same level.
     * @return bool True if the value should be skipped as an option-pair slug.
     */
    private function is_option_pair_slug_value( string $key, string $value, array $attrs ): bool {
        return 'value' === $key
            && isset( $attrs['label'] )
            && 1 === \preg_match( '/^[a-z0-9_-]+$/', $value );
    }

    /**
     * Get field validator for content type.
     *
     * @param array<string, mixed> $context Translation context.
     * @return Field_Validator_Interface Validator.
     */
    private function get_field_validator( array $context ): Field_Validator_Interface {
        $validator = new Base_Field_Validator();

        /**
         * Filter to provide custom field validator.
         *
         * @param Field_Validator_Interface $validator Validator instance.
         * @param array                     $context   Context data.
         */
        return \apply_filters( 'pllat_json_field_validator', $validator, $context );
    }
}
