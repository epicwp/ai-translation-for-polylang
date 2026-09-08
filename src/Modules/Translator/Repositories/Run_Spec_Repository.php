<?php
declare(strict_types=1);

namespace PLLAT\Translator\Repositories;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Models\Run_Spec;

/**
 * Lean read repository for pllat_bulk_runs.
 *
 * Returns plain arrays plus a Run_Spec value object.
 * Status semantics: lean runs use 'processing'; 'pending' is
 * accepted as transitional. Other statuses are terminal.
 *
 * Shape returned by find_*:
 *   array{
 *     id: int,
 *     status: string,
 *     spec: Run_Spec,
 *     started_at: int,
 *     completed_at: int,
 *     last_heartbeat: int,
 *   }
 */
class Run_Spec_Repository {

    public const ACTIVE_STATUSES = array( 'pending', 'processing' );

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find_active(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, status, spec, started_at, completed_at, last_heartbeat
             FROM {$wpdb->prefix}pllat_bulk_runs
             WHERE status IN ('pending', 'processing')
             ORDER BY id DESC",
            \ARRAY_A,
        );
        return $this->hydrate_rows( $rows );
    }

    public function find_by_id( int $run_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, spec, started_at, completed_at, last_heartbeat
                 FROM {$wpdb->prefix}pllat_bulk_runs
                 WHERE id = %d",
                $run_id,
            ),
            \ARRAY_A,
        );
        if ( null === $row ) {
            return null;
        }
        return $this->hydrate_row( $row );
    }

    /**
     * Find the first active run whose spec covers the given content type.
     *
     * "Covers" = post_query.post_types contains $entity OR
     *            term_query.taxonomies contains $entity.
     */
    public function find_active_for_content_type( string $entity ): ?array {
        foreach ( $this->find_active() as $run ) {
            if ( $this->spec_covers_entity( $run['spec'], $entity ) ) {
                return $run;
            }
        }
        return null;
    }

    /**
     * Map a run's spec content types onto the (post_types, taxonomies)
     * tuple that the dashboard groups by. Used by Dashboard_Data_Service
     * to mark cards as translating.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public function content_types_for_run( Run_Spec $spec ): array {
        $post_query = $spec->post_query();
        $term_query = $spec->term_query();
        $post_types = ( null !== $post_query && isset( $post_query['post_types'] ) && \is_array( $post_query['post_types'] ) )
            ? \array_values( \array_map( 'strval', $post_query['post_types'] ) )
            : array();
        $taxonomies = ( null !== $term_query && isset( $term_query['taxonomies'] ) && \is_array( $term_query['taxonomies'] ) )
            ? \array_values( \array_map( 'strval', $term_query['taxonomies'] ) )
            : array();
        return array( $post_types, $taxonomies );
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrate_rows( ?array $rows ): array {
        if ( null === $rows ) {
            return array();
        }
        $out = array();
        foreach ( $rows as $row ) {
            $hydrated = $this->hydrate_row( $row );
            if ( null !== $hydrated ) {
                $out[] = $hydrated;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate_row( array $row ): ?array {
        $json = (string) ( $row['spec'] ?? '' );
        if ( '' === $json ) {
            return null;
        }
        return array(
            'id'             => (int) $row['id'],
            'status'         => (string) $row['status'],
            'spec'           => Run_Spec::from_json( $json ),
            'started_at'     => (int) ( $row['started_at'] ?? 0 ),
            'completed_at'   => (int) ( $row['completed_at'] ?? 0 ),
            'last_heartbeat' => (int) ( $row['last_heartbeat'] ?? 0 ),
        );
    }

    private function spec_covers_entity( Run_Spec $spec, string $entity ): bool {
        list( $post_types, $taxonomies ) = $this->content_types_for_run( $spec );
        return \in_array( $entity, $post_types, true ) || \in_array( $entity, $taxonomies, true );
    }
}
