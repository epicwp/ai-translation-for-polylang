<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Translator\Models\Run_Spec;

/**
 * Atomic claim of (source_kind, source_id, target_lang) work units.
 *
 * Worker calls claim_next_unit(run_id, spec) to acquire a unit. The claim
 * is implemented as INSERT ... ON DUPLICATE KEY UPDATE on jobs.claim_unit
 * unique key — concurrent workers cannot both win the same tuple.
 *
 * Returns the unit on success, null when no candidates remain.
 */
class Job_Claim_Service {
    /**
     * In-memory candidate batch, keyed by "run_id:kind". find_*_candidates()
     * fetches 50 rows via the heavy candidate JOIN but a single
     * claim_next_unit() call consumes only one; the remainder is cached here so
     * the JOIN runs once per batch instead of once per claimed unit (the worker
     * loops up to MAX_CLAIMS_PER_INVOCATION times per invocation). Scoped to one
     * worker invocation (this service is built once per Action Scheduler action)
     * and re-fetched whenever the cache empties. Serving a stale entry is safe:
     * a tuple claimed elsewhere fails the atomic INSERT and is skipped; a tuple
     * already translated yields zero gap fields and is released as a clean no-op
     * by Lean_Job_Worker::process_unit() (no AI call, no failure).
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $candidate_cache = array();

    /**
     * @return array{source_kind:string, source_id:int, source_lang:string, target_lang:string, job_id:int}|null
     */
    public function claim_next_unit( int $run_id, Run_Spec $spec ): ?array {
        // One post at a time: always FINISH an in-flight post (a parked
        // batch-continuation — a 'pending' claim that carries batch_state)
        // before opening a new one. This keeps a started translation moving
        // to completion instead of fanning out across many posts with no
        // visible progress, and is gentle on low provider tiers. A unit
        // that only ever FAILED has no batch_state, so it is NOT prioritised
        // here — that preserves the anti-blocking property below.
        $unit = $this->try_resume_continuation( $run_id );
        if ( null !== $unit ) {
            return $unit;
        }

        if ( $this->limit_reached( $run_id, $spec ) ) {
            return $this->try_reclaim_pending( $run_id );
        }
        $unit = $this->try_claim( $run_id, 'post', $spec->post_query(), $spec );
        if ( null !== $unit ) {
            return $unit;
        }
        $unit = $this->try_claim( $run_id, 'term', $spec->term_query(), $spec );
        if ( null !== $unit ) {
            return $unit;
        }
        // No in-flight continuation and no fresh candidates left. Only NOW
        // fall back to retrying the remaining pending claims (failure
        // retries with attempts < MAX). Revisiting these last prevents one
        // consistently-failing post from blocking the whole run for
        // MAX_ATTEMPTS × worker_runtime.
        return $this->try_reclaim_pending( $run_id );
    }

    /**
     * Deterministic existence check: is there any (post|term, target_lang)
     * tuple that this run could still claim?
     *
     * Used by Pipeline_Tick_Handler / Run_Completion_Service to decide
     * completion without relying on a "quiet moment" between parallel worker
     * actions. A run with 0 open claims AND has_remaining_candidates() === false
     * is provably complete.
     *
     * Mirrors claim_next_unit's eligibility logic (limit cap + force_clause +
     * "claim not held" filter) but stops at LIMIT 1 for cheapness and skips
     * the INSERT side effect.
     */
    public function has_remaining_candidates( int $run_id, Run_Spec $spec ): bool {
        if ( $this->limit_reached( $run_id, $spec ) ) {
            // Limit is exhausted, but a parked pending claim could still
            // be reactivated by try_reclaim_pending. Treat that as work.
            return $this->has_pending_reclaimable( $run_id );
        }

        $post_query = $spec->post_query();
        if ( null !== $post_query && $this->count_post_candidates( $run_id, $post_query, $spec, 1 ) > 0 ) {
            return true;
        }
        $term_query = $spec->term_query();
        if ( null !== $term_query && $this->count_term_candidates( $run_id, $term_query, $spec, 1 ) > 0 ) {
            return true;
        }
        // Fresh candidates exhausted — but pending claims (batch continuation,
        // retryable failures) still count as remaining work since
        // claim_next_unit falls back to try_reclaim_pending after fresh ones.
        return $this->has_pending_reclaimable( $run_id );
    }

    private function has_pending_reclaimable( int $run_id ): bool {
        global $wpdb;
        return 1 === (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}pllat_claims WHERE run_id = %d AND status = 'pending' LIMIT 1",
                $run_id,
            ),
        );
    }

    /**
     * Fetch the run's started_at timestamp (unix). Returns 0 if the run row
     * is missing — callers must treat 0 as "no run-scoped windowing".
     */
    private function get_run_started_at( int $run_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT started_at FROM {$wpdb->prefix}pllat_bulk_runs WHERE id = %d",
                $run_id,
            ),
        );
    }

    /**
     * Cap on candidate (source_id) count for this run. Counts already-claimed
     * source_ids (in pllat_claims for this run) plus any post/term that has
     * been translated since the run started (last_synced >= runs.started_at).
     * When the count reaches spec.limit, no more candidates are claimed —
     * effectively "translate the first N items".
     */
    private function limit_reached( int $run_id, Run_Spec $spec ): bool {
        $limit = $spec->limit();
        if ( null === $limit || $limit <= 0 ) {
            return false;
        }
        $target_langs = $spec->target_languages();
        if ( 0 === \count( $target_langs ) ) {
            return false;
        }
        global $wpdb;
        $started_at = $this->get_run_started_at( $run_id );

        // Distinct source_ids that count toward the limit: those already claimed
        // in this run, UNION those synced to a run target lang since the run
        // started (claims are deleted on success, so translation_index is the
        // durable record). Counted in one aggregate query rather than shipping
        // both id sets into PHP and array_unique-ing them — this runs up to 50x
        // per worker invocation plus every tick. The synced half is served by
        // the last_synced (last_synced, target_lang) index.
        $tl_ph = \implode( ',', \array_fill( 0, \count( $target_langs ), '%s' ) );

        if ( $started_at > 0 ) {
            $sql    = "SELECT COUNT(*) FROM (
                        SELECT source_id FROM {$wpdb->prefix}pllat_claims WHERE run_id = %d
                        UNION
                        SELECT source_id FROM {$wpdb->prefix}pllat_translation_index
                         WHERE last_synced >= FROM_UNIXTIME(%d) AND target_lang IN ({$tl_ph})
                       ) u";
            $params = \array_merge( array( $run_id, $started_at ), $target_langs );
        } else {
            $sql    = "SELECT COUNT(DISTINCT source_id) FROM {$wpdb->prefix}pllat_claims WHERE run_id = %d";
            $params = array( $run_id );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $count = (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
        return $count >= $limit;
    }

    /**
     * Resume a parked batch-continuation: a 'pending' claim that carries
     * batch_state, i.e. a post whose translation is already in flight and
     * partially done. Picked BEFORE any fresh candidate so a started post
     * is finished before a new one is opened (one post at a time). Returns
     * null when no in-flight continuation exists for this run.
     *
     * @param int $run_id Run to resume a continuation for.
     * @return array<string, mixed>|null
     */
    private function try_resume_continuation( int $run_id ): ?array {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';

        $this->reclaim_dead_in_progress( $run_id );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; run_id is prepared.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, source_kind, source_id, source_lang, target_lang, content_subtype
                 FROM {$claims_table}
                 WHERE run_id = %d AND status = 'pending'
                   AND batch_state IS NOT NULL AND batch_state <> ''
                 LIMIT 1",
                $run_id,
            ),
            \ARRAY_A,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( null === $row ) {
            return null;
        }
        return $this->reclaim_row( $run_id, $row );
    }

    /**
     * Re-activate one remaining pending claim (a failure retry whose
     * attempts are still under MAX, or — when the run limit is reached —
     * a parked unit). Called only after in-flight continuations and fresh
     * candidates are exhausted, so a consistently-failing post is revisited
     * last and cannot block the run for MAX_ATTEMPTS × worker_runtime.
     *
     * @param int $run_id Run to reclaim a pending claim for.
     * @return array<string, mixed>|null
     */
    private function try_reclaim_pending( int $run_id ): ?array {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';

        $this->reclaim_dead_in_progress( $run_id );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; run_id is prepared.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, source_kind, source_id, source_lang, target_lang, content_subtype
                 FROM {$claims_table}
                 WHERE run_id = %d AND status = 'pending'
                 LIMIT 1",
                $run_id,
            ),
            \ARRAY_A,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( null === $row ) {
            return null;
        }
        return $this->reclaim_row( $run_id, $row );
    }

    /**
     * A worker that died mid-claim leaves the row stuck 'in_progress'.
     * Liveness is derived from the Action Scheduler action that owns the
     * claim (AS is the system of record for "is the worker alive" and is
     * a hard plugin requirement). A claim whose owning action is no
     * longer live — failed, completed, cancelled, or pruned away — is
     * abandoned back to 'pending' WITHOUT burning an attempt (a crash is
     * not a translation failure). A claim with no recorded owner
     * (as_action_id 0/NULL — only possible outside an AS action, i.e.
     * tests) is left alone: the owner cannot be proven dead.
     *
     * @param int $run_id Run whose dead in-progress claims to abandon.
     */
    private function reclaim_dead_in_progress( int $run_id ): void {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';
        $as_actions   = $wpdb->actionscheduler_actions;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix; run_id is prepared.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$claims_table} c
                 LEFT JOIN {$as_actions} a ON a.action_id = c.as_action_id
                 SET c.status = 'pending'
                 WHERE c.run_id = %d
                   AND c.status = 'in_progress'
                   AND c.as_action_id IS NOT NULL AND c.as_action_id > 0
                   AND ( a.action_id IS NULL OR a.status NOT IN ('pending','in-progress') )",
                $run_id,
            ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Re-acquire a pending claim row (hand ownership to the calling
     * worker's AS action via attempt_insert) and shape it into a work
     * unit. Returns null when another worker won the row first.
     *
     * @param int                  $run_id Run the row belongs to.
     * @param array<string, mixed> $row    Pending claim row to re-acquire.
     * @return array<string, mixed>|null
     */
    private function reclaim_row( int $run_id, array $row ): ?array {
        $job_id = $this->attempt_insert( $run_id, $row );
        if ( $job_id <= 0 ) {
            return null;
        }

        return array(
            'batch_state' => $this->fetch_batch_state( $job_id ),
            'job_id'      => $job_id,
            'source_id'   => (int) $row['source_id'],
            'source_kind' => $row['source_kind'],
            'source_lang' => $row['source_lang'],
            'target_lang' => $row['target_lang'],
        );
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private function try_claim( int $run_id, string $kind, ?array $query, Run_Spec $spec ): ?array {
        if ( null === $query ) {
            return null;
        }

        $cache_key = $run_id . ':' . $kind;

        // Two passes at most: drain the cached batch (refilling once if empty),
        // and if that batch is fully claimed-out, fetch ONE fresh batch and try
        // again. A fresh fetch excludes already-claimed rows (c.id IS NULL in
        // the candidate query), so the second batch cannot repeat the first's
        // exhausted set — this bounds the work to a single re-fetch and cannot
        // loop. Common path is a cache hit: the JOIN runs once per 50-row batch
        // rather than once per claimed unit.
        for ( $pass = 0; $pass < 2; $pass++ ) {
            if ( 0 === \count( $this->candidate_cache[ $cache_key ] ?? array() ) ) {
                $this->candidate_cache[ $cache_key ] = 'post' === $kind
                    ? $this->find_post_candidates( $run_id, $query, $spec )
                    : $this->find_term_candidates( $run_id, $query, $spec );
                if ( 0 === \count( $this->candidate_cache[ $cache_key ] ) ) {
                    return null;
                }
            }

            while ( \count( $this->candidate_cache[ $cache_key ] ) > 0 ) {
                $candidate = \array_shift( $this->candidate_cache[ $cache_key ] );
                $job_id    = $this->attempt_insert( $run_id, $candidate );
                if ( $job_id > 0 ) {
                    return array(
                        'batch_state' => $this->fetch_batch_state( $job_id ),
                        'job_id'      => $job_id,
                        'source_id'   => $candidate['source_id'],
                        'source_kind' => $candidate['source_kind'],
                        'source_lang' => $candidate['source_lang'],
                        'target_lang' => $candidate['target_lang'],
                    );
                }
            }
            // Batch drained with no successful claim → loop to fetch a fresh one.
        }
        return null;
    }

    /**
     * Find post candidates via a live JOIN: wp_posts × Polylang language
     * taxonomy × spec.target_languages LEFT JOIN translation_index.
     *
     * An empty index means all posts in the source language are gaps. Rows
     * with target_id set are skipped (already translated) unless force=true
     * or outdated_at IS NOT NULL (edited since last translation).
     *
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function find_post_candidates( int $run_id, array $query, Run_Spec $spec ): array {
        return $this->query_post_candidates( $run_id, $query, $spec, 50 );
    }

    /**
     * Cheap LIMIT 1 existence check; shares the same SELECT as
     * find_post_candidates so eligibility logic cannot diverge.
     *
     * @param array<string, mixed> $query
     */
    private function count_post_candidates( int $run_id, array $query, Run_Spec $spec, int $limit ): int {
        return \count( $this->query_post_candidates( $run_id, $query, $spec, $limit ) );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function query_post_candidates( int $run_id, array $query, Run_Spec $spec, int $limit ): array {
        global $wpdb;
        $source_lang = (string) ( $query['source_lang'] ?? '' );
        if ( '' === $source_lang ) {
            return array();
        }

        $post_types = $query['post_types'] ?? array();
        if ( ! \is_array( $post_types ) || 0 === \count( $post_types ) ) {
            return array();
        }
        $post_status = $query['post_status'] ?? Helpers::post_statuses_for( $post_types );
        if ( ! \is_array( $post_status ) || 0 === \count( $post_status ) ) {
            $post_status = Helpers::post_statuses_for( $post_types );
        }

        // Strip source from target list so we never claim a pair "translate to itself".
        $target_langs = \array_values(
            \array_filter(
                $spec->target_languages(),
                static fn( string $lang ): bool => $lang !== $source_lang,
            ),
        );
        if ( 0 === \count( $target_langs ) ) {
            return array();
        }

        // Build CROSS JOIN of target langs as a derived UNION ALL table.
        $tl_unions = array();
        $tl_params = array();
        foreach ( $target_langs as $lang ) {
            $tl_unions[] = 'SELECT %s AS lang';
            $tl_params[] = $lang;
        }
        $tl_derived = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';

        $pt_ph = \implode( ',', \array_fill( 0, \count( $post_types ), '%s' ) );
        $ps_ph = \implode( ',', \array_fill( 0, \count( $post_status ), '%s' ) );

        // id_list filter.
        $id_list_clause = '';
        $id_list_params = array();
        if ( isset( $query['id_list'] ) && \is_array( $query['id_list'] ) && \count( $query['id_list'] ) > 0 ) {
            $id_ph          = \implode( ',', \array_fill( 0, \count( $query['id_list'] ), '%d' ) );
            $id_list_clause = "AND p.ID IN ({$id_ph}) ";
            $id_list_params = $query['id_list'];
        }

        // Force mode bypasses the "target_id set & not outdated" gate, but
        // still needs a stop condition so the worker doesn't re-claim the
        // same tuple immediately after complete() deletes its claim row.
        // Use translation_index.last_synced vs the run's started_at: a tuple
        // touched by this run's own upsert / clear_outdated will have
        // last_synced >= started_at and is excluded. Old `OR 1=1` removed —
        // that produced an infinite re-claim loop (see issue: activity log
        // showing the same fields completed every 3-5s under force mode).
        $started_at   = $this->get_run_started_at( $run_id );
        $force_clause = $spec->is_force()
            ? \sprintf(
                'AND ( i.target_id IS NULL OR i.target_id = 0 OR i.outdated_at IS NOT NULL OR i.last_synced IS NULL OR i.last_synced <= FROM_UNIXTIME(%d) )',
                $started_at,
            )
            : 'AND ( i.target_id IS NULL OR i.target_id = 0 OR i.outdated_at IS NOT NULL )';

        $sql = "
            SELECT p.ID AS source_id,
                   p.post_type AS content_subtype,
                   p.post_status AS content_status,
                   ll.slug AS source_lang_slug,
                   tl.lang AS target_lang
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} ll_tax
                ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id
                AND ll_tax.taxonomy = 'language'
            INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
            CROSS JOIN {$tl_derived} AS tl
            LEFT JOIN {$wpdb->prefix}pllat_translation_index i
                ON i.source_kind = 'post'
                AND i.source_id = p.ID
                AND i.target_lang = tl.lang
            LEFT JOIN {$wpdb->prefix}pllat_claims c
                ON c.run_id = %d
                AND c.source_kind = 'post'
                AND c.source_id = p.ID
                AND c.target_lang = tl.lang
            WHERE p.post_type IN ({$pt_ph})
              AND p.post_status IN ({$ps_ph})
              AND ll.slug = %s
              AND tl.lang != ll.slug
              {$id_list_clause}
              {$force_clause}
              AND c.id IS NULL
              /* Excluded only when meta_value = '1' (true). Two writers: Translatable_Post deletes the row on toggle-off; Single_Translation_Service stores ''. Both are correctly NOT excluded by '= 1'. Do not relax to != '0' or a bare EXISTS — that re-introduces false-excludes. */
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} ex
                  WHERE ex.post_id = p.ID
                    AND ex.meta_key = '_pllat_exclude_from_translation'
                    AND ex.meta_value = '1'
              )
            ORDER BY p.ID, tl.lang
            LIMIT %d
        ";

        // Parameter order matches SQL placeholders top to bottom:
        // tl_params (CROSS JOIN), run_id (claims LEFT JOIN), post_types,
        // post_status, source_lang, optional id_list, limit.
        $params = \array_merge(
            $tl_params,
            array( $run_id ),
            $post_types,
            $post_status,
            array( $source_lang ),
            $id_list_params,
            array( $limit ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
        if ( null === $rows ) {
            return array();
        }
        return \array_map(
            static fn( array $row ): array => array(
                'content_status'  => (string) $row['content_status'],
                'content_subtype' => (string) $row['content_subtype'],
                'source_id'       => (int) $row['source_id'],
                'source_kind'     => 'post',
                'source_lang'     => (string) $row['source_lang_slug'],
                'target_lang'     => (string) $row['target_lang'],
            ),
            $rows,
        );
    }

    /**
     * Find term candidates via a live JOIN: wp_terms × Polylang term_language
     * taxonomy × spec.target_languages LEFT JOIN translation_index.
     *
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function find_term_candidates( int $run_id, array $query, Run_Spec $spec ): array {
        return $this->query_term_candidates( $run_id, $query, $spec, 50 );
    }

    /**
     * Cheap LIMIT 1 existence check; shares the same SELECT as
     * find_term_candidates so eligibility logic cannot diverge.
     *
     * @param array<string, mixed> $query
     */
    private function count_term_candidates( int $run_id, array $query, Run_Spec $spec, int $limit ): int {
        return \count( $this->query_term_candidates( $run_id, $query, $spec, $limit ) );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function query_term_candidates( int $run_id, array $query, Run_Spec $spec, int $limit ): array {
        global $wpdb;
        $source_lang = (string) ( $query['source_lang'] ?? '' );
        if ( '' === $source_lang ) {
            return array();
        }

        $taxonomies = $query['taxonomies'] ?? array();
        if ( ! \is_array( $taxonomies ) || 0 === \count( $taxonomies ) ) {
            return array();
        }

        // Respect Polylang's translatable-taxonomy setting (single source of
        // truth, works on both Polylang Free and Pro). A taxonomy can have
        // historical pllat_translation_index rows from a session in which
        // PLLWC or another addon registered it as translated; if that addon
        // is later disabled, pllat must not attempt to translate it — the
        // Polylang slug-suffix filters that prevent term-name collisions
        // only fire for translated taxonomies, so the insert would either
        // hit a "term name already exists" error or smear into an existing
        // term in the wrong language.
        $taxonomies = $this->filter_translatable_taxonomies( $taxonomies );
        if ( 0 === \count( $taxonomies ) ) {
            return array();
        }

        $target_langs = \array_values(
            \array_filter(
                $spec->target_languages(),
                static fn( string $lang ): bool => $lang !== $source_lang,
            ),
        );
        if ( 0 === \count( $target_langs ) ) {
            return array();
        }

        $tl_unions = array();
        $tl_params = array();
        foreach ( $target_langs as $lang ) {
            $tl_unions[] = 'SELECT %s AS lang';
            $tl_params[] = $lang;
        }
        $tl_derived = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';

        $tx_ph = \implode( ',', \array_fill( 0, \count( $taxonomies ), '%s' ) );

        $id_list_clause = '';
        $id_list_params = array();
        if ( isset( $query['id_list'] ) && \is_array( $query['id_list'] ) && \count( $query['id_list'] ) > 0 ) {
            $id_ph          = \implode( ',', \array_fill( 0, \count( $query['id_list'] ), '%d' ) );
            $id_list_clause = "AND t.term_id IN ({$id_ph}) ";
            $id_list_params = $query['id_list'];
        }

        $started_at   = $this->get_run_started_at( $run_id );
        $force_clause = $spec->is_force()
            ? \sprintf(
                'AND ( i.target_id IS NULL OR i.target_id = 0 OR i.outdated_at IS NOT NULL OR i.last_synced IS NULL OR i.last_synced <= FROM_UNIXTIME(%d) )',
                $started_at,
            )
            : 'AND ( i.target_id IS NULL OR i.target_id = 0 OR i.outdated_at IS NOT NULL )';

        // Polylang stores term_language slugs WITH a 'pll_' prefix
        // ('pll_en', 'pll_de', ...), unlike the 'language' taxonomy used for
        // posts which keeps the bare slug ('en', 'de'). Run_Spec carries the
        // bare form throughout, so we prepend 'pll_' only at the term-language
        // join here and strip it again in the SELECT so callers downstream
        // (Translation_Index_Repository, Activity_Service, etc.) keep dealing
        // with the bare form they expect.
        $ll_slug_param = 'pll_' . $source_lang;

        $sql = "
            SELECT t.term_id AS source_id,
                   tt.taxonomy AS content_subtype,
                   'active' AS content_status,
                   SUBSTRING(ll.slug, 5) AS source_lang_slug,
                   tl.lang AS target_lang
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = t.term_id
            INNER JOIN {$wpdb->term_taxonomy} ll_tax
                ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id
                AND ll_tax.taxonomy = 'term_language'
            INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
            CROSS JOIN {$tl_derived} AS tl
            LEFT JOIN {$wpdb->prefix}pllat_translation_index i
                ON i.source_kind = 'term'
                AND i.source_id = t.term_id
                AND i.target_lang = tl.lang
            LEFT JOIN {$wpdb->prefix}pllat_claims c
                ON c.run_id = %d
                AND c.source_kind = 'term'
                AND c.source_id = t.term_id
                AND c.target_lang = tl.lang
            WHERE tt.taxonomy IN ({$tx_ph})
              AND ll.slug = %s
              AND tl.lang != %s
              {$id_list_clause}
              {$force_clause}
              AND c.id IS NULL
            ORDER BY t.term_id, tl.lang
            LIMIT %d
        ";

        // Placeholder order: tl_params (CROSS JOIN), run_id (claims LEFT JOIN),
        // taxonomies, prefixed source_lang (ll.slug match), bare source_lang
        // (tl.lang != source_lang), optional id_list, limit.
        $params = \array_merge(
            $tl_params,
            array( $run_id ),
            $taxonomies,
            array( $ll_slug_param, $source_lang ),
            $id_list_params,
            array( $limit ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
        if ( null === $rows ) {
            return array();
        }
        return \array_map(
            static fn( array $row ): array => array(
                'content_status'  => (string) $row['content_status'],
                'content_subtype' => (string) $row['content_subtype'],
                'source_id'       => (int) $row['source_id'],
                'source_kind'     => 'term',
                'source_lang'     => (string) $row['source_lang_slug'],
                'target_lang'     => (string) $row['target_lang'],
            ),
            $rows,
        );
    }

    /**
     * Fetch the persisted batch_state JSON for a job row and decode it.
     *
     * Returns null when no state exists (first claim, or post-DELETE cleanup).
     *
     * @return array<string, mixed>|null
     */
    private function fetch_batch_state( int $job_id ): ?array {
        global $wpdb;
        $json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT batch_state FROM {$wpdb->prefix}pllat_claims WHERE id = %d",
                $job_id,
            ),
        );
        if ( null === $json || '' === $json ) {
            return null;
        }
        $decoded = \json_decode( (string) $json, true );
        return \is_array( $decoded ) ? $decoded : null;
    }

    /**
     * INSERT a jobs row claiming this unit. Returns the job_id on success or
     * 0 if another worker already holds an in_progress claim for the same unit.
     *
     * On DUPLICATE KEY (fresh installs have a claim_unit UNIQUE KEY), the row is
     * re-activated from 'pending' → 'in_progress'. This supports the retry flow
     * where record_failure resets a retryable job to 'pending'.
     *
     * Records the owning Action Scheduler action (as_action_id). Claim
     * liveness is derived from that action's status, not a wall-clock
     * heartbeat: a claim stuck 'in_progress' whose owning action is no
     * longer live is reclaimed by try_reclaim_pending (no attempt burned).
     * A re-claim of a 'pending' row hands ownership to the new worker's
     * action via the ON DUPLICATE KEY UPDATE.
     */
    private function attempt_insert( int $run_id, array $candidate ): int {
        global $wpdb;
        $claims_table = $wpdb->prefix . 'pllat_claims';
        $now          = \time();
        // The Action Scheduler action that owns this claim. Claim liveness
        // is derived from this action's status (see Claim_Lifecycle). 0 when
        // claimed outside an AS action (only possible in tests) — treated as
        // "owner unknown", never reclaimed by the AS rule.
        $as_action_id = (int) ( AS_Action_Context::get() ?? 0 );

        // Try INSERT first. If the claim_unit UNIQUE KEY fires, the row already
        // exists in 'pending' state (retryable failure reset) — re-activate it
        // and hand ownership to the re-claiming worker's action.
        $sql = "INSERT INTO {$claims_table}
            (run_id, source_kind, source_id, source_lang, target_lang, content_subtype, status, attempts, as_action_id, created_at, started_at, last_heartbeat)
            VALUES (%d, %s, %d, %s, %s, %s, 'in_progress', 0, %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE
                status         = IF(status = 'pending', 'in_progress', status),
                as_action_id   = IF(status = 'pending', VALUES(as_action_id), as_action_id),
                started_at     = IF(status = 'pending', VALUES(started_at), started_at),
                last_heartbeat = IF(status = 'pending', VALUES(last_heartbeat), last_heartbeat)";

        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name; values are prepared.
                $sql,
                $run_id,
                $candidate['source_kind'],
                $candidate['source_id'],
                $candidate['source_lang'],
                $candidate['target_lang'],
                $candidate['content_subtype'],
                $as_action_id,
                $now,
                $now,
                $now,
            ),
        );

        // rows_affected == 1 → new row inserted (fresh claim).
        // rows_affected == 2 → ON DUPLICATE KEY updated a pending row (re-claim).
        // rows_affected == 0 → row existed but was already in_progress; no claim.
        $affected = (int) $wpdb->rows_affected;
        if ( $affected >= 1 ) {
            // For a fresh INSERT, insert_id is the new row's id.
            // For a re-claim update, insert_id is also set by MySQL to the row's id
            // when LAST_INSERT_ID is involved. Fall back to a SELECT if insert_id is 0.
            $job_id = (int) $wpdb->insert_id;
            if ( $job_id > 0 ) {
                return $job_id;
            }
            // Fallback: look up the row id (re-claim case on older MySQL/MariaDB).
            $job_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$claims_table}
                     WHERE run_id = %d AND source_kind = %s AND source_id = %d AND target_lang = %s
                     AND status = 'in_progress'
                     LIMIT 1",
                    $run_id,
                    $candidate['source_kind'],
                    $candidate['source_id'],
                    $candidate['target_lang'],
                ),
            );
            return $job_id;
        }
        return 0;
    }

    /**
     * Drop any taxonomy slug Polylang doesn't list as translatable.
     *
     * `pll_is_translated_taxonomy` is the single source of truth across
     * Polylang Free and Pro (Pro extends Free's model). When an addon
     * such as Polylang for WooCommerce is disabled, its taxonomies
     * (pa_color, pa_size, …) stop being translatable mid-session even
     * if pllat_translation_index still has rows for them — those rows
     * need to remain readable but no new claims should be made.
     *
     * @param array<int, string> $taxonomies
     * @return array<int, string>
     */
    private function filter_translatable_taxonomies( array $taxonomies ): array {
        if ( ! \function_exists( 'pll_is_translated_taxonomy' ) || ! isset( $GLOBALS['polylang'] ) ) {
            // Polylang not booted (e.g. WP test bootstrap loads the
            // plugin file but skips its init hooks). PLL() reads
            // $GLOBALS['polylang'] and fatals if unset — guard the
            // global directly. Production WP always has it.
            return $taxonomies;
        }
        return \array_values(
            \array_filter(
                $taxonomies,
                static fn( string $tax ): bool => \pll_is_translated_taxonomy( $tax ),
            ),
        );
    }
}
