<?php
namespace PLLAT\Admin;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Admin\Controllers\Dashboard_REST_Controller;
use PLLAT\Admin\Controllers\Prime_REST_Controller;
use PLLAT\Admin\Handlers\Admin_Page_Handler;
use PLLAT\Admin\Handlers\Dashboard_Cache_Handler;
use PLLAT\Admin\Handlers\Legacy_Migration_Notice_Handler;
use PLLAT\Admin\Handlers\Post_List_Handler;
use PLLAT\Admin\Services\Admin_Data_Service;
use PLLAT\Admin\Services\Dashboard_Data_Service;
use PLLAT\Admin\Services\Post_List_Service;
use XWP\DI\Decorators\Module;

#[Module(
    hook: 'init',
    priority: 10,
    handlers: array(
        Admin_Page_Handler::class,
        Dashboard_Cache_Handler::class,
        Dashboard_REST_Controller::class,
        Legacy_Migration_Notice_Handler::class,
        Post_List_Handler::class,
        Prime_REST_Controller::class,
    ),
    services: array(
        Admin_Data_Service::class,
        Dashboard_Data_Service::class,
        Post_List_Service::class,
    ),
)]
class Admin_Module {
    public static function configure(): array {
        return array(
            Admin_Data_Service::class     => \DI\autowire(),
            Dashboard_Data_Service::class => \DI\autowire(),
            Post_List_Service::class      => \DI\autowire(),
        );
    }
}
