<?php
/**
 * AI_Provider_Registry for managing available AI providers.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Providers\AI_Provider;

/**
 * Registry for managing AI providers with filter hook support.
 */
class AI_Provider_Registry {
    /**
     * Registered providers.
     *
     * @var array<string,AI_Provider>
     */
    private static array $providers = array();

    /**
     * Whether the registry has been initialized.
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * Register an AI provider.
     *
     * @param AI_Provider $provider The provider to register.
     * @return void
     */
    public static function register_provider( AI_Provider $provider ): void {
        self::$providers[ $provider->get_provider_key() ] = $provider;
    }

    /**
     * Get a specific provider by key.
     *
     * @param string $key The provider key.
     * @return AI_Provider|null The provider or null if not found.
     */
    public static function get_provider( string $key ): ?AI_Provider {
        return self::$providers[ $key ] ?? null;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string,AI_Provider> Array of provider_key => provider.
     */
    public static function get_all_providers(): array {
        return self::$providers;
    }

    /**
     * Get providers that have API keys configured.
     *
     * @param callable $has_api_key_callback Callback to check if provider has API key configured.
     * @return array<string,AI_Provider> Array of configured providers.
     */
    public static function get_configured_providers( callable $has_api_key_callback ): array {
        $configured = array();

        foreach ( self::$providers as $key => $provider ) {
            if ( ! $has_api_key_callback( $key ) ) {
                continue;
            }

            $configured[ $key ] = $provider;
        }

        return $configured;
    }

    /**
     * Check if registry has been initialized.
     *
     * @return bool True if initialized.
     */
    public static function is_initialized(): bool {
        return self::$initialized;
    }

    /**
     * Mark registry as initialized.
     *
     * @return void
     */
    public static function mark_initialized(): void {
        self::$initialized = true;
    }

    /**
     * Reset registry (for testing).
     *
     * @return void
     */
    public static function reset(): void {
        self::$providers   = array();
        self::$initialized = false;
    }

    /**
     * Count registered providers.
     *
     * @return int Number of registered providers.
     */
    public static function count(): int {
        return \count( self::$providers );
    }

    /**
     * Check if a provider is registered.
     *
     * @param string $key The provider key to check.
     * @return bool True if provider is registered.
     */
    public static function has_provider( string $key ): bool {
        return isset( self::$providers[ $key ] );
    }

    /**
     * Get all provider keys.
     *
     * @return array<string> Array of provider keys.
     */
    public static function get_provider_keys(): array {
        return \array_keys( self::$providers );
    }

    /**
     * Get all providers as key => display_name pairs for form options.
     *
     * @return array<string,string> Array of provider_key => display_name.
     */
    public static function get_providers_for_options(): array {
        $options = array();
        foreach ( self::$providers as $provider ) {
            $options[ $provider->get_provider_key() ] = $provider->get_display_name();
        }
        return $options;
    }

    /**
     * Get only available providers.
     *
     * @return array<string,AI_Provider> Array of available providers.
     */
    public static function get_available_providers(): array {
        $available = array();
        foreach ( self::$providers as $key => $provider ) {
            if ( $provider->is_available() ) {
                $available[ $key ] = $provider;
            }
        }
        return $available;
    }

    /**
     * Check if a specific provider is available.
     *
     * @param string $provider_key The provider key.
     * @return bool True if provider is available, false otherwise.
     */
    public static function is_provider_available( string $provider_key ): bool {
        $provider = self::get_provider( $provider_key );
        return $provider ? $provider->is_available() : false;
    }

    /**
     * Get providers for options with availability status.
     * Returns all providers but marks unavailable ones.
     *
     * @return array<string,array> Array of provider_key => array('name' => string, 'available' => bool).
     */
    public static function get_providers_for_options_with_availability(): array {
        $options = array();
        foreach ( self::$providers as $provider ) {
            $key = $provider->get_provider_key();
            $options[ $key ] = array(
                'name'      => $provider->get_display_name(),
                'available' => $provider->is_available(),
            );
        }
        return $options;
    }

    /**
     * Get the fallback provider (OpenAI).
     *
     * @return AI_Provider|null The fallback provider or null if not found.
     */
    public static function get_fallback_provider(): ?AI_Provider {
        // OpenAI is the primary fallback provider
        return self::get_provider( 'openai' );
    }

    /**
     * Get available models for a specific provider.
     *
     * @param string $provider_key The provider key.
     * @return array<string,string> Array of model_key => display_name.
     */
    public static function get_models_for_provider( string $provider_key ): array {
        $provider = self::get_provider( $provider_key );
        return $provider ? $provider->get_available_models() : array();
    }

    /**
     * Get default model for a specific provider.
     *
     * @param string $provider_key The provider key.
     * @return string The default model key.
     */
    public static function get_default_model_for_provider( string $provider_key ): string {
        $provider = self::get_provider( $provider_key );
        return $provider ? $provider->get_default_model() : 'gpt-4.1';
    }

    /**
     * Get all default models for each provider.
     *
     * @return array<string,string> Array of provider_key => default_model.
     */
    public static function get_all_default_models(): array {
        $defaults = array();
        foreach ( self::$providers as $provider ) {
            $defaults[ $provider->get_provider_key() ] = $provider->get_default_model();
        }
        return $defaults;
    }

    /**
     * Get API key description for a specific provider.
     *
     * @param string $provider_key The provider key.
     * @return string The API key description.
     */
    public static function get_api_key_description_for_provider( string $provider_key ): string {
        $provider = self::get_provider( $provider_key );
        return $provider ? $provider->get_api_key_description() : '';
    }

    /**
     * Check if a provider supports custom model input.
     *
     * @param string $provider_key The provider key.
     * @return bool True if provider supports custom model input, false otherwise.
     */
    public static function provider_supports_custom_model( string $provider_key ): bool {
        $provider = self::get_provider( $provider_key );
        return $provider ? $provider->supports_custom_model() : false;
    }
}
