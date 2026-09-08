<?php
/**
 * Block_Test_CLI_Service class file.
 *
 * Tests Gutenberg block extraction and application with fixtures.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage CLI
 */

declare(strict_types=1);

namespace PLLAT\CLI\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\Gutenberg_Patch_Applier;
use PLLAT\Translator\Services\Gutenberg_Patch_Extractor;

/**
 * CLI service for testing Gutenberg block translation.
 */
class Block_Test_CLI_Service {
	/**
	 * Translation prefix used for fake translations.
	 */
	private const TRANSLATION_PREFIX = 'TRANSLATED: ';

	/**
	 * Constructor.
	 *
	 * @param Gutenberg_Patch_Extractor $extractor Patch extractor.
	 * @param Gutenberg_Patch_Applier   $applier   Patch applier.
	 */
	public function __construct(
		private Gutenberg_Patch_Extractor $extractor,
		private Gutenberg_Patch_Applier $applier,
	) {
	}

	/**
	 * Run all fixture tests.
	 *
	 * @param bool $verbose Show detailed output.
	 * @return void
	 */
	public function run_all_tests( bool $verbose = false ): void {
		$fixtures_dir = \dirname( __DIR__, 4 ) . '/tests/fixtures/blocks';

		if ( ! \is_dir( $fixtures_dir ) ) {
			\WP_CLI::error( "Fixtures directory not found: {$fixtures_dir}" );
			return;
		}

		$categories = array( 'core', 'third-party', 'edge-cases' );
		$results    = array(
			'passed' => 0,
			'failed' => 0,
			'errors' => array(),
		);

		foreach ( $categories as $category ) {
			$category_dir = "{$fixtures_dir}/{$category}";

			if ( ! \is_dir( $category_dir ) ) {
				continue;
			}

			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( "%b=== {$category} ===%n" ) );

			$files = \glob( "{$category_dir}/*.html" );

			if ( false === $files || array() === $files ) {
				\WP_CLI::line( '  No fixtures found.' );
				continue;
			}

			foreach ( $files as $file ) {
				$result = $this->run_single_test( $file, $verbose );

				if ( $result['success'] ) {
					++$results['passed'];
				} else {
					++$results['failed'];
					$results['errors'][] = array(
						'file'  => \basename( $file ),
						'error' => $result['error'],
					);
				}
			}
		}

		// Summary.
		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%b=== Summary ===%n' ) );
		\WP_CLI::line( \WP_CLI::colorize( "%g  Passed: {$results['passed']}%n" ) );

		if ( $results['failed'] > 0 ) {
			\WP_CLI::line( \WP_CLI::colorize( "%r  Failed: {$results['failed']}%n" ) );

			foreach ( $results['errors'] as $error ) {
				\WP_CLI::line( \WP_CLI::colorize( "%r    - {$error['file']}: {$error['error']}%n" ) );
			}
		} else {
			\WP_CLI::line( \WP_CLI::colorize( '  Failed: 0' ) );
		}

		if ( $results['failed'] > 0 ) {
			\WP_CLI::error( 'Some tests failed!' );
		} else {
			\WP_CLI::success( 'All tests passed!' );
		}
	}

	/**
	 * Run a single fixture test.
	 *
	 * @param string $file    Path to fixture file.
	 * @param bool   $verbose Show detailed output.
	 * @return array{success: bool, error?: string} Test result.
	 */
	private function run_single_test( string $file, bool $verbose ): array {
		$name    = \basename( $file, '.html' );
		$content = \file_get_contents( $file );

		if ( false === $content ) {
			$this->print_result( $name, false, 'Could not read file' );
			return array(
				'success' => false,
				'error'   => 'Could not read file',
			);
		}

		try {
			// Step 1: Parse blocks.
			$blocks = \parse_blocks( $content );

			if ( $verbose ) {
				\WP_CLI::line( "  Parsed " . \count( $blocks ) . ' top-level blocks' );
			}

			// Step 2: Extract patches.
			$patches = $this->extractor->extract( $blocks, array() );

			if ( $verbose ) {
				\WP_CLI::line( '  Extracted ' . \count( $patches ) . ' patches' );
			}

			// Step 3: Create fake translations.
			$translated_patches = $this->create_fake_translations( $patches );

			// Step 4: Apply patches.
			$translated_blocks = $this->applier->apply( $blocks, $translated_patches, $patches );

			// Step 5: Serialize.
			$output = \serialize_blocks( $translated_blocks );

			// Step 6: Verify translations were applied.
			$verification = $this->verify_translations( $patches, $output, $verbose );

			if ( ! $verification['success'] ) {
				$this->print_result( $name, false, $verification['error'] );
				return array(
					'success' => false,
					'error'   => $verification['error'],
				);
			}

			// Step 7: Verify output is valid (can be re-parsed).
			$reparsed = \parse_blocks( $output );

			if ( \count( $reparsed ) !== \count( $blocks ) ) {
				$error = 'Block count mismatch after serialization';
				$this->print_result( $name, false, $error );
				return array(
					'success' => false,
					'error'   => $error,
				);
			}

			$this->print_result( $name, true, \count( $patches ) . ' patches' );
			return array( 'success' => true );

		} catch ( \Exception $e ) {
			$this->print_result( $name, false, $e->getMessage() );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Create fake translations by prefixing all values.
	 *
	 * @param array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $patches Original patches.
	 * @return array<array{op: string, path: string, value: string}> Translated patches.
	 */
	private function create_fake_translations( array $patches ): array {
		$translated = array();

		foreach ( $patches as $patch ) {
			$translated[] = array(
				'op'    => 'replace',
				'path'  => $patch['path'],
				'value' => self::TRANSLATION_PREFIX . $patch['value'],
			);
		}

		return $translated;
	}

	/**
	 * Verify all translations were applied to output.
	 *
	 * @param array<array{path: string, value: string}> $patches Original patches.
	 * @param string                                    $output  Serialized output.
	 * @param bool                                      $verbose Show detailed output.
	 * @return array{success: bool, error?: string} Verification result.
	 */
	private function verify_translations( array $patches, string $output, bool $verbose ): array {
		$missing = array();

		foreach ( $patches as $patch ) {
			// The translated text should appear in output.
			$expected = self::TRANSLATION_PREFIX . $patch['value'];

			// For HTML content, we need to check if the text (without placeholders) appears.
			// Placeholders like ⟨1⟩ get restored to actual tags.
			$text_to_find = $this->extract_text_for_search( $expected );

			if ( '' !== $text_to_find && ! \str_contains( $output, $text_to_find ) ) {
				$missing[] = $patch['path'];

				if ( $verbose ) {
					\WP_CLI::line( \WP_CLI::colorize( "%r    Missing: {$patch['path']}%n" ) );
					\WP_CLI::line( "      Expected to find: \"{$text_to_find}\"" );
				}
			}
		}

		if ( array() !== $missing ) {
			return array(
				'success' => false,
				'error'   => \count( $missing ) . ' translations missing: ' . \implode( ', ', \array_slice( $missing, 0, 3 ) ),
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Extract searchable text from a value (handles placeholders).
	 *
	 * @param string $value Value potentially containing placeholders.
	 * @return string Text to search for.
	 */
	private function extract_text_for_search( string $value ): string {
		// If value has placeholders, extract the prefix part before any placeholder.
		if ( \preg_match( '/^([^⟨]+)/', $value, $matches ) ) {
			$text = \trim( $matches[1] );
			// Make sure we have meaningful text (not just the TRANSLATED: prefix).
			if ( \strlen( $text ) > \strlen( self::TRANSLATION_PREFIX ) + 2 ) {
				return $text;
			}
		}

		// If no placeholders or too short, search for the prefix followed by something.
		// This handles simple cases where the entire text should appear.
		if ( ! \str_contains( $value, '⟨' ) ) {
			return $value;
		}

		// For complex cases with placeholders, just check for the TRANSLATED: prefix.
		return self::TRANSLATION_PREFIX;
	}

	/**
	 * Print test result.
	 *
	 * @param string $name    Test name.
	 * @param bool   $success Whether test passed.
	 * @param string $detail  Additional detail.
	 * @return void
	 */
	private function print_result( string $name, bool $success, string $detail ): void {
		$icon = $success ? '%g✓%n' : '%r✗%n';
		$line = \WP_CLI::colorize( "  {$icon} {$name}" );

		if ( '' !== $detail ) {
			$color = $success ? '%c' : '%r';
			$line .= \WP_CLI::colorize( " {$color}({$detail})%n" );
		}

		\WP_CLI::line( $line );
	}

	/**
	 * Run test for a single fixture file.
	 *
	 * @param string $fixture Fixture name or path.
	 * @return void
	 */
	public function run_single( string $fixture ): void {
		$fixtures_dir = \dirname( __DIR__, 4 ) . '/tests/fixtures/blocks';

		// Try to find the fixture.
		$file = null;

		// Direct path.
		if ( \file_exists( $fixture ) ) {
			$file = $fixture;
		}

		// Search in categories.
		if ( null === $file ) {
			foreach ( array( 'core', 'third-party', 'edge-cases' ) as $category ) {
				$path = "{$fixtures_dir}/{$category}/{$fixture}";

				if ( \file_exists( $path ) ) {
					$file = $path;
					break;
				}

				// Try with .html extension.
				if ( \file_exists( "{$path}.html" ) ) {
					$file = "{$path}.html";
					break;
				}
			}
		}

		if ( null === $file ) {
			\WP_CLI::error( "Fixture not found: {$fixture}" );
			return;
		}

		\WP_CLI::line( "Testing: {$file}" );
		\WP_CLI::line( '' );

		$result = $this->run_single_test( $file, true );

		if ( $result['success'] ) {
			\WP_CLI::success( 'Test passed!' );
		} else {
			\WP_CLI::error( "Test failed: {$result['error']}" );
		}
	}

	/**
	 * List all available fixtures.
	 *
	 * @return void
	 */
	public function list_fixtures(): void {
		$fixtures_dir = \dirname( __DIR__, 4 ) . '/tests/fixtures/blocks';

		if ( ! \is_dir( $fixtures_dir ) ) {
			\WP_CLI::error( "Fixtures directory not found: {$fixtures_dir}" );
			return;
		}

		$categories = array( 'core', 'third-party', 'edge-cases' );

		foreach ( $categories as $category ) {
			$category_dir = "{$fixtures_dir}/{$category}";

			if ( ! \is_dir( $category_dir ) ) {
				continue;
			}

			\WP_CLI::line( \WP_CLI::colorize( "%b{$category}/%n" ) );

			$files = \glob( "{$category_dir}/*.html" );

			if ( false === $files || array() === $files ) {
				\WP_CLI::line( '  (empty)' );
				continue;
			}

			foreach ( $files as $file ) {
				$name = \basename( $file );
				$size = \filesize( $file );
				\WP_CLI::line( "  {$name} ({$size} bytes)" );
			}

			\WP_CLI::line( '' );
		}
	}
}
