<?php
declare(strict_types=1);

namespace PLLAT\Common\Installer\Migrations;

\defined( 'ABSPATH' ) || exit;

/**
 * Migration to 3.3.0: clean up obsolete external processor IP cache transient.
 *
 * External_Processor_Service, External_Processor_Controller, and
 * IP_Verification_Service were removed in 3.3.0. The transient populated
 * by IP_Verification_Service has no consumers anymore.
 */
final class Migrate_To_3_3_0 {
	public const VERSION = '3.3.0';

	public static function run(): void {
		\delete_transient( 'pllat_external_processor_ips' );
	}
}
