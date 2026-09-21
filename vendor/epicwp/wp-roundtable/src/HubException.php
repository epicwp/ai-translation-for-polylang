<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

// phpcs:disable SlevomatCodingStandard.Classes.SuperfluousExceptionNaming.SuperfluousSuffix -- the `*Exception` suffix is the conventional, discoverable name external devs expect for a public exception type.
/**
 * A failure talking to the hub. The API key is never included in the message.
 *
 * Use the named constructors; `kind()` returns one of the `*` constants so callers can
 * branch (e.g. render "you are over your limit" for OVER_QUOTA).
 */
final class HubException extends \RuntimeException {
    /** The gate refused the request (HTTP 403). */
    public const BLOCKED = 'blocked';
    /** The project's community chat is paused hub-side (HTTP 403, code `chat_disabled`). */
    public const CHAT_DISABLED = 'chat_disabled';
    /** The subject is over its request quota (HTTP 429). */
    public const OVER_QUOTA = 'over_quota';

    public const LICENCE_INVALID = 'licence_invalid';
    /** A transport-level failure (no HTTP response). */
    public const NETWORK = 'network';
    /** The hub returned a server error. */
    public const SERVER = 'server';
    /** The hub response could not be parsed. */
    public const BAD_RESPONSE = 'bad_response';

    /** The request was refused by the gate (HTTP 403). */
    public static function blocked(): self {
        return new self( self::BLOCKED, 'The request was refused (blocked).' );
    }

    /** The project's community chat is paused hub-side (HTTP 403, code `chat_disabled`). */
    public static function chatDisabled(): self {
        return new self( self::CHAT_DISABLED, 'The community chat is paused for this project.' );
    }

    /** The subject is over its request quota (HTTP 429). */
    public static function overQuota(): self {
        return new self( self::OVER_QUOTA, 'The request was refused (over quota).' );
    }

    /** The install's licence failed hub-side verification (HTTP 402). */
    public static function licenceInvalid(): self {
        return new self( self::LICENCE_INVALID, 'The request was refused (licence invalid).' );
    }

    /**
     * A transport-level failure (no HTTP response). `$detail` must not contain secrets.
     *
     * @param string $detail Non-secret detail (e.g. the transport's error string).
     */
    public static function network( string $detail ): self {
        return new self( self::NETWORK, "Network error contacting the hub: {$detail}" );
    }

    /**
     * The hub returned a server error.
     *
     * @param int $status The HTTP status code the hub responded with.
     */
    public static function server( int $status ): self {
        return new self( self::SERVER, "The hub returned a server error (HTTP {$status})." );
    }

    /**
     * The hub response could not be parsed. `$detail` must not contain secrets.
     *
     * @param string $detail Non-secret detail (e.g. the JSON decode error).
     */
    public static function badResponse( string $detail ): self {
        return new self( self::BAD_RESPONSE, "Malformed hub response: {$detail}" );
    }

    /**
     * Builds the exception; use one of the named constructors instead.
     *
     * @param string $kind    One of the class constants.
     * @param string $message Human-readable message. Never contains the API key.
     */
    private function __construct( private string $kind, string $message ) {
        parent::__construct( $message );
    }

    /**
     * Returns the exception's kind.
     *
     * @return string One of the class constants.
     */
    public function kind(): string {
        return $this->kind;
    }
}
