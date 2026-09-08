<?php
/**
 * Post_List_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Admin
 */

declare(strict_types=1);

namespace PLLAT\Admin\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Helpers;
use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Sql_Loader;

/**
 * Service for post list translation status queries.
 * Provides batch queries for N+1 prevention and filter queries for admin list filtering.
 */
class Post_List_Service {
    private const TABLE_NAME = 'pllat_claims';

    /**
     * Cache for preloaded translation status.
     *
     * @var array<int, array<string, array{status: string, job_id: int}>>
     */
    private array $status_cache = array();

    /**
     * Constructor.
     *
     * @param Language_Manager $language_manager The language manager.
     */
    public function __construct(
        private Language_Manager $language_manager,
    ) {
    }

    /**
     * Get translation status for multiple posts at once (batch query).
     * Solves N+1 problem for post list display.
     *
     * @param array<int> $post_ids Array of post IDs.
     * @return array<int, array<string, array{status: string, job_id: int}>> Keyed by post_id, then lang_to.
     */
    public function get_translation_status_batch( array $post_ids ): array {
        if ( 0 === \count( $post_ids ) ) {
            return array();
        }

        global $wpdb;

        // Build placeholders for IN clause.
        $placeholders = \implode( ',', \array_fill( 0, \count( $post_ids ), '%d' ) );

        $sql = Sql_Loader::load(
            'post_list/get_translation_status_batch.sql',
            array(
                '{jobs_table}'           => $wpdb->prefix . self::TABLE_NAME,
                '{post_id_placeholders}' => $placeholders,
            ),
        );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL loaded from file with placeholders.
        $results = $wpdb->get_results(
            $wpdb->prepare( $sql, $post_ids ),
            ARRAY_A,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        // Group by post_id. Claims carry only IN-FLIGHT state (pending /
        // in_progress / failed) — a successful claim is DELETED, so the
        // authoritative "completed" signal lives in pllat_translation_index,
        // overlaid below.
        $status_map = array();
        foreach ( $results as $row ) {
            $post_id = (int) $row['source_id'];
            $lang_to = $row['target_lang'];

            if ( ! isset( $status_map[ $post_id ] ) ) {
                $status_map[ $post_id ] = array();
            }

            $status_map[ $post_id ][ $lang_to ] = array(
                'job_id' => (int) $row['id'],
                'status' => $row['status'],
            );
        }

        return $this->overlay_completed_from_index( $status_map, $post_ids );
    }

    /**
     * Overlay 'completed' status from the durable translation index.
     *
     * The column reads claims for in-flight state, but claims are deleted on
     * success — so a finished translation has no claim row. A done index row
     * (target_id set, not outdated) is the authoritative "completed" signal and
     * wins over any leftover claim state for that (post, lang).
     *
     * @param array<int, array<string, array{status: string, job_id: int}>> $status_map Claim-derived statuses.
     * @param array<int>                                                     $post_ids   Post IDs in scope.
     * @return array<int, array<string, array{status: string, job_id: int}>>
     */
    private function overlay_completed_from_index( array $status_map, array $post_ids ): array {
        global $wpdb;
        $placeholders = \implode( ',', \array_fill( 0, \count( $post_ids ), '%d' ) );
        $index_table  = $wpdb->prefix . 'pllat_translation_index';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from prefix; one %d per post id, ids prepared.
        $done = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_id, target_lang FROM {$index_table}
                 WHERE source_kind = 'post' AND source_id IN ({$placeholders})
                   AND target_id IS NOT NULL AND outdated_at IS NULL",
                $post_ids,
            ),
            ARRAY_A,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        foreach ( $done as $row ) {
            $post_id = (int) $row['source_id'];
            $lang_to = (string) $row['target_lang'];
            if ( ! isset( $status_map[ $post_id ] ) ) {
                $status_map[ $post_id ] = array();
            }
            $existing                           = $status_map[ $post_id ][ $lang_to ]['job_id'] ?? 0;
            $status_map[ $post_id ][ $lang_to ] = array(
                'job_id' => (int) $existing,
                'status' => 'completed',
            );
        }

        return $status_map;
    }

    /**
     * Preload translation status for a list of posts.
     * Call this before rendering column to avoid N+1 queries.
     *
     * @param array<int> $post_ids Array of post IDs.
     * @return void
     */
    public function preload_status( array $post_ids ): void {
        $this->status_cache = $this->get_translation_status_batch( $post_ids );
    }

    /**
     * Get translation status for a single post.
     * Uses cache if preloaded, otherwise queries directly.
     *
     * @param int $post_id The post ID.
     * @return array<string, array{status: string, job_id: int}> Keyed by lang_to.
     */
    public function get_translation_status( int $post_id ): array {
        // Return from cache if available.
        if ( isset( $this->status_cache[ $post_id ] ) ) {
            return $this->status_cache[ $post_id ];
        }

        // Query for single post.
        $batch = $this->get_translation_status_batch( array( $post_id ) );
        return $batch[ $post_id ] ?? array();
    }

    /**
     * Get post IDs with failed translation jobs.
     *
     * @param string $post_type The post type.
     * @return array<int> Array of post IDs.
     */
    public function get_posts_with_errors( string $post_type ): array {
        global $wpdb;

        $sql = Sql_Loader::load(
            'post_list/get_posts_with_errors.sql',
            array(
                '{jobs_table}' => $wpdb->prefix . self::TABLE_NAME,
            ),
        );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL loaded from file with placeholders.
        $results = $wpdb->get_col(
            $wpdb->prepare( $sql, $post_type ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return \array_map( 'intval', $results );
    }

    /**
     * Get post IDs with pending or in_progress translation jobs.
     *
     * @param string $post_type The post type.
     * @return array<int> Array of post IDs.
     */
    public function get_posts_pending( string $post_type ): array {
        global $wpdb;

        $sql = Sql_Loader::load(
            'post_list/get_posts_pending.sql',
            array(
                '{jobs_table}' => $wpdb->prefix . self::TABLE_NAME,
            ),
        );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL loaded from file with placeholders.
        $results = $wpdb->get_col(
            $wpdb->prepare( $sql, $post_type ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return \array_map( 'intval', $results );
    }

    /**
     * Get post IDs that have all target languages translated.
     *
     * @param string $post_type The post type.
     * @return array<int> Array of post IDs.
     */
    public function get_posts_all_translated( string $post_type ): array {
        global $wpdb;

        // Get target language count (all languages except source).
        $target_count = $this->get_target_language_count();

        if ( 0 === $target_count ) {
            return array();
        }

        $sql = Sql_Loader::load(
            'post_list/get_posts_all_translated.sql',
            array(
                '{index_table}' => $wpdb->prefix . 'pllat_translation_index',
            ),
        );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL loaded from file with placeholders.
        $results = $wpdb->get_col(
            $wpdb->prepare( $sql, $post_type, $target_count ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return \array_map( 'intval', $results );
    }

    /**
     * Get post IDs that are missing translations for any target language.
     *
     * @param string $post_type The post type.
     * @return array<int> Array of post IDs.
     */
    public function get_posts_missing_translations( string $post_type ): array {
        global $wpdb;

        // Get target language count (all languages except source).
        $target_count = $this->get_target_language_count();

        if ( 0 === $target_count ) {
            return array();
        }

        $post_statuses    = Helpers::get_translatable_post_statuses();
        $post_statuses_ph = \implode( ',', \array_fill( 0, \count( $post_statuses ), '%s' ) );

        $sql = Sql_Loader::load(
            'post_list/get_posts_missing_translations.sql',
            array(
                '{index_table}'                 => $wpdb->prefix . 'pllat_translation_index',
                '{post_statuses_placeholders}' => $post_statuses_ph,
                '{posts_table}'                => $wpdb->posts,
            ),
        );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL loaded from file with placeholders.
        // Order: %s (content_type), %s (post_type), post_statuses, %d (count).
        $results = $wpdb->get_col(
            $wpdb->prepare( $sql, $post_type, $post_type, ...\array_merge( $post_statuses, array( $target_count ) ) ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return \array_map( 'intval', $results );
    }

    /**
     * Get filtered post IDs based on translation status filter.
     *
     * @param string $filter_value Filter value (missing|all_translated|has_errors|pending).
     * @param string $post_type    The post type.
     * @return array<int>|null Array of post IDs or null if no filtering needed.
     */
    public function get_filtered_post_ids( string $filter_value, string $post_type ): ?array {
        return match ( $filter_value ) {
            'missing'        => $this->get_posts_missing_translations( $post_type ),
            'all_translated' => $this->get_posts_all_translated( $post_type ),
            'has_errors'     => $this->get_posts_with_errors( $post_type ),
            'pending'        => $this->get_posts_pending( $post_type ),
            default          => null,
        };
    }

    /**
     * Clear the status cache.
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->status_cache = array();
    }

    /**
     * Get the number of target languages (all languages except source).
     *
     * @return int The target language count.
     */
    private function get_target_language_count(): int {
        $all_languages   = $this->language_manager->get_available_languages( true );
        $source_language = $this->language_manager->get_default_language();

        // Filter out source language.
        $target_languages = \array_filter(
            $all_languages,
            static fn( string $lang ) => $lang !== $source_language,
        );

        return \count( $target_languages );
    }
}
