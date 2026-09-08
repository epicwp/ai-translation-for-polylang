<?php
declare(strict_types=1);

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped record of the Action Scheduler action currently executing.
 *
 * Action Scheduler fires `action_scheduler_before_execute` with the action
 * id immediately before each callback (AS's own FatalErrorMonitor uses the
 * same hook). Translation_Worker_Handler captures it here so the claim a
 * worker creates can record its owning action. Claim liveness is then
 * derived from that action's status — AS is the system of record for
 * "is the worker alive" and is a hard plugin requirement.
 *
 * Static because exactly one AS action executes per request; the queue
 * runner overwrites it per action via the before_execute hook.
 */
final class AS_Action_Context {
    /**
     * The id of the AS action executing this request, or null when none.
     *
     * @var int|null
     */
    private static ?int $action_id = null;

    /**
     * Record the executing AS action id. Non-positive ids are treated as
     * "no owner" (null).
     *
     * @param int|null $action_id The Action Scheduler action id.
     */
    public static function set( ?int $action_id ): void {
        self::$action_id = null !== $action_id && $action_id > 0 ? $action_id : null;
    }

    /**
     * The id of the AS action executing this request, or null.
     *
     * @return int|null
     */
    public static function get(): ?int {
        return self::$action_id;
    }

    /**
     * Forget the current AS action (after_execute).
     */
    public static function clear(): void {
        self::$action_id = null;
    }
}
