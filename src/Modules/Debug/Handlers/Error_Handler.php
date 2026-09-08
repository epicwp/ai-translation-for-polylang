<?php
declare(strict_types=1);

namespace PLLAT\Debug\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use XWP\DI\Decorators\Handler;
use XWP\DI\Interfaces\On_Initialize;

/**
 * Error handler for capturing PHP errors related to the plugin.
 *
 * Intercepts WordPress error logging to capture plugin-related errors
 * and log them to the debug log when debug mode is enabled.
 */
#[Handler( tag: 'init', priority: 10 )]
class Error_Handler implements On_Initialize {
	/**
	 * Constructor.
	 *
	 * @param Debug_Logger_Service $logger The debug logger service.
	 */
	public function __construct(
		private Debug_Logger_Service $logger,
	) {
	}

	/**
	 * Initialize the error handler.
	 *
	 * @return void
	 */
	public function on_initialize(): void {
		// Only register error handler if debug mode is enabled.
		if ( ! $this->logger->is_debug_enabled() ) {
			return;
		}

		// Register shutdown function to catch fatal errors.
		\register_shutdown_function( array( $this, 'handle_shutdown' ) );

		// Register error handler for non-fatal errors.
		\set_error_handler( array( $this, 'handle_error' ), E_ALL );

		// Register exception handler for uncaught exceptions.
		\set_exception_handler( array( $this, 'handle_exception' ) );
	}

	/**
	 * Handle PHP errors.
	 *
	 * @param int    $errno   Error number.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line where error occurred.
	 * @return bool True if error was handled, false to continue normal error handling.
	 */
	public function handle_error( int $errno, string $errstr, string $errfile, int $errline ): bool {
		// Only log errors related to our plugin.
		if ( ! $this->is_plugin_file( $errfile ) ) {
			return false; // Let WordPress handle non-plugin errors.
		}

		// Only log if debug mode is enabled.
		if ( ! $this->logger->is_debug_enabled() ) {
			return false;
		}

		$error_type = $this->get_error_type( $errno );

		try {
			// Get logger via private method to avoid exposing it.
			$logger = $this->get_monolog_logger();

			$logger->error(
				\sprintf( '[%s] %s', $error_type, $errstr ),
				array(
					'error_type' => $error_type,
					'event_type' => 'php_error',
					'file'       => $errfile,
					'line'       => $errline,
				),
			);
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- logging must never break the request; PHP still reports the original error via the false return below.
		}

		// Return false to let WordPress continue its normal error handling.
		return false;
	}

	/**
	 * Handle uncaught exceptions.
	 *
	 * @param \Throwable $exception The uncaught exception.
	 * @return void
	 */
	public function handle_exception( \Throwable $exception ): void {
		// Only log exceptions from our plugin.
		if ( ! $this->is_plugin_file( $exception->getFile() ) ) {
			return;
		}

		// Only log if debug mode is enabled.
		if ( ! $this->logger->is_debug_enabled() ) {
			return;
		}

		try {
			$logger = $this->get_monolog_logger();

			$logger->error(
				\sprintf( 'Uncaught %s: %s', \get_class( $exception ), $exception->getMessage() ),
				array(
					'event_type' => 'uncaught_exception',
					'exception'  => \get_class( $exception ),
					'file'       => $exception->getFile(),
					'line'       => $exception->getLine(),
					'message'    => $exception->getMessage(),
					'trace'      => $exception->getTraceAsString(),
				),
			);
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- logging must never break the request; a failing debug logger has no safer sink to report to.
		}
	}

	/**
	 * Handle shutdown to catch fatal errors.
	 *
	 * @return void
	 */
	public function handle_shutdown(): void {
		$error = \error_get_last();

		// Check if it's a fatal error.
		if ( null === $error || ! \in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		// Only log errors from our plugin.
		if ( ! $this->is_plugin_file( $error['file'] ) ) {
			return;
		}

		// Only log if debug mode is enabled.
		if ( ! $this->logger->is_debug_enabled() ) {
			return;
		}

		$error_type = $this->get_error_type( $error['type'] );

		try {
			$logger = $this->get_monolog_logger();

			$logger->critical(
				\sprintf( '[%s] %s', $error_type, $error['message'] ),
				array(
					'error_type' => $error_type,
					'event_type' => 'fatal_error',
					'file'       => $error['file'],
					'line'       => $error['line'],
				),
			);
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- logging must never break shutdown; PHP has already reported the fatal error itself.
		}
	}

	/**
	 * Check if a file belongs to our plugin.
	 *
	 * @param string $file File path.
	 * @return bool True if file is part of the plugin.
	 */
	private function is_plugin_file( string $file ): bool {
		// Check if file path is inside our plugin directory.
		if ( \str_contains( $file, PLLAT_PLUGIN_DIR ) ) {
			return true;
		}

		// Check if file path contains PLLAT namespace.
		if ( \str_contains( $file, 'PLLAT' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get human-readable error type name.
	 *
	 * @param int $errno Error number.
	 * @return string Error type name.
	 */
	private function get_error_type( int $errno ): string {
		return match ( $errno ) {
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_STRICT            => 'E_STRICT',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
			default             => 'UNKNOWN',
		};
	}

	/**
	 * Get the Monolog logger instance via reflection.
	 *
	 * @return \PLLAT\Dependencies\Monolog\Logger The logger instance.
	 */
	private function get_monolog_logger(): \PLLAT\Dependencies\Monolog\Logger {
		// Access the private get_logger method via reflection.
		$reflection = new \ReflectionClass( $this->logger );
		$method     = $reflection->getMethod( 'get_logger' );
		$method->setAccessible( true );
		return $method->invoke( $this->logger );
	}
}
