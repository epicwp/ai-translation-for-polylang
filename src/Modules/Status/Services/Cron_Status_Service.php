<?php
/**
 * Cron_Status_Service file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Services
 */

declare(strict_types=1);

namespace PLLAT\Status\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Reads WP-Cron configuration and freshness signals.
 *
 * Used by Cron preflight to distinguish three states:
 *   - internal WP-Cron (DISABLE_WP_CRON not set / false)
 *   - server cron configured and processing events
 *   - server cron configured but events are piling up
 *
 * Detection of "events piling up" relies on `_get_cron_array()`: when
 * cron fires, WP removes events for that timestamp from the array. Any
 * timestamp still in the array that is older than `now - threshold`
 * means cron has not run for at least `threshold` seconds.
 */
class Cron_Status_Service {
    public function is_disable_wp_cron_defined(): bool {
        return \defined( 'DISABLE_WP_CRON' ) && true === \DISABLE_WP_CRON;
    }

    /**
     * Seconds the oldest overdue cron event has been waiting.
     *
     * Returns null when no event is overdue beyond the threshold,
     * i.e. cron is firing on schedule (or there are no scheduled
     * events at all, which is also fine).
     */
    public function get_oldest_overdue_seconds( int $threshold_seconds ): ?int {
        if ( ! \function_exists( '_get_cron_array' ) ) {
            return null;
        }

        $crons = \_get_cron_array();
        if ( ! \is_array( $crons ) || 0 === \count( $crons ) ) {
            return null;
        }

        $now          = \time();
        $overdue_ages = \array_filter(
            \array_map(
                static fn( $timestamp ): int => $now - (int) $timestamp,
                \array_keys( $crons ),
            ),
            static fn( int $age ): bool => $age >= $threshold_seconds,
        );

        return 0 === \count( $overdue_ages ) ? null : \max( $overdue_ages );
    }
}
