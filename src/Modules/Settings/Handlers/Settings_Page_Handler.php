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
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handles the settings page registration and display.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN | Handler::CTX_AJAX )]
class Settings_Page_Handler {
    private const ONBOARDING_DISMISSED_META = 'pllat_onboarding_notice_dismissed';
    private const ONBOARDING_DISMISS_ACTION = 'pllat_dismiss_onboarding_notice';
    private const ONBOARDING_SCREENS        = array(
        'dashboard',
        'plugins',
    );

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
     * Onboarding notice while no AI provider is configured, on the WordPress
     * dashboard, the Plugins screen and the plugin's own pages. Dismissed per
     * user through the notice's close button; gone anyway once a key is saved.
     *
     * @return void
     */
    #[Action( tag: 'admin_notices' )]
    public function show_onboarding_notice(): void {
        if ( ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = \get_current_screen();
        if ( null === $screen || ! \in_array( $screen->id, self::ONBOARDING_SCREENS, true ) ) {
            return;
        }

        if ( (bool) \get_user_meta( \get_current_user_id(), self::ONBOARDING_DISMISSED_META, true ) ) {
            return;
        }

        if ( (bool) \xwp_app( 'pllat' )->get( 'translator.configured' ) ) {
            return;
        }

        $settings_url = \admin_url( 'admin.php?page=pllat-settings&tab=general' );
        $nonce        = \wp_create_nonce( self::ONBOARDING_DISMISS_ACTION );
        $action       = \wp_json_encode( self::ONBOARDING_DISMISS_ACTION );
        $title        = \__( 'Set up AI translation', 'ai-translation-for-polylang' );
        ?>
        <div id="pllat-onboarding-notice"
            class="notice notice-info is-dismissible"
            data-nonce="<?php echo \esc_attr( $nonce ); ?>">
            <p><strong><?php echo \esc_html( $title ); ?></strong></p>
            <p>
                <?php
                \esc_html_e(
                    'Add your OpenAI API key to start translating posts, pages and terms into your Polylang languages. OpenAI bills API usage separately.',
                    'ai-translation-for-polylang',
                );
                ?>
            </p>
            <p>
                <a href="<?php echo \esc_url( $settings_url ); ?>" class="button button-primary">
                    <?php \esc_html_e( 'Add API key', 'ai-translation-for-polylang' ); ?>
                </a>
            </p>
        </div>
        <script>
            document.addEventListener( 'click', function ( event ) {
                var notice = event.target.closest( '#pllat-onboarding-notice' );
                if ( ! notice || ! event.target.closest( '.notice-dismiss' ) ) {
                    return;
                }
                var body = new URLSearchParams( { action: <?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded constant. ?>, nonce: notice.dataset.nonce } );
                window.fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
            } );
        </script>
        <?php
    }

    /**
     * Remember the onboarding notice dismissal for the current user.
     *
     * @return void
     */
    #[Action( tag: 'wp_ajax_pllat_dismiss_onboarding_notice' )]
    public function dismiss_onboarding_notice(): void {
        \check_ajax_referer( self::ONBOARDING_DISMISS_ACTION, 'nonce' );

        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( null, 403 );
        }

        \update_user_meta( \get_current_user_id(), self::ONBOARDING_DISMISSED_META, 1 );
        \wp_send_json_success();
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
