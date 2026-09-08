<?php
/**
 * Polylang Language Manager Implementation
 *
 * @package Polylang AI Automatic Translation
 */

namespace PLLAT\Core\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;

/**
 * Polylang-specific implementation of Language_Manager interface.
 */
class Polylang_Language_Manager implements Language_Manager {
    /**
     * Get the default language of the site.
     *
     * @return string The default language code.
     */
    public function get_default_language(): string {
        return \pll_default_language();
    }

    /**
     * Get all available language codes, optionally excluding the site default.
     *
     * @param bool $exclude_default Whether to exclude the default language.
     * @return array Array of language codes.
     */
    public function get_available_languages( bool $exclude_default = false ): array {
        $languages = \pll_languages_list( array( 'hide_empty' => false ) );

        if ( $exclude_default ) {
            $default_language = $this->get_default_language();
            $languages        = \array_filter( $languages, static fn( $lang ) => $lang !== $default_language );
        }

        return $languages;
    }

    /**
     * Get detailed language data for admin interfaces.
     *
     * @return array Array of language objects with slug, name, flag, and label.
     */
    public function get_languages_data(): array {
        if ( ! \function_exists( 'pll_languages_list' ) ) {
            return array();
        }

        // Get detailed language data from Polylang
        $languages = \pll_languages_list(
            array(
                'fields'     => '',  // Return full objects
                'hide_empty' => false,
            ),
        );

        if ( ! \is_array( $languages ) ) {
            return array();
        }

        $formatted_languages = array();
        foreach ( $languages as $language ) {
            if ( ! \is_object( $language ) || ! isset( $language->slug ) ) {
                continue;
            }

            $formatted_languages[] = array(
                'flag'   => $language->flag_url ?? '',
                'label'  => $language->name ?? $language->slug,
                'locale' => $language->locale ?? '',
                'name'   => $language->name ?? $language->slug,
                'slug'   => $language->slug,
            );
        }

        return $formatted_languages;
    }

    /**
     * Get the full language name from a language code.
     *
     * @param string $language_code The language code (e.g., 'en', 'hi', 'bn').
     * @return string The full language name (e.g., 'English', 'Hindi', 'Bengali').
     */
    public function get_language_name( string $language_code ): string {
        static $language_names = null;

        // Build cache on first call.
        if ( null === $language_names ) {
            $language_names = array();

            if ( \function_exists( 'pll_languages_list' ) ) {
                $languages = \pll_languages_list(
                    array(
                        'fields'     => '',  // Return full objects.
                        'hide_empty' => false,
                    ),
                );

                if ( \is_array( $languages ) ) {
                    foreach ( $languages as $language ) {
                        if ( ! \is_object( $language ) || ! isset( $language->slug, $language->name ) ) {
                            continue;
                        }

                        $language_names[ $language->slug ] = $language->name;
                    }
                }
            }
        }

        // Return the full language name, or the code itself if not found.
        return $language_names[ $language_code ] ?? $language_code;
    }

    /**
     * Get the post in a specific language.
     *
     * @param int    $post_id The post ID.
     * @param string $language The target language code.
     * @return int The post ID in the target language, or 0 if not found.
     */
    public function get_post_by_language( int $post_id, string $language ): int {
        $translated_post_id = \pll_get_post( $post_id, $language );
        return $translated_post_id ? (int) $translated_post_id : 0;
    }

    /**
     * Get the term in a specific language.
     *
     * @param int    $term_id The term ID.
     * @param string $language The target language code.
     * @return int The term ID in the target language, or 0 if not found.
     */
    public function get_term_by_language( int $term_id, string $language ): int {
        $translated_term_id = \pll_get_term( $term_id, $language );
        return $translated_term_id ? (int) $translated_term_id : 0;
    }

    /**
     * Get the language of a post.
     *
     * @param int $post_id The post ID.
     * @return string The language code of the post.
     */
    public function get_post_language( int $post_id ): string {
        return \pll_get_post_language( $post_id ) ?: '';
    }

    /**
     * Get the language of a term.
     *
     * @param int $term_id The term ID.
     * @return string The language code of the term.
     */
    public function get_term_language( int $term_id ): string {
        return \pll_get_term_language( $term_id ) ?: '';
    }

    /**
     * Get the term_language taxonomy term ID for a language slug.
     *
     * @param string $lang_slug Language slug (e.g., 'it', 'en').
     * @return int Term ID in the 'term_language' taxonomy, or 0 if not found.
     */
    /**
     * Set the language of a post.
     *
     * @param int    $post_id The post ID.
     * @param string $language The language code.
     * @return bool True on success, false on failure.
     */
    public function set_post_language( int $post_id, string $language ): bool {
        try {
            \pll_set_post_language( $post_id, $language );
            return true;
        } catch ( \Exception ) {
            return false;
        }
    }

    /**
     * Get the translation of a post.
     *
     * @param int    $post_id The post ID.
     * @param string $language The target language code.
     * @return int The post ID in the target language, or 0 if not found.
     */
    public function get_post_translation( int $post_id, string $language ): int {
        $translation_id = \pll_get_post( $post_id, $language );

        // Treat trashed translations as non-existent.
        if ( $translation_id && 'trash' === \get_post_status( $translation_id ) ) {
            return 0;
        }

        return $translation_id;
    }

    /**
     * Get all translations for a post.
     *
     * @param int $post_id The post ID.
     * @return array Array of language_code => post_id pairs.
     */
    public function get_post_translations( int $post_id ): array {
        return \pll_get_post_translations( $post_id ) ?: array();
    }

    /**
     * Get all translations for a term.
     *
     * @param int $term_id The term ID.
     * @return array Array of language_code => term_id pairs.
     */
    public function get_term_translations( int $term_id ): array {
        return \pll_get_term_translations( $term_id ) ?: array();
    }

    /**
     * Get the active Polylang post types.
     *
     * @return array The active Polylang post types.
     */
    public function get_active_post_types(): array {
        return Helpers::get_active_post_types();
    }

    /**
     * Get the active Polylang taxonomies.
     *
     * @return array The active Polylang taxonomies.
     */
    public function get_active_taxonomies(): array {
        return Helpers::get_active_taxonomies();
    }

    /**
     * Copy a post to a new language.
     *
     * @param int    $source_id   Source post ID.
     * @param string $target_lang Target language.
     * @return int The post ID in the target language, or 0 if not found.
     */
    public function copy_post( int $source_id, string $target_lang ): int {
        $is_pro = $this->is_pro();

        $result = $is_pro ? $this->copy_post_pro( $source_id, $target_lang ) : $this->copy_post_free(
            $source_id,
            $target_lang,
        );

        return $result;
    }

    /**
     * Whether Polylang Pro is active.
     *
     * Used internally by copy_post() to route between Pro / Free copy paths.
     * Private — the interface intentionally does not expose this since the
     * lean pipeline shouldn't branch on Pro vs Free outside this file. If
     * external code ever needs the check, expose via the interface.
     */
    private function is_pro(): bool {
        return \property_exists( \PLL(), 'sync_post_model' );
    }

    /**
     * Copy a term to a new language using Polylang free copy term method.
     *
     * @param int    $source_id   Source term ID.
     * @param string $target_lang The target language code.
     * @return int The term ID in the target language, or 0 if not found.
     */
    public function copy_term( int $source_id, string $target_lang ): int {
        $term = \get_term( $source_id );
        if ( ! $term instanceof \WP_Term ) {
            return 0;
        }

        // Check if the source term has a language.
        $source_language = \PLL()->model->term->get_language( $term->term_id );
        if ( ! $source_language || $source_language->slug === $target_lang ) {
            return 0;
        }

        // Check if translation already exists.
        $existing_translation = \PLL()->model->term->get_translation( $term->term_id, $target_lang );
        if ( $existing_translation ) {
            return (int) $existing_translation;
        }

        // Duplicate the parent if the parent translation doesn't exist yet.
        $tr_parent = 0;
        if ( $term->parent ) {
            $tr_parent = \PLL()->model->term->get_translation( $term->parent, $target_lang );
            if ( ! $tr_parent ) {
                $tr_parent = $this->copy_term( $term->parent, $target_lang );
            }
        }

        // Get the target language object for Polylang's term API.
        $target_language = \PLL()->model->get_language( $target_lang );
        if ( ! $target_language instanceof \PLL_Language ) {
            return 0;
        }

        // Build translation group linking source and new term.
        $translations                 = \PLL()->model->term->get_translations( $term->term_id );
        $translations[ $target_lang ] = 0; // Placeholder — insert() will replace with actual ID.

        // Provide an explicit slug so wp_insert_term takes the slug-provided
        // branch and skips its name-sibling early-return (taxonomy.php:2569).
        // Without this, any source term whose name already exists anywhere
        // in the taxonomy fails with WP_Error('term_exists') — even though
        // Polylang's pre_term_slug filter would have produced a unique slug.
        // The force_lang branch mirrors Polylang Pro's share-slug separator
        // ('___'), which save_term then post-processes to strip back out.
        // @phpstan-ignore-next-line — Options class is ArrayAccess but missing from polylang-stubs.
        $base_slug = \PLL()->model->options['force_lang']
            ? $term->slug . '___' . $target_lang
            : \sanitize_title( $term->name ) . '-' . $target_lang;

        // Use Polylang's term API which handles slug suffixing, language assignment,
        // and translation linking in all execution contexts (admin, cron, REST, CLI).
        $t = \PLL()->model->term->insert(
            \wp_slash( $term->name ),
            $term->taxonomy,
            $target_language,
            array(
                'description'  => \wp_slash( $term->description ),
                'parent'       => $tr_parent,
                'slug'         => $base_slug,
                'translations' => $translations,
            ),
        );

        if ( \is_wp_error( $t ) || ! \is_array( $t ) || ! isset( $t['term_id'] ) ) {
            return 0;
        }

        $tr_term_id = (int) $t['term_id'];

        // Notify integrations (Polylang sync copies term metas, ACF copies fields).
        // PLL()->model->term->insert() does not fire this action itself.
        \do_action( 'pll_duplicate_term', $term->term_id, $tr_term_id, $target_lang );

        return $tr_term_id;
    }

    /**
     * Suspend meta synchronization between translations.
     *
     * Disables Polylang's automatic meta sync hooks to prevent translated values
     * from being synced back to source post when programmatically updating content.
     *
     * @return void
     */
    public function suspend_meta_sync(): void {
        $pll = \PLL();

        // Suspend standard Polylang meta sync (works for Polylang Free + Pro).
        if ( $pll && $pll->sync && $pll->sync->post_metas ) {
            $pll->sync->post_metas->remove_all_meta_actions();

            // Also unhook save_object: a wp_update_post() on the target fires
            // pll_save_post, and save_object then copy()s ALL sync-listed metas
            // from the target onto the source directly (bypassing the live
            // hooks removed above) AND re-adds those live hooks when it
            // finishes, silently undoing this suspension.
            \remove_action( 'pll_save_post', array( $pll->sync->post_metas, 'save_object' ), 10 );
        }

        // Suspend Polylang Pro 3.7+ ACF integration sync.
        // This is a separate sync mechanism that bypasses the standard meta sync.
        if ( ! \class_exists( \WP_Syntex\Polylang_Pro\Integrations\ACF\Dispatcher::class ) ) {
            return;
        }

        \remove_filter(
            'acf/update_value',
            array( \WP_Syntex\Polylang_Pro\Integrations\ACF\Dispatcher::class, 'update' ),
            5,
        );
    }

    /**
     * Resume meta synchronization between translations.
     *
     * Re-enables Polylang's automatic meta sync hooks after programmatic updates.
     *
     * @return void
     */
    public function resume_meta_sync(): void {
        $pll = \PLL();

        // Resume standard Polylang meta sync.
        if ( $pll && $pll->sync && $pll->sync->post_metas ) {
            $pll->sync->post_metas->add_all_meta_actions();
            \add_action( 'pll_save_post', array( $pll->sync->post_metas, 'save_object' ), 10, 3 );
        }

        // Resume Polylang Pro 3.7+ ACF integration sync.
        if ( ! \class_exists( \WP_Syntex\Polylang_Pro\Integrations\ACF\Dispatcher::class ) ) {
            return;
        }

        \add_filter(
            'acf/update_value',
            array( \WP_Syntex\Polylang_Pro\Integrations\ACF\Dispatcher::class, 'update' ),
            5,
            3,
        );
    }

    /**
     * Copy a post to a new language using Polylang pro copy post method.
     *
     * @param int    $source_id   Source post ID.
     * @param string $language The target language code.
     * @return int The post ID in the target language, or 0 if not found.
     */
    private function copy_post_pro( int $source_id, string $language ): int {
        try {
            $post_id = \method_exists( \PLL()->sync_post_model, 'copy' ) ? \PLL()->sync_post_model->copy(
                $source_id,
                $language,
                false,
            ) : \PLL()->sync_post_model->copy_post( $source_id, $language, false );

            return $post_id;
        } catch ( \Error $e ) {
            // Log the error and fall back to creating a basic translation without copying meta.
            \do_action(
                'pllat_copy_post_error',
                $source_id,
                $language,
                $e->getMessage(),
            );

            // If translation was already created before the error (e.g. error in pll_created_sync_post hooks
            // like PLLWC copy_variations), return existing ID instead of falling back to copy_post_free().
            $existing = \PLL()->model->post->get( $source_id, \PLL()->model->get_language( $language ) );

            if ( $existing ) {
                return (int) $existing;
            }

            // Fall back to free method which creates a basic copy without Polylang Pro's meta sync.
            return $this->copy_post_free( $source_id, $language );
        }
    }

    /**
     * Copy a post to a new language using Polylang free copy post method.
     *
     * @param int    $source_id   Source post ID.
     * @param string $language The target language code.
     * @return int The post ID in the target language, or 0 if not found.
     */
    private function copy_post_free( int $source_id, string $language ): int {
        global $wpdb;

        $pll_language = \PLL()->model->get_language( $language );

        if ( ! $pll_language ) {
            \do_action(
                'pllat_copy_post_error',
                $source_id,
                $language,
                "Polylang language object not found for slug '{$language}'",
            );
            return 0;
        }

        // Get the translated post.
        $tr_id   = \PLL()->model->post->get( $source_id, $pll_language );
        $post    = \get_post( $source_id );
        $tr_post = $post;

        if ( ! $tr_post instanceof \WP_Post ) {
            \do_action( 'pllat_copy_post_error', $source_id, $language, "Source post #{$source_id} not found" );
            return 0;
        }

        // If the post is not translated, create a new post.
        if ( ! $tr_id ) {
            $tr_id = $this->create_translation_post( $source_id, $tr_post, $language );

            if ( 0 === $tr_id ) {
                return 0;
            }
        }

        $tr_post->ID          = $tr_id;
        $tr_post->post_parent = (int) \PLL()->model->post->get( $post->post_parent, $language );

        $columns = array(
            'post_author',
            'post_date',
            'post_date_gmt',
            'post_content',
            'post_title',
            'post_excerpt',
            'comment_status',
            'ping_status',
            'post_name',
            'post_modified',
            'post_modified_gmt',
            'post_parent',
            'menu_order',
            'post_mime_type',
        );

        if ( \is_sticky( $source_id ) ) {
            \stick_post( $tr_id );
        }

        // Update the post.
        $tr_post = \array_intersect_key( (array) $tr_post, \array_combine( $columns, $columns ) );
        $wpdb->update( $wpdb->posts, $tr_post, array( 'ID' => $tr_id ) );

        // Clean the post cache.
        \clean_post_cache( $tr_id );

        // Emit the same action Polylang Pro's sync_post_model fires after
        // creating a translated copy. Addons hook here to do their own
        // cloning work for the new pair:
        //   - PLLWC (polylang-wc/src/products.php:57) → copy_variations
        //     clones product variations, including attribute translation,
        //     stock handling, SKU uniqueness, and recursion guards.
        //   - PLL Bookings, Subscriptions, and other PLL Pro addons hook
        //     the same action for their own related-object copies.
        //
        // Without this dispatch, copy_post_free creates the parent post but
        // leaves all related objects (variations, etc.) on the source side,
        // resulting in silent broken translations for WC variable products
        // when running on Polylang Free + PLLWC.
        \do_action( 'pll_created_sync_post', $source_id, $tr_id, $language );

        return $tr_id;
    }

    /**
     * Create a new translation post and link it to the source via Polylang.
     *
     * @param int      $source_id Source post ID.
     * @param \WP_Post $tr_post   Source post object (will be cloned for translation).
     * @param string   $language  Target language slug.
     * @return int New post ID, or 0 on failure.
     */
    private function create_translation_post( int $source_id, \WP_Post $tr_post, string $language ): int {
        $tr_post->ID = 0;

        // Pre-empt Polylang's save_post (priority 10) which would otherwise
        // assign the new post to the plugin's default_lang ('en') when run in
        // a context without admin/frontend hints — i.e. our Action Scheduler
        // worker. The new post would then briefly carry the source language,
        // inflating the dashboard's source-total count (COUNT(*) … WHERE
        // ll.slug = 'en') and dropping the per-card progress percentage on
        // every poll while target posts are being copied. By setting the
        // target language at priority 5, Polylang's handler sees the language
        // already in place and skips set_default_language entirely.
        $preempt = static function ( int $post_id ) use ( $language ): void {
            if ( \PLL()->model->post->get_language( $post_id ) ) {
                return;
            }
            \PLL()->model->post->set_language( $post_id, $language );
        };
        \add_action( 'save_post', $preempt, 5, 1 );

        $tr_id = \wp_insert_post( \wp_slash( $tr_post->to_array() ), true );

        \remove_action( 'save_post', $preempt, 5 );

        if ( \is_wp_error( $tr_id ) ) {
            \do_action(
                'pllat_copy_post_error',
                $source_id,
                $language,
                "wp_insert_post failed for source #{$source_id} → {$language}: " . $tr_id->get_error_message(),
            );
            return 0;
        }

        \PLL()->model->post->set_language( $tr_id, $language );

        // Get the translations of the source post.
        $translations              = \PLL()->model->post->get_translations( $source_id );
        $translations[ $language ] = $tr_id;

        // Save the translations of the source post.
        \PLL()->model->post->save_translations( $source_id, $translations );

        // Copy the taxonomies of the source post.
        \PLL()->sync->taxonomies->copy( $source_id, $tr_id, $language );

        // Copy post meta - wrapped in try-catch to handle orphaned serialized objects.
        try {
            \PLL()->sync->post_metas->copy( $source_id, $tr_id, $language );
        } catch ( \Error $e ) {
            // Log but don't fail - translations will overwrite the needed fields anyway.
            \do_action(
                'pllat_copy_post_meta_error',
                $source_id,
                $tr_id,
                $language,
                $e->getMessage(),
            );
        }

        \do_action( 'pll_save_post', $source_id, \get_post( $source_id ), $translations );

        return $tr_id;
    }
}
