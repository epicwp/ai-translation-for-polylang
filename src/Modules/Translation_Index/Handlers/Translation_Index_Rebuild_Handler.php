<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Services\Translation_Index_Prime_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * Hourly safety rebuild: walks Polylang's translation map in full and
 * upserts the index. Backstop for write-through hooks that may have
 * missed an event (CLI imports, REST skip_hooks, plugins bypassing
 * save_post, etc.) AND for `pll_is_translated_post_type` set changes
 * that don't go through the option-mutation path (polylang-wc bridge,
 * other plugin filters). Drift max ≈ 1h.
 *
 * The hourly recurring action invokes handle() with no args ('post', 0).
 * Each invocation walks chunks only until a wall-clock budget is spent, then
 * re-enqueues itself at the next offset (and chains 'post' → 'term'), mirroring
 * the prime handler. This keeps the hourly cadence (a deliberate drift-freshness
 * choice) while ensuring a single action never tries to walk a 100k+ corpus in
 * one go and time out.
 */
#[Handler( tag: 'init', priority: 20 )]
class Translation_Index_Rebuild_Handler {

    public const HOOK  = 'pllat_translation_index_rebuild';
    private const CHUNK = 500;

    /**
     * Wall-clock budget per invocation. Kept well under typical PHP/AS limits
     * so the action always returns cleanly and re-enqueues if more remains.
     */
    private const TIME_BUDGET_SECONDS = 20;

    public function __construct( protected Translation_Index_Prime_Service $service ) {}

    #[Action( tag: self::HOOK, priority: 10 )]
    public function handle( string $source_kind = 'post', int $offset = 0 ): void {
        $can_async = \function_exists( 'as_enqueue_async_action' );
        $deadline  = \time() + self::TIME_BUDGET_SECONDS;

        do {
            $result = $this->service->run_chunk( $source_kind, $offset, self::CHUNK );
            $offset = (int) $result['next_offset'];

            if ( ! $result['done'] && $can_async && \time() >= $deadline ) {
                // Budget spent — resume this kind from the next offset later.
                \as_enqueue_async_action( self::HOOK, array( $source_kind, $offset ), 'pllat-translation-index' );
                return;
            }
        } while ( ! $result['done'] );

        // Posts done → chain to terms. (No async runner: finish terms inline.)
        if ( 'post' !== $source_kind ) {
            return;
        }
        if ( $can_async ) {
            \as_enqueue_async_action( self::HOOK, array( 'term', 0 ), 'pllat-translation-index' );
        } else {
            $this->handle( 'term', 0 );
        }
    }

    public function ensure_scheduled(): void {
        if ( \as_has_scheduled_action( self::HOOK ) ) {
            return;
        }
        \as_schedule_recurring_action(
            \time() + \HOUR_IN_SECONDS,
            \HOUR_IN_SECONDS,
            self::HOOK,
            array(),
            'pllat-translation-index',
        );
    }
}
