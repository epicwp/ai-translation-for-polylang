<?php
/**
 * Shared plugin bootstrap, required by both main files right after their
 * defines (see PLLAT_PLUGIN_FILE, PLLAT_PLUGIN_DIR, PLLAT_PLUGIN_VERSION).
 *
 * Loads the autoloader, the compatibility functions and Action Scheduler,
 * registers the early hooks that cannot wait for the DI container, and
 * schedules the app on plugins_loaded. Everything edition-specific stays in
 * the main files (header, constants, the edition guard).
 *
 * @package Polylang AI Automatic Translation
 */

declare(strict_types=1);

\defined( 'ABSPATH' ) || exit;

// Load the autoloader.
require_once PLLAT_PLUGIN_DIR . 'vendor/autoload_packages.php';

// Compatibility functions. Not a composer `files` autoload entry: that would run
// on every `vendor/autoload.php` include (phpunit, phpstan, mozart) and the
// ABSPATH guard would silently abort those tools.
require_once PLLAT_PLUGIN_DIR . 'src/Common/Functions/pllat-compat-fns.php';

// Action Scheduler must be loaded separately.
require_once PLLAT_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Force Polylang admin context for Action Scheduler async requests.
// Must run before Polylang's plugins_loaded priority 1 hook.
// See Polylang_Context_Forcer class documentation for details.
\PLLAT\Common\Services\Polylang_Context_Forcer::init();

// Trigger immediate HTTP loopback when pllat_process_run is enqueued so workers
// start within ~1 s instead of waiting for the next WP-Cron cycle (up to 20 s).
\PLLAT\Common\Services\Async_Dispatcher::init();

// Register activation hook for fresh installations.
register_activation_hook(
    PLLAT_PLUGIN_FILE,
    static function () {
        \PLLAT\Common\Installer\Installer::install();
    },
);

// Ensure database schema is up to date in any context.
// init:11 fires after Translation_Index_Module's init:10 so the eager
// `do_action( 'pllat_pipeline_tick' )` at the end of maybe_upgrade()
// reaches a registered Pipeline_Tick_Handler. Covers admin, REST, CLI,
// and cron paths that `admin_init` misses — WP-CLI plugin install/activate,
// WP-Core auto-update via WP-Cron, and REST-first / headless deployments
// would otherwise run the new plugin code against the old schema until
// an admin happened to visit wp-admin.
add_action(
    'init',
    static function () {
        ( new \PLLAT\Common\Installer\Installer() )->maybe_upgrade();
    },
    11,
);

// Clean up scheduled actions on deactivation.
register_deactivation_hook(
    PLLAT_PLUGIN_FILE,
    static function (): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }
        as_unschedule_all_actions( 'pllat_internal_link_check' );
    },
);

xwp_load_app(
    app: array(
        'app_file'       => PLLAT_PLUGIN_FILE,
        'app_id'         => 'pllat',
        'app_module'     => \PLLAT\App::class,
        'app_version'    => PLLAT_PLUGIN_VERSION,
        'cache_app'      => false,
        'cache_defs'     => false,
        'cache_dir'      => PLLAT_PLUGIN_DIR . 'cache',
        'cache_hooks'    => false,
        'public'         => true,
        'use_attributes' => true,
        'use_autowiring' => true,
    ),
    hook: 'plugins_loaded',
    priority: 0,
);
