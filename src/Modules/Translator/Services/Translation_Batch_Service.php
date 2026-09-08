<?php
/**
 * Translation_Batch_Service class file.
 *
 * Handles batched translation of extracted patches via LLM.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Logging\Event_Codes;
use PLLAT\Integrations\Integrations\Markup\Services\Inline_Tag_Replacer;
use PLLAT\Translator\Exceptions\Batch_Continuation_Exception;
use PLLAT\Translator\Exceptions\Response_Truncated_Exception;

/**
 * Translates patches in batches using the AI client.
 *
 * Features:
 * - Configurable batch size
 * - Retry logic for failed batches
 * - JSON structured output
 * - Inline tag placeholder instructions
 */
class Translation_Batch_Service {
    /**
     * Number of patches per batch to send to LLM.
     */
    private const BATCH_SIZE = 30;

    /**
     * Maximum retry attempts per batch.
     */
    private const MAX_BATCH_RETRIES = 3;

    /**
     * Maximum number of batch continuations before aborting.
     * Prevents infinite loops in case of malformed content that always exceeds batch size.
     */
    private const MAX_CONTINUATIONS = 50;

    /**
     * Constructor.
     *
     * @param AI_Client              $ai_client        AI client.
     * @param Language_Manager       $language_manager Language manager.
     * @param System_Prompt_Enricher $enricher         Injects website context + instructions.
     */
    public function __construct(
        private AI_Client $ai_client,
        private Language_Manager $language_manager,
        private System_Prompt_Enricher $enricher,
    ) {
    }

    /**
     * Translate patches using batched LLM calls.
     *
     * Processes ONE batch per invocation. If more batches remain, throws
     * Batch_Continuation_Exception with state for the caller to persist
     * and resume in a new Action Scheduler action.
     *
     * @param array<array{path: string, value: string, tag_map: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $patches Patches to translate.
     * @param string                                                                                                                $from    Source language.
     * @param string                                                                                                                $to      Target language.
     * @param array<string, mixed>                                                                                                  $context Translation context.
     * @return array<array{op: string, path: string, value: string}> RFC 6902 patches (all batches complete).
     * @throws Batch_Continuation_Exception If more batches remain after processing one.
     * @throws \Exception If translation fails after all retries.
     */
    public function translate( array $patches, string $from, string $to, array $context ): array {
        $state = $context['batch_state'] ?? null;

        $has_placeholders = $this->patches_contain_placeholders( $patches );
        $system_prompt    = $this->build_system_prompt( $from, $to, $context, $has_placeholders );
        $id_to_patch      = $this->build_id_lookup( $patches );
        $all_translations = $state ? $state['translated'] : array();
        $batch_start_id   = $state ? $state['batch_start_id'] : 1;
        $start_batch      = $state ? $state['next_batch'] : 0;

        $batches       = \array_chunk( $patches, self::BATCH_SIZE, true );
        $total_batches = \count( $batches );

        foreach ( $batches as $batch_index => $batch ) {
            // Skip already-processed batches (batch_start_id already correct from saved state).
            if ( $batch_index < $start_batch ) {
                continue;
            }

            $batch_with_ids = $this->add_ids_to_batch( $batch, $batch_start_id );

            \do_action(
                'pllat_log_event',
                Event_Codes::BATCH_STARTED,
                array(
                    'batch_num'     => $batch_index + 1,
                    'total_batches' => $total_batches,
                    'patch_count'   => \count( $batch ),
                ),
            );

            $batch_translations = $this->translate_batch(
                $batch_with_ids,
                $system_prompt,
                $batch_index + 1,
                $total_batches,
            );

            $mapped           = $this->map_translations_to_patches( $batch_translations, $id_to_patch );
            $all_translations = \array_merge( $all_translations, $mapped );
            $batch_start_id  += \count( $batch );

            \do_action(
                'pllat_batch_translate_completed',
                $batch_index + 1,
                $total_batches,
                \count( $mapped ),
            );

            // Abort if we've exceeded the maximum allowed continuations.
            if ( $batch_index + 1 >= self::MAX_CONTINUATIONS ) {
                throw new \Exception(
                    \sprintf(
                        'Translation aborted: exceeded maximum %d batch continuations. Content may be too large.',
                        (int) self::MAX_CONTINUATIONS,
                    ),
                );
            }

            // If more batches remain, signal continuation.
            if ( $batch_index + 1 < $total_batches ) {
                $batch_state = array(
                    'batch_start_id'     => $batch_start_id,
                    'next_batch'         => $batch_index + 1,
                    'patches'            => $patches,
                    // Survives a restart so repeated drift on the same field
                    // converges on Translator::MAX_DRIFT_RESTARTS.
                    'restarts'           => (int) ( $context['restarts'] ?? 0 ),
                    // Lets the next run detect that the source was edited
                    // while this continuation was pending, in which case the
                    // frozen patch paths above no longer resolve.
                    'source_fingerprint' => $context['source_fingerprint'] ?? null,
                    // The source snapshot the patches were built from, so the
                    // continuation translates and applies against the same
                    // document even if the live field is rewritten mid-run.
                    'source_content'     => $context['source_content'] ?? null,
                    // The field this state belongs to. A post's batch_state is
                    // handed to every gap field, so the snapshot above must
                    // only be reused for the field that produced it.
                    'reference'          => $context['reference'] ?? null,
                    'translated'         => $all_translations,
                );
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- control-flow exception carrying persisted batch state, not a message.
                throw new \PLLAT\Translator\Exceptions\Batch_Continuation_Exception( $batch_state );
            }
        }

        return $all_translations;
    }

    /**
     * Check if any patch contains inline tag placeholders.
     *
     * @param array<array{tag_map?: array<int, array{tag: string, attrs: string, self_closing: bool}>}> $patches Patches to check.
     * @return bool True if any patch has a non-empty tag_map.
     */
    private function patches_contain_placeholders( array $patches ): bool {
        foreach ( $patches as $patch ) {
            if ( isset( $patch['tag_map'] ) && \count( $patch['tag_map'] ) > 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build ID → patch lookup.
     *
     * @param array<array{path: string}> $patches Patches.
     * @return array<int, array{path: string}> ID → patch lookup.
     */
    private function build_id_lookup( array $patches ): array {
        $lookup = array();

        foreach ( $patches as $index => $patch ) {
            $lookup[ $index + 1 ] = $patch; // 1-indexed IDs.
        }

        return $lookup;
    }

    /**
     * Add sequential IDs to batch.
     *
     * @param array<array{value: string}> $batch    Batch of patches.
     * @param int                         $start_id Starting ID.
     * @return array<array{id: int, text: string}> Batch with IDs.
     */
    private function add_ids_to_batch( array $batch, int $start_id ): array {
        $batch_with_ids = array();
        $id             = $start_id;

        foreach ( $batch as $patch ) {
            $batch_with_ids[] = array(
                'id'   => $id,
                'text' => $patch['value'],
            );
            ++$id;
        }

        return $batch_with_ids;
    }

    /**
     * Translate a single batch with retry logic.
     *
     * @param array<array{id: int, text: string}> $batch         Batch of texts with IDs.
     * @param string                              $system_prompt System prompt.
     * @param int                                 $batch_num     Current batch number.
     * @param int                                 $total_batches Total number of batches.
     * @return array<array{id: int, text: string}> Translated texts with IDs.
     * @throws \Exception If all retries fail.
     */
    private function translate_batch( array $batch, string $system_prompt, int $batch_num, int $total_batches ): array {
        $last_exception = null;

        for ( $attempt = 1; $attempt <= self::MAX_BATCH_RETRIES; $attempt++ ) {
            try {
                return $this->execute_batch_translation( $batch, $system_prompt, $batch_num, $total_batches );
            } catch ( \PLLAT\Translator\Exceptions\Response_Truncated_Exception $e ) {
                return $this->split_and_translate( $batch, $system_prompt, $batch_num, $total_batches, $e );
            } catch ( \PLLAT\Translator\Exceptions\Provider_Quota_Exhausted_Exception $e ) {
                // Out of credits is permanent — retrying burns doomed API
                // calls, and wrapping would hide the exception type from
                // Lean_Job_Worker's terminal handling (issue #461).
                throw $e;
            } catch ( \Exception $e ) {
                $last_exception = $e;
                $this->log_retry_attempt( $batch_num, $total_batches, $attempt, $e );
            }
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for internal logging.
        throw new \Exception(
            \sprintf(
                'Batch %d/%d failed after %d attempts: %s',
                $batch_num,
                $total_batches,
                self::MAX_BATCH_RETRIES,
                $last_exception->getMessage(),
            ),
        );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * Recover from an output truncation (finish_reason=length) by halving
     * the batch and translating the halves. Retrying the identical doomed
     * call would only burn tokens; splitting is bounded by the batch size.
     * A single irreducible patch that still truncates propagates so the
     * claim surfaces it as a failure (park+surface floor).
     *
     * @param array<array{id: int, text: string}> $batch         Batch with IDs.
     * @param string                              $system_prompt System prompt.
     * @param int                                 $batch_num     Current batch number.
     * @param int                                 $total_batches Total batches.
     * @return array<array{id: int, text: string}> Translated texts with IDs.
     * @throws Response_Truncated_Exception When a single patch still truncates.
     */
    private function split_and_translate(
        array $batch,
        string $system_prompt,
        int $batch_num,
        int $total_batches,
        \PLLAT\Translator\Exceptions\Response_Truncated_Exception $truncation
    ): array {
        if ( \count( $batch ) <= 1 ) {
            throw $truncation;
        }

        $half = (int) \ceil( \count( $batch ) / 2 );
        $left = $this->translate_batch(
            \array_slice( $batch, 0, $half ),
            $system_prompt,
            $batch_num,
            $total_batches,
        );
        $right = $this->translate_batch(
            \array_slice( $batch, $half ),
            $system_prompt,
            $batch_num,
            $total_batches,
        );

        return \array_merge( $left, $right );
    }

    /**
     * Execute single batch translation call.
     *
     * @param array<array{id: int, text: string}> $batch         Batch with IDs.
     * @param string                              $system_prompt System prompt.
     * @param int                                 $batch_num     Current batch number.
     * @param int                                 $total_batches Total batches.
     * @return array<array{id: int, text: string}> Translations.
     * @throws \Exception If LLM returns invalid response.
     */
    private function execute_batch_translation( array $batch, string $system_prompt, int $batch_num, int $total_batches ): array {
        $user_content = \wp_json_encode( $batch, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );

        $messages = array(
            array(
                'content' => $system_prompt,
                'role'    => 'system',
            ),
            array(
                'content' => $user_content,
                'role'    => 'user',
            ),
        );

        $response = $this->ai_client->chat_completion(
            messages: $messages,
            additional_params: array(
                'response_format' => $this->get_json_schema(),
                'temperature'     => 1.0,
            ),
        );

        return $this->parse_batch_response( $response, $batch, $batch_num, $total_batches );
    }

    /**
     * Parse and validate batch response from LLM.
     *
     * @param array<string, mixed>                $response      LLM response.
     * @param array<array{id: int, text: string}> $batch         Original batch.
     * @param int                                 $batch_num     Batch number.
     * @param int                                 $total_batches Total batches.
     * @return array<array{id: int, text: string}> Translations.
     * @throws \Exception If response is invalid.
     */
    private function parse_batch_response( array $response, array $batch, int $batch_num, int $total_batches ): array {
        $content       = $response['choices'][0]['message']['content'] ?? '{}';
        $finish_reason = $response['choices'][0]['finish_reason'] ?? 'unknown';

        // Detect truncated responses before parsing.
        if ( 'length' === $finish_reason ) {
            throw new \Exception(
                \sprintf(
                    'Batch %d/%d: LLM response truncated (finish_reason=length, response_length=%d). Reduce batch size or content length.',
                    (int) $batch_num,
                    (int) $total_batches,
                    (int) \strlen( $content ),
                ),
            );
        }

        $result = AI_Client::parse_json_response_content( $content );

        if ( ! isset( $result['translations'] ) || ! \is_array( $result['translations'] ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for internal logging.
            throw new \Exception(
                $this->build_invalid_batch_message( $content, $finish_reason, $result ),
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->validate_all_ids_present( $batch, $result['translations'], $batch_num, $total_batches );

        return $result['translations'];
    }

    /**
     * Validate all expected IDs are in response.
     *
     * @param array<array{id: int}> $batch         Original batch.
     * @param array<array{id: int}> $translations  Returned translations.
     * @param int                   $batch_num     Batch number.
     * @param int                   $total_batches Total batches.
     * @throws \Exception If IDs are missing.
     */
    private function validate_all_ids_present( array $batch, array $translations, int $batch_num, int $total_batches ): void {
        $expected_ids = \array_column( $batch, 'id' );
        $returned_ids = \array_column( $translations, 'id' );
        $missing_ids  = \array_diff( $expected_ids, $returned_ids );

        if ( \count( $missing_ids ) > 0 ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception for internal logging.
            throw new \Exception(
                \sprintf(
                    'Batch %d/%d: Missing translations for IDs: %s',
                    $batch_num,
                    $total_batches,
                    \implode( ', ', $missing_ids ),
                ),
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * Map translations back to patch paths.
     *
     * @param array<array{id: int, text: string}> $translations Translations with IDs.
     * @param array<int, array{path: string}>     $id_to_patch  ID → patch lookup.
     * @return array<array{op: string, path: string, value: string}> RFC 6902 patches.
     */
    private function map_translations_to_patches( array $translations, array $id_to_patch ): array {
        $patches = array();

        foreach ( $translations as $translation ) {
            $id = $translation['id'];

            if ( ! isset( $id_to_patch[ $id ] ) ) {
                continue;
            }

            $patches[] = array(
                'op'    => 'replace',
                'path'  => $id_to_patch[ $id ]['path'],
                'value' => $translation['text'],
            );
        }

        return $patches;
    }

    /**
     * Log retry attempt.
     *
     * @param int        $batch_num     Batch number.
     * @param int        $total_batches Total batches.
     * @param int        $attempt       Attempt number.
     * @param \Exception $e             Exception.
     */
    private function log_retry_attempt( int $batch_num, int $total_batches, int $attempt, \Exception $e ): void {
        if ( $attempt >= self::MAX_BATCH_RETRIES ) {
            return;
        }

        \do_action(
            'pllat_log_warning',
            \sprintf(
                'Batch %d/%d failed (attempt %d/%d): %s. Retrying...',
                $batch_num,
                $total_batches,
                $attempt,
                self::MAX_BATCH_RETRIES,
                $e->getMessage(),
            ),
        );
    }

    /**
     * Build a diagnostic exception message for invalid batch responses.
     *
     * @param string               $raw_content   Raw LLM response content.
     * @param string               $finish_reason API finish reason.
     * @param array<string, mixed> $parsed_result Parsed JSON result.
     * @return string Diagnostic message.
     */
    private function build_invalid_batch_message( string $raw_content, string $finish_reason, array $parsed_result ): string {
        $top_level_keys = \array_keys( $parsed_result );
        $preview        = \mb_substr( $raw_content, 0, 500 );

        return \sprintf(
            'LLM returned invalid format for batch translation. finish_reason=%s, response_length=%d, keys=[%s], preview=%s',
            $finish_reason,
            \strlen( $raw_content ),
            \implode( ', ', $top_level_keys ),
            $preview,
        );
    }

    /**
     * Build system prompt for batch translation.
     *
     * @param string               $from             Source language.
     * @param string               $to               Target language.
     * @param array<string, mixed> $context          Translation context.
     * @param bool                 $has_placeholders Whether patches contain inline tag placeholders.
     * @return string System prompt.
     */
    private function build_system_prompt( string $from, string $to, array $context, bool $has_placeholders = false ): string {
        $from_name = $this->language_manager->get_language_name( $from );
        $to_name   = $this->language_manager->get_language_name( $to );

        $prompt = "You are a professional translator for WordPress websites. Translate content from {$from_name} ({$from}) to {$to_name} ({$to}) accurately while maintaining the original tone and meaning.";

        $prompt .= "\n\n" . $this->get_markup_instructions();

        if ( $has_placeholders ) {
            $prompt .= "\n\n" . Inline_Tag_Replacer::get_prompt_instruction();
        }

        $prompt .= $this->get_format_instructions( $has_placeholders );

        $prompt = $this->enricher->apply( $prompt, $context );

        /**
         * Filter the markup translation system prompt.
         *
         * @param string $prompt  System prompt.
         * @param string $from    Source language.
         * @param string $to      Target language.
         * @param array  $context Context data.
         */
        return \apply_filters( 'pllat_markup_translation_system_prompt', $prompt, $from, $to, $context );
    }

    /**
     * Get markup-specific instructions.
     *
     * @return string Instructions.
     */
    private function get_markup_instructions(): string {
        return 'You are translating content extracted from WordPress post content. ' .
            'The text may contain HTML formatting preserved as numbered placeholders. ' .
            'Maintain natural language flow while preserving all placeholder structures.';
    }

    /**
     * Get format instructions for JSON I/O.
     *
     * @param bool $has_placeholders Whether to include placeholder examples and rules.
     * @return string Instructions.
     */
    private function get_format_instructions( bool $has_placeholders = false ): string {
        $example_input  = $has_placeholders
            ? 'Input: [{"id": 1, "text": "Hello ⟨1⟩world⟨/1⟩"}, {"id": 2, "text": "Goodbye"}]'
            : 'Input: [{"id": 1, "text": "Hello world"}, {"id": 2, "text": "Goodbye"}]';
        $example_output = $has_placeholders
            ? 'Output: {"translations": [{"id": 1, "text": "Hallo ⟨1⟩wereld⟨/1⟩"}, {"id": 2, "text": "Tot ziens"}]}'
            : 'Output: {"translations": [{"id": 1, "text": "Hallo wereld"}, {"id": 2, "text": "Tot ziens"}]}';
        $example        = $example_input . "\n" . $example_output;

        $rules = "1. Translate EVERY item - do not skip any ID\n";

        if ( $has_placeholders ) {
            $rules .= "2. Keep placeholders ⟨N⟩, ⟨/N⟩, and ⟨N/⟩ EXACTLY as they appear (do not add, remove, or modify)\n";
        }

        $rules .= "3. Do not translate URLs, code, or technical terms\n";
        $rules .= '4. Preserve HTML entities like &amp; &nbsp; &quot; exactly as written';

        return "\n\n"
            . "INPUT FORMAT:\n"
            . 'You will receive a JSON array of objects, each with "id" (number) and "text" (string to translate).' . "\n"
            . "\n"
            . "OUTPUT FORMAT:\n"
            . 'Return a JSON object with "translations" array. Each translation must have:' . "\n"
            . '- "id": The EXACT same ID from input (do not change)' . "\n"
            . '- "text": The translated text' . "\n"
            . "\n"
            . "EXAMPLE:\n"
            . $example . "\n"
            . "\n"
            . "CRITICAL RULES:\n"
            . $rules;
    }

    /**
     * Get JSON schema for structured output.
     *
     * @return array<string, mixed> JSON schema.
     */
    private function get_json_schema(): array {
        return array(
            'name'   => 'batch_translations',
            'schema' => array(
                'additionalProperties' => false,
                'properties'           => array(
                    'translations' => array(
                        'items' => array(
                            'additionalProperties' => false,
                            'properties'           => array(
                                'id'   => array( 'type' => 'integer' ),
                                'text' => array( 'type' => 'string' ),
                            ),
                            'required'             => array( 'id', 'text' ),
                            'type'                 => 'object',
                        ),
                        'type'  => 'array',
                    ),
                ),
                'required'             => array( 'translations' ),
                'type'                 => 'object',
            ),
            'strict' => true,
        );
    }
}
