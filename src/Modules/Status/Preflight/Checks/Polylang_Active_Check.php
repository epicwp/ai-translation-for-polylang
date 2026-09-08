<?php
/**
 * Polylang_Active_Check file.
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
 * Verifies Polylang is loaded. Without it the plugin has nothing to translate.
 */
class Polylang_Active_Check implements Preflight_Check {

    public function __construct( private bool $pll_function_exists ) {}

    public static function detect(): self {
        return new self( \function_exists( 'pll_languages_list' ) );
    }

    public function get_name(): string {
        return 'polylang_active';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        if ( $this->pll_function_exists ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'Polylang is active.',
                null,
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Fail,
            'Polylang is not active.',
            'Install and activate Polylang before using PLLAT.',
        );
    }
}
