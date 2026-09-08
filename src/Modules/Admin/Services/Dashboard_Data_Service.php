<?php
declare(strict_types=1);

namespace PLLAT\Admin\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Traits\Run_Progress_Trait;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use PLLAT\Translator\Repositories\Run_Spec_Repository;

/**
 *
 * @package PLLAT\Admin\Services
 */
class Dashboard_Data_Service {
    use Run_Progress_Trait;

    /**
     * Constructor.
     */
    public function __construct(
        private Language_Manager $language_manager,
        private Run_Spec_Repository $run_spec_repository,
        private Translation_Index_Repository $translation_index,
    ) {
    }

    /**
     * Transient cache key for dashboard stats.
     */
    private const CACHE_KEY = 'pllat_dashboard_stats';

    /**
     * Cache TTL in seconds (invalidated earlier by hooks).
     */
    private const CACHE_TTL = 30;

    /**
     * Get dashboard-formatted statistics.
     *
     * During active translations the cache is bypassed entirely so that
     * language counts (e.g. 22/23 → 23/23) update on every poll rather
     * than lagging behind the transient TTL. When idle the 30 s cache
     * protects against expensive re-computation on every page load.
     *
     * @return array<string, mixed> Dashboard-ready data structure.
     */
    public function get_dashboard_data(): array {
        $active_runs = $this->run_spec_repository->find_active();

        if ( count( $active_runs ) > 0 ) {
            // Pass the already-fetched runs so compute_dashboard_data()
            // does not need to query them again.
            return $this->compute_dashboard_data( $active_runs );
        }

        $cached = \get_transient( self::CACHE_KEY );

        if ( false !== $cached && \is_array( $cached ) ) {
            return $this->refresh_volatile_data( $cached );
        }

        $data = $this->compute_dashboard_data( array() );

        \set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

        return $data;
    }

    /**
     * Invalidate the cached dashboard stats.
     *
     * Call this when job/run statuses change (cascade, completion, etc.).
     *
     * @return void
     */
    public static function invalidate_cache(): void {
        \delete_transient( self::CACHE_KEY );
    }

    /**
     * Compute the full dashboard data from scratch.
     *
     * Per-(kind × subtype × lang) totals come from translation_index
     * (a single indexed aggregate query) instead of joins on pllat_claims.
     *
     * @return array<string, mixed> Dashboard-ready data structure.
     */
    /**
     * @param array<int, array<string, mixed>> $active_runs Pre-fetched active runs (avoids a second find_active() call).
     */
    private function compute_dashboard_data( array $active_runs ): array {
        $source_lang = $this->language_manager->get_default_language();
        $index_rows  = $this->translation_index->aggregate_for_dashboard( $source_lang );
        $stats       = $this->build_stats_from_index( $index_rows, $source_lang );
        $overall     = $this->compute_overall_progress( $stats );

        // Get full language data (with names) and filter out source language.
        $all_languages    = $this->language_manager->get_languages_data();
        $target_languages = \array_values(
            \array_filter(
                $all_languages,
                static fn( $lang ) => $lang['slug'] !== $source_lang,
            ),
        );

        $has_active_translations = count( $active_runs ) > 0;

        return array(
            'contentTypes'    => $this->format_content_types( $stats, $active_runs ),
            'discovery'       => array(
                'discovering' => false,
                'has_posts'   => false,
                'has_terms'   => false,
                'needed'      => false,
                'suppressed'  => $has_active_translations,
            ),
            'lastUpdated'     => \time(),
            'overallProgress' => $overall,
            'targetLanguages' => $target_languages,
        );
    }

    /**
     * Reshape flat index aggregate rows into the stats array expected by format_content_types().
     *
     * Input row shape: { source_kind, content_subtype, source_lang, target_lang, total, done }
     * Output shape:
     *   posts[subtype][total]              = int
     *   posts[subtype][by_language][lang]  = { total, translated, waiting, never_translated, breakdown }
     *   terms[subtype][...]                = same
     *
     * @param array<int, array<string, mixed>> $rows       Rows from aggregate_for_dashboard().
     * @param string                           $source_lang The source language slug.
     * @return array{ posts: array, terms: array, source_language: string } Stats shaped for format_content_types().
     */
    private function build_stats_from_index( array $rows, string $source_lang ): array {
        $posts = array();
        $terms = array();

        foreach ( $rows as $row ) {
            $kind    = $row['source_kind'];
            $subtype = $row['content_subtype'];
            $lang    = $row['target_lang'];
            $total   = $row['total'];
            $done    = $row['done'];

            if ( 'post' === $kind ) {
                if ( ! isset( $posts[ $subtype ] ) ) {
                    $posts[ $subtype ] = array( 'by_language' => array(), 'total' => $total );
                } else {
                    $posts[ $subtype ]['total'] = \max( $posts[ $subtype ]['total'], $total );
                }
                $posts[ $subtype ]['by_language'][ $lang ] = array(
                    'breakdown'        => array( 'failed' => 0, 'pending' => 0 ),
                    'never_translated' => \max( 0, $total - $done ),
                    'total'            => $total,
                    'translated'       => $done,
                    'waiting'          => 0,
                );
            } else {
                if ( ! isset( $terms[ $subtype ] ) ) {
                    $terms[ $subtype ] = array( 'by_language' => array(), 'total' => $total );
                } else {
                    // Multiple target langs — keep the largest total (source count is identical
                    // for all langs but may differ slightly during a partial backfill).
                    $terms[ $subtype ]['total'] = \max( $terms[ $subtype ]['total'], $total );
                }
                $terms[ $subtype ]['by_language'][ $lang ] = array(
                    'breakdown'        => array( 'failed' => 0, 'pending' => 0 ),
                    'never_translated' => \max( 0, $total - $done ),
                    'total'            => $total,
                    'translated'       => $done,
                    'waiting'          => 0,
                );
            }
        }

        return array(
            'posts'           => $posts,
            'source_language' => $source_lang,
            'terms'           => $terms,
        );
    }

    /**
     * Compute overall progress from the index-based stats array.
     *
     * @param array<string, mixed> $stats Output of build_stats_from_index().
     * @return array<string, mixed> Overall progress.
     */
    private function compute_overall_progress( array $stats ): array {
        $total_items      = 0;
        $total_translated = 0;
        $total_waiting    = 0;

        foreach ( array( $stats['posts'], $stats['terms'] ) as $kind_data ) {
            foreach ( $kind_data as $ct_data ) {
                $total_items += $ct_data['total'];
                foreach ( $ct_data['by_language'] as $lang_data ) {
                    $total_translated += $lang_data['translated'];
                    $total_waiting    += $lang_data['waiting'];
                }
            }
        }

        $target_lang_count           = \count( $this->language_manager->get_available_languages( exclude_default: true ) );
        $total_possible_translations = $total_items * ( $target_lang_count ?: 1 );
        $total_never_translated      = $total_possible_translations - $total_translated - $total_waiting;

        $percentage_complete = $total_possible_translations > 0
            ? \round( $total_translated / $total_possible_translations * 100, 2 )
            : 0.0;

        return array(
            'percentage_complete'         => $percentage_complete,
            'total_items'                 => $total_items,
            'total_never_translated'      => \max( 0, $total_never_translated ),
            'total_possible_translations' => $total_possible_translations,
            'total_translated'            => $total_translated,
            'total_waiting'               => $total_waiting,
        );
    }

    /**
     * Refresh volatile parts of cached dashboard data.
     *
     * Run progress and discovery state change frequently during active
     * translations. These are recomputed live on every poll.
     *
     * @param array<string, mixed> $cached Previously cached data.
     * @return array<string, mixed> Data with refreshed volatile fields.
     */
    private function refresh_volatile_data( array $cached ): array {
        $active_runs             = $this->run_spec_repository->find_active();
        $has_active_translations = count( $active_runs ) > 0;

        $translation_data = $this->map_runs_to_content_type_states( $active_runs );

        foreach ( $cached['contentTypes'] as $content_type => &$data ) {
            $run_id       = $translation_data['run_ids'][ $content_type ] ?? null;
            $run_progress = null;

            if ( null !== $run_id ) {
                $run_progress = $this->compute_run_progress( $run_id, $translation_data['started_at'][ $content_type ] ?? 0 );
            }

            $data['runId']            = $run_id;
            $data['runProgress']      = $run_progress;
            $data['translationState'] = $translation_data['states'][ $content_type ] ?? 'idle';

            $hash_data = $data;
            if ( \is_array( $hash_data['runProgress'] ) ) {
                unset( $hash_data['runProgress']['elapsedTime'], $hash_data['runProgress']['estimatedTime'], $hash_data['runProgress']['startedAt'] );
            }
            $data['hash'] = \md5( \wp_json_encode( $hash_data ) );
        }
        unset( $data );

        $cached['discovery'] = array(
            'discovering' => false,
            'has_posts'   => false,
            'has_terms'   => false,
            'needed'      => false,
            'suppressed'  => $has_active_translations,
        );

        $cached['lastUpdated'] = \time();

        return $cached;
    }

    /**
     * Map active runs to content-type → UI state.
     *
     * @param array<int, array<string, mixed>> $runs Hydrated run rows from Run_Spec_Repository.
     * @return array{
     *   states: array<string, string>,
     *   run_ids: array<string, int>,
     *   started_at: array<string, int>
     * }
     */
    private function map_runs_to_content_type_states( array $runs ): array {
        $states     = array();
        $run_ids    = array();
        $started_at = array();

        foreach ( $runs as $run ) {
            $state                                    = $this->status_to_ui_state( (string) $run['status'] );
            list( $post_types, $taxonomies )          = $this->run_spec_repository->content_types_for_run( $run['spec'] );
            $content_types                            = \array_merge( $post_types, $taxonomies );

            foreach ( $content_types as $content_type ) {
                $states[ $content_type ]     = $this->merge_states( $states[ $content_type ] ?? 'idle', $state );
                $run_ids[ $content_type ]    = (int) $run['id'];
                $started_at[ $content_type ] = (int) $run['started_at'];
            }
        }

        return array(
            'run_ids'    => $run_ids,
            'started_at' => $started_at,
            'states'     => $states,
        );
    }

    private function status_to_ui_state( string $status ): string {
        return match ( $status ) {
            'pending'    => 'pending',
            'processing' => 'translating',
            'cancelled'  => 'cancelled',
            'failed'     => 'failed',
            default      => 'idle',
        };
    }

    /**
     * Build run-progress payload from activity_log + claims for a run.
     *
     * Unit = distinct (source_kind, source_id, target_lang) triplet.
     * Completed units: triplets with at least one 'completed' field in activity_log.
     * Active units: rows in pllat_claims still pending or in_progress.
     * Total: limit × target_lang_count when spec has a limit; else dynamic sum.
     *
     * @return array<string, mixed>|null
     */
    private function compute_run_progress( int $run_id, int $started_at ): ?array {
        global $wpdb;

        $log_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT CONCAT(source_kind,'|',source_id,'|',target_lang)) AS processed,
                    COUNT(DISTINCT IF(status='completed', CONCAT(source_kind,'|',source_id,'|',target_lang), NULL)) AS completed_units
                 FROM {$wpdb->prefix}pllat_activity_log WHERE run_id = %d",
                $run_id,
            ),
            \ARRAY_A,
        );

        $claim_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(status='in_progress') AS in_progress, SUM(status='pending') AS pending
                 FROM {$wpdb->prefix}pllat_claims WHERE run_id = %d",
                $run_id,
            ),
            \ARRAY_A,
        );

        $processed   = (int) ( $log_row['processed'] ?? 0 );
        $completed   = (int) ( $log_row['completed_units'] ?? 0 );
        $failed      = $processed - $completed;
        $in_progress = (int) ( $claim_row['in_progress'] ?? 0 );
        $pending     = (int) ( $claim_row['pending'] ?? 0 );

        $run   = $this->run_spec_repository->find_by_id( $run_id );
        $limit = $run ? $run['spec']->limit() : null;

        // Unlimited runs have no knowable total — discovery streams candidates
        // as it goes, so any "completed/total" we'd show grows in lockstep with
        // completed and is meaningless ("1/2 → 2/3 → 3/4 …"). Suppress the
        // payload so the frontend falls back to the plain "Translating…" label.
        if ( null === $limit ) {
            return null;
        }

        // The limit caps SOURCE POSTS, not units. When fewer in-scope source
        // posts exist than the limit (e.g. limit 3 but only 1 post), the run can
        // never reach limit × langs units — it showed "2/9" for a 1-post × 3-lang
        // run. Cap the post factor at the actual in-scope source-post count. The
        // count is independent of translation state, so the denominator is
        // stable (no flicker between worker units).
        $lang_count   = \max( 1, \count( $run['spec']->target_languages() ) );
        $source_posts = $this->count_source_posts_in_scope( $run['spec'] );
        $post_factor  = null === $source_posts ? $limit : \min( $limit, $source_posts );

        $total = $post_factor * $lang_count;
        if ( 0 === $total ) {
            return null;
        }

        return $this->build_run_progress_data(
            array(
                'total'       => $total,
                'completed'   => $completed,
                'failed'      => $failed,
                'pending'     => $pending,
                'in_progress' => $in_progress,
            ),
            $started_at,
        );
    }

    /**
     * Count the source posts a run could translate, for the progress
     * denominator. Mirrors Job_Claim_Service's candidate scope: posts of the
     * run's post_type(s), in a translatable status, in the run's source
     * language (Polylang `language` taxonomy), optionally limited to the spec's
     * id_list. Counts source posts only — not units — and ignores translation
     * state, so the denominator is stable across polls.
     *
     * Returns null when the run has no post_query (term-only run) so the caller
     * keeps the limit-based denominator unchanged.
     */
    private function count_source_posts_in_scope( \PLLAT\Translator\Models\Run_Spec $spec ): ?int {
        $query = $spec->post_query();
        if ( null === $query ) {
            return null;
        }
        $post_types = ( isset( $query['post_types'] ) && \is_array( $query['post_types'] ) )
            ? \array_values( \array_map( 'strval', $query['post_types'] ) )
            : array();
        $source_lang = (string) ( $query['source_lang'] ?? '' );
        if ( 0 === \count( $post_types ) || '' === $source_lang ) {
            return null;
        }

        global $wpdb;
        $pt_ph         = \implode( ',', \array_fill( 0, \count( $post_types ), '%s' ) );
        $post_statuses = Helpers::post_statuses_for( $post_types );
        $ps_ph         = \implode( ',', \array_fill( 0, \count( $post_statuses ), '%s' ) );

        $id_clause  = '';
        $id_params  = array();
        if ( isset( $query['id_list'] ) && \is_array( $query['id_list'] ) && \count( $query['id_list'] ) > 0 ) {
            $id_ph     = \implode( ',', \array_fill( 0, \count( $query['id_list'] ), '%d' ) );
            $id_clause = "AND p.ID IN ({$id_ph}) ";
            $id_params = \array_map( 'intval', \array_values( $query['id_list'] ) );
        }

        $sql = "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} ll_tax ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id AND ll_tax.taxonomy = 'language'
            INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
            WHERE p.post_type IN ({$pt_ph})
              AND p.post_status IN ({$ps_ph})
              AND ll.slug = %s
              {$id_clause}";

        $params = \array_merge( $post_types, $post_statuses, array( $source_lang ), $id_params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above; values in $params.
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    /**
     * Merge two states, giving priority to more active states.
     * Priority: translating > pending > cancelled > failed > idle.
     */
    private function merge_states( string $current_state, string $new_state ): string {
        $priorities = array(
            'cancelled'   => 3,
            'failed'      => 2,
            'idle'        => 1,
            'pending'     => 4,
            'translating' => 5,
        );

        $current_priority = $priorities[ $current_state ] ?? 1;
        $new_priority     = $priorities[ $new_state ] ?? 1;

        return $current_priority >= $new_priority ? $current_state : $new_state;
    }

    /**
     * Format content types for dashboard UI.
     *
     * Ordering: post types follow \get_post_types() registration order
     * ("post" and "page" first, then customs like "product",
     * "product_variation", …), matching the pre-lean dashboard layout
     * that users built muscle memory around. Index-row order (raw SQL
     * insertion order) is not a stable axis to sort on.
     *
     * @param array<string, mixed> $stats       The statistics data.
     * @param array                $active_runs Active runs (pending/running).
     * @return array<string, mixed> Formatted content types.
     */
    private function format_content_types( array $stats, array $active_runs ): array {
        $formatted        = array();
        $translation_data = $this->map_runs_to_content_type_states( $active_runs );

        foreach ( $this->order_subtypes( \array_keys( $stats['posts'] ), 'post' ) as $content_type ) {
            $formatted[ $content_type ] = $this->format_content_type(
                'post',
                $content_type,
                $stats['posts'][ $content_type ],
                $translation_data,
            );
        }

        foreach ( $this->order_subtypes( \array_keys( $stats['terms'] ), 'term' ) as $content_type ) {
            $formatted[ $content_type ] = $this->format_content_type(
                'term',
                $content_type,
                $stats['terms'][ $content_type ],
                $translation_data,
            );
        }

        return $formatted;
    }

    /**
     * Sort content subtypes by WP registration order.
     *
     * Subtypes present in stats but unknown to WP (e.g. removed post type
     * still has stale index rows) are appended at the end in their
     * existing order so they remain visible without breaking the canonical
     * sort.
     *
     * @param array<int, string> $subtypes Subtype slugs from the stats array.
     * @param string             $kind     'post' or 'term'.
     * @return array<int, string> Ordered subtype slugs.
     */
    private function order_subtypes( array $subtypes, string $kind ): array {
        $canonical = 'post' === $kind
            ? \array_values( \get_post_types( array(), 'names' ) )
            : \array_values( \get_taxonomies( array(), 'names' ) );

        $present    = \array_flip( $subtypes );
        $ordered    = array();
        foreach ( $canonical as $name ) {
            if ( isset( $present[ $name ] ) ) {
                $ordered[] = $name;
                unset( $present[ $name ] );
            }
        }
        foreach ( $subtypes as $name ) {
            if ( isset( $present[ $name ] ) ) {
                $ordered[] = $name;
            }
        }
        return $ordered;
    }

    /**
     * Helper methods to format specific content type
     *
     * @param string $type post or term
     * @param string $content_type post type or taxonomt name
     * @param array  $data data for this content
     * @return array
     */
    private function format_content_type( string $type, string $content_type, array $stats_data, array $translation_data ): array {
        $run_id     = $translation_data['run_ids'][ $content_type ] ?? null;
        $started_at = $translation_data['started_at'][ $content_type ] ?? 0;

        $run_progress = null;
        if ( null !== $run_id ) {
            $run_progress = $this->compute_run_progress( (int) $run_id, (int) $started_at );
        }

        $data = array(
            'icon'             => 'post' === $type ? 'dashicons-admin-post' : 'dashicons-category',
            'label'            => $this->get_content_type_label( $content_type ),
            'singularLabel'    => $this->get_content_type_singular_label( $content_type ),
            'languages'        => $this->format_language_stats( $stats_data['by_language'] ),
            'runId'            => $run_id,
            'runProgress'      => $run_progress,
            'total'            => $stats_data['total'],
            'translationState' => $translation_data['states'][ $content_type ] ?? 'idle',
            'type'             => $type,
        );

        // Generate hash from stable fields only (exclude time-based runProgress fields).
        $hash_data = $data;
        if ( \is_array( $hash_data['runProgress'] ) ) {
            unset( $hash_data['runProgress']['elapsedTime'], $hash_data['runProgress']['estimatedTime'], $hash_data['runProgress']['startedAt'] );
        }
        $data['hash'] = \md5( \wp_json_encode( $hash_data ) );

        return $data;
    }

    /**
     * Format language statistics to camelCase for frontend.
     *
     * Output preserves Polylang's configured language order
     * (pll_languages_list), so per-card language rows line up with the
     * bulk-config modal's language list — the user's "most important first"
     * preference flows through both surfaces from a single source of truth.
     *
     * @param array<string, mixed> $languages Language statistics keyed by slug.
     * @return array<string, mixed> Formatted, language-ordered statistics.
     */
    private function format_language_stats( array $languages ): array {
        $canonical_order = $this->language_manager->get_available_languages( exclude_default: true );

        $formatted = array();
        foreach ( $canonical_order as $lang_code ) {
            if ( ! isset( $languages[ $lang_code ] ) ) {
                continue;
            }
            $formatted[ $lang_code ] = $this->shape_language_stats( $languages[ $lang_code ] );
        }

        // Fallback: any language present in stats but missing from Polylang
        // (e.g. just-removed language with stale index rows) tails the list
        // so it stays visible until cleanup.
        foreach ( $languages as $lang_code => $stats ) {
            if ( isset( $formatted[ $lang_code ] ) ) {
                continue;
            }
            $formatted[ $lang_code ] = $this->shape_language_stats( $stats );
        }

        return $formatted;
    }

    /**
     * Reshape a raw per-language stats row to camelCase.
     *
     * @param array<string, mixed> $stats Raw stats.
     * @return array<string, mixed> Frontend-shaped stats.
     */
    private function shape_language_stats( array $stats ): array {
        return array(
            'breakdown'       => $stats['breakdown'] ?? array(),
            'neverTranslated' => $stats['never_translated'] ?? 0,
            'total'           => $stats['total'] ?? 0,
            'translated'      => $stats['translated'] ?? 0,
            'waiting'         => $stats['waiting'] ?? 0,
        );
    }

    /**
     * Get human-readable label for content type.
     *
     * @param string $content_type The content type slug.
     * @return string The human-readable label.
     */
    private function get_content_type_label( string $content_type ): string {
        $post_type_obj = \get_post_type_object( $content_type );
        if ( null !== $post_type_obj && isset( $post_type_obj->labels->name ) ) {
            return $post_type_obj->labels->name;
        }

        $taxonomy_obj = \get_taxonomy( $content_type );
        if ( false !== $taxonomy_obj && isset( $taxonomy_obj->labels->name ) ) {
            return $taxonomy_obj->labels->name;
        }

        return \ucfirst( \str_replace( '_', ' ', $content_type ) );
    }

    /**
     * Get human-readable singular label for content type.
     *
     * @param string $content_type The content type slug.
     * @return string The human-readable singular label.
     */
    private function get_content_type_singular_label( string $content_type ): string {
        $post_type_obj = \get_post_type_object( $content_type );
        if ( null !== $post_type_obj && isset( $post_type_obj->labels->singular_name ) ) {
            return $post_type_obj->labels->singular_name;
        }

        $taxonomy_obj = \get_taxonomy( $content_type );
        if ( false !== $taxonomy_obj && isset( $taxonomy_obj->labels->singular_name ) ) {
            return $taxonomy_obj->labels->singular_name;
        }

        return \ucfirst( \str_replace( '_', ' ', $content_type ) );
    }
}
