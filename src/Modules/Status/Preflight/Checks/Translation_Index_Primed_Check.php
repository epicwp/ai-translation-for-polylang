<?php
/**
 * Translation_Index_Primed_Check file.
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
use PLLAT\Translation_Index\Services\Prime_Status_Service;

/**
 * Blocking check: refuses to start any translation run when the
 * pllat_translation_index has not been primed (or is mid-prime, or is
 * stuck mid-prime). Workers rely on the index for gap computation; a
 * partial index produces a partial run scope. Returning Fail rather
 * than Warn ensures every entry point (single + bulk) honors the gate
 * via the existing is_fail() consult.
 *
 * Two distinct user-facing states both return Fail; the message text
 * distinguishes them and the frontend separately polls
 * /pllat/v1/admin/prime/status for live progress when an AS action
 * is active:
 *   - "Priming in progress" — Prime_Status_Service::is_currently_priming()
 *   - "Needs to run" / "Stuck" — everything else that is not Pass
 */
class Translation_Index_Primed_Check implements Preflight_Check {

    public const NAME = 'translation_index_primed';

    public function __construct( private Prime_Status_Service $prime ) {}

    public function get_name(): string {
        return self::NAME;
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        // Primed wins over everything else — a fully-built index is usable
        // regardless of stale AS actions left over from prior runs.
        if ( $this->prime->is_primed() ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'Translation index is ready.',
                null,
            );
        }

        if ( $this->prime->has_stuck_prime() ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                'Translation index priming appears stuck.',
                'The priming background job has been running for over 5 minutes without completing. Click Re-run prime to reset and restart it.',
            );
        }

        if ( $this->prime->is_currently_priming() ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                'Translation index priming is in progress.',
                'The plugin is detecting your existing translations. This usually takes a minute or two. Translation runs will become available as soon as it finishes.',
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Fail,
            'Translation index has not been primed.',
            'The plugin needs to detect your existing translations once before it can run new ones. Click Re-run prime to start this background job.',
        );
    }
}
