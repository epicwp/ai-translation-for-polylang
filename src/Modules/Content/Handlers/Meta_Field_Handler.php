<?php
/**
 * Meta_Field_Handler class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Content
 */

declare(strict_types=1);

namespace PLLAT\Content\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Content\Services\Meta_Field_Classification_Service;
use PLLAT\Content\Services\Meta_Field_Filter_Service;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

/**
 * Injects unified meta field classifications into entity-scoped translation
 * and copy filters so each post type / taxonomy only sees its own keys, and
 * copies page-builder meta as-is when Polylang creates a translation.
 *
 * We intentionally do NOT hook the base
 * `pllat_available_post_meta_translation_fields` or
 * `pllat_available_term_meta_translation_fields` filters: those feed BOTH
 * the `_for_entity` and `_for` variants, so contributing classified keys
 * there leaks them across every post type / taxonomy.
 */
#[Handler( tag: 'init', priority: 11 )]
class Meta_Field_Handler {
    /**
     * Constructor.
     *
     * @param Meta_Field_Classification_Service $classification_service Classification service.
     */
    public function __construct(
        private readonly Meta_Field_Classification_Service $classification_service,
    ) {
    }

    /**
     * Add translate-classified keys for a post type.
     *
     * @param array<string> $fields    Current translatable post meta fields for the post type.
     * @param string        $post_type Post type slug.
     * @return array<string>
     */
    #[Filter( tag: 'pllat_available_post_meta_translation_fields_for_entity', priority: 90 )]
    public function add_translate_keys_for_post_type( array $fields, string $post_type ): array {
        $keys = $this->classification_service->get_translate_keys( $post_type );

        if ( 0 === \count( $keys ) ) {
            return $fields;
        }

        return \array_values( \array_unique( \array_merge( $fields, $keys ) ) );
    }

    /**
     * Add translate-classified keys for a specific post (derives post type from ID).
     *
     * @param array<string> $fields  Current translatable post meta fields.
     * @param int           $post_id Post ID.
     * @return array<string>
     */
    #[Filter( tag: 'pllat_available_post_meta_translation_fields_for', priority: 90 )]
    public function add_translate_keys_for_post( array $fields, int $post_id ): array {
        $post_type = \get_post_type( $post_id );

        if ( false === $post_type || '' === $post_type ) {
            return $fields;
        }

        return $this->add_translate_keys_for_post_type( $fields, $post_type );
    }

    /**
     * Add translate-classified keys for a taxonomy.
     *
     * @param array<string> $fields   Current translatable term meta fields for the taxonomy.
     * @param string        $taxonomy Taxonomy slug.
     * @return array<string>
     */
    #[Filter( tag: 'pllat_available_term_meta_translation_fields_for_entity', priority: 90 )]
    public function add_translate_keys_for_taxonomy( array $fields, string $taxonomy ): array {
        $keys = $this->classification_service->get_translate_keys( Meta_Field_Classification_Service::taxonomy_key( $taxonomy ) );

        if ( 0 === \count( $keys ) ) {
            return $fields;
        }

        return \array_values( \array_unique( \array_merge( $fields, $keys ) ) );
    }

    /**
     * Add translate-classified keys for a specific term (derives taxonomy from ID).
     *
     * @param array<string> $fields  Current translatable term meta fields.
     * @param int           $term_id Term ID.
     * @return array<string>
     */
    #[Filter( tag: 'pllat_available_term_meta_translation_fields_for', priority: 90 )]
    public function add_translate_keys_for_term( array $fields, int $term_id ): array {
        $term = \get_term( $term_id );

        if ( ! $term instanceof \WP_Term ) {
            return $fields;
        }

        return $this->add_translate_keys_for_taxonomy( $fields, $term->taxonomy );
    }

    /**
     * Add the page-builder meta of the source and the copy-classified keys of
     * its post type to Polylang's meta copy list.
     *
     * Only contributes on creation-time copy (`$sync === false`). Polylang
     * also fires this filter with `$sync = true` from its live sync
     * (`PLL_Sync_Metas::update_meta` / `delete_meta`) on every meta write;
     * contributing keys there force-enables two-way live sync even when the
     * user disabled Polylang's custom-fields sync, so emptying or deleting
     * a copy-classified meta on one translation wipes it on the source and
     * every other translation (issue #460).
     *
     * @param array<string> $keys Current meta keys to copy.
     * @param bool          $sync Whether this is a sync (vs. create) operation.
     * @param int           $from Source post ID.
     * @param int           $to   Target post ID.
     * @param string        $lang Target language slug.
     * @return array<string>
     */
    #[Filter( tag: 'pll_copy_post_metas', priority: 10 )]
    public function add_copy_keys( array $keys, bool $sync, int $from, int $to, string $lang ): array {
        if ( true === $sync ) {
            return $keys;
        }

        $keys = \array_merge( $keys, $this->builder_keys( $from ), $this->classified_copy_keys( $from ) );

        return \array_values( \array_unique( $keys ) );
    }

    /**
     * Strip WordPress internal per-post-instance meta keys from the copy list.
     *
     * Polylang Pro's sync-post-model copies ALL custom fields except
     * `_edit_last` and `_edit_lock`, so per-post-instance internals like
     * `_wp_old_slug`, `_wp_old_date`, `_wp_trash_meta_*` leak from the
     * source post to its translations. The leak is observable as e.g. a
     * French translation carrying the Dutch source's historical slug in
     * `_wp_old_slug` — confusing data, not functionally fatal, but never
     * what the customer wants.
     *
     * Runs at priority 100 — well after Polylang's own contribution at
     * priority 5 and our own `add_copy_keys` at priority 10 — so we always
     * see the final list and can subtract. Uses the same exclusion list
     * the classifier already applies for AI training (DRY against
     * Meta_Field_Filter_Service::WP_INTERNAL_PREFIXES).
     *
     * @param array<string> $keys Current meta keys to copy.
     * @return array<string>
     */
    #[Filter( tag: 'pll_copy_post_metas', priority: 100 )]
    public function strip_wp_internals_from_copy( array $keys ): array {
        $prefixes = Meta_Field_Filter_Service::WP_INTERNAL_PREFIXES;

        return \array_values(
            \array_filter(
                $keys,
                static function ( string $key ) use ( $prefixes ): bool {
                    foreach ( $prefixes as $prefix ) {
                        if ( \str_starts_with( $key, $prefix ) ) {
                            return false;
                        }
                    }
                    return true;
                },
            ),
        );
    }

    /**
     * Add copy-classified keys to Polylang's term meta copy list, scoped to the source taxonomy.
     *
     * Only contributes on creation-time copy (`$sync === false`) — same
     * live-sync wipe hazard as `add_copy_keys` (issue #460).
     *
     * @param array<string> $keys Current term meta keys to copy.
     * @param bool          $sync Whether this is a sync (vs. create) operation.
     * @param int           $from Source term ID.
     * @param int           $to   Target term ID.
     * @param string        $lang Target language slug.
     * @return array<string>
     */
    #[Filter( tag: 'pll_copy_term_metas', priority: 10 )]
    public function add_term_copy_keys( array $keys, bool $sync, int $from, int $to, string $lang ): array {
        if ( true === $sync ) {
            return $keys;
        }

        $term = \get_term( $from );

        if ( ! $term instanceof \WP_Term ) {
            return $keys;
        }

        $entity_key = Meta_Field_Classification_Service::taxonomy_key( $term->taxonomy );
        $copy       = $this->classification_service->get_copy_keys( $entity_key );

        if ( 0 === \count( $copy ) ) {
            return $keys;
        }

        return \array_values( \array_unique( \array_merge( $keys, $copy ) ) );
    }

    /**
     * Every meta key on the source that a page builder owns.
     *
     * Elementor and Bricks keep the layout in underscore-prefixed meta, which
     * Polylang's own copy skips as protected. Copying those keys as they are
     * when the translation is created keeps the layout on the translation in
     * every edition; the builder integrations (pro) overwrite the content key
     * with its translation afterwards. Never contributed on live sync.
     *
     * @param int $post_id Source post ID.
     * @return array<string>
     */
    private function builder_keys( int $post_id ): array {
        $prefixes = Meta_Field_Filter_Service::BUILDER_PREFIXES;

        return \array_values(
            \array_filter(
                \array_map( 'strval', \array_keys( \get_post_meta( $post_id ) ) ),
                static function ( string $key ) use ( $prefixes ): bool {
                    foreach ( $prefixes as $prefix ) {
                        if ( \str_starts_with( $key, $prefix ) ) {
                            return true;
                        }
                    }
                    return false;
                },
            ),
        );
    }

    /**
     * The copy-classified keys of the source's post type.
     *
     * @param int $post_id Source post ID.
     * @return array<string>
     */
    private function classified_copy_keys( int $post_id ): array {
        $post_type = \get_post_type( $post_id );

        if ( false === $post_type || '' === $post_type ) {
            return array();
        }

        return $this->classification_service->get_copy_keys( $post_type );
    }
}
