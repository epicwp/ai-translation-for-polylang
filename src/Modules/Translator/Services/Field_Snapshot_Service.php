<?php
/**
 * Field_Snapshot_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Translator\Models\Translations\Translation_Post;
use PLLAT\Translator\Models\Translations\Translation_Post_Meta;
use PLLAT\Translator\Models\Translations\Translation_Term;
use PLLAT\Translator\Models\Translations\Translation_Term_Meta;

/**
 * Tracks the set of available translation fields across post types / taxonomies.
 *
 * Field coverage at translation time is handled by live JOINs in Job_Claim_Service.
 * The snapshot is used for detecting new fields after classification scans and
 * surfacing counts in the REST response.
 */
class Field_Snapshot_Service {

    /**
     * Option key for the field snapshot.
     *
     * @var string
     */
    private const OPTION_KEY = 'pllat_field_snapshot';

    /**
     * Constructor.
     *
     * @param Language_Manager $language_manager Language manager.
     */
    public function __construct(
        private Language_Manager $language_manager,
    ) {
    }

    /**
     * Detect new fields compared to the stored snapshot and update the snapshot.
     *
     * Returns the added-fields diff so callers can surface counts in REST responses.
     * No tasks are created; the lean pipeline picks up new fields via live JOINs.
     *
     * @return array{post_fields: array<string>, post_meta_fields: array<string,array<string>>, term_fields: array<string>, term_meta_fields: array<string,array<string>>}
     */
    public function detect_and_handle_new_fields(): array {
        $current  = $this->get_current_fields();
        $snapshot = \get_option( self::OPTION_KEY, array() );

        // First run OR legacy flat format — seed baseline, return empty diff.
        if ( ! \is_array( $snapshot ) || 0 === \count( $snapshot ) || $this->is_legacy_snapshot( $snapshot ) ) {
            \update_option( self::OPTION_KEY, $current, false );
            return array(
                'post_fields'      => array(),
                'post_meta_fields' => array(),
                'term_fields'      => array(),
                'term_meta_fields' => array(),
            );
        }

        $diff = $this->compute_diff( $current, $snapshot );

        \update_option( self::OPTION_KEY, $current, false );

        return $diff;
    }

    /**
     * Compute the added-fields diff between current state and snapshot.
     *
     * @param array{post_fields: array<string>, post_meta_fields: array<string,array<string>>, term_fields: array<string>, term_meta_fields: array<string,array<string>>} $current  Current state.
     * @param array<string,mixed>                                                                                                                                         $snapshot Stored snapshot.
     * @return array{post_fields: array<string>, post_meta_fields: array<string,array<string>>, term_fields: array<string>, term_meta_fields: array<string,array<string>>}
     */
    private function compute_diff( array $current, array $snapshot ): array {
        return array(
            'post_fields'      => \array_values(
                \array_diff( $current['post_fields'], $snapshot['post_fields'] ?? array() ),
            ),
            'post_meta_fields' => $this->diff_entity_meta(
                $current['post_meta_fields'],
                $snapshot['post_meta_fields'] ?? array(),
            ),
            'term_fields'      => \array_values(
                \array_diff( $current['term_fields'], $snapshot['term_fields'] ?? array() ),
            ),
            'term_meta_fields' => $this->diff_entity_meta(
                $current['term_meta_fields'],
                $snapshot['term_meta_fields'] ?? array(),
            ),
        );
    }

    /**
     * Compute per-entity added meta fields.
     *
     * @param array<string,array<string>> $current  Current per-entity meta fields.
     * @param array<string,array<string>> $previous Previous per-entity meta fields.
     * @return array<string,array<string>>
     */
    private function diff_entity_meta( array $current, array $previous ): array {
        $added = array();

        foreach ( $current as $entity => $fields ) {
            $delta = \array_values( \array_diff( $fields, $previous[ $entity ] ?? array() ) );

            if ( 0 === \count( $delta ) ) {
                continue;
            }

            $added[ $entity ] = $delta;
        }

        return $added;
    }

    /**
     * Get the current available fields per entity.
     *
     * @return array{post_fields: array<string>, post_meta_fields: array<string,array<string>>, term_fields: array<string>, term_meta_fields: array<string,array<string>>}
     */
    private function get_current_fields(): array {
        $post_meta_fields = array();
        foreach ( $this->language_manager->get_active_post_types() as $post_type ) {
            $post_meta_fields[ $post_type ] = Translation_Post_Meta::get_available_fields_for_entity(
                $post_type,
            );
        }

        $term_meta_fields = array();
        foreach ( $this->language_manager->get_active_taxonomies() as $taxonomy ) {
            $term_meta_fields[ $taxonomy ] = Translation_Term_Meta::get_available_fields_for_entity(
                $taxonomy,
            );
        }

        return array(
            'post_fields'      => Translation_Post::get_available_fields(),
            'post_meta_fields' => $post_meta_fields,
            'term_fields'      => Translation_Term::get_available_fields(),
            'term_meta_fields' => $term_meta_fields,
        );
    }

    /**
     * Detect legacy flat snapshot format.
     *
     * Legacy: `post_meta_fields` is a flat list of strings.
     * New: associative map keyed by post type.
     *
     * @param array<string,mixed> $snapshot Snapshot data.
     */
    private function is_legacy_snapshot( array $snapshot ): bool {
        if ( ! isset( $snapshot['post_meta_fields'] ) || ! \is_array( $snapshot['post_meta_fields'] ) ) {
            return false;
        }

        $post_meta = $snapshot['post_meta_fields'];

        if ( 0 === \count( $post_meta ) ) {
            return false;
        }

        return \array_is_list( $post_meta );
    }
}
