<?php
declare(strict_types=1);

namespace PLLAT\Common\Utils;

\defined( 'ABSPATH' ) || exit;

/**
 * Memory management utility.
 * Monitors and manages PHP memory usage to prevent exhaustion.
 */
class Memory_Manager {
    /**
     * Default memory threshold percentage (80%).
     */
    private const DEFAULT_THRESHOLD = 0.8;

    /**
     * Check if memory usage is approaching the configured limit.
     *
     * @param float $threshold_percent Threshold as decimal (0.8 = 80%). Defaults to 80%.
     * @return bool True if memory usage exceeds threshold.
     */
    public function is_approaching_limit( float $threshold_percent = self::DEFAULT_THRESHOLD ): bool {
        $memory_limit = \ini_get( 'memory_limit' );

        // Guard: No memory limit set (unlimited).
        if ( '-1' === $memory_limit ) {
            return false;
        }

        $limit_bytes = $this->convert_to_bytes( $memory_limit );

        // Guard: Invalid or zero memory limit.
        if ( 0 === $limit_bytes ) {
            return false;
        }

        $current_usage = \memory_get_usage( true );
        $threshold     = $limit_bytes * $threshold_percent;

        return $current_usage >= $threshold;
    }

    /**
     * Get current memory usage as percentage of limit.
     *
     * @return float|null Percentage (0.0 to 1.0), or null if no limit set.
     */
    public function get_usage_percent(): ?float {
        $memory_limit = \ini_get( 'memory_limit' );

        // Guard: No memory limit.
        if ( '-1' === $memory_limit ) {
            return null;
        }

        $limit_bytes = $this->convert_to_bytes( $memory_limit );

        // Guard: Invalid limit.
        if ( 0 === $limit_bytes ) {
            return null;
        }

        $current_usage = \memory_get_usage( true );

        return $current_usage / $limit_bytes;
    }

    /**
     * Get current memory usage in bytes.
     *
     * @param bool $real_usage If true, gets real allocated memory. If false, gets reported usage.
     * @return int Memory usage in bytes.
     */
    public function get_usage_bytes( bool $real_usage = true ): int {
        return \memory_get_usage( $real_usage );
    }

    /**
     * Get memory limit in bytes.
     *
     * @return int|null Memory limit in bytes, or null if unlimited.
     */
    public function get_limit_bytes(): ?int {
        $memory_limit = \ini_get( 'memory_limit' );

        // Guard: No memory limit.
        if ( '-1' === $memory_limit ) {
            return null;
        }

        $limit_bytes = $this->convert_to_bytes( $memory_limit );

        return $limit_bytes > 0 ? $limit_bytes : null;
    }

    /**
     * Trigger garbage collection.
     * Only calls gc_collect_cycles() if GC is enabled.
     *
     * @return void
     */
    public function collect_garbage(): void {
        if ( \gc_enabled() ) {
            \gc_collect_cycles();
        }
    }

    /**
     * Convert PHP shorthand memory notation to bytes.
     * Supports: K (kilobytes), M (megabytes), G (gigabytes).
     *
     * @param string $value Memory value (e.g., "128M", "1G", "512K").
     * @return int Bytes.
     */
    private function convert_to_bytes( string $value ): int {
        $value = \trim( $value );

        // Guard: Empty value.
        if ( empty( $value ) ) {
            return 0;
        }

        $last  = \strtolower( $value[ \strlen( $value ) - 1 ] );
        $value = (int) $value;

        switch ( $last ) {
            case 'g':
                $value *= 1024;
                // Fall through to multiply by MB.
            case 'm':
                $value *= 1024;
                // Fall through to multiply by KB.
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
