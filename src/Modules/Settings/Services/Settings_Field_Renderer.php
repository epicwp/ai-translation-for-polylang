<?php
// phpcs:disable
declare(strict_types=1);

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Content\Services\Meta_Field_Classification_Service;
use PLLAT\Translator\Services\AI_Provider_Factory;
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
		<p><?php \esc_html_e( 'Configure advanced translation options including AI context and custom meta fields.', 'ai-translation-for-polylang' ); ?></p>
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
		$provider       = $args['provider'];
		$api_key_value = $this->settings_service->get_translation_api_key( $provider );
		?>
		<div class="api-key-row" data-api="<?php echo \esc_attr( $provider ); ?>">
			<input type="password"
				name="pllat_<?php echo \esc_attr( $provider ); ?>_api_key"
				id="pllat_<?php echo \esc_attr( $provider ); ?>_api_key"
				value="<?php echo \esc_attr( $api_key_value ); ?>"
				class="regular-text"
				autocomplete="off"
			/>
			<p class="description">
				<?php echo $this->get_api_key_description( $provider ); ?>
			</p>
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

	public function render_internal_link_translation_field(): void {
		$enabled = (bool) \get_option( 'pllat_internal_link_translation', true );
		?>
		<label for="pllat_internal_link_translation">
			<input type="checkbox"
				   name="pllat_internal_link_translation"
				   id="pllat_internal_link_translation"
				   value="1"
				   <?php \checked( $enabled ); ?>>
			<?php \esc_html_e( 'Automatically replace internal links in translated content', 'ai-translation-for-polylang' ); ?>
		</label>
		<p class="description">
			<?php \esc_html_e( 'When enabled, internal links in translated posts and terms are automatically replaced with their translated equivalents after each translation.', 'ai-translation-for-polylang' ); ?>
		</p>
		<?php
		$total_replaced = (int) \get_option( 'pllat_total_links_replaced', 0 );

		if ( $enabled && $total_replaced > 0 ) {
			?>
			<p class="description" style="margin-top: 6px;">
				<span class="dashicons dashicons-admin-links" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-top; color: #646970;"></span>
				<?php
				/* translators: %s: number of links replaced */
				echo \esc_html( \sprintf( \__( '%s links replaced', 'ai-translation-for-polylang' ), \number_format_i18n( $total_replaced ) ) );
				?>
			</p>
			<?php
		}
	}

	public function render_meta_field_management(): void {
		$classification = new Meta_Field_Classification_Service();
		$ai_configured  = AI_Provider_Factory::can_create_from_settings( $this->settings_service );
		$grouped        = $classification->get_grouped_classifications();

		$active_post_types = \get_post_types( array( 'public' => true ) );
		$active_taxonomies = \get_taxonomies( array( 'public' => true ) );

		$all_content_types = \array_unique( \array_merge(
			\array_keys( $grouped ),
			\array_keys( $active_post_types ),
			\array_map( array( Meta_Field_Classification_Service::class, 'taxonomy_key' ), \array_keys( $active_taxonomies ) ),
		) );
		// Pin post and page to the front, then sort the rest alphabetically.
		$pinned = array_intersect( array( 'post', 'page', 'product' ), $all_content_types );
		$rest   = array_diff( $all_content_types, $pinned );
		\sort( $rest );
		$all_content_types = \array_merge( $pinned, $rest );

		$labels = array();
		foreach ( $all_content_types as $ct ) {
			if ( Meta_Field_Classification_Service::is_taxonomy_key( $ct ) ) {
				$raw_slug = Meta_Field_Classification_Service::strip_taxonomy_prefix( $ct );
				$tax_obj  = \get_taxonomy( $raw_slug );
				$labels[ $ct ] = $tax_obj
					? $tax_obj->labels->singular_name . ' ' . \__( '(Taxonomy)', 'ai-translation-for-polylang' )
					: $raw_slug . ' ' . \__( '(Taxonomy)', 'ai-translation-for-polylang' );
			} else {
				$pt_obj = \get_post_type_object( $ct );
				$labels[ $ct ] = $pt_obj ? $pt_obj->labels->singular_name : $ct;
			}
		}

		$tabs = \array_values( \array_filter( $all_content_types, fn( $ct ) => isset( $grouped[ $ct ] ) ) );
		?>
		<div id="pllat-meta-field-management">
			<p style="margin: 0 0 8px; color: #50575e; max-width: 680px;">
				<?php \esc_html_e(
					'WordPress plugins and themes like WooCommerce, Yoast SEO, and Kadence store additional data (like prices, SEO titles, or layout settings) in meta fields. This section lets you control how each field is handled during translation.',
					'ai-translation-for-polylang',
				); ?>
			</p>
			<p style="margin: 0 0 16px; color: #50575e; max-width: 680px;">
				<?php \esc_html_e(
					'Use "Scan for new fields" to automatically detect and classify fields, or manage them manually per content type.',
					'ai-translation-for-polylang',
				); ?>
			</p>

			<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
				<button type="button"
					id="pllat-scan-meta-fields"
					class="button button-secondary"
					<?php echo $ai_configured ? '' : 'disabled'; ?>
					<?php if ( ! $ai_configured ) : ?>
						title="<?php \esc_attr_e( 'Configure an AI provider to enable scanning.', 'ai-translation-for-polylang' ); ?>"
					<?php endif; ?>
				>
					<?php \esc_html_e( 'Scan for new fields', 'ai-translation-for-polylang' ); ?>
				</button>
				<span id="pllat-scan-status" style="color: #666;"></span>
				<?php
				$last_scan = \get_option( 'pllat_meta_field_last_scan' )
					?: \get_option( 'pllat_meta_field_activation_scan_done' )
					?: null;
				if ( $last_scan ) :
					$human_time = \human_time_diff( $last_scan, \time() );
				?>
					<span style="color: #999; font-size: 12px;">
						<?php
						/* translators: %s: human-readable time difference, e.g. "2 hours" */
						\printf( \esc_html__( 'Last scanned %s ago', 'ai-translation-for-polylang' ), \esc_html( $human_time ) );
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( \count( $tabs ) > 0 ) : ?>
				<!-- Tab bar -->
				<div style="border-bottom: 1px solid #ccc; margin-bottom: 0;">
					<?php foreach ( $tabs as $i => $ct ) : ?>
						<button type="button"
							class="pllat-meta-tab<?php echo 0 === $i ? ' pllat-meta-tab--active' : ''; ?>"
							data-content-type="<?php echo \esc_attr( $ct ); ?>"
							style="padding: 8px 16px; margin: 0 2px -1px 0; border: 1px solid <?php echo 0 === $i ? '#ccc' : 'transparent'; ?>; border-bottom-color: <?php echo 0 === $i ? '#fff' : 'transparent'; ?>; background: <?php echo 0 === $i ? '#fff' : 'transparent'; ?>; cursor: pointer; font-size: 13px; border-radius: 4px 4px 0 0; font-weight: <?php echo 0 === $i ? '600' : 'normal'; ?>;"
						>
							<?php echo \esc_html( $labels[ $ct ] ?? $ct ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<!-- Tab panels -->
				<?php foreach ( $tabs as $i => $ct ) :
					$types   = $grouped[ $ct ] ?? array( 'translate' => array(), 'copy' => array(), 'ignore' => array() );
					$t_count = \count( $types['translate'] );
					$c_count = \count( $types['copy'] );
					$i_count = \count( $types['ignore'] );
				?>
					<div class="pllat-meta-panel"
						 data-content-type="<?php echo \esc_attr( $ct ); ?>"
						 style="<?php echo 0 !== $i ? 'display: none;' : ''; ?> padding: 16px; border: 1px solid #ccc; border-top: none; background: #fff;">

						<!-- Translate -->
						<div style="margin-bottom: 12px;">
							<div style="display: flex; align-items: center; gap: 4px; margin-bottom: 6px;">
								<span style="font-size: 11px; font-weight: 600; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">
									<?php
									/* translators: %d: number of translate fields */
									\printf( \esc_html__( 'Translate (%d)', 'ai-translation-for-polylang' ), $t_count );
									?>
								</span>
								<span class="pllat-tooltip pllat-tooltip--wide dashicons dashicons-editor-help"
									  data-tooltip="<?php \esc_attr_e( 'These fields contain human-readable text and will be sent to AI for translation.', 'ai-translation-for-polylang' ); ?>"
									  style="font-size: 14px; width: 14px; height: 14px; color: rgba(22, 101, 52, 0.6); cursor: help;"></span>
							</div>
							<div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
								<?php foreach ( $types['translate'] as $key ) : ?>
									<span class="pllat-meta-chip" data-key="<?php echo \esc_attr( $key ); ?>" data-category="translate"
										  style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 12px; font-family: monospace; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; border-radius: 3px;">
										<?php echo \esc_html( $key ); ?>
										<button type="button" class="pllat-meta-remove" style="background: none; border: none; cursor: pointer; padding: 0; color: #166534; font-size: 14px; line-height: 1;" title="<?php \esc_attr_e( 'Remove', 'ai-translation-for-polylang' ); ?>">&times;</button>
									</span>
								<?php endforeach; ?>
								<button type="button" class="pllat-meta-add button" data-category="translate"
										style="font-size: 12px; padding: 2px 8px; line-height: 1.6;">
									+ <?php \esc_html_e( 'Add', 'ai-translation-for-polylang' ); ?>
								</button>
							</div>
						</div>

						<!-- Copy -->
						<div style="margin-bottom: 12px;">
							<div style="display: flex; align-items: center; gap: 4px; margin-bottom: 6px;">
								<span style="font-size: 11px; font-weight: 600; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px;">
									<?php
									/* translators: %d: number of copy fields */
									\printf( \esc_html__( 'Copy (%d)', 'ai-translation-for-polylang' ), $c_count );
									?>
								</span>
								<span class="pllat-tooltip pllat-tooltip--wide dashicons dashicons-editor-help"
									  data-tooltip="<?php \esc_attr_e( 'These fields contain structural or configuration values. They are duplicated as-is to translated posts without modification.', 'ai-translation-for-polylang' ); ?>"
									  style="font-size: 14px; width: 14px; height: 14px; color: rgba(30, 64, 175, 0.6); cursor: help;"></span>
							</div>
							<div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
								<?php foreach ( $types['copy'] as $key ) : ?>
									<span class="pllat-meta-chip" data-key="<?php echo \esc_attr( $key ); ?>" data-category="copy"
										  style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 12px; font-family: monospace; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 3px;">
										<?php echo \esc_html( $key ); ?>
										<button type="button" class="pllat-meta-remove" style="background: none; border: none; cursor: pointer; padding: 0; color: #1e40af; font-size: 14px; line-height: 1;" title="<?php \esc_attr_e( 'Remove', 'ai-translation-for-polylang' ); ?>">&times;</button>
									</span>
								<?php endforeach; ?>
								<button type="button" class="pllat-meta-add button" data-category="copy"
										style="font-size: 12px; padding: 2px 8px; line-height: 1.6;">
									+ <?php \esc_html_e( 'Add', 'ai-translation-for-polylang' ); ?>
								</button>
							</div>
						</div>

						<!-- Ignore (collapsible) -->
						<?php if ( $i_count > 0 ) : ?>
							<details>
								<summary style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; user-select: none;">
									<?php
									/* translators: %d: number of ignored fields */
									\printf( \esc_html__( 'Ignored (%d)', 'ai-translation-for-polylang' ), $i_count );
									?>
									<span class="pllat-tooltip pllat-tooltip--wide dashicons dashicons-editor-help"
										  data-tooltip="<?php \esc_attr_e( 'Internal or technical fields not relevant for translation. Ignored fields are skipped during scans and translation.', 'ai-translation-for-polylang' ); ?>"
										  style="font-size: 14px; width: 14px; height: 14px; color: rgba(107, 114, 128, 0.6); cursor: help; vertical-align: middle;"></span>
								</summary>
								<div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px;">
									<?php foreach ( $types['ignore'] as $key ) : ?>
										<span class="pllat-meta-chip" data-key="<?php echo \esc_attr( $key ); ?>" data-category="ignore"
											  style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 12px; font-family: monospace; background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; border-radius: 3px;">
											<?php echo \esc_html( $key ); ?>
										</span>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p style="color: #666; font-style: italic;">
					<?php \esc_html_e( 'No fields classified yet. Click "Scan for new fields" to discover meta fields automatically.', 'ai-translation-for-polylang' ); ?>
				</p>
			<?php endif; ?>
		</div>
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
