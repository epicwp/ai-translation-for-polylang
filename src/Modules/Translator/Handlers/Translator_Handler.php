<?php

namespace PLLAT\Translator\Handlers;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Translator handler.
 */
#[Handler( tag: 'init', priority: 10 )]
class Translator_Handler {
    /**
     * Constructor.
     */
    public function __construct() {
    }

    /**
     * Filter into the system prompt for translations by adding website context from settings.
     *
     * @param string $prompt    System prompt.
     * @param string $from      Source language code.
     * @param string $to        Target language code.
     * @param array  $context   Context data including reference.
     * @return string System prompt.
     */
    #[Filter( tag: 'pllat_translation_system_prompt', priority: 10 )]
    public function filter_translation_system_prompt( string $prompt, string $from, string $to, array $context ): string {
        return $this->add_slug_instructions( $prompt, $context );
    }

    /**
     * Add slug-specific instructions to system prompt.
     *
     * @param string $prompt  System prompt.
     * @param array  $context Context data.
     * @return string System prompt.
     */
    private function add_slug_instructions( string $prompt, array $context ): string {
        $reference = $context['reference'] ?? '';

        // Check if this is a slug field (term slug or post slug).
        if ( 'slug' === $reference || 'post_name' === $reference ) {
            $prompt .= ' For URL slugs: provide only the translated slug, no explanations.';
        }

        return $prompt;
    }
}
