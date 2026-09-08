<?php
/**
 * Preflight_Failed_Exception file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a hard-gate preflight check fails before a translation run starts.
 */
class Preflight_Failed_Exception extends \RuntimeException {

    public function __construct( public readonly Preflight_Result $result ) {
        parent::__construct( 'Preflight check failed. See result for details.' );
    }
}
