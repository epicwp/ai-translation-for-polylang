<?php
declare(strict_types=1);

namespace PLLAT\Debug\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Logging\Event_Codes;
use PLLAT\Common\Logging\Sensitive_Data_Scrubber;
use PLLAT\Common\Logging\Trace_Context_Processor;
use PLLAT\Dependencies\Monolog\Formatter\JsonFormatter;
use PLLAT\Dependencies\Monolog\Handler\RotatingFileHandler;
use PLLAT\Dependencies\Monolog\Level;
use PLLAT\Dependencies\Monolog\Logger;
use PLLAT\Settings\Services\Settings_Service;

/**
 * Debug logger service using Monolog.
 *
 * Writes detailed debug information to a separate debug.log file.
 * Only active when debug mode is enabled in settings.
 */
class Debug_Logger_Service {
	/**
	 * Monolog logger instance.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Maximum number of log files to keep.
	 * Debug logs kept for 7 days (vs 30 for translation logs).
	 *
	 * @var int
	 */
	private int $max_files = 7;

	/**
	 * Settings service for checking debug mode.
	 *
	 * @var Settings_Service
	 */
	private Settings_Service $settings_service;

	/**
	 * Constructor.
	 *
	 * @param Settings_Service         $settings_service        Settings service instance.
	 * @param Trace_Context_Processor  $trace_context_processor Monolog processor that injects trace IDs.
	 * @param Sensitive_Data_Scrubber  $scrubber                Scrubber for API keys and oversized payloads.
	 */
	public function __construct(
		Settings_Service $settings_service,
		private Trace_Context_Processor $trace_context_processor,
		private Sensitive_Data_Scrubber $scrubber
	) {
		$this->settings_service = $settings_service;
		$upload_dir             = \wp_upload_dir();
		$this->log_dir          = $upload_dir['basedir'] . '/pllat-logs';

		// Ensure log directory exists.
		$this->ensure_log_directory();
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool
	 */
	public function is_debug_enabled(): bool {
		return $this->settings_service->is_debug_mode();
	}

	/**
	 * Code → (level, human-readable message) map for log_event.
	 *
	 * Codes not in this map default to ('debug', $code). Add an entry here
	 * to upgrade a code's level or give it a readable header in log files.
	 *
	 * @var array<string, array{level: string, message: string}>
	 */
	private const EVENT_CONFIG = array(
		Event_Codes::AI_REQUEST_SENT      => array( 'level' => 'debug', 'message' => 'AI API call initiated' ),
		Event_Codes::AI_RESPONSE_RECEIVED => array( 'level' => 'debug', 'message' => 'AI API call completed' ),
		Event_Codes::AI_PROMPT_SYSTEM     => array( 'level' => 'debug', 'message' => 'System prompt built' ),
		Event_Codes::AI_PROMPT_USER       => array( 'level' => 'debug', 'message' => 'User prompt built' ),
		Event_Codes::AI_MESSAGES_BUILT    => array( 'level' => 'debug', 'message' => 'Translation messages built' ),
		Event_Codes::AI_RESPONSE_INVALID  => array( 'level' => 'error', 'message' => 'AI returned invalid response structure' ),
		Event_Codes::AI_RESPONSE_EMPTY    => array( 'level' => 'error', 'message' => 'AI returned empty translation' ),
		Event_Codes::AI_RESPONSE_TOO_LONG => array( 'level' => 'error', 'message' => 'Translation exceeds maximum length' ),
		Event_Codes::BATCH_STARTED        => array( 'level' => 'debug', 'message' => 'Batch translation started' ),
	);

	/**
	 * Codes whose context carries AI request/response payloads that may
	 * contain API keys or oversized bodies and must be scrubbed.
	 *
	 * @var array<int, string>
	 */
	private const SCRUB_CODES = array(
		Event_Codes::AI_REQUEST_SENT,
		Event_Codes::AI_RESPONSE_RECEIVED,
	);

	/**
	 * Write a structured event record.
	 *
	 * Called by Debug_Logging_Handler via the pllat_log_event hook. Firers
	 * pass an Event_Codes constant and a flat context array; this method
	 * looks up the level + human message, runs the scrubber when needed,
	 * and writes through Monolog.
	 *
	 * @param string               $code    One of the Event_Codes constants.
	 * @param array<string, mixed> $context Structured context for the record.
	 */
	public function log_event( string $code, array $context = array() ): void {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		$config = self::EVENT_CONFIG[ $code ] ?? array( 'level' => 'debug', 'message' => $code );

		if ( \in_array( $code, self::SCRUB_CODES, true ) ) {
			$context = $this->scrubber->scrub( $context );
		}

		$this->get_logger()->log(
			$config['level'],
			$config['message'],
			array( 'event_type' => $code ) + $context,
		);
	}


	/**
	 * Get parsed debug logs for a specific date with pagination.
	 *
	 * @param string|null $date   Date in Y-m-d format, or null for today.
	 * @param int         $limit  Max entries to return (0 = all).
	 * @param int         $offset Entries to skip.
	 * @return array{entries: array, total: int} Array with entries and total count.
	 */
	public function get_logs( ?string $date = null, int $limit = 0, int $offset = 0 ): array {
		$log_file = $this->get_log_file_path( $date );

		if ( ! \file_exists( $log_file ) ) {
			return array( 'entries' => array(), 'total' => 0 );
		}

		$content = \file_get_contents( $log_file );

		if ( false === $content || '' === $content ) {
			return array( 'entries' => array(), 'total' => 0 );
		}

		$lines = \explode( "\n", \trim( $content ) );
		$lines = \array_filter( $lines, fn( $line ) => '' !== \trim( $line ) );
		$lines = \array_reverse( $lines ); // Newest first.
		$total = \count( $lines );

		// Apply pagination.
		if ( $limit > 0 ) {
			$lines = \array_slice( $lines, $offset, $limit );
		}

		$logs = array();
		foreach ( $lines as $line ) {
			$data = \json_decode( \trim( $line ), true );
			if ( null === $data ) {
				continue;
			}
			$logs[] = $this->transform_log_entry_for_display( $data );
		}

		return array( 'entries' => $logs, 'total' => $total );
	}

	/**
	 * Transform a debug log entry for frontend display (user-friendly).
	 *
	 * @param array $data Raw log data from JSON.
	 * @return array Transformed log entry with curated context.
	 */
	private function transform_log_entry_for_display( array $data ): array {
		$context = $data['context'] ?? array();

		// Build user-friendly context.
		$friendly_context = array();

		if ( isset( $context['event_type'] ) ) {
			$friendly_context['Event'] = $this->humanize_event_type( $context['event_type'] );
		}
		if ( isset( $context['lang_from'], $context['lang_to'] ) ) {
			$friendly_context['Translation'] = \strtoupper( $context['lang_from'] ) . ' → ' . \strtoupper( $context['lang_to'] );
		}
		if ( isset( $context['content_id'] ) ) {
			$friendly_context['Content ID'] = $context['content_id'];
		}
		if ( isset( $context['post_id'] ) ) {
			$friendly_context['Post ID'] = $context['post_id'];
		}
		if ( isset( $context['term_id'] ) ) {
			$friendly_context['Term ID'] = $context['term_id'];
		}
		if ( isset( $context['model'] ) ) {
			$friendly_context['AI Model'] = $context['model'];
		}
		if ( isset( $context['total_tokens'] ) ) {
			$friendly_context['Tokens Used'] = \number_format( $context['total_tokens'] );
		}
		if ( isset( $context['reference'] ) ) {
			$friendly_context['Field'] = $context['reference'];
		}
		if ( isset( $context['field_name'] ) ) {
			$friendly_context['Field'] = $context['field_name'];
		}
		if ( isset( $context['iterations'] ) ) {
			$friendly_context['Iterations'] = $context['iterations'];
		}

		return array(
			'timestamp' => $data['datetime'] ?? '',
			'level'     => \strtolower( $data['level_name'] ?? 'debug' ),
			'message'   => $data['message'] ?? '',
			'context'   => $friendly_context,
		);
	}

	/**
	 * Convert event_type to human-readable label.
	 *
	 * @param string $event_type The event type slug.
	 * @return string Human-readable label.
	 */
	private function humanize_event_type( string $event_type ): string {
		$map = array(
			'ai_api_call_before'        => 'AI API Call Started',
			'ai_api_call_after'         => 'AI API Call Completed',
			'system_prompt_built'       => 'System Prompt Built',
			'user_prompt_built'         => 'User Prompt Built',
			'translation_messages_built' => 'Translation Messages Built',
			'invalid_ai_response'           => 'Invalid AI Response',
			'empty_translation_received'    => 'Empty Translation',
			'translation_too_long'          => 'Translation Too Long',
			'placeholder_validation_failed' => 'Placeholder Validation Failed',
			'post_field_update_before'  => 'Post Update Started',
			'post_field_update_after'   => 'Post Update Completed',
			'term_field_update_before'  => 'Term Update Started',
			'term_field_update_after'   => 'Term Update Completed',
			'task_timing'               => 'Task Timing',
			'batch_translate_started'   => 'Batch Translation Started',
			'batch_translate_completed' => 'Batch Translation Completed',
			'batch_continuation'        => 'Batch Continuation',
		);

		return $map[ $event_type ] ?? \ucwords( \str_replace( '_', ' ', $event_type ) );
	}

	/**
	 * Get total size of debug log files in bytes.
	 *
	 * @return int Total size in bytes.
	 */
	public function get_debug_log_size(): int {
		if ( ! \is_dir( $this->log_dir ) ) {
			return 0;
		}

		$total_size = 0;
		$files      = \glob( $this->log_dir . '/debug*.log' );

		if ( false === $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( ! \is_file( $file ) ) {
				continue;
			}
			$total_size += \filesize( $file );
		}

		return $total_size;
	}

	/**
	 * Check if debug log size exceeds warning threshold.
	 *
	 * @param int $threshold_mb Threshold in megabytes (default: 50 MB).
	 * @return bool True if size exceeds threshold.
	 */
	public function is_debug_log_size_warning( int $threshold_mb = 50 ): bool {
		$size_bytes      = $this->get_debug_log_size();
		$threshold_bytes = $threshold_mb * 1024 * 1024;

		return $size_bytes > $threshold_bytes;
	}

	/**
	 * Get human-readable debug log size.
	 *
	 * @return string Formatted size (e.g., "15.3 MB").
	 */
	public function get_debug_log_size_formatted(): string {
		$bytes = $this->get_debug_log_size();
		return \size_format( $bytes, 2 );
	}

	/**
	 * Get log directory path.
	 *
	 * @return string The log directory path.
	 */
	public function get_log_directory(): string {
		return $this->log_dir;
	}

	/**
	 * Get all available debug log files with metadata.
	 *
	 * @return array Array of log files with date, path, and size.
	 */
	public function get_available_log_files(): array {
		$files = \glob( $this->log_dir . '/debug-*.log' );
		if ( false === $files || 0 === \count( $files ) ) {
			return array();
		}

		$log_files = array();
		foreach ( $files as $file ) {
			// Extract date from filename: debug-2025-11-24.log
			if ( \preg_match( '/debug-(\d{4}-\d{2}-\d{2})\.log$/', \basename( $file ), $matches ) ) {
				$date = $matches[1];
				$log_files[] = array(
					'date' => $date,
					'path' => $file,
					'size' => \file_exists( $file ) ? \filesize( $file ) : 0,
				);
			}
		}

		// Sort by date, newest first
		\usort( $log_files, fn( $a, $b ) => \strcmp( $b['date'], $a['date'] ) );

		return $log_files;
	}

	/**
	 * Get the path to the active debug log file.
	 *
	 * @param string|null $date Optional date in Y-m-d format.
	 * @return string The log file path.
	 */
	public function get_log_file_path( ?string $date = null ): string {
		if ( $date ) {
			// Validate date format
			if ( \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$file = $this->log_dir . '/debug-' . $date . '.log';
				if ( \file_exists( $file ) ) {
					return $file;
				}
			}
		}

		// Monolog RotatingFileHandler creates dated files, not debug.log
		// Try today's file first
		$today_file = $this->log_dir . '/debug-' . \gmdate( 'Y-m-d' ) . '.log';
		if ( \file_exists( $today_file ) ) {
			return $today_file;
		}

		// Fallback to most recent debug file
		$files = \glob( $this->log_dir . '/debug-*.log' );
		if ( false !== $files && 0 < \count( $files ) ) {
			// Sort by modification time, newest first
			\usort( $files, fn( $a, $b ) => \filemtime( $b ) <=> \filemtime( $a ) );
			return $files[0];
		}

		// Fallback to debug.log (shouldn't exist with RotatingFileHandler)
		return $this->log_dir . '/debug.log';
	}

	/**
	 * Read last N lines from the debug log.
	 *
	 * @param int         $lines Number of lines to read (default: 200).
	 * @param string|null $date  Optional date in Y-m-d format.
	 * @return string Log content, empty string if no log exists.
	 */
	public function read_last_lines( int $lines = 200, ?string $date = null ): string {
		$log_file = $this->get_log_file_path( $date );

		if ( ! \file_exists( $log_file ) ) {
			return '';
		}

		// Read the entire file
		$content = \file_get_contents( $log_file );

		if ( false === $content || '' === $content ) {
			return '';
		}

		// Split into lines and get the last N lines
		$all_lines = \explode( "\n", $content );
		$all_lines = \array_filter( $all_lines ); // Remove empty lines
		$last_lines = \array_slice( $all_lines, -$lines );

		// Format JSON logs for better readability
		$formatted_lines = \array_map( array( $this, 'format_log_line' ), $last_lines );

		return \implode( "\n\n", $formatted_lines );
	}

	/**
	 * Format a single log line for display.
	 *
	 * @param string $line Raw log line (JSON).
	 * @return string Formatted log line.
	 */
	private function format_log_line( string $line ): string {
		$data = \json_decode( $line, true );
		if ( ! $data ) {
			return $line; // Return as-is if not JSON
		}

		// Extract key information
		$timestamp = $data['datetime'] ?? '';
		$level = $data['level_name'] ?? '';
		$message = $data['message'] ?? '';
		$context = $data['context'] ?? array();

		// Format timestamp
		if ( $timestamp ) {
			$dt = new \DateTime( $timestamp );
			$timestamp = $dt->format( 'Y-m-d H:i:s' );
		}

		// Build formatted output
		$output = \sprintf( '[%s] %s: %s', $timestamp, $level, $message );

		// Add relevant context fields
		if ( isset( $context['event_type'] ) ) {
			$output .= \sprintf( ' [%s]', $context['event_type'] );
		}

		// Add important context details
		$details = array();
		if ( isset( $context['content_id'] ) ) {
			$details[] = 'ID:' . $context['content_id'];
		}
		if ( isset( $context['reference'] ) ) {
			$details[] = 'Ref:' . $context['reference'];
		}
		if ( isset( $context['lang_from'], $context['lang_to'] ) ) {
			$details[] = \sprintf( '%s→%s', $context['lang_from'], $context['lang_to'] );
		}
		if ( isset( $context['model'] ) ) {
			$details[] = 'Model:' . $context['model'];
		}
		if ( isset( $context['total_tokens'] ) ) {
			$details[] = 'Tokens:' . $context['total_tokens'];
		}

		if ( 0 < \count( $details ) ) {
			$output .= ' (' . \implode( ', ', $details ) . ')';
		}

		return $output;
	}

	/**
	 * Clear the debug log file.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_log(): bool {
		// Clear all dated debug files, not just the current one
		$files = \glob( $this->log_dir . '/debug-*.log' );

		if ( false === $files || 0 === \count( $files ) ) {
			return true; // No files to clear
		}

		$all_cleared = true;
		foreach ( $files as $file ) {
			// Delete the file instead of emptying it
			\wp_delete_file( $file );
			if ( \file_exists( $file ) ) {
				$all_cleared = false;
			}
		}

		return $all_cleared;
	}

	/**
	 * Get the Monolog logger instance.
	 *
	 * @return Logger The logger instance.
	 */
	private function get_logger(): Logger {
		if ( null === $this->logger ) {
			$this->logger = new Logger( 'pllat-debug' );

			// Daily rotating file handler.
			$handler = new RotatingFileHandler(
				$this->log_dir . '/debug.log',
				$this->max_files,
				Level::Debug,
			);

			// JSON formatter for structured logs.
			$formatter = new JsonFormatter();
			$handler->setFormatter( $formatter );

			$this->logger->pushHandler( $handler );
			$this->logger->pushProcessor( $this->trace_context_processor );
		}

		return $this->logger;
	}

	/**
	 * Ensure log directory exists and is protected.
	 *
	 * @return void
	 */
	private function ensure_log_directory(): void {
		if ( ! \file_exists( $this->log_dir ) ) {
			\wp_mkdir_p( $this->log_dir );
		}

		// Add .htaccess to prevent direct access.
		$htaccess = $this->log_dir . '/.htaccess';
		if ( ! \file_exists( $htaccess ) ) {
			\file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Add index.php to prevent directory listing.
		$index = $this->log_dir . '/index.php';
		if ( \file_exists( $index ) ) {
			return;
		}

		\file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}
}
