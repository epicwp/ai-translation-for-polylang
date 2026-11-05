<?php
/**
 * Translation_Refused_Exception class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

declare(strict_types=1);

namespace PLLAT\Translator\Exceptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Signals that a translation request was refused before a run was created.
 *
 * Carries the machine-readable code, extra response data and HTTP status so
 * the REST route and the abilities can each map it to their own error shape.
 */
class Translation_Refused_Exception extends \RuntimeException {
    /**
     * Constructor.
     *
     * @param string               $refusal_code Machine-readable refusal code.
     * @param string               $message      Human-readable message.
     * @param array<string, mixed> $data         Extra data for the response.
     * @param int                  $http_status  HTTP status for the refusal.
     */
    public function __construct(
        private string $refusal_code,
        string $message,
        private array $data = array(),
        private int $http_status = 422,
    ) {
        parent::__construct( $message );
    }

    /**
     * Get the refusal code.
     */
    public function code(): string {
        return $this->refusal_code;
    }

    /**
     * Get the extra response data.
     *
     * @return array<string, mixed>
     */
    public function data(): array {
        return $this->data;
    }

    /**
     * Get the HTTP status.
     */
    public function http_status(): int {
        return $this->http_status;
    }

    /**
     * Convert to a WP_Error.
     */
    public function to_wp_error(): \WP_Error {
        return new \WP_Error(
            $this->refusal_code,
            $this->getMessage(),
            $this->data + array( 'status' => $this->http_status ),
        );
    }
}
