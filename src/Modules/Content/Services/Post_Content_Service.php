<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Services\Traits\Reference_Parsing_Trait;
use PLLAT\Translator\Models\Translatables\Translatable_Post;

/**
 * Handles updates for post content.
 */
class Post_Content_Service {
    use Reference_Parsing_Trait;

    public function __construct(
        private Language_Manager $language_manager,
    ) {
    }

    /**
     * Update a post field based on a reference key.
     * Delegates to core/meta/custom handlers.
     *
     * @param int    $post_id     Post ID.
     * @param string $reference   Reference key (core|_meta|*_custom_data*).
     * @param string $translation Translated value.
     * @return mixed Result from update operation (post ID, meta ID, or null on failure).
     */
    public function update_post_field( int $post_id, string $reference, string $translation ) {
        $reference_info = $this->parse_reference( $reference );

        /**
         * Fires before updating a post field with translated content.
         *
         * @param int    $post_id     Post ID being updated.
         * @param string $reference   Full reference key (e.g., "core|post_title", "_meta|_yoast_wpseo_title").
         * @param string $translation Translated value.
         * @param string $field_type  Type of field: 'core', 'meta', 'custom_data', or 'unknown'.
         * @param string $field_name  Field name (e.g., "post_title", "_yoast_wpseo_title").
         */
        \do_action(
            'pllat_before_update_post_field',
            $post_id,
            $reference,
            $translation,
            $reference_info['type'],
            $reference_info['field'],
        );

        $result = null;

        switch ( $reference_info['type'] ) {
            case 'core':
                $result = $this->update_core_post_field( $post_id, $reference_info['field'], $translation );
                break;

            case 'meta':
                /**
                 * Filter the translation value before updating post meta.
                 *
                 * This allows integrations to normalize or transform the value
                 * before it's saved (e.g., encoding adjustments for Elementor).
                 *
                 * @param string $translation The translated value.
                 * @param int    $post_id     Post ID.
                 * @param string $meta_key    Meta key being updated.
                 */
                $translation = \apply_filters(
                    'pllat_before_update_post_meta',
                    $translation,
                    $post_id,
                    $reference_info['field'],
                );

                // Update meta via Translatable instance (allows integrations to hook via pllat_update_post_meta filter).
                // Unserialize so WordPress can properly handle array storage.
                // Translations are stored serialized in task table (TEXT column), but update_post_meta()
                // expects the actual value type - it handles serialization internally.
                // This matches ACF handler pattern (ACF_Post_Handler.php:104).
                $translatable = Translatable_Post::get_instance( $post_id );
                $result       = $translatable->update_meta( $reference_info['field'], \maybe_unserialize( $translation ) );

                /**
                 * Fires after a post meta field has been updated with a translation.
                 *
                 * This allows integrations (like Elementor) to clear caches or perform
                 * additional processing after specific meta fields are updated.
                 *
                 * @param int    $post_id    Post ID.
                 * @param string $meta_key   Meta key that was updated.
                 * @param string $translation New meta value (translated).
                 */
                \do_action( 'pllat_after_update_post_meta', $post_id, $reference_info['field'], $translation );
                break;

            case 'custom_data':
                \do_action(
                    'pllat_update_custom_post_field',
                    $post_id,
                    $reference_info['field'],
                    $translation,
                );
                break;

            default:
                \do_action( 'pllat_update_unknown_post_field', $post_id, $reference, $translation );
        }

        /**
         * Fires after updating a post field with translated content.
         *
         * @param int    $post_id     Post ID that was updated.
         * @param string $reference   Full reference key.
         * @param string $translation Translated value.
         * @param string $field_type  Type of field: 'core', 'meta', 'custom_data', or 'unknown'.
         * @param string $field_name  Field name.
         * @param mixed  $result      Result from update operation (post ID, meta ID, or null).
         */
        \do_action(
            'pllat_after_update_post_field',
            $post_id,
            $reference,
            $translation,
            $reference_info['type'],
            $reference_info['field'],
            $result,
        );

        return $result;
    }

    /**
     * Get the value of a post field by reference.
     * Mirror of update_post_field() but for reading.
     *
     * @param int    $post_id   The post ID.
     * @param string $reference The field reference.
     * @return string|null The field value, or null if not found.
     * @throws \Exception If post not found.
     */
    public function get_post_field( int $post_id, string $reference ): ?string {
        $post = \get_post( $post_id );

        if ( ! $post ) {
            throw new \Exception( \sprintf( 'Post %d no longer exists', (int) $post_id ) );
        }

        $parsed = $this->parse_reference( $reference );

        // Core fields.
        if ( 'core' === $parsed['type'] ) {
            $field = $parsed['field'];
            if ( \property_exists( $post, $field ) ) {
                return (string) $post->$field;
            }
        }

        // Meta fields.
        if ( 'meta' === $parsed['type'] ) {
            $translatable = Translatable_Post::get_instance( $post_id );
            $value        = $translatable->get_meta( $parsed['field'], true );
            if ( ! $value ) {
                return null;
            }
            return \maybe_serialize( $value );
        }

        // Custom data.
        if ( 'custom_data' === $parsed['type'] ) {
            $translatable = Translatable_Post::get_instance( $post_id );
            $value        = $translatable->get_meta( $parsed['field'], true );
            if ( ! $value ) {
                return null;
            }
            return \maybe_serialize( $value );
        }

        /**
         * Allow integrations to provide custom field values.
         *
         * @param string|null $value     The resolved value.
         * @param int         $post_id   Post ID.
         * @param string      $reference Field reference.
         * @param array       $parsed    Parsed reference (type, field).
         */
        return \apply_filters( 'pllat_get_post_field', null, $post_id, $reference, $parsed );
    }

    /**
     * Update one of the supported core post fields.
     *
     * @param int    $post_id     Post ID.
     * @param string $field       Core field name.
     * @param string $translation Translated value.
     * @return int|null Post ID on success, null on failure.
     */
    public function update_core_post_field( int $post_id, string $field, string $translation ): ?int {
        $update_data = array( 'ID' => $post_id );

        switch ( $field ) {
            case 'post_title':
                $update_data['post_title'] = $translation;
                break;
            case 'post_content':
                $update_data['post_content'] = $translation;
                break;
            case 'post_excerpt':
                $update_data['post_excerpt'] = $translation;
                break;
            case 'post_name':
                $update_data['post_name'] = \sanitize_title( $translation );
                break;
            default:
                \do_action( 'pllat_update_unknown_core_post_field', $post_id, $field, $translation );
                return null;
        }

        if ( \count( $update_data ) <= 1 ) {
            return null;
        }

        $result = \wp_update_post( \wp_slash( $update_data ) );

        // wp_update_post returns 0 on failure, post ID on success.
        return $result > 0 ? $result : null;
    }

    /**
     * Resolve or infer a target post ID for the given language.
     * Falls back to source when a translation does not exist yet.
     *
     * @param int    $source_id   Source post ID.
     * @param string $target_lang Target language.
     * @return int Target post ID (or source ID fallback).
     */
    public function resolve_target_id( int $source_id, string $target_lang ): int {
        $existing_target = $this->language_manager->get_post_translation( $source_id, $target_lang );

        if ( $existing_target ) {
            return $existing_target;
        }

        $new_target = (int) $this->language_manager->copy_post( $source_id, $target_lang );

        if ( $new_target <= 0 ) {
            return 0;
        }

        // Verify the translation link was established. If copy_post created a post
        // but linking failed, delete the orphan to prevent duplicates on retry.
        $linked = $this->language_manager->get_post_translation( $source_id, $target_lang );

        if ( ! $linked || $linked !== $new_target ) {
            \wp_delete_post( $new_target, true );
            \do_action(
                'pllat_log_error',
                \sprintf(
                    'Deleted orphaned translation post #%d: Polylang linking failed for source #%d → %s.',
                    $new_target,
                    $source_id,
                    $target_lang,
                ),
            );
            return 0;
        }

        return $new_target;
    }
}
