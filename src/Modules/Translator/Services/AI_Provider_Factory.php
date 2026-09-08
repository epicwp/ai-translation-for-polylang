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
        // OpenAI, Claude, Gemini, OpenRouter.
        $active_api     = $settings->get_active_translation_api();
        $original_api   = $active_api;
        $using_fallback = false;

        // Get provider from registry.
        $provider = AI_Provider_Registry::get_provider( $active_api );
        if ( null === $provider ) {
            throw new \Exception( \sprintf( 'AI Provider not found: %s', \esc_html( $active_api ) ) );
        }

        // Check if selected provider is available, fallback to OpenAI if not.
        if ( ! $provider->is_available() ) {
            $fallback_provider = AI_Provider_Registry::get_fallback_provider();
            if ( null === $fallback_provider ) {
                throw new \Exception( 'No available AI provider found' );
            }

            // Log the fallback for debugging.
            \do_action(
                'pllat_log_warning',
                \sprintf(
                    'Provider "%s" is not available, falling back to OpenAI',
                    $original_api,
                ),
            );

            $provider       = $fallback_provider;
            $active_api     = 'openai';
            $using_fallback = true;

            // Optionally update settings to reflect the fallback (temporary).
            // This ensures UI consistency if settings are reloaded.
            // NOTE: We're not permanently changing the user's selection.
        }

        // Get credentials from settings.
        $api_key = $settings->get_translation_api_key( $active_api );
        if ( null === $api_key || '' === $api_key ) {
            // If we're using fallback and no API key is configured, provide helpful error.
            if ( $using_fallback ) {
                throw new \Exception(
                    \sprintf(
                        'Provider "%s" is not available yet (beta/license restriction). Please configure OpenAI API key to use translations.',
                        \esc_html( $original_api ),
                    ),
                );
            }
            throw new \Exception(
                \sprintf( 'API key not configured for provider: %s', \esc_html( $active_api ) ),
            );
        }

        $model = $settings->get_translation_model( $active_api );
        if ( null === $model || '' === $model ) {
            // Use default model if not configured (especially for fallback).
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
            $active_api       = $settings->get_active_translation_api();
            $check_api        = $active_api;
            $is_using_fallback = false;

            // Check if provider exists.
            $provider = AI_Provider_Registry::get_provider( $active_api );
            if ( null === $provider ) {
                $errors[] = "Unknown AI provider: {$active_api}";
                return $errors;
            }

            // Check if provider is available.
            if ( ! $provider->is_available() ) {
                // Check if fallback provider (OpenAI) is configured.
                $fallback_provider = AI_Provider_Registry::get_fallback_provider();
                if ( null !== $fallback_provider ) {
                    $provider          = $fallback_provider;
                    $check_api         = 'openai';
                    $is_using_fallback = true;

                    // Add info message about fallback.
                    $errors[] = \sprintf(
                        'Note: %s is not available yet (beta/license restriction). Will use OpenAI as fallback.',
                        AI_Provider_Registry::get_provider( $active_api )->get_display_name(),
                    );
                } else {
                    $errors[] = "No available AI providers found";
                    return $errors;
                }
            }

            // Check API key for the provider we'll actually use.
            $api_key = $settings->get_translation_api_key( $check_api );
            if ( null === $api_key || '' === $api_key ) {
                $errors[] = "API key not configured for {$provider->get_display_name()}";
            }

            // Check model.
            $model = $settings->get_translation_model( $check_api );
            if ( null === $model || '' === $model ) {
                // Don't report as error if we can use default model.
                if ( ! $is_using_fallback ) {
                    $errors[] = "Model not configured for {$provider->get_display_name()}";
                }
            } else {
                // Providers that take a free-form model ID (OpenRouter routes
                // to hundreds of upstream models) expose an empty
                // get_available_models(); the membership check would reject
                // every valid model. Skip it for them — model validity is the
                // live validate_configuration()'s concern. See support ticket
                // TS-97853308 (#363): «Invalid model 'anthropic/claude-sonnet-4.5'».
                $available_models = $provider->get_available_models();
                $is_unknown_model = ! \array_key_exists( $model, $available_models );
                if ( $is_unknown_model && ! $provider->supports_custom_model() ) {
                    $errors[] = "Invalid model '{$model}' for {$provider->get_display_name()}";
                }
            }

            // If we have minimum config, test provider validation.
            if ( null !== $api_key && '' !== $api_key ) {
                // Use default model if not configured.
                if ( null === $model || '' === $model ) {
                    $model = $provider->get_default_model();
                }

                $test_provider = clone $provider;
                $test_provider->configure( $api_key, $model );
                $provider_errors = $test_provider->validate_configuration();
                $errors          = \array_merge( $errors, $provider_errors );
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
}
