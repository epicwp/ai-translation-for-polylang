<?php
declare(strict_types=1);

namespace PLLAT\CLI\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\CLI\Services\Block_Test_CLI_Service;
use XWP\DI\Decorators\CLI_Command;
use XWP\DI\Decorators\CLI_Handler;

/**
 * WP-CLI commands for running the Gutenberg block fixture test suite.
 *
 * Verifies that the block extractor/applier roundtrips correctly across
 * the fixture corpus under tests/fixtures/blocks/.
 */
#[CLI_Handler(
	namespace: 'pllat',
	description: 'Polylang AI Automatic Translation — block test commands',
	container: 'pllat',
)]
class Test_CLI_Handler {

	public function __construct(
		protected Block_Test_CLI_Service $block_test_cli_service,
	) {}

	/**
	 * Run all Gutenberg block fixture tests.
	 *
	 * USAGE: wp pllat test:blocks [--verbose]
	 */
	#[CLI_Command(
		command: 'test:blocks',
		summary: 'Run all Gutenberg block fixture tests',
		args: array(
			array(
				'name'        => 'verbose',
				'type'        => 'flag',
				'description' => 'Show detailed output',
				'optional'    => true,
			),
		),
	)]
	public function test_blocks( bool $verbose = false ): void {
		$this->block_test_cli_service->run_all_tests( $verbose );
	}

	/**
	 * Run a single Gutenberg block fixture test.
	 *
	 * USAGE: wp pllat test:block <fixture>
	 */
	#[CLI_Command(
		command: 'test:block',
		summary: 'Run a single Gutenberg block fixture test',
		args: array(
			array(
				'name'        => 'fixture',
				'type'        => 'positional',
				'description' => 'Fixture name or path',
				'optional'    => false,
			),
		),
	)]
	public function test_block( string $fixture ): void {
		$this->block_test_cli_service->run_single( $fixture );
	}

	/**
	 * List all available Gutenberg block fixtures.
	 *
	 * USAGE: wp pllat test:list
	 */
	#[CLI_Command( command: 'test:list', summary: 'List all available block fixtures' )]
	public function test_list(): void {
		$this->block_test_cli_service->list_fixtures();
	}
}
