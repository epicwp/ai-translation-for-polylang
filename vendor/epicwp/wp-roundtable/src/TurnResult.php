<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/** The parsed outcome of one chat turn: the ordered events plus convenience accessors. */
final class TurnResult {
    /**
     * Creates the turn result.
     *
     * @param list<Event> $events The turn's events, in order.
     */
    public function __construct( // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- `list<Event>` is a PHPStan generic array-shape; the native param type stays `array`.
        public readonly array $events,
    ) {
    }

    /**
     * The assistant's reply — every `assistant_text` event's `text`, concatenated in order.
     *
     * @return string The reply text (empty string if the turn produced no assistant text).
     */
    public function assistantText(): string {
        $text = '';
        foreach ( $this->events as $event ) {
            if ( Event::ASSISTANT_TEXT !== $event->type || ! ( \is_string( $event->data['text'] ?? null ) ) ) {
                continue;
            }

            $text .= $event->data['text'];
        }
        return $text;
    }

    /**
     * The turn-completion `result` event, if any.
     *
     * @return Event|null The result event, or null.
     */
    public function outcome(): ?Event {
        foreach ( $this->events as $event ) {
            if ( Event::RESULT === $event->type ) {
                return $event;
            }
        }
        return null;
    }

    /**
     * Whether the turn errored — an `error` event, or a `result` event flagged `is_error`.
     *
     * @return bool True on error.
     */
    public function isError(): bool {
        foreach ( $this->events as $event ) {
            if ( Event::ERROR === $event->type || $this->resultFlagsError( $event ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The "working…" steps (`tool_step` / `progress`) for the later UI to render.
     *
     * @return list<Event> The step events, in order.
     */
    public function steps(): array {
        return \array_values(
            \array_filter(
                $this->events,
                static fn( Event $e ): bool => \in_array(
                    $e->type,
                    array( Event::TOOL_STEP, Event::PROGRESS ),
                    true,
                ),
            ),
        );
    }

    /**
     * Whether a `result` event is flagged `is_error`.
     *
     * @param Event $event The event to check.
     * @return bool True if it's a result event with `is_error` true.
     */
    private function resultFlagsError( Event $event ): bool {
        return Event::RESULT === $event->type && true === ( $event->data['is_error'] ?? false );
    }
}
