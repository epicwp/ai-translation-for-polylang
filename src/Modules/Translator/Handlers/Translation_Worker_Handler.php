<?php
declare(strict_types=1);

namespace PLLAT\Translator\Handlers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translation_Index\Handlers\Pipeline_Tick_Handler;
use PLLAT\Translator\Services\AS_Action_Context;
use PLLAT\Translator\Services\Lean_Job_Worker;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

/**
 * AS hook target for the lean worker.
 *
 * Pipeline_Tick_Handler enqueues one HOOK_PROCESS_RUN action per
 * concurrency slot per processing run. This handler is the listener:
 * each enqueued action invokes Lean_Job_Worker::run( $run_id ) once.
 *
 * The worker self-limits via MAX_CLAIMS_PER_INVOCATION; the next tick
 * re-enqueues actions for runs that still have work.
 */
#[Handler( tag: 'init', priority: 20 )]
class Translation_Worker_Handler {

    public function __construct(
        protected Lean_Job_Worker $worker,
    ) {}

    #[Action( tag: Pipeline_Tick_Handler::HOOK_PROCESS_RUN, priority: 10 )]
    public function handle( int $run_id ): void {
        if ( $run_id <= 0 ) {
            return;
        }
        $this->worker->run( $run_id );
    }

    /**
     * Record which Action Scheduler action is executing so the claim a
     * worker creates can be tied to it (AS-derived claim liveness).
     * Fires for every AS action; harmless for non-worker actions since
     * only Job_Claim_Service::attempt_insert reads it, and that only runs
     * inside a worker action.
     *
     * @param mixed $action_id The Action Scheduler action id being executed.
     */
    #[Action( tag: 'action_scheduler_before_execute', priority: 0, args: 1 )]
    public function capture_action( mixed $action_id ): void {
        AS_Action_Context::set( (int) $action_id );
    }

    /**
     * Forget the captured AS action once it finishes executing.
     */
    #[Action( tag: 'action_scheduler_after_execute', priority: 0 )]
    public function release_action(): void {
        AS_Action_Context::clear();
    }
}
