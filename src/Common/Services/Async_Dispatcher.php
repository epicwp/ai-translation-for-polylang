<?php
declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Forces immediate async dispatch for specific Action Scheduler actions.
 *
 * Action Scheduler's normal async dispatch happens on the 'shutdown' hook,
 * which can cause 10-20 second delays before workers start. This class hooks
 * into 'action_scheduler_stored_action' (fires immediately after DB insert)
 * and triggers an HTTP loopback request so AS picks up the action within ~1 s.
 *
 * Uses reflection to access ActionScheduler's protected async_request property.
 * Degrades gracefully to normal WP-Cron processing if the loopback fails.
 */
class Async_Dispatcher {

    /**
     * Hooks where the customer is actively waiting and we want AS to start
     * within ~1s instead of up to 60s.
     *
     * - pllat_process_run: a translation worker invocation. Customer
     *   has just clicked Translate and is staring at a "starting…" state.
     * - pllat_translation_index_prime: the initial prime walk after a
     *   fresh install or after schedule_prime() is called from the
     *   Re-run prime dialog. Customer has either just installed the
     *   plugin (and is on the dashboard waiting for counts) or just
     *   clicked Re-run prime in the preflight dialog (and is polling
     *   for completion). Without this, the prime can sit pending for
     *   minutes on low-traffic / saturated-cron sites.
     */
    private const INSTANT_HOOKS = array(
        'pllat_process_run',
        'pllat_translation_index_prime',
    );

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;
        \add_action( 'action_scheduler_stored_action', array( self::class, 'maybe_force_dispatch' ), 5 );
    }

    public static function maybe_force_dispatch( int $action_id ): void {
        try {
            if ( ! \class_exists( 'ActionScheduler' ) ) {
                return;
            }

            $action   = \ActionScheduler::store()->fetch_action( $action_id );
            $schedule = $action->get_schedule();

            if ( ! ( $schedule instanceof \ActionScheduler_NullSchedule ) ) {
                return;
            }

            if ( ! \in_array( $action->get_hook(), self::INSTANT_HOOKS, true ) ) {
                return;
            }

            self::force_async_dispatch();

        } catch ( \Throwable $e ) {
            // Degrade gracefully — WP-Cron will pick it up.
        }
    }

    private static function force_async_dispatch(): void {
        $runner = \ActionScheduler::runner();
        if ( ! $runner ) {
            return;
        }

        $reflection = new \ReflectionClass( $runner );
        if ( ! $reflection->hasProperty( 'async_request' ) ) {
            return;
        }

        $property = $reflection->getProperty( 'async_request' );
        $property->setAccessible( true );
        $async_request = $property->getValue( $runner );

        if ( $async_request && \method_exists( $async_request, 'maybe_dispatch' ) ) {
            $async_request->maybe_dispatch();
        }
    }
}
