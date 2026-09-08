<?php
/**
 * Markup_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Services\Field_Validators\Field_Validator_Interface;
use PLLAT\Common\Services\JSON_Patch_Service;
use PLLAT\Common\Services\URL_Translation_Service;
use PLLAT\Content\Services\Content_Service;
use PLLAT\Integrations\Integrations\Markup\Services\Gutenberg_Field_Validator;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Markup translation hooks handler.
 *
 * Registers filters for:
 * - Translator type routing (post_content → 'markup')
 * - Field validation for Gutenberg blocks
 * - Post-processing for URL translation
 */
#[Handler( tag: 'init', priority: 9 )]
class Markup_Handler {
    /**
     * Constructor.
     *
     * @param Content_Service         $content_service         Content service.
     * @param URL_Translation_Service $url_translation_service URL translation service.
     */
    public function __construct(
        private Content_Service $content_service,
        private URL_Translation_Service $url_translation_service,
    ) {
    }

    /**
     * Route post_content and HTML-containing meta fields to markup translator.
     *
     * @param string               $translator_type Current translator type.
     * @param string               $reference       Reference key.
     * @param mixed                $value           Source value.
     * @param array<string, mixed> $context         Optional context.
     */
    #[Filter( tag: 'pllat_field_translator_type', priority: 5 )]
    public function filter_field_translator_type( string $translator_type, string $reference, mixed $value = '', array $context = array() ): string {
        return $this->route_to_markup( $translator_type, $reference, \is_string( $value ) ? $value : '' );
    }

    private function route_to_markup( string $translator_type, string $reference, string $value ): string {
        $reference_info = $this->content_service->parse_reference( $reference );

        // Always route post_content to markup.
        if ( 'core' === $reference_info['type'] && 'post_content' === $reference_info['field'] ) {
            return 'markup';
        }

        // Route meta fields containing significant HTML to markup.
        if ( 'meta' === $reference_info['type'] && $this->has_significant_html( $value ) ) {
            return 'markup';
        }

        return $translator_type;
    }

    /**
     * Check if a value contains significant HTML markup worth preserving.
     *
     * Detects block-level and structural tags that indicate rich content
     * rather than incidental angle brackets or simple inline formatting.
     *
     * @param string $value The value to check.
     * @return bool Whether the value contains significant HTML.
     */
    private function has_significant_html( string $value ): bool {
        if ( '' === $value ) {
            return false;
        }

        return (bool) \preg_match( '/<(?:h[1-6]|p|a\s|ul|ol|li|div|table|blockquote)[\s>]/i', $value );
    }

    /**
     * Provide Gutenberg-specific field validator.
     *
     * When translating Gutenberg content, use the Gutenberg_Field_Validator
     * which includes Gutenberg-specific non-translatable keys.
     *
     * @param Field_Validator_Interface|null $validator Current validator.
     * @param array<string, mixed>           $context   Translation context.
     * @return Field_Validator_Interface|null Validator to use.
     */
    #[Filter( tag: 'pllat_json_field_validator', priority: 5 )]
    public function provide_gutenberg_field_validator( $validator, array $context ) {
        // Check if we're translating post_content with Gutenberg.
        if ( ! isset( $context['reference'] ) ) {
            return $validator;
        }

        $reference_info = $this->content_service->parse_reference( $context['reference'] );

        // Only for post_content.
        if ( 'core' !== $reference_info['type'] || 'post_content' !== $reference_info['field'] ) {
            return $validator;
        }

        // Check if content is Gutenberg (validator is only needed for block attrs).
        if ( ! isset( $context['content_type'] ) || 'gutenberg' !== $context['content_type'] ) {
            return $validator;
        }

        return new Gutenberg_Field_Validator();
    }

    /**
     * Post-process translated markup to translate internal URLs.
     *
     * After text content is translated, find and translate internal URLs
     * to point to the correct language version.
     *
     * @param string               $translated_content Translated content.
     * @param string               $original_content   Original content.
     * @param string               $target_language    Target language code.
     * @param array<string, mixed> $context            Translation context.
     * @return string Content with translated URLs.
     */
    #[Filter( tag: 'pllat_post_process_translated_markup', priority: 10 )]
    public function translate_internal_urls(
        string $translated_content,
        string $original_content,
        string $target_language,
        array $context,
    ): string {
        // Find and translate all internal URLs in the content.
        $url_patches = $this->url_translation_service->find_and_translate_urls(
            $translated_content,
            $target_language,
        );

        // Apply URL translation patches if any were found.
        if ( \count( $url_patches ) > 0 ) {
            $translated_content = JSON_Patch_Service::apply_patches( $translated_content, $url_patches );
        }

        return $translated_content;
    }
}
