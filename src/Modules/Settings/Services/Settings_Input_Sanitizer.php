<?php
declare(strict_types=1);

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\AI_Provider_Registry;

/**
 * Sanitization callbacks for the WordPress Settings API.
 *
 * Each method is wired as a `sanitize_callback` when settings are registered
 * in Settings_Form::register_settings(). Kept separate so the rendering layer
 * stays focused on producing markup, not validating writes.
 */
class Settings_Input_Sanitizer {

	public function __construct(
		private Settings_Service $settings_service,
	) {}

	/**
	 * @param mixed $value The raw input value.
	 */
	public function sanitize_api_provider( $value ): string {
		if ( null === $value || ! \is_string( $value ) ) {
			return 'openai';
		}

		$providers = AI_Provider_Registry::get_providers_for_options();
		return \array_key_exists( $value, $providers ) ? $value : 'openai';
	}

	/**
	 * @param mixed $value The raw input value.
	 */
	public function sanitize_translation_mode( $value ): string {
		if ( null === $value || ! \is_string( $value ) ) {
			return 'byok';
		}

		return \in_array( $value, array( 'byok', 'credits' ), true ) ? $value : 'byok';
	}

	/**
	 * @param mixed $value The raw input value.
	 */
	public function sanitize_processing_method( $value ): string {
		if ( null === $value || ! \is_string( $value ) ) {
			return 'self_hosted';
		}

		return \in_array( $value, array( 'self_hosted', 'external' ), true ) ? $value : 'self_hosted';
	}

	/**
	 * Sanitize a model selection for a specific provider.
	 *
	 * Null values mean the field was not submitted (provider inactive); we
	 * preserve the existing DB value so other providers' models stay intact.
	 * Providers that support custom model strings accept any non-empty input;
	 * others validate against the registry's known model list.
	 *
	 * @param mixed  $value    The raw input value.
	 * @param string $provider The provider whose model is being sanitized.
	 */
	public function sanitize_model_for_api( $value, string $provider ): string {
		if ( null === $value ) {
			return $this->settings_service->get_translation_model( $provider );
		}

		if ( AI_Provider_Registry::provider_supports_custom_model( $provider ) ) {
			$sanitized = \is_string( $value ) ? \trim( $value ) : '';
			return '' !== $sanitized
				? $sanitized
				: AI_Provider_Registry::get_default_model_for_provider( $provider );
		}

		$models = AI_Provider_Registry::get_models_for_provider( $provider );
		return \array_key_exists( $value, $models )
			? $value
			: AI_Provider_Registry::get_default_model_for_provider( $provider );
	}

	/**
	 * @param mixed $value The raw input value.
	 */
	public function sanitize_max_tokens( $value ): int {
		$tokens = \intval( $value );

		// Floor is the default, not a token amount someone might plausibly want:
		// multiple support cases traced back to sites sitting at 100, where the
		// model has room for a few words and every translation is silently cut
		// off mid-sentence. Nothing about this workload works below 16000 — one
		// batch of Gutenberg patches alone needs far more.
		//
		// Ceiling stays at 32000: the plugin does not stream, so a large output
		// budget risks HTTP timeouts rather than better translations.
		return \max( Settings_Service::MIN_OUTPUT_TOKENS, \min( 32000, $tokens ) );
	}

	/**
	 * @param mixed $value The raw input value.
	 */
	public function sanitize_checkbox( $value ): bool {
		return '1' === $value || 1 === $value || true === $value;
	}
}
