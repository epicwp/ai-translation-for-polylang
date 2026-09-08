<?php
/**
 * PHP_Environment_Check file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight\Checks
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight\Checks;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Preflight\Preflight_Check;
use PLLAT\Status\Preflight\Preflight_Check_Result;
use PLLAT\Status\Preflight\Preflight_Level;
use PLLAT\Status\Preflight\Preflight_Scope;

/**
 * Validates PHP runtime constraints the translation pipeline needs:
 * memory_limit and max_execution_time.
 */
class PHP_Environment_Check implements Preflight_Check {

    private const MIN_MEMORY_BYTES  = 128 * 1024 * 1024;
    private const WARN_MEMORY_BYTES = 256 * 1024 * 1024;
    private const MIN_EXEC_SECONDS  = 30;
    private const WARN_EXEC_SECONDS = 60;

    public function __construct(
        private string $memory_limit,
        private int $max_execution_time,
    ) {}

    public static function from_ini(): self {
        return new self(
            memory_limit: (string) \ini_get( 'memory_limit' ),
            max_execution_time: (int) \ini_get( 'max_execution_time' ),
        );
    }

    public function get_name(): string {
        return 'php_environment';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $issues = array();

        $memory_bytes = $this->parse_memory_to_bytes( $this->memory_limit );
        if ( -1 !== $memory_bytes && $memory_bytes < self::MIN_MEMORY_BYTES ) {
            $issues[] = array(
                'level'      => Preflight_Level::Fail,
                'message'    => \sprintf( 'PHP memory_limit is %s, minimum is 128M.', $this->memory_limit ),
                'actionable' => 'Ask your host to increase PHP memory_limit to at least 256M.',
            );
        } elseif ( -1 !== $memory_bytes && $memory_bytes < self::WARN_MEMORY_BYTES ) {
            $issues[] = array(
                'level'      => Preflight_Level::Warn,
                'message'    => \sprintf( 'PHP memory_limit is %s, recommended 256M+.', $this->memory_limit ),
                'actionable' => 'Larger posts may hit memory limits. Increase to 256M when possible.',
            );
        }

        if ( $this->max_execution_time > 0 && $this->max_execution_time < self::MIN_EXEC_SECONDS ) {
            $issues[] = array(
                'level'      => Preflight_Level::Fail,
                'message'    => \sprintf( 'PHP max_execution_time is %ds, minimum 30s.', $this->max_execution_time ),
                'actionable' => 'Increase max_execution_time to 60s or more.',
            );
        } elseif ( $this->max_execution_time > 0 && $this->max_execution_time < self::WARN_EXEC_SECONDS ) {
            $issues[] = array(
                'level'      => Preflight_Level::Warn,
                'message'    => \sprintf( 'PHP max_execution_time is %ds, recommended 60s+.', $this->max_execution_time ),
                'actionable' => 'Large translation batches may time out. Increase to 60s+ when possible.',
            );
        }

        if ( array() === $issues ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'PHP environment looks good.',
                null,
            );
        }

        $worst_level = Preflight_Level::Pass;
        $messages    = array();
        $actions     = array();
        foreach ( $issues as $issue ) {
            $worst_level = $worst_level->max( $issue['level'] );
            $messages[]  = $issue['message'];
            if ( null !== $issue['actionable'] ) {
                $actions[] = $issue['actionable'];
            }
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            $worst_level,
            \implode( ' | ', $messages ),
            array() === $actions ? null : \implode( ' ', $actions ),
        );
    }

    private function parse_memory_to_bytes( string $value ): int {
        $value = \trim( $value );
        if ( '-1' === $value || '' === $value ) {
            return -1;
        }

        $unit = \strtoupper( \substr( $value, -1 ) );
        $num  = (int) $value;
        return match ( $unit ) {
            'G'     => $num * 1024 * 1024 * 1024,
            'M'     => $num * 1024 * 1024,
            'K'     => $num * 1024,
            default => $num,
        };
    }
}
