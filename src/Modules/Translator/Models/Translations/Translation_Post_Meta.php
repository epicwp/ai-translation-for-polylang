<?php
namespace PLLAT\Translator\Models\Translations;

\defined( 'ABSPATH' ) || exit;

/**
 * Translation_Post_Meta class.
 *
 * @package PLLAT\Translator\Models\Translations
 */
class Translation_Post_Meta extends Translation_Base {
    /**
     * Get the available fields for translation.
     *
     * @return array
     */
    public static function get_available_fields(): array {
        return \apply_filters(
            'pllat_available_post_meta_translation_fields',
            array(
                // WordPress core. Plugin-owned keys come from their modules (WooCommerce, SEO).
                '_wp_attachment_image_alt',
            ),
        );
    }

    /**
     * Get the available fields for translation for a post type.
     *
     * @param string $post_type The post type slug.
     * @return array
     */
    public static function get_available_fields_for_entity( string $post_type ): array {
        $fields = \apply_filters(
            'pllat_available_post_meta_translation_fields_for_entity',
            self::get_available_fields(),
            $post_type,
        );

        return \array_values( \array_unique( $fields ) );
    }

    /**
     * Get the available fields for translation for a specific post.
     *
     * @param int $id The post ID.
     * @return array
     */
    public static function get_available_fields_for( int $id ): array {
        $fields = \apply_filters(
            'pllat_available_post_meta_translation_fields_for',
            self::get_available_fields(),
            $id,
        );

        return \array_values( \array_unique( $fields ) );
    }
}
