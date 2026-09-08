<?php
/**
 * Meta_Field_Scan_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Services\Meta_Field_Scanner_Service;
use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Services\AI_Provider_Factory;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Schedules and handles one-time meta field scan on plugin activation.
 *
 * Uses a transient-based approach: on admin_init, checks if the activation
 * scan has been scheduled. If not and AI is configured, enqueues an async
 * Action Scheduler action to scan all translatable post types.
 */
#[Handler( tag: 'init', priority: 18 )]
class Meta_Field_Scan_Handler {

	public const HOOK_SCAN        = 'pllat_meta_field_scan';
	private const OPTION_SCANNED  = 'pllat_meta_field_activation_scan_done';

	/**
	 * Constructor.
	 *
	 * @param Meta_Field_Scanner_Service $scanner          Meta field scanner service.
	 * @param Settings_Service           $settings         Plugin settings service.
	 * @param Language_Manager           $language_manager Language manager.
	 */
	public function __construct(
		private readonly Meta_Field_Scanner_Service $scanner,
		private readonly Settings_Service $settings,
		private readonly Language_Manager $language_manager,
	) {}

	/**
	 * Schedule initial scan on activation if AI is configured and not already done.
	 *
	 * Runs on admin_init to ensure DI container and Polylang are fully loaded.
	 * Uses an option flag to ensure this only fires once per installation.
	 *
	 * @return void
	 */
	#[Action( tag: 'admin_init', priority: 20 )]
	public function schedule_activation_scan(): void {
		if ( \get_option( self::OPTION_SCANNED ) !== false ) {
			return;
		}

		// Mark as done immediately to prevent duplicate scheduling.
		\update_option( self::OPTION_SCANNED, \time(), true );

		if ( ! AI_Provider_Factory::can_create_from_settings( $this->settings ) ) {
			return;
		}

		if ( \as_has_scheduled_action( self::HOOK_SCAN, array(), 'pllat-explorer' ) ) {
			return;
		}

		\as_enqueue_async_action( self::HOOK_SCAN, array(), 'pllat-explorer' );
	}

	/**
	 * Process the scheduled scan — scans all translatable post types.
	 *
	 * @return void
	 */
	#[Action( tag: 'pllat_meta_field_scan' )]
	public function process_scan(): void {
		$post_types = $this->language_manager->get_active_post_types();

		foreach ( $post_types as $post_type ) {
			$this->scanner->scan( $post_type );
		}

		\update_option( 'pllat_meta_field_last_scan', \time(), false );
	}

}
