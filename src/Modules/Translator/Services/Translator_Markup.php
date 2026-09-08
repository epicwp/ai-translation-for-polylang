<?php
/**
 * Translator_Markup class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Integrations\Integrations\Markup\Services\Content_Type_Detector;
use PLLAT\Integrations\Integrations\Markup\Services\HTML_String_Extractor;
use PLLAT\Integrations\Integrations\Markup\Services\Inline_Tag_Replacer;

/**
 * Translates markup content (HTML/Gutenberg) with string extraction.
 *
 * Always uses content-type-aware extraction to protect structured content
 * (Gutenberg blocks, HTML) from having identifiers and markup translated.
 * Plain text falls back to parent Translator for standard translation.
 *
 * This class orchestrates the translation workflow using:
 * - Gutenberg_Patch_Extractor: Extract patches from blocks
 * - Gutenberg_Patch_Applier: Apply translated patches
 * - Translation_Batch_Service: Handle LLM batching
 */
class Translator_Markup extends Translator {
    /**
     * Constructor.
     *
     * @param AI_Client                 $ai_client                AI client.
     * @param Language_Manager          $language_manager         Language manager.
     * @param System_Prompt_Enricher    $enricher                 Injects website context + instructions.
     * @param Content_Type_Detector     $content_type_detector    Content type detector.
     * @param Inline_Tag_Replacer       $inline_tag_replacer      Inline tag replacer.
     * @param HTML_String_Extractor     $html_string_extractor    HTML string extractor.
     * @param Gutenberg_Patch_Extractor $gutenberg_patch_extractor Gutenberg patch extractor.
     * @param Gutenberg_Patch_Applier   $gutenberg_patch_applier  Gutenberg patch applier.
     * @param Translation_Batch_Service $translation_batch_service Translation batch service.
     */
    public function __construct(
        AI_Client $ai_client,
        Language_Manager $language_manager,
        System_Prompt_Enricher $enricher,
        private Content_Type_Detector $content_type_detector,
        private Inline_Tag_Replacer $inline_tag_replacer,
        private HTML_String_Extractor $html_string_extractor,
        private Gutenberg_Patch_Extractor $gutenberg_patch_extractor,
        private Gutenberg_Patch_Applier $gutenberg_patch_applier,
        private Translation_Batch_Service $translation_batch_service,
    ) {
        parent::__construct( $ai_client, $language_manager, $enricher );
    }

    /**
     * Translate markup content.
     *
     * @param string               $text    Content to translate.
     * @param string               $from    Source language code.
     * @param string               $to      Target language code.
     * @param array<string, mixed> $context Translation context.
     * @return string Translated content.
     */
    public function translate_single( string $text, string $from, string $to, array $context = array() ): string {
        // Detect content type first — structured content (Gutenberg, HTML) always
        // uses extraction to protect block identifiers and markup from translation.
        $content_type = $this->content_type_detector->detect( $text );

        // Add content type to context for field validator filter.
        $context['content_type'] = $content_type;

        // Route to appropriate handler.
        $translated = match ( $content_type ) {
            Content_Type_Detector::TYPE_GUTENBERG => $this->translate_gutenberg( $text, $from, $to, $context ),
            Content_Type_Detector::TYPE_HTML      => $this->translate_html( $text, $from, $to, $context ),
            default                               => parent::translate_single( $text, $from, $to, $context ),
        };

        /**
         * Post-process translated markup for URL translation, etc.
         *
         * @param string $translated Translated content.
         * @param string $original   Original content.
         * @param string $to         Target language.
         * @param array  $context    Translation context.
         */
        return \apply_filters( 'pllat_post_process_translated_markup', $translated, $text, $to, $context );
    }

    /**
     * Translate Gutenberg block content.
     *
     * @param string               $content Content with Gutenberg blocks.
     * @param string               $from    Source language code.
     * @param string               $to      Target language code.
     * @param array<string, mixed> $context Translation context.
     * @return string Translated content.
     * @throws \Exception If translation fails.
     */
    private function translate_gutenberg( string $content, string $from, string $to, array $context ): string {
        $content     = $this->freeze_source( $content, $context );
        $batch_state = $this->resolve_batch_state( $context, $content );

        // Parse blocks.
        $blocks = \parse_blocks( $content );

        // On continuation, reuse stored patches to ensure consistency.
        if ( $batch_state ) {
            $patches = $batch_state['patches'];
        } else {
            // Extract translatable patches.
            $patches = $this->gutenberg_patch_extractor->extract( $blocks, $context );

            // Skip if no translatable content found.
            if ( 0 === \count( $patches ) ) {
                return $content;
            }
        }

        // Translate patches — may throw Batch_Continuation_Exception (caught by Lean_Job_Worker).
        $translated_patches = $this->translation_batch_service->translate( $patches, $from, $to, $context );

        // All batches complete — apply.
        $this->validate_patch_count( $patches, $translated_patches );
        $translated_blocks = $this->gutenberg_patch_applier->apply( $blocks, $translated_patches, $patches );

        return \serialize_blocks( $translated_blocks );
    }

    /**
     * Translate HTML content.
     *
     * @param string               $content HTML content.
     * @param string               $from    Source language.
     * @param string               $to      Target language.
     * @param array<string, mixed> $context Translation context.
     * @return string Translated HTML.
     * @throws \Exception If translation fails.
     */
    private function translate_html( string $content, string $from, string $to, array $context ): string {
        $content     = $this->freeze_source( $content, $context );
        $batch_state = $this->resolve_batch_state( $context, $content );

        // On continuation, reuse stored patches to ensure consistency.
        if ( $batch_state ) {
            $patches = $batch_state['patches'];
        } else {
            // Extract strings from HTML.
            $extraction = $this->html_string_extractor->extract( $content );

            // Skip if no translatable content.
            if ( 0 === \count( $extraction['strings'] ) ) {
                return $content;
            }

            // Convert to patch format.
            $patches = $this->convert_html_extraction_to_patches( $extraction );
        }

        // Translate patches — may throw Batch_Continuation_Exception (caught by Lean_Job_Worker).
        $translated_patches = $this->translation_batch_service->translate( $patches, $from, $to, $context );

        // All batches complete — re-extract for application structure, then apply.
        $extraction = $this->html_string_extractor->extract( $content );
        $this->validate_patch_count( $patches, $translated_patches );
        $translations = $this->restore_html_translations( $patches, $translated_patches );

        return $this->html_string_extractor->replace( $extraction['html'], $translations );
    }

    /**
     * Convert HTML extraction result to patch format.
     *
     * @param array{html: string, strings: array<array{path: string, text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}>} $extraction Extraction result.
     * @return array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Patches.
     */
    private function convert_html_extraction_to_patches( array $extraction ): array {
        $patches = array();

        foreach ( $extraction['strings'] as $item ) {
            $patches[] = array(
                'path'    => $item['path'],
                'tag_map' => $item['tag_map'],
                'value'   => $item['text'],
            );
        }

        return $patches;
    }

    /**
     * Restore inline tags in translated HTML patches.
     *
     * @param array<array{path: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $original_patches Original patches.
     * @param array<array{path: string, value: string}> $translated_patches Translated patches.
     * @return array<array{path: string, text: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> Translations.
     * @throws \Exception If placeholder validation fails.
     */
    private function restore_html_translations( array $original_patches, array $translated_patches ): array {
        $translations = array();

        foreach ( $translated_patches as $index => $patch ) {
            $original = $original_patches[ $index ];
            $value    = $patch['value'];

            // Restore inline tags.
            if ( \count( $original['tag_map'] ) > 0 ) {
                $unrecoverable = ! $this->inline_tag_replacer->validate( $value, $original['tag_map'] )
                    && ! $this->inline_tag_replacer->is_recoverable( $value, $original['tag_map'] );
                if ( $unrecoverable ) {
                    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception.
                    throw new \Exception(
                        'Invalid placeholder structure in HTML translation for: ' . $original['path'],
                    );
                    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                // Recoverable drop (LLM rephrased a wrapper away): restore()
                // omits the absent wrapper; keep the translated field.
                $value = $this->inline_tag_replacer->restore( $value, $original['tag_map'] );
            }

            $translations[] = array(
                'path'    => $original['path'],
                'tag_map' => $original['tag_map'],
                'text'    => $value,
            );
        }

        return $translations;
    }

    /**
     * Validate that all patches were translated.
     *
     * @param array<array<string, mixed>> $original_patches   Original patches.
     * @param array<array<string, mixed>> $translated_patches Translated patches.
     * @throws \Exception If patch counts don't match.
     */
    private function validate_patch_count( array $original_patches, array $translated_patches ): void {
        if ( \count( $translated_patches ) !== \count( $original_patches ) ) {
            throw new \Exception(
                \sprintf(
                    'Incomplete translation: expected %d patches, got %d',
                    \count( $original_patches ),
                    \count( $translated_patches ),
                ),
            );
        }
    }
}
