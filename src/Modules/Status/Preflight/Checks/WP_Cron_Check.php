<?php
/**
 * WP_Cron_Check file.
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
use PLLAT\Status\Services\Cron_Status_Service;

/**
 * Warns when WP-Cron is configured in a way that risks stalled translations:
 *
 *   - Internal WP-Cron is on: works, but only fires on page visits — low
 *     traffic sites can stall between visits.
 *   - DISABLE_WP_CRON is set but no event has fired for 5+ minutes: server
 *     cron is presumably configured but not actually hitting wp-cron.php.
 *
 * Never returns Fail: translations can still recover the next time cron
 * (or a page visit, for internal WP-Cron) runs. Both warns are dismissable
 * per-browser via the existing preflight modal.
 */
class WP_Cron_Check implements Preflight_Check {
    private const CRON_HELP_URL     = 'https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/';
    private const OVERDUE_THRESHOLD = 5 * \MINUTE_IN_SECONDS;

    public function __construct( private Cron_Status_Service $cron ) {
    }

    public function get_name(): string {
        return 'wp_cron';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        if ( ! $this->cron->is_disable_wp_cron_defined() ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Warn,
                'Using internal WP-Cron. On low-traffic sites this can delay translations between visits.',
                'Set up a server-side cron job calling wp-cron.php every minute. The guide below has step-by-step instructions for cPanel, Plesk, and most hosting panels.',
                self::CRON_HELP_URL,
            );
        }

        $overdue = $this->cron->get_oldest_overdue_seconds( self::OVERDUE_THRESHOLD );
        if ( null === $overdue ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Pass,
                'Server cron is processing scheduled events.',
                null,
            );
        }

        $minutes = (int) \ceil( $overdue / 60 );
        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Warn,
            \sprintf(
                'Server cron is configured but no events have fired for %d minute%s.',
                $minutes,
                1 === $minutes ? '' : 's',
            ),
            'Verify your server cron is calling wp-cron.php every minute. The guide below covers common hosting panels.',
            self::CRON_HELP_URL,
        );
    }
}
