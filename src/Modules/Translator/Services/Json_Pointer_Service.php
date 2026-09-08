<?php
/**
 * Json_Pointer_Service class file.
 *
 * Utilities for JSON Pointer (RFC 6901) operations.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * JSON Pointer (RFC 6901) utility service.
 *
 * Provides escape/unescape operations and array navigation using JSON Pointer paths.
 */
class Json_Pointer_Service {
    /**
     * Escape a string for use in JSON Pointer path segment.
     *
     * Per RFC 6901: ~ becomes ~0, / becomes ~1.
     *
     * @param string $segment The path segment to escape.
     * @return string Escaped segment.
     */
    public function escape( string $segment ): string {
        return \str_replace( array( '~', '/' ), array( '~0', '~1' ), $segment );
    }

    /**
     * Unescape a JSON Pointer path segment.
     *
     * Per RFC 6901: ~1 becomes /, ~0 becomes ~.
     * Order matters: ~1 must be replaced before ~0.
     *
     * @param string $segment The escaped segment.
     * @return string Unescaped segment.
     */
    public function unescape( string $segment ): string {
        return \str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );
    }

    /**
     * Parse a JSON Pointer path into segments.
     *
     * @param string $path JSON Pointer path (e.g., "/0/attrs/title").
     * @return array<string|int> Array of path segments.
     */
    public function parse( string $path ): array {
        $parts = \explode( '/', \ltrim( $path, '/' ) );

        return \array_map(
            function ( string $part ): string|int {
                $unescaped = $this->unescape( $part );
                return \is_numeric( $unescaped ) ? (int) $unescaped : $unescaped;
            },
            $parts,
        );
    }

    /**
     * Get value at a JSON Pointer path from an array.
     *
     * @param array<mixed> $data The data array.
     * @param string       $path JSON Pointer path.
     * @param mixed        $default Default value if path doesn't exist.
     * @return mixed Value at path or default.
     */
    public function get( array $data, string $path, mixed $default = null ): mixed {
        $parts   = $this->parse( $path );
        $current = $data;

        foreach ( $parts as $part ) {
            if ( ! isset( $current[ $part ] ) ) {
                return $default;
            }
            $current = $current[ $part ];
        }

        return $current;
    }

    /**
     * Set value at a JSON Pointer path in an array.
     *
     * @param array<mixed> $data  The data array (modified by reference).
     * @param string       $path  JSON Pointer path.
     * @param mixed        $value Value to set.
     * @return array<mixed> Modified data array.
     */
    public function set( array $data, string $path, mixed $value ): array {
        $parts    = $this->parse( $path );
        $current  = &$data;
        $last_key = \array_pop( $parts );

        foreach ( $parts as $part ) {
            if ( ! isset( $current[ $part ] ) ) {
                return $data; // Path doesn't exist.
            }
            $current = &$current[ $part ];
        }

        if ( null !== $last_key ) {
            $current[ $last_key ] = $value;
        }

        return $data;
    }

    /**
     * Get reference to value at a JSON Pointer path.
     *
     * Useful for modifying nested values in place.
     *
     * @param array<mixed> $data The data array.
     * @param string       $path JSON Pointer path.
     * @return mixed|null Reference to value or null if not found.
     */
    public function &get_reference( array &$data, string $path ): mixed {
        $parts   = $this->parse( $path );
        $current = &$data;

        foreach ( $parts as $part ) {
            if ( ! isset( $current[ $part ] ) ) {
                $null = null;
                return $null;
            }
            $current = &$current[ $part ];
        }

        return $current;
    }

    /**
     * Extract the parent path from a JSON Pointer path.
     *
     * @param string $path Full path (e.g., "/0/innerContent/1").
     * @return string Parent path (e.g., "/0/innerContent").
     */
    public function get_parent_path( string $path ): string {
        $parts = \explode( '/', \ltrim( $path, '/' ) );
        \array_pop( $parts );

        if ( 0 === \count( $parts ) ) {
            return '';
        }

        return '/' . \implode( '/', $parts );
    }

    /**
     * Extract block path from an innerContent path.
     *
     * Removes the /innerContent/N suffix to get the block path.
     *
     * @param string $path Path like "/0/innerContent/1" or "/0/innerBlocks/2/innerContent/0".
     * @return string Block path like "/0" or "/0/innerBlocks/2".
     */
    public function get_block_path_from_inner_content_path( string $path ): string {
        $parts             = \explode( '/', \ltrim( $path, '/' ) );
        $inner_content_pos = \array_search( 'innerContent', $parts, true );

        if ( false === $inner_content_pos ) {
            return $path;
        }

        $block_parts = \array_slice( $parts, 0, $inner_content_pos );

        if ( 0 === \count( $block_parts ) ) {
            return '';
        }

        return '/' . \implode( '/', $block_parts );
    }
}
