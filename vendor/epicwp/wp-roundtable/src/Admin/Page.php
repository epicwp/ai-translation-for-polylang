<?php
declare(strict_types=1);

namespace EpicWP\Roundtable\Admin;

use EpicWP\Roundtable\Config;
use EpicWP\Roundtable\HubClient;

/** The "Community" admin page: registers the submenu, enqueues the bundle, renders the root container. */
final class Page {
    /** The admin page slug. */
    public const PAGE_SLUG = 'roundtable-community';
    /** The shared script/style handle. */
    public const HANDLE = 'roundtable';

    /**
     * Creates the page.
     *
     * @param Config    $config   The SDK configuration.
     * @param string    $menuSlug The parent menu slug the host already registered (submenu parent).
     * @param HubClient $hub      The hub client, used to fetch the remote display config (E11 §6).
     */
    public function __construct(
        private Config $config,
        private string $menuSlug,
        private HubClient $hub,
    ) {
    }

    /** Register the "Community" submenu. Call on the `admin_menu` action. */
    public function registerMenu(): void {
        \add_submenu_page(
            $this->menuSlug,
            \__( 'Community', 'wp-roundtable' ),
            \__( 'Community', 'wp-roundtable' ),
            'read',
            self::PAGE_SLUG,
            array( $this, 'render' ),
        );
    }

    /**
     * Enqueue the bundle + localize config, only on our page.
     *
     * @param string $hookSuffix The current admin screen's hook suffix.
     */
    public function enqueue( string $hookSuffix ): void {
        if ( ! \str_ends_with( $hookSuffix, '_page_' . self::PAGE_SLUG ) ) {
            return;
        }
        $display = $this->resolveDisplayConfig();
        \wp_enqueue_editor();
        \wp_enqueue_style( self::HANDLE, $this->assetUrl( 'roundtable.css' ), array(), $this->version() );
        \wp_enqueue_script( self::HANDLE, $this->assetUrl( 'roundtable.js' ), array(), $this->version(), true );
        \wp_localize_script(
            self::HANDLE,
            'RoundtableConfig',
            array(
                'agentName'      => $display['agentName'],
                'attribution'    => $this->config->attribution,
                'beta'           => $this->config->beta,
                'chatDisabled'   => $display['chatDisabled'],
                'chatEnabled'    => $this->config->enableChat,
                'initialMessage' => $display['initialMessage'],
                'nonce'          => \wp_create_nonce( 'wp_rest' ),
                'projectName'    => $display['projectName'],
                'restUrl'        => \rest_url( 'roundtable/v1' ),
            ),
        );
    }

    /**
     * Remove every other plugin's registered admin notices on the Community page only, so they
     * don't stack up under our own header. Call on the `in_admin_header` action — the canonical
     * point to strip `admin_notices`/`all_admin_notices` before they render, and late enough for
     * {@see \get_current_screen()} to be populated. Our own page renders no notices via those
     * hooks, so nothing of ours is lost; every other screen is left untouched.
     */
    public function suppressAdminNotices(): void {
        $screen = \get_current_screen();
        if ( ! \str_ends_with( (string) ( $screen->id ?? '' ), '_page_' . self::PAGE_SLUG ) ) {
            return;
        }
        \remove_all_actions( 'admin_notices' );
        \remove_all_actions( 'all_admin_notices' );
    }

    /** Render the admin page: a wrapper and the Preact root container. */
    public function render(): void {
        echo '<div class="wrap rt-community-wrap"><div id="roundtable-app" data-loading="'
            . \esc_attr__( 'Loading…', 'wp-roundtable' ) . '"></div></div>';
    }

    /**
     * Resolve the display config: the hub's `/project/config` values, falling back to the
     * local `Config` (and an empty initial message) on any hub/transport failure — the hub
     * is the primary source (E11 §5), local `Config` stays a safety-net fallback.
     *
     * @return array{agentName: string, projectName: string, initialMessage: string, chatDisabled: bool}
     */
    private function resolveDisplayConfig(): array {
        try {
            $remote = $this->hub->getProjectConfig();
            return array(
                'agentName'      => (string) ( $remote['agent_name'] ?? $this->config->agentName ),
                'chatDisabled'   => true === ( $remote['chat_disabled'] ?? false ),
                'initialMessage' => (string) ( $remote['initial_message'] ?? '' ),
                'projectName'    => (string) ( $remote['project_name'] ?? $this->config->projectName ),
            );
        } catch ( \EpicWP\Roundtable\HubException ) {
            return array(
                'agentName'      => $this->config->agentName,
                'chatDisabled'   => false,
                'initialMessage' => '',
                'projectName'    => $this->config->projectName,
            );
        }
    }

    /**
     * Resolve a built asset's URL from this file's location.
     *
     * @param string $file The dist filename (e.g. `roundtable.js`).
     *
     * @return string The public URL, or an empty string if it cannot be resolved.
     */
    private function assetUrl( string $file ): string {
        $path = \dirname( __DIR__, 2 ) . '/assets/dist/' . $file; // package-root/assets/dist/<file>.
        if ( \defined( 'WP_CONTENT_DIR' ) && \str_starts_with( $path, (string) \WP_CONTENT_DIR ) ) {
            return \content_url( \substr( $path, \strlen( (string) \WP_CONTENT_DIR ) ) );
        }
        return \plugins_url( 'assets/dist/' . $file, \dirname( __DIR__, 1 ) );
    }

    /**
     * A cache-busting version derived from the built file's mtime.
     *
     * @return string The version string (falls back to `'1'`).
     */
    private function version(): string {
        $js    = \dirname( __DIR__, 2 ) . '/assets/dist/roundtable.js';
        $mtime = \is_file( $js ) ? (string) \filemtime( $js ) : '';
        return '' !== $mtime ? $mtime : '1';
    }
}
