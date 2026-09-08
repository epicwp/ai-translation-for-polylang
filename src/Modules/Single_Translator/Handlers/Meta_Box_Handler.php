<?php
/**
 * Meta_Box_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\Asset_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Handler for registering meta boxes on post and term edit pages.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN )]
class Meta_Box_Handler {
    /**
     * Constructor.
     *
     * @param Language_Manager $language_manager The language manager.
     * @param Asset_Service    $asset_service    The asset service.
     */
    public function __construct(
        private Language_Manager $language_manager,
        private Asset_Service $asset_service,
    ) {
    }

    /**
     * Enqueue assets for post edit pages.
     *
     * @return void
     */
    #[Action( tag: 'admin_enqueue_scripts' )]
    public function enqueue_post_assets(): void {
        $screen = \get_current_screen();

        if ( ! $screen || 'post' !== $screen->base ) {
            return;
        }

        // Check if post type is active.
        if ( ! \in_array( $screen->post_type, Helpers::get_active_post_types(), true ) ) {
            return;
        }

        // Get post ID.
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 0 === $post_id ) {
            return;
        }

        // Enqueue assets.
        $this->enqueue_assets( 'post', $post_id, $screen->post_type );
    }

    /**
     * Register meta box for posts.
     *
     * @return void
     */
    #[Action( tag: 'add_meta_boxes' )]
    public function register_post_meta_box(): void {
        $post_types = Helpers::get_active_post_types();

        foreach ( $post_types as $post_type ) {
            \add_meta_box(
                'pllat-single-translator',
                \__( 'AI Translation', 'ai-translation-for-polylang' ),
                array( $this, 'render_post_meta_box' ),
                $post_type,
                'normal',
                'high',
            );
        }
    }

    /**
     * Render meta box for posts.
     *
     * @param \WP_Post $post The post object.
     * @return void
     */
    public function render_post_meta_box( \WP_Post $post ): void {
        // Render React app container.
        // Any-to-any translation: works from any language post.
        echo '<div id="pllat-single-translator-root" data-type="post" data-id="' . \esc_attr(
            (string) $post->ID,
        ) . '"></div>';
    }

    /**
     * Register term edit form hooks.
     * WordPress doesn't have a native meta box API for terms, so we use the {$taxonomy}_edit_form hook.
     *
     * @return void
     */
    #[Action( tag: 'admin_init' )]
    public function register_term_hooks(): void {
        $taxonomies = Helpers::get_available_taxonomies();

        foreach ( $taxonomies as $taxonomy ) {
            \add_action( "{$taxonomy}_edit_form", array( $this, 'render_term_meta_box' ), 20, 2 );
        }
    }

    /**
     * Enqueue assets for term edit pages.
     *
     * @return void
     */
    #[Action( tag: 'admin_enqueue_scripts' )]
    public function enqueue_term_assets(): void {
        $screen = \get_current_screen();

        if ( ! $screen || 'term' !== $screen->base ) {
            return;
        }

        // Check if taxonomy is active.
        $taxonomies = Helpers::get_available_taxonomies();
        if ( ! \in_array( $screen->taxonomy, $taxonomies, true ) ) {
            return;
        }

        // Get term ID from URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term_id = isset( $_GET['tag_ID'] ) ? (int) $_GET['tag_ID'] : 0;

        if ( 0 === $term_id ) {
            return;
        }

        // Enqueue scripts and styles.
        $this->enqueue_assets( 'term', $term_id, $screen->taxonomy );
    }

    /**
     * Render meta box for terms.
     *
     * @param \WP_Term $term     The term object.
     * @param string   $taxonomy The taxonomy name.
     * @return void
     */
    public function render_term_meta_box( \WP_Term $term, string $taxonomy ): void {
        ?>
        <div id="pllat-single-translator-term" class="pllat-mt-3 pllat-p-8 pllat-bg-white pllat-rounded-lg pllat-shadow-sm">
            <div class="pllat-mb-4">
                <span class="pllat-text-xl pllat-font-semibold">
                    <?php echo \esc_html( \__( 'AI Translation', 'ai-translation-for-polylang' ) ); ?>
                </span>
            </div>
            <div id="pllat-single-translator-root" data-type="term" data-id="
            <?php
            echo \esc_attr( (string) $term->term_id );
            ?>
            "></div>
        </div>
        <?php
    }

    /**
     * Enqueue assets for the meta box.
     *
     * @param string $type   Content type (post or term).
     * @param int    $id     Content ID.
     * @param string $entity Post type slug or taxonomy name.
     * @return void
     */
    private function enqueue_assets( string $type, int $id, string $entity ): void {
        $asset_file = PLLAT_PLUGIN_DIR . 'dist/admin/single-translator.asset.php';

        if ( ! \file_exists( $asset_file ) ) {
            return;
        }

        $asset = require $asset_file;

        // Enqueue script.
        \wp_enqueue_script(
            'pllat-single-translator',
            PLLAT_PLUGIN_URL . 'dist/admin/single-translator.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        // Enqueue WordPress component styles.
        \wp_enqueue_style( 'wp-components' );

        // Enqueue Tailwind CSS.
        \wp_enqueue_style(
            'pllat-admin',
            PLLAT_PLUGIN_URL . 'dist/admin/admin.css',
            array(),
            $asset['version'],
        );

        // Get current content language for any-to-any translation.
        $current_lang = 'post' === $type
            ? $this->language_manager->get_post_language( $id )
            : $this->language_manager->get_term_language( $id );

        // Localize script with initial data.
        \wp_localize_script(
            'pllat-single-translator',
            'pllatSingleTranslator',
            array(
                'apiUrl'                => \rest_url( 'pllat/v1/single-translator' ),
                'entity'                => $entity,
                'id'                    => $id,
                'language'              => array(
                    'currentLang' => $current_lang,
                    'defaultLang' => $this->language_manager->get_default_language(),
                    'languages'   => $this->get_language_list( $current_lang ),
                ),
                'nonce'                 => \wp_create_nonce( 'pllat-single-translator' ),
                'type'                  => $type,
                'translatorConfigured'  => \xwp_app( 'pllat' )->get( 'translator.configured' ),
            ),
        );

        // Also add global pllat object for shared utilities (flag display, icons, etc).
        \wp_localize_script(
            'pllat-single-translator',
            'pllat',
            array(
                'assets'                  => $this->asset_service->get_shared_assets(),
                'languages'               => $this->language_manager->get_languages_data(),
                'singleTranslatorEnabled' => \xwp_app( 'pllat' )->get( 'single_translator.enabled' ),
                'adminUrl'                => \admin_url(),
            ),
        );
    }

    /**
     * Get list of available languages for localization.
     * Returns full language data including flags to match dashboard format.
     *
     * @param string $exclude_lang Language to exclude (current content language for any-to-any translation).
     * @return array Language data with slug, name, and flag.
     */
    private function get_language_list( string $exclude_lang ): array {
        $languages_data = $this->language_manager->get_languages_data();
        $languages      = array();

        foreach ( $languages_data as $lang_data ) {
            // Handle both object and array format.
            $slug = \is_object( $lang_data ) ? $lang_data->slug : $lang_data['slug'];
            $name = \is_object( $lang_data ) ? $lang_data->name : $lang_data['name'];
            $flag = \is_object( $lang_data ) ? $lang_data->flag : ( $lang_data['flag'] ?? '' );

            // Skip current content language (can't translate to same language).
            if ( $slug === $exclude_lang ) {
                continue;
            }

            $languages[] = array(
                'flag' => $flag,
                'name' => $name,
                'slug' => $slug,
            );
        }

        return $languages;
    }
}
