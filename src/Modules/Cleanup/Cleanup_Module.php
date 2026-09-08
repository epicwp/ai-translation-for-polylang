<?php
/**
 * Cleanup_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Cleanup
 */

declare(strict_types=1);

namespace PLLAT\Cleanup;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Cleanup\Handlers\Cleanup_Handler;
use PLLAT\Cleanup\Handlers\Job_Cleanup_Handler;
use PLLAT\Cleanup\Services\Cleanup_Service;
use XWP\DI\Decorators\Module;

/**
 * Cleanup module — content deletion cleanup + old run retention.
 *
 * Responsibilities:
 * - Cascade source deletion through pllat_claims (Cleanup_Handler).
 * - Daily retention sweep of completed runs (Job_Cleanup_Handler).
 *
 * Renamed from Sync_Module in v3.12 after the legacy sync/discovery
 * pipeline was removed.
 */
#[Module(
    hook: 'init',
    priority: 15,
    handlers: array(
        Cleanup_Handler::class,
        Job_Cleanup_Handler::class,
    ),
    services: array(
        Cleanup_Service::class,
    ),
)]
class Cleanup_Module {
}
