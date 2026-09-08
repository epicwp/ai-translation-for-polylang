<?php
/**
 * Content_Fields_REST_Controller class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Models\Translations\Translation_Post;
use PLLAT\Translator\Models\Translations\Translation_Post_Meta;
use PLLAT\Translator\Models\Translations\Translation_Term;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST controller for listing available translatable fields.
 *
 * Returns grouped content and meta fields for a given content type.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'content' )]
class Content_Fields_REST_Controller extends \XWP_REST_Controller {
    /**
     * Slug fields that should be marked as protected (may affect SEO).
     *
     * @var array<string>
     */
    private const PROTECTED_FIELDS = array( 'post_name', 'slug' );

    /**
     * Permission check — requires manage_options capability.
     *
     * @return bool Whether the user has permission.
     */
    public function check_permission(): bool {
        return \current_user_can( 'manage_options' );
    }

    /**
     * Get available translatable fields for a content type.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route(
        route: '(?P<type>post|term)/(?P<entity>[a-zA-Z0-9_-]+)/fields',
        methods: 'GET',
        guard: 'check_permission',
    )]
    public function get_fields( \WP_REST_Request $request ): \WP_REST_Response {
        $type   = $request->get_param( 'type' );
        $entity = $request->get_param( 'entity' );
        $id     = (int) $request->get_param( 'id' );

        if ( 'post' !== $type && 'term' !== $type ) {
            return new \WP_REST_Response( array( 'message' => 'Invalid type.' ), 400 );
        }

        $resolved       = $this->resolve_fields( $type, $entity, $id );
        $content_fields = $resolved['content'];
        $meta_fields    = $resolved['meta'];

        return new \WP_REST_Response(
            array(
                'content' => \array_map(
                    fn( string $field ) => array(
                        'key'       => $field,
                        'label'     => $this->humanize_field_name( $field ),
                        'protected' => \in_array( $field, self::PROTECTED_FIELDS, true ),
                    ),
                    $content_fields,
                ),
                'meta'    => \array_map(
                    static fn( string $field ) => array(
                        'key'       => '_meta|' . $field,
                        'label'     => $field,
                        'protected' => false,
                    ),
                    $meta_fields,
                ),
            ),
        );
    }

    /**
     * Resolve content and meta fields for a given type, entity, and optional ID.
     *
     * @param string $type   Content type (post or term).
     * @param string $entity Entity slug (post type or taxonomy).
     * @param int    $id     Optional specific post/term ID.
     * @return array{content: array<string>, meta: array<string>}
     */
    private function resolve_fields( string $type, string $entity, int $id ): array {
        if ( 'post' === $type ) {
            return array(
                'content' => Translation_Post::get_available_fields(),
                'meta'    => $id > 0
                    ? Translation_Post_Meta::get_available_fields_for( $id )
                    : Translation_Post_Meta::get_available_fields_for_entity( $entity ),
            );
        }

        return array(
            'content' => Translation_Term::get_available_fields(),
            'meta'    => $id > 0
                ? Translation_Term_Meta::get_available_fields_for( $id )
                : Translation_Term_Meta::get_available_fields_for_entity( $entity ),
        );
    }

    /**
     * Humanize a core field name.
     *
     * @param string $field The field name.
     * @return string The human-readable label.
     */
    private function humanize_field_name( string $field ): string {
        $map = array(
            'description'  => \__( 'Description', 'ai-translation-for-polylang' ),
            'name'         => \__( 'Name', 'ai-translation-for-polylang' ),
            'post_content' => \__( 'Post content', 'ai-translation-for-polylang' ),
            'post_excerpt' => \__( 'Post excerpt', 'ai-translation-for-polylang' ),
            'post_name'    => \__( 'Post slug', 'ai-translation-for-polylang' ),
            'post_title'   => \__( 'Post title', 'ai-translation-for-polylang' ),
            'slug'         => \__( 'Slug', 'ai-translation-for-polylang' ),
        );

        return $map[ $field ] ?? \ucfirst( \str_replace( '_', ' ', $field ) );
    }
}
