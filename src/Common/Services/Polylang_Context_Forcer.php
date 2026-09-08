<?php
declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Forces Polylang admin context during Action Scheduler async execution.
 *
 * ## The Problem
 *
 * When translating WooCommerce products via Action Scheduler, Polylang's admin classes
 * aren't loaded because of how Polylang determines its execution context.
 *
 * At `plugins_loaded` priority 1, Polylang defines:
 * ```php
 * define( 'PLL_ADMIN', wp_doing_cron() || WP_CLI || is_admin() );
 * ```
 *
 * During Action Scheduler's HTTP loopback requests, ALL THREE checks return `false`:
 * - `wp_doing_cron()` = false (AS doesn't use WP-Cron's execution path)
 * - `WP_CLI` = false (not a CLI request)
 * - `is_admin()` = false (AS uses admin-ajax.php but without admin context)
 *
 * ## The Cascade of Failures
 *
 * Without `PLL_ADMIN = true`:
 * 1. Polylang loads `PLL_Frontend` instead of `PLL_Admin`
 * 2. Polylang for WooCommerce (PLLWC) only creates `PLLWC_Admin_Taxonomies` when `$polylang instanceof PLL_Admin`
 * 3. Without `PLLWC_Admin_Taxonomies`, callbacks like `fix_term_thumbnail` are registered with NULL objects
 * 4. NULL callbacks crash product copy mid-process with `call_user_func_array()` errors
 * 5. Polylang's bidirectional sync then deletes data from the source product (SKU, price, thumbnails)
 *
 * ## Why This Solution is Correct
 *
 * Polylang's own context detection already treats cron/CLI as admin context:
 * ```php
 * define( 'PLL_ADMIN', wp_doing_cron() || WP_CLI || is_admin() );
 * ```
 *
 * This is because background tasks need full editing capabilities. Action Scheduler's
 * HTTP loopback is functionally equivalent to cron - it's a background process that
 * copies/creates content. The issue is that AS doesn't trigger `wp_doing_cron()`.
 *
 * This class simply makes AS requests detectable as the cron-like context they are,
 * aligning with Polylang's intended design.
 *
 * ## Timing Requirements
 *
 * This class MUST be initialized before Polylang's `plugins_loaded` priority 1 hook.
 * It runs at file inclusion time via bootstrap.php:
 *
 * ```
 * WordPress loads polylang-ai-automatic-translation.php
 *   ├── Line 34: require autoloader
 *   ├── Line 40: require bootstrap.php ← Context forcer runs HERE
 *   └── Lines 42-58: xwp_load_app() registered to plugins_loaded priority 0
 *
 * Then hooks fire:
 *   ├── plugins_loaded priority 0: Our DI container
 *   └── plugins_loaded priority 1: Polylang checks if(defined('PLL_ADMIN')) ← Already defined!
 * ```
 *
 * @since 4.4.0
 */
class Polylang_Context_Forcer {

	/**
	 * Action Scheduler hooks that require admin context.
	 *
	 * These are PLLAT's async processing hooks that copy/translate content
	 * and therefore need full Polylang admin capabilities.
	 *
	 * @var array<string>
	 */
	private const ADMIN_CONTEXT_HOOKS = array(
		'pllat_process_run',
	);

	/**
	 * Initialize the context forcer.
	 *
	 * Must be called BEFORE Polylang loads (before plugins_loaded priority 1).
	 * Safe to call multiple times - will only define PLL_ADMIN once.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( defined( 'PLL_ADMIN' ) ) {
			return;
		}

		if ( ! self::is_action_scheduler_request() ) {
			return;
		}

		if ( ! self::has_pending_pllat_actions() ) {
			return;
		}

		define( 'PLL_ADMIN', true );
	}

	/**
	 * Check if this is an Action Scheduler async request.
	 *
	 * Action Scheduler uses WP_Async_Request which sends requests to admin-ajax.php
	 * with action=as_async_request_queue_runner (prefix 'as' + '_' + 'async_request_queue_runner').
	 *
	 * @return bool
	 */
	private static function is_action_scheduler_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = $_REQUEST['action'] ?? '';
		return 'as_async_request_queue_runner' === $action;
	}

	/**
	 * Check if there are pending PLLAT actions in the Action Scheduler queue.
	 *
	 * Only force admin context when our plugin's actions are about to run.
	 * This prevents unnecessary context changes for other plugins' AS actions.
	 *
	 * @return bool
	 */
	private static function has_pending_pllat_actions(): bool {
		global $wpdb;

		// $wpdb may not be initialized yet at this early stage.
		if ( ! $wpdb instanceof \wpdb ) {
			// If we can't check, assume we need admin context for AS requests.
			// This is safe because we've already verified it's an AS request.
			return true;
		}

		$hooks = self::ADMIN_CONTEXT_HOOKS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE hook IN (" . implode( ',', array_fill( 0, count( $hooks ), '%s' ) ) . ")
				 AND status IN ('pending', 'running')
				 LIMIT 1",
				$hooks
			)
		);

		return (bool) $result;
	}
}
