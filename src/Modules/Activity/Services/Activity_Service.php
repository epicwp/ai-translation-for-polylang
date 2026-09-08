<?php
declare(strict_types=1);

namespace PLLAT\Activity\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Activity\Repositories\Activity_Repository;
use PLLAT\Content\Services\Traits\Reference_Parsing_Trait;

/**
 * Lean activity service.
 * Derives activity feed from activity_log (done/errors), claims (active), and bulk_runs (has_active_run).
 * No dependency on Job model, Task model, or Run model.
 */
class Activity_Service {
    use Reference_Parsing_Trait;

    /**
     * Constructor.
     *
     * @param Activity_Repository $repository The lean activity repository.
     */
    public function __construct(
        private Activity_Repository $repository,
    ) {
    }

    /**
     * Get paginated activity items for a date.
     *
     * @param string $date     Date in Y-m-d format.
     * @param int    $page     1-based page number.
     * @param int    $per_page Items per page.
     * @param string $status   'all' | 'active' | 'done' | 'errors'.
     * @return array{jobs: array<int, array<string, mixed>>, has_active_run: bool, failed_count: int, pagination: array{has_more: bool, page: int, per_page: int}}
     */
    public function get_activity( string $date, int $page, int $per_page, string $status ): array {
        $offset = ( $page - 1 ) * $per_page;

        if ( 'errors' === $status ) {
            return $this->errors_response( $date, $offset, $per_page, $page );
        }

        if ( 'active' === $status ) {
            return $this->active_only_response( $offset, $per_page, $page );
        }

        if ( 'done' === $status ) {
            return $this->done_only_response( $date, $offset, $per_page, $page );
        }

        // 'all': merge active events first, then done events.
        return $this->all_response( $date, $offset, $per_page, $page );
    }

    /**
     * Get dates that have field_state activity.
     *
     * @return array<int, string> Dates in Y-m-d format, newest first.
     */
    public function get_available_dates(): array {
        return $this->repository->find_available_dates( 90 );
    }

    /**
     * Build response for status=active (in-flight claims only).
     *
     * @param int $offset   DB offset.
     * @param int $per_page Per page.
     * @param int $page     Current page.
     * @return array<string, mixed>
     */
    private function active_only_response( int $offset, int $per_page, int $page ): array {
        $rows     = $this->repository->find_active_events( $offset, $per_page + 1 );
        $has_more = count( $rows ) > $per_page;

        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        return array(
            'failed_count'   => $this->repository->count_errors_for_date( \gmdate( 'Y-m-d' ) ),
            'has_active_run' => $this->repository->has_active_run(),
            'jobs'           => array_values( array_map( array( $this, 'format_active_event' ), $rows ) ),
            'pagination'     => array(
                'has_more' => $has_more,
                'page'     => $page,
                'per_page' => $per_page,
            ),
        );
    }

    /**
     * Build response for status=done (field_state groups only).
     *
     * @param string $date    Date in Y-m-d.
     * @param int    $offset  DB offset.
     * @param int    $per_page Per page.
     * @param int    $page    Current page.
     * @return array<string, mixed>
     */
    private function done_only_response( string $date, int $offset, int $per_page, int $page ): array {
        $rows     = $this->repository->find_done_events( $date, $offset, $per_page + 1 );
        $has_more = count( $rows ) > $per_page;

        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        $jobs = array_values( array_map(
            function( array $row ) use ( $date ): array {
                $tasks = $this->load_tasks_for_row( $row, $date );
                return $this->format_done_event( $row, $tasks );
            },
            $rows,
        ) );

        return array(
            'failed_count'   => $this->repository->count_errors_for_date( $date ),
            'has_active_run' => $this->repository->has_active_run(),
            'jobs'           => $jobs,
            'pagination'     => array(
                'has_more' => $has_more,
                'page'     => $page,
                'per_page' => $per_page,
            ),
        );
    }

    /**
     * Build response for status=all (active first, then done).
     *
     * Pagination runs over done events only (active events are always shown first
     * on page 1 and disappear once complete). This matches the old behavior where
     * in_progress jobs floated to the top.
     *
     * @param string $date    Date in Y-m-d.
     * @param int    $offset  DB offset (applied to done events; active events appear before offset on page 1).
     * @param int    $per_page Per page.
     * @param int    $page    Current page.
     * @return array<string, mixed>
     */
    private function all_response( string $date, int $offset, int $per_page, int $page ): array {
        $active_rows = array();
        $failed_rows = array();
        $done_offset = $offset;

        // On page 1, prepend all active claims and the day's failures (no
        // pagination on either — like active claims, failures are the
        // "needs attention" items and must surface in the main timeline,
        // not only behind the separate Errors tab).
        if ( 1 === $page ) {
            $active_rows = $this->repository->find_active_events( 0, 100 );
            $failed_rows = $this->repository->find_errors_for_date( $date, 0, 100 );
            $done_offset = 0;
        }

        $remaining = $per_page - count( $active_rows ) - count( $failed_rows );

        if ( $remaining > 0 ) {
            $done_rows = $this->repository->find_done_events( $date, $done_offset, $remaining + 1 );
            $has_more  = count( $done_rows ) > $remaining;

            if ( $has_more ) {
                $done_rows = array_slice( $done_rows, 0, $remaining );
            }
        } else {
            $done_rows = array();
            $has_more  = $this->repository->count_done_events( $date ) > 0;
        }

        // Active claims float to the very top (they are happening now).
        // Failed and completed events are interleaved strictly newest-first
        // so a unit that failed then later succeeded shows its newer
        // Completed entry ABOVE the older Failed entry. MySQL datetime
        // strings sort lexicographically == chronologically. Done groups
        // sort by their latest field activity, failures by logged_at.
        $mixed = array();
        foreach ( $failed_rows as $row ) {
            $mixed[] = array(
                'ts'  => (string) $row['logged_at'],
                'job' => $this->format_error_event( $row ),
            );
        }
        foreach ( $done_rows as $row ) {
            $mixed[] = array(
                'ts'  => (string) ( $row['latest'] ?? $row['earliest'] ?? '' ),
                'job' => $this->format_done_event( $row, $this->load_tasks_for_row( $row, $date ) ),
            );
        }
        \usort( $mixed, static fn( array $a, array $b ): int => \strcmp( (string) $b['ts'], (string) $a['ts'] ) );

        $jobs = array_merge(
            array_values( array_map( array( $this, 'format_active_event' ), $active_rows ) ),
            array_values( array_map( static fn( array $m ): array => $m['job'], $mixed ) ),
        );

        return array(
            'failed_count'   => $this->repository->count_errors_for_date( $date ),
            'has_active_run' => $this->repository->has_active_run(),
            'jobs'           => $jobs,
            'pagination'     => array(
                'has_more' => $has_more,
                'page'     => $page,
                'per_page' => $per_page,
            ),
        );
    }

    /**
     * Build response for status=errors (failed activity_log rows).
     *
     * @param string $date     Date in Y-m-d.
     * @param int    $offset   DB offset.
     * @param int    $per_page Per page.
     * @param int    $page     Current page.
     * @return array<string, mixed>
     */
    private function errors_response( string $date, int $offset, int $per_page, int $page ): array {
        $rows     = $this->repository->find_errors_for_date( $date, $offset, $per_page + 1 );
        $has_more = count( $rows ) > $per_page;

        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        $jobs = array_values( array_map( array( $this, 'format_error_event' ), $rows ) );

        return array(
            'failed_count'   => $this->repository->count_errors_for_date( $date ),
            'has_active_run' => $this->repository->has_active_run(),
            'jobs'           => $jobs,
            'pagination'     => array(
                'has_more' => $has_more,
                'page'     => $page,
                'per_page' => $per_page,
            ),
        );
    }

    /**
     * Format a failed activity_log row into the frontend job shape.
     *
     * @param array<string, mixed> $row Raw row from Activity_Repository::find_errors_for_date.
     * @return array<string, mixed>
     */
    private function format_error_event( array $row ): array {
        $source_kind     = (string) $row['source_kind'];
        $source_id       = (int) $row['source_id'];
        $target_lang     = (string) $row['target_lang'];
        $source_lang     = isset( $row['source_lang'] ) ? (string) $row['source_lang'] : '';
        $target_id       = isset( $row['target_id'] ) && null !== $row['target_id'] ? (int) $row['target_id'] : null;
        $content_subtype = isset( $row['content_subtype'] ) ? (string) $row['content_subtype'] : '';
        // Unique per failed activity_log row (its PK). The done-event id is
        // the CRC32 group_key; a failed event must NOT reuse that or the
        // same post (failed earlier, completed later) yields jobs with
        // duplicate ids — the frontend keys list items by id and flips the
        // post between Completed and Failed on poll vs page-load.
        $log_id = (int) ( $row['log_id'] ?? 0 );

        $title      = $this->resolve_title( $source_kind, $source_id, $content_subtype );
        $source_url = $this->resolve_edit_url( $source_kind, $source_id, $content_subtype );
        $target_url = null !== $target_id ? $this->resolve_edit_url( $source_kind, $target_id, $content_subtype ) : null;

        return array(
            'completed_tasks'    => 0,
            'content_slug'       => $content_subtype,
            'content_type'       => $source_kind,
            'content_type_label' => $this->get_content_type_label( $content_subtype ),
            'duration'           => null,
            'id'                 => $log_id,
            'id_from'            => $source_id,
            'id_to'              => $target_id,
            'issue'              => (string) ( $row['error_message'] ?? '' ),
            'lang_from'          => $source_lang,
            'lang_to'            => $target_lang,
            'source_url'         => $source_url,
            'started_at'         => (string) $row['logged_at'],
            'status'             => 'failed',
            'target_url'         => $target_url,
            'tasks'              => array(
                array(
                    'reference' => isset( $row['reference'] ) && null !== $row['reference']
                        ? $this->parse_reference( (string) $row['reference'] )['field']
                        : null,
                    'status'    => 'failed',
                    'issue'     => (string) ( $row['error_message'] ?? '' ),
                ),
            ),
            'title'              => $title,
            'total_tasks'        => 1,
        );
    }

    /**
     * Load field-level task rows for a done-event group.
     *
     * @param array<string, mixed> $row  Done-event row (from find_done_events).
     * @param string               $date Date in Y-m-d format.
     * @return array<int, array{reference: string|null, status: string, issue: string|null}>
     */
    private function load_tasks_for_row( array $row, string $date ): array {
        $raw = $this->repository->find_tasks_for_group(
            $date,
            (string) $row['source_kind'],
            (int) $row['source_id'],
            (string) $row['target_lang'],
        );

        return array_values( array_map(
            fn( array $task ): array => array(
                'reference' => null !== $task['reference']
                    ? $this->parse_reference( (string) $task['reference'] )['field']
                    : null,
                'status'    => $task['status'],
                'issue'     => $task['error_message'] ?? null,
            ),
            $raw,
        ) );
    }

    /**
     * Format a done-event row (field_state group) into the frontend job shape.
     *
     * @param array<string, mixed> $row Raw row from Activity_Repository::find_done_events.
     * @return array<string, mixed>
     */
    private function format_done_event( array $row, array $tasks = array() ): array {
        $source_kind    = (string) $row['source_kind'];
        $source_id      = (int) $row['source_id'];
        $target_lang    = (string) $row['target_lang'];
        $source_lang    = isset( $row['source_lang'] ) ? (string) $row['source_lang'] : '';
        $target_id      = isset( $row['target_id'] ) && null !== $row['target_id'] ? (int) $row['target_id'] : null;
        $content_subtype = isset( $row['content_subtype'] ) ? (string) $row['content_subtype'] : '';
        $field_count    = (int) $row['field_count'];
        $group_key      = (int) $row['group_key'];

        $title      = $this->resolve_title( $source_kind, $source_id, $content_subtype );
        $source_url = $this->resolve_edit_url( $source_kind, $source_id, $content_subtype );
        $target_url = null !== $target_id
            ? $this->resolve_edit_url( $source_kind, $target_id, $content_subtype )
            : null;

        return array(
            'completed_tasks'    => $field_count,
            'content_slug'       => $content_subtype,
            'content_type'       => $source_kind,
            'content_type_label' => $this->get_content_type_label( $content_subtype ),
            'duration'           => null,
            'id'                 => $group_key,
            'id_from'            => $source_id,
            'id_to'              => $target_id,
            'issue'              => null,
            'lang_from'          => $source_lang,
            'lang_to'            => $target_lang,
            'source_url'         => $source_url,
            'started_at'         => (string) $row['earliest'],
            'status'             => 'completed',
            'target_url'         => $target_url,
            'tasks'              => $tasks,
            'title'              => $title,
            'total_tasks'        => $field_count,
        );
    }

    /**
     * Format an active-event row (jobs claim) into the frontend job shape.
     *
     * @param array<string, mixed> $row Raw row from Activity_Repository::find_active_events.
     * @return array<string, mixed>
     */
    private function format_active_event( array $row ): array {
        $source_kind    = (string) $row['source_kind'];
        $source_id      = (int) $row['source_id'];
        $target_lang    = (string) $row['target_lang'];
        $source_lang    = (string) $row['source_lang'];
        $target_id      = isset( $row['target_id'] ) && null !== $row['target_id'] ? (int) $row['target_id'] : null;
        $content_subtype = (string) $row['content_subtype'];
        $job_id         = (int) $row['id'];

        // started_at in jobs is a Unix timestamp (bigint); convert to ISO 8601.
        $started_unix = (int) $row['started_at'];
        $started_at   = $started_unix > 0 ? \gmdate( 'c', $started_unix ) : \gmdate( 'c' );

        $title      = $this->resolve_title( $source_kind, $source_id, $content_subtype );
        $source_url = $this->resolve_edit_url( $source_kind, $source_id, $content_subtype );
        $target_url = null !== $target_id
            ? $this->resolve_edit_url( $source_kind, $target_id, $content_subtype )
            : null;

        return array(
            'completed_tasks'    => 0,
            'content_slug'       => $content_subtype,
            'content_type'       => $source_kind,
            'content_type_label' => $this->get_content_type_label( $content_subtype ),
            'duration'           => null,
            'id'                 => $job_id,
            'id_from'            => $source_id,
            'id_to'              => $target_id,
            'issue'              => null,
            'lang_from'          => $source_lang,
            'lang_to'            => $target_lang,
            'source_url'         => $source_url,
            'started_at'         => $started_at,
            'status'             => 'in_progress',
            'target_url'         => $target_url,
            'tasks'              => array(),
            'title'              => $title,
            'total_tasks'        => 0,
        );
    }

    /**
     * Resolve a human-readable title for the source content.
     *
     * Falls back to '#ID' if the content has been deleted.
     *
     * @param string $source_kind    'post' or 'term'.
     * @param int    $source_id      The source post or term ID.
     * @param string $content_subtype The post type or taxonomy slug.
     * @return string
     */
    private function resolve_title( string $source_kind, int $source_id, string $content_subtype ): string {
        if ( 'post' === $source_kind ) {
            $post = \get_post( $source_id );
            return $post?->post_title ?? \sprintf( '#%d', $source_id );
        }

        if ( 'nav_menu' === $content_subtype ) {
            $term = \get_term( $source_id, 'nav_menu' );
            if ( ! \is_wp_error( $term ) && null !== $term ) {
                return $term->name;
            }
            return \sprintf( '#%d', $source_id );
        }

        $term = \get_term( $source_id );

        if ( \is_wp_error( $term ) || null === $term ) {
            return \sprintf( '#%d', $source_id );
        }

        return $term->name;
    }

    /**
     * Resolve the admin edit URL for a post or term.
     *
     * @param string $source_kind    'post' or 'term'.
     * @param int    $content_id     Post or term ID.
     * @param string $content_subtype Post type or taxonomy slug.
     * @return string|null
     */
    private function resolve_edit_url( string $source_kind, int $content_id, string $content_subtype ): ?string {
        if ( 'post' === $source_kind ) {
            $url = \get_edit_post_link( $content_id, 'raw' );
            return \is_string( $url ) && \strlen( $url ) > 0 ? $url : null;
        }

        $taxonomy = \strlen( $content_subtype ) > 0 ? $content_subtype : null;
        $term     = null !== $taxonomy ? \get_term( $content_id, $taxonomy ) : \get_term( $content_id );

        if ( \is_wp_error( $term ) || null === $term ) {
            return null;
        }

        $url = \get_edit_term_link( $content_id, $term->taxonomy );
        return \is_string( $url ) && \strlen( $url ) > 0 ? $url : null;
    }

    /**
     * Get a human-readable label for a post type or taxonomy.
     *
     * @param string $content_subtype Post type or taxonomy slug.
     * @return string
     */
    private function get_content_type_label( string $content_subtype ): string {
        if ( 'nav_menu' === $content_subtype ) {
            return \__( 'Nav menu', 'ai-translation-for-polylang' );
        }

        $post_type = \get_post_type_object( $content_subtype );

        if ( null !== $post_type ) {
            return $post_type->labels->singular_name;
        }

        $taxonomy = \get_taxonomy( $content_subtype );

        if ( false !== $taxonomy ) {
            return $taxonomy->labels->singular_name;
        }

        return \ucfirst( $content_subtype );
    }
}
