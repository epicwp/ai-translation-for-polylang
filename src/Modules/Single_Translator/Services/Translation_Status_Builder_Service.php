<?php
/**
 * Translation_Status_Builder_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Content\Services\Traits\Reference_Parsing_Trait;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;

/**
 * Service for building translation status information for UI display.
 *
 * Lean replacement — reads from Translation_Index_Repository (index rows) and
 * pllat_claims (claim rows). No Job model, no Task model.
 *
 * Per-(source, target_lang) status logic:
 * - translated  : index row exists, target_id NOT NULL, outdated_at NULL
 * - outdated    : index row exists, target_id NOT NULL, outdated_at NOT NULL
 * - in_progress : pllat_claims claim with status IN ('pending','in_progress')
 *                 AND run_id NOT NULL
 * - not_translated : no index row or target_id NULL
 */
class Translation_Status_Builder_Service {
    use Reference_Parsing_Trait;

    /**
     * Cap on the number of completed field refs included in the progress
     * payload — large enough to cover any realistic post (~10-30 fields)
     * without unbounded growth on edge cases.
     */
    private const COMPLETED_FIELDS_CAP = 30;

    /**
     * Constructor.
     *
     * @param Language_Manager             $language_manager The language manager.
     * @param Translation_Index_Repository $index_repository The translation index repository.
     */
    public function __construct(
        private Language_Manager $language_manager,
        private Translation_Index_Repository $index_repository,
    ) {
    }

    /**
     * Build status information for a single target language.
     *
     * @param string $type           Content type (post or term).
     * @param int    $id             Content ID.
     * @param string $lang_from      Source language code.
     * @param string $lang_to        Target language code.
     * @param array  $language_names Map of language codes to display names.
     * @return array Status information for the language.
     */
    public function build_language_status( string $type, int $id, string $lang_from, string $lang_to, array $language_names ): array {
        $kind      = 'post' === $type ? Translation_Index_Repository::KIND_POST : Translation_Index_Repository::KIND_TERM;
        $index_row = $this->index_repository->find_pair( $kind, $id, $lang_to );
        $claim     = $this->find_active_claim( $type, $id, $lang_to );

        $translation_id = $this->resolve_translation_id( $index_row );

        $status = array(
            'language'       => $lang_to,
            'language_name'  => $language_names[ $lang_to ] ?? $lang_to,
            'translation_id' => $translation_id,
            'run_id'         => $claim ? (int) $claim['run_id'] : null,
            'job_id'         => $claim ? (int) $claim['id'] : null,
            'created_at'     => $claim ? (int) $claim['created_at'] : null,
            'progress'       => $claim
                ? $this->build_progress( $type, $id, $lang_to, (int) $claim['created_at'] )
                : null,
        );

        $status = $this->apply_status_value( $status, $index_row, $claim, $type, $translation_id );

        return $status;
    }

    /**
     * Build progress payload for an active claim.
     *
     * Returns the list of field references the worker has completed since
     * the claim was created, stripped of routing prefixes (`_meta|`,
     * `_custom_data|`) via Reference_Parsing_Trait. Ordered newest-first;
     * frontend tooltip shows the full list, inline shows the count.
     *
     * @param string $type             Content type ('post' or 'term').
     * @param int    $id               Source content ID.
     * @param string $lang_to          Target language slug.
     * @param int    $claim_created_at Claim creation timestamp.
     * @return array{completed:int, fields:array<int, string>}
     */
    private function build_progress( string $type, int $id, string $lang_to, int $claim_created_at ): array {
        global $wpdb;

        // pllat_activity_log.logged_at is written by the worker as GMT (via
        // \current_time('mysql', true)). Convert claim.created_at (a unix
        // timestamp) to a GMT-formatted string so the comparison stays in
        // the same timezone regardless of the MySQL server's session TZ.
        $threshold_gmt = \gmdate( 'Y-m-d H:i:s', $claim_created_at );

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT reference FROM {$wpdb->prefix}pllat_activity_log
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s
                   AND status = 'completed'
                   AND logged_at >= %s
                 ORDER BY logged_at DESC
                 LIMIT %d",
                $type,
                $id,
                $lang_to,
                $threshold_gmt,
                self::COMPLETED_FIELDS_CAP,
            ),
        );

        $fields = array();
        foreach ( $rows as $reference ) {
            $reference = (string) $reference;
            if ( '' === $reference ) {
                continue;
            }
            $fields[] = $this->parse_reference( $reference )['field'];
        }

        return array(
            'completed' => \count( $fields ),
            'fields'    => $fields,
        );
    }

    /**
     * Build a map of language codes to display names.
     *
     * @return array Map of language slug => display name.
     */
    public function build_language_names_map(): array {
        $languages_data = $this->language_manager->get_languages_data();
        $language_names = array();

        foreach ( $languages_data as $lang_data ) {
            $slug   = \is_object( $lang_data ) ? $lang_data->slug : $lang_data['slug'];
            $name   = \is_object( $lang_data ) ? $lang_data->name : $lang_data['name'];
            $locale = \is_object( $lang_data ) ? ( $lang_data->locale ?? '' ) : ( $lang_data['locale'] ?? '' );

            $language_names[ $slug ] = '' !== $locale ? "{$name} ({$locale})" : $name;
        }

        return $language_names;
    }

    /**
     * Analyze in-flight state to determine UI flags.
     *
     * Replaces the old Job-model-based analyze_job_timing. Derives flags from
     * pllat_claims claim rows for the given source content item across all languages.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return array Timing flags: has_active, has_recent_error (always false in lean), has_recent_success (always false in lean).
     */
    public function analyze_timing( string $type, int $id ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'pllat_claims';
        $active = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE source_kind = %s AND source_id = %d AND run_id IS NOT NULL
                   AND status IN ('pending', 'in_progress')",
                $type,
                $id,
            ),
        );

        return array(
            'has_active'         => $active > 0,
            // In lean, error and success timing come from index outdated_at / target_id,
            // not from job timestamps. These flags remain for API compatibility.
            'has_recent_error'   => false,
            'has_recent_success' => false,
        );
    }

    /**
     * Find an active claim row for a (source, lang_to) pair.
     *
     * Returns the first claim with run_id NOT NULL and status in pending/in_progress.
     *
     * @param string $type    Content type (post or term).
     * @param int    $id      Source content ID.
     * @param string $lang_to Target language slug.
     * @return array|null Claim row or null.
     */
    private function find_active_claim( string $type, int $id, string $lang_to ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'pllat_claims';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, run_id, status, created_at FROM {$table}
                 WHERE source_kind = %s AND source_id = %d AND target_lang = %s
                   AND run_id IS NOT NULL
                   AND status IN ('pending', 'in_progress')
                 ORDER BY id DESC LIMIT 1",
                $type,
                $id,
                $lang_to,
            ),
            \ARRAY_A,
        );

        return $row ?? null;
    }

    /**
     * Resolve the translation target ID from an index row.
     *
     * @param array|null $index_row Translation index row.
     * @return int Target ID or 0.
     */
    private function resolve_translation_id( ?array $index_row ): int {
        if ( null === $index_row ) {
            return 0;
        }

        return (int) ( $index_row['target_id'] ?? 0 );
    }

    /**
     * Apply the derived status value to the status array.
     *
     * Priority order:
     * 1. Active claim (pending/in_progress with run_id) → 'in_progress' or 'queued'
     * 2. Index: target_id NOT NULL, outdated_at NOT NULL → 'outdated'
     * 3. Index: target_id NOT NULL, outdated_at NULL → 'translated'
     * 4. No index or target_id NULL → null (not_translated)
     *
     * @param array      $status         Base status array.
     * @param array|null $index_row      Translation index row or null.
     * @param array|null $claim          Active claim row or null.
     * @param string     $type           Content type.
     * @param int        $translation_id Resolved target ID.
     * @return array Status array with status field set.
     */
    private function apply_status_value( array $status, ?array $index_row, ?array $claim, string $type, int $translation_id ): array {
        if ( null !== $claim ) {
            $claim_status    = (string) $claim['status'];
            $status['status'] = 'in_progress' === $claim_status ? 'in_progress' : 'queued';
            return $status;
        }

        if ( $translation_id > 0 ) {
            $outdated = isset( $index_row['outdated_at'] ) && null !== $index_row['outdated_at'];

            if ( $outdated ) {
                $status['status'] = 'outdated';
            } else {
                $status['status']        = 'translated';
                $status['translated_at'] = $this->get_translation_timestamp( $type, $translation_id );
            }

            return $status;
        }

        $status['status'] = null;
        return $status;
    }

    /**
     * Get the timestamp for when a translation was last modified.
     *
     * @param string $type           Content type (post or term).
     * @param int    $translation_id The translation target ID.
     * @return int Timestamp or 0 if not available.
     */
    private function get_translation_timestamp( string $type, int $translation_id ): int {
        if ( 'post' === $type ) {
            // Use the GMT modified time so translated_at is a true absolute
            // instant. post_modified is a site-LOCAL string and WordPress runs
            // PHP in UTC, so strtotime( $post->post_modified ) would parse the
            // local string as UTC — offsetting the instant by the site's tz.
            // The card's JS renders in the browser tz, which would then add the
            // offset a second time (ticket TS-68420275).
            $modified_gmt = \get_post_modified_time( 'U', true, $translation_id );
            return false !== $modified_gmt ? (int) $modified_gmt : 0;
        }

        $term = \get_term( $translation_id );
        return $term && ! \is_wp_error( $term ) ? \time() : 0;
    }
}
