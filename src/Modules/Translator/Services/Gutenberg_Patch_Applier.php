<?php
/**
 * Gutenberg_Patch_Applier class file.
 *
 * Applies translated patches to Gutenberg blocks.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Integrations\Integrations\Markup\Services\Inline_Tag_Replacer;

/**
 * Applies translated patches to Gutenberg block structures.
 *
 * Handles:
 * - Restoring inline tags from placeholders
 * - Setting values at JSON Pointer paths
 * - Syncing innerHTML ↔ innerContent after modifications
 *
 * Ensures serialize_blocks() produces valid output.
 */
class Gutenberg_Patch_Applier {
    /**
     * Constructor.
     *
     * @param Inline_Tag_Replacer  $inline_tag_replacer  Inline tag replacer.
     * @param Json_Pointer_Service $json_pointer_service JSON pointer utilities.
     */
    public function __construct(
        private Inline_Tag_Replacer $inline_tag_replacer,
        private Json_Pointer_Service $json_pointer_service,
    ) {
    }

    /**
     * Apply translated patches to blocks.
     *
     * @param array<array<string, mixed>>                                                                                           $blocks             Original blocks.
     * @param array<array{op: string, path: string, value: string}>                                                                 $translated_patches Translated patches.
     * @param array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $original_patches   Original patches with tag maps.
     * @return array<array<string, mixed>> Updated blocks.
     * @throws \Exception If placeholder validation fails.
     */
    public function apply( array $blocks, array $translated_patches, array $original_patches ): array {
        // Build lookups from path to tag_map and original value.
        $tag_maps        = $this->build_tag_map_lookup( $original_patches );
        $original_values = $this->build_original_value_lookup( $original_patches );

        // Track blocks that need innerHTML rebuilt from innerContent.
        $blocks_to_sync = array();

        // Apply each patch.
        foreach ( $translated_patches as $patch ) {
            $path  = $patch['path'];
            $value = $patch['value'];

            // Restore inline tags if we have a tag map.
            $value = $this->restore_inline_tags(
                $value,
                $path,
                $tag_maps[ $path ] ?? array(),
                $original_values[ $path ] ?? '',
            );

            // Apply patch using JSON pointer.
            $blocks = $this->json_pointer_service->set( $blocks, $path, $value );

            // Sync innerHTML ↔ innerContent as needed.
            if ( \str_ends_with( $path, '/innerHTML' ) ) {
                // innerHTML patch: sync to innerContent.
                $blocks = $this->sync_inner_content( $blocks, $path, $value );
            } elseif ( \str_contains( $path, '/innerContent/' ) ) {
                // innerContent patch: mark block for innerHTML rebuild.
                $block_path                    = $this->json_pointer_service->get_block_path_from_inner_content_path( $path );
                $blocks_to_sync[ $block_path ] = true;
            }
        }

        // Rebuild innerHTML for blocks that had innerContent patches.
        return $this->rebuild_marked_blocks( $blocks, $blocks_to_sync );
    }

    /**
     * Build lookup from path to tag_map.
     *
     * @param array<array{path: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $patches Patches.
     * @return array<string, array<int, array{tag: string, attrs: string, self_closing: bool}>> Path → tag_map lookup.
     */
    private function build_tag_map_lookup( array $patches ): array {
        $lookup = array();

        foreach ( $patches as $patch ) {
            $lookup[ $patch['path'] ] = $patch['tag_map'];
        }

        return $lookup;
    }

    /**
     * Build lookup from path to original value.
     *
     * @param array<array{path: string, value: string}> $patches Patches.
     * @return array<string, string> Path → original value lookup.
     */
    private function build_original_value_lookup( array $patches ): array {
        $lookup = array();

        foreach ( $patches as $patch ) {
            $lookup[ $patch['path'] ] = $patch['value'];
        }

        return $lookup;
    }

    /**
     * Restore inline tags from placeholders.
     *
     * @param string                                                            $value          Translated value with placeholders.
     * @param string                                                            $path           Patch path.
     * @param array<int, array{tag: string, attrs: string, self_closing: bool}> $tag_map        Tag map.
     * @param string                                                            $original_value Original value (for error reporting).
     * @return string Value with restored HTML tags.
     * @throws \Exception If placeholder validation fails.
     */
    private function restore_inline_tags( string $value, string $path, array $tag_map, string $original_value ): string {
        if ( 0 === \count( $tag_map ) ) {
            return $value;
        }

        $unrecoverable = ! $this->inline_tag_replacer->validate( $value, $tag_map )
            && ! $this->inline_tag_replacer->is_recoverable( $value, $tag_map );
        if ( $unrecoverable ) {
            \do_action(
                'pllat_placeholder_validation_failed',
                array(
                    'original_value'   => $original_value,
                    'path'             => $path,
                    'tag_map'          => $tag_map,
                    'translated_value' => $value,
                ),
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception for internal logging.
            throw new \Exception( "Invalid placeholder structure in translation for path: {$path}" );
        }

        // Recoverable: the LLM rephrased a wrapped phrase away (an inline
        // formatting wrapper it could not preserve). Keep the translated
        // field — restore() omits the absent wrapper — instead of failing
        // the field and burning retries on deterministic model behaviour.
        return $this->inline_tag_replacer->restore( $value, $tag_map );
    }

    /**
     * Sync innerContent array when innerHTML is updated.
     *
     * serialize_blocks() uses innerContent, not innerHTML.
     * For simple blocks (no inner blocks), innerContent typically has one string.
     *
     * @param array<array<string, mixed>> $blocks Blocks array.
     * @param string                      $path   innerHTML path (e.g., /0/innerHTML).
     * @param string                      $value  New innerHTML value.
     * @return array<array<string, mixed>> Updated blocks.
     */
    private function sync_inner_content( array $blocks, string $path, string $value ): array {
        $block_path = \str_replace( '/innerHTML', '', $path );
        $block      = &$this->json_pointer_service->get_reference( $blocks, $block_path );

        if ( null === $block ) {
            return $blocks;
        }

        if ( ! isset( $block['innerContent'] ) || ! \is_array( $block['innerContent'] ) ) {
            return $blocks;
        }

        // Replace first non-null string with translated content.
        foreach ( $block['innerContent'] as $i => $content ) {
            if ( null === $content || ! \is_string( $content ) ) {
                continue;
            }

            $block['innerContent'][ $i ] = $value;
            break; // Only replace first occurrence.
        }

        return $blocks;
    }

    /**
     * Rebuild innerHTML for all marked blocks.
     *
     * @param array<array<string, mixed>> $blocks         Blocks array.
     * @param array<string, bool>         $blocks_to_sync Blocks that need innerHTML rebuild.
     * @return array<array<string, mixed>> Updated blocks.
     */
    private function rebuild_marked_blocks( array $blocks, array $blocks_to_sync ): array {
        foreach ( \array_keys( $blocks_to_sync ) as $block_path ) {
            $blocks = $this->rebuild_inner_html( $blocks, $block_path );
        }

        return $blocks;
    }

    /**
     * Rebuild innerHTML from innerContent for a block.
     *
     * @param array<array<string, mixed>> $blocks     Blocks array.
     * @param string                      $block_path Path to the block.
     * @return array<array<string, mixed>> Updated blocks.
     */
    private function rebuild_inner_html( array $blocks, string $block_path ): array {
        $block = &$this->json_pointer_service->get_reference( $blocks, $block_path );

        if ( null === $block ) {
            return $blocks;
        }

        if ( ! isset( $block['innerContent'] ) || ! \is_array( $block['innerContent'] ) ) {
            return $blocks;
        }

        $block['innerHTML'] = \implode(
            '',
            \array_map(
                static fn( $c ) => $c ?? '',
                $block['innerContent'],
            ),
        );

        return $blocks;
    }
}
