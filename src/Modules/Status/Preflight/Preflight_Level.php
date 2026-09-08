<?php
/**
 * Preflight_Level enum file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Severity level for a single preflight check or aggregated result.
 */
enum Preflight_Level: string {
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';

    /**
     * Return the higher severity of two levels (for aggregation).
     */
    public function max( self $other ): self {
        $order = array(
            self::Pass->value => 0,
            self::Warn->value => 1,
            self::Fail->value => 2,
        );
        return $order[ $this->value ] >= $order[ $other->value ] ? $this : $other;
    }
}
