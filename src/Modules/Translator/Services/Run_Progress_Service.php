<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Translator\Models\Run_Spec;

/**
 * Per-run unit counts derived from activity_log and claims.
 *
 * Unit = distinct (source_kind, source_id, target_lang) triplet. A skipped
 * unit is its own bucket: it is done, but it is neither completed nor failed.
 */
class Run_Progress_Service {
    /**
     * Count the units of a run by outcome.
     *
     * @param int $run_id Run ID.
     * @return array{processed:int, completed:int, skipped:int, failed:int, pending:int, in_progress:int}
     */
    public function counts_for_run( int $run_id ): array {
        global $wpdb;

        $log_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT CONCAT(source_kind,'|',source_id,'|',target_lang)) AS processed,
                    COUNT(DISTINCT IF(status='completed', CONCAT(source_kind,'|',source_id,'|',target_lang), NULL)) AS completed_units,
                    COUNT(DISTINCT IF(status IN ('completed','skipped'), CONCAT(source_kind,'|',source_id,'|',target_lang), NULL)) AS done_units
                 FROM {$wpdb->prefix}pllat_activity_log WHERE run_id = %d",
                $run_id,
            ),
            \ARRAY_A,
        );

        // A claim parked between batches or waiting to resume (pending with a
        // batch_state) has started: count it as in progress so pending means not started.
        $claim_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(status='in_progress' OR (status='pending' AND batch_state IS NOT NULL AND batch_state <> '')) AS in_progress,
                        SUM(status='pending' AND (batch_state IS NULL OR batch_state = '')) AS pending
                 FROM {$wpdb->prefix}pllat_claims WHERE run_id = %d",
                $run_id,
            ),
            \ARRAY_A,
        );

        $processed = (int) ( $log_row['processed'] ?? 0 );
        $completed = (int) ( $log_row['completed_units'] ?? 0 );
        $skipped   = (int) ( $log_row['done_units'] ?? 0 ) - $completed;

        return array(
            'processed'   => $processed,
            'completed'   => $completed,
            'skipped'     => $skipped,
            'failed'      => $processed - $completed - $skipped,
            'pending'     => (int) ( $claim_row['pending'] ?? 0 ),
            'in_progress' => (int) ( $claim_row['in_progress'] ?? 0 ),
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
     *
     * @param Run_Spec $spec Run spec.
     * @return int|null
     */
    public function count_source_posts_in_scope( Run_Spec $spec ): ?int {
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
}
