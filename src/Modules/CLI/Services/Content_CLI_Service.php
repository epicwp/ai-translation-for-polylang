<?php
namespace PLLAT\CLI\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translator\Models\Translatables\Translatable_Post;
use PLLAT\Translator\Models\Translatables\Translatable_Term;
use PLLAT\Translator\Models\Translations\Translation_Post;
use PLLAT\Translator\Models\Translations\Translation_Post_Meta;
use PLLAT\Translator\Models\Translations\Translation_Term;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;

/**
 * Content CLI service for post/term operations.
 *
 * @package PLLAT\CLI\Services
 */
class Content_CLI_Service {
    public function __construct(
        protected Language_Manager $language_manager,
    ) {}

    /**
     * Show translations for content in table format.
     *
     * @param string $type Content type ('post' or 'term').
     * @param int    $id   Content ID.
     * @return void
     */
    public function show_translations( string $type, int $id ): void {
        $translations = 'post' === $type
            ? $this->language_manager->get_post_translations( $id )
            : $this->language_manager->get_term_translations( $id );

        if ( ! $translations ) {
            \WP_CLI::line( "No translations found for {$type} {$id}" );
            return;
        }

        \WP_CLI::line( "Translations for {$type} {$id}:" );

        $table_data = array();
        foreach ( $translations as $lang_code => $content_id ) {
            $table_data[] = array(
                'Language Code' => $lang_code,
                'Language Name' => $this->language_manager->get_language_name( $lang_code ),
                'Translated ID' => $content_id,
            );
        }

        \WP_CLI\Utils\format_items(
            'table',
            $table_data,
            array( 'Language Code', 'Language Name', 'Translated ID' ),
        );
    }

    /**
     * Show the language assigned to content.
     *
     * @param string $type Content type ('post' or 'term').
     * @param int    $id   Content ID.
     * @return void
     */
    public function show_language( string $type, int $id ): void {
        $lang = 'post' === $type
            ? $this->language_manager->get_post_language( $id )
            : $this->language_manager->get_term_language( $id );

        if ( ! $lang ) {
            \WP_CLI::line( "No language assigned to {$type} {$id}" );
            return;
        }

        \WP_CLI::line( "Language: {$lang}" );
    }

    /**
     * Show translatable standard post fields in table format.
     *
     * @param int $id Post ID.
     * @return void
     */
    public function show_post_fields( int $id ): void {
        $post = \get_post( $id );
        if ( ! $post ) {
            \WP_CLI::error( "Post {$id} not found" );
            return;
        }

        $available_fields = Translation_Post::get_available_fields_for( $id );

        \WP_CLI::line( "Standard fields for post {$id}:" );

        $table_data = array();
        foreach ( $available_fields as $field ) {
            $value         = $post->$field ?? '';
            $has_value     = ! empty( $value );
            $value_preview = $has_value ? \mb_substr( \wp_strip_all_tags( $value ), 0, 60 ) : '';

            $table_data[] = array(
                'Field'         => $field,
                'Has Value'     => $has_value ? 'Yes' : 'No',
                'Value Preview' => $value_preview,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $table_data, array( 'Field', 'Has Value', 'Value Preview' ) );
    }

    /**
     * Show translatable post meta fields (SEO, ACF, etc.) in table format.
     *
     * @param int $id Post ID.
     * @return void
     */
    public function show_post_meta_fields( int $id ): void {
        $post = \get_post( $id );
        if ( ! $post ) {
            \WP_CLI::error( "Post {$id} not found" );
            return;
        }

        $available_fields = Translation_Post_Meta::get_available_fields_for( $id );

        \WP_CLI::line( "Meta fields for post {$id}:" );

        $table_data = array();
        foreach ( $available_fields as $field ) {
            $value         = \get_post_meta( $id, $field, true );
            $has_value     = ! empty( $value );
            $value_preview = $has_value ? \mb_substr( \wp_strip_all_tags( (string) $value ), 0, 60 ) : '';

            $table_data[] = array(
                'Has Value'     => $has_value ? 'Yes' : 'No',
                'Meta Key'      => $field,
                'Value Preview' => $value_preview,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $table_data, array( 'Meta Key', 'Has Value', 'Value Preview' ) );
    }

    /**
     * Show translatable standard term fields in table format.
     *
     * @param int $id Term ID.
     * @return void
     */
    public function show_term_fields( int $id ): void {
        $term = \get_term( $id );
        if ( ! $term || \is_wp_error( $term ) ) {
            \WP_CLI::error( "Term {$id} not found" );
            return;
        }

        $available_fields = Translation_Term::get_available_fields_for( $id );

        \WP_CLI::line( "Standard fields for term {$id}:" );

        $table_data = array();
        foreach ( $available_fields as $field ) {
            $value         = $term->$field ?? '';
            $has_value     = ! empty( $value );
            $value_preview = $has_value ? \mb_substr( \wp_strip_all_tags( $value ), 0, 60 ) : '';

            $table_data[] = array(
                'Field'         => $field,
                'Has Value'     => $has_value ? 'Yes' : 'No',
                'Value Preview' => $value_preview,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $table_data, array( 'Field', 'Has Value', 'Value Preview' ) );
    }

    /**
     * Show translatable term meta fields (SEO, etc.) in table format.
     *
     * @param int $id Term ID.
     * @return void
     */
    public function show_term_meta_fields( int $id ): void {
        $term = \get_term( $id );
        if ( ! $term || \is_wp_error( $term ) ) {
            \WP_CLI::error( "Term {$id} not found" );
            return;
        }

        $available_fields = Translation_Term_Meta::get_available_fields_for( $id );

        \WP_CLI::line( "Meta fields for term {$id}:" );

        $table_data = array();
        foreach ( $available_fields as $field ) {
            $value         = \get_term_meta( $id, $field, true );
            $has_value     = ! empty( $value );
            $value_preview = $has_value ? \mb_substr( \wp_strip_all_tags( (string) $value ), 0, 60 ) : '';

            $table_data[] = array(
                'Has Value'     => $has_value ? 'Yes' : 'No',
                'Meta Key'      => $field,
                'Value Preview' => $value_preview,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $table_data, array( 'Meta Key', 'Has Value', 'Value Preview' ) );
    }
}
