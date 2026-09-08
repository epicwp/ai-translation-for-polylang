<?php
declare(strict_types=1);

namespace PLLAT\Status\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;

/**
 * Compares the translation index against Polylang's own translation map and
 * reports the delta per source kind.
 *
 * Why: the index is a cache of Polylang's reality. When a write-through path is
 * missed (as happened with media — Polylang had the translations, the index did
 * not), the dashboard silently under-reports and nothing flags it. This service
 * is the proactive detector: for each kind it computes how many directional
 * pairs Polylang actually has versus how many the index holds, and surfaces the
 * shortfall.
 *
 * Truth model: Polylang stores each translation group as a term in the
 * `post_translations` / `term_translations` taxonomy, whose description is a
 * serialized `lang => object_id` map. A group spanning k languages corresponds
 * to k·(k-1) directional index rows (each member is a source against the other
 * k-1 languages) — exactly how the index and the prime walk store them. Summing
 * k·(k-1) over all groups yields the expected index row count.
 *
 * Read-only. Potentially heavier than the rest of the report on large sites
 * (it scans the translation-group descriptions), so it is opt-in via
 * `?include=coverage` rather than part of the default report.
 */
class Index_Coverage_Service {

    private const POST_TAXONOMY = 'post_translations';
    private const TERM_TAXONOMY = 'term_translations';

    public function __construct(
        private Translation_Index_Repository $index,
    ) {}

    /**
     * @return array{
     *   post: array{polylang_pairs:int, index_rows:int, missing:int},
     *   term: array{polylang_pairs:int, index_rows:int, missing:int},
     *   outdated_backlog:int,
     *   in_sync:bool
     * }
     */
    public function compute(): array {
        $index_by_kind = $this->index->count_by_kind();

        $post = $this->kind_coverage( self::POST_TAXONOMY, (int) $index_by_kind['post'] );
        $term = $this->kind_coverage( self::TERM_TAXONOMY, (int) $index_by_kind['term'] );

        return array(
            'post'             => $post,
            'term'             => $term,
            'outdated_backlog' => $this->index->count_outdated(),
            'in_sync'          => 0 === $post['missing'] && 0 === $term['missing'],
        );
    }

    /**
     * @return array{polylang_pairs:int, index_rows:int, missing:int}
     */
    private function kind_coverage( string $taxonomy, int $index_rows ): array {
        $polylang_pairs = $this->polylang_pairs( $taxonomy );
        return array(
            'polylang_pairs' => $polylang_pairs,
            'index_rows'     => $index_rows,
            'missing'        => \max( 0, $polylang_pairs - $index_rows ),
        );
    }

    /**
     * Directional pair count Polylang actually has for a translation taxonomy.
     */
    private function polylang_pairs( string $taxonomy ): int {
        global $wpdb;
        $descriptions = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy,
            ),
        );

        return self::pairs_for_descriptions( (array) $descriptions );
    }

    /**
     * Sum of k·(k-1) over each serialized translation-group description.
     *
     * Pure (no DB) so the core counting logic is unit-testable independent of
     * Polylang being installed.
     *
     * @param array<int, string|null> $descriptions Serialized `lang => id` maps.
     */
    public static function pairs_for_descriptions( array $descriptions ): int {
        $total = 0;
        foreach ( $descriptions as $description ) {
            if ( ! \is_string( $description ) || '' === $description ) {
                continue;
            }
            $map = \maybe_unserialize( $description );
            if ( ! \is_array( $map ) ) {
                continue;
            }
            // Count only real, distinct language targets (ids > 0).
            $langs = 0;
            foreach ( $map as $object_id ) {
                if ( (int) $object_id > 0 ) {
                    ++$langs;
                }
            }
            $total += $langs * ( $langs - 1 );
        }
        return $total;
    }
}
