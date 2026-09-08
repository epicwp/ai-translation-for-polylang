<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Repositories;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;

class Translation_Index_Repository {

	public const KIND_POST = 'post';
	public const KIND_TERM = 'term';

	public function __construct(
		private readonly \PLLAT\Common\Interfaces\Language_Manager $language_manager,
	) {}

	public function upsert(
		string $source_kind,
		int $source_id,
		string $source_lang,
		string $target_lang,
		?int $target_id,
		string $content_subtype,
		string $content_status
	): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';

		$update = 'ON DUPLICATE KEY UPDATE
			target_id = VALUES(target_id),
			source_lang = VALUES(source_lang),
			content_subtype = VALUES(content_subtype),
			content_status = VALUES(content_status),
			last_synced = NOW()';

		if ( null === $target_id ) {
			$sql = "INSERT INTO {$table}
				(source_kind, source_id, source_lang, target_lang, target_id, content_subtype, content_status, last_synced)
				VALUES (%s, %d, %s, %s, NULL, %s, %s, NOW())
				{$update}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name; values are prepared.
			$wpdb->query( $wpdb->prepare( $sql, $source_kind, $source_id, $source_lang, $target_lang, $content_subtype, $content_status ) );
			return;
		}

		$sql = "INSERT INTO {$table}
			(source_kind, source_id, source_lang, target_lang, target_id, content_subtype, content_status, last_synced)
			VALUES (%s, %d, %s, %s, %d, %s, %s, NOW())
			{$update}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name; values are prepared.
		$wpdb->query( $wpdb->prepare( $sql, $source_kind, $source_id, $source_lang, $target_lang, $target_id, $content_subtype, $content_status ) );
	}

	public function find_pair( string $source_kind, int $source_id, string $target_lang ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_kind = %s AND source_id = %d AND target_lang = %s",
				$source_kind,
				$source_id,
				$target_lang,
			),
			\ARRAY_A,
		);
		if ( null === $row ) {
			return null;
		}
		return $this->cast_row( $row );
	}

	public function count_all(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Index row counts grouped by source kind ('post' / 'term').
	 *
	 * @return array{post:int, term:int}
	 */
	public function count_by_kind(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
		$rows = $wpdb->get_results(
			"SELECT source_kind, COUNT(*) AS n FROM {$table} GROUP BY source_kind",
			\ARRAY_A,
		);
		$out = array( 'post' => 0, 'term' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source_kind'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Count index rows flagged outdated (source changed, not yet re-translated).
	 */
	public function count_outdated(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE outdated_at IS NOT NULL" );
	}

	/**
	 * Index row counts grouped by content subtype, highest first.
	 *
	 * Read-only diagnostic helper for the System Report — surfaces e.g.
	 * `['attachment' => 84084, 'product' => 28154, ...]` so a supporter can
	 * see at a glance whether media/products are represented in the index.
	 *
	 * @return array<string, int> content_subtype => row count.
	 */
	public function count_by_subtype(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'pllat_translation_index';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
		$rows = $wpdb->get_results(
			"SELECT content_subtype, COUNT(*) AS n FROM {$table} GROUP BY content_subtype ORDER BY n DESC",
			\ARRAY_A,
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['content_subtype'] ] = (int) $row['n'];
		}
		return $out;
	}

	public function delete_pair( string $source_kind, int $source_id, string $target_lang ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'pllat_translation_index',
			array( 'source_kind' => $source_kind, 'source_id' => $source_id, 'target_lang' => $target_lang ),
			array( '%s', '%d', '%s' ),
		);
	}

	public function delete_for_source( string $source_kind, int $source_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'pllat_translation_index',
			array( 'source_kind' => $source_kind, 'source_id' => $source_id ),
			array( '%s', '%d' ),
		);
	}

	/**
	 * Delete all rows where this entity appears as a target.
	 *
	 * Used when a target post/term is hard-deleted. The (source, target_lang)
	 * pair becomes meaningless without its target.
	 */
	public function delete_target_references( string $source_kind, int $target_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'pllat_translation_index',
			array( 'source_kind' => $source_kind, 'target_id' => $target_id ),
			array( '%s', '%d' ),
		);
	}

	public function mark_outdated_for_pair( string $source_kind, int $source_id, string $target_lang ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'pllat_translation_index',
			array( 'outdated_at' => \current_time( 'mysql' ) ),
			array(
				'source_kind' => $source_kind,
				'source_id'   => $source_id,
				'target_lang' => $target_lang,
			),
			array( '%s' ),
			array( '%s', '%d', '%s' ),
		);
	}

	/**
	 * @param array<int, string> $target_langs
	 */
	public function mark_outdated_bulk( string $source_kind, int $source_id, array $target_langs ): void {
		if ( \count( $target_langs ) === 0 ) {
			return;
		}
		global $wpdb;
		$placeholders = \implode( ',', \array_fill( 0, \count( $target_langs ), '%s' ) );
		$params       = \array_merge(
			array( \current_time( 'mysql' ), $source_kind, $source_id ),
			$target_langs,
		);
		$sql = "UPDATE {$wpdb->prefix}pllat_translation_index
                SET outdated_at = %s
                WHERE source_kind = %s
                  AND source_id = %d
                  AND target_lang IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
		$wpdb->query( $wpdb->prepare( $sql, ...$params ) );
	}

	public function clear_outdated( string $source_kind, int $source_id, string $target_lang ): void {
		global $wpdb;
		// Bump last_synced together with outdated_at so the force-mode candidate
		// query in Job_Claim_Service can use last_synced >= runs.started_at as
		// the "already processed this run" stop condition — including in the
		// empty-gaps path where no upsert from ensure_target_exists touches the
		// row. Without this update, force runs whose force_fields don't
		// intersect translatable spin in an infinite claim → complete → re-claim
		// loop (see Job_Claim_Service::find_post_candidates force_clause).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}pllat_translation_index
                 SET outdated_at = NULL, last_synced = NOW()
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s",
				$source_kind,
				$source_id,
				$target_lang,
			),
		);
	}

	public function aggregate_for_dashboard( string $source_lang ): array {
		global $wpdb;

		$post_types = $this->language_manager->get_active_post_types();
		$taxonomies = $this->language_manager->get_active_taxonomies();
		$available  = $this->language_manager->get_available_languages();

		$target_langs = \array_values( \array_filter(
			$available,
			static fn( string $lang ): bool => $lang !== $source_lang,
		) );
		if ( \count( $target_langs ) === 0 ) {
			return array();
		}

		// Source totals (how many source-language posts/terms exist per type ×
		// target lang) barely change during a run — translation creates
		// target-language posts, which these CROSS JOINs do not count — so they
		// are cached for 60s. The live done-count below is overlaid on EVERY
		// call, so realtime progress is unaffected (only the denominator is
		// cached). Cache key varies by source lang + active types + target langs.
		$src_cache_key = 'pllat_dash_src_' . \md5(
			$source_lang . '|' . \implode( ',', $post_types ) . '|' . \implode( ',', $taxonomies ) . '|' . \implode( ',', $target_langs ),
		);
		$cached_totals = \get_transient( $src_cache_key );
		$aggregates    = \is_array( $cached_totals ) ? $cached_totals : array();

		// Posts: live total per (post_type × target_lang).
		if ( ! \is_array( $cached_totals ) && \count( $post_types ) > 0 ) {
			$tl_unions = array();
			$tl_params = array();
			foreach ( $target_langs as $lang ) {
				$tl_unions[] = 'SELECT %s AS lang';
				$tl_params[] = $lang;
			}
			$tl_derived = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';
			$pt_ph         = \implode( ',', \array_fill( 0, \count( $post_types ), '%s' ) );
			$post_statuses = Helpers::post_statuses_for( $post_types );
			$ps_ph         = \implode( ',', \array_fill( 0, \count( $post_statuses ), '%s' ) );
			$params        = \array_merge( $tl_params, $post_statuses, $post_types, array( $source_lang ) );

			$sql = "
				SELECT p.post_type AS content_subtype,
				       tl.lang AS target_lang,
				       COUNT(p.ID) AS total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} ll_tax ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id AND ll_tax.taxonomy = 'language'
				INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
				CROSS JOIN {$tl_derived} AS tl
				WHERE p.post_status IN ({$ps_ph})
				  AND p.post_type IN ({$pt_ph})
				  AND ll.slug = %s
				  AND tl.lang != ll.slug
				  /* Excluded only when meta_value = '1' (true). Two writers: Translatable_Post deletes the row on toggle-off; Single_Translation_Service stores ''. Both are correctly NOT excluded by '= 1'. Do not relax to != '0' or a bare EXISTS — that re-introduces false-excludes. */
				  AND NOT EXISTS (
				      SELECT 1 FROM {$wpdb->postmeta} ex
				      WHERE ex.post_id = p.ID
				        AND ex.meta_key = '_pllat_exclude_from_translation'
				        AND ex.meta_value = '1'
				  )
				GROUP BY p.post_type, tl.lang
			";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
			foreach ( $rows ?? array() as $row ) {
				$key = "post|{$row['content_subtype']}|{$row['target_lang']}";
				$aggregates[ $key ] = array(
					'source_kind'     => 'post',
					'content_subtype' => (string) $row['content_subtype'],
					'source_lang'     => $source_lang,
					'target_lang'     => (string) $row['target_lang'],
					'total'           => (int) $row['total'],
					'done'            => 0,
				);
			}
		}

		// Terms: live total per (taxonomy × target_lang).
		// Polylang stores term_language slugs as 'pll_en'/'pll_de'/... (prefixed),
		// unlike the 'language' taxonomy used for posts which is bare. Match
		// against the prefixed form in the join, and use the bare source_lang
		// for the != comparison.
		if ( ! \is_array( $cached_totals ) && \count( $taxonomies ) > 0 ) {
			$tl_unions = array();
			$tl_params = array();
			foreach ( $target_langs as $lang ) {
				$tl_unions[] = 'SELECT %s AS lang';
				$tl_params[] = $lang;
			}
			$tl_derived    = '( ' . \implode( ' UNION ALL ', $tl_unions ) . ' )';
			$tx_ph         = \implode( ',', \array_fill( 0, \count( $taxonomies ), '%s' ) );
			$ll_slug_param = 'pll_' . $source_lang;
			$params        = \array_merge( $tl_params, $taxonomies, array( $ll_slug_param, $source_lang ) );

			$sql = "
				SELECT tt.taxonomy AS content_subtype,
				       tl.lang AS target_lang,
				       COUNT(t.term_id) AS total
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->term_relationships} ll_rel ON ll_rel.object_id = t.term_id
				INNER JOIN {$wpdb->term_taxonomy} ll_tax ON ll_tax.term_taxonomy_id = ll_rel.term_taxonomy_id AND ll_tax.taxonomy = 'term_language'
				INNER JOIN {$wpdb->terms} ll ON ll.term_id = ll_tax.term_id
				CROSS JOIN {$tl_derived} AS tl
				WHERE tt.taxonomy IN ({$tx_ph})
				  AND ll.slug = %s
				  AND tl.lang != %s
				GROUP BY tt.taxonomy, tl.lang
			";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A );
			foreach ( $rows ?? array() as $row ) {
				$key = "term|{$row['content_subtype']}|{$row['target_lang']}";
				$aggregates[ $key ] = array(
					'source_kind'     => 'term',
					'content_subtype' => (string) $row['content_subtype'],
					'source_lang'     => $source_lang,
					'target_lang'     => (string) $row['target_lang'],
					'total'           => (int) $row['total'],
					'done'            => 0,
				);
			}
		}

		// Cache the freshly-computed source totals (with done still 0) for 60s.
		if ( ! \is_array( $cached_totals ) ) {
			\set_transient( $src_cache_key, $aggregates, 60 );
		}

		// Done from index: target_id NOT NULL AND outdated_at NULL AND content_status in (publish, active).
		$done_sql = "
			SELECT source_kind, content_subtype, target_lang, COUNT(*) AS done
			FROM {$wpdb->prefix}pllat_translation_index
			WHERE source_lang = %s
			  AND target_id IS NOT NULL
			  AND outdated_at IS NULL
			  AND content_status IN ('publish', 'active')
			GROUP BY source_kind, content_subtype, target_lang
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $done_sql is literal SQL plus the prefixed table name; values are prepared.
		$done_rows = $wpdb->get_results( $wpdb->prepare( $done_sql, $source_lang ), \ARRAY_A );
		foreach ( $done_rows ?? array() as $row ) {
			$key = "{$row['source_kind']}|{$row['content_subtype']}|{$row['target_lang']}";
			if ( isset( $aggregates[ $key ] ) ) {
				$aggregates[ $key ]['done'] = (int) $row['done'];
			}
		}

		return \array_values( $aggregates );
	}

	private function cast_row( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['source_id'] = (int) $row['source_id'];
		$row['target_id'] = null === $row['target_id'] ? null : (int) $row['target_id'];
		return $row;
	}
}
