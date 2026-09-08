<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\Field_Validators\Base_Field_Validator;
use PLLAT\Common\Services\Field_Validators\Field_Validator_Interface;
use PLLAT\Common\Services\JSON_Patch_Service;
use PLLAT\Translator\Services\AI_Client;
use PLLAT\Translator\Services\Translation_Batch_Service;
use PLLAT\Translator\Services\Translator;

/**
 * Translates JSON page builder data using RFC 6901 JSON Patches.
 *
 * This translator uses a two-step approach:
 * 1. Path Extraction (Step 1): Pattern-based field validation identifies translatable content paths
 * 2. Translation (Step 2): LLM translates the extracted content and generates RFC 6902 patches
 *
 * Benefits:
 * - Token efficient (only translatable content sent to LLM, not full JSON)
 * - Hallucination resistant (LLM doesn't reconstruct structure, only translates)
 * - Fast path extraction (deterministic pattern matching, no API calls)
 * - Scalable (works with any JSON page builder via field validators)
 * - Extensible (custom validators via filters for different page builders)
 */
class Translator_JSON extends Translator {
    /**
     * Constructor.
     *
     * @param AI_Client                 $ai_client                 AI client for LLM calls.
     * @param Language_Manager          $language_manager          Language manager for getting full language names.
     * @param System_Prompt_Enricher    $enricher                  Injects website context + instructions.
     * @param Translation_Batch_Service $translation_batch_service Batch translation service.
     */
    public function __construct(
        AI_Client $ai_client,
        Language_Manager $language_manager,
        System_Prompt_Enricher $enricher,
        private Translation_Batch_Service $translation_batch_service,
    ) {
        parent::__construct( $ai_client, $language_manager, $enricher );
    }

    /**
     * Translate a single JSON string using batch translation service.
     *
     * @param string               $text The JSON to translate.
     * @param string               $from The source language.
     * @param string               $to The target language.
     * @param array<string, mixed> $context The context.
     * @return string The translated JSON.
     */
    public function translate_single( string $text, string $from, string $to, array $context = array() ): string {
        $json        = $this->validate_json( $text );
        $json        = $this->freeze_source( $json, $context );
        $batch_state = $this->resolve_batch_state( $context, $json );

        // On continuation, reuse stored patches to ensure consistency.
        if ( $batch_state ) {
            $extracted_patches = $batch_state['patches'];
        } else {
            $extracted_patches = $this->extract_translatable_patches( $json, $context );

            if ( 0 === \count( $extracted_patches ) ) {
                /*
                 * Not an error — a field may legitimately hold only structural
                 * data. But downstream this is indistinguishable from a real
                 * translation: the job completes and record_field_state() stores
                 * the source hash, so a field that translated nothing is
                 * remembered as done and never retried. Log it so support can
                 * tell "nothing to translate" apart from "translated".
                 */
                \do_action(
                    'pllat_log_warning',
                    \sprintf(
                        'JSON translation found no translatable content for field "%s" (%s to %s); source returned unchanged.',
                        (string) ( $context['reference'] ?? 'unknown' ),
                        $from,
                        $to,
                    ),
                );

                return $text;
            }
        }

        // May throw Batch_Continuation_Exception (caught by Lean_Job_Worker).
        $patches = $this->translation_batch_service->translate( $extracted_patches, $from, $to, $context );

        $translated_json = $this->apply_patches( $json, $patches );

        /**
         * Filter to post-process translated JSON.
         *
         * Allows integrations to perform additional transformations after
         * text translation, such as translating internal URLs in link fields.
         *
         * @param string $translated_json The JSON after text translation
         * @param string $original_json   The original JSON before translation
         * @param string $target_language Target language code
         * @param array  $context         Translation context
         */
        return \apply_filters(
            'pllat_post_process_translated_json',
            $translated_json,
            $json,
            $to,
            $context,
        );
    }

    /**
     * Step 1: Extract translatable patches from JSON.
     *
     * This step analyzes the JSON structure and identifies all user-facing
     * translatable content with their RFC 6901 JSON Pointer paths using
     * pattern-based field validation.
     *
     * @param string               $json The JSON to analyze.
     * @param array<string, mixed> $context The context.
     * @return array<array{path: string, value: string}> Array of patches (path + value pairs).
     * @throws \Exception If JSON is invalid.
     */
    private function extract_translatable_patches( string $json, array $context ): array {
        // Decode JSON.
        $data = \json_decode( $json, true );
        if ( null === $data ) {
            throw new \Exception( 'Invalid JSON for patch extraction' );
        }

        // Get appropriate validator (via filter for extensibility).
        $validator = $this->get_field_validator( $context );

        // Recursively find translatable patches.
        $patches = JSON_Patch_Service::find_translatable_patches( $data, $validator );

        /**
         * Filter extracted translatable patches before translation.
         *
         * Allows integrations to add additional translatable patches that
         * cannot be detected by the field validator alone (e.g. Bricks
         * component properties with arbitrary key names).
         *
         * @param array<array{path: string, value: string}> $patches   Extracted patches.
         * @param mixed                                      $data      Decoded JSON data.
         * @param array<string, mixed>                       $context   Translation context.
         */
        return \apply_filters( 'pllat_json_extracted_patches', $patches, $data, $context );
    }

    /**
     * Get field validator for JSON patch extraction.
     *
     * Returns a validator appropriate for the content type. Can be filtered
     * to provide custom validators for specific page builders or content types.
     *
     * @param array<string, mixed> $context The context.
     * @return \PLLAT\Common\Services\Field_Validators\Field_Validator_Interface The validator.
     */
    private function get_field_validator( array $context ): Field_Validator_Interface {
        // Default to base validator.
        $validator = new Base_Field_Validator();

        /**
         * Filter to provide custom field validator.
         *
         * Allows integrations to use different validators for different page builders.
         * For example, Elementor integration can return Elementor_Field_Validator.
         *
         * @param Field_Validator_Interface $validator The validator instance.
         * @param array<string, mixed>                                               $context   The context data.
         */
        return \apply_filters( 'pllat_json_field_validator', $validator, $context );
    }

    /**
     * Validate the JSON.
     *
     * @param string $text The JSON to validate.
     * @return string The validated JSON.
     * @throws \Exception If JSON is invalid.
     */
    private function validate_json( string $text ): string {
        $json = \json_decode( $text, true );
        if ( null === $json ) {
            throw new \Exception( 'Invalid JSON' );
        }
        return $text;
    }

    /**
     * Apply JSON RFC 6902 patches to the JSON.
     *
     * @param string                       $json The JSON to apply the patches to.
     * @param array<array<string, string>> $patches The patches to apply.
     * @return string The patched JSON.
     */
    private function apply_patches( string $json, array $patches ): string {
        return JSON_Patch_Service::apply_patches( $json, $patches );
    }
}
