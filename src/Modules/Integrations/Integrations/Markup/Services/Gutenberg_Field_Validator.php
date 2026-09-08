<?php
/**
 * Gutenberg_Field_Validator class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Integrations/Markup
 */

declare(strict_types=1);

namespace PLLAT\Integrations\Integrations\Markup\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Services\Field_Validators\Base_Field_Validator;

/**
 * Field validator for Gutenberg block attributes.
 *
 * Extends base validator with Gutenberg-specific keys to ignore.
 * Used when extracting translatable content from parsed blocks.
 */
class Gutenberg_Field_Validator extends Base_Field_Validator {
    /**
     * Constructor.
     *
     * Adds Gutenberg-specific non-translatable keys to the base validator.
     */
    public function __construct() {
        parent::__construct(
            // Additional translatable keys specific to Gutenberg.
            array(
                'content',   // Core block content.
                'citation',  // Quote blocks.
                'value',     // Various blocks.
                'text',      // Button text, etc.
            ),
            // Additional non-translatable keys specific to Gutenberg.
            array(
                // Block structure.
                'blockName',
                'clientId',
                'isValid',
                'originalContent',
                'validationIssues',
                // Block settings.
                'ref',              // Reusable block reference.
                'tagName',          // HTML tag names.
                'lock',             // Block locking settings.
                'anchor',           // HTML anchors.
                'className',        // CSS classes.
                'align',            // Alignment settings.
                'dropCap',          // Typography setting.
                'fontSize',         // Typography.
                'level',            // Heading level.
                'textColor',        // Color settings.
                'backgroundColor',
                'gradient',
                'linkTarget',
                'rel',
                'mediaType',
                'mediaId',
                'mediaSizeSlug',
                'type',             // Block type.
                'providerNameSlug',
                'responsive',
                'verticalAlignment',
                'isStackedOnMobile',
                'templateLock',
                'allowedBlocks',
                'orientation',
                // Media.
                'id',
                'url',
                'alt',              // Note: alt text IS translatable but often empty or auto-generated.
                'href',
                'linkDestination',
                'sizeSlug',
                'width',
                'height',
                'aspectRatio',
                'scale',
                'focalPoint',
                // Layout.
                'layout',
                'style',
                'spacing',
                'margin',
                'padding',
                'blockGap',
                'dimensions',
                'minHeight',
                'position',
                'sticky',
                'top',
            ),
            // Additional non-translatable patterns.
            array(
                'color',
                'background',
                'size',
                'width',
                'height',
            ),
            // Additional translatable patterns.
            array(
                'content',
                'caption',
                'citation',
            ),
        );
    }
}
