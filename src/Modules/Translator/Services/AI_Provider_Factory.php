<?php
/**
 * AI_Provider_Factory for creating configured providers from settings.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

namespace PLLAT\Translator\Services;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Providers\AI_Provider;
use PLLAT\Translator\Services\AI_Provider_Registry;

/**
 * Factory for creating configured AI providers from settings.
 */
class AI_Provider_Factory {
    /**
     * Create a configured provider from settings.
     *
     * @param Settings_Service $settings The settings service.
     * @return AI_Provider The configured provider.
     * @throws \Exception If provider cannot be created or configured.
     */
    public static function create_from_settings( Settings_Service $settings ): AI_Provider {
        $provider   = self::resolve_provider( $settings->get_active_translation_api() );
        $active_api = $provider->get_provider_key();

        // Get credentials from settings.
        $api_key = $settings->get_translation_api_key( $active_api );
        if ( '' === $api_key ) {
            throw new \Exception(
                \sprintf( 'API key not configured for provider: %s', \esc_html( $active_api ) ),
            );
        }

        // The stored model when there is one, else the provider's default.
        $model = $settings->get_translation_model( $active_api );
        if ( '' === $model ) {
            $model = $provider->get_default_model();
        }

        // Get max output tokens from settings.
        $max_tokens = $settings->get_max_output_tokens();

        // Clone provider to avoid modifying the registry instance.
        $configured_provider = clone $provider;

        // Configure with credentials.
        $configured_provider->configure( $api_key, $model, $max_tokens );

        // Validate configuration.
        $validation_errors = $configured_provider->validate_configuration();
        if ( \count( $validation_errors ) > 0 ) {
            throw new \Exception(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- joined translatable validation messages (some contain quotes); stored and shown as plain text, esc_html would render entities.
                'Provider configuration invalid: ' . \implode( ', ', $validation_errors ),
            );
        }

        return $configured_provider;
    }

    /**
     * Check if a provider can be created from current settings.
     *
     * @param Settings_Service $settings The settings service.
     * @return bool True if provider can be created.
     */
    public static function can_create_from_settings( Settings_Service $settings ): bool {
        try {
            self::create_from_settings( $settings );
            return true;
        } catch ( \Exception ) {
            return false;
        }
    }

    /**
     * Get validation errors for current settings without throwing exceptions.
     *
     * @param Settings_Service $settings The settings service.
     * @return array<string> Array of validation error messages.
     */
    public static function get_validation_errors( Settings_Service $settings ): array {
        $errors = array();

        try {
            $provider   = self::resolve_provider( $settings->get_active_translation_api() );
            $active_api = $provider->get_provider_key();

            $api_key = $settings->get_translation_api_key( $active_api );
            if ( '' === $api_key ) {
                $errors[] = "API key not configured for {$provider->get_display_name()}";
            }

            $model = $settings->get_translation_model( $active_api );
            if ( '' === $model ) {
                $model = $provider->get_default_model();
            }

            // Providers that take a free-form model ID (OpenRouter routes
            // to hundreds of upstream models) expose an empty
            // get_available_models(); the membership check would reject
            // every valid model. Skip it for them — model validity is the
            // live validate_configuration()'s concern. See support ticket
            // TS-97853308 (#363): «Invalid model 'anthropic/claude-sonnet-4.5'».
            $is_unknown_model = ! \array_key_exists( $model, $provider->get_available_models() );
            if ( $is_unknown_model && ! $provider->supports_custom_model() ) {
                $errors[] = "Invalid model '{$model}' for {$provider->get_display_name()}";
            }

            // If we have minimum config, test provider validation.
            if ( '' !== $api_key ) {
                $test_provider = clone $provider;
                $test_provider->configure( $api_key, $model );
                $errors = \array_merge( $errors, $test_provider->validate_configuration() );
            }
        } catch ( \Exception $e ) {
            $errors[] = 'Configuration error: ' . $e->getMessage();
        }

        return $errors;
    }

    /**
     * Create a provider for testing purposes with custom credentials.
     *
     * @param string $provider_key The provider key.
     * @param string $api_key      The API key.
     * @param string $model        The model.
     * @return AI_Provider The configured provider.
     * @throws \Exception If provider cannot be created.
     */
    public static function create_for_testing( string $provider_key, string $api_key, string $model ): AI_Provider {
        $provider = AI_Provider_Registry::get_provider( $provider_key );
        if ( null === $provider ) {
            throw new \Exception( \sprintf( 'AI Provider not found: %s', \esc_html( $provider_key ) ) );
        }

        $configured_provider = clone $provider;
        $configured_provider->configure( $api_key, $model );

        return $configured_provider;
    }

    /**
     * The registered provider for a stored provider key.
     *
     * A key no registered provider answers to (a provider another module
     * registers, a stale option) resolves to the first registered provider
     * so the settings stay usable; the settings sanitizer stores that key
     * on the next save. The settings page applies the same resolution
     * (AI_Provider_Registry::resolve_provider()) without the log line.
     *
     * @param string $key The stored provider key.
     * @return AI_Provider The provider to use.
     * @throws \Exception When no provider is registered at all.
     */
    private static function resolve_provider( string $key ): AI_Provider {
        $provider = AI_Provider_Registry::resolve_provider( $key );
        if ( null === $provider ) {
            throw new \Exception( 'No AI provider is registered' );
        }

        if ( $provider->get_provider_key() !== $key ) {
            \do_action(
                'pllat_log_warning',
                \sprintf( 'Provider "%s" is not registered, using "%s"', $key, $provider->get_provider_key() ),
            );
        }

        return $provider;
    }
}
