<?php
namespace PLLAT\Translator\Models\Translations;

\defined( 'ABSPATH' ) || exit;

/**
 * Translation_Term class.
 *
 * @package PLLAT\Translator\Models\Translations
 */
class Translation_Term extends Translation_Base {
    /**
     * Get the available fields for translation.
     *
     * @return array
     */
    public static function get_available_fields(): array {
        return \apply_filters(
            'pllat_available_term_translation_fields',
            array(
                'name',
                'description',
                'slug',
            ),
        );
    }

    /**
     * Get the available fields for translation for a specific term.
     *
     * @param int $id The term ID.
     * @return array
     */
    public static function get_available_fields_for( int $id ): array {
        return \apply_filters(
            'pllat_available_term_translation_fields_for',
            self::get_available_fields(),
            $id,
        );
    }
}
