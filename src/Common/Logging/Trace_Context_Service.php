<?php
/**
 * Trace_Context_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Logging
 */

declare(strict_types=1);

namespace PLLAT\Common\Logging;

\defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped trace context for logging correlation.
 *
 * One DI singleton per container = one per WP request / AS action.
 * Translation_Worker_Handler resets the context at each worker iteration
 * and clears it in a finally block to prevent leakage.
 */
class Trace_Context_Service {

	private ?string $trace_id = null;
	private ?int $run_id      = null;
	private ?int $job_id      = null;

	public function set_trace_id( ?string $trace_id ): void {
		$this->trace_id = $trace_id;
	}

	public function get_trace_id(): ?string {
		return $this->trace_id;
	}

	public function set_run_id( ?int $run_id ): void {
		$this->run_id = $run_id;
	}

	public function get_run_id(): ?int {
		return $this->run_id;
	}

	public function set_job_id( ?int $job_id ): void {
		$this->job_id = $job_id;
	}

	public function get_job_id(): ?int {
		return $this->job_id;
	}


	/**
	 * Overwrite all fields. Used at AS action entry to establish fresh context.
	 *
	 * @param string|null $trace_id Trace UUID.
	 * @param int|null    $run_id   Run ID.
	 * @param int|null    $job_id   Job ID.
	 */
	public function reset_and_set(
		?string $trace_id = null,
		?int $run_id = null,
		?int $job_id = null
	): void {
		$this->trace_id = $trace_id;
		$this->run_id   = $run_id;
		$this->job_id   = $job_id;
	}

	/**
	 * Reset all fields to null. Use in finally blocks.
	 */
	public function clear(): void {
		$this->trace_id = null;
		$this->run_id   = null;
		$this->job_id   = null;
	}

	/**
	 * Export non-null fields as array for injection into log records.
	 *
	 * @return array<string,string|int>
	 */
	public function to_array(): array {
		$out = array();
		if ( null !== $this->trace_id ) {
			$out['trace_id'] = $this->trace_id;
		}
		if ( null !== $this->run_id ) {
			$out['run_id'] = $this->run_id;
		}
		if ( null !== $this->job_id ) {
			$out['job_id'] = $this->job_id;
		}
		return $out;
	}
}
