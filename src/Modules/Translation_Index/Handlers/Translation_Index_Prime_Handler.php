<?php
declare(strict_types=1);

namespace PLLAT\Translation_Index\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Services\Prime_Status_Service;
use PLLAT\Translation_Index\Services\Translation_Index_Prime_Service;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * AS hook target: walks the prime in chunks, self-spawning until done.
 *
 * Pass first hook invocation as `do_action(self::HOOK, 'post', 0)`. The
 * handler will chain to terms after posts complete, and set the
 * `pllat_translation_index_primed` option on full done.
 */
#[Handler( tag: 'init', priority: 20 )]
class Translation_Index_Prime_Handler {

    public const HOOK = 'pllat_translation_index_prime';
    private const CHUNK_SIZE = 500;

    public function __construct(
        protected Translation_Index_Prime_Service $service,
        protected Prime_Status_Service $status,
    ) {}

    #[Action( tag: self::HOOK, priority: 10 )]
    public function handle( string $source_kind, int $offset ): void {
        // First chunk of a fresh walk — stamp the start time for the report and
        // drop the cached progress denominators so they are recomputed once for
        // this run (post/term counts may have changed since the last prime).
        if ( 'post' === $source_kind && 0 === $offset ) {
            $this->status->mark_prime_started();
            \delete_option( 'pllat_prime_total_posts' );
            \delete_option( 'pllat_prime_total_terms' );
        }

        $result = $this->service->run_chunk( $source_kind, $offset, self::CHUNK_SIZE );

        $this->update_progress( $source_kind, $result );

        if ( ! $result['done'] ) {
            \as_enqueue_async_action(
                self::HOOK,
                array( $source_kind, $result['next_offset'] ),
                'pllat-prime',
            );
            return;
        }

        if ( 'post' === $source_kind ) {
            \as_enqueue_async_action(
                self::HOOK,
                array( 'term', 0 ),
                'pllat-prime',
            );
            return;
        }

        // Term phase done → the whole walk is complete.
        $this->status->mark_prime_completed();
        \update_option( 'pllat_translation_index_primed', 1, false );
    }

    /**
     * Record a coarse progress percentage (0-99) after each chunk.
     * Posts phase maps to 0-50%, terms phase to 50-99%.
     * Full done (100%) is signalled by pllat_translation_index_primed=1 instead.
     */
    private function update_progress( string $kind, array $result ): void {
        if ( 'post' === $kind ) {
            $total  = $this->cached_total( 'post' );
            $base   = 0;
            $weight = 50;
        } else {
            $total  = $this->cached_total( 'term' );
            $base   = 50;
            $weight = 49;
        }
        if ( $total <= 0 ) {
            return;
        }
        $offset_done = (int) $result['next_offset'];
        $pct         = $base + (int) \min( $weight, ( $offset_done / $total ) * $weight );
        \update_option( 'pllat_translation_index_prime_progress', $pct, false );
    }

    /**
     * The progress denominator (total posts / terms) for a kind, computed once
     * per prime run and cached in an option — the previous code re-ran an
     * unfiltered COUNT(*) full scan on every chunk (~hundreds of times) just to
     * derive a constant. Reset at the start of each prime in handle().
     */
    private function cached_total( string $kind ): int {
        $option = 'post' === $kind ? 'pllat_prime_total_posts' : 'pllat_prime_total_terms';
        $cached = (int) \get_option( $option, 0 );
        if ( $cached > 0 ) {
            return $cached;
        }
        global $wpdb;
        $total = 'post' === $kind
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" )
            : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
        \update_option( $option, $total, false );
        return $total;
    }
}
