<?php
/**
 * Polylang_Languages_Check file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight\Checks
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight\Checks;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Common\Interfaces\Language_Manager;
use PLLAT\Status\Preflight\Preflight_Check;
use PLLAT\Status\Preflight\Preflight_Check_Result;
use PLLAT\Status\Preflight\Preflight_Level;
use PLLAT\Status\Preflight\Preflight_Scope;

/**
 * Verifies Polylang has at least 2 languages configured and a default set.
 */
class Polylang_Languages_Check implements Preflight_Check {

    public function __construct( private Language_Manager $language_manager ) {}

    public function get_name(): string {
        return 'polylang_languages';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $languages = $this->language_manager->get_available_languages();
        $default   = $this->language_manager->get_default_language();

        if ( \count( $languages ) < 2 ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                'Fewer than 2 languages configured in Polylang.',
                'Add at least 2 languages in Polylang settings.',
            );
        }

        if ( '' === $default ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                'No default Polylang language set.',
                'Set a default language in Polylang settings.',
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Pass,
            \sprintf( '%d languages configured, default: %s.', \count( $languages ), $default ),
            null,
        );
    }
}
