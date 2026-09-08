<?php
/**
 * Single_Translation_REST_Controller class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Single_Translator
 */

declare(strict_types=1);

namespace PLLAT\Single_Translator\Controllers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Single_Translator\Services\Single_Translation_Service;
use PLLAT\Translator\Enums\TranslatableMetaKey;
use PLLAT\Status\Preflight\Checks\Translation_Index_Primed_Check;
use PLLAT\Status\Preflight\Preflight_Scope;
use PLLAT\Status\Preflight\Preflight_Service;
use PLLAT\Translator\Models\Run_Spec;
use PLLAT\Translator\Services\Job_Claim_Service;
use PLLAT\Translator\Services\Run_Spec_Service;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

/**
 * REST controller for single item translation operations.
 *
 * Provides endpoints for translating individual posts and terms
 * from their edit pages.
 *
 * Status comes from Translation_Index_Repository via Single_Translation_Service.
 */
#[REST_Handler( namespace: 'pllat/v1', basename: 'single-translator' )]
class Single_Translation_REST_Controller extends \XWP_REST_Controller {
    /**
     * Constructor.
     *
     * @param Single_Translation_Service $translation_service The single translation service.
     * @param Preflight_Service          $preflight           The preflight service.
     * @param Run_Spec_Service           $run_spec_service    The run spec service.
     * @param Language_Manager           $language_manager    The language manager.
     */
    public function __construct(
        protected Single_Translation_Service $translation_service,
        protected Preflight_Service $preflight,
        protected Run_Spec_Service $run_spec_service,
        protected Language_Manager $language_manager,
        protected Job_Claim_Service $claim_service,
    ) {
    }

    /**
     * Permission check for endpoints (must be able to edit the content).
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function get_status_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for translate endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function translate_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for exclusion endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function set_exclusion_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Permission check for cancel endpoint.
     *
     * @param \WP_REST_Request $request The request.
     * @return bool Whether the user has permission.
     */
    public function cancel_translation_permissions_check( \WP_REST_Request $request ): bool {
        if ( ! \xwp_app( 'pllat' )->get( 'single_translator.enabled' ) ) {
            return false;
        }

        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        return $this->can_edit_content( $type, $id );
    }

    /**
     * Get translation status for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'status/(?P<type>post|term)/(?P<id>\d+)', methods: 'GET', guard: 'get_status_permissions_check' )]
    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        try {
            $status = $this->translation_service->get_translation_status( $type, $id );

            return $this->success_response( $status );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 500 );
        }
    }

    /**
     * Start translation for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'translate/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'translate_permissions_check' )]
    public function translate( \WP_REST_Request $request ): \WP_REST_Response {
        $type                  = $request->get_param( 'type' );
        $id                    = (int) $request->get_param( 'id' );
        $target_languages      = $request->get_param( 'target_languages' );
        $force                 = (bool) $request->get_param( 'force' );
        $selected_fields       = $request->get_param( 'selected_fields' );
        $acknowledge_preflight = (bool) $request->get_param( 'acknowledge_preflight' );
        $instructions          = (string) ( $request->get_param( 'instructions' ) ?? '' );

        if ( ! \is_array( $selected_fields ) ) {
            $selected_fields = array();
        }

        // Validate target languages.
        if ( ! \is_array( $target_languages ) || 0 === \count( $target_languages ) ) {
            return $this->error_response( \__( 'Target languages are required.', 'ai-translation-for-polylang' ), 400 );
        }

        // For posts, refuse upfront when the post is out of pipeline scope.
        // Mirror of the taxonomy guard below. The three exclusion paths match
        // what the pipeline itself applies (Job_Claim_Service::find_post_candidates,
        // Translation_Index_Repository::aggregate_for_dashboard,
        // Run_Reconciliation_Service::find_post_candidates) — keeps the gate
        // consistent with what actually gets translated.
        if ( 'post' === $type ) {
            $post = \get_post( $id );
            if ( $post instanceof \WP_Post ) {
                if ( '1' === (string) \get_post_meta( $id, TranslatableMetaKey::Exclude->value, true ) ) {
                    return new \WP_REST_Response(
                        array(
                            'code'    => 'pllat_post_excluded_from_translation',
                            'message' => \__( 'This post is excluded from translation. Toggle the exclusion off in the Translation panel to enable translation.', 'ai-translation-for-polylang' ),
                            'success' => false,
                        ),
                        422,
                    );
                }

                if ( \function_exists( 'pll_is_translated_post_type' )
                    && isset( $GLOBALS['polylang'] )
                    && ! \pll_is_translated_post_type( $post->post_type ) ) {
                    return new \WP_REST_Response(
                        array(
                            'code'    => 'pllat_post_type_not_translatable',
                            'message' => \sprintf(
                                /* translators: %s: post type slug */
                                \__( 'The "%s" post type is not enabled as translatable in Polylang. Enable it in Polylang → Settings → Custom post types and Taxonomies.', 'ai-translation-for-polylang' ),
                                $post->post_type,
                            ),
                            'success' => false,
                        ),
                        422,
                    );
                }

                // Status check requires Polylang to enumerate active post types accurately.
                // If Polylang isn't loaded the polylang_active preflight check will surface
                // the real cause; skip this branch to avoid a misleading "inherit status" error.
                if ( \function_exists( 'pll_is_translated_post_type' )
                    && isset( $GLOBALS['polylang'] ) ) {
                    $allowed_statuses = \PLLAT\Common\Helpers::post_statuses_for( \PLLAT\Common\Helpers::get_active_post_types() );
                    if ( ! \in_array( $post->post_status, $allowed_statuses, true ) ) {
                        return new \WP_REST_Response(
                            array(
                                'code'    => 'pllat_post_excluded_from_translation',
                                'message' => \sprintf(
                                    /* translators: %s: post status, e.g. "private" or "draft" */
                                    \__( 'This post is set to "%s" status and is only translated when published. Publish the post first, then run translation.', 'ai-translation-for-polylang' ),
                                    $post->post_status,
                                ),
                                'success' => false,
                            ),
                            422,
                        );
                    }
                }
            }
        }

        // For terms, refuse upfront when Polylang doesn't list the taxonomy
        // as translatable — the claim/translation pipeline would later have
        // to invent slugs Polylang's filters don't generate for it, and the
        // term insert would hit a "term name already exists" wp_error. The
        // honest answer to the user is "Polylang doesn't think this is
        // translatable; enable it or install the addon that registers it".
        if ( 'term' === $type ) {
            $term = \get_term( $id );
            if ( $term instanceof \WP_Term
                && \function_exists( 'pll_is_translated_taxonomy' )
                && isset( $GLOBALS['polylang'] )
                && ! \pll_is_translated_taxonomy( $term->taxonomy ) ) {
                return new \WP_REST_Response(
                    array(
                        'code'    => 'pllat_taxonomy_not_translatable',
                        'message' => \sprintf(
                            /* translators: %s: taxonomy slug, e.g. pa_color */
                            \__( 'The "%s" taxonomy is not enabled as translatable in Polylang. Enable it in Polylang → Settings → Custom post types and Taxonomies, or install Polylang for WooCommerce for WC product attributes.', 'ai-translation-for-polylang' ),
                            $term->taxonomy,
                        ),
                        'success' => false,
                    ),
                    422,
                );
            }
        }

        // Run single-scope preflight at the REST layer before touching the pipeline.
        $preflight_context = 'post' === $type
            ? array( 'post_id' => $id )
            : array( 'term_id' => $id );
        $preflight_result  = $this->preflight->check( Preflight_Scope::Single, $preflight_context );
        $preflight_array   = $preflight_result->to_array();
        $preflight_checks  = $preflight_array['checks'];
        if ( $preflight_result->is_fail() && ! $acknowledge_preflight ) {
            return new \WP_REST_Response(
                array(
                    'code'      => 'pllat_preflight_failed',
                    'message'   => \__( 'System is not ready for translation. See details.', 'ai-translation-for-polylang' ),
                    'preflight' => $preflight_array,
                    'success'   => false,
                ),
                422,
            );
        }

        // The translation_index_primed check is non-bypassable: a run on an
        // unprimed index produces a partial scope (workers see 0 gaps for
        // posts not yet in the index) and the customer perceives silent
        // failure. Even an explicit "Continue anyway" cannot proceed.
        // Must fire BEFORE the "user continued" audit log so the log truly
        // records continuations, not requests blocked despite acknowledge.
        if ( $this->is_check_failing( $preflight_checks, Translation_Index_Primed_Check::NAME ) ) {
            return new \WP_REST_Response(
                array(
                    'code'      => 'pllat_index_not_ready',
                    'message'   => \__( 'Translation index is not ready yet. Use the Re-run prime button to fix this.', 'ai-translation-for-polylang' ),
                    'preflight' => $preflight_array,
                    'success'   => false,
                ),
                422,
            );
        }

        if ( $preflight_result->is_fail() && $acknowledge_preflight ) {
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

        try {
            $spec = $this->build_spec_for_single( $type, $id, $target_languages, $force, $selected_fields, $instructions );

            // Reject submissions that would translate nothing: no force AND
            // every selected language is already fully translated against
            // the current source hash. Pre-fix this created an empty run
            // row and the UI showed "starting → done" with no feedback.
            if ( ! $force && ! $this->claim_service->has_remaining_candidates( 0, $spec ) ) {
                return new \WP_REST_Response(
                    array(
                        'code'    => 'pllat_nothing_to_translate',
                        'message' => \__(
                            'All selected languages are already translated. Enable force re-translation to overwrite.',
                            'ai-translation-for-polylang',
                        ),
                        'success' => false,
                    ),
                    409,
                );
            }

            $run_id = $this->run_spec_service->create_run( $spec );

            return $this->success_response(
                array(
                    'message'  => \__(
                        'Translation started successfully.',
                        'ai-translation-for-polylang',
                    ),
                    'run_id'   => $run_id,
                    'trace_id' => null,
                ),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 400 );
        }
    }

    /**
     * Build the Run_Spec for a single-post or single-term request.
     *
     * Split off from the create-run call so callers can run pre-flight
     * checks (e.g. has_remaining_candidates) against the spec before
     * committing a row to pllat_bulk_runs.
     *
     * @param array<int, string> $target_languages Target language slugs.
     * @param array<int, string> $selected_fields Fields selected for force re-translation.
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
     * Set exclusion status for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'exclusion/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'set_exclusion_permissions_check' )]
    public function set_exclusion( \WP_REST_Request $request ): \WP_REST_Response {
        $type     = $request->get_param( 'type' );
        $id       = (int) $request->get_param( 'id' );
        // filter_var with FILTER_VALIDATE_BOOLEAN handles the 'false' string
        // correctly (returns false), unlike PHP's (bool) cast which coerces
        // any non-empty string to true. Avoids the rest_sanitize_boolean
        // PHPStan stub template-inference issue.
        $excluded = \filter_var( $request->get_param( 'excluded' ), \FILTER_VALIDATE_BOOLEAN );

        try {
            $this->translation_service->set_exclusion( $type, $id, $excluded );

            return $this->success_response(
                array(
                    'message' => $excluded
                        ? \__( 'Content excluded from AI translation.', 'ai-translation-for-polylang' )
                        : \__( 'Content included in AI translation.', 'ai-translation-for-polylang' ),
                ),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 500 );
        }
    }

    /**
     * Cancel active translation for a content item.
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response The response.
     */
    #[REST_Route( route: 'cancel/(?P<type>post|term)/(?P<id>\d+)', methods: 'POST', guard: 'cancel_translation_permissions_check' )]
    public function cancel_translation( \WP_REST_Request $request ): \WP_REST_Response {
        $type = $request->get_param( 'type' );
        $id   = (int) $request->get_param( 'id' );

        try {
            $run_id = $this->translation_service->cancel_active_translation( $type, $id );

            return $this->success_response(
                array(
                    'message' => \__(
                        'Translation cancelled successfully.',
                        'ai-translation-for-polylang',
                    ),
                    'run_id'  => $run_id,
                ),
            );
        } catch ( \Exception $e ) {
            return $this->error_response( $e->getMessage(), 400 );
        }
    }

    /**
     * Check if the user can edit the content.
     *
     * @param string $type Content type (post or term).
     * @param int    $id   Content ID.
     * @return bool Whether the user can edit.
     */
    private function can_edit_content( string $type, int $id ): bool {
        if ( 'post' === $type ) {
            $post = \get_post( $id );
            return $post && \current_user_can( 'edit_post', $id );
        }

        $term = \get_term( $id );
        return $term && ! \is_wp_error( $term ) && \current_user_can( 'edit_term', $id );
    }

    /**
     * Return an error response.
     *
     * @param string $message The error message.
     * @param int    $code    The HTTP status code.
     * @return \WP_REST_Response The error response.
     */
    private function error_response( string $message, int $code ): \WP_REST_Response {
        return new \WP_REST_Response( array('message' => $message, 'success' => false), $code);
    }

    /**
     * Return a success response.
     *
     * @param array<string, mixed> $data The response data.
     */
    private function success_response( array $data ): \WP_REST_Response {
        return new \WP_REST_Response( \array_merge( array( 'success' => true ), $data ), 200 );
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
     * Duplicated from Dashboard_REST_Controller — no shared REST base
     * class exists beyond XWP_REST_Controller, so duplication is intentional.
     * TODO: extract to a shared trait if a third controller needs this.
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
