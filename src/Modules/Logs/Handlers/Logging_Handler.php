<?php
declare(strict_types=1);

namespace PLLAT\Logs\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Debug\Services\Error_Logger_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Logging handler for translation events.
 *
 * Listens to translation-related WordPress actions and logs them via Error_Logger_Service.
 */
#[Handler( tag: 'init', priority: 10 )]
class Logging_Handler {
    /**
     * Characters of body content kept per logged value.
     */
    private const MAX_LOGGED_VALUE = 500;

    /**
     * Constructor.
     *
     * @param Error_Logger_Service $error_logger The error logger service.
     */
    public function __construct(
        private Error_Logger_Service $error_logger,
    ) {
    }

    /**
     * Log when a term update fails via Polylang's term API.
     *
     * @param int       $term_id  Term ID.
     * @param string    $field    Field name that failed to update.
     * @param \WP_Error $error    The error from wp_update_term.
     * @return void
     */
    #[Action( tag: 'pllat_term_update_failed', priority: 10 )]
    public function log_term_update_failure( int $term_id, string $field, \WP_Error $error ): void {
        $this->error_logger->log_error(
            \sprintf(
                'Failed to update term #%d field "%s": %s',
                $term_id,
                $field,
                $error->get_error_message(),
            ),
            array(
                'field'   => $field,
                'term_id' => $term_id,
            ),
        );
    }

    /**
     * Log when copy_post fails.
     *
     * @param int    $source_id Source post ID.
     * @param string $language  Target language.
     * @param string $error     Error message.
     * @return void
     */
    #[Action( tag: 'pllat_copy_post_error', priority: 10 )]
    public function log_copy_post_error( int $source_id, string $language, string $error ): void {
        $this->error_logger->log_error(
            'Translation post creation failed',
            array(
                'error'     => $error,
                'language'  => $language,
                'source_id' => $source_id,
            ),
        );
    }

    /**
     * Catch-all sink for `pllat_log_error` action calls fired from anywhere
     * in the plugin (worker write_back exceptions, link-replacement failures,
     * batch translation failures recorded by Claim_Lifecycle, integration
     * handlers).
     *
     * Before this handler existed, every do_action('pllat_log_error', $msg)
     * in the codebase vanished silently — no listener was bound. Operators
     * checking Settings → Support → Error Logs saw an empty file even when
     * translations were failing.
     *
     * @param string               $message The error message.
     * @param array<string, mixed> $context Optional structured context.
     */
    #[Action( tag: 'pllat_log_error', priority: 10 )]
    public function log_error( string $message, array $context = array() ): void {
        $this->error_logger->log_error( $message, $context );
    }

    /**
     * Catch-all sink for `pllat_log_warning` action calls.
     *
     * Counterpart to log_error above. Warnings had no listener bound at all, so
     * anything raised this way — a preflight bypass, a field that produced no
     * translatable content — was dropped before reaching the log, leaving
     * support with nothing to correlate against a customer report.
     *
     * @param string               $message The warning message.
     * @param array<string, mixed> $context Optional structured context.
     */
    #[Action( tag: 'pllat_log_warning', priority: 10 )]
    public function log_warning( string $message, array $context = array() ): void {
        $this->error_logger->log_warning( $message, $context );
    }

    /**
     * Record the payload behind a placeholder validation failure.
     *
     * Gutenberg_Patch_Applier fires this with the original text, the model's
     * output, the path and the tag map, then throws. Nothing was listening, so
     * the one payload that explains the failure was destroyed at the only moment
     * it existed — leaving a path in the activity log and no way to tell a model
     * that mangled the markup from source markup that was impossible to preserve.
     *
     * Both texts are truncated: they are body content, and a run can produce
     * hundreds of these.
     *
     * @param array<string, mixed> $payload Keys: original_value, translated_value, path, tag_map.
     */
    #[Action( tag: 'pllat_placeholder_validation_failed', priority: 10 )]
    public function log_placeholder_validation_failed( array $payload ): void {
        $tag_map = \is_array( $payload['tag_map'] ?? null ) ? $payload['tag_map'] : array();

        $tags = array();
        foreach ( $tag_map as $info ) {
            $tags[] = (string) ( $info['tag'] ?? '' );
        }

        $this->error_logger->log_warning(
            \sprintf(
                'Placeholder structure lost in translation for path: %s',
                (string) ( $payload['path'] ?? 'unknown' ),
            ),
            array(
                'original_value'   => $this->clip( $payload['original_value'] ?? '' ),
                'placeholder_ids'  => \array_map( '\intval', \array_keys( $tag_map ) ),
                'placeholder_tags' => $tags,
                'translated_value' => $this->clip( $payload['translated_value'] ?? '' ),
            ),
        );
    }

    /**
     * Clip a body-content value to keep the log readable.
     *
     * @param mixed $value Raw value.
     * @return string Clipped value.
     */
    private function clip( mixed $value ): string {
        $text = \is_string( $value ) ? $value : '';

        return \mb_strlen( $text ) > self::MAX_LOGGED_VALUE
            ? \mb_substr( $text, 0, self::MAX_LOGGED_VALUE ) . '…'
            : $text;
    }
}
