<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use PLLAT\Translator\Models\Translations\Translation_Post;
use PLLAT\Translator\Models\Translations\Translation_Post_Meta;
use PLLAT\Translator\Models\Translations\Translation_Term;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;

/**
 * Walks Polylang's translation map in chunks and upserts the index.
 *
 * Used at v3.9.0 upgrade time to seed the index from existing translations,
 * and by the daily safety rebuild handler to repair drift from missed hooks.
 *
 * Idempotent (the upsert is). Safe to re-run any number of times.
 */
class Translation_Index_Prime_Service {

    public function __construct(
        protected Translation_Index_Repository $repository,
        protected Language_Manager $language_manager,
        protected Translation_Field_State_Repository $field_state_repository,
        protected Field_Hash_Service $field_hash_service,
    ) {}

    /**
     * @return array{processed:int, done:bool, next_offset:int}
     */
    public function run_chunk( string $source_kind, int $offset, int $chunk_size ): array {
        if ( Translation_Index_Repository::KIND_POST === $source_kind ) {
            return $this->run_post_chunk( $offset, $chunk_size );
        }
        return $this->run_term_chunk( $offset, $chunk_size );
    }

    private function run_post_chunk( int $offset, int $chunk_size ): array {
        global $wpdb;
        $post_types = $this->language_manager->get_active_post_types();
        if ( \count( $post_types ) === 0 ) {
            return array( 'processed' => 0, 'done' => true, 'next_offset' => $offset );
        }

        $placeholders = \implode( ',', \array_fill( 0, \count( $post_types ), '%s' ) );

        // Always skip auto-draft. Skip 'inherit' (attachments) unless media
        // translation is enabled in Polylang, in which case 'attachment' is
        // an active post type and attachments must be primed.
        $excluded_statuses = array( 'auto-draft' );
        if ( ! \in_array( 'attachment', $post_types, true ) ) {
            $excluded_statuses[] = 'inherit';
        }
        $es_ph = \implode( ',', \array_fill( 0, \count( $excluded_statuses ), '%s' ) );

        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per post type and per excluded status plus LIMIT/OFFSET, matching the merged args.
            $wpdb->prepare(
                "SELECT ID, post_type, post_status FROM {$wpdb->posts}
                 WHERE post_type IN ({$placeholders})
                   AND post_status NOT IN ({$es_ph})
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                ...\array_merge( $post_types, $excluded_statuses, array( $chunk_size, $offset ) ),
            ),
            \ARRAY_A,
        );

        if ( null === $rows || \count( $rows ) === 0 ) {
            return array( 'processed' => 0, 'done' => true, 'next_offset' => $offset );
        }

        $processed = 0;
        foreach ( $rows as $row ) {
            $post_id     = (int) $row['ID'];
            $source_lang = $this->language_manager->get_post_language( $post_id );
            if ( '' === $source_lang ) {
                continue;
            }
            $translations = $this->language_manager->get_post_translations( $post_id );
            foreach ( $translations as $lang => $other_id ) {
                if ( $lang === $source_lang ) {
                    continue;
                }
                $other_id = (int) $other_id;
                $this->repository->upsert(
                    Translation_Index_Repository::KIND_POST,
                    $post_id,
                    $source_lang,
                    (string) $lang,
                    $other_id > 0 ? $other_id : null,
                    $row['post_type'],
                    $row['post_status'],
                );
                $this->seed_field_state_for_post( $post_id, (string) $lang );
            }
            $processed++;
        }

        return array(
            'processed'   => $processed,
            'done'        => \count( $rows ) < $chunk_size,
            'next_offset' => $offset + \count( $rows ),
        );
    }

    private function run_term_chunk( int $offset, int $chunk_size ): array {
        global $wpdb;
        $taxonomies = $this->language_manager->get_active_taxonomies();
        if ( \count( $taxonomies ) === 0 ) {
            return array( 'processed' => 0, 'done' => true, 'next_offset' => $offset );
        }

        $placeholders = \implode( ',', \array_fill( 0, \count( $taxonomies ), '%s' ) );
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per taxonomy plus LIMIT/OFFSET, matching the merged args.
            $wpdb->prepare(
                "SELECT t.term_id, tt.taxonomy
                   FROM {$wpdb->terms} t
                   INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                  WHERE tt.taxonomy IN ({$placeholders})
               ORDER BY t.term_id ASC
                  LIMIT %d OFFSET %d",
                ...\array_merge( $taxonomies, array( $chunk_size, $offset ) ),
            ),
            \ARRAY_A,
        );

        if ( null === $rows || \count( $rows ) === 0 ) {
            return array( 'processed' => 0, 'done' => true, 'next_offset' => $offset );
        }

        $processed = 0;
        foreach ( $rows as $row ) {
            $term_id     = (int) $row['term_id'];
            $source_lang = $this->language_manager->get_term_language( $term_id );
            if ( '' === $source_lang ) {
                continue;
            }
            $translations = $this->language_manager->get_term_translations( $term_id );
            foreach ( $translations as $lang => $other_id ) {
                if ( $lang === $source_lang ) {
                    continue;
                }
                $other_id = (int) $other_id;
                $this->repository->upsert(
                    Translation_Index_Repository::KIND_TERM,
                    $term_id,
                    $source_lang,
                    (string) $lang,
                    $other_id > 0 ? $other_id : null,
                    $row['taxonomy'],
                    'active',
                );
                $this->seed_field_state_for_term( $term_id, (string) $lang );
            }
            $processed++;
        }

        return array(
            'processed'   => $processed,
            'done'        => \count( $rows ) < $chunk_size,
            'next_offset' => $offset + \count( $rows ),
        );
    }

    /**
     * Compute and upsert source-field hashes for all translatable fields of a post,
     * for a given target language. Used at prime time so existing translations
     * benefit from smart edit-detection from day one.
     */
    private function seed_field_state_for_post( int $post_id, string $target_lang ): void {
        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        foreach ( Translation_Post::get_available_fields_for( $post_id ) as $field ) {
            $value = $post->{$field} ?? null;
            if ( null === $value || '' === $value ) {
                continue;
            }
            $this->field_state_repository->upsert(
                Translation_Index_Repository::KIND_POST,
                $post_id,
                $target_lang,
                (string) $field,
                $this->field_hash_service->hash_value( $value ),
            );
        }

        foreach ( Translation_Post_Meta::get_available_fields_for( $post_id ) as $meta_key ) {
            $value = \get_post_meta( $post_id, $meta_key, true );
            if ( null === $value || '' === $value ) {
                continue;
            }
            $this->field_state_repository->upsert(
                Translation_Index_Repository::KIND_POST,
                $post_id,
                $target_lang,
                '_meta|' . $meta_key,
                $this->field_hash_service->hash_value( $value ),
            );
        }
    }

    /**
     * Compute and upsert source-field hashes for all translatable fields of a term,
     * for a given target language.
     */
    private function seed_field_state_for_term( int $term_id, string $target_lang ): void {
        $term = \get_term( $term_id );
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        $field_map = array(
            'name'        => $term->name,
            'description' => $term->description,
            'slug'        => $term->slug,
        );

        foreach ( Translation_Term::get_available_fields_for( $term_id ) as $field ) {
            $value = $field_map[ $field ] ?? null;
            if ( null === $value || '' === $value ) {
                continue;
            }
            $this->field_state_repository->upsert(
                Translation_Index_Repository::KIND_TERM,
                $term_id,
                $target_lang,
                (string) $field,
                $this->field_hash_service->hash_value( $value ),
            );
        }

        foreach ( Translation_Term_Meta::get_available_fields_for( $term_id ) as $meta_key ) {
            $value = \get_term_meta( $term_id, $meta_key, true );
            if ( null === $value || '' === $value ) {
                continue;
            }
            $this->field_state_repository->upsert(
                Translation_Index_Repository::KIND_TERM,
                $term_id,
                $target_lang,
                '_meta|' . $meta_key,
                $this->field_hash_service->hash_value( $value ),
            );
        }
    }
}
