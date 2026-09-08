<?php
declare(strict_types=1);

namespace PLLAT\Activity\Repositories;

\defined( 'ABSPATH' ) || exit;

/**
 * Read-only repository for lean activity data.
 * Derives activity state from activity_log, translation_index, and claims tables.
 * No dependency on Activity_Log, Job, Task, or Run models.
 */
class Activity_Repository {

    /**
     * Table name for translation index.
     *
     * @return string
     */
    private function index_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pllat_translation_index';
    }

    /**
     * Table name for claims.
     *
     * @return string
     */
    private function claims_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pllat_claims';
    }

    /**
     * Table name for bulk runs.
     *
     * @return string
     */
    private function runs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pllat_bulk_runs';
    }

    /**
     * Find done events for a date, grouped by (source_kind, source_id, target_lang).
     *
     * Joins translation_index to get source_lang, target_id, and content_subtype
     * for each group. Groups without an index row still appear; source_lang will
     * be null in that case and the service falls back to an empty string.
     *
     * @param string $date_ymd Date in Y-m-d format.
     * @param int    $offset   Row offset for pagination.
     * @param int    $limit    Max rows to return.
     * @return array<int, array<string, mixed>>
     */
    public function find_done_events( string $date_ymd, int $offset, int $limit ): array {
        global $wpdb;

        $al  = $wpdb->prefix . 'pllat_activity_log';
        $idx = $this->index_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    al.source_kind,
                    al.source_id,
                    al.target_lang,
                    MAX(al.run_id) AS run_id,
                    MIN(al.logged_at) AS earliest,
                    MAX(al.logged_at) AS latest,
                    SUM(al.status = 'completed') AS completed_count,
                    SUM(al.status = 'failed') AS failed_count,
                    COUNT(*) AS field_count,
                    CRC32(CONCAT(al.source_kind, al.source_id, al.target_lang, DATE(al.logged_at))) AS group_key,
                    COALESCE(NULLIF(al.source_lang, ''), i.source_lang) AS source_lang,
                    i.target_id,
                    i.content_subtype,
                    i.outdated_at
                FROM {$al} al
                LEFT JOIN {$idx} i
                    ON i.source_kind = al.source_kind
                    AND i.source_id  = al.source_id
                    AND i.target_lang = al.target_lang
                WHERE DATE(al.logged_at) = %s
                  AND al.status = 'completed'
                GROUP BY al.source_kind, al.source_id, al.target_lang, DATE(al.logged_at)
                ORDER BY latest DESC
                LIMIT %d OFFSET %d",
                $date_ymd,
                $limit,
                $offset,
            ),
            ARRAY_A,
        );

        return $rows ?: array();
    }

    /**
     * Count distinct (source_kind, source_id, target_lang) groups for a date.
     *
     * @param string $date_ymd Date in Y-m-d format.
     * @return int
     */
    public function count_done_events( string $date_ymd ): int {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT CONCAT(source_kind, '|', source_id, '|', target_lang))
                FROM {$al}
                WHERE DATE(logged_at) = %s
                  AND status = 'completed'",
                $date_ymd,
            ),
        );
    }

    /**
     * Find in-flight job claims (pending or in_progress).
     *
     * @param int $offset Row offset.
     * @param int $limit  Max rows.
     * @return array<int, array<string, mixed>>
     */
    public function find_active_events( int $offset, int $limit ): array {
        global $wpdb;

        $claims = $this->claims_table();
        $runs   = $this->runs_table();

        // INNER JOIN the run: a claim only counts as an active event while
        // its owning run is still alive. A claim orphaned under a terminal
        // run can never complete and must not render as a phantom
        // "Translating … In progress" (matches has_active_run's status set).
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    c.id,
                    c.source_kind,
                    c.source_id,
                    c.source_lang,
                    c.target_lang,
                    c.content_subtype,
                    c.started_at,
                    c.status
                FROM {$claims} c
                INNER JOIN {$runs} r ON r.id = c.run_id
                WHERE c.status IN ('pending', 'in_progress')
                  AND r.status IN ('pending', 'running', 'processing')
                ORDER BY c.started_at DESC
                LIMIT %d OFFSET %d",
                $limit,
                $offset,
            ),
            ARRAY_A,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $rows ?: array();
    }

    /**
     * Count in-flight claim rows.
     *
     * @return int
     */
    public function count_active_events(): int {
        global $wpdb;

        $claims = $this->claims_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$claims} WHERE status IN ('pending', 'in_progress')",
        );
    }

    /**
     * Return distinct dates that have activity_log entries.
     *
     * @param int $limit Max dates to return.
     * @return array<int, string> Dates in Y-m-d format, newest first.
     */
    public function find_available_dates( int $limit = 90 ): array {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT DATE(logged_at) AS d
                FROM {$al}
                ORDER BY d DESC
                LIMIT %d",
                $limit,
            ),
        );

        return $rows ?: array();
    }

    /**
     * Find individual field-level rows for a (source_kind, source_id, target_lang) group on a date.
     *
     * @param string $date_ymd    Y-m-d.
     * @param string $source_kind 'post' or 'term'.
     * @param int    $source_id   Source ID.
     * @param string $target_lang Target language slug.
     * @return array<int, array<string, mixed>>
     */
    public function find_tasks_for_group( string $date_ymd, string $source_kind, int $source_id, string $target_lang ): array {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT reference, status, error_message, logged_at
                FROM {$al}
                WHERE DATE(logged_at) = %s
                  AND source_kind = %s
                  AND source_id = %d
                  AND target_lang = %s
                ORDER BY logged_at ASC",
                $date_ymd,
                $source_kind,
                $source_id,
                $target_lang,
            ),
            ARRAY_A,
        );

        return $rows ?: array();
    }

    /**
     * Find failed activity rows for a date (for the Errors tab).
     *
     * @param string $date_ymd Y-m-d.
     * @param int    $offset   Row offset.
     * @param int    $limit    Max rows.
     * @return array<int, array<string, mixed>>
     */
    public function find_errors_for_date( string $date_ymd, int $offset, int $limit ): array {
        global $wpdb;
        $al  = $wpdb->prefix . 'pllat_activity_log';
        $idx = $this->index_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    al.id AS log_id,
                    al.source_kind,
                    al.source_id,
                    al.target_lang,
                    al.run_id,
                    al.reference,
                    al.error_message,
                    al.logged_at,
                    CRC32(CONCAT(al.source_kind, al.source_id, al.target_lang, DATE(al.logged_at))) AS group_key,
                    COALESCE(NULLIF(al.source_lang, ''), i.source_lang) AS source_lang,
                    i.target_id,
                    i.content_subtype
                FROM {$al} al
                LEFT JOIN {$idx} i
                    ON i.source_kind = al.source_kind
                    AND i.source_id  = al.source_id
                    AND i.target_lang = al.target_lang
                WHERE DATE(al.logged_at) = %s
                  AND al.status = 'failed'
                ORDER BY al.logged_at DESC
                LIMIT %d OFFSET %d",
                $date_ymd,
                $limit,
                $offset,
            ),
            ARRAY_A,
        );

        return $rows ?: array();
    }

    /**
     * Count failed rows for a date.
     *
     * @param string $date_ymd Y-m-d.
     * @return int
     */
    public function count_errors_for_date( string $date_ymd ): int {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$al} WHERE DATE(logged_at) = %s AND status = 'failed'",
                $date_ymd,
            ),
        );
    }

    /**
     * Check whether any bulk run is currently pending or running.
     *
     * @return bool
     */
    public function has_active_run(): bool {
        global $wpdb;

        $runs = $this->runs_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$runs} WHERE status IN ('pending', 'running', 'processing')",
        ) > 0;
    }
}
