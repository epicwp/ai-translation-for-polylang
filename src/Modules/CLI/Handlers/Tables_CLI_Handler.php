<?php
declare(strict_types=1);

namespace PLLAT\CLI\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\CLI\Services\Tables_CLI_Service;
use XWP\DI\Decorators\CLI_Command;
use XWP\DI\Decorators\CLI_Handler;

/**
 * WP-CLI commands for inspecting the plugin's DB tables.
 */
#[CLI_Handler(
	namespace: 'pllat',
	description: 'Polylang AI Automatic Translation — table inspection commands',
	container: 'pllat',
)]
class Tables_CLI_Handler {

	public function __construct(
		protected Tables_CLI_Service $tables_cli_service,
	) {}

	/**
	 * Count rows in all translation tables.
	 *
	 * USAGE: wp pllat tables:count
	 */
	#[CLI_Command( command: 'tables:count', summary: 'Count rows in all tables' )]
	public function tables_count_all(): void {
		$this->tables_cli_service->tables_count_all();
	}
}
