<?php
/**
 * Job_Cleanup_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Cleanup
 */

declare(strict_types=1);

namespace PLLAT\Cleanup\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\Job_Cleanup_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Schedules and executes daily job cleanup.
 *
 * Removes completed/failed jobs older than the retention period
 * to prevent unbounded table growth.
 */
#[Handler(
    tag: 'init',
    priority: 16,
    context: Handler::CTX_CRON | Handler::CTX_ADMIN | Handler::CTX_AJAX | Handler::CTX_CLI,
)]
class Job_Cleanup_Handler {
    /**
     * Daily cleanup hook fired by Action Scheduler.
     */
    private const HOOK_CLEANUP_OLD_RUNS = 'pllat_sync_cleanup_old_runs';
    /**
     * Constructor.
     *
     * @param Job_Cleanup_Service $cleanup_service Job cleanup service.
     */
    public function __construct(
        private Job_Cleanup_Service $cleanup_service,
    ) {
        $this->ensure_cron_scheduled();
    }

    /**
     * Execute job cleanup.
     *
     * @return void
     */
    #[Action( tag: self::HOOK_CLEANUP_OLD_RUNS )]
    public function execute_cleanup(): void {
        try {
            $this->cleanup_service->cleanup();
        } catch ( \Throwable $e ) {
            \do_action( 'pllat_log_error', 'Job cleanup failed: ' . $e->getMessage() );
        }
    }

    /**
     * Ensure cleanup cron is scheduled (daily).
     *
     * @return void
     */
    private function ensure_cron_scheduled(): void {
        if ( \as_next_scheduled_action( self::HOOK_CLEANUP_OLD_RUNS ) ) {
            return;
        }

        \as_schedule_recurring_action(
            \strtotime( 'tomorrow 04:00' ),
            DAY_IN_SECONDS,
            self::HOOK_CLEANUP_OLD_RUNS,
            array(),
            'pllat-sync',
        );
    }
}
