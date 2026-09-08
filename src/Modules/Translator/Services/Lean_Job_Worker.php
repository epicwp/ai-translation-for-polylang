<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Services\Rate_Limiter_Service;
use PLLAT\Content\Services\Content_Service;
use PLLAT\Translation_Index\Handlers\Pipeline_Tick_Handler;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use PLLAT\Translation_Index\Services\Field_Hash_Service;
use PLLAT\Translation_Index\Services\Run_Reconciliation_Service;
use PLLAT\Translator\Models\Run_Spec;

/**
 * Lean pipeline worker.
 *
 * One AS action invocation = one call to run(). Loops:
 *   claim unit → mini-reconcile → translate gap fields → write target →
 *   upsert field_state → DELETE jobs row → claim next.
 *
 * Mini-reconcile delegates to Run_Reconciliation_Service::compute_mini_gaps.
 *
 * Translation, write-back, and source-field reading are protected methods
 * intended to be overridden by tests for the failure path.
 */
class Lean_Job_Worker {
    /**
     * Cap on how many units a single worker invocation may claim.
     *
     * Defensive: the tick handler will spawn a fresh worker action per
     * processing run, so an early exit after MAX_CLAIMS_PER_INVOCATION
     * just hands work back to the AS scheduler. Without this cap, a
     * worker can loop indefinitely when compute_gap_fields returns
     * zero gaps but translation_index.target_id is still null (the
     * candidate set never narrows because there's no "completed"
     * marker). That's a real production scenario for post types with
     * no translatable fields.
     */
    protected const MAX_CLAIMS_PER_INVOCATION = 50;

    private int $current_run_id = 0;

    private string $current_instructions = '';

    public function __construct(
        protected Job_Claim_Service $claim_service,
        protected Run_Spec_Service $run_service,
        protected Translation_Field_State_Repository $field_state_repository,
        protected Field_Hash_Service $field_hash_service,
        protected Run_Reconciliation_Service $reconciliation_service,
        protected Translation_Index_Repository $index_repository,
        protected Field_Translator $field_translator,
        protected Content_Service $content_service,
        protected Language_Manager $language_manager,
        protected Rate_Limiter_Service $rate_limiter,
        protected Run_Completion_Service $completion_service,
        protected Claim_Lifecycle $claim_lifecycle,
        protected Activity_Writer $activity_writer,
    ) {}

    public function run( int $run_id ): void {
        $this->current_run_id = $run_id;
        $spec                 = $this->run_service->get_spec( $run_id );
        if ( null === $spec ) {
            return;
        }

        $this->current_instructions = $spec->instructions();

        $this->claim_lifecycle->touch_run_heartbeat( $run_id );

        $claims = 0;
        while ( $claims < self::MAX_CLAIMS_PER_INVOCATION ) {
            // Exit on cancelled/completed/abandoned/failed runs. The looser
            // is_run_cancelled gate that lived here missed the case where
            // mark_completed deleted claims while a sibling worker was
            // about to INSERT a new one — the new claim survived as an
            // orphan because Pipeline_Tick_Handler only sweeps 'processing'.
            if ( $run_id > 0 && $this->claim_lifecycle->is_run_terminal( $run_id ) ) {
                return;
            }

            if ( $run_id > 0 && ! $this->rate_limiter->check_rate_limit( $run_id ) ) {
                return;
            }

            $unit = $this->claim_service->claim_next_unit( $run_id, $spec );
            if ( null === $unit ) {
                $this->completion_service->handle_idle_worker( $run_id, $spec );
                return;
            }
            ++$claims;

            if ( ! $this->source_is_available( $unit ) ) {
                $this->claim_lifecycle->complete( $unit['job_id'] );
                continue;
            }

            $gap_fields = $this->compute_gap_fields( $unit );
            if ( 0 === \count( $gap_fields ) ) {
                $this->index_repository->clear_outdated(
                    $unit['source_kind'],
                    $unit['source_id'],
                    $unit['target_lang'],
                );
                $this->claim_lifecycle->complete( $unit['job_id'] );
                continue;
            }

            try {
                $translations = $this->translate_fields( $unit, $gap_fields );
            } catch ( \PLLAT\Translator\Exceptions\Batch_Continuation_Exception $e ) {
                $this->claim_lifecycle->persist_batch_state( $unit['job_id'], $e->get_batch_state() );
                // Batch continuation is normal flow — not a failure. Chain an
                // immediate successor so the next batch resumes within seconds:
                // without this the parked claim waits for the recurring pipeline
                // tick (up to TICK_INTERVAL 60s), so a multi-batch post would
                // crawl at ~one batch per minute. enqueue_worker is idempotent
                // and this invocation is returning, so net concurrency is
                // unchanged; the recurring tick stays the crash-safety net.
                Pipeline_Tick_Handler::enqueue_worker( $this->current_run_id );
                return;
            } catch ( \PLLAT\Translator\Exceptions\Provider_Quota_Exhausted_Exception $e ) {
                // Provider account out of credits: permanent until the
                // customer tops up, so retrying can never succeed. Fail the
                // claim terminally and record a visible activity row —
                // release_for_retry here made runs cycle on "processing"
                // forever with zero user feedback (issue #461). With the
                // claim terminal, the run reaches Run_Completion_Service
                // through the normal idle-worker path.
                $message = $e->getMessage();
                $this->claim_lifecycle->record_terminal_failure( $unit['job_id'], $message );
                $this->activity_writer->record_failure( $unit, $message, $this->current_run_id );
                return;
            } catch ( \PLLAT\Translator\Exceptions\Provider_Rate_Limited_Exception | \PLLAT\Translator\Exceptions\Provider_Unavailable_Exception $e ) {
                // Provider rate-limited or circuit breaker open. Neither is the
                // unit's fault: release the claim WITHOUT recording a failure
                // (no attempt consumed, no 'failed' activity row). The recurring
                // pllat_pipeline_tick re-claims it once the provider recovers.
                $this->claim_lifecycle->release_for_retry( $unit['job_id'] );
                return;
            } catch ( \Throwable $e ) {
                $message = $this->failure_message( $e );
                $this->claim_lifecycle->record_failure( $unit['job_id'], $message );
                $this->activity_writer->record_failure( $unit, $message, $this->current_run_id );
                // Stop this invocation on translation failure. The next AS action
                // will retry the unit (record_failure resets status to 'pending',
                // allowing re-claim). Prevents an infinite re-try loop within a
                // single invocation.
                return;
            }

            try {
                $written_target_id = $this->write_back( $unit, $translations );
            } catch ( \PLLAT\Translator\Exceptions\Field_Write_Rejected_Exception $e ) {
                // A translated value did not persist on the target — another
                // actor's update_post_metadata filter rejected the plugin's
                // write (e.g. Bricks' builder-access block for userless
                // workers, issue #459). Fail the claim loudly: attempts++,
                // failed activity row, and NO field-state hash. Recording
                // 'completed' here would hide the gap from compute_mini_gaps
                // forever, so the page could never self-heal.
                $this->claim_lifecycle->record_failure( $unit['job_id'], $e->getMessage() );
                $this->activity_writer->record_failure( $unit, $e->getMessage(), $this->current_run_id );
                return;
            }

            if ( $written_target_id <= 0 ) {
                // ensure_target_exists() failed (e.g. Polylang's term insert
                // hit a "term name already exists" error on a previously
                // translated taxonomy, or copy_post hit a deeper issue).
                // Record a real failure so Claim_Lifecycle's attempts counter
                // increments toward MAX_ATTEMPTS — left to the success path
                // below the claim would be DELETEd and the next worker would
                // immediately re-claim the same unit, looping AI calls
                // without ever producing a translation_index row.
                $message = \sprintf(
                    'Unable to create target %s #%d for language %s',
                    (string) $unit['source_kind'],
                    (int) $unit['source_id'],
                    (string) $unit['target_lang'],
                );
                $this->claim_lifecycle->record_failure( $unit['job_id'], $message );
                $this->activity_writer->record_failure( $unit, $message, $this->current_run_id );
                return;
            }

            $this->record_field_state( $unit, $translations );
            $this->activity_writer->record_success( $unit, $translations, $this->current_run_id );
            /**
             * Fires once every translated field of a unit has been written to its target.
             *
             * Post-write work that is not part of the engine (internal link
             * rewriting in Internal_Links_Module) listens here, so the worker
             * needs no dependency on it and nothing happens when the listener
             * is absent.
             *
             * @param string $kind        'post' or 'term'.
             * @param int    $source_id   Source post or term ID.
             * @param int    $target_id   The translation the fields were written to.
             * @param string $target_lang Target language slug.
             */
            \do_action(
                'pllat_unit_translated',
                (string) $unit['source_kind'],
                (int) $unit['source_id'],
                $written_target_id,
                (string) $unit['target_lang'],
            );
            $this->index_repository->clear_outdated(
                $unit['source_kind'],
                $unit['source_id'],
                $unit['target_lang'],
            );
            $this->claim_lifecycle->complete( $unit['job_id'] );
        }
    }

    /**
     * Build the claim failure message for a caught throwable.
     *
     * PHP engine errors (TypeError etc.) carry generic messages like
     * "count(): Argument #1 ... null given" that are undiagnosable from a
     * customer's claim list without the throw site — append file:line for
     * those. Exception messages are left untouched: they are authored to be
     * descriptive on their own.
     */
    private function failure_message( \Throwable $e ): string {
        if ( ! $e instanceof \Error ) {
            return $e->getMessage();
        }

        $file = $e->getFile();
        if ( \defined( 'ABSPATH' ) ) {
            $file = \str_replace( \ABSPATH, '', $file );
        }

        return $e->getMessage() . ' @ ' . $file . ':' . $e->getLine();
    }

    /**
     * Compute the gap field references for a claimed unit via
     * Run_Reconciliation_Service::compute_mini_gaps.
     *
     * @param array<string, mixed> $unit
     * @return array<int, string>
     */
    protected function compute_gap_fields( array $unit ): array {
        $spec = $this->run_service->get_spec( $this->current_run_id );
        if ( null === $spec ) {
            return array();
        }

        $pair      = $this->index_repository->find_pair(
            $unit['source_kind'],
            $unit['source_id'],
            $unit['target_lang'],
        );
        $target_id = null !== $pair && null !== $pair['target_id'] ? (int) $pair['target_id'] : 0;

        return $this->reconciliation_service->compute_mini_gaps(
            $unit['source_kind'],
            $unit['source_id'],
            $unit['target_lang'],
            $target_id,
            $spec,
        );
    }

    /**
     * Translate the gap fields via Field_Translator.
     *
     * @param array<string, mixed> $unit
     * @param array<int, string>   $gap_fields
     * @return array<string, mixed> reference => translated value
     */
    protected function translate_fields( array $unit, array $gap_fields ): array {
        $translations = array();
        foreach ( $gap_fields as $reference ) {
            $reference = (string) $reference;
            $source    = $this->read_source_field( $unit, $reference );
            // Only non-empty strings and arrays (page-builder / ACF-complex
            // JSON) carry translatable text. Anything else — null, '', or a
            // non-string scalar such as the boolean false ACF returns for an
            // empty repeater/group/flexible/clone — has nothing to translate.
            // Skipping here (rather than letting it reach the string-typed
            // translate_single()) avoids a fatal TypeError that fails the whole
            // batch and leaves the target unwritten.
            $is_translatable = ( \is_string( $source ) && '' !== $source ) || \is_array( $source );
            if ( ! $is_translatable ) {
                continue;
            }
            $translations[ $reference ] = $this->field_translator->process_field(
                $reference,
                $source,
                (string) $unit['source_lang'],
                (string) $unit['target_lang'],
                array(
                    'batch_state'  => $unit['batch_state'] ?? null,
                    'source_id'    => (int) $unit['source_id'],
                    'source_kind'  => (string) $unit['source_kind'],
                    'instructions' => $this->current_instructions,
                ),
            );
            if ( $this->current_run_id <= 0 ) {
                continue;
            }

            $this->rate_limiter->increment( $this->current_run_id );
        }
        return $translations;
    }

    /**
     * Write translated fields back to the target post/term.
     *
     * Returns the target post/term ID written to, or 0 when the target
     * could not be created or resolved. Callers MUST check the return
     * and treat 0 as a hard failure of the claim — otherwise the worker
     * loop will log a phantom success, delete the claim, and re-pick the
     * same unit on the next iteration with no translation_index row
     * recorded, burning AI tokens on an infinite retry.
     *
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $translations
     * @throws \PLLAT\Translator\Exceptions\Field_Write_Rejected_Exception When a write verifiably did not persist (issue #459).
     */
    protected function write_back( array $unit, array $translations ): int {
        if ( 0 === \count( $translations ) ) {
            return 0;
        }

        $target_id = $this->ensure_target_exists( $unit );
        if ( $target_id <= 0 ) {
            return 0;
        }

        // Suspend AFTER target creation: Polylang's copy() re-adds its live
        // meta sync hooks unconditionally when it finishes, which would undo
        // an earlier suspension. Without this, PLL_Sync_Metas (and Polylang
        // Pro's ACF sync on acf/update_value) mirrors every translated meta
        // value written below onto the source post (ticket TS-79430082).
        $this->language_manager->suspend_meta_sync();

        try {
            foreach ( $translations as $reference => $value ) {
                try {
                    $this->content_service->write_field_translation(
                        $target_id,
                        (string) $unit['source_kind'],
                        (string) $reference,
                        $value,
                    );
                } catch ( \PLLAT\Translator\Exceptions\Field_Write_Rejected_Exception $e ) {
                    // A write that verifiably did not persist must fail the
                    // claim — never the log-and-continue path below, which
                    // would let the field be recorded 'completed'.
                    throw $e;
                } catch ( \Throwable $e ) {
                    \do_action(
                        'pllat_log_error',
                        \sprintf(
                            'Lean worker: write_field_translation failed for %s #%d ref %s: %s',
                            (string) $unit['source_kind'],
                            $target_id,
                            (string) $reference,
                            $e->getMessage(),
                        ),
                    );
                }
            }
        } finally {
            $this->language_manager->resume_meta_sync();
        }

        return $target_id;
    }

    /**
     * Read the live source-field value for a reference. Used both in
     * translate_fields (to get the value to translate) and in record_field_state
     * (to store the source-version-at-translate hash).
     *
     * @param array<string, mixed> $unit
     */
    protected function read_source_field( array $unit, string $reference ): mixed {
        $kind      = (string) $unit['source_kind'];
        $source_id = (int) $unit['source_id'];

        if ( 'post' === $kind ) {
            return $this->read_post_field_value( $source_id, $reference );
        }
        if ( 'term' === $kind ) {
            return $this->read_term_field_value( $source_id, $reference );
        }
        return null;
    }

    private function read_post_field_value( int $post_id, string $reference ): mixed {
        if ( \str_starts_with( $reference, '_meta|' ) ) {
            // Route through Translatable_Post::get_meta() so the pllat_get_post_meta
            // filter applies. The ACF integration hooks that filter to extract a
            // complex field's (repeater/group/flexible/clone) translatable text;
            // a raw get_post_meta() returns a repeater's row-count ("2"), which the
            // AI then "translates" as a no-op and the rows stay in the source lang.
            return \PLLAT\Translator\Models\Translatables\Translatable_Post::get_instance( $post_id )->get_meta( \substr( $reference, \strlen( '_meta|' ) ), true );
        }
        if ( \str_starts_with( $reference, '_custom_data|' ) ) {
            return \get_post_meta( $post_id, \substr( $reference, \strlen( '_custom_data|' ) ), true );
        }
        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return null;
        }
        return $post->{$reference} ?? null;
    }

    private function read_term_field_value( int $term_id, string $reference ): mixed {
        if ( \str_starts_with( $reference, '_meta|' ) ) {
            // Symmetric with read_post_field_value: go through the Translatable
            // model so any term meta-read filter applies (mirrors the post path).
            return \PLLAT\Translator\Models\Translatables\Translatable_Term::get_instance( $term_id )->get_meta( \substr( $reference, \strlen( '_meta|' ) ), true );
        }
        if ( \str_starts_with( $reference, '_custom_data|' ) ) {
            return \get_term_meta( $term_id, \substr( $reference, \strlen( '_custom_data|' ) ), true );
        }
        $term = \get_term( $term_id );
        if ( ! $term instanceof \WP_Term ) {
            return null;
        }
        return $term->{$reference} ?? null;
    }

    private function ensure_target_exists( array $unit ): int {
        $kind        = (string) $unit['source_kind'];
        $source_id   = (int) $unit['source_id'];
        $source_lang = (string) $unit['source_lang'];
        $target_lang = (string) $unit['target_lang'];

        $pair = $this->index_repository->find_pair( $kind, $source_id, $target_lang );
        if ( null !== $pair && null !== $pair['target_id'] && (int) $pair['target_id'] > 0 ) {
            return (int) $pair['target_id'];
        }

        $target_id = 'post' === $kind
            ? $this->language_manager->copy_post( $source_id, $target_lang )
            : $this->language_manager->copy_term( $source_id, $target_lang );

        if ( $target_id <= 0 ) {
            return 0;
        }

        // Resolve content_subtype + content_status for the index upsert.
        // Posts: post_type + post_status from the source post.
        // Terms: taxonomy from the source term, content_status='active' to
        // match the pre-lean writes (Dashboard_Data_Service::aggregate_for_dashboard
        // counts done by 'publish' OR 'active' — leaving these empty for
        // terms makes them invisible to the dashboard).
        $content_subtype = '';
        $content_status  = '';
        if ( 'post' === $kind ) {
            $post = \get_post( $source_id );
            if ( $post instanceof \WP_Post ) {
                $content_subtype = $post->post_type;
                $content_status  = $post->post_status;
            }
        } else {
            $term = \get_term( $source_id );
            if ( $term instanceof \WP_Term ) {
                $content_subtype = $term->taxonomy;
                $content_status  = 'active';
            }
        }

        $this->index_repository->upsert(
            $kind,
            $source_id,
            $source_lang,
            $target_lang,
            $target_id,
            $content_subtype,
            $content_status,
        );

        return $target_id;
    }

    /**
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $translations
     */
    private function record_field_state( array $unit, array $translations ): void {
        foreach ( $translations as $reference => $_value ) {
            $this->field_state_repository->upsert(
                $unit['source_kind'],
                $unit['source_id'],
                $unit['target_lang'],
                (string) $reference,
                $this->field_hash_service->hash_value( $this->read_source_field( $unit, (string) $reference ) ),
            );
        }
    }

    private function source_is_available( array $unit ): bool {
        $source_id = (int) $unit['source_id'];
        if ( $source_id <= 0 ) {
            return false;
        }

        $kind = (string) $unit['source_kind'];
        if ( 'post' === $kind ) {
            $post = \get_post( $source_id );
            if ( ! $post instanceof \WP_Post ) {
                return false;
            }
            // 'trash' and 'auto-draft' indicate a stale candidate; skip.
            return 'trash' !== $post->post_status && 'auto-draft' !== $post->post_status;
        }

        if ( 'term' === $kind ) {
            $term = \get_term( $source_id );
            return $term instanceof \WP_Term;
        }

        return false;
    }
}
