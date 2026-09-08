<?php
/**
 * Preflight_Check interface file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Contract implemented by each individual preflight check.
 */
interface Preflight_Check {

    /**
     * Unique slug identifying this check (used as result name).
     */
    public function get_name(): string;

    /**
     * Run the check and return its result.
     *
     * @param Preflight_Scope      $scope   Scope describing the run about to start.
     * @param array<string,mixed>  $context Optional scope-specific data (post_id, content_type, etc.).
     */
    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result;

    /**
     * Whether this check applies to the given scope.
     */
    public function applies_to( Preflight_Scope $scope ): bool;
}
