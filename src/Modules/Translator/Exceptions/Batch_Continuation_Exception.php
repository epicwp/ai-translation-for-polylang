<?php
/**
 * Batch_Continuation_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Signals that batch translation requires continuation in a new AS action.
 *
 * Thrown by Translation_Batch_Service after processing one batch when more batches remain.
 * Caught by Lean_Job_Worker to save batch state and continue on the next AS tick.
 *
 * This is an intentional flow control mechanism (like Symfony AccessDenied)
 * that short-circuits a deep call chain without changing return types.
 */
class Batch_Continuation_Exception extends \Exception {
    /**
     * Constructor.
     *
     * @param array<string, mixed> $batch_state State to persist for continuation.
     */
    public function __construct(
        private array $batch_state,
    ) {
        parent::__construct( 'Batch translation requires continuation.' );
    }

    /**
     * Get the batch state for persistence.
     *
     * @return array<string, mixed> Batch state with keys: patches, translated, next_batch, batch_start_id.
     */
    public function get_batch_state(): array {
        return $this->batch_state;
    }
}
