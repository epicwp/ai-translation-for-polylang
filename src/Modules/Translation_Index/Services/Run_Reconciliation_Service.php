<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translator\Models\Run_Spec;

/**
 * Computes the gap field set for a (source_kind, source_id, target_lang)
 * unit. Used by Lean_Job_Worker (mini-reconcile at claim) and Phase 4
 * tick handler (full reconcile at run completion).
 *
 * Gap rules:
 *   - F is in translatable_fields(source)
 *   - AND (target lacks F OR field_state.hash != current_hash(source.F) OR no field_state row)
 *
 * Force flags layer on top — see compute_mini_gaps.
 *
 * The protected reader methods are designed to be overridden in tests
 * and in Phase 4.2 when production wiring needs adapter methods. By
 * default they delegate to Translatable_Post / Translatable_Term
 * (via get_available_fields) and to WordPress post/term field accessors.
 */
class Run_Reconciliation_Service {

    public function __construct(
        protected Translation_Field_State_Repository $field_state_repository,
        protected Field_Hash_Service $field_hash_service,
    ) {}

    /**
     * Compute gap fields for a single (source_kind, source_id, target_lang) unit.
     *
     * @return array<int, string>
     */
    public function compute_mini_gaps(
        string $source_kind,
        int $source_id,
        string $target_lang,
        int $target_id,
        Run_Spec $spec
    ): array {
        $translatable = $this->read_source_translatable_fields( $source_kind, $source_id );
        if ( \count( $translatable ) === 0 ) {
            return array();
        }

        // Force-with-list: return intersection of force_fields with translatable fields.
        if ( $spec->is_force() && null !== $spec->force_fields() && \count( $spec->force_fields() ) > 0 ) {
            return \array_values( \array_intersect( $spec->force_fields(), $translatable ) );
        }

        // Force-without-list: all translatable fields are gaps.
        if ( $spec->is_force() ) {
            return \array_values( $translatable );
        }

        // Default rules: target lacks F OR hash mismatch OR no field_state entry.
        $stored_hashes = $this->field_state_repository->find_for_pair( $source_kind, $source_id, $target_lang );
        $gaps          = array();

        foreach ( $translatable as $reference ) {
            $source_value = $this->read_source_field_value( $source_kind, $source_id, $reference );
            if ( $this->is_empty_value( $source_value ) ) {
                continue; // nothing to translate — not a gap
            }

            $target_value = $this->read_target_field_value( $source_kind, $target_id, $reference );
            if ( $this->is_empty_value( $target_value ) ) {
                $gaps[] = $reference;
                continue;
            }

            $stored = $stored_hashes[ $reference ] ?? null;
            if ( null === $stored ) {
                $gaps[] = $reference;
                continue;
            }

            $current = $this->field_hash_service->hash_value( $source_value );
            if ( $stored !== $current ) {
                $gaps[] = $reference;
            }
        }

        return $gaps;
    }

    /**
     * Return the list of translatable field references for the given source entity.
     *
     * Override in tests (or production adapters) to inject arbitrary field lists
     * without needing a live WordPress post/term.
     *
     * @return array<int, string>
     */
    protected function read_source_translatable_fields( string $kind, int $source_id ): array {
        if ( 'post' === $kind ) {
            $translatable = \PLLAT\Translator\Models\Translatables\Translatable_Post::get_instance( $source_id );
            $core         = $translatable->get_available_fields();
            $meta         = \array_map(
                static fn( string $key ): string => \PLLAT\Content\Services\Content_Service::create_reference_key( $key, 'meta' ),
                $translatable->get_available_meta_fields(),
            );
            return \array_values( \array_merge( $core, $meta ) );
        }
        if ( 'term' === $kind ) {
            $translatable = \PLLAT\Translator\Models\Translatables\Translatable_Term::get_instance( $source_id );
            $core         = $translatable->get_available_fields();
            $meta         = \array_map(
                static fn( string $key ): string => \PLLAT\Content\Services\Content_Service::create_reference_key( $key, 'meta' ),
                $translatable->get_available_meta_fields(),
            );
            return \array_values( \array_merge( $core, $meta ) );
        }
        return array();
    }

    /**
     * Read a single field value from the source entity.
     *
     * Override in tests to inject controlled values.
     */
    protected function read_source_field_value( string $kind, int $source_id, string $reference ): mixed {
        if ( 'post' === $kind ) {
            return $this->read_post_field( $source_id, $reference );
        }
        if ( 'term' === $kind ) {
            return $this->read_term_field( $source_id, $reference );
        }
        return '';
    }

    /**
     * Read a single field value from the target entity.
     *
     * Override in tests to inject controlled values.
     */
    protected function read_target_field_value( string $kind, int $target_id, string $reference ): mixed {
        if ( 'post' === $kind ) {
            return $this->read_post_field( $target_id, $reference );
        }
        if ( 'term' === $kind ) {
            return $this->read_term_field( $target_id, $reference );
        }
        return '';
    }

    /**
     * Walk live wp_posts/wp_terms × spec.target_languages and check whether any
     * (source, target_lang) pair still has gap fields. Returns true when the run
     * is fully translated; false when work remains.
     *
     * Pure-cache model: translation_index has no rows for un-translated pairs, so
     * we must derive the full candidate set from the live entity tables instead.
     */
    public function reconcile_completion( Run_Spec $spec ): bool {
        if ( null !== $spec->post_query() ) {
            if ( ! $this->reconcile_kind( 'post', $spec->post_query(), $spec ) ) {
                return false;
            }
        }
        if ( null !== $spec->term_query() ) {
            if ( ! $this->reconcile_kind( 'term', $spec->term_query(), $spec ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function reconcile_kind( string $kind, array $query, Run_Spec $spec ): bool {
        $source_lang = (string) ( $query['source_lang'] ?? '' );
        if ( '' === $source_lang ) {
            return true;
        }
        $target_langs = \array_values( \array_filter(
            $spec->target_languages(),
            static fn( string $lang ): bool => $lang !== $source_lang,
        ) );
        if ( \count( $target_langs ) === 0 ) {
            return true;
        }

        $rows = 'post' === $kind
            ? $this->live_post_pairs( $query, $source_lang, $target_langs )
            : $this->live_term_pairs( $query, $source_lang, $target_langs );

        foreach ( $rows as $row ) {
            $source_id   = (int) $row['source_id'];
            $target_lang = (string) $row['target_lang'];
            $target_id   = (int) ( $row['target_id'] ?? 0 );

            $gaps = $this->compute_mini_gaps( $kind, $source_id, $target_lang, $target_id, $spec );
            if ( \count( $gaps ) > 0 ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Live walk wp_posts × Polylang language × target_langs LEFT JOIN translation_index.
     * Returns one row per (post, target_lang) pair. target_id is null/0 when no Polylang pair exists.
     *
     * @param array<string, mixed> $query
     * @param array<int, string>   $target_langs
     * @return array<int, array<string, mixed>>
     */
    private function live_post_pairs( array $query, string $source_lang, array $target_langs ): array {
        global $wpdb;

        $post_types = $query['post_types'] ?? array();
        if ( ! \is_array( $post_types ) || \count( $post_types ) === 0 ) {
            return array();
        }
        $post_status = $query['post_status'] ?? Helpers::post_statuses_for( $post_types );
        if ( ! \is_array( $post_status ) || \count( $post_status ) === 0 ) {
            $post_status = Helpers::post_statuses_for( $post_types );
        }

        $tl_unions = array();
        $tl_params = array();
        foreach ( $target_langs as $lang ) {
            $tl_unions[] = 'SELECT %s AS lang';
            $tl_params[] = $lang;
        }
        $tl_derived = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';

        $pt_ph = \implode( ',', \array_fill( 0, \count( $post_types ), '%s' ) );
        $ps_ph = \implode( ',', \array_fill( 0, \count( $post_status ), '%s' ) );

        $id_list_clause = '';
        $id_list_params = array();
        if ( isset( $query['id_list'] ) && \is_array( $query['id_list'] ) && \count( $query['id_list'] ) > 0 ) {
            $id_ph          = \implode( ',', \array_fill( 0, \count( $query['id_list'] ), '%d' ) );
            $id_list_clause = "AND p.ID IN ({$id_ph}) ";
            $id_list_params = $query['id_list'];
        }

        $index_table = $wpdb->prefix . 'pllat_translation_index';
        $sql         = "
            SELECT p.ID AS source_id,
                   tl.lang AS target_lang,
                   i.target_id AS target_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} ll_tax ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id AND ll_tax.taxonomy = 'language'
            INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
            CROSS JOIN {$tl_derived} AS tl
            LEFT JOIN {$index_table} i
                ON i.source_kind = 'post' AND i.source_id = p.ID AND i.target_lang = tl.lang
            WHERE p.post_type IN ({$pt_ph})
              AND p.post_status IN ({$ps_ph})
              AND ll.slug = %s
              AND tl.lang != ll.slug
              /* Excluded only when meta_value = '1' (true). Two writers: Translatable_Post deletes the row on toggle-off; Single_Translation_Service stores ''. Both are correctly NOT excluded by '= 1'. Do not relax to != '0' or a bare EXISTS — that re-introduces false-excludes. */
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} ex
                  WHERE ex.post_id = p.ID
                    AND ex.meta_key = '_pllat_exclude_from_translation'
                    AND ex.meta_value = '1'
              )
              {$id_list_clause}
        ";

        $params = \array_merge( $tl_params, $post_types, $post_status, array( $source_lang ), $id_list_params );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
        return null === $rows ? array() : $rows;
    }

    /**
     * Live walk wp_terms × Polylang term_language × target_langs LEFT JOIN translation_index.
     * Returns one row per (term, target_lang) pair. target_id is null/0 when no Polylang pair exists.
     *
     * @param array<string, mixed> $query
     * @param array<int, string>   $target_langs
     * @return array<int, array<string, mixed>>
     */
    private function live_term_pairs( array $query, string $source_lang, array $target_langs ): array {
        global $wpdb;

        $taxonomies = $query['taxonomies'] ?? array();
        if ( ! \is_array( $taxonomies ) || \count( $taxonomies ) === 0 ) {
            return array();
        }

        // Same defensive filter as Job_Claim_Service::query_term_candidates:
        // never consider taxonomies that Polylang doesn't list as
        // translatable. Reconciliation runs in the same workers; it must
        // see the same eligibility set.
        if ( \function_exists( 'pll_is_translated_taxonomy' ) && isset( $GLOBALS['polylang'] ) ) {
            $taxonomies = \array_values( \array_filter(
                $taxonomies,
                static fn( string $tax ): bool => \pll_is_translated_taxonomy( $tax ),
            ) );
        }
        if ( \count( $taxonomies ) === 0 ) {
            return array();
        }

        $tl_unions = array();
        $tl_params = array();
        foreach ( $target_langs as $lang ) {
            $tl_unions[] = 'SELECT %s AS lang';
            $tl_params[] = $lang;
        }
        $tl_derived = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';
        $tx_ph      = \implode( ',', \array_fill( 0, \count( $taxonomies ), '%s' ) );

        $id_list_clause = '';
        $id_list_params = array();
        if ( isset( $query['id_list'] ) && \is_array( $query['id_list'] ) && \count( $query['id_list'] ) > 0 ) {
            $id_ph          = \implode( ',', \array_fill( 0, \count( $query['id_list'] ), '%d' ) );
            $id_list_clause = "AND t.term_id IN ({$id_ph}) ";
            $id_list_params = $query['id_list'];
        }

        // Polylang stores term_language slugs prefixed ('pll_en'), unlike
        // the post-side 'language' taxonomy which is bare ('en'). Match the
        // prefixed form in the join, compare against the bare source_lang
        // for the "don't translate to self" guard.
        $index_table   = $wpdb->prefix . 'pllat_translation_index';
        $ll_slug_param = 'pll_' . $source_lang;
        $sql           = "
            SELECT t.term_id AS source_id,
                   tl.lang AS target_lang,
                   i.target_id AS target_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = t.term_id
            INNER JOIN {$wpdb->term_taxonomy} ll_tax ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id AND ll_tax.taxonomy = 'term_language'
            INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
            CROSS JOIN {$tl_derived} AS tl
            LEFT JOIN {$index_table} i
                ON i.source_kind = 'term' AND i.source_id = t.term_id AND i.target_lang = tl.lang
            WHERE tt.taxonomy IN ({$tx_ph})
              AND ll.slug = %s
              AND tl.lang != %s
              {$id_list_clause}
        ";

        $params = \array_merge( $tl_params, $taxonomies, array( $ll_slug_param, $source_lang ), $id_list_params );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
        return null === $rows ? array() : $rows;
    }

    private function read_post_field( int $post_id, string $reference ): mixed {
        if ( \str_starts_with( $reference, '_meta|' ) ) {
            $key = \substr( $reference, \strlen( '_meta|' ) );
            // Route through Translatable_Post::get_meta() so the pllat_get_post_meta
            // filter applies — that is how the ACF integration expands a complex
            // field (repeater/group/flexible/clone) into its translatable text.
            // A raw get_post_meta() here returns a repeater's row-count ("2"),
            // so the rows would never be detected as gaps or translated.
            return \PLLAT\Translator\Models\Translatables\Translatable_Post::get_instance( $post_id )->get_meta( $key, true );
        }
        if ( \str_starts_with( $reference, '_custom_data|' ) ) {
            $key = \substr( $reference, \strlen( '_custom_data|' ) );
            return \get_post_meta( $post_id, $key, true );
        }
        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return '';
        }
        return $post->{$reference} ?? '';
    }

    private function read_term_field( int $term_id, string $reference ): mixed {
        if ( \str_starts_with( $reference, '_meta|' ) ) {
            $key = \substr( $reference, \strlen( '_meta|' ) );
            // Symmetric with read_post_field: go through the Translatable model so
            // any term meta-read filter applies (mirrors the post path).
            return \PLLAT\Translator\Models\Translatables\Translatable_Term::get_instance( $term_id )->get_meta( $key, true );
        }
        $term = \get_term( $term_id );
        if ( ! $term instanceof \WP_Term ) {
            return '';
        }
        return $term->{$reference} ?? '';
    }

    private function is_empty_value( mixed $value ): bool {
        if ( null === $value ) {
            return true;
        }
        if ( \is_string( $value ) && '' === $value ) {
            return true;
        }
        if ( \is_array( $value ) && \count( $value ) === 0 ) {
            return true;
        }

        // JSON-encoded empty containers: plugins like Yoast store empty meta
        // as `"[]"` or `"[\"\"]"`. Without this branch, gap computation
        // treats those strings as non-empty, the worker calls the AI on
        // semantically empty input, the activity log accumulates "completed"
        // sub-iterations, and tokens are wasted at scale.
        if ( \is_string( $value ) ) {
            $first_char = $value[0] ?? '';
            if ( '[' === $first_char || '{' === $first_char ) {
                $decoded = \json_decode( $value, true );
                if ( \is_array( $decoded ) && array() === \array_filter( $decoded ) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
