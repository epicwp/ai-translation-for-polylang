<?php
declare(strict_types=1);

namespace PLLAT\Integrations\Core\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Integrations\Core\Interfaces\Integration;

/**
 * Central registry for all plugin integrations.
 *
 * Manages registration and lookup of integrations.
 * Integrations are registered during their module initialization.
 */
class Integration_Registry {
    /**
     * Registered integrations.
     *
     * @var array<string, Integration>
     */
    private array $integrations = array();

    /**
     * Register an integration.
     *
     * @param Integration $integration Integration to register.
     * @return void
     */
    public function register( Integration $integration ): void {
        $this->integrations[ $integration->get_key() ] = $integration;
    }

    /**
     * Get an integration by key.
     *
     * @param string $key Integration key.
     * @return Integration|null Integration instance or null if not found
     */
    public function get( string $key ): ?Integration {
        return $this->integrations[ $key ] ?? null;
    }

    /**
     * Check if an integration is registered.
     *
     * @param string $key Integration key.
     * @return bool True if registered
     */
    public function has( string $key ): bool {
        return isset( $this->integrations[ $key ] );
    }

    /**
     * Get all registered integrations.
     *
     * @return array<Integration> Array of integration instances
     */
    public function get_all(): array {
        return \array_values( $this->integrations );
    }

    /**
     * Get all active integrations (plugin must be active).
     *
     * @return array<Integration> Array of active integration instances
     */
    public function get_all_active(): array {
        return \array_filter(
            $this->get_all(),
            static fn( Integration $integration ) => $integration->is_active(),
        );
    }

    /**
     * Get integrations by type.
     *
     * @param string $type Integration type (e.g., 'page_builder', 'field_plugin').
     * @return array<Integration> Array of matching integration instances
     */
    public function get_by_type( string $type ): array {
        return \array_filter(
            $this->get_all(),
            static fn( Integration $integration ) => $integration->get_type() === $type,
        );
    }
}
