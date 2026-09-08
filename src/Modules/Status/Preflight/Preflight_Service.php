<?php
/**
 * Preflight_Service file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Runs all registered preflight checks for a given scope and aggregates their
 * results into a single Preflight_Result. No billable operations ever happen
 * inside run() — the cached-connectivity check reads options only.
 */
class Preflight_Service {

    /**
     * @param array<Preflight_Check> $checks
     */
    public function __construct( private array $checks ) {}

    /**
     * @param array<string,mixed> $context
     */
    public function check( Preflight_Scope $scope, array $context = array() ): Preflight_Result {
        $results = array();
        foreach ( $this->checks as $check ) {
            if ( ! $check->applies_to( $scope ) ) {
                continue;
            }
            $results[] = $check->run( $scope, $context );
        }
        return new Preflight_Result( $results );
    }
}
