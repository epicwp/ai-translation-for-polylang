<?php
namespace PLLAT\Translator\Models\Translations;

\defined( 'ABSPATH' ) || exit;

/**
 * Translation_Term_Meta class.
 *
 * @package PLLAT\Translator\Models\Translations
 */
class Translation_Term_Meta extends Translation_Base {
    /**
     * Get the available fields for translation.
     *
     * @return array
     */
    public static function get_available_fields(): array {
        return \apply_filters( 'pllat_available_term_meta_translation_fields', array() );
    }

    /**
     * Get the available fields for translation for a taxonomy.
     *
     * @param string $taxonomy The taxonomy slug.
     * @return array
     */
    public static function get_available_fields_for_entity( string $taxonomy ): array {
        $fields = \apply_filters(
            'pllat_available_term_meta_translation_fields_for_entity',
            self::get_available_fields(),
            $taxonomy,
        );

        return \array_values( \array_unique( $fields ) );
    }

    /**
     * Get the available fields for translation for a specific term.
     *
     * @param int $id The term ID.
     * @return array
     */
    public static function get_available_fields_for( int $id ): array {
        $fields = \apply_filters(
            'pllat_available_term_meta_translation_fields_for',
            self::get_available_fields(),
            $id,
        );

        return \array_values( \array_unique( $fields ) );
    }
}
