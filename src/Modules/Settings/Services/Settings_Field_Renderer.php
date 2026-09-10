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
		<p><?php \esc_html_e( 'Configure your AI translation settings below.', 'ai-translation-for-polylang' ); ?></p>
		<?php
	}

	public function render_advanced_section_description(): void { ?>
		<p><?php \esc_html_e( 'Configure advanced translation options.', 'ai-translation-for-polylang' ); ?></p>
		<?php
	}

	public function render_api_provider_field(): void {
		$active_api = $this->settings_service->get_active_translation_api();
		$providers  = AI_Provider_Registry::get_providers_for_options_with_availability();
		?>
		<select name="pllat_translator_api" id="pllat_translator_api" class="regular-text">
			<?php foreach ( $providers as $api_key => $provider_info ) : ?>
				<?php
				$is_available = $provider_info['available'];
				$label        = $provider_info['name'];
				if ( ! $is_available ) {
					$label .= ' ' . \__( '(Coming Soon)', 'ai-translation-for-polylang' );
				}
				?>
				<option value="<?php echo \esc_attr( $api_key ); ?>"
						<?php \selected( $active_api, $api_key ); ?>
						<?php \disabled( ! $is_available ); ?>>
					<?php echo \esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php \esc_html_e( 'Select the AI provider for translations.', 'ai-translation-for-polylang' ); ?>
			<?php if ( ! AI_Provider_Registry::is_provider_available( $active_api ) ) : ?>
				<br><strong style="color: #d63638;">
					<?php \esc_html_e( 'Note: Your selected provider is not available yet. OpenAI will be used as fallback.', 'ai-translation-for-polylang' ); ?>
				</strong>
			<?php endif; ?>
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
		$can_test = '' !== $api_key_value && $provider === $this->settings_service->get_active_translation_api();
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
				<?php \esc_html_e( 'Test connection', 'ai-translation-for-polylang' ); ?>
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
					<?php \esc_html_e( 'Save your API key first, then test the connection.', 'ai-translation-for-polylang' ); ?>
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
					'ai-translation-for-polylang',
				),
				\esc_html( \number_format_i18n( Settings_Service::MIN_OUTPUT_TOKENS ) ),
			);
			?>
		</p>
		<?php
	}

	private function get_api_key_description( string $api ): string {
		$description = AI_Provider_Registry::get_api_key_description_for_provider( $api );
		return $description ?: \__(
			'Enter your API key for this provider.',
			'ai-translation-for-polylang',
		);
	}
}
