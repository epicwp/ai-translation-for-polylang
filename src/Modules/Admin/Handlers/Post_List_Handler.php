<?php
/**
 * Post_List_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Admin
 */

declare(strict_types=1);

namespace PLLAT\Admin\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Admin\Services\Post_List_Service;
use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Handler for WordPress admin post list integration.
 * Adds translation status column and filter dropdown.
 */
#[Handler( tag: 'init', priority: 11, context: Handler::CTX_ADMIN )]
class Post_List_Handler {
    /**
     * Column ID for the translation status column.
     */
    private const COLUMN_ID = 'pllat_translation_status';

    /**
     * Filter query parameter name.
     */
    private const FILTER_PARAM = 'pllat_translation_filter';

    /**
     * Constructor.
     *
     * @param Post_List_Service $post_list_service The post list service.
     * @param Language_Manager  $language_manager  The language manager.
     */
    public function __construct(
        private Post_List_Service $post_list_service,
        private Language_Manager $language_manager,
    ) {
    }

    /**
     * Add translation status column to post list.
     *
     * @param array<string, string> $columns The existing columns.
     * @return array<string, string> Modified columns.
     */
    #[Filter( tag: 'manage_posts_columns', priority: 10 )]
    #[Filter( tag: 'manage_pages_columns', priority: 10 )]
    public function add_columns( array $columns ): array {
        $screen = \get_current_screen();

        if ( ! $screen || ! \in_array( $screen->post_type, Helpers::get_active_post_types(), true ) ) {
            return $columns;
        }

        // Insert column after title.
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( 'title' !== $key ) {
                continue;
            }

            $new_columns[ self::COLUMN_ID ] = \__( 'AI', 'ai-translation-for-polylang' );
        }

        return $new_columns;
    }

    /**
     * Render the translation status column content.
     *
     * @param string $column  The column ID.
     * @param int    $post_id The post ID.
     * @return void
     */
    #[Action( tag: 'manage_posts_custom_column', priority: 10, args: 2 )]
    #[Action( tag: 'manage_pages_custom_column', priority: 10, args: 2 )]
    public function render_column( string $column, int $post_id ): void {
        if ( self::COLUMN_ID !== $column ) {
            return;
        }

        $post_lang        = $this->language_manager->get_post_language( $post_id );
        $target_languages = $this->get_target_languages( $post_lang );
        $statuses         = $this->post_list_service->get_translation_status( $post_id );

        if ( 0 === \count( $target_languages ) ) {
            echo '<span class="pllat-status-na">—</span>';
            return;
        }

        // Calculate summary.
        $completed   = 0;
        $failed      = 0;
        $in_progress = 0;
        $pending     = 0;
        $total       = \count( $target_languages );

        foreach ( $target_languages as $lang ) {
            $status = $statuses[ $lang ]['status'] ?? 'none';
            match ( $status ) {
                'completed'   => $completed++,
                'failed'      => $failed++,
                'in_progress' => $in_progress++,
                'pending'     => $pending++,
                default       => null,
            };
        }

        // Determine overall status icon.
        $icon  = $this->get_status_icon( $completed, $failed, $in_progress, $pending, $total );
        $class = $this->get_status_class( $completed, $failed, $in_progress, $pending, $total );

        // Build summary tooltip.
        $tooltip = $this->build_summary_tooltip( $completed, $failed, $in_progress, $pending, $total );

        \printf(
            '<span class="pllat-tooltip pllat-status %s" data-tooltip="%s">%s</span>',
            \esc_attr( $class ),
            \esc_attr( $tooltip ),
            \esc_html( "{$completed}/{$total} {$icon}" ),
        );
    }

    /**
     * Add filter dropdown to post list.
     *
     * @param string $post_type The current post type.
     * @param string $which     The location of the extra table nav (top or bottom).
     * @return void
     */
    #[Action( tag: 'restrict_manage_posts', priority: 10, args: 2 )]
    public function add_filter_dropdown( string $post_type, string $which ): void {
        // Only show on top filter bar.
        if ( 'top' !== $which ) {
            return;
        }

        // Only for translatable post types.
        if ( ! \in_array( $post_type, Helpers::get_active_post_types(), true ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading filter value only.
        $current = isset( $_GET[ self::FILTER_PARAM ] ) ? \sanitize_text_field(
            \wp_unslash( $_GET[ self::FILTER_PARAM ] ),
        ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        ?>
        <select name="<?php echo \esc_attr( self::FILTER_PARAM ); ?>">
            <option value="">
            <?php
            \esc_html_e( 'All Translation Status', 'ai-translation-for-polylang' );
            ?>
                            </option>
            <option value="missing" <?php \selected( $current, 'missing' ); ?>>
            <?php
            \esc_html_e( 'Missing Translations', 'ai-translation-for-polylang' );
            ?>
                                    </option>
            <option value="all_translated" <?php \selected( $current, 'all_translated' ); ?>>
            <?php
            \esc_html_e( 'All Translated', 'ai-translation-for-polylang' );
            ?>
                                            </option>
            <option value="has_errors" <?php \selected( $current, 'has_errors' ); ?>>
            <?php
            \esc_html_e( 'Has Errors', 'ai-translation-for-polylang' );
            ?>
                                        </option>
            <option value="pending" <?php \selected( $current, 'pending' ); ?>>
            <?php
            \esc_html_e( 'Translation Pending', 'ai-translation-for-polylang' );
            ?>
                                    </option>
        </select>
        <?php
    }

    /**
     * Filter the post query based on translation status.
     *
     * @param \WP_Query $query The WordPress query.
     * @return void
     */
    #[Action( tag: 'pre_get_posts', priority: 10 )]
    public function filter_query( \WP_Query $query ): void {
        if ( ! $this->should_filter_query( $query ) ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        $post_ids  = $this->post_list_service->get_filtered_post_ids(
            $this->get_filter_value(),
            \is_string( $post_type ) ? $post_type : '',
        );

        if ( null === $post_ids ) {
            return;
        }

        // Force empty result if no matches, otherwise filter by post IDs.
        $query->set( 'post__in', 0 === \count( $post_ids ) ? array( 0 ) : $post_ids );
    }

    /**
     * Preload translation status for posts in the current list.
     * Called via the_posts filter to batch load status data.
     *
     * @param array<\WP_Post> $posts The posts being displayed.
     * @return array<\WP_Post> The posts (unchanged).
     */
    #[Filter( tag: 'the_posts', priority: 10 )]
    public function preload_status( array $posts ): array {
        if ( ! \function_exists( 'get_current_screen' ) ) {
            return $posts;
        }

        $screen = \get_current_screen();

        if ( ! $screen || 'edit' !== $screen->base ) {
            return $posts;
        }

        if ( ! \in_array( $screen->post_type, Helpers::get_active_post_types(), true ) ) {
            return $posts;
        }

        if ( 0 === \count( $posts ) ) {
            return $posts;
        }

        $post_ids = \wp_list_pluck( $posts, 'ID' );
        $this->post_list_service->preload_status( $post_ids );

        return $posts;
    }

    /**
     * Enqueue admin assets for post list pages.
     *
     * @return void
     */
    #[Action( tag: 'admin_enqueue_scripts', priority: 10, context: Action::CTX_ADMIN )]
    public function enqueue_assets(): void {
        $screen = \get_current_screen();

        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        if ( ! \in_array( $screen->post_type, Helpers::get_active_post_types(), true ) ) {
            return;
        }

        \wp_enqueue_style(
            'pllat-admin',
            PLLAT_PLUGIN_URL . 'dist/admin/admin.css',
            array(),
            PLLAT_PLUGIN_VERSION,
        );
    }

    /**
     * Check if the query should be filtered.
     *
     * @param \WP_Query $query The WordPress query.
     * @return bool True if the query should be filtered.
     */
    private function should_filter_query( \WP_Query $query ): bool {
        if ( ! \is_admin() || ! $query->is_main_query() ) {
            return false;
        }

        $filter_value = $this->get_filter_value();
        if ( '' === $filter_value ) {
            return false;
        }

        $post_type = $query->get( 'post_type' );

        return \is_string( $post_type ) && \in_array( $post_type, Helpers::get_active_post_types(), true );
    }

    /**
     * Get the translation filter value from the request.
     *
     * @return string The filter value.
     */
    private function get_filter_value(): string {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading filter value only.
        $value = isset( $_GET[ self::FILTER_PARAM ] )
            ? \sanitize_text_field( \wp_unslash( $_GET[ self::FILTER_PARAM ] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $value;
    }

    /**
     * Get target languages excluding the post's language.
     *
     * @param string $exclude_lang The language to exclude.
     * @return array<string> Array of target language codes.
     */
    private function get_target_languages( string $exclude_lang ): array {
        $all_languages = $this->language_manager->get_available_languages( false );

        return \array_values(
            \array_filter(
                $all_languages,
                static fn( string $lang ) => $lang !== $exclude_lang,
            ),
        );
    }

    /**
     * Get the status icon based on translation counts.
     *
     * @param int $completed   Number of completed translations.
     * @param int $failed      Number of failed translations.
     * @param int $in_progress Number of in-progress translations.
     * @param int $pending     Number of pending translations.
     * @param int $total       Total number of target languages.
     * @return string The status icon.
     */
    private function get_status_icon( int $completed, int $failed, int $in_progress, int $pending, int $total ): string {
        if ( $failed > 0 ) {
            return '❌';
        }

        if ( $in_progress > 0 ) {
            return '⏳';
        }

        if ( $pending > 0 ) {
            return '🕐';
        }

        if ( $completed === $total ) {
            return '✅';
        }

        return '➖';
    }

    /**
     * Get the CSS class for the status display.
     *
     * @param int $completed   Number of completed translations.
     * @param int $failed      Number of failed translations.
     * @param int $in_progress Number of in-progress translations.
     * @param int $pending     Number of pending translations.
     * @param int $total       Total number of target languages.
     * @return string The CSS class.
     */
    private function get_status_class( int $completed, int $failed, int $in_progress, int $pending, int $total ): string {
        if ( $failed > 0 ) {
            return 'pllat-status-error';
        }

        if ( $in_progress > 0 ) {
            return 'pllat-status-progress';
        }

        if ( $pending > 0 ) {
            return 'pllat-status-pending';
        }

        if ( $completed === $total ) {
            return 'pllat-status-complete';
        }

        if ( $completed > 0 ) {
            return 'pllat-status-partial';
        }

        return 'pllat-status-none';
    }

    /**
     * Build summary tooltip showing status counts.
     *
     * @param int $completed   Number of completed translations.
     * @param int $failed      Number of failed translations.
     * @param int $in_progress Number of in-progress translations.
     * @param int $pending     Number of pending translations.
     * @param int $total       Total number of target languages.
     * @return string The tooltip content.
     */
    private function build_summary_tooltip( int $completed, int $failed, int $in_progress, int $pending, int $total ): string {
        $parts       = array();
        $not_started = $total - $completed - $failed - $in_progress - $pending;

        if ( $completed > 0 ) {
            $parts[] = \sprintf( '%d %s', $completed, \__( 'translated', 'ai-translation-for-polylang' ) );
        }

        if ( $in_progress > 0 ) {
            $parts[] = \sprintf( '%d %s', $in_progress, \__( 'in progress', 'ai-translation-for-polylang' ) );
        }

        if ( $pending > 0 ) {
            $parts[] = \sprintf( '%d %s', $pending, \__( 'pending', 'ai-translation-for-polylang' ) );
        }

        if ( $failed > 0 ) {
            $parts[] = \sprintf( '%d %s', $failed, \__( 'failed', 'ai-translation-for-polylang' ) );
        }

        if ( $not_started > 0 ) {
            $parts[] = \sprintf( '%d %s', $not_started, \__( 'not started', 'ai-translation-for-polylang' ) );
        }

        return \implode( ' | ', $parts );
    }
}
