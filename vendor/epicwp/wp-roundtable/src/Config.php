<?php
declare(strict_types=1);

namespace EpicWP\Roundtable;

/** Immutable SDK configuration, built once by the consumer at bootstrap. */
final class Config {
    /**
     * Creates the configuration.
     *
     * @param string      $projectApiKey  Per-project (multi-tenant) bearer key; stays server-side.
     * @param Consumer    $consumer       The plugin's licensing/identity boundary.
     * @param string      $agentName      Display name for the agent (local fallback; the hub's
     *                                    /project/config is preferred when reachable).
     * @param string|null $agentAvatarUrl Optional avatar URL for the agent.
     * @param int         $timeoutSeconds HTTP timeout for a buffered turn.
     * @param string|null $hubBaseUrl     Dev/staging override ONLY; null uses the baked-in SaaS URL.
     * @param string      $projectName    The consuming product's display name; '' hides it in the UI.
     * @param bool        $beta           Whether the UI shows a "Beta" label next to the agent name
     *                                    and the Community heading.
     * @param bool        $enableChat     Whether the AI chat is available. False (the default) hides
     *                                    the chat UI and unregisters the chat/message REST routes.
     * @param string|null $attribution    Optional one-line attribution shown under the page header
     *                                    (e.g. "Community powered by Acme"). Null (the default) hides
     *                                    it. The SDK never supplies its own text — the host plugin does.
     */
    public function __construct(
        public readonly string $projectApiKey,
        public readonly Consumer $consumer,
        public readonly string $agentName = 'Clara',
        public readonly ?string $agentAvatarUrl = null,
        public readonly int $timeoutSeconds = 30,
        public readonly ?string $hubBaseUrl = null,
        public readonly string $projectName = '',
        public readonly bool $beta = false,
        public readonly bool $enableChat = false,
        public readonly ?string $attribution = null,
    ) {
    }
}
