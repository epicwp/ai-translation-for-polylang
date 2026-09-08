<?php
/**
 * Provider_Connectivity_Handler file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\AI_Provider_Registry;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Listens for AI provider settings changes and triggers a fresh connectivity
 * test. Fires a single AS job so the billable ping happens out-of-band rather
 * than in the settings-save request.
 */
#[Handler( tag: 'init', priority: 15 )]
class Provider_Connectivity_Handler {

    private const AS_HOOK = 'pllat_preflight_test_connectivity';

    public function __construct( private Provider_Connectivity_Tester $tester ) {}

    #[Action( tag: 'init', priority: 15 )]
    public function register_as_hook(): void {
        \add_action( self::AS_HOOK, array( $this, 'run_test' ) );
    }

    #[Action( tag: 'updated_option' )]
    public function on_option_updated( string $option_name, mixed $old_value, mixed $new_value ): void {
        if ( ! $this->is_trigger_option( $option_name ) ) {
            return;
        }
        $this->schedule_test();
    }

    #[Action( tag: 'added_option' )]
    public function on_option_added( string $option_name, mixed $value ): void {
        if ( ! $this->is_trigger_option( $option_name ) ) {
            return;
        }
        $this->schedule_test();
    }

    public function run_test(): void {
        $this->tester->test_active_provider();
    }

    private function is_trigger_option( string $option_name ): bool {
        if ( 'pllat_translator_api' === $option_name ) {
            return true;
        }
        foreach ( AI_Provider_Registry::get_provider_keys() as $provider ) {
            if ( "pllat_{$provider}_api_key" === $option_name || "pllat_{$provider}_translation_model" === $option_name ) {
                return true;
            }
        }
        return false;
    }

    private function schedule_test(): void {
        if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
            $this->run_test();
            return;
        }
        if ( \function_exists( 'as_has_scheduled_action' ) && \as_has_scheduled_action( self::AS_HOOK ) ) {
            return;
        }
        \as_enqueue_async_action( self::AS_HOOK, array(), 'pllat-preflight' );
    }
}
