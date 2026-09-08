<?php
/**
 * Settings_Page_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Settings
 */

namespace PLLAT\Settings\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Debug_Logger_Service;
use PLLAT\Debug\Services\Error_Logger_Service;
use PLLAT\Settings\Services\Settings_Form;
use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Services\AI_Provider_Factory;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handles the settings page registration and display.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN | Handler::CTX_AJAX )]
class Settings_Page_Handler {
    /**
     * Constructor.
     *
     * @param Settings_Service         $settings_service         The settings service.
     * @param Settings_Form            $settings_form            The settings form renderer.
     * @param Debug_Logger_Service     $debug_logger_service     The debug logger service.
     * @param Error_Logger_Service     $error_logger_service     The error logger service.
     */
    public function __construct(
        protected Settings_Service $settings_service,
        protected Settings_Form $settings_form,
        protected Debug_Logger_Service $debug_logger_service,
        protected Error_Logger_Service $error_logger_service,
    ) {
        $this->settings_form->register_hooks();

        // Register AJAX handlers directly
        \add_action( 'wp_ajax_pllat_clear_logs', array( $this, 'handle_clear_logs_ajax' ) );
    }

    /**
     * Show admin notice when AI provider is not properly configured.
     *
     * @return void
     */
    #[Action( tag: 'admin_notices' )]
    public function show_ai_config_notice(): void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( (bool) \xwp_app( 'pllat' )->get( 'translator.configured' ) ) {
            return;
        }

        $errors       = AI_Provider_Factory::get_validation_errors( $this->settings_service );
        $settings_url = \admin_url( 'admin.php?page=pllat-settings&tab=general' );

        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php echo \esc_html__( 'Polylang AI Translation', 'ai-translation-for-polylang' ); ?>:</strong>
                <?php echo \esc_html__( 'AI provider is not configured correctly. Translations are disabled.', 'ai-translation-for-polylang' ); ?>
            </p>
            <?php if ( $errors !== array() ) : ?>
                <ul style="list-style: disc; margin-left: 1.5em;">
                    <?php foreach ( $errors as $error ) : ?>
                        <li><?php echo \esc_html( $error ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p>
                <a href="<?php echo \esc_url( $settings_url ); ?>">
                    <?php echo \esc_html__( 'Go to AI Settings', 'ai-translation-for-polylang' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Register the settings page in the admin menu.
     *
     * @return void
     */
    #[Action( tag: 'admin_menu', priority: 11 )]
    public function register_admin_menu(): void {
        \add_submenu_page(
            'mlang',
            \__( 'AI Settings', 'ai-translation-for-polylang' ),
            \__( 'AI Settings', 'ai-translation-for-polylang' ),
            'manage_options',
            'pllat-settings',
            array( $this, 'render_settings_page' ),
        );
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render_settings_page(): void {
        // Check user capabilities.
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die(
                \esc_html__(
                    'You do not have sufficient permissions to access this page.',
                    'ai-translation-for-polylang',
                ),
            );
        }

        // Render the form.
        $this->settings_form->render();
    }

    /**
     * Handle download logs action.
     *
     * @return void
     */
    #[Action( tag: 'admin_init' )]
    public function handle_download_logs_action(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['action'] ) || 'pllat_download_logs' !== $_GET['action'] ) {
            return;
        }

        // Check permissions
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( \esc_html__( 'You do not have permission to access this page.', 'ai-translation-for-polylang' ) );
        }

        // Verify nonce
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['_wpnonce'] ) || ! \wp_verify_nonce( \sanitize_key( $_GET['_wpnonce'] ), 'pllat_support_actions' ) ) {
            \wp_die( \esc_html__( 'Invalid security token.', 'ai-translation-for-polylang' ) );
        }

        // Get selected date and log type if provided
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected_date = isset( $_GET['log_date'] ) ? \sanitize_text_field( $_GET['log_date'] ) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $log_type = isset( $_GET['log_type'] ) ? \sanitize_key( $_GET['log_type'] ) : 'debug';

        // Get the appropriate log file based on type.
        if ( 'error' === $log_type ) {
            $log_file      = $this->error_logger_service->get_log_file_path( $selected_date );
            $filename_base = 'pllat-error';
        } else {
            $log_file      = $this->debug_logger_service->get_log_file_path( $selected_date );
            $filename_base = 'pllat-debug';
        }

        if ( ! \file_exists( $log_file ) ) {
            \wp_die( \esc_html__( 'Log file not found.', 'ai-translation-for-polylang' ) );
        }

        // Use the date from the filename for download
        $filename_date = $selected_date ? $selected_date : \gmdate( 'Y-m-d' );

        // Set headers for file download
        \header( 'Content-Type: text/plain' );
        \header( 'Content-Disposition: attachment; filename="' . $filename_base . '-' . $filename_date . '.log"' );
        \header( 'Content-Length: ' . \filesize( $log_file ) );
        \header( 'Pragma: no-cache' );
        \header( 'Expires: 0' );

        // Output file content
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- debug logs grow to tens of MB before auto-disable; streamed to the client instead of loaded into memory.
        \readfile( $log_file );
        exit;
    }


    /**
     * Handle clear logs AJAX action.
     *
     * @return void
     */
    public function handle_clear_logs_ajax(): void {
        try {
            // Verify nonce
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( ! isset( $_POST['nonce'] ) || ! \wp_verify_nonce( \sanitize_key( $_POST['nonce'] ), 'pllat_support_actions' ) ) {
                \wp_send_json_error(
                    array(
                        'message' => \__( 'Invalid security token.', 'ai-translation-for-polylang' ),
                    ),
                );
                return;
            }

            // Check permissions
            if ( ! \current_user_can( 'manage_options' ) ) {
                \wp_send_json_error(
                    array(
                        'message' => \__( 'You do not have permission to perform this action.', 'ai-translation-for-polylang' ),
                    ),
                );
                return;
            }

            // Clear the log
            $result = $this->debug_logger_service->clear_log();

            if ( $result ) {
                \wp_send_json_success(
                    array(
                        'message' => \__( 'Debug logs cleared successfully.', 'ai-translation-for-polylang' ),
                    ),
                );
            } else {
                \wp_send_json_error(
                    array(
                        'message' => \__( 'Failed to clear debug logs.', 'ai-translation-for-polylang' ),
                    ),
                );
            }
        } catch ( \Exception $e ) {
            \wp_send_json_error(
                array(
                    'message' => \sprintf(
                        /* translators: %s: error message */
                        \__( 'An error occurred: %s', 'ai-translation-for-polylang' ),
                        $e->getMessage()
                    ),
                ),
            );
        }
    }
}
