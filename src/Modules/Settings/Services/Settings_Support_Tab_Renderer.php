<?php
// phpcs:disable
declare(strict_types=1);

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use PLLAT\Debug\Services\Error_Logger_Service;

/**
 * Renders the Support tab on the settings page.
 *
 * Owns the log viewer (error + debug), support links and the debug-mode
 * toggle; modules add their own cards on `pllat_support_tab_cards`. Pulled
 * out of Settings_Form so the rendering surface for one feature lives in
 * one file.
 */
class Settings_Support_Tab_Renderer {

	public function __construct(
		private Settings_Service $settings_service,
		private Debug_Logger_Service $debug_logger_service,
		private Error_Logger_Service $error_logger_service,
	) {}

	/**
	 * Render support tab content.
	 *
	 * @return void
	 */
	public function render(): void {
		// Determine which log type tab is active (error or debug).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$log_type      = isset( $_GET['log_type'] ) ? \sanitize_key( $_GET['log_type'] ) : 'error';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_date = isset( $_GET['log_date'] ) ? \sanitize_text_field( $_GET['log_date'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page  = isset( $_GET['log_page'] ) ? \max( 1, \intval( $_GET['log_page'] ) ) : 1;
		$per_page      = 100;
		$offset        = ( $current_page - 1 ) * $per_page;
		$debug_mode    = $this->settings_service->is_debug_mode();
		$nonce         = \wp_create_nonce( 'pllat_support_actions' );

		// Get log data based on active tab.
		if ( 'debug' === $log_type ) {
			$available_logs = $this->debug_logger_service->get_available_log_files();
			$log_label      = \__( 'Debug Logs', 'ai-translation-for-polylang' );
			$empty_message  = $debug_mode
				? \__( 'No debug logs yet. They will appear here once a translation runs.', 'ai-translation-for-polylang' )
				: \__( 'Debug logging is off. Switch on "Enable debug logging" above and save to start capturing detailed AI request/response traces here.', 'ai-translation-for-polylang' );
		} else {
			$available_logs = $this->get_error_log_files();
			$log_label      = \__( 'Error Logs', 'ai-translation-for-polylang' );
			$empty_message  = \__( 'No error logs found. This is good - no errors have been recorded.', 'ai-translation-for-polylang' );
		}

		// Initialize pagination vars.
		$entries      = array();
		$total        = 0;
		$total_pages  = 0;
		$log_size     = '0 B';

		if ( ! empty( $available_logs ) ) {
			// If no date selected, use the most recent.
			if ( empty( $selected_date ) && isset( $available_logs[0] ) ) {
				$selected_date = $available_logs[0]['date'];
			}

			// Get paginated log entries.
			if ( 'debug' === $log_type ) {
				$log_data = $this->debug_logger_service->get_logs( $selected_date, $per_page, $offset );
				$log_file = $this->debug_logger_service->get_log_file_path( $selected_date );
			} else {
				$log_data = $this->error_logger_service->get_logs( $selected_date, $per_page, $offset );
				$log_file = $this->error_logger_service->get_log_file_path( $selected_date );
			}

			$entries     = $log_data['entries'];
			$total       = $log_data['total'];
			$total_pages = (int) \ceil( $total / $per_page );

			if ( \file_exists( $log_file ) ) {
				$size_bytes = \filesize( $log_file );
				$log_size   = \size_format( $size_bytes, 2 );
			}
		}

		/**
		 * Filters the support card: where to ask for help. The free edition
		 * points to the wordpress.org forum, the pro Support_Access module to
		 * the support desk.
		 *
		 * @param array{url: string, title: string, description: string} $support_link
		 */
		$support_link = \apply_filters(
			'pllat_support_tab_support_link',
			array(
				'url'         => 'https://wordpress.org/support/plugin/ai-translation-for-polylang/',
				'title'       => \__( 'Support Forum', 'ai-translation-for-polylang' ),
				'description' => \__( 'Ask a question on WordPress.org', 'ai-translation-for-polylang' ),
			),
		);

		// Build tab URLs.
		$base_url      = \admin_url( 'admin.php?page=pllat-settings&tab=support' );
		$error_tab_url = \add_query_arg( 'log_type', 'error', $base_url );
		$debug_tab_url = \add_query_arg( 'log_type', 'debug', $base_url );
		?>
		<div class="pllat-support-tab-wrapper">
		<div class="pllat-support-container">
			<!-- Support Links Section -->
			<div class="pllat-support-card">
				<h2><?php \esc_html_e( 'Support Resources', 'ai-translation-for-polylang' ); ?></h2>
				<div class="pllat-support-grid">
					<a href="https://www.epicwpsolutions.com/category/knowlegde-base/" target="_blank" class="pllat-support-link">
						<span class="dashicons dashicons-book-alt"></span>
						<div class="pllat-support-link-content">
							<span class="pllat-support-link-title"><?php \esc_html_e( 'Documentation', 'ai-translation-for-polylang' ); ?></span>
							<span class="pllat-support-link-desc"><?php \esc_html_e( 'View user guides', 'ai-translation-for-polylang' ); ?></span>
						</div>
					</a>

					<a href="<?php echo \esc_url( $support_link['url'] ); ?>" target="_blank" class="pllat-support-link">
						<span class="dashicons dashicons-sos"></span>
						<div class="pllat-support-link-content">
							<span class="pllat-support-link-title"><?php echo \esc_html( $support_link['title'] ); ?></span>
							<span class="pllat-support-link-desc"><?php echo \esc_html( $support_link['description'] ); ?></span>
						</div>
					</a>

					<a href="<?php echo \esc_url( \admin_url( 'site-health.php' ) ); ?>" class="pllat-support-link">
						<span class="dashicons dashicons-admin-tools"></span>
						<div class="pllat-support-link-content">
							<span class="pllat-support-link-title"><?php \esc_html_e( 'Health Check', 'ai-translation-for-polylang' ); ?></span>
							<span class="pllat-support-link-desc"><?php \esc_html_e( 'Check system status', 'ai-translation-for-polylang' ); ?></span>
						</div>
					</a>
				</div>
			</div>

			<?php
			/**
			 * Fires between the support links and the debug mode card so modules
			 * can add their own Support tab cards.
			 */
			\do_action( 'pllat_support_tab_cards' );
			?>

			<!-- Debug Mode Section -->
			<div class="pllat-support-card">
				<h2><?php \esc_html_e( 'Debug Mode', 'ai-translation-for-polylang' ); ?></h2>
				<form method="post" action="options.php" class="pllat-debug-form">
					<?php \settings_fields( 'pllat_debug_settings_group' ); ?>
					<label for="pllat_debug_mode" class="pllat-debug-label">
						<input type="checkbox"
							   name="pllat_debug_mode"
							   id="pllat_debug_mode"
							   value="1"
							   <?php \checked( $debug_mode ); ?>>
						<span><?php \esc_html_e( 'Enable debug logging', 'ai-translation-for-polylang' ); ?></span>
					</label>
					<?php \submit_button( \__( 'Save', 'ai-translation-for-polylang' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="pllat-debug-description">
					<?php \esc_html_e( 'When enabled, detailed logs will be written to help troubleshoot issues.', 'ai-translation-for-polylang' ); ?>
				</p>
			</div>

			<!-- Log Viewer Section with Tabs -->
			<div class="pllat-support-card">
				<!-- Log Type Tabs -->
				<div class="pllat-log-tabs" style="display: flex; gap: 0; margin-bottom: 1rem; border-bottom: 1px solid #c3c4c7;">
					<a href="<?php echo \esc_url( $error_tab_url ); ?>"
					   class="pllat-log-tab <?php echo 'error' === $log_type ? 'active' : ''; ?>"
					   style="padding: 10px 20px; text-decoration: none; border-bottom: 2px solid <?php echo 'error' === $log_type ? '#2271b1' : 'transparent'; ?>; color: <?php echo 'error' === $log_type ? '#2271b1' : '#50575e'; ?>; font-weight: <?php echo 'error' === $log_type ? '600' : '400'; ?>;">
						<span class="dashicons dashicons-warning" style="vertical-align: middle; margin-right: 4px;"></span>
						<?php \esc_html_e( 'Error Logs', 'ai-translation-for-polylang' ); ?>
					</a>
					<a href="<?php echo \esc_url( $debug_tab_url ); ?>"
					   class="pllat-log-tab <?php echo 'debug' === $log_type ? 'active' : ''; ?>"
					   style="padding: 10px 20px; text-decoration: none; border-bottom: 2px solid <?php echo 'debug' === $log_type ? '#2271b1' : 'transparent'; ?>; color: <?php echo 'debug' === $log_type ? '#2271b1' : '#50575e'; ?>; font-weight: <?php echo 'debug' === $log_type ? '600' : '400'; ?>;">
						<span class="dashicons dashicons-admin-tools" style="vertical-align: middle; margin-right: 4px;"></span>
						<?php \esc_html_e( 'Debug Logs', 'ai-translation-for-polylang' ); ?>
					</a>
				</div>

				<div class="pllat-log-header">
					<h2><?php echo \esc_html( $log_label ); ?></h2>
					<div class="pllat-log-controls">
						<?php if ( ! empty( $available_logs ) ) : ?>
							<select id="pllat-log-date-select" class="pllat-log-date-select" data-log-type="<?php echo \esc_attr( $log_type ); ?>">
								<?php foreach ( $available_logs as $log_file_info ) : ?>
									<option value="<?php echo \esc_attr( $log_file_info['date'] ); ?>"
											<?php \selected( $selected_date, $log_file_info['date'] ); ?>>
										<?php
										$date_obj = \DateTime::createFromFormat( 'Y-m-d', $log_file_info['date'] );
										if ( $date_obj ) {
											echo \esc_html( $date_obj->format( 'F j, Y' ) );
										} else {
											echo \esc_html( $log_file_info['date'] );
										}
										?>
										(<?php echo \esc_html( \size_format( $log_file_info['size'], 2 ) ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<span class="pllat-log-size">
							<?php
							/* translators: %s: formatted file size */
							echo \esc_html( \sprintf( \__( 'File size: %s', 'ai-translation-for-polylang' ), $log_size ) );
							?>
						</span>
					</div>
				</div>

				<?php if ( empty( $entries ) ) : ?>
					<div class="pllat-log-empty">
						<span class="dashicons dashicons-yes-alt"></span>
						<p><?php echo \esc_html( $empty_message ); ?></p>
					</div>
				<?php else : ?>
					<?php $this->render_log_pagination( $current_page, $total_pages, $total, $log_type, $selected_date ); ?>
					<?php $this->render_log_entries( $entries, $log_type ); ?>
					<?php if ( $total_pages > 1 ) : ?>
						<?php $this->render_log_pagination( $current_page, $total_pages, $total, $log_type, $selected_date ); ?>
					<?php endif; ?>
				<?php endif; ?>

				<div class="pllat-button-group">
					<a href="<?php echo \esc_url( \admin_url( 'admin.php?action=pllat_download_logs&log_type=' . \urlencode( $log_type ) . '&log_date=' . \urlencode( $selected_date ) . '&_wpnonce=' . $nonce ) ); ?>"
					   id="pllat-download-logs-link"
					   class="pllat-btn pllat-btn-primary">
						<span class="dashicons dashicons-download"></span>
						<?php \esc_html_e( 'Download Logs', 'ai-translation-for-polylang' ); ?>
					</a>

					<button type="button"
							id="pllat-clear-logs-btn"
							data-nonce="<?php echo \esc_attr( $nonce ); ?>"
							data-date="<?php echo \esc_attr( $selected_date ); ?>"
							data-log-type="<?php echo \esc_attr( $log_type ); ?>"
							class="pllat-btn pllat-btn-danger">
						<span class="dashicons dashicons-trash"></span>
						<?php
						/* translators: %s: formatted file size */
						echo \esc_html( \sprintf( \__( 'Clear Logs (%s)', 'ai-translation-for-polylang' ), $log_size ) );
						?>
					</button>

					<span id="pllat-clear-logs-spinner" class="spinner" style="float: none; display: none; margin: 4px 0 0 8px;"></span>
				</div>

				<div id="pllat-clear-logs-message" class="pllat-message" style="display: none;"></div>
			</div>
		</div>
		</div>
		<?php
	}

	/**
	 * @return array<int, array{date: string, size: int}>
	 */
	private function get_error_log_files(): array {
		$dates = $this->error_logger_service->get_available_dates();
		$files = array();

		foreach ( $dates as $date ) {
			$file_path = $this->error_logger_service->get_log_file_path( $date );
			$size      = \file_exists( $file_path ) ? \filesize( $file_path ) : 0;

			$files[] = array(
				'date' => $date,
				'size' => $size,
			);
		}

		return $files;
	}

	private function render_log_entries( array $entries, string $log_type ): void {
		if ( empty( $entries ) ) {
			return;
		}
		?>
		<div class="pllat-log-entries" style="max-height: 500px; overflow-y: auto; margin-bottom: 1rem; border: 1px solid #c3c4c7; border-radius: 4px;">
			<?php foreach ( $entries as $log ) : ?>
				<?php
				$timestamp   = isset( $log['timestamp'] ) ? \wp_date( 'M j, Y g:i:s a', \strtotime( $log['timestamp'] ) ) : '';
				$level       = $log['level'] ?? ( 'error' === $log_type ? 'error' : 'debug' );
				$level_color = $this->get_log_level_color( $level );
				?>
				<div class="pllat-log-entry" style="border-bottom: 1px solid #e2e4e7; padding: 12px 16px;">
					<div class="pllat-log-header" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;" onclick="this.parentElement.classList.toggle('expanded')">
						<span class="dashicons dashicons-arrow-right-alt2 pllat-expand-icon" style="color: #50575e; transition: transform 0.2s; margin-top: 2px;"></span>
						<span style="background: <?php echo \esc_attr( $level_color ); ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: 500;">
							<?php echo \esc_html( $level ); ?>
						</span>
						<span style="color: #787c82; font-size: 12px; min-width: 150px;">
							<?php echo \esc_html( $timestamp ); ?>
						</span>
						<span style="flex: 1; font-weight: 500; color: #1d2327;">
							<?php echo \esc_html( $log['message'] ?? '' ); ?>
						</span>
					</div>
					<div class="pllat-log-details" style="display: none; margin-top: 12px; margin-left: 32px; background: #f6f7f7; padding: 12px; border-radius: 4px; font-size: 13px;">
						<?php $this->render_log_details( $log, $log_type ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<style>
			.pllat-log-entry.expanded .pllat-expand-icon { transform: rotate(90deg); }
			.pllat-log-entry.expanded .pllat-log-details { display: block !important; }
			.pllat-log-entry:hover { background: #f9f9f9; }
		</style>
		<?php
	}

	private function render_log_details( array $log, string $log_type ): void {
		if ( ! empty( $log['context'] ) ) : ?>
			<div style="margin-bottom: 12px;">
				<strong style="display: block; margin-bottom: 6px; color: #50575e;"><?php \esc_html_e( 'Details:', 'ai-translation-for-polylang' ); ?></strong>
				<?php if ( 'debug' === $log_type ) : ?>
					<div style="background: #fff; padding: 8px; border-radius: 3px; border: 1px solid #e2e4e7;">
						<?php foreach ( $log['context'] as $key => $value ) : ?>
							<div style="margin-bottom: 4px;"><strong><?php echo \esc_html( $key ); ?>:</strong> <?php echo \esc_html( (string) $value ); ?></div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<code style="display: block; background: #fff; padding: 8px; border-radius: 3px; white-space: pre-wrap; font-size: 12px; border: 1px solid #e2e4e7;"><?php echo \esc_html( \wp_json_encode( $log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></code>
				<?php endif; ?>
			</div>
		<?php endif;

		if ( 'error' === $log_type && ! empty( $log['exception'] ) ) :
			$exc = $log['exception'];
			?>
			<div>
				<strong style="display: block; margin-bottom: 6px; color: #50575e;"><?php \esc_html_e( 'Exception:', 'ai-translation-for-polylang' ); ?></strong>
				<div style="background: #fff; padding: 8px; border-radius: 3px; border: 1px solid #e2e4e7;">
					<div style="margin-bottom: 4px;"><strong>Class:</strong> <?php echo \esc_html( $exc['class'] ?? '' ); ?></div>
					<div style="margin-bottom: 4px;"><strong>Message:</strong> <?php echo \esc_html( $exc['message'] ?? '' ); ?></div>
					<div style="margin-bottom: 4px;"><strong>File:</strong> <?php echo \esc_html( $exc['file'] ?? '' ); ?>:<?php echo \esc_html( $exc['line'] ?? '' ); ?></div>
					<?php if ( ! empty( $exc['trace_preview'] ) ) : ?>
						<div style="margin-top: 8px;">
							<strong><?php \esc_html_e( 'Stack Trace (first 10 lines):', 'ai-translation-for-polylang' ); ?></strong>
							<pre style="margin: 4px 0 0; padding: 8px; background: #f0f0f1; border-radius: 3px; font-size: 11px; overflow-x: auto; white-space: pre;"><?php echo \esc_html( \implode( "\n", $exc['trace_preview'] ) ); ?></pre>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif;
	}

	private function render_log_pagination( int $current_page, int $total_pages, int $total, string $log_type, string $selected_date ): void {
		$base_url = \admin_url( 'admin.php?page=pllat-settings&tab=support&log_type=' . $log_type . '&log_date=' . $selected_date );

		if ( $total_pages <= 1 ) {
			?>
			<div class="pllat-log-pagination" style="margin-bottom: 1rem; color: #50575e; font-size: 13px;">
				<?php
				/* translators: %d: number of log entries */
				echo \esc_html( \sprintf( \__( 'Showing %d entries', 'ai-translation-for-polylang' ), $total ) );
				?>
			</div>
			<?php
			return;
		}

		$start = ( $current_page - 1 ) * 100 + 1;
		$end   = \min( $current_page * 100, $total );
		?>
		<div class="pllat-log-pagination" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; padding: 8px 12px; background: #f6f7f7; border-radius: 4px;">
			<span style="color: #50575e; font-size: 13px;">
				<?php
				/* translators: %1$d: start entry, %2$d: end entry, %3$d: total entries */
				echo \esc_html( \sprintf( \__( 'Showing %1$d-%2$d of %3$d entries', 'ai-translation-for-polylang' ), $start, $end, $total ) );
				?>
			</span>
			<div class="pllat-pagination-links" style="display: flex; gap: 4px;">
				<?php if ( $current_page > 1 ) : ?>
					<a href="<?php echo \esc_url( \add_query_arg( 'log_page', $current_page - 1, $base_url ) ); ?>" class="button button-secondary">&laquo; <?php \esc_html_e( 'Prev', 'ai-translation-for-polylang' ); ?></a>
				<?php endif; ?>

				<span style="padding: 4px 12px; background: #fff; border: 1px solid #c3c4c7; border-radius: 3px;">
					<?php
					/* translators: %1$d: current page, %2$d: total pages */
					echo \esc_html( \sprintf( \__( 'Page %1$d of %2$d', 'ai-translation-for-polylang' ), $current_page, $total_pages ) );
					?>
				</span>

				<?php if ( $current_page < $total_pages ) : ?>
					<a href="<?php echo \esc_url( \add_query_arg( 'log_page', $current_page + 1, $base_url ) ); ?>" class="button button-secondary"><?php \esc_html_e( 'Next', 'ai-translation-for-polylang' ); ?> &raquo;</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function get_log_level_color( string $level ): string {
		return match ( $level ) {
			'error'   => '#d63638',
			'warning' => '#dba617',
			'info'    => '#2271b1',
			default   => '#50575e',
		};
	}
}
