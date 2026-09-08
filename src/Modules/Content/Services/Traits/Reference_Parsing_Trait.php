<?php
declare(strict_types=1);

namespace PLLAT\Content\Services\Traits;

\defined( 'ABSPATH' ) || exit;

trait Reference_Parsing_Trait {
    /**
     * Parse a reference key into type and field components.
     *
     * Expected formats:
     * - core:        "post_title" (no prefix)
     * - meta:        "_meta|custom_key"
     * - custom_data: "_custom_data|field_name"
     *
     * Extensible via the 'pllat_parse_reference' filter.
     *
     * @param string $reference The reference key to parse.
     * @return array{type:string,field:string} Parsed info with 'type' and 'field'.
     */
    public function parse_reference( string $reference ): array {
        if ( \str_starts_with( $reference, '_meta|' ) ) {
            return array(
                'field' => \str_replace( '_meta|', '', $reference ),
                'type'  => 'meta',
            );
        }
        if ( \str_starts_with( $reference, '_custom_data|' ) ) {
            return array(
                'field' => \str_replace( '_custom_data|', '', $reference ),
                'type'  => 'custom_data',
            );
        }
        $parsed = \apply_filters( 'pllat_parse_reference', array(), $reference );
        if ( \count( $parsed ) > 0 ) {
            return $parsed;
        }
        return array(
            'field' => $reference,
            'type'  => 'core',
        );
    }
}
