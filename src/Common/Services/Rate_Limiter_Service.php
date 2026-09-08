<?php
/**
 * Rate_Limiter_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;

/**
 * Service for rate limiting API requests.
 * Uses WordPress transients for simple, database-backed rate limiting.
 */
class Rate_Limiter_Service {
    /**
     * Default rate limit (requests per minute).
     */
    private const DEFAULT_LIMIT = 100;

    /**
     * Window duration in seconds (1 minute).
     */
    private const WINDOW_DURATION = 60;

    /**
     * Settings service.
     *
     * @param Settings_Service $settings_service Settings service.
     */
    public function __construct(
        private Settings_Service $settings_service,
    ) {
    }

    /**
     * Check if request is within rate limit.
     *
     * @param int $run_id Run ID to check rate limit for.
     * @return bool True if within limit, false if exceeded.
     */
    public function check_rate_limit( int $run_id ): bool {
        $limit   = $this->get_rate_limit();
        $current = $this->get_current_count( $run_id );

        return $current < $limit;
    }

    /**
     * Increment request count for a run.
     *
     * @param int $run_id Run ID to increment count for.
     * @return int New count after increment.
     */
    public function increment( int $run_id ): int {
        $key     = $this->get_cache_key( $run_id );
        $current = $this->get_current_count( $run_id );
        $new     = $current + 1;

        // Store with auto-expiration after window duration.
        \set_transient( $key, $new, self::WINDOW_DURATION );

        return $new;
    }

    /**
     * Get current request count for a run.
     *
     * @param int $run_id Run ID to get count for.
     * @return int Current request count.
     */
    public function get_current_count( int $run_id ): int {
        $key   = $this->get_cache_key( $run_id );
        $count = \get_transient( $key );

        if ( false === $count ) {
            return 0;
        }

        return \is_numeric( $count ) ? (int) $count : 0;
    }

    /**
     * Get remaining requests before hitting rate limit.
     *
     * @param int $run_id Run ID to check.
     * @return int Remaining requests (0 if limit exceeded).
     */
    public function get_remaining( int $run_id ): int {
        $limit     = $this->get_rate_limit();
        $current   = $this->get_current_count( $run_id );
        $remaining = $limit - $current;

        return \max( 0, $remaining );
    }

    /**
     * Reset rate limit for a run.
     * Useful for testing or manual intervention.
     *
     * @param int $run_id Run ID to reset.
     * @return bool True on success.
     */
    public function reset( int $run_id ): bool {
        $key = $this->get_cache_key( $run_id );
        return \delete_transient( $key );
    }

    /**
     * Get the cache key for a run's rate limit.
     *
     * Uses current minute timestamp for sliding window.
     *
     * @param int $run_id Run ID.
     * @return string Cache key.
     */
    private function get_cache_key( int $run_id ): string {
        $minute = \floor( \time() / self::WINDOW_DURATION );
        return "pllat_ratelimit_{$run_id}_{$minute}";
    }

    /**
     * Get rate limit from settings.
     *
     * @return int Requests per minute limit.
     */
    private function get_rate_limit(): int {
        $limit = $this->settings_service->get_rate_limit();

        /**
         * Filter the rate limit for external processor requests.
         *
         * @param int $limit Requests per minute limit.
         * @return int Filtered limit.
         */
        $filtered = \apply_filters( 'pllat_rate_limit', $limit );

        return \is_numeric( $filtered ) ? (int) $filtered : self::DEFAULT_LIMIT;
    }
}
