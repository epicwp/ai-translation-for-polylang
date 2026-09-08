<?php
namespace PLLAT\Translator\Models\Interfaces;

\defined( 'ABSPATH' ) || exit;

interface Translation {
    /**
     * Get the available fields for translation.
     *
     * @return array
     */
    public static function get_available_fields(): array;

    /**
     * Get the available fields for translation for a specific ID.
     *
     * @param int $id The ID.
     * @return array
     */
    public static function get_available_fields_for( int $id ): array;
}
