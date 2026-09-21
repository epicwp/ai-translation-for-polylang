<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * Per-user chat session state, stored in WordPress user meta.
 *
 * Holds the `chat_id` that ties follow-up turns to the same hub session, and a "primed"
 * flag so the health-report `metadata` + `client_version` ride only the first turn.
 */
final class Session {
    private const META_CHAT_ID = 'roundtable_chat_id';
    private const META_PRIMED  = 'roundtable_chat_primed';

    /**
     * Creates the session for the given user.
     *
     * @param int $userId The WordPress user id.
     */
    public function __construct( private int $userId ) {
    }

    /**
     * The current chat id, minting and persisting a fresh UUID on first use.
     *
     * @return string The chat/session id.
     */
    public function chatId(): string {
        $existing = (string) \get_user_meta( $this->userId, self::META_CHAT_ID, true );
        if ( '' !== $existing ) {
            return $existing;
        }
        $chatId = \wp_generate_uuid4();
        \update_user_meta( $this->userId, self::META_CHAT_ID, $chatId );
        return $chatId;
    }

    /**
     * Whether the first turn has completed (so context has already been sent).
     *
     * @return bool True once primed.
     */
    public function isPrimed(): bool {
        return '1' === (string) \get_user_meta( $this->userId, self::META_PRIMED, true );
    }

    /** Mark the session primed after a successful first turn. */
    public function markPrimed(): void {
        \update_user_meta( $this->userId, self::META_PRIMED, '1' );
    }

    /** Clear the session — the next turn starts a fresh chat and re-sends context. */
    public function reset(): void {
        \delete_user_meta( $this->userId, self::META_CHAT_ID );
        \delete_user_meta( $this->userId, self::META_PRIMED );
    }
}
