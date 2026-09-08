<?php
/**
 * Loopback_Handler file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Registers the ?pllat_loopback=1 query handler at the earliest init priority.
 *
 * Must load independent of Translator_Module / Status_Module init success so
 * loopback checks work even during degraded states (missing API key, etc.).
 */
#[Handler( tag: 'init', priority: 11 )]
class Loopback_Handler {

    #[Action( tag: 'init', priority: 11 )]
    public function handle_loopback_request(): void {
        if ( ! isset( $_GET['pllat_loopback'] ) ) {
            return;
        }

        \status_header( 200 );
        \header( 'Content-Type: application/json' );
        echo \wp_json_encode(
            array(
                'ok'        => true,
                'timestamp' => \time(),
                'plugin'    => 'pllat',
            ),
        );
        exit;
    }
}
