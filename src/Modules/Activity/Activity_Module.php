<?php
/**
 * Activity_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Activity
 */

namespace PLLAT\Activity;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Activity\Controllers\Activity_REST_Controller;
use PLLAT\Activity\Repositories\Activity_Repository;
use PLLAT\Activity\Services\Activity_Service;
use XWP\DI\Decorators\Module;

/**
 * Activity module definition.
 * Provides activity panel data via REST API endpoints backed by lean tables only.
 * No dependency on Activity_Log, Job model, Task model, or Run model.
 */
#[Module(
    hook: 'init',
    priority: -10,
    handlers: array(
        Activity_REST_Controller::class,
    ),
    services: array(
        Activity_Repository::class,
        Activity_Service::class,
    ),
)]
class Activity_Module {
    /**
     * Module definition.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array(
            Activity_Repository::class => \DI\autowire(),
            Activity_Service::class    => \DI\autowire(),
        );
    }
}
