<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * One contract event from a chat turn (mirrors the hub's `agent/events.py`).
 *
 * `$data` is the decoded frame payload, verbatim. The event `type` is one of the
 * constants below; unknown types are preserved as-is.
 */
final class Event {
    /** The assistant's final reply text. */
    public const ASSISTANT_TEXT = 'assistant_text';
    /** The assistant's intermediate reasoning, streamed as it thinks. */
    public const THINKING = 'thinking';
    /** The assistant is about to invoke a tool. */
    public const TOOL_STEP = 'tool_step';
    /** The result returned by a previously invoked tool. */
    public const TOOL_RESULT = 'tool_result';
    /** A progress update on a long-running turn. */
    public const PROGRESS = 'progress';
    /** The turn's final outcome. */
    public const RESULT = 'result';
    /** The turn failed; `$data` carries the error detail. */
    public const ERROR = 'error';
    /** E12: the agent judged this conversation worth turning into a topic. */
    public const TOPIC_WORTHY = 'topic_worthy';
    /** E12: a topic proposal was drafted and persisted as a private draft Case. */
    public const TOPIC_DRAFTED = 'topic_drafted';

    /**
     * Creates the event.
     *
     * @param string               $type The event type (one of the class constants).
     * @param array<string, mixed> $data The decoded frame payload.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {
    }
}
