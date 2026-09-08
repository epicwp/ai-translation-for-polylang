<?php
/**
 * Status_Module class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status
 */

namespace PLLAT\Status;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Controllers\Preflight_REST_Controller;
use PLLAT\Status\Controllers\System_Report_REST_Controller;
use PLLAT\Status\Preflight\Checks\Loopback_Check;
use PLLAT\Status\Preflight\Checks\PHP_Environment_Check;
use PLLAT\Status\Preflight\Checks\Polylang_Active_Check;
use PLLAT\Status\Preflight\Checks\Polylang_Languages_Check;
use PLLAT\Status\Preflight\Checks\Polylang_Source_Language_Check;
use PLLAT\Status\Preflight\Checks\Provider_Connectivity_Check;
use PLLAT\Status\Preflight\Checks\Provider_Key_Check;
use PLLAT\Status\Preflight\Checks\Translation_Index_Primed_Check;
use PLLAT\Status\Preflight\Checks\WooCommerce_Variations_Check;
use PLLAT\Status\Preflight\Checks\WP_Cron_Check;
use PLLAT\Status\Preflight\Loopback_Handler;
use PLLAT\Status\Preflight\Preflight_Service;
use PLLAT\Status\Preflight\Provider_Connectivity_Handler;
use PLLAT\Status\Preflight\Provider_Connectivity_Tester;
use PLLAT\Status\Preflight\Provider_Validation_Gateway;
use PLLAT\Status\Services\Cron_Status_Service;
use PLLAT\Status\Services\Health_Service;
use PLLAT\Status\Services\Index_Coverage_Service;
use PLLAT\Status\Services\System_Report_Service;
use XWP\DI\Decorators\Module;

/**
 * Status module — exposes Preflight checks and REST surface only.
 * Diagnostic tools (Status, Job_Timeline, Diagnostic_Bundle) removed in Sprint 6.
 */
#[Module(
    hook: 'plugins_loaded',
    priority: 10,
    context: Module::CTX_ADMIN | Module::CTX_REST | Module::CTX_FRONTEND,
    handlers: array(
        Loopback_Handler::class,
        Provider_Connectivity_Handler::class,
        Preflight_REST_Controller::class,
        System_Report_REST_Controller::class,
    ),
    services: array(
        Provider_Validation_Gateway::class,
        Provider_Connectivity_Tester::class,
        Loopback_Check::class,
        Polylang_Languages_Check::class,
        Polylang_Source_Language_Check::class,
        Provider_Key_Check::class,
        Provider_Connectivity_Check::class,
        WooCommerce_Variations_Check::class,
        WP_Cron_Check::class,
        Cron_Status_Service::class,
        Translation_Index_Primed_Check::class,
        Preflight_Service::class,
        Health_Service::class,
        Index_Coverage_Service::class,
        System_Report_Service::class,
    ),
)]
class Status_Module {
    /**
     * Module configuration.
     *
     * PHP_Environment_Check and Polylang_Active_Check take scalar/bool args
     * that DI auto-wiring cannot resolve — wire them through named-constructor
     * factories. Preflight_Service is composed from all check services so its
     * concrete array is assembled here.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array(
            PHP_Environment_Check::class => \DI\factory(
                static fn(): PHP_Environment_Check => PHP_Environment_Check::from_ini(),
            ),
            Polylang_Active_Check::class => \DI\factory(
                static fn(): Polylang_Active_Check => Polylang_Active_Check::detect(),
            ),
            Preflight_Service::class     => \DI\factory(
                static fn(
                    Loopback_Check $loopback,
                    PHP_Environment_Check $php,
                    Polylang_Active_Check $pll_active,
                    Polylang_Languages_Check $pll_languages,
                    Polylang_Source_Language_Check $pll_source,
                    Provider_Key_Check $provider_key,
                    Provider_Connectivity_Check $provider_connectivity,
                    WooCommerce_Variations_Check $wc_variations,
                    WP_Cron_Check $wp_cron,
                    Translation_Index_Primed_Check $index_primed,
                ): Preflight_Service => new Preflight_Service(
                    array(
                        $loopback,
                        $php,
                        $pll_active,
                        $pll_languages,
                        $pll_source,
                        $provider_key,
                        $provider_connectivity,
                        $wc_variations,
                        $wp_cron,
                        $index_primed,
                    ),
                ),
            ),
        );
    }
}
