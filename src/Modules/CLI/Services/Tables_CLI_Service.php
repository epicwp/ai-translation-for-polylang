<?php
namespace PLLAT\CLI\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Tables CLI service.
 */
class Tables_CLI_Service {
    /**
     * Count all tables count.
     *
     * @return void
     */
    public function tables_count_all(): void {
        \WP_CLI::line( 'Counting all tables...' );
        global $wpdb;
        $claims_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'pllat_claims' );
        $runs_count   = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'pllat_bulk_runs' );
        \WP_CLI::line( 'Claims count: ' . $claims_count );
        \WP_CLI::line( 'Runs count: ' . $runs_count );
    }
}
