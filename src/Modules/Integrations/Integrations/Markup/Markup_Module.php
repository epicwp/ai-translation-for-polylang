<?php
/**
 * Markup_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Content\Services\Content_Service;
use PLLAT\Integrations\Integrations\Markup\Services\Content_Type_Detector;
use PLLAT\Integrations\Integrations\Markup\Services\Gutenberg_Field_Validator;
use PLLAT\Integrations\Integrations\Markup\Services\HTML_String_Extractor;
use PLLAT\Integrations\Integrations\Markup\Services\Inline_Tag_Replacer;
use PLLAT\Integrations\Integrations\Markup\Services\Markup_Integration;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\Can_Initialize;
use XWP\DI\Interfaces\On_Initialize;

/**
 * Markup-aware translation module.
 *
 * Handles translation of HTML and Gutenberg block content.
 * Loads at priority 8 (before Elementor's 10) so Elementor can override if needed.
 *
 * Always loads — provides core markup/Gutenberg translation support.
 */
#[Module(
    hook: 'init',
    priority: 8,
    handlers: array(
        Markup_Handler::class,
    ),
    services: array(
        Content_Type_Detector::class,
        Inline_Tag_Replacer::class,
        HTML_String_Extractor::class,
        Gutenberg_Field_Validator::class,
        Markup_Integration::class,
        Content_Service::class,
    ),
)]
class Markup_Module implements Can_Initialize, On_Initialize {
    /**
     * Check if module can initialize.
     *
     * @return bool
     */
    public static function can_initialize(): bool {
        // No license gate — integration filters must be available during
        // Action Scheduler processing. Translation engine is license-gated.
        return true;
    }

    /**
     * Initialize module.
     *
     * @return void
     */
    public function on_initialize(): void {
    }
}
