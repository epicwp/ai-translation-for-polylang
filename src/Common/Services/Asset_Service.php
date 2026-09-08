<?php
/**
 * Service for managing shared frontend assets.
 *
 * @package Polylang_AI_Automatic_Translation
 */

namespace PLLAT\Common\Services;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Service;

/**
 * Service for managing shared frontend assets (icons, images, etc).
 *
 * Provides centralized access to plugin assets for both Dashboard and Single Translator UIs.
 */
#[Service]
class Asset_Service {

	/**
	 * Get shared assets for frontend (icons, images, etc).
	 *
	 * @return array Shared assets data.
	 */
	public function get_shared_assets(): array {
		return array(
			'icons' => array(
				'dotsLoader' => PLLAT_PLUGIN_URL . 'dist/images/3-dots-bounce.svg',
				'ringLoader' => PLLAT_PLUGIN_URL . 'dist/images/ring-resize.svg',
			),
		);
	}
}
