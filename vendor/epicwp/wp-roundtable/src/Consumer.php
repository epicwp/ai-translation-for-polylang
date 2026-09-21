<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/**
 * The consuming plugin's licensing/identity boundary.
 *
 * The SDK calls these per request to decide whether a request may be made and to supply
 * per-user context; it never hardcodes any of it. All methods run server-side.
 */
interface Consumer {
    /**
     * Whether the current user may use Roundtable (e.g. holds a valid licence).
     *
     * The SDK makes NO hub request when this returns false. The SDK's own REST gate only
     * requires the `read` capability (i.e. any logged-in user); it does NOT restrict by role.
     * If Roundtable should be limited to a specific role or capability (e.g. admins-only),
     * that check is the consumer's responsibility to enforce inside this method.
     *
     * @return bool True to allow the request; false to refuse before any hub call.
     */
    public function isUserAllowed(): bool;

    /**
     * A stable, opaque per-user handle (e.g. derived from the licence).
     *
     * Never a real name; the hub mints its pseudonymous handle from this value.
     *
     * @return string The opaque subject id.
     */
    public function subjectId(): string;

    /**
     * Opaque ambient context sent silently on the first turn (the WP site health report).
     *
     * The agent uses it without announcing it. Return null to send none.
     *
     * @return string|null The metadata string, or null.
     */
    public function metadata(): ?string;

    /**
     * The consuming plugin's version, sent as the top-level `client_version` field.
     *
     * @return string|null The version string, or null.
     */
    public function clientVersion(): ?string;

    /**
     * The install's licence material, verified server-side by the hub.
     *
     * Sent with every chat turn as the `licence` body field. Hubs with licence
     * verification enabled refuse chat turns without a valid payload; return null
     * when the consumer has no licence system (the hub project must then run with
     * verification disabled).
     *
     * @return array{key: string, activation_id: string}|null The licence material, or null.
     */
    public function licence(): ?array;

    /**
     * The current user's email address, sent to the hub as direct-submission subject
     * identity so moderation notifications can reach them.
     *
     * Stored dashboard-side only; never returned in any public hub API response.
     * Return null when unknown.
     *
     * @return string|null The email address, or null.
     */
    public function email(): ?string;
}
