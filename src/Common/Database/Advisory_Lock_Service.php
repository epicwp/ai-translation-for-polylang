<?php
/**
 * Advisory_Lock_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common\Database
 */

declare(strict_types=1);

namespace PLLAT\Common\Database;

\defined( 'ABSPATH' ) || exit;

/**
 * MySQL advisory lock wrapper.
 *
 * Used to serialize critical state transitions (run create vs cancel).
 * Locks are session-scoped and auto-released when the connection closes,
 * so forgotten releases don't persist.
 */
class Advisory_Lock_Service {

	/**
	 * Acquire a named lock, blocking up to $timeout seconds.
	 *
	 * @throws Run_State_Locked_Exception If the lock cannot be acquired.
	 */
	public function acquire( string $name, int $timeout = 5 ): void {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		if ( '1' !== (string) $result ) {
			throw new Run_State_Locked_Exception( \esc_html( $name ), (int) $timeout );
		}
	}

	/**
	 * Release a named lock. Idempotent: returns silently if the lock
	 * was not held by this session.
	 */
	public function release( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Run $callback while holding the lock. Releases even on exception.
	 *
	 * @template T
	 * @param callable():T $callback Work to run while holding the lock.
	 * @return T
	 */
	public function with_lock( string $name, int $timeout, callable $callback ): mixed {
		$this->acquire( $name, $timeout );
		try {
			return $callback();
		} finally {
			$this->release( $name );
		}
	}
}
