<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Handlers\Field_State_Invalidation_Handler;
use PLLAT\Translation_Index\Handlers\Pipeline_Tick_Handler;
use PLLAT\Translation_Index\Handlers\Translation_Index_Prime_Handler;
use PLLAT\Translation_Index\Handlers\Translation_Index_Hook_Handler;
use PLLAT\Translation_Index\Handlers\Translation_Index_Rebuild_Handler;
use PLLAT\Translation_Index\Repositories\Translation_Field_State_Repository;
use PLLAT\Translation_Index\Repositories\Translation_Index_Repository;
use PLLAT\Translation_Index\Services\Field_Hash_Service;
use PLLAT\Translation_Index\Services\Prime_Status_Service;
use PLLAT\Translation_Index\Services\Run_Reconciliation_Service;
use PLLAT\Translation_Index\Services\Translation_Index_Prime_Service;
use XWP\DI\Decorators\Infuse;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\On_Initialize;

/**
 * Translation index module.
 *
 * Owns the wp_pllat_translation_index projection of Polylang's translation
 * map and the wp_pllat_translation_field_state per-field signature ledger.
 *
 * @see docs/plans/2026-05-08-lean-pipeline.md (Phase 1)
 */
#[Module(
    hook: 'init',
    priority: 10,
    handlers: array(
        Translation_Index_Hook_Handler::class,
        Translation_Index_Prime_Handler::class,
        Translation_Index_Rebuild_Handler::class,
        Field_State_Invalidation_Handler::class,
        Pipeline_Tick_Handler::class,
    ),
    services: array(
        Translation_Index_Repository::class,
        Translation_Field_State_Repository::class,
        Field_Hash_Service::class,
        Translation_Index_Prime_Service::class,
        Prime_Status_Service::class,
        Run_Reconciliation_Service::class,
    ),
)]
class Translation_Index_Module implements On_Initialize {

    public static function configure(): array {
        return array(
            Translation_Index_Hook_Handler::class       => \DI\autowire(),
            Translation_Index_Prime_Handler::class      => \DI\autowire(),
            Translation_Index_Rebuild_Handler::class    => \DI\autowire(),
            Field_State_Invalidation_Handler::class     => \DI\autowire(),
            Pipeline_Tick_Handler::class                => \DI\autowire(),
            Translation_Index_Repository::class       => \DI\autowire(),
            Translation_Field_State_Repository::class => \DI\autowire(),
            Field_Hash_Service::class                 => \DI\autowire(),
            Translation_Index_Prime_Service::class    => \DI\autowire(),
            Prime_Status_Service::class               => \DI\autowire(),
            Run_Reconciliation_Service::class         => \DI\autowire(),
        );
    }

    #[Infuse(
        Prime_Status_Service::class,
        Pipeline_Tick_Handler::class,
        Translation_Index_Rebuild_Handler::class,
    )]
    public function on_initialize(
        ?Prime_Status_Service $prime_status = null,
        ?Pipeline_Tick_Handler $tick_handler = null,
        ?Translation_Index_Rebuild_Handler $rebuild_handler = null,
    ): void {
        $prime_status?->ensure_primed();
        $tick_handler?->ensure_scheduled();
        $rebuild_handler?->ensure_scheduled();
    }
}
