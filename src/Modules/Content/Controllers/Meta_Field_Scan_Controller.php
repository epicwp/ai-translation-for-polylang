<?php
/**
 * Meta_Field_Scan_Controller class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Services\Meta_Field_Scanner_Service;
use PLLAT\Translator\Services\Field_Snapshot_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST controller for triggering meta field AI classification scans.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'meta-fields' )]
class Meta_Field_Scan_Controller extends \XWP_REST_Controller {
    /**
     * Constructor.
     *
     * @param Meta_Field_Scanner_Service $scanner                The meta field scanner service.
     * @param Language_Manager           $language_manager       Language manager.
     * @param Field_Snapshot_Service     $field_snapshot_service Snapshot detection service.
     */
    public function __construct(
        private readonly Meta_Field_Scanner_Service $scanner,
        private readonly Language_Manager $language_manager,
        private readonly Field_Snapshot_Service $field_snapshot_service,
    ) {
    }

    /**
     * Permission check — requires manage_options capability.
     *
     * @return bool Whether the user has permission.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }

    /**
     * Add a meta key to a classification for a content type.
     *
     * @param \WP_REST_Request $request {
     *     @type string $content_type Post type or taxonomy slug.
     *     @type string $meta_key     The meta key name.
     *     @type string $category     Classification: translate|copy|ignore.
     * }
     * @return \WP_REST_Response
     */
    #[REST_Route( route: 'classify', methods: 'PUT', guard: 'check_permission' )]
    public function classify_field( \WP_REST_Request $request ): \WP_REST_Response {
        $content_type = \sanitize_key( $request->get_param( 'content_type' ) ?? '' );
        $meta_key     = \sanitize_text_field( $request->get_param( 'meta_key' ) ?? '' );
        $category     = \sanitize_key( $request->get_param( 'category' ) ?? '' );

        if ( '' === $content_type || '' === $meta_key ) {
            return new \WP_REST_Response( array( 'message' => 'Missing content_type or meta_key.' ), 400 );
        }

        if ( ! \in_array( $category, array( 'translate', 'copy', 'ignore' ), true ) ) {
            return new \WP_REST_Response(
                array( 'message' => 'Invalid category. Must be translate, copy, or ignore.' ),
                400,
            );
        }

        // Remove from all categories first, then add to target.
        $this->scanner->get_classification_service()->remove_key( $content_type, $meta_key );
        $this->scanner->get_classification_service()->store_classifications(
            $content_type,
            array( $meta_key => $category ),
        );

        $body = array(
            'affected_fields' => $this->run_snapshot_detection(),
            'success'         => true,
        );

        return new \WP_REST_Response( $body );
    }

    /**
     * Remove a meta key from translate/copy (moves to ignore).
     *
     * @param \WP_REST_Request $request {
     *     @type string $content_type Post type or taxonomy slug.
     *     @type string $meta_key     The meta key name.
     * }
     * @return \WP_REST_Response
     */
    #[REST_Route( route: 'classify', methods: 'DELETE', guard: 'check_permission' )]
    public function remove_field( \WP_REST_Request $request ): \WP_REST_Response {
        $content_type = \sanitize_key( $request->get_param( 'content_type' ) ?? '' );
        $meta_key     = \sanitize_text_field( $request->get_param( 'meta_key' ) ?? '' );

        if ( '' === $content_type || '' === $meta_key ) {
            return new \WP_REST_Response( array( 'message' => 'Missing content_type or meta_key.' ), 400 );
        }

        // Move to ignore so re-scan doesn't resurrect it.
        $this->scanner->get_classification_service()->remove_key( $content_type, $meta_key );
        $this->scanner->get_classification_service()->store_classifications(
            $content_type,
            array( $meta_key => 'ignore' ),
        );

        $body = array(
            'affected_fields' => $this->run_snapshot_detection(),
            'success'         => true,
        );

        return new \WP_REST_Response( $body );
    }

    /**
     * Trigger a meta field scan for a given post type, or all translatable types if omitted.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'scan', methods: 'POST', guard: 'check_permission' )]
    public function scan( \WP_REST_Request $request ): \WP_REST_Response {
        $post_type = $request->get_param( 'post_type' );
        $taxonomy  = $request->get_param( 'taxonomy' );
        $force     = (bool) $request->get_param( 'force' );

        // Single taxonomy scan.
        if ( \is_string( $taxonomy ) && \strlen( $taxonomy ) > 0 ) {
            if ( ! \taxonomy_exists( $taxonomy ) ) {
                return new \WP_REST_Response( array( 'message' => 'Invalid taxonomy.' ), 400 );
            }

            $result = $this->scanner->scan_taxonomy( $taxonomy, $force );

            \update_option( 'pllat_meta_field_last_scan', \time(), false );

            return new \WP_REST_Response(
                array(
                    'affected_fields' => $this->run_snapshot_detection(),
                    'copy_count'      => $result->copy_count,
                    'fields'          => $result->classifications,
                    'ignore_count'    => $result->ignore_count,
                    'success'         => true,
                    'translate_count' => $result->translate_count,
                ),
            );
        }

        // Single post type scan.
        if ( \is_string( $post_type ) && \strlen( $post_type ) > 0 ) {
            if ( ! \post_type_exists( $post_type ) ) {
                return new \WP_REST_Response( array( 'message' => 'Invalid post type.' ), 400 );
            }

            $result = $this->scanner->scan( $post_type, $force );

            \update_option( 'pllat_meta_field_last_scan', \time(), false );

            return new \WP_REST_Response(
                array(
                    'affected_fields' => $this->run_snapshot_detection(),
                    'copy_count'      => $result->copy_count,
                    'fields'          => $result->classifications,
                    'ignore_count'    => $result->ignore_count,
                    'success'         => true,
                    'translate_count' => $result->translate_count,
                ),
            );
        }

        // Scan all translatable post types and taxonomies.
        $total_translate = 0;
        $total_copy      = 0;
        $total_ignore    = 0;
        $all_fields      = array();

        foreach ( $this->language_manager->get_active_post_types() as $pt ) {
            $result           = $this->scanner->scan( $pt, $force );
            $total_translate += $result->translate_count;
            $total_copy      += $result->copy_count;
            $total_ignore    += $result->ignore_count;
            $all_fields       = \array_merge( $all_fields, $result->classifications );
        }

        foreach ( $this->language_manager->get_active_taxonomies() as $tax ) {
            $result           = $this->scanner->scan_taxonomy( $tax, $force );
            $total_translate += $result->translate_count;
            $total_copy      += $result->copy_count;
            $total_ignore    += $result->ignore_count;
            $all_fields       = \array_merge( $all_fields, $result->classifications );
        }

        \update_option( 'pllat_meta_field_last_scan', \time(), false );

        return new \WP_REST_Response(
            array(
                'affected_fields' => $this->run_snapshot_detection(),
                'copy_count'      => $total_copy,
                'fields'          => $all_fields,
                'ignore_count'    => $total_ignore,
                'success'         => true,
                'translate_count' => $total_translate,
            ),
        );
    }

    /**
     * Trigger field snapshot detection after a classification write.
     *
     * Returns the number of affected meta fields across post types and taxonomies
     * so the REST response can surface "N new fields queued for existing translations".
     */
    private function run_snapshot_detection(): int {
        $detected = $this->field_snapshot_service->detect_and_handle_new_fields();

        return \array_sum( \array_map( '\count', $detected['post_meta_fields'] ) )
            + \array_sum( \array_map( '\count', $detected['term_meta_fields'] ) );
    }
}
