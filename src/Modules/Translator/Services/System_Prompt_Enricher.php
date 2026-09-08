<?php

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;

/**
 * Single source of truth for injecting user "directives" into any translation
 * system prompt: the global website AI context (a setting) and per-run custom
 * instructions (carried on the translation context). Both the text and the
 * markup/JSON prompt builders call this, so a directive can never reach one
 * path but not another.
 */
class System_Prompt_Enricher {

    private const MAX_LENGTH = 500;

    public function __construct(
        private Settings_Service $settings_service,
    ) {}

    /**
     * Append website context + custom instructions to a system prompt.
     *
     * @param string               $prompt  Base system prompt.
     * @param array<string, mixed> $context Translation context; reads 'instructions'.
     * @return string Enriched system prompt.
     */
    public function apply( string $prompt, array $context ): string {
        $website_context = $this->sanitize( $this->settings_service->get_website_ai_context() );
        if ( '' !== $website_context ) {
            $prompt .= "\n\nAdditional context provided by the owner of this website: " . $website_context;
        }

        $instructions = $this->sanitize( (string) ( $context['instructions'] ?? '' ) );
        if ( '' !== $instructions ) {
            $prompt .= "\n\nAdditional instructions: " . $instructions;
        }

        return $prompt;
    }

    private function sanitize( string $value ): string {
        $value = \trim( $value );
        if ( '' === $value ) {
            return '';
        }
        if ( \mb_strlen( $value ) > self::MAX_LENGTH ) {
            $value = \mb_substr( $value, 0, self::MAX_LENGTH );
        }
        $value = (string) \preg_replace( '/\s+/', ' ', $value );
        return \wp_strip_all_tags( $value );
    }
}
