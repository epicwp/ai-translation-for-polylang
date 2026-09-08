<?php
namespace PLLAT\Translator\Models\Translatables;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Translator\Enums\TranslatableMetaKey;
use PLLAT\Translator\Models\Translations\Translation_Term;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;

/**
 * A term that can be translated.
 */
class Translatable_Term extends Base_Translatable {
    /**
     * Gets the language slug of the term.
     *
     * @return string The language slug.
     */
    public function get_language(): string {
        return $this->language_manager->get_term_language( $this->id );
    }

    /**
     * Gets the connected translations of the term.
     *
     * @return array<string, int> Key value pairs of language slug and term id.
     */
    public function get_translations(): array {
        return $this->language_manager->get_term_translations( $this->id );
    }

    /**
     * Gets the title of the term.
     *
     * @return string The title of the term.
     */
    public function get_title(): string {
        return \get_term( $this->id )->name;
    }

    /**
     * Gets the type of the term for job classification.
     * Always returns 'term' for all WordPress taxonomies (category, post_tag, custom taxonomies).
     *
     * @return string The type of the term ('term').
     */
    public function get_type(): string {
        return 'term';
    }

    /**
     * Gets the WordPress content type (taxonomy).
     *
     * @return string The WordPress taxonomy (e.g., 'category', 'post_tag', 'product_cat').
     */
    public function get_content_type(): string {
        $term = \get_term( $this->id );
        if ( $term instanceof \WP_Term ) {
            return $term->taxonomy;
        }
        return '';
    }

    /**
     * Gets the meta data of the term.
     *
     * @param string $key The meta key.
     * @param bool   $single Whether to return a single value or an array.
     * @return mixed The meta value.
     */
    public function get_meta( string $key, bool $single = false ) {
        return \get_term_meta( $this->id, $key, $single );
    }

    /**
     * Set whether this term is excluded from translation.
     *
     * @param bool $excluded Whether to exclude from translation.
     * @return void
     */
    public function set_excluded_from_translation( bool $excluded ): void {
        if ( $excluded ) {
            \update_term_meta( $this->id, TranslatableMetaKey::Exclude->value, true );
        } else {
            \delete_term_meta( $this->id, TranslatableMetaKey::Exclude->value );
        }
    }

    /**
     * Gets the available fields for the term.
     *
     * @return array<string> The available fields.
     */
    public function get_available_fields(): array {
        return Translation_Term::get_available_fields_for( $this->id );
    }

    /**
     * Gets the available meta fields for the term.
     *
     * @return array<string> The available meta fields.
     */
    public function get_available_meta_fields(): array {
        return Translation_Term_Meta::get_available_fields_for( $this->id );
    }

    /**
     * Gets the data underlying term data as an array.
     *
     * @param string|null $field The field to get the data for.
     * @return string|null The value of the field.
     */
    public function get_data( string $field ): ?string {
        $value = \get_term_field( $field, $this->id );
        return \is_string( $value ) ? $value : null;
    }

    /**
     * Gets all the data underlying term data as an array.
     *
     * @return array<string, mixed> The data underlying term data as an array.
     */
    public function get_all_data(): array {
        return \get_term( $this->id )->to_array();
    }

    /**
     * Gets the meta data underlying term meta data as an array.
     *
     * @return array<string, mixed> The meta data underlying term meta data as an array.
     */
    public function get_all_meta( bool $flatten = true ): array {
        return $flatten ? Helpers::get_flatten_term_meta( $this->id ) : \get_term_meta( $this->id );
    }
}
