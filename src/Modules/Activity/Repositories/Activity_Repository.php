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

    /**
     * Newest activity row of any status for a unit.
     *
     * @param string $kind Source kind.
     * @param int    $id   Source ID.
     * @param string $lang Target language.
     * @return array{status:string, error_message:?string, logged_at:string, run_id:?int}|null
     */
    public function latest_outcome_for_unit( string $kind, int $id, string $lang ): ?array {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, error_message, logged_at, run_id
                 FROM {$al}
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s
                 ORDER BY id DESC
                 LIMIT 1",
                $kind,
                $id,
                $lang,
            ),
            ARRAY_A,
        );

        if ( ! \is_array( $row ) ) {
            return null;
        }

        return array(
            'status'        => (string) $row['status'],
            'error_message' => null === $row['error_message'] ? null : (string) $row['error_message'],
            'logged_at'     => (string) $row['logged_at'],
            'run_id'        => null === $row['run_id'] ? null : (int) $row['run_id'],
        );
    }

    /**
     * Units touched by a run, one entry per unit, newest first.
     *
     * Outcome precedence: completed (translated) > skipped > failed.
     *
     * @param int $run_id Run ID.
     * @param int $limit  Max units.
     * @return array<int, array{source_kind:string, source_id:int, target_lang:string, outcome:string, error_message:?string, logged_at:string}>
     */
    public function find_units_for_run( int $run_id, int $limit ): array {
        global $wpdb;
        $al = $wpdb->prefix . 'pllat_activity_log';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    source_kind,
                    source_id,
                    target_lang,
                    MAX(status = 'completed') AS has_completed,
                    MAX(status = 'skipped') AS has_skipped,
                    SUBSTRING_INDEX(GROUP_CONCAT(IF(status = 'skipped', error_message, NULL) ORDER BY id DESC SEPARATOR 0x1F), 0x1F, 1) AS skipped_message,
                    SUBSTRING_INDEX(GROUP_CONCAT(IF(status = 'failed', error_message, NULL) ORDER BY id DESC SEPARATOR 0x1F), 0x1F, 1) AS failed_message,
                    MAX(logged_at) AS logged_at
                 FROM {$al}
                 WHERE run_id = %d
                 GROUP BY source_kind, source_id, target_lang
                 ORDER BY MAX(logged_at) DESC, MAX(id) DESC
                 LIMIT %d",
                $run_id,
                $limit,
            ),
            ARRAY_A,
        );

        if ( ! \is_array( $rows ) ) {
            return array();
        }

        $units = array();
        foreach ( $rows as $row ) {
            $outcome = 'failed';
            $message = $row['failed_message'];
            if ( '1' === (string) $row['has_completed'] ) {
                $outcome = 'translated';
                $message = null;
            } elseif ( '1' === (string) $row['has_skipped'] ) {
                $outcome = 'skipped';
                $message = $row['skipped_message'];
            }

            $units[] = array(
                'source_kind'   => (string) $row['source_kind'],
                'source_id'     => (int) $row['source_id'],
                'target_lang'   => (string) $row['target_lang'],
                'outcome'       => $outcome,
                'error_message' => null === $message || '' === $message ? null : (string) $message,
                'logged_at'     => (string) $row['logged_at'],
            );
        }

        return $units;
    }

    /**
     * Units whose newest activity row is a failure and that have no current translation.
     *
     * @param array{kind?:string, subtype?:string, lang?:string} $filters Optional filters.
     * @param int                                                $offset  Row offset.
     * @param int                                                $limit   Max rows.
     * @return array<int, array<string, mixed>>
     */
    public function find_failed_units( array $filters, int $offset, int $limit ): array {
        global $wpdb;

        [ $where, $args ] = $this->failed_units_where( $filters );
        $args[]           = $limit;
        $args[]           = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from prefix, $where built from fixed fragments.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    al.source_kind,
                    al.source_id,
                    al.target_lang,
                    COALESCE(i.content_subtype, '') AS content_subtype,
                    al.error_message,
                    al.logged_at,
                    al.run_id
                {$this->failed_units_from()}
                WHERE {$where}
                ORDER BY al.logged_at DESC, al.id DESC
                LIMIT %d OFFSET %d",
                ...$args,
            ),
            ARRAY_A,
        );

        return \is_array( $rows ) ? $rows : array();
    }

    /**
     * Count units matching find_failed_units().
     *
     * @param array{kind?:string, subtype?:string, lang?:string} $filters Optional filters.
     * @return int
     */
    public function count_failed_units( array $filters ): int {
        global $wpdb;

        [ $where, $args ] = $this->failed_units_where( $filters );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix, $where built from fixed fragments.
        $sql = $this->prepare_args( "SELECT COUNT(*) {$this->failed_units_from()} WHERE {$where}", $args );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Failed-unit counts keyed "{kind}|{subtype}|{lang}".
     *
     * @return array<string, int>
     */
    public function count_failed_by_pair(): array {
        global $wpdb;

        [ $where, $args ] = $this->failed_units_where( array() );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix, $where built from fixed fragments.
        $sql = $this->prepare_args(
            "SELECT al.source_kind, COALESCE(i.content_subtype, '') AS content_subtype, al.target_lang, COUNT(*) AS cnt
            {$this->failed_units_from()}
            WHERE {$where}
            GROUP BY al.source_kind, COALESCE(i.content_subtype, ''), al.target_lang",
            $args,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $pairs = array();
        foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
            $key           = $row['source_kind'] . '|' . $row['content_subtype'] . '|' . $row['target_lang'];
            $pairs[ $key ] = ( $pairs[ $key ] ?? 0 ) + (int) $row['cnt'];
        }

        return $pairs;
    }

    /**
     * FROM/JOIN clause selecting each unit's newest activity row, joined to the index.
     *
     * @return string
     */
    private function failed_units_from(): string {
        global $wpdb;
        $al  = $wpdb->prefix . 'pllat_activity_log';
        $idx = $this->index_table();

        return "FROM {$al} al
                JOIN (
                    SELECT MAX(id) AS max_id
                    FROM {$al}
                    GROUP BY source_kind, source_id, target_lang
                ) latest ON latest.max_id = al.id
                LEFT JOIN {$idx} i
                    ON i.source_kind = al.source_kind
                    AND i.source_id  = al.source_id
                    AND i.target_lang = al.target_lang";
    }

    /**
     * WHERE fragment and prepare() args for the failed-units queries.
     *
     * @param array{kind?:string, subtype?:string, lang?:string} $filters Optional filters.
     * @return array{0:string, 1:array<int, string>}
     */
    private function failed_units_where( array $filters ): array {
        $where = array(
            "al.status = 'failed'",
            '(i.target_id IS NULL OR i.outdated_at IS NOT NULL)',
            'al.logged_at >= ( UTC_TIMESTAMP() - INTERVAL 90 DAY )',
        );
        $args  = array();

        if ( isset( $filters['kind'] ) && '' !== $filters['kind'] ) {
            $where[] = 'al.source_kind = %s';
            $args[]  = $filters['kind'];
        }
        if ( isset( $filters['subtype'] ) && '' !== $filters['subtype'] ) {
            $where[] = 'i.content_subtype = %s';
            $args[]  = $filters['subtype'];
        }
        if ( isset( $filters['lang'] ) && '' !== $filters['lang'] ) {
            $where[] = 'al.target_lang = %s';
            $args[]  = $filters['lang'];
        }

        return array( implode( ' AND ', $where ), $args );
    }

    /**
     * Run wpdb::prepare() only when the query has placeholders.
     *
     * @param string            $sql  SQL with %s placeholders.
     * @param array<int, mixed> $args Placeholder values.
     * @return string
     */
    private function prepare_args( string $sql, array $args ): string {
        global $wpdb;

        if ( 0 === count( $args ) ) {
            return $sql;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is literal SQL plus the prefixed table name and a placeholder list sized to $params; values are prepared.
        return (string) $wpdb->prepare( $sql, ...$args );
    }
}
