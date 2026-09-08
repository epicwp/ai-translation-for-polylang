<?php
/**
 * Provider_Health_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Repositories\Provider_Health_Repository;

/**
 * Circuit breaker around AI provider calls.
 *
 * Five consecutive failures within FAIL_WINDOW_SECS open the circuit
 * for OPEN_DURATION_SECS. Transitions fire pllat_provider_circuit_open
 * / pllat_provider_circuit_closed once per state change so listeners
 * (notifications, logging) don't see duplicates while the circuit
 * remains open.
 */
class Provider_Health_Service {

	public const FAIL_THRESHOLD     = 5;
	public const FAIL_WINDOW_SECS   = 60;
	public const OPEN_DURATION_SECS = 300;

	public function __construct( private Provider_Health_Repository $repository ) {}

	public function is_open( string $provider ): bool {
		return $this->repository->is_circuit_open( $provider );
	}

	/**
	 * Seconds until the breaker is scheduled to close. Returns 0 when the
	 * circuit is closed (or never opened) so callers can use the value
	 * directly as a reschedule delay.
	 */
	public function seconds_until_close( string $provider ): int {
		$row = $this->repository->get( $provider );
		if ( null === $row ) {
			return 0;
		}
		return \max( 0, $row['circuit_open_until'] - \time() );
	}

	public function record_success( string $provider ): void {
		$row = $this->repository->get( $provider );
		$this->repository->record_success( $provider );

		if ( null !== $row && $row['circuit_open_until'] > \time() ) {
			\do_action( 'pllat_provider_circuit_closed', array( 'provider' => $provider ) );
		}
	}

	public function record_failure( string $provider ): void {
		$already_open = $this->is_open( $provider );

		$this->repository->record_failure( $provider );

		if ( $already_open ) {
			return;
		}

		$row = $this->repository->get( $provider );
		if ( null === $row ) {
			return;
		}

		$within_window = ( \time() - $row['last_failure_at'] ) <= self::FAIL_WINDOW_SECS;
		if ( $row['consecutive_failures'] >= self::FAIL_THRESHOLD && $within_window ) {
			$until = \time() + self::OPEN_DURATION_SECS;
			$this->repository->open_circuit( $provider, $until );
			\do_action(
				'pllat_provider_circuit_open',
				array(
					'provider'   => $provider,
					'failures'   => $row['consecutive_failures'],
					'open_until' => $until,
				),
			);
		}
	}
}
