<?php
/**
 * Dashboard_Cache_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Admin
 */

namespace PLLAT\Admin\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Admin\Services\Dashboard_Data_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Keeps the dashboard cache in sync with the pipeline.
 *
 * Listens in every context: run completion happens in Action Scheduler
 * workers (cron, CLI, async requests), not in the admin.
 */
#[Handler( tag: 'init', priority: 11 )]
class Dashboard_Cache_Handler {
    /**
     * Drop the cached dashboard data when a run completes.
     */
    #[Action( tag: 'pllat_run_completed' )]
    public function invalidate_on_run_completed(): void {
        Dashboard_Data_Service::invalidate_cache();
    }
}
