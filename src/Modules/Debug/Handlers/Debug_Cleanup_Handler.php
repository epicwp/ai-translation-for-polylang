<?php
declare(strict_types=1);

namespace PLLAT\Debug\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use PLLAT\Settings\Services\Settings_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handler for debug log cleanup and monitoring.
 *
 * Cleans up old debug logs and monitors file sizes to prevent disk space issues.
 */
#[Handler( tag: 'init', priority: 10 )]
class Debug_Cleanup_Handler {
	/**
	 * Action hook name for cleanup.
	 *
	 * @var string
	 */
	private const CLEANUP_HOOK = 'pllat_cleanup_debug_logs';

	/**
	 * Action hook name for size check.
	 *
	 * @var string
	 */
	private const SIZE_CHECK_HOOK = 'pllat_debug_log_size_check';

	/**
	 * Option key for size warning dismissal.
	 *
	 * @var string
	 */
	private const WARNING_DISMISSED_OPTION = 'pllat_debug_size_warning_dismissed';

	/**
	 * Debug logs retention in days.
	 *
	 * @var int
	 */
	private const DEBUG_RETENTION_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param Debug_Logger_Service $debug_logger     Debug logger service.
	 * @param Settings_Service     $settings_service Settings service.
	 */
	public function __construct(
		private Debug_Logger_Service $debug_logger,
		private Settings_Service $settings_service,
	) {
	}

	/**
	 * Initialize cleanup scheduling.
	 *
	 * Schedules daily cleanup and size checks.
	 *
	 * @return void
	 */
	#[Action( tag: 'init', priority: 20 )]
	public function schedule_cleanup(): void {
		// Schedule daily cleanup at 3 AM.
		if ( ! \as_next_scheduled_action( self::CLEANUP_HOOK ) ) {
			\as_schedule_recurring_action(
				\strtotime( 'tomorrow 03:00' ),
				DAY_IN_SECONDS,
				self::CLEANUP_HOOK,
				array(),
				'pllat',
			);
		}

		// Schedule hourly size check (only runs if debug mode enabled).
		if ( ! \as_next_scheduled_action( self::SIZE_CHECK_HOOK ) ) {
			\as_schedule_recurring_action(
				\time() + HOUR_IN_SECONDS,
				HOUR_IN_SECONDS,
				self::SIZE_CHECK_HOOK,
				array(),
				'pllat',
			);
		}
	}

	/**
	 * Execute debug log cleanup.
	 *
	 * Deletes debug log files older than 7 days.
	 *
	 * @return void
	 */
	#[Action( tag: 'pllat_cleanup_debug_logs', priority: 10 )]
	public function execute_cleanup(): void {
		try {
			$log_dir = $this->debug_logger->get_log_directory();
			if ( ! \is_dir( $log_dir ) ) {
				return;
			}

			$cutoff_time = \time() - ( self::DEBUG_RETENTION_DAYS * DAY_IN_SECONDS );

			// Find all debug-*.log files.
			$files = \glob( $log_dir . '/debug*.log' );
			if ( false === $files ) {
				return;
			}

			foreach ( $files as $file ) {
				if ( ! \is_file( $file ) ) {
					continue;
				}

				// Delete if older than retention period.
				if ( \filemtime( $file ) < $cutoff_time ) {
					\wp_delete_file( $file );
				}
			}
		} catch ( \Throwable $e ) {
			\do_action( 'pllat_log_error', 'Failed to clean up debug logs: ' . $e->getMessage() );
		}
	}

	/**
	 * Check debug log size and auto-disable if too large.
	 *
	 * @return void
	 */
	#[Action( tag: 'pllat_debug_log_size_check', priority: 10 )]
	public function check_debug_log_size(): void {
		try {
			// Only check if debug mode is enabled.
			if ( ! $this->settings_service->is_debug_mode() ) {
				return;
			}

			// Check if warning was dismissed.
			$dismissed = \get_option( self::WARNING_DISMISSED_OPTION, false );
			if ( $dismissed ) {
				return;
			}

			// Check if size exceeds threshold (50 MB default).
			$threshold_mb = \apply_filters( 'pllat_debug_log_size_threshold_mb', 50 );

			if ( $this->debug_logger->is_debug_log_size_warning( $threshold_mb ) ) {
				$size = $this->debug_logger->get_debug_log_size_formatted();

				// Auto-disable debug mode to prevent further growth.
				$this->settings_service->set_debug_mode( false );

				// Log warning.
				\do_action(
					'pllat_log_warning',
					\sprintf(
						'Debug logs reached %s (threshold: %d MB). Debug mode automatically disabled. ' .
						'Clear debug logs or increase threshold via pllat_debug_log_size_threshold_mb filter.',
						$size,
						$threshold_mb,
					),
				);

				// Add admin notice hook.
				\add_action( 'admin_notices', array( $this, 'show_size_warning_notice' ) );

				// Set transient to show notice for 7 days.
				\set_transient( 'pllat_debug_size_warning', $size, 7 * DAY_IN_SECONDS );
			}
		} catch ( \Throwable $e ) {
			\do_action( 'pllat_log_error', 'Failed to check debug log size: ' . $e->getMessage() );
		}
	}

	/**
	 * Show admin notice when debug logs are too large.
	 *
	 * @return void
	 */
	public function show_size_warning_notice(): void {
		$size = \get_transient( 'pllat_debug_size_warning' );
		if ( ! $size ) {
			return;
		}

		?>
		<div class="notice notice-warning is-dismissible" data-pllat-debug-warning>
			<p>
				<strong><?php echo \esc_html__( 'PLLAT Debug Logs Warning', 'ai-translation-for-polylang' ); ?></strong><br>
				<?php
				echo \esc_html(
					\sprintf(
						/* translators: %s: current debug log size */
						\__( 'Debug logs have reached %s. Debug mode has been automatically disabled to prevent disk space issues.', 'ai-translation-for-polylang' ),
						$size,
					)
				);
				?>
			</p>
			<p>
				<strong><?php echo \esc_html__( 'Recommended actions:', 'ai-translation-for-polylang' ); ?></strong>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php echo \esc_html__( 'Clear debug logs from the plugin settings page', 'ai-translation-for-polylang' ); ?></li>
				<li><?php echo \esc_html__( 'Only enable debug mode when actively troubleshooting', 'ai-translation-for-polylang' ); ?></li>
				<li><?php echo \esc_html__( 'Debug logs are automatically deleted after 7 days', 'ai-translation-for-polylang' ); ?></li>
			</ul>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('[data-pllat-debug-warning] .notice-dismiss').on('click', function() {
				$.post(ajaxurl, {
					action: 'pllat_dismiss_debug_warning',
					nonce: '<?php echo \esc_js( \wp_create_nonce( 'pllat_dismiss_debug_warning' ) ); ?>'
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle AJAX request to dismiss size warning.
	 *
	 * @return void
	 */
	#[Action( tag: 'wp_ajax_pllat_dismiss_debug_warning', priority: 10 )]
	public function handle_dismiss_warning(): void {
		\check_ajax_referer( 'pllat_dismiss_debug_warning', 'nonce' );

		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( 'Unauthorized', 403 );
		}

		// Set option to dismiss warning for 7 days.
		\update_option( self::WARNING_DISMISSED_OPTION, \time() + ( 7 * DAY_IN_SECONDS ) );

		// Delete transient.
		\delete_transient( 'pllat_debug_size_warning' );

		\wp_send_json_success();
	}
}
