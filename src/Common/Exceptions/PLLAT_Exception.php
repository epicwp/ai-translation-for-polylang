<?php
declare(strict_types=1);

namespace PLLAT\Common\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Custom exception class for PLLAT plugin.
 *
 * Extends standard Exception with structured context that bubbles up with the exception.
 */
class PLLAT_Exception extends \Exception {
    /**
     * Constructor.
     *
     * @param string          $message  The exception message.
     * @param array           $context  Structured context data (task_id, job_id, etc.).
     * @param \Throwable|null $previous The previous exception for chaining.
     */
    public function __construct(
        string $message,
        public readonly array $context = array(),
        ?\Throwable $previous = null,
    ) {
        parent::__construct( $message, 0, $previous );
    }

    /**
     * Get all context including from chained exceptions.
     *
     * @return array Merged context from this exception and all previous exceptions.
     */
    public function get_full_context(): array {
        $full_context = $this->context;

        $previous = $this->getPrevious();
        while ( $previous instanceof self ) {
            $full_context = \array_merge( $previous->context, $full_context );
            $previous     = $previous->getPrevious();
        }

        return $full_context;
    }
}
