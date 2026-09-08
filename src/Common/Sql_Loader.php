<?php
/**
 * SQL Loader class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common;

\defined( 'ABSPATH' ) || exit;

/**
 * Helper class for loading SQL queries from files.
 * Keeps SQL separate from PHP code for better readability and maintainability.
 */
class Sql_Loader {
    /**
     * Cache for loaded SQL queries.
     *
     * @var array<string, string>
     */
    private static array $cache = array();

    /**
     * Load SQL query from file with optional template variable replacement.
     *
     * @param string               $relative_path Path relative to plugin's sql directory (e.g., 'post_list/get_posts_pending.sql').
     * @param array<string,string> $replacements  Optional template variable replacements (e.g., ['{jobs_table}' => 'wp_pllat_claims']).
     * @return string The SQL query with replacements applied.
     * @throws \RuntimeException If file cannot be read.
     */
    public static function load( string $relative_path, array $replacements = array() ): string {
        // Build cache key including replacements for proper caching.
        $cache_key = self::get_cache_key( $relative_path, $replacements );

        // Return cached version if available.
        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        // Build absolute path to SQL file.
        $file_path = self::get_file_path( $relative_path );

        // Validate file exists and is readable.
        if ( ! \file_exists( $file_path ) || ! \is_readable( $file_path ) ) {
            throw new \RuntimeException(
                \sprintf(
                    'SQL file not found or not readable: %s',
                    \esc_html( $file_path ),
                ),
            );
        }

        // Load SQL content.
        $sql = \file_get_contents( $file_path );

        if ( false === $sql ) {
            throw new \RuntimeException(
                \sprintf(
                    'Failed to read SQL file: %s',
                    \esc_html( $file_path ),
                ),
            );
        }

        // Apply template variable replacements.
        if ( \count( $replacements ) > 0 ) {
            $sql = \strtr( $sql, $replacements );
        }

        // Cache and return.
        self::$cache[ $cache_key ] = $sql;

        return $sql;
    }

    /**
     * Clear the SQL cache.
     * Useful for testing or when SQL files change during development.
     *
     * @return void
     */
    public static function clear_cache(): void {
        self::$cache = array();
    }

    public static function get_cache_key( string $relative_path, array $replacements = array() ): string {
        $json = \wp_json_encode( $replacements );
        return $relative_path . \md5( false !== $json ? $json : '' );
    }

    public static function get_file_path( string $relative_path ): string {
        $sql_dir = \dirname( __DIR__ ) . '/Sql';
        return $sql_dir . '/' . $relative_path;
    }
}
