<?php
declare(strict_types=1);

namespace PLLAT\Content\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Handlers\Content_Change_Handler;
use PLLAT\Content\Services\Interfaces\Content_Service as Content_Service_Interface;

/**
 * Content Service for processing translations and updating content fields.
 * Handles reference-based field updates with full hook integration.
 */
class Content_Service implements Content_Service_Interface {
    use Traits\Reference_Parsing_Trait;

    /**
     * Create a normalized reference key for a field and type.
     * Types: 'meta', 'custom_data', default core when empty.
     * Extensible via 'pllat_reference_prefixes' and 'pllat_content_reference_prefix'.
     *
     * @param string $field Field name.
     * @param string $type  Field type ('meta'|'custom_data'|'').
     * @return string Reference key.
     */
    public static function create_reference_key( string $field, string $type = '' ): string {
        $prefix_map = array(
            'custom_data' => '_custom_data|',
            'meta'        => '_meta|',
        );

        /**
         * Filter reference key prefixes.
         *
         * @param array $prefix_map Map of type => prefix.
         */
        $prefix_map = \apply_filters( 'pllat_reference_prefixes', $prefix_map );

        $prefix = $prefix_map[ $type ] ?? '';

        /**
         * Filter individual prefix for type.
         *
         * @param string $prefix The prefix for this type.
         * @param string $type   The field type.
         */
        $prefix = \apply_filters( 'pllat_content_reference_prefix', $prefix, $type );

        return $prefix . \str_replace( ' ', '_', $field );
    }

    /**
     * Constructor.
     *
     * @param Post_Content_Service   $post_content_service   Post service.
     * @param Term_Content_Service   $term_content_service   Term service.
     * @param Content_Change_Handler $content_change_handler Content change handler.
     * @param Language_Manager       $language_manager       Language manager for translation plugin integration.
     */
    public function __construct(
        private Post_Content_Service $post_content_service,
        private Term_Content_Service $term_content_service,
        private Content_Change_Handler $content_change_handler,
        private Language_Manager $language_manager,
    ) {
    }

    /**
     * Get the value of a content field by reference.
     * Orchestrates between post and term services.
     *
     * @param int    $content_id   Content ID.
     * @param string $content_type Content type (post|term).
     * @param string $reference    Field reference.
     * @return string|null The field value.
     * @throws \Exception If content not found.
     */
    public function get_content_field( int $content_id, string $content_type, string $reference ): ?string {
        if ( 'post' === $content_type ) {
            return $this->post_content_service->get_post_field( $content_id, $reference );
        }

        if ( 'term' === $content_type ) {
            return $this->term_content_service->get_term_field( $content_id, $reference );
        }

        throw new \Exception( \esc_html( "Unknown content type: {$content_type}" ) );
    }

    /**
     * Write a single translated field to the target content (lean pipeline entry point).
     *
     * Per-field public API used by Lean_Job_Worker. Routing and the
     * pllat_handle_field_update integration filter are delegated to
     * update_content_field. The lean worker wraps its write loop in
     * Language_Manager::suspend_meta_sync/resume_meta_sync; this method
     * itself does not suspend anything.
     *
     * @param int    $target_id    Target post or term ID.
     * @param string $content_type 'post' | 'term'.
     * @param string $reference    Reference key (e.g. 'post_title', '_meta|key').
     * @param mixed  $value        Translated value.
     *
     * @throws \Exception When $content_type is unknown.
     */
    public function write_field_translation(
        int $target_id,
        string $content_type,
        string $reference,
        mixed $value,
    ): void {
        if ( 'post' !== $content_type && 'term' !== $content_type ) {
            throw new \Exception( \esc_html( "Unknown content type: {$content_type}" ) );
        }
        $this->update_content_field( $target_id, $content_type, $reference, (string) $value );
    }

    /**
     * Update a content field based on reference key.
     *
     * @param int    $content_id   Content ID.
     * @param string $content_type Content type (post|term).
     * @param string $reference    Field reference key.
     * @param string $translation  Translated value.
     * @return bool True if the field was updated successfully.
     */
    private function update_content_field( int $content_id, string $content_type, string $reference, string $translation ): bool {
        /**
         * Allow integrations to handle field updates.
         *
         * @param bool   $handled      Whether the update was handled.
         * @param int    $content_id   Content ID.
         * @param string $content_type Content type (post|term).
         * @param string $reference    Field reference key.
         * @param string $translation  Translated value.
         */
        $handled = \apply_filters(
            'pllat_handle_field_update',
            false,
            $content_id,
            $content_type,
            $reference,
            $translation,
        );

        if ( $handled ) {
            return true;
        }

        // Core field handling delegates.
        $result = null;
        if ( 'post' === $content_type ) {
            $result = $this->post_content_service->update_post_field( $content_id, $reference, $translation );
        } elseif ( 'term' === $content_type ) {
            $result = $this->term_content_service->update_term_field( $content_id, $reference, $translation );
        }

        /**
         * Action after field update.
         *
         * @param int    $content_id   Content ID.
         * @param string $content_type Content type.
         * @param string $reference    Field reference.
         * @param string $translation  Translation value.
         */
        \do_action( 'pllat_field_updated', $content_id, $content_type, $reference, $translation );

        return null !== $result && ! \is_wp_error( $result );
    }

}
