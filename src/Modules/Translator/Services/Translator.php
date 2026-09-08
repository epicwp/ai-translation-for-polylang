<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Common\Logging\Event_Codes;
use PLLAT\Translator\Services\AI_Client;

/**
 * Translation service with hookable prompts.
 */
class Translator {
    /**
     * How many times a field may restart because its source moved before the
     * job is failed instead. Bounds the restart path, which consumes no attempt.
     */
    private const MAX_DRIFT_RESTARTS = 3;

    /**
     * Constructor.
     *
     * @param AI_Client            $ai_client        The AI client.
     * @param Language_Manager     $language_manager The language manager.
     * @param System_Prompt_Enricher $enricher       Injects website context + instructions.
     */
    public function __construct(
        private AI_Client $ai_client,
        private Language_Manager $language_manager,
        private System_Prompt_Enricher $enricher,
    ) {
    }

    /**
     * Translate single text with optional context.
     *
     * @param string $text    Text to translate.
     * @param string $from    Source language code.
     * @param string $to      Target language code.
     * @param array  $context Context data including reference, content_type, content_id.
     * @return string Translated text.
     */
    public function translate_single( string $text, string $from, string $to, array $context = array() ): string {
        return $this->translate( $text, $from, $to, $context );
    }

    /**
     * Translate a map of keyed strings in a single AI call.
     *
     * Sends all items in one request. The AI returns a JSON object with the same
     * keys and translated values. Throws on parse failure so task retry logic works.
     *
     * @param array  $items   Map of { key: original_text }.
     * @param string $from    Source language code.
     * @param string $to      Target language code.
     * @param array  $context Context data (instructions, reference, etc.).
     * @return array Map of { key: translated_text }.
     * @throws \Exception If AI response cannot be parsed as a JSON object.
     */
    public function translate_map( array $items, string $from, string $to, array $context = array() ): array {
        if ( array() === $items ) {
            return array();
        }

        $system_prompt = $this->build_system_prompt( $from, $to, $context );

        $json_input  = \wp_json_encode( $items, JSON_UNESCAPED_UNICODE );
        $user_prompt = "Translate the string values in the following JSON object. Return ONLY a valid JSON object with the same keys and the translated values. Do not add any explanation.\n\n{$json_input}";

        $messages = array(
            array( 'role' => 'system', 'content' => $system_prompt ),
            array( 'role' => 'user',   'content' => $user_prompt ),
        );

        $response = $this->ai_client->chat_completion( $messages );

        $raw = $response['choices'][0]['message']['content'] ?? '';
        $raw = \trim( $raw );

        // Strip markdown code fences if the AI wrapped the JSON.
        $raw = \preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = \preg_replace( '/\s*```$/', '', $raw );
        $raw = \trim( $raw );

        $decoded = \json_decode( $raw, true );

        if ( ! \is_array( $decoded ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- raw AI response excerpt kept verbatim for diagnostics; stored and shown as plain text.
            throw new \Exception( 'translate_map: AI response is not a valid JSON object. Raw: ' . \mb_substr( $raw, 0, 200 ) );
        }

        return $decoded;
    }

    /**
     * Resolve batch state for a continuation, discarding it if the source moved.
     *
     * A field with more than BATCH_SIZE translatable strings is split across
     * Action Scheduler runs. The patches — and their paths — are frozen into
     * batch_state at first extraction, but the source document is re-read fresh
     * on every continuation. If an editor touches the post in between, those
     * paths describe a document that no longer exists: application dies with
     * "path does not resolve in target", and any translations accumulated so far
     * describe text that is no longer on the page. Restarting the field is the
     * only outcome that cannot silently corrupt it.
     *
     * Also stamps the current fingerprint into the context so the batch service
     * can persist it alongside any state it emits.
     *
     * Restarting consumes no attempt, so it is capped: past MAX_DRIFT_RESTARTS
     * the field is failed instead, letting the job's own attempt counter take
     * over rather than looping on a source that never settles.
     *
     * @param array<string, mixed> $context Translation context, modified in place.
     * @param string               $source  Source document as read this run.
     * @return array<string, mixed>|null Reusable batch state, or null to start over.
     * @throws \Exception If the source kept changing across restarts.
     */
    protected function resolve_batch_state( array &$context, string $source ): ?array {
        $fingerprint = \hash( 'xxh3', $source );
        $state       = $context['batch_state'] ?? null;

        $context['source_fingerprint'] = $fingerprint;

        if ( null === $state ) {
            return null;
        }

        if ( ( $state['source_fingerprint'] ?? null ) === $fingerprint ) {
            // Carry the counter through an ordinary continuation, so a field that
            // drifts intermittently still converges on the ceiling below.
            $context['restarts'] = (int) ( $state['restarts'] ?? 0 );

            return $state;
        }

        $restarts = (int) ( $state['restarts'] ?? 0 ) + 1;

        // Lean_Job_Worker treats a continuation as normal flow and consumes no
        // attempt, and the only other ceiling — MAX_CONTINUATIONS, counted via
        // batch_state['next_batch'] — is discarded by the restart below. Without
        // a ceiling of its own, a source that reads differently every run (a busy
        // editor, or a filter injecting dynamic content) would restart forever.
        if ( $restarts > self::MAX_DRIFT_RESTARTS ) {
            throw new \Exception(
                \sprintf(
                    'Source changed repeatedly during translation of field "%s" (%d restarts); giving up so the job records a failure instead of looping.',
                    \esc_html( (string) ( $context['reference'] ?? 'unknown' ) ),
                    (int) self::MAX_DRIFT_RESTARTS,
                ),
            );
        }

        \do_action(
            'pllat_log_warning',
            \sprintf(
                'Source changed mid-translation for field "%s"; discarding %d stale patches and restarting the field (restart %d of %d).',
                (string) ( $context['reference'] ?? 'unknown' ),
                \count( $state['patches'] ?? array() ),
                $restarts,
                self::MAX_DRIFT_RESTARTS,
            ),
        );

        $context['restarts'] = $restarts;
        unset( $context['batch_state'] );

        return null;
    }

    /**
     * Freeze the source document across batch continuations.
     *
     * A field split across Action Scheduler runs re-enters the translator on
     * every continuation. resolve_batch_state() re-reads the live source each
     * time, so a process that rewrites the field mid-run (an image optimiser
     * rewriting markup, an SEO/content generator, a sync job) shifts the source
     * out from under the patches frozen at first extraction, tripping the drift
     * guard until the item fails. Reusing the source captured in batch_state
     * keeps those patches valid and applies the translation against the exact
     * snapshot they were built from. The source moving on is picked up by the
     * next run; the target is never left half translated.
     *
     * The snapshot is only reused when the batch_state belongs to the field
     * being processed now. A post's continuation batch_state is handed to every
     * one of its gap fields (Lean_Job_Worker::translate_fields passes the same
     * $unit['batch_state'] to each), so without a field-identity check field B
     * would translate field A's document — surfacing as count() on a null
     * tag_map for HTML fields or an invalid target JSON for complex fields.
     * Matching the reference keeps the snapshot scoped to its originating field;
     * a foreign field falls through to its own live source and the
     * fingerprint-based drift guard in resolve_batch_state() re-extracts it.
     *
     * Backward compatible: batch_state persisted before this change carries no
     * source_content or reference, so those in-flight jobs fall through to the
     * live read and the drift guard exactly as before.
     *
     * @param string               $source  Live source read this run.
     * @param array<string, mixed> $context Context, modified in place to carry the frozen source into the next continuation.
     * @return string The source to translate — the frozen snapshot when one exists for this field.
     */
    protected function freeze_source( string $source, array &$context ): string {
        $state = $context['batch_state'] ?? null;

        $belongs = \is_array( $state )
            && isset( $state['source_content'], $state['reference'] )
            && ( $context['reference'] ?? null ) === $state['reference'];

        if ( $belongs ) {
            $source = (string) $state['source_content'];
        }

        $context['source_content'] = $source;

        return $source;
    }

    /**
     * Build system prompt for translation.
     * Hookable for customization.
     *
     * @param string $from    Source language.
     * @param string $to      Target language.
     * @param array  $context Context data.
     * @return string System prompt.
     */
    protected function build_system_prompt( string $from, string $to, array $context ): string {
        // Build base system prompt. "You are a translator...".
        $prompt = $this->build_system_prompt_base( $from, $to );

        // Add website context + custom instructions.
        $prompt = $this->enricher->apply( $prompt, $context );

        /**
         * Filter the system prompt for translations.
         *
         * @param string $prompt  System prompt.
         * @param string $from    Source language.
         * @param string $to      Target language.
         * @param array  $context Context data.
         */
        $prompt = \apply_filters( 'pllat_translation_system_prompt', $prompt, $from, $to, $context );

        /**
         * Fires after the system prompt is built.
         */
        \do_action(
            'pllat_log_event',
            Event_Codes::AI_PROMPT_SYSTEM,
            array(
                'prompt'    => $prompt,
                'lang_from' => $from,
                'lang_to'   => $to,
                'context'   => $context,
            ),
        );

        return $prompt;
    }

    /**
     * Build system prompt base.
     *
     * @param string $from Source language.
     * @param string $to   Target language.
     * @return string System prompt base.
     */
    protected function build_system_prompt_base( string $from, string $to ): string {
        // Convert language codes to full language names for better AI understanding.
        $from_name = $this->language_manager->get_language_name( $from );
        $to_name   = $this->language_manager->get_language_name( $to );

        return "You are a professional translator for WordPress websites. Translate content from {$from_name} ({$from}) to {$to_name} ({$to}) accurately while maintaining the original tone and meaning. Do not translate or alter URLs, file paths, email addresses, shortcodes, or code — reproduce them exactly.";
    }

    /**
     * Core translation method.
     *
     * @param string $text    Text to translate.
     * @param string $from    Source language code.
     * @param string $to      Target language code.
     * @param array  $context Context data.
     * @return string Translated text.
     */
    private function translate( string $text, string $from, string $to, array $context = array() ): string {
        $messages = $this->build_messages( $text, $from, $to, $context );
        $response = $this->ai_client->chat_completion( $messages );

        return $this->extract_translation( $response );
    }

    /**
     * Build chat messages for translation.
     * Hookable for customization.
     *
     * @param string $text    Text to translate.
     * @param string $from    Source language.
     * @param string $to      Target language.
     * @param array  $context Context data.
     * @return array Messages array.
     */
    private function build_messages( string $text, string $from, string $to, array $context ): array {
        $system_prompt = $this->build_system_prompt( $from, $to, $context );
        $user_prompt   = $this->build_user_prompt( $text, $context );

        $messages = array(
            array(
                'content' => $system_prompt,
                'role'    => 'system',
            ),
            array(
                'content' => $user_prompt,
                'role'    => 'user',
            ),
        );

        /**
         * Filter translation messages before sending to AI.
         *
         * @param array  $messages Messages array.
         * @param string $text     Text being translated.
         * @param string $from     Source language.
         * @param string $to       Target language.
         * @param array  $context  Context data.
         */
        $messages = \apply_filters( 'pllat_translation_messages', $messages, $text, $from, $to, $context );

        /**
         * Fires after translation messages are built.
         */
        \do_action(
            'pllat_log_event',
            Event_Codes::AI_MESSAGES_BUILT,
            array(
                'messages'      => $messages,
                'message_count' => \count( $messages ),
                'lang_from'     => $from,
                'lang_to'       => $to,
                'context'       => $context,
            ),
        );

        return $messages;
    }

    /**
     * Build user prompt with text and context.
     *
     * @param string $text    Text to translate.
     * @param array  $context Context data.
     * @return string User prompt.
     */
    private function build_user_prompt( string $text, array $context ): string {
        // Wrap in XML tags to clearly delimit content vs instruction.
        $prompt = "Translate the text inside <translate> tags. Return ONLY the translated text — do not include the <translate> tags or any other markup in your response.\n\n<translate>{$text}</translate>";

        /**
         * Filter the user prompt for translations.
         *
         * @param string $prompt  User prompt.
         * @param string $text    Text being translated.
         * @param array  $context Context data.
         */
        $prompt = \apply_filters( 'pllat_translation_user_prompt', $prompt, $text, $context );

        /**
         * Fires after the user prompt is built.
         */
        \do_action(
            'pllat_log_event',
            Event_Codes::AI_PROMPT_USER,
            array(
                'prompt'       => $prompt,
                'text_length'  => \mb_strlen( $text ),
                'text_preview' => \mb_substr( $text, 0, 100 ),
                'context'      => $context,
            ),
        );

        return $prompt;
    }

    /**
     * Extract translation from AI response.
     *
     * @param array $response AI response.
     * @return string Translated text.
     * @throws \Exception If response is invalid or empty.
     */
    private function extract_translation( array $response ): string {
        // Validate response structure.
        if ( ! $this->is_valid_ai_response( $response ) ) {
            /**
             * Fires when AI response validation fails.
             */
            \do_action(
                'pllat_log_event',
                Event_Codes::AI_RESPONSE_INVALID,
                array( 'response' => $response ),
            );

            throw new \Exception( 'Invalid AI response structure' );
        }

        // A well-formed response can still carry no translation: reasoning-first
        // models (common on OpenRouter, e.g. deepseek-r1) put their answer in
        // message.reasoning and leave content null. That is a different failure
        // from a broken structure, so name the cause instead of the opaque
        // "Invalid AI response structure".
        $content = $response['choices'][0]['message']['content'] ?? null;
        if ( null === $content || '' === \trim( (string) $content ) ) {
            \do_action(
                'pllat_log_event',
                Event_Codes::AI_RESPONSE_EMPTY,
                array( 'response' => $response ),
            );

            throw new \Exception(
                'The AI returned no translation (empty content). Some reasoning-first models '
                . 'on OpenRouter return only reasoning; if you use OpenRouter, select a '
                . 'non-reasoning model.',
            );
        }

        // Validate content.
        $validated_content = $this->validate_translation_content( (string) $content );

        return $validated_content;
    }

    /**
     * Validate AI response structure.
     *
     * @param array $response The AI response.
     * @return bool True if response is valid.
     */
    private function is_valid_ai_response( array $response ): bool {
        // Structure only. Whether content is present is checked separately in
        // extract_translation(), so a well-formed response with empty content
        // (a reasoning-first model that answered only in message.reasoning)
        // gets a specific, actionable error rather than this generic one.
        return isset( $response['choices'] )
            && \is_array( $response['choices'] )
            && \count( $response['choices'] ) > 0
            && isset( $response['choices'][0]['message'] )
            && \is_array( $response['choices'][0]['message'] );
    }

    /**
     * Validate and clean translation content.
     *
     * @param string $content The translation content.
     * @return string The validated content.
     * @throws \Exception If content is invalid.
     */
    private function validate_translation_content( string $content ): string {
        $content = \trim( $content );

        // Strip <translate> wrapper tags if the AI echoed them back.
        $content = \preg_replace( '#^\s*</?translate>\s*#i', '', $content );
        $content = \preg_replace( '#\s*</?translate>\s*$#i', '', $content );
        $content = \trim( $content );

        // Check for empty content.
        if ( '' === $content ) {
            /**
             * Fires when AI returns empty translation.
             */
            \do_action( 'pllat_log_event', Event_Codes::AI_RESPONSE_EMPTY, array() );

            throw new \Exception( 'AI returned empty translation' );
        }

        // Check for suspiciously long content (potential hallucination).
        // Default 100,000 chars (~70 pages) - still prevents extreme hallucinations while allowing large content.
        $max_length = \apply_filters( 'pllat_max_translation_length', 100000 );
        if ( \mb_strlen( $content ) > $max_length ) {
            /**
             * Fires when translation exceeds maximum length.
             */
            \do_action(
                'pllat_log_event',
                Event_Codes::AI_RESPONSE_TOO_LONG,
                array(
                    'actual_length'   => \mb_strlen( $content ),
                    'content_preview' => \mb_substr( $content, 0, 200 ),
                    'max_length'      => $max_length,
                ),
            );

            throw new \Exception( 'Translation exceeds maximum length (' . (int) $max_length . ' characters)' );
        }

        /**
         * Filter to validate translation content.
         *
         * @param string $content The translation content.
         * @return string The validated content.
         * @throws \Exception If content is invalid.
         */
        return \apply_filters( 'pllat_validate_translation_content', $content );
    }
}
