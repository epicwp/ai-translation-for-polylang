<?php
declare(strict_types=1);

namespace PLLAT\CLI\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\CLI\Services\Content_CLI_Service;
use XWP\DI\Decorators\CLI_Command;
use XWP\DI\Decorators\CLI_Handler;

/**
 * WP-CLI commands for inspecting post/term translation state.
 * Delegates everything to Content_CLI_Service.
 */
#[CLI_Handler(
	namespace: 'pllat',
	description: 'Polylang AI Automatic Translation — post/term commands',
	container: 'pllat',
)]
class Content_CLI_Handler {

	public function __construct(
		protected Content_CLI_Service $content_cli_service,
	) {}

	/**
	 * Show all language translations for a post.
	 *
	 * USAGE: wp pllat post:translations <post_id>
	 */
	#[CLI_Command(
		command: 'post:translations',
		summary: 'Show translations for a post',
		args: array(
			array(
				'name'        => 'post_id',
				'type'        => 'positional',
				'description' => 'The post ID',
				'optional'    => false,
			),
		),
	)]
	public function post_translations( int $post_id ): void {
		$this->content_cli_service->show_translations( 'post', $post_id );
	}

	/**
	 * Show the Polylang language assigned to a post.
	 *
	 * USAGE: wp pllat post:language <post_id>
	 */
	#[CLI_Command(
		command: 'post:language',
		summary: 'Show language of a post',
		args: array(
			array(
				'name'        => 'post_id',
				'type'        => 'positional',
				'description' => 'The post ID',
				'optional'    => false,
			),
		),
	)]
	public function post_language( int $post_id ): void {
		$this->content_cli_service->show_language( 'post', $post_id );
	}

	/**
	 * Show translatable standard post fields (title, content, excerpt, slug).
	 *
	 * USAGE: wp pllat post:fields <post_id>
	 */
	#[CLI_Command(
		command: 'post:fields',
		summary: 'Show translatable standard fields for a post',
		args: array(
			array(
				'name'        => 'post_id',
				'type'        => 'positional',
				'description' => 'The post ID',
				'optional'    => false,
			),
		),
	)]
	public function post_fields( int $post_id ): void {
		$this->content_cli_service->show_post_fields( $post_id );
	}

	/**
	 * Show translatable meta fields (SEO, ACF, custom) for a post.
	 *
	 * USAGE: wp pllat post:meta_fields <post_id>
	 */
	#[CLI_Command(
		command: 'post:meta_fields',
		summary: 'Show translatable meta fields for a post',
		args: array(
			array(
				'name'        => 'post_id',
				'type'        => 'positional',
				'description' => 'The post ID',
				'optional'    => false,
			),
		),
	)]
	public function post_meta_fields( int $post_id ): void {
		$this->content_cli_service->show_post_meta_fields( $post_id );
	}

	/**
	 * Show all language translations for a term.
	 *
	 * USAGE: wp pllat term:translations <term_id>
	 */
	#[CLI_Command(
		command: 'term:translations',
		summary: 'Show translations for a term',
		args: array(
			array(
				'name'        => 'term_id',
				'type'        => 'positional',
				'description' => 'The term ID',
				'optional'    => false,
			),
		),
	)]
	public function term_translations( int $term_id ): void {
		$this->content_cli_service->show_translations( 'term', $term_id );
	}

	/**
	 * Show the Polylang language assigned to a term.
	 *
	 * USAGE: wp pllat term:language <term_id>
	 */
	#[CLI_Command(
		command: 'term:language',
		summary: 'Show language of a term',
		args: array(
			array(
				'name'        => 'term_id',
				'type'        => 'positional',
				'description' => 'The term ID',
				'optional'    => false,
			),
		),
	)]
	public function term_language( int $term_id ): void {
		$this->content_cli_service->show_language( 'term', $term_id );
	}

	/**
	 * Show translatable standard term fields (name, description, slug).
	 *
	 * USAGE: wp pllat term:fields <term_id>
	 */
	#[CLI_Command(
		command: 'term:fields',
		summary: 'Show translatable standard fields for a term',
		args: array(
			array(
				'name'        => 'term_id',
				'type'        => 'positional',
				'description' => 'The term ID',
				'optional'    => false,
			),
		),
	)]
	public function term_fields( int $term_id ): void {
		$this->content_cli_service->show_term_fields( $term_id );
	}

	/**
	 * Show translatable meta fields (SEO, custom) for a term.
	 *
	 * USAGE: wp pllat term:meta_fields <term_id>
	 */
	#[CLI_Command(
		command: 'term:meta_fields',
		summary: 'Show translatable meta fields for a term',
		args: array(
			array(
				'name'        => 'term_id',
				'type'        => 'positional',
				'description' => 'The term ID',
				'optional'    => false,
			),
		),
	)]
	public function term_meta_fields( int $term_id ): void {
		$this->content_cli_service->show_term_meta_fields( $term_id );
	}
}
