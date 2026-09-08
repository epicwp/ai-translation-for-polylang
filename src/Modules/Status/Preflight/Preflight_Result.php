<?php
/**
 * Preflight_Result aggregate value object.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Aggregate of preflight check results with overall severity.
 */
final class Preflight_Result {

    /**
     * @param array<Preflight_Check_Result> $checks Per-check results.
     */
    public function __construct( public readonly array $checks ) {}

    public function get_overall_level(): Preflight_Level {
        $level = Preflight_Level::Pass;
        foreach ( $this->checks as $check ) {
            $level = $level->max( $check->level );
        }
        return $level;
    }

    public function is_fail(): bool {
        return Preflight_Level::Fail === $this->get_overall_level();
    }

    public function has_warnings(): bool {
        return Preflight_Level::Warn === $this->get_overall_level();
    }

    /**
     * @return array{overall_level:string,checks:array<array<string,mixed>>}
     */
    public function to_array(): array {
        return array(
            'overall_level' => $this->get_overall_level()->value,
            'checks'        => \array_map(
                static fn( Preflight_Check_Result $c ): array => $c->to_array(),
                $this->checks,
            ),
        );
    }
}
