<?php
declare(strict_types=1);

namespace PLLAT\Debug\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Exceptions\PLLAT_Exception;
use PLLAT\Common\Logging\Trace_Context_Processor;
use PLLAT\Dependencies\Monolog\Formatter\JsonFormatter;
use PLLAT\Dependencies\Monolog\Handler\RotatingFileHandler;
use PLLAT\Dependencies\Monolog\Level;
use PLLAT\Dependencies\Monolog\Logger;

/**
 * Error logger service using Monolog.
 *
 * ALWAYS active (regardless of debug_mode setting).
 * Logs errors and exceptions to error-{date}.log files.
 */
class Error_Logger_Service {
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
	 * Error logs kept for 30 days (like translation logs).
	 *
	 * @var int
	 */
	private int $max_files = 30;

	/**
	 * Constructor.
	 *
	 * @param Trace_Context_Processor $trace_context_processor Monolog processor that injects trace IDs.
	 */
	public function __construct(
		private Trace_Context_Processor $trace_context_processor
	) {
		$upload_dir    = \wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/pllat-logs';

		// Ensure log directory exists.
		$this->ensure_log_directory();
	}

	/**
	 * Log an error with context and optional exception.
	 *
	 * @param string          $message   The error message.
	 * @param array           $context   Additional context (task_id, job_id, etc.).
	 * @param \Throwable|null $exception Optional exception for stack trace.
	 * @return void
	 */
	public function log_error( string $message, array $context = [], ?\Throwable $exception = null ): void {
		$log_context = array(
			'context' => $context,
		);

		if ( null !== $exception ) {
			$log_context['exception'] = array(
				'class'   => \get_class( $exception ),
				'message' => $exception->getMessage(),
				'code'    => $exception->getCode(),
				'file'    => $exception->getFile(),
				'line'    => $exception->getLine(),
				'trace'   => $exception->getTraceAsString(),
			);

			// Include PLLAT_Exception context if available.
			if ( $exception instanceof PLLAT_Exception ) {
				$log_context['exception']['pllat_context'] = $exception->get_full_context();
			}

			// Include previous exception info if chained.
			$previous = $exception->getPrevious();
			if ( null !== $previous ) {
				$log_context['exception']['previous'] = array(
					'class'   => \get_class( $previous ),
					'message' => $previous->getMessage(),
				);
			}
		}

		$this->get_logger()->error( $message, $log_context );
	}

	/**
	 * Log a warning with context.
	 *
	 * @param string $message The warning message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log_warning( string $message, array $context = [] ): void {
		$this->get_logger()->warning( $message, array( 'context' => $context ) );
	}

	/**
	 * Get logs for a specific date with pagination.
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
			$logs[] = $this->transform_log_entry( $data );
		}

		return array( 'entries' => $logs, 'total' => $total );
	}

	/**
	 * Find all error log entries with a matching trace_id.
	 *
	 * Trace_Context_Processor injects trace_id into the Monolog record's `extra`
	 * array. Older entries may carry it under `context` or top-level.
	 *
	 * @param string   $trace_id Trace UUID to match.
	 * @param int|null $since    Unix ts lower bound (inclusive). Null = no lower bound.
	 * @param int|null $until    Unix ts upper bound (inclusive). Null = no upper bound.
	 * @return array<int, array<string, mixed>> Raw Monolog records.
	 */
	public function find_by_trace_id( string $trace_id, ?int $since = null, ?int $until = null ): array {
		$results = array();

		foreach ( $this->get_available_dates() as $date ) {
			$date_ts = \strtotime( $date );
			if ( false === $date_ts ) {
				continue;
			}
			if ( null !== $until && $date_ts > $until ) {
				continue;
			}
			if ( null !== $since && $date_ts + DAY_IN_SECONDS - 1 < $since ) {
				continue;
			}

			$file = $this->get_log_file_path( $date );
			if ( ! \file_exists( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- daily error logs can be large; streamed line by line instead of loaded into memory.
			$handle = \fopen( $file, 'r' );
			if ( false === $handle ) {
				continue;
			}

			while ( false !== ( $line = \fgets( $handle ) ) ) {
				$decoded = \json_decode( \trim( $line ), true );
				if ( ! \is_array( $decoded ) ) {
					continue;
				}

				$entry_trace = $decoded['extra']['trace_id']
					?? $decoded['context']['trace_id']
					?? $decoded['trace_id']
					?? null;
				if ( $entry_trace !== $trace_id ) {
					continue;
				}

				$entry_ts = isset( $decoded['datetime'] ) ? \strtotime( (string) $decoded['datetime'] ) : null;
				if ( false === $entry_ts ) {
					$entry_ts = null;
				}
				if ( null !== $since && null !== $entry_ts && $entry_ts < $since ) {
					continue;
				}
				if ( null !== $until && null !== $entry_ts && $entry_ts > $until ) {
					continue;
				}

				$results[] = $this->transform_log_entry( $decoded );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the streamed handle opened above.
			\fclose( $handle );
		}

		return $results;
	}

	/**
	 * Transform a log entry for frontend display.
	 *
	 * @param array $data Raw log data from JSON.
	 * @return array Transformed log entry.
	 */
	private function transform_log_entry( array $data ): array {
		$timestamp = $data['datetime'] ?? '';
		$context   = $data['context']['context'] ?? array();
		$exception = $data['context']['exception'] ?? null;

		$entry = array(
			'timestamp'    => $timestamp,
			'level'        => \strtolower( $data['level_name'] ?? 'error' ),
			'message'      => $data['message'] ?? '',
			'context'      => $context,
			'has_exception' => null !== $exception,
		);

		if ( null !== $exception ) {
			$entry['exception'] = array(
				'class'   => $exception['class'] ?? '',
				'message' => $exception['message'] ?? '',
				'file'    => $exception['file'] ?? '',
				'line'    => $exception['line'] ?? 0,
			);
			// Include truncated stack trace for UI.
			if ( isset( $exception['trace'] ) ) {
				$trace_lines       = \explode( "\n", $exception['trace'] );
				$entry['exception']['trace_preview'] = \array_slice( $trace_lines, 0, 10 );
			}
		}

		return $entry;
	}

	/**
	 * Get available log dates.
	 *
	 * @return array Array of available dates (Y-m-d format).
	 */
	public function get_available_dates(): array {
		$files = \glob( $this->log_dir . '/error-*.log' );

		if ( false === $files || 0 === \count( $files ) ) {
			return array();
		}

		$dates = array();
		foreach ( $files as $file ) {
			if ( \preg_match( '/error-(\d{4}-\d{2}-\d{2})\.log$/', \basename( $file ), $matches ) ) {
				$dates[] = $matches[1];
			}
		}

		// Sort newest first.
		\rsort( $dates );

		return $dates;
	}

	/**
	 * Get the path to the error log file for a specific date.
	 *
	 * @param string|null $date Optional date in Y-m-d format.
	 * @return string The log file path.
	 */
	public function get_log_file_path( ?string $date = null ): string {
		if ( null !== $date && \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $this->log_dir . '/error-' . $date . '.log';
		}

		return $this->log_dir . '/error-' . \gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Get raw log file content for download.
	 *
	 * @param string|null $date Date in Y-m-d format.
	 * @return string|null File content or null if not found.
	 */
	public function get_log_content( ?string $date = null ): ?string {
		$log_file = $this->get_log_file_path( $date );

		if ( ! \file_exists( $log_file ) ) {
			return null;
		}

		$content = \file_get_contents( $log_file );
		return false === $content ? null : $content;
	}

	/**
	 * Get total size of error log files in bytes.
	 *
	 * @return int Total size in bytes.
	 */
	public function get_log_size(): int {
		if ( ! \is_dir( $this->log_dir ) ) {
			return 0;
		}

		$total_size = 0;
		$files      = \glob( $this->log_dir . '/error-*.log' );

		if ( false === $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( \is_file( $file ) ) {
				$total_size += \filesize( $file );
			}
		}

		return $total_size;
	}

	/**
	 * Clear old error log files.
	 *
	 * @param int $days Number of days to keep (default: 30).
	 * @return int Number of files deleted.
	 */
	public function clear_old_logs( int $days = 30 ): int {
		$files = \glob( $this->log_dir . '/error-*.log' );

		if ( false === $files || 0 === \count( $files ) ) {
			return 0;
		}

		$cutoff  = \strtotime( "-{$days} days" );
		$deleted = 0;

		foreach ( $files as $file ) {
			if ( \preg_match( '/error-(\d{4}-\d{2}-\d{2})\.log$/', \basename( $file ), $matches ) ) {
				$file_date = \strtotime( $matches[1] );
				if ( false !== $file_date && $file_date < $cutoff ) {
					\wp_delete_file( $file );
					if ( ! \file_exists( $file ) ) {
						++$deleted;
					}
				}
			}
		}

		return $deleted;
	}

	/**
	 * Get the Monolog logger instance.
	 *
	 * @return Logger The logger instance.
	 */
	private function get_logger(): Logger {
		if ( null === $this->logger ) {
			$this->logger = new Logger( 'pllat-error' );

			// Daily rotating file handler.
			$handler = new RotatingFileHandler(
				$this->log_dir . '/error.log',
				$this->max_files,
				Level::Warning,
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
