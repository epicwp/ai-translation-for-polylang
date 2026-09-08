<?php
/**
 * Field_Translator class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Content\Services\Content_Service;

/**
 * Translates a single field value by selecting the appropriate translator.
 *
 * Factory/dispatcher pattern that determines which translator to use
 * based on field type (text, JSON, markup) via filter hooks.
 *
 * This service is only available in BYOK mode when API keys are configured.
 */
class Field_Translator {
    /**
     * Constructor.
     *
     * @param Translator        $text_translator   Standard text translator.
     * @param Translator_JSON   $json_translator   JSON translator for page builders.
     * @param Translator_Markup $markup_translator Markup translator for HTML/Gutenberg.
     * @param Content_Service   $content_service   Content service for resolving values.
     */
    public function __construct(
        private Translator $text_translator,
        private Translator_JSON $json_translator,
        private Translator_Markup $markup_translator,
        private Content_Service $content_service,
        private AI_Client $ai_client,
    ) {
    }

    /**
     * Translate a single field value.
     *
     * @param string               $reference Reference key (e.g. 'post_title', '_meta|key').
     * @param mixed                $value     Source value to translate.
     * @param string               $lang_from Source language code.
     * @param string               $lang_to   Target language code.
     * @param array<string, mixed> $context   Optional context (post_id, post_type, etc.).
     * @return string Translated text.
     * @throws \Exception If translator not available or translation fails.
     */
    public function process_field(
        string $reference,
        mixed $value,
        string $lang_from,
        string $lang_to,
        array $context = array(),
    ): string {
        if ( null === $value ) {
            return '';
        }

        // A field holding only whitespace has nothing to translate. Asked to
        // translate nothing, models answer conversationally ("Please provide
        // the text you would like me to translate."), and that reply passes
        // validate_translation_content() — which only rejects empty and
        // over-long output — and is written into the field. Blank in, blank
        // out: never ask.
        //
        // \p{Z} rather than trim(): trim() is byte-based and leaves a
        // non-breaking space (U+00A0) — which editors insert constantly — so
        // such a field would still reach the model. Adding U+00A0 to trim()'s
        // charlist would strip its bytes individually and corrupt other
        // multibyte characters. preg_match returns false on invalid UTF-8,
        // which falls through and is translated as before.
        if ( \is_string( $value ) && 1 === \preg_match( '/^[\p{Z}\s]*$/u', $value ) ) {
            return $value;
        }

        $original_value = \maybe_unserialize( $value );

        // Handle arrays: skip empty, skip unless explicitly allowed.
        if ( \is_array( $original_value ) ) {
            if ( array() === \array_filter( $original_value ) ) {
                return '';
            }
            /**
             * Filter whether to allow array translation for a field.
             *
             * @param bool   $allow     Whether to allow array translation. Default false.
             * @param string $reference The field reference being processed.
             * @return bool Whether to allow array translation.
             */
            if ( ! \apply_filters( 'pllat_allow_array_translation_for_field', false, $reference ) ) {
                return \maybe_serialize( $original_value );
            }
        }

        $string_value = \is_array( $original_value )
            ? \wp_json_encode( $original_value, \JSON_UNESCAPED_UNICODE )
            : $original_value;

        $translator_type = (string) \apply_filters(
            'pllat_field_translator_type',
            'text',
            $reference,
            $string_value,
            $context,
        );

        $full_context = \array_merge(
            $context,
            array(
                'reference' => $reference,
            ),
        );

        $translated = $this->get_translator( $translator_type )->translate_single(
            $string_value,
            $lang_from,
            $lang_to,
            $full_context,
        );

        return $this->finalize_translation( $translated, $original_value );
    }

    /**
     * Get translator instance by type.
     *
     * @param string $type Translator type ('text', 'json', or 'markup').
     * @return Translator The translator instance.
     */
    private function get_translator( string $type ): Translator {
        return match ( $type ) {
            'text'   => $this->text_translator,
            'json'   => $this->json_translator,
            'markup' => $this->markup_translator,
            default  => $this->text_translator, // Safe fallback for unknown types.
        };
    }

    /**
     * Finalize translation: convert JSON back to array if original was array, then serialize.
     *
     * @param string $translated     Translated value (JSON or string).
     * @param mixed  $original_value Original value before translation.
     * @return string Serialized result.
     */
    private function finalize_translation( string $translated, $original_value ): string {
        // If original was array, JSON decode the translated value.
        if ( \is_array( $original_value ) ) {
            // Strip markdown code fences the AI may wrap around JSON responses.
            $json_string = \preg_replace( '/^```(?:json)?\s*/i', '', $translated );
            $json_string = \preg_replace( '/\s*```$/', '', $json_string );
            $json_string = \trim( $json_string );

            $decoded = \json_decode( $json_string, true );

            // Guard against json_decode failure — return original value instead of null
            // which would clear complex fields (e.g. ACF flexible_content).
            if ( null === $decoded && \JSON_ERROR_NONE !== \json_last_error() ) {
                return \maybe_serialize( $original_value );
            }

            $translated = $decoded;
        }

        // Return serialized (WordPress standard for storing arrays).
        return \maybe_serialize( $translated );
    }
}
