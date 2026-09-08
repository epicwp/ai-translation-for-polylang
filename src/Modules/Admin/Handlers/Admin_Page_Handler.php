<?php
namespace PLLAT\Admin\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Admin\Services\Admin_Data_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handles display and functionality of the bulk translation page in the admin area.
 */
#[Handler( tag: 'admin_menu', priority: 10, context: Handler::CTX_ADMIN )]
class Admin_Page_Handler {
    /**
     * Constructor.
     *
     * @param Admin_Data_Service $data_service The admin data service.
     */
    public function __construct(
        protected Admin_Data_Service $data_service,
    ) {
    }
    /**
     * Register the translation dashboard page in the admin menu.
     *
     * @param  string $base_path The base path for the plugin.
     */
    #[Action(
        tag: 'admin_menu',
        priority: 11,
        context: Action::CTX_ADMIN,
        invoke: Action::INV_PROXIED,
        args: 0,
        params: array( 'app.path' ),
    )]
    public function add_menu( string $base_path ): void {
        if ( ! \xwp_app( 'pllat' )->get( 'translator.configured' ) ) {
            return;
        }

        \add_submenu_page(
            parent_slug:'mlang',
            page_title: \__( 'AI Translation', 'ai-translation-for-polylang' ),
            menu_title: \__( 'AI Translation', 'ai-translation-for-polylang' ),
            capability:'manage_options',
            menu_slug: 'polylang-ai-translate-bulk',
            callback: array( $this, 'render_page' ),
        );
    }

    /**
     * Render the translation dashboard page.
     */
    public function render_page(): void {
        // Enqueue dashboard assets.
        $this->enqueue_dashboard_assets();

        \do_action( 'pllat_before_translation_dashboard' );

        // Render page container with standard WP heading so notices appear above content.
        echo '<div class="wrap">';
        echo '<h1 class="screen-reader-text">' . \esc_html__( 'AI Translation', 'ai-translation-for-polylang' ) . '</h1>';
        echo '<div class="pllat-p-4">';
        echo '<hr class="wp-header-end">';
        echo '<div id="pllat_translation_dashboard"></div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Show admin notice when there are recent translation failures.
     * Only displayed on PLLAT admin pages.
     */
    #[Action(
        tag: 'admin_notices',
        priority: 10,
        context: Action::CTX_ADMIN,
    )]
    public function show_failure_notice(): void {
        $screen = \get_current_screen();

        if ( null === $screen || 'languages_page_polylang-ai-translate-bulk' !== $screen->id ) {
            return;
        }

        $failed_count = $this->get_recent_failure_count();

        if ( 0 === $failed_count ) {
            return;
        }

        \printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            \esc_html(
                \sprintf(
                    /* translators: %d: number of failed jobs */
                    \_n(
                        '%d translation job failed in the last 24 hours. Check the Activity panel for details.',
                        '%d translation jobs failed in the last 24 hours. Check the Activity panel for details.',
                        $failed_count,
                        'ai-translation-for-polylang',
                    ),
                    $failed_count,
                ),
            ),
        );
    }

    /**
     * Add error count badge to PLLAT admin menu item.
     *
     * @param string $base_path The base path for the plugin.
     */
    #[Action(
        tag: 'admin_menu',
        priority: 99,
        context: Action::CTX_ADMIN,
        invoke: Action::INV_PROXIED,
        args: 0,
        params: array( 'app.path' ),
    )]
    public function add_failure_badge( string $base_path ): void {
        global $submenu;

        if ( ! isset( $submenu['mlang'] ) ) {
            return;
        }

        $failed_count = $this->get_recent_failure_count();

        if ( 0 === $failed_count ) {
            return;
        }

        foreach ( $submenu['mlang'] as &$item ) {
            if ( 'polylang-ai-translate-bulk' !== ( $item[2] ?? '' ) ) {
                continue;
            }

            $item[0] .= \sprintf(
                ' <span class="awaiting-mod">%d</span>',
                $failed_count,
            );
            break;
        }
    }

    /**
     * Get count of failed jobs in the last 24 hours, cached for 5 minutes.
     *
     * @return int Number of recently failed jobs.
     */
    private function get_recent_failure_count(): int {
        $cached = \get_transient( 'pllat_recent_failure_count' );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';
        $cutoff       = \time() - DAY_IN_SECONDS;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name with prefix.
                "SELECT COUNT(*) FROM {$claims_table} WHERE status = %s AND created_at > %d",
                'failed',
                $cutoff,
            ),
        );

        \set_transient( 'pllat_recent_failure_count', $count, 5 * MINUTE_IN_SECONDS );

        return $count;
    }

    /**
     * Enqueue assets for translation dashboard.
     */
    private function enqueue_dashboard_assets(): void {
        $version = PLLAT_PLUGIN_VERSION;

        $asset_file = \file_exists(
            PLLAT_PLUGIN_DIR . 'dist/admin/translation-dashboard.asset.php',
        ) ? include PLLAT_PLUGIN_DIR . 'dist/admin/translation-dashboard.asset.php' : array(
            'dependencies' => array( 'wp-element', 'wp-api-fetch' ),
            'version'      => $version,
        );
        // Enqueue the dashboard script.
        \wp_enqueue_script(
            'pllat-translation-dashboard',
            PLLAT_PLUGIN_URL . 'dist/admin/translation-dashboard.js',
            $asset_file['dependencies'],
            $asset_file['version'],
            true,
        );

        // Enqueue WordPress components styles.
        \wp_enqueue_style( 'wp-components' );

        // Enqueue styles with higher priority to override WordPress admin styles.
        \wp_enqueue_style( 'pllat-admin', PLLAT_PLUGIN_URL . 'dist/admin/admin.css', array(), $version );

        // Override WordPress admin body background on this page.
        \wp_add_inline_style(
            'pllat-admin',
            'body.languages_page_polylang-ai-translate-bulk { background: #f9fafb !important; }',
        );

        // Localize script with admin data.
        \wp_localize_script(
            'pllat-translation-dashboard',
            'pllat',
            $this->data_service->get_all_data(),
        );
    }
}
