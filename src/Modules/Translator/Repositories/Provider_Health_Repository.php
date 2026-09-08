<?php
/**
 * Provider_Health_Repository class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Repositories;

\defined( 'ABSPATH' ) || exit;

/**
 * Atomic CRUD for the wp_pllat_provider_health table.
 *
 * Backs Provider_Health_Service's circuit breaker. ON DUPLICATE KEY UPDATE
 * with a SQL-side counter increment guarantees consistent state under
 * concurrent Action Scheduler workers.
 */
class Provider_Health_Repository {

	/**
	 * @return array{provider:string,consecutive_failures:int,last_failure_at:int,circuit_open_until:int,updated_at:int}|null
	 */
	public function get( string $provider ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pllat_provider_health WHERE provider = %s",
				$provider,
			),
			\ARRAY_A,
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'provider'             => (string) $row['provider'],
			'consecutive_failures' => (int) $row['consecutive_failures'],
			'last_failure_at'      => (int) $row['last_failure_at'],
			'circuit_open_until'   => (int) $row['circuit_open_until'],
			'updated_at'           => (int) $row['updated_at'],
		);
	}

	public function record_failure( string $provider ): void {
		global $wpdb;
		$now = \time();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}pllat_provider_health
					(provider, consecutive_failures, last_failure_at, updated_at)
				VALUES (%s, 1, %d, %d)
				ON DUPLICATE KEY UPDATE
					consecutive_failures = consecutive_failures + 1,
					last_failure_at = VALUES(last_failure_at),
					updated_at = VALUES(updated_at)",
				$provider,
				$now,
				$now,
			),
		);
	}

	public function record_success( string $provider ): void {
		global $wpdb;
		$now = \time();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}pllat_provider_health
					(provider, consecutive_failures, last_failure_at, circuit_open_until, updated_at)
				VALUES (%s, 0, 0, 0, %d)
				ON DUPLICATE KEY UPDATE
					consecutive_failures = 0,
					circuit_open_until = 0,
					updated_at = VALUES(updated_at)",
				$provider,
				$now,
			),
		);
	}

	public function open_circuit( string $provider, int $until ): void {
		global $wpdb;
		$now = \time();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}pllat_provider_health
					(provider, circuit_open_until, updated_at)
				VALUES (%s, %d, %d)
				ON DUPLICATE KEY UPDATE
					circuit_open_until = VALUES(circuit_open_until),
					updated_at = VALUES(updated_at)",
				$provider,
				$until,
				$now,
			),
		);
	}

	public function is_circuit_open( string $provider ): bool {
		$row = $this->get( $provider );
		if ( null === $row ) {
			return false;
		}
		return $row['circuit_open_until'] > \time();
	}
}
