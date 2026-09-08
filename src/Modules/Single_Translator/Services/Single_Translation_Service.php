<?php
/**
 * Single_Translation_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Single_Translator\Services\Translation_Status_Builder_Service;
use PLLAT\Status\Services\Health_Service;
use PLLAT\Translator\Enums\TranslatableMetaKey;
use PLLAT\Translator\Services\Cancel_Service;

/**
 * Service for single item translation from edit pages.
 *
 * Lean version: no Job/Task/Run model reads. Status comes from
 * Translation_Index_Repository + pllat_claims claim rows via
 * Translation_Status_Builder_Service.
 */
class Single_Translation_Service {
    /**
     * Constructor.
     *
     * @param Language_Manager                   $language_manager The language manager.
     * @param Translation_Status_Builder_Service $status_builder   The status builder service.
     * @param Health_Service                     $health_service   The health service.
     * @param Cancel_Service                     $cancel_service   The cancel service.
     */
    public function __construct(
        private Language_Manager $language_manager,
        private Translation_Status_Builder_Service $status_builder,
        private Health_Service $health_service,
        private Cancel_Service $cancel_service,
    ) {
    }

    /**
     * Get translation status for a specific content item.
     *
     * @param string $type Content type (post or term).
     * @param int    $id Content ID.
     * @return array Status data including system status and per-language status.
     */
    public function get_translation_status( string $type, int $id ): array {
        $lang_from      = $this->get_content_language( $type, $id );
        $language_names = $this->status_builder->build_language_names_map();
        $timing_flags   = $this->status_builder->analyze_timing( $type, $id );

        $languages = $this->build_all_language_statuses( $type, $id, $lang_from, $language_names );

        // Include scheduler health when there are active translations.
        $scheduler_health = null;
        if ( $timing_flags['has_active'] ) {
            $scheduler_health = $this->health_service->get_scheduler_health();
        }

        return \array_merge(
            $timing_flags,
            array(
                'is_excluded'      => $this->is_excluded( $type, $id ),
                'languages'        => $languages,
                'scheduler_health' => $scheduler_health,
            ),
        );
    }

    /**
     * Set exclusion status for a content item.
     * Discovery respects this flag when creating jobs.
     *
     * @param string $type     Content type (post or term).
     * @param int    $id       Content ID.
     * @param bool   $excluded Whether to exclude from translation.
     * @return void
     */
    public function set_exclusion( string $type, int $id, bool $excluded ): void {
        $this->update_exclusion_meta( $type, $id, $excluded );
    }

    /**
     * Cancel active translation for a content item.
     *
     * Finds the run_id via a direct pllat_claims query, then delegates to Cancel_Service.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return int The run ID that was cancelled.
     * @throws \Exception If no active translation found.
     */
    public function cancel_active_translation( string $type, int $id ): int {
        $run_id = $this->find_active_run_id( $type, $id );

        if ( null === $run_id ) {
            throw new \Exception(
                \esc_html__(
                    'No active translation found for this content.',
                    'ai-translation-for-polylang',
                ),
            );
        }

        $this->cancel_service->cancel( $run_id );

        return $run_id;
    }

    /**
     * Find the run_id of an active (pending/in_progress) claim for this content.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Source content ID.
     * @return int|null Run ID or null if none found.
     */
    private function find_active_run_id( string $type, int $id ): ?int {
        global $wpdb;

        $table  = $wpdb->prefix . 'pllat_claims';
        $run_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT run_id FROM {$table}
                 WHERE source_kind = %s AND source_id = %d AND run_id IS NOT NULL
                   AND status IN ('pending', 'in_progress')
                 ORDER BY id DESC LIMIT 1",
                $type,
                $id,
            ),
        );

        return null !== $run_id ? (int) $run_id : null;
    }

    /**
     * Check if content is excluded from translation.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return bool True if excluded.
     */
    private function is_excluded( string $type, int $id ): bool {
        if ( 'post' === $type ) {
            return (bool) \get_post_meta( $id, TranslatableMetaKey::Exclude->value, true );
        }

        return (bool) \get_term_meta( $id, TranslatableMetaKey::Exclude->value, true );
    }

    /**
     * Build status for all target languages.
     *
     * @param string $type           Content type (post or term).
     * @param int    $id             Content ID.
     * @param string $lang_from      Source language code.
     * @param array  $language_names Map of language codes to display names.
     * @return array Array of language statuses.
     */
    private function build_all_language_statuses( string $type, int $id, string $lang_from, array $language_names ): array {
        $target_languages = $this->get_target_languages( $lang_from );
        $languages        = array();

        foreach ( $target_languages as $lang_to ) {
            $languages[] = $this->status_builder->build_language_status(
                $type,
                $id,
                $lang_from,
                $lang_to,
                $language_names,
            );
        }

        return $languages;
    }

    /**
     * Get the source language for a content item.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return string Language code.
     */
    private function get_content_language( string $type, int $id ): string {
        return 'post' === $type
            ? $this->language_manager->get_post_language( $id )
            : $this->language_manager->get_term_language( $id );
    }

    /**
     * Get available target languages (excludes source language).
     *
     * @param string $lang_from Source language code.
     * @return array Array of language codes.
     */
    private function get_target_languages( string $lang_from ): array {
        $available_languages = $this->language_manager->get_available_languages( false );
        return \array_diff( $available_languages, array( $lang_from ) );
    }

    /**
     * Update exclusion meta for a content item.
     *
     * @param string $type     Content type (post or term).
     * @param int    $id       Content ID.
     * @param bool   $excluded Whether to exclude from translation.
     * @return void
     */
    private function update_exclusion_meta( string $type, int $id, bool $excluded ): void {
        if ( 'post' === $type ) {
            \update_post_meta( $id, TranslatableMetaKey::Exclude->value, $excluded );
        } else {
            \update_term_meta( $id, TranslatableMetaKey::Exclude->value, $excluded );
        }
    }
}
