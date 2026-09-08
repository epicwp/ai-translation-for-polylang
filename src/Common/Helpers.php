<?php
declare(strict_types=1);

namespace PLLAT\Common;

\defined( 'ABSPATH' ) || exit;

/**
 * Helper functions for the plugin.
 */
class Helpers {
    /**
     * Get the active Polylang post types
     *
     * @return array The active Polylang post types.
     */
    public static function get_active_post_types(): array {
        if ( ! \function_exists( 'pll_is_translated_post_type' ) ) {
            return array();
        }
        $post_types = \array_filter(
            \get_post_types(),
            static fn( $post_type ) => \pll_is_translated_post_type( $post_type ),
        );
        return \array_values( $post_types );
    }

    /**
     * Get the available Polylang post types that are not excluded
     *
     * @return array The available Polylang post types.
     */
    public static function get_available_post_types(): array {
        $exclude_post_types = array(
            'wp_block',
            'wp_template_part',
            'wp_navigation',
            'shop_order_placehold',
            'shop_order',
        );
        $post_types         = \array_filter(
            self::get_active_post_types(),
            static fn( $post_type ) => ! \in_array( $post_type, $exclude_post_types ),
        );
        return \array_values( $post_types );
    }

    /**
     * Get the active Polylang taxonomies
     *
     * @return array The active Polylang taxonomies.
     */
    public static function get_active_taxonomies(): array {
        if ( ! \function_exists( 'pll_is_translated_taxonomy' ) ) {
            return array();
        }
        $taxonomies = \array_filter(
            \get_taxonomies(),
            static fn( $taxonomy ) => \pll_is_translated_taxonomy( $taxonomy ),
        );
        return \array_values( $taxonomies );
    }

    /**
     * Get the available Polylang taxonomies that are not excluded
     *
     * @return array The available Polylang taxonomies.
     */
    public static function get_available_taxonomies(): array {
        $exclude_taxonomies = array();
        $taxonomies         = \array_filter(
            self::get_active_taxonomies(),
            static fn( $taxonomy ) => ! \in_array( $taxonomy, $exclude_taxonomies ),
        );
        return \array_values( $taxonomies );
    }

    /**
     * Get all meta data as a flatten array
     *
     * @param int   $post_id         The post ID.
     * @param array $available_fields Optional. If provided, only return these meta keys.
     * @return array The flatten post meta.
     */
    public static function get_flatten_post_meta( int $post_id, array $available_fields = array() ): array {
        $post_meta = \get_post_meta( $post_id );
        if ( ! $post_meta ) { // phpcs:ignore SlevomatCodingStandard.ControlStructures.DisallowEmpty.DisallowedEmpty
            return array();
        }
        $flattened = \array_map(
            static fn( $row ) => $row[0] ?? null,
            $post_meta,
        );

        // If available fields specified, filter to only those keys.
        if ( 0 === \count( $available_fields ) ) {
            return $flattened;
        }

        return \array_intersect_key( $flattened, \array_flip( $available_fields ) );
    }

    /**
     * Get all meta data as a flatten array
     *
     * @param int $term_id The term ID.
     * @return array The flatten term meta.
     */
    public static function get_flatten_term_meta( int $term_id ): array {
        $term_meta = \get_term_meta( $term_id );
        if ( ! $term_meta ) { // phpcs:ignore SlevomatCodingStandard.ControlStructures.DisallowEmpty.DisallowedEmpty
            return array();
        }
        return \array_map(
            static fn( $row ) => $row[0] ?? null,
            $term_meta,
        );
    }

    /**
     * Helper function to find the changed fields between two arrays
     *
     * Supports both scalar values (strings, numbers) and complex values (arrays, objects).
     * For complex values, uses serialize() for accurate comparison.
     *
     * @param array $before_array The before array.
     * @param array $after_array The after array.
     * @param array $available_fields The available fields.
     * @return array The changed fields.
     */
    public static function get_changed_fields( array $before_array, array $after_array, array $available_fields ): array {
        $changes = array();
        foreach ( $available_fields as $field ) {
            $before_value = $before_array[ $field ] ?? null;
            $after_value  = $after_array[ $field ] ?? null;

            // For complex types (arrays, objects), serialize for comparison.
            if ( \is_array( $before_value ) || \is_array( $after_value ) ) {
                $before_serialized = \maybe_serialize( $before_value );
                $after_serialized  = \maybe_serialize( $after_value );

                if ( $before_serialized === $after_serialized ) {
                    continue;
                }
            } elseif ( $before_value === $after_value ) {
                // For scalar values, use direct comparison.
                continue;
            }

            $changes[] = $field;
        }
        return $changes;
    }

    /**
     * Adds indexes from starting from 1
     *
     * @param array $strings The strings.
     * @return array The numbered strings.
     */
    public static function number_strings_array( array $strings ): array {
        if ( array() === $strings ) {
            return array();
        }
        return \array_combine( \range( 1, \count( $strings ) ), $strings );
    }

    /**
     * Get the post statuses eligible for translation discovery and statistics.
     *
     * Default is 'publish', plus 'inherit' when Polylang media translation is
     * enabled (i.e. 'attachment' is an active translated post type). Use the
     * `pllat_translatable_post_statuses` filter to override.
     *
     * @return array<int, string> Post status slugs.
     */
    public static function get_translatable_post_statuses(): array {
        /**
         * Filter the post statuses that are eligible for translation.
         *
         * @param array<int, string> $statuses Post status slugs.
         */
        $statuses = \apply_filters(
            'pllat_translatable_post_statuses',
            self::post_statuses_for( self::get_active_post_types() ),
        );

        return \array_values( \array_unique( $statuses ) );
    }

    /**
     * Post statuses in scope for a given set of active post types.
     *
     * Pure: callers pass their already-resolved active post types (from the
     * injected Language_Manager), so this never touches Polylang directly.
     * Attachments use the 'inherit' status, so 'inherit' is in scope exactly
     * when Polylang has media translation enabled (i.e. 'attachment' is an
     * active translated post type).
     *
     * @param array<int, string> $active_post_types
     * @return array<int, string>
     */
    public static function post_statuses_for( array $active_post_types ): array {
        $statuses = array( 'publish' );
        if ( \in_array( 'attachment', $active_post_types, true ) ) {
            $statuses[] = 'inherit';
        }
        return $statuses;
    }

    /**
     * Encodes an array to json
     *
     * @param array $data The data.
     * @param int   $options The options.
     * @return string The encoded json.
     */
    public static function encode_json( $data, int $options = \JSON_UNESCAPED_UNICODE ): string {
        $json = \wp_json_encode( $data, $options );
        if ( false === $json ) {
            // It's okay to not escape json_last_error_msg in an exception message.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \Exception( 'Error encoding JSON: ' . \json_last_error_msg() );
        }
        return $json;
    }

    /**
     * Decodes a json string to an array
     *
     * @param string $json The json.
     * @param bool   $associative The associative.
     * @return array The decoded json.
     */
    public static function decode_json( string $json, bool $associative = true ): array {
        $data = \json_decode( $json, $associative );
        if ( null === $data && JSON_ERROR_NONE !== \json_last_error() ) {
            // It's okay to not escape json_last_error_msg in an exception message.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \Exception( 'Error decoding JSON: ' . \json_last_error_msg() );
        }
        // If $data is null after decoding (e.g., decoding the string "null"), return empty array.
        return (array) ( $data ?? array() );
    }

    /**
     * Set the max execution time for the current request.
     *
     * @param int $time_limit The time limit.
     * @param int $desired_limit The desired limit.
     * @return int The max execution time.
     */
    public static function set_max_execution_time( int $time_limit, int $desired_limit = 120 ): int {
        $php_max_execution = (int) \ini_get( 'max_execution_time' );

        // If PHP has unlimited execution time.
        if ( 0 === $php_max_execution ) {
            return $desired_limit;
        }

        // If PHP timeout is very low (less than 30 seconds), keep original.
        if ( $php_max_execution < 30 ) {
            return $time_limit;
        }

        // Calculate safe timeout (leave 20% buffer or minimum 10 seconds).
        $buffer   = \max( 10, (int) ( $php_max_execution * 0.2 ) );
        $safe_max = $php_max_execution - $buffer;

        // Return the minimum of the desired timeout and the safe max.
        return \min( $desired_limit, $safe_max );
    }
}
