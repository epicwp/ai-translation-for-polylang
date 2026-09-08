<?php
/**
 * AI_Provider_Gate class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Providers\AI_Provider;

/**
 * DI-friendly wrapper around the static AI_Provider_Factory that wires
 * the circuit-breaker health service into the resulting AI_Client.
 *
 * The circuit check itself happens in AI_Client::chat_completion() so an
 * open breaker never breaks DI graph construction (a thrown exception
 * here would fatal every request that resolves AI_Client transitively).
 *
 * Pre-flight test code (settings UI, validation gateway) keeps using
 * AI_Provider_Factory directly so it can probe even when the circuit
 * is open.
 */
class AI_Provider_Gate {

	public function __construct(
		private Settings_Service $settings,
		private Provider_Health_Service $health,
	) {}

	/**
	 * @throws \Exception Rethrown from AI_Provider_Factory on misconfiguration.
	 */
	public function create(): AI_Provider {
		$provider = AI_Provider_Factory::create_from_settings( $this->settings );
		$client   = $provider->get_client();

		// Resolved provider may differ from settings (fallback). Use the actual
		// provider key for health bookkeeping so circuit state stays correct.
		$client->set_health_service( $this->health, $provider->get_provider_key() );

		return $provider;
	}

	/**
	 * Convenience: skip the wrapper and return the wired AI_Client directly.
	 *
	 * @throws \Exception
	 */
	public function create_client(): AI_Client {
		return $this->create()->get_client();
	}
}
