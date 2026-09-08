<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.8.0: drop the scoped support diagnostic role.
 *
 * The scoped role made support access read-only-by-design, blocking
 * actual cleanup work. The TTL + audit log provide the safety net, so
 * we revert to administrator and remove the custom role. Existing
 * support users keep their Application Password — only the role
 * assignment changes.
 */
final class Migrate_To_3_8_0 {
	public const VERSION = '3.8.0';

	public static function run(): void {
		$option = \get_option( 'pllat_support_access' );
		if ( \is_array( $option ) && isset( $option['user_id'] ) ) {
			$user = \get_user_by( 'id', $option['user_id'] );
			if ( $user instanceof \WP_User && \in_array( 'pllat_support_diagnostic', $user->roles, true ) ) {
				$user->set_role( 'administrator' );
			}
		}

		\remove_role( 'pllat_support_diagnostic' );
	}
}
