<?php
declare(strict_types=1);

namespace PLLAT\Status\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use PLLAT\Translation_Index\Services\Prime_Status_Service;
use PLLAT\Translator\Repositories\Provider_Health_Repository;

/**
 * Assembles a single read-only diagnostic blob from signals the plugin already
 * tracks (versions, schema, Action Scheduler health, WP-Cron, provider circuit
 * breaker, connectivity cache, translation-index counts).
 *
 * Purpose: remote diagnosis without SSH. A supporter who has been granted
 * Support Access pulls GET /pllat/v1/system-report and sees the whole picture
 * in one call — what cost an SSH session before.
 *
 * STRICTLY READ-ONLY. No method here writes state, performs a billable call,
 * or makes an outbound HTTP request. The report must be safe to pull
 * repeatedly. New (also read-only) sections — migration ledger, index coverage
 * vs Polylang, prime timing — are layered on in follow-up increments.
 */
class System_Report_Service {

    private const CRON_OVERDUE_THRESHOLD = 60;

    public function __construct(
        private Settings_Service $settings,
        private Translation_Index_Repository $index,
        private Health_Service $health,
        private Cron_Status_Service $cron,
        private Provider_Health_Repository $provider_health,
        private Index_Coverage_Service $coverage,
        private Prime_Status_Service $prime_status,
    ) {}

    /**
     * Build the report.
     *
     * @param array<int, string> $includes Optional heavier sections to add
     *                                      (currently 'coverage' — the index-vs-Polylang
     *                                      drift probe, opt-in because it scans
     *                                      translation-group descriptions).
     * @return array<string, mixed>
     */
    public function generate( array $includes = array() ): array {
        $report = array(
            'generated_at'       => \current_time( 'mysql' ),
            'meta'               => $this->meta(),
            'index'              => $this->index_section(),
            'prime'              => $this->prime_status->get_timing(),
            'migrations'         => $this->migrations_section(),
            'action_scheduler'   => $this->health->get_scheduler_health(),
            'cron'               => $this->cron_section(),
            'provider_circuit'   => $this->provider_circuit_section(),
            'connectivity_cache' => $this->connectivity_cache_section(),
        );

        if ( \in_array( 'coverage', $includes, true ) ) {
            $report['coverage'] = $this->coverage->compute();
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array {
        $db_version = (string) \get_option( 'pllat_db_version', '' );
        $target     = \defined( 'PLLAT_DB_VERSION' ) ? PLLAT_DB_VERSION : '';
        $polylang   = \get_option( 'polylang' );

        return array(
            'plugin_version'      => \defined( 'PLLAT_PLUGIN_VERSION' ) ? PLLAT_PLUGIN_VERSION : 'unknown',
            'db_version'          => '' !== $db_version ? $db_version : 'unset',
            'db_version_target'   => $target,
            'db_up_to_date'       => $db_version === $target,
            'wp_version'          => \get_bloginfo( 'version' ),
            'php_version'         => \PHP_VERSION,
            'polylang_version'    => \defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : 'unknown',
            'polylang_pro'        => \defined( 'POLYLANG_PRO_VERSION' ) || \class_exists( 'PLL_Pro', false ),
            'woocommerce_version' => \defined( 'WC_VERSION' ) ? WC_VERSION : null,
            'active_provider'     => $this->settings->get_active_translation_api(),
            'active_model'        => $this->settings->get_translation_model(),
            'media_translation'   => \is_array( $polylang ) && ! empty( $polylang['media_support'] ),
            'debug_mode'          => (bool) \get_option( 'pllat_debug_mode' ),
        );
    }

    /**
     * Migration ledger: which migrations ran, their outcome, and whether any
     * failed. Written by Installer::record_migration().
     *
     * @return array{count:int, has_failures:bool, recent:array<int, array<string,mixed>>}
     */
    private function migrations_section(): array {
        $ledger = \get_option( 'pllat_migration_ledger', array() );
        if ( ! \is_array( $ledger ) ) {
            $ledger = array();
        }

        $has_failures = false;
        foreach ( $ledger as $entry ) {
            if ( \is_array( $entry ) && 'failed' === ( $entry['status'] ?? '' ) ) {
                $has_failures = true;
                break;
            }
        }

        return array(
            'count'        => \count( $ledger ),
            'has_failures' => $has_failures,
            'recent'       => \array_slice( $ledger, -20 ),
        );
    }

    /**
     * @return array{total:int, by_subtype:array<string,int>}
     */
    private function index_section(): array {
        return array(
            'total'      => $this->index->count_all(),
            'by_subtype' => $this->index->count_by_subtype(),
        );
    }

    /**
     * @return array{disable_wp_cron_defined:bool, oldest_overdue_seconds:int|null}
     */
    private function cron_section(): array {
        return array(
            'disable_wp_cron_defined' => $this->cron->is_disable_wp_cron_defined(),
            'oldest_overdue_seconds'  => $this->cron->get_oldest_overdue_seconds( self::CRON_OVERDUE_THRESHOLD ),
        );
    }

    /**
     * Circuit-breaker state for the active provider (null = no record yet).
     *
     * @return array<string, mixed>|null
     */
    private function provider_circuit_section(): ?array {
        $provider = $this->settings->get_active_translation_api();
        if ( '' === $provider ) {
            return null;
        }
        $row = $this->provider_health->get( $provider );
        if ( null === $row ) {
            return null;
        }
        $row['circuit_open']         = $row['circuit_open_until'] > \time();
        $row['seconds_until_close']  = $row['circuit_open'] ? $row['circuit_open_until'] - \time() : 0;
        return $row;
    }

    /**
     * Cached (non-billable) connectivity test record for the active provider.
     *
     * @return array<string, mixed>|null
     */
    private function connectivity_cache_section(): ?array {
        $provider = $this->settings->get_active_translation_api();
        if ( '' === $provider ) {
            return null;
        }
        $cached = \get_option( 'pllat_provider_connectivity_' . $provider );
        return \is_array( $cached ) ? $cached : null;
    }
}
