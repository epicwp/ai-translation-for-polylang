<?php
declare(strict_types=1);

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Checks minimum requirements before the plugin initializes.
 *
 * Polylang 3.7 introduced PLL()->model->term->insert() and update()
 * which PLLAT relies on for safe term creation/updates in all execution contexts.
 * Earlier versions lack these methods, causing fatal errors.
 *
 * @since 4.9.0
 */
class Requirements {

	/**
	 * Minimum required Polylang version.
	 */
	public const MIN_POLYLANG_VERSION = '3.7';

	/**
	 * Check if all requirements are met.
	 *
	 * @return bool
	 */
	public static function check(): bool {
		return \defined( 'POLYLANG_VERSION' )
			&& \version_compare( POLYLANG_VERSION, self::MIN_POLYLANG_VERSION, '>=' );
	}

	/**
	 * Display admin notice when requirements are not met.
	 *
	 * @return void
	 */
	public static function admin_notices(): void {
		$current = \defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : 'unknown';
		printf(
			'<div class="notice notice-error"><p><strong>Polylang Automatic Translation with AI</strong> requires Polylang %s or higher. You are running Polylang %s. Please update Polylang to continue using AI translations.</p></div>',
			\esc_html( self::MIN_POLYLANG_VERSION ),
			\esc_html( $current ),
		);
	}
}
