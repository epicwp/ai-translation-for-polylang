<?php
/**
 * Single_Translation_Start_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Status\Preflight\Checks\Translation_Index_Primed_Check;
use PLLAT\Status\Preflight\Preflight_Scope;
use PLLAT\Status\Preflight\Preflight_Service;
use PLLAT\Translator\Enums\TranslatableMetaKey;
use PLLAT\Translator\Exceptions\Translation_Refused_Exception;
use PLLAT\Translator\Models\Run_Spec;
use PLLAT\Translator\Services\Job_Claim_Service;
use PLLAT\Translator\Services\Run_Spec_Service;

/**
 * Starts a single post or term translation run.
 *
 * Holds the refusal chain shared by the REST route and the abilities, so
 * both refuse the same requests with the same codes.
 */
class Single_Translation_Start_Service {
    /**
     * Constructor.
     *
     * @param Single_Translation_Service $translation_service The single translation service.
     * @param Preflight_Service          $preflight           The preflight service.
     * @param Run_Spec_Service           $run_spec_service    The run spec service.
     * @param Language_Manager           $language_manager    The language manager.
     * @param Job_Claim_Service          $claim_service       The claim service.
     */
    public function __construct(
        private Single_Translation_Service $translation_service,
        private Preflight_Service $preflight,
        private Run_Spec_Service $run_spec_service,
        private Language_Manager $language_manager,
        private Job_Claim_Service $claim_service,
    ) {
    }

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are returned as JSON by the REST layer and the abilities, never printed.
    /**
     * Start a translation run for one post or term.
     *
     * @param string             $type                  Content type (post or term).
     * @param int                $id                    Content ID.
     * @param array<int, string> $target_languages      Target language slugs.
     * @param bool               $force                 Re-translate already translated languages.
     * @param array<int, string> $selected_fields       Fields selected for force re-translation.
     * @param string             $instructions          Extra translation instructions.
     * @param bool               $acknowledge_preflight Continue despite failing preflight checks.
     * @return int The run ID.
     * @throws Translation_Refused_Exception When the request is refused.
     * @throws \Exception When a deeper service fails.
     */
    public function start(
        string $type,
        int $id,
        array $target_languages,
        bool $force,
        array $selected_fields,
        string $instructions,
        bool $acknowledge_preflight,
    ): int {
        $this->refuse_when_active( $type, $id );
        $this->refuse_out_of_scope( $type, $id );
        $this->refuse_failed_preflight( $type, $id, $acknowledge_preflight );

        $spec = $this->build_spec_for_single( $type, $id, $target_languages, $force, $selected_fields, $instructions );

        // Reject submissions that would translate nothing: no force AND
        // every selected language is already fully translated against
        // the current source hash. Pre-fix this created an empty run
        // row and the UI showed "starting → done" with no feedback.
        if ( ! $force && ! $this->claim_service->has_remaining_candidates( 0, $spec ) ) {
            throw new Translation_Refused_Exception(
                'pllat_nothing_to_translate',
                \__(
                    'All selected languages are already translated. Enable force re-translation to overwrite.',
                    'epicwp-ai-translation-for-polylang',
                ),
                array(),
                409,
            );
        }

        return $this->run_spec_service->create_run( $spec );
    }

    /**
     * Split the requested languages into planned, skipped and invalid.
     *
     * @param string                  $type      Content type (post or term).
     * @param int                     $id        Content ID.
     * @param array<int, string>|null $requested Requested language codes, null for all missing or outdated.
     * @param bool                    $force     Whether translated languages may be re-translated.
     * @return array{planned: array<int, string>, skipped: array<int, array{code: string, reason: string}>, invalid: array<int, string>}
     */
    public function plan_languages( string $type, int $id, ?array $requested, bool $force ): array {
        $statuses = array();
        foreach ( $this->translation_service->get_translation_status( $type, $id )['languages'] as $language ) {
            $statuses[ (string) $language['language'] ] = $language['status'] ?? null;
        }

        $plan = array(
            'planned' => array(),
            'skipped' => array(),
            'invalid' => array(),
        );

        if ( null === $requested ) {
            foreach ( $statuses as $code => $status ) {
                if ( null === $status || 'outdated' === $status ) {
                    $plan['planned'][] = $code;
                }
            }

            return $plan;
        }

        foreach ( $requested as $code ) {
            if ( ! \array_key_exists( $code, $statuses ) ) {
                $plan['invalid'][] = $code;
            } elseif ( 'translated' === $statuses[ $code ] && ! $force ) {
                $plan['skipped'][] = array(
                    'code'   => $code,
                    'reason' => 'already_translated',
                );
            } else {
                $plan['planned'][] = $code;
            }
        }

        return $plan;
    }

    /**
     * Refuse when the item already has an active run.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @throws Translation_Refused_Exception When a run is active.
     */
    private function refuse_when_active( string $type, int $id ): void {
        $run_id = $this->translation_service->find_active_run_id( $type, $id );
        if ( null === $run_id ) {
            return;
        }

        throw new Translation_Refused_Exception(
            'pllat_run_already_active',
            \__( 'A translation is already running for this item. Wait for it to finish or cancel it first.', 'epicwp-ai-translation-for-polylang' ),
            array( 'run_id' => $run_id ),
            409,
        );
    }

    /**
     * Refuse items that are outside the pipeline scope.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @throws Translation_Refused_Exception When the item is out of scope.
     */
    private function refuse_out_of_scope( string $type, int $id ): void {
        if ( 'post' === $type ) {
            $this->refuse_out_of_scope_post( $id );
            return;
        }

        if ( 'term' === $type ) {
            $this->refuse_out_of_scope_term( $id );
        }
    }

    /**
     * For posts, refuse upfront when the post is out of pipeline scope.
     *
     * The three exclusion paths match what the pipeline itself applies
     * (Job_Claim_Service::find_post_candidates,
     * Translation_Index_Repository::aggregate_for_dashboard,
     * Run_Reconciliation_Service::find_post_candidates) — keeps the gate
     * consistent with what actually gets translated.
     *
     * @param int $id Post ID.
     * @throws Translation_Refused_Exception When the post is out of scope.
     */
    private function refuse_out_of_scope_post( int $id ): void {
        $post = \get_post( $id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( '1' === (string) \get_post_meta( $id, TranslatableMetaKey::Exclude->value, true ) ) {
            throw new Translation_Refused_Exception(
                'pllat_post_excluded_from_translation',
                \__( 'This post is excluded from translation. Toggle the exclusion off in the Translation panel to enable translation.', 'epicwp-ai-translation-for-polylang' ),
            );
        }

        if ( ! \function_exists( 'pll_is_translated_post_type' ) || ! isset( $GLOBALS['polylang'] ) ) {
            return;
        }

        if ( ! \pll_is_translated_post_type( $post->post_type ) ) {
            throw new Translation_Refused_Exception(
                'pllat_post_type_not_translatable',
                \sprintf(
                    /* translators: %s: post type slug */
                    \__( 'The "%s" post type is not enabled as translatable in Polylang. Enable it in Polylang → Settings → Custom post types and Taxonomies.', 'epicwp-ai-translation-for-polylang' ),
                    $post->post_type,
                ),
            );
        }

        // Status check requires Polylang to enumerate active post types accurately.
        // If Polylang isn't loaded the polylang_active preflight check will surface
        // the real cause; the early return above skips this to avoid a misleading
        // "inherit status" error.
        $allowed_statuses = \PLLAT\Common\Helpers::post_statuses_for( \PLLAT\Common\Helpers::get_active_post_types() );
        // Single-translate only: a scheduled post can be
        // translated ahead of its publish date (issue #468 /
        // TS-57452941) so it stays Polylang-linked and goes live
        // with the source. Bulk scope resolution (Helpers::
        // post_statuses_for(), used by the claim/reconciliation
        // queries and the dashboard) is untouched — this gate
        // only widens the single-post path.
        $allowed_statuses[] = 'future';
        if ( ! \in_array( $post->post_status, $allowed_statuses, true ) ) {
            throw new Translation_Refused_Exception(
                'pllat_post_excluded_from_translation',
                \sprintf(
                    /* translators: %s: post status, e.g. "private" or "draft" */
                    \__( 'This post is set to "%s" status and is only translated when published. Publish the post first, then run translation.', 'epicwp-ai-translation-for-polylang' ),
                    $post->post_status,
                ),
            );
        }
    }

    /**
     * For terms, refuse upfront when Polylang doesn't list the taxonomy
     * as translatable — the claim/translation pipeline would later have
     * to invent slugs Polylang's filters don't generate for it, and the
     * term insert would hit a "term name already exists" wp_error. The
     * honest answer to the user is "Polylang doesn't think this is
     * translatable; enable it or install the addon that registers it".
     *
     * @param int $id Term ID.
     * @throws Translation_Refused_Exception When the taxonomy is not translatable.
     */
    private function refuse_out_of_scope_term( int $id ): void {
        $term = \get_term( $id );
        if ( ! $term instanceof \WP_Term
            || ! \function_exists( 'pll_is_translated_taxonomy' )
            || ! isset( $GLOBALS['polylang'] )
            || \pll_is_translated_taxonomy( $term->taxonomy ) ) {
            return;
        }

        throw new Translation_Refused_Exception(
            'pllat_taxonomy_not_translatable',
            \sprintf(
                /* translators: %s: taxonomy slug, e.g. pa_color */
                \__( 'The "%s" taxonomy is not enabled as translatable in Polylang. Enable it in Polylang → Settings → Custom post types and Taxonomies, or install Polylang for WooCommerce for WC product attributes.', 'epicwp-ai-translation-for-polylang' ),
                $term->taxonomy,
            ),
        );
    }

    /**
     * Run single-scope preflight before touching the pipeline.
     *
     * @param string $type                  Content type (post or term).
     * @param int    $id                    Content ID.
     * @param bool   $acknowledge_preflight Whether the user chose to continue despite failures.
     * @throws Translation_Refused_Exception When preflight blocks the run.
     */
    private function refuse_failed_preflight( string $type, int $id, bool $acknowledge_preflight ): void {
        $context          = 'post' === $type
            ? array( 'post_id' => $id )
            : array( 'term_id' => $id );
        $preflight_result = $this->preflight->check( Preflight_Scope::Single, $context );
        $preflight_array  = $preflight_result->to_array();
        $preflight_checks = $preflight_array['checks'];

        if ( $preflight_result->is_fail() && ! $acknowledge_preflight ) {
            throw new Translation_Refused_Exception(
                'pllat_preflight_failed',
                \__( 'System is not ready for translation. See details.', 'epicwp-ai-translation-for-polylang' ),
                array( 'preflight' => $preflight_array ),
            );
        }

        // The translation_index_primed check is non-bypassable: a run on an
        // unprimed index produces a partial scope (workers see 0 gaps for
        // posts not yet in the index) and the customer perceives silent
        // failure. Even an explicit "Continue anyway" cannot proceed.
        // Must fire BEFORE the "user continued" audit log so the log truly
        // records continuations, not requests blocked despite acknowledge.
        if ( $this->is_check_failing( $preflight_checks, Translation_Index_Primed_Check::NAME ) ) {
            throw new Translation_Refused_Exception(
                'pllat_index_not_ready',
                \__( 'Translation index is not ready yet. Use the Re-run prime button to fix this.', 'epicwp-ai-translation-for-polylang' ),
                array( 'preflight' => $preflight_array ),
            );
        }

        if ( $preflight_result->is_fail() ) {
            // User explicitly chose to bypass the fail-gate via the modal's
            // "Continue anyway" path. Log it so support can correlate later
            // when a customer reports broken translations.
            \do_action(
                'pllat_log_warning',
                \sprintf(
                    'Single translator: user %d acknowledged preflight failures and continued. Failing checks: %s',
                    \get_current_user_id(),
                    \implode( ', ', $this->failing_check_names( $preflight_checks ) ),
                ),
            );
        }
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Build the Run_Spec for a single-post or single-term request.
     *
     * Split off from the create-run call so callers can run pre-flight
     * checks (e.g. has_remaining_candidates) against the spec before
     * committing a row to pllat_bulk_runs.
     *
     * @param array<int, string> $target_languages Target language slugs.
     * @param array<int, string> $selected_fields  Fields selected for force re-translation.
     */
    private function build_spec_for_single(
        string $type,
        int $id,
        array $target_languages,
        bool $force,
        array $selected_fields,
        string $instructions = '',
    ): Run_Spec {
        $force_fields = $force && \count( $selected_fields ) > 0 ? \array_values( $selected_fields ) : null;

        if ( 'post' === $type ) {
            $source_lang = $this->language_manager->get_post_language( $id );
            if ( '' === $source_lang ) {
                $source_lang = $this->language_manager->get_default_language();
            }

            $post_type = '';
            $post      = \get_post( $id );
            if ( $post instanceof \WP_Post ) {
                $post_type = $post->post_type;
            }

            return Run_Spec::for_single_post( $id, $source_lang, $target_languages, $force, $force_fields, $post_type, $instructions );
        }

        $source_lang = $this->language_manager->get_term_language( $id );
        if ( '' === $source_lang ) {
            $source_lang = $this->language_manager->get_default_language();
        }

        $taxonomy = '';
        $term     = \get_term( $id );
        if ( $term instanceof \WP_Term ) {
            $taxonomy = $term->taxonomy;
        }

        return Run_Spec::for_single_term( $id, $source_lang, $target_languages, $force, $force_fields, $taxonomy, $instructions );
    }

    /**
     * Extract failing check names from a preflight result array shape.
     *
     * Used in the audit log emitted when a user bypasses the preflight
     * fail-gate via `acknowledge_preflight=true`. Compact list keeps the
     * log readable when several checks fail at once.
     *
     * @param array<int, array<string, mixed>> $checks Per-check rows from Preflight_Result::to_array().
     * @return array<int, string>
     */
    private function failing_check_names( array $checks ): array {
        $names = array();
        foreach ( $checks as $check ) {
            if ( 'fail' === ( $check['level'] ?? '' ) ) {
                $names[] = (string) ( $check['name'] ?? 'unknown' );
            }
        }
        return $names;
    }

    /**
     * Check whether a named preflight check is currently failing (level = fail).
     *
     * @param array<int, array<string, mixed>> $checks Per-check rows from Preflight_Result::to_array().
     * @param string                           $name   The check name to look for.
     */
    private function is_check_failing( array $checks, string $name ): bool {
        foreach ( $checks as $check ) {
            if ( ( $check['name'] ?? '' ) === $name && ( $check['level'] ?? '' ) === 'fail' ) {
                return true;
            }
        }
        return false;
    }
}
