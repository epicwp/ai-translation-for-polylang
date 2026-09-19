<?php
// phpcs:disable
declare(strict_types=1);

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\AI_Provider_Registry;

/**
 * Field-level renderers for the WordPress Settings API.
 *
 * Each render_*_field method is the `callback` argument passed to
 * add_settings_field() in Settings_Form. Section descriptions live here
 * too.
 */
class Settings_Field_Renderer {

	public function __construct(
		private Settings_Service $settings_service,
	) {}

	public function render_section_description(): void { ?>
		<p><?php \esc_html_e( 'Configure your AI translation settings below.', 'epicwp-ai-translation-for-polylang' ); ?></p>
		<?php
	}

	public function render_advanced_section_description(): void { ?>
		<p><?php \esc_html_e( 'Configure advanced translation options.', 'epicwp-ai-translation-for-polylang' ); ?></p>
		<?php
	}

	public function render_api_provider_field(): void {
		$active_api = $this->active_provider_key();
		$providers  = AI_Provider_Registry::get_providers_for_options();

		// One registered provider: nothing to choose, so name it. The option
		// is then absent from the form and the sanitizer stores that provider.
		if ( 1 === \count( $providers ) ) {
			?>
			<span id="pllat_translator_api" class="pllat-provider-name"><?php echo \esc_html( (string) \reset( $providers ) ); ?></span>
			<?php
			return;
		}
		?>
		<select name="pllat_translator_api" id="pllat_translator_api" class="regular-text">
			<?php foreach ( $providers as $api_key => $label ) : ?>
				<option value="<?php echo \esc_attr( $api_key ); ?>" <?php \selected( $active_api, $api_key ); ?>>
					<?php echo \esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php \esc_html_e( 'Select the AI provider for translations.', 'epicwp-ai-translation-for-polylang' ); ?>
		</p>
		<?php
	}

	/**
	 * @param array<string,string> $args The field arguments containing provider and label.
	 */
	public function render_api_key_field( array $args ): void {
		$provider      = $args['provider'];
		$api_key_value = $this->settings_service->get_translation_api_key( $provider );
		// The test endpoint pings the saved active provider, so an unsaved key cannot be tested.
		$can_test = '' !== $api_key_value && $provider === $this->active_provider_key();
		?>
		<div class="api-key-row" data-api="<?php echo \esc_attr( $provider ); ?>">
			<input type="password"
				name="pllat_<?php echo \esc_attr( $provider ); ?>_api_key"
				id="pllat_<?php echo \esc_attr( $provider ); ?>_api_key"
				value="<?php echo \esc_attr( $api_key_value ); ?>"
				class="regular-text"
				autocomplete="off"
			/>
			<button type="button" class="button pllat-test-connection" <?php \disabled( ! $can_test ); ?>>
				<?php \esc_html_e( 'Test connection', 'epicwp-ai-translation-for-polylang' ); ?>
			</button>
			<span class="pllat-test-connection-result" role="status" style="margin-left: 8px;"></span>
			<p class="description">
				<?php
				echo \wp_kses(
					$this->get_api_key_description( $provider ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					),
				);
				?>
			</p>
			<?php if ( ! $can_test ) : ?>
				<p class="description">
					<?php \esc_html_e( 'Save your API key first, then test the connection.', 'epicwp-ai-translation-for-polylang' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_max_tokens_field(): void {
		$max_tokens = $this->settings_service->get_max_output_tokens();
		?>
		<input type="number"
			name="pllat_max_output_tokens"
			id="pllat_max_output_tokens"
			value="<?php echo \esc_attr( $max_tokens ); ?>"
			class="small-text"
			min="<?php echo \esc_attr( (string) Settings_Service::MIN_OUTPUT_TOKENS ); ?>"
			max="32000"
			step="100" />
		<p class="description">
			<?php
			\printf(
				/* translators: %s: minimum number of output tokens. */
				\esc_html__(
					'Maximum number of tokens for AI responses. Higher values allow longer translations but increase costs. Values below %s are raised to that minimum: less room than that cuts translations off mid-sentence.',
					'epicwp-ai-translation-for-polylang',
				),
				\esc_html( \number_format_i18n( Settings_Service::MIN_OUTPUT_TOKENS ) ),
			);
			?>
		</p>
		<?php
	}

	/**
	 * The provider the stored key stands for, resolved like AI_Provider_Factory
	 * does: a key no registered provider answers to (a site that moved from
	 * an edition with more providers) means the first registered provider, so
	 * Test connection works before the settings are saved once.
	 */
	private function active_provider_key(): string {
		$active = $this->settings_service->get_active_translation_api();
		return AI_Provider_Registry::resolve_provider( $active )?->get_provider_key() ?? $active;
	}

	private function get_api_key_description( string $api ): string {
		$description = AI_Provider_Registry::get_api_key_description_for_provider( $api );
		return $description ?: \__(
			'Enter your API key for this provider.',
			'epicwp-ai-translation-for-polylang',
		);
	}
}
