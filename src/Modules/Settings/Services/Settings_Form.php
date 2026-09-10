<?php
// phpcs:disable
declare(strict_types=1);

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Services\Health_Service;
use PLLAT\Translator\Services\AI_Provider_Registry;

/**
 * Orchestrates the AI Translation admin settings page.
 *
 * Owns: WordPress Settings API registration, the top-level tab dispatch
 * in render(), and asset enqueueing. Field rendering, input sanitization,
 * and the Support tab live in dedicated collaborators. Other modules add
 * tabs through the `pllat_settings_tabs` filter (rendered on
 * `pllat_settings_render_tab_{$tab}`) and fields through the
 * `pllat_settings_general_fields` / `pllat_settings_advanced_fields` filters.
 */
class Settings_Form {

	public function __construct(
		private Settings_Service $settings_service,
		private Settings_Field_Renderer $field_renderer,
		private Settings_Input_Sanitizer $sanitizer,
		private Settings_Support_Tab_Renderer $support_tab_renderer,
		private Health_Service $health_service,
	) {}

	public function register_hooks(): void {
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function register_settings(): void {
		\register_setting(
			'pllat_settings_group',
			'pllat_translator_api',
			array(
				'default'           => 'openai',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_api_provider' ),
			),
		);
		\register_setting(
			'pllat_advanced_settings_group',
			'pllat_max_output_tokens',
			array(
				'default'           => 16000,
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_max_tokens' ),
			),
		);

		\register_setting(
			'pllat_debug_settings_group',
			'pllat_debug_mode',
			array(
				'default'           => true,
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_checkbox' ),
			),
		);

		$providers = AI_Provider_Registry::get_providers_for_options();
		foreach ( \array_keys( $providers ) as $provider ) {
			\register_setting(
				'pllat_settings_group',
				"pllat_{$provider}_api_key",
				array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			);
		}

		// Add settings section for General tab
		\add_settings_section(
			'pllat_main_section',
			\__( 'AI Translation Configuration', 'ai-translation-for-polylang' ),
			array( $this->field_renderer, 'render_section_description' ),
			'pllat_settings',
		);

		// Add settings section for Advanced tab
		\add_settings_section(
			'pllat_advanced_section',
			\__( 'Advanced Settings', 'ai-translation-for-polylang' ),
			array( $this->field_renderer, 'render_advanced_section_description' ),
			'pllat_advanced_settings',
		);

		/**
		 * Filters the General tab fields, keyed by field id in render order.
		 *
		 * @param array<string,array{title: string, callback: callable, args?: array<string,mixed>}> $fields
		 */
		$this->add_settings_fields(
			'pllat_settings',
			'pllat_main_section',
			\apply_filters( 'pllat_settings_general_fields', $this->get_general_fields() ),
		);

		/**
		 * Filters the Advanced tab fields, keyed by field id in render order.
		 *
		 * @param array<string,array{title: string, callback: callable, args?: array<string,mixed>}> $fields
		 */
		$this->add_settings_fields(
			'pllat_advanced_settings',
			'pllat_advanced_section',
			\apply_filters( 'pllat_settings_advanced_fields', $this->get_advanced_fields() ),
		);
	}

	/**
	 * @return array<string,array{title: string, callback: callable, args?: array<string,mixed>}>
	 */
	private function get_general_fields(): array {
		$fields = array(
			'pllat_translator_api' => array(
				'title'    => \__( 'Translation API Provider', 'ai-translation-for-polylang' ),
				'callback' => array( $this->field_renderer, 'render_api_provider_field' ),
			),
		);

		foreach ( AI_Provider_Registry::get_providers_for_options() as $provider => $label ) {
			$fields[ "pllat_{$provider}_api_key" ] = array(
				'title'    => \sprintf( \__( '%s API Key', 'ai-translation-for-polylang' ), $label ),
				'callback' => array( $this->field_renderer, 'render_api_key_field' ),
				'args'     => array(
					'provider' => $provider,
					'label'   => $label,
				),
			);
		}

		return $fields;
	}

	/**
	 * @return array<string,array{title: string, callback: callable, args?: array<string,mixed>}>
	 */
	private function get_advanced_fields(): array {
		return array(
			'pllat_max_output_tokens' => array(
				'title'    => \__( 'Max Output Tokens', 'ai-translation-for-polylang' ),
				'callback' => array( $this->field_renderer, 'render_max_tokens_field' ),
			),
		);
	}

	/**
	 * @param array<string,array{title: string, callback: callable, args?: array<string,mixed>}> $fields
	 */
	private function add_settings_fields( string $page, string $section, array $fields ): void {
		foreach ( $fields as $id => $field ) {
			\add_settings_field( $id, $field['title'], $field['callback'], $page, $section, $field['args'] ?? array() );
		}
	}

	public function enqueue_scripts( string $hook ): void {
		if ( 'languages_page_pllat-settings' !== $hook ) {
			return;
		}

		\wp_enqueue_style(
			'pllat-admin',
			PLLAT_PLUGIN_URL . 'dist/admin/admin.css',
			array(),
			\filemtime( PLLAT_PLUGIN_DIR . 'dist/admin/admin.css' ),
		);

		\wp_enqueue_script( 'jquery' );
		\wp_enqueue_script(
			'pllat-settings',
			\plugins_url( '../assets/settings.js', __FILE__ ),
			array( 'jquery' ),
			'1.0.0',
			true,
		);

		\wp_localize_script(
			'pllat-settings',
			'pllat_settings',
			array(
				'restUrl' => \rest_url( 'pllat/v1/' ),
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'testing'   => \__( 'Testing connection...', 'ai-translation-for-polylang' ),
					'connected' => \__( 'Connected', 'ai-translation-for-polylang' ),
					/* translators: %s: error message from the provider */
					'failed'    => \__( 'Connection failed: %s', 'ai-translation-for-polylang' ),
					'unknown'   => \__( 'unknown error', 'ai-translation-for-polylang' ),
				),
			),
		);

		\wp_enqueue_script(
			'pllat-support-tab',
			\plugins_url( '../assets/support-tab.js', __FILE__ ),
			array( 'jquery' ),
			'1.0.0',
			true,
		);

		\wp_localize_script(
			'pllat-support-tab',
			'pllat_support',
			array(
				'ajax_url' => \admin_url( 'admin-ajax.php' ),
			),
		);
	}

	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? \sanitize_key( $_GET['tab'] ) : 'general';
		$validation = $this->settings_service->validate_api_settings();

		/**
		 * Filters the settings page tabs: labels keyed by slug, in display order.
		 * A tab without a branch below is rendered on `pllat_settings_render_tab_{$tab}`.
		 *
		 * @param array<string,string> $tabs
		 */
		$tabs = \apply_filters(
			'pllat_settings_tabs',
			array(
				'general'  => \__( 'General', 'ai-translation-for-polylang' ),
				'advanced' => \__( 'Advanced', 'ai-translation-for-polylang' ),
				'support'  => \__( 'Support', 'ai-translation-for-polylang' ),
			),
		);
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'AI Translation Settings', 'ai-translation-for-polylang' ); ?></h1>

			<!-- Tab Navigation -->
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab => $label ) : ?>
					<a href="?page=pllat-settings&tab=<?php echo \esc_attr( $tab ); ?>"
					   class="nav-tab <?php echo $tab === $active_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo \esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'general' === $active_tab ) : ?>
				<!-- General Settings Tab -->
				<?php if ( ! $validation['valid'] ) : ?>
					<div class="notice notice-error">
						<p><strong><?php \esc_html_e( 'Configuration Issues:', 'ai-translation-for-polylang' ); ?></strong></p>
						<ul>
							<?php foreach ( $validation['errors'] as $error ) : ?>
								<li><?php echo \esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! \defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) : ?>
					<div class="notice notice-warning">
						<p>
							<strong><?php \esc_html_e( 'Performance Recommendation', 'ai-translation-for-polylang' ); ?></strong>
						</p>
						<p>
							<?php
							\printf(
								/* translators: %1$s: link to site health page, %2$s: link to setup guide */
								\esc_html__( 'Your site is using WordPress internal cron. For better translation performance, we recommend setting up server cron. %1$s or %2$s', 'ai-translation-for-polylang' ),
								'<a href="' . \esc_url( \admin_url( 'site-health.php' ) ) . '">' . \esc_html__( 'View Site Health', 'ai-translation-for-polylang' ) . '</a>',
								'<a href="https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/" target="_blank" rel="noopener">' . \esc_html__( 'Read Setup Guide', 'ai-translation-for-polylang' ) . '</a>',
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php
				$scheduler_health = $this->health_service->get_scheduler_health();
				if ( $scheduler_health['has_stuck_actions'] ) :
					$stuck_minutes = (int) \floor( $scheduler_health['oldest_pending_age'] / 60 );
				?>
					<div class="notice notice-error">
						<p>
							<strong><?php \esc_html_e( 'Action Scheduler Issue Detected', 'ai-translation-for-polylang' ); ?></strong>
						</p>
						<p>
							<?php
							\printf(
								/* translators: %d: number of minutes actions have been stuck */
								\esc_html__( 'Translation tasks have been pending for %d minutes. This usually means WP-Cron is not running properly.', 'ai-translation-for-polylang' ),
								$stuck_minutes
							);
							?>
						</p>
						<p>
							<a href="https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/" target="_blank" rel="noopener">
								<?php \esc_html_e( 'Learn how to set up server cron', 'ai-translation-for-polylang' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="options.php" id="pllat-settings-form">
					<?php
					\settings_fields( 'pllat_settings_group' );
					\do_settings_sections( 'pllat_settings' );
					\submit_button( \__( 'Save Settings', 'ai-translation-for-polylang' ) );
					?>
				</form>

			<?php elseif ( 'advanced' === $active_tab ) : ?>
				<!-- Advanced Settings Tab -->
				<form method="post" action="options.php" id="pllat-advanced-settings-form">
					<?php
					\settings_fields( 'pllat_advanced_settings_group' );
					\do_settings_sections( 'pllat_advanced_settings' );
					\submit_button( \__( 'Save Settings', 'ai-translation-for-polylang' ) );
					?>
				</form>

			<?php elseif ( 'support' === $active_tab ) : ?>
				<!-- Support Tab — delegate to dedicated renderer. -->
				<?php $this->support_tab_renderer->render(); ?>
			<?php elseif ( isset( $tabs[ $active_tab ] ) ) : ?>
				<!-- Tab added through pllat_settings_tabs — rendered by its module. -->
				<?php \do_action( "pllat_settings_render_tab_{$active_tab}" ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
