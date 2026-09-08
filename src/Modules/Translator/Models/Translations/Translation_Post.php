<?php
namespace PLLAT\Translator\Models\Translations;

\defined( 'ABSPATH' ) || exit;

/**
 * Translation_Post class.
 *
 * @package PLLAT\Translator\Models\Translations
 */
class Translation_Post extends Translation_Base {
    /**
     * Get the available fields for translation.
     *
     * @return array
     */
    public static function get_available_fields(): array {
        return \apply_filters(
            'pllat_available_post_translation_fields',
            array(
                'post_title',
                'post_content',
                'post_excerpt',
                'post_name',
            ),
        );
    }

    /**
     * Get the available fields for translation for a specific post.
     *
     * @param int $id The post ID.
     * @return array
     */
    public static function get_available_fields_for( int $id ): array {
        return \apply_filters(
            'pllat_available_post_translation_fields_for',
            self::get_available_fields(),
            $id,
        );
    }
}
