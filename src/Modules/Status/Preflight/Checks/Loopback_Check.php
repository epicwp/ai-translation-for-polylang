<?php
/**
 * Loopback_Check file.
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
 * Issues a self HTTP call to ?pllat_loopback=1 and verifies the server can
 * reach its own URL. Without loopback, async job processing will not work.
 */
class Loopback_Check implements Preflight_Check {

    private const TIMEOUT_SECONDS = 10;

    public function get_name(): string {
        return 'loopback_reachable';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return true;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $url = \add_query_arg( 'pllat_loopback', '1', \site_url( '/' ) );

        $response = \wp_remote_get(
            $url,
            array(
                'timeout'   => self::TIMEOUT_SECONDS,
                'sslverify' => false,
            ),
        );

        if ( \is_wp_error( $response ) ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                'Your site cannot reach itself over HTTP — translations run in the background by calling your own site, so this needs to work.',
                'Common causes: hosting firewall blocking outbound calls, password protection on staging, or a reverse proxy. Ask your host to allow loopback HTTP requests, or set up a server-side cron as a fallback.',
                'https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/',
            );
        }

        $status = (int) \wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new Preflight_Check_Result(
                $this->get_name(),
                Preflight_Level::Fail,
                \sprintf( 'Your site responds with HTTP %d when called from itself — this blocks background translations.', $status ),
                'Common causes: site under a "coming soon" plugin, basic-auth on staging, or a redirect rule. Disable the gate on the loopback URL, or set up a server-side cron as a fallback.',
                'https://www.epicwpsolutions.com/how-to-set-up-server-cron-for-better-plugin-performance/',
            );
        }

        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Pass,
            'Loopback HTTP reachable.',
            null,
        );
    }
}
