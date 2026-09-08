<?php
/**
 * Settings_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Settings
 */

namespace PLLAT\Settings\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Handles setting storage, retrieval and validation for the plugin.
 */
class Settings_Service {
    /**
     * Lowest usable max-output-tokens value, and the default.
     *
     * Not a preference: below this the model runs out of room mid-translation
     * and the text is silently cut off. Multiple support cases came from sites
     * sitting at 100.
     */
    public const MIN_OUTPUT_TOKENS = 16000;

    /**
     * Cached options
     *
     * @var array<string,mixed>
     */
    private static array $data = array();

    /**
     * Get the active translation API
     *
     * @return string The active translation API (e.g., 'openai', 'claude', etc.)
     */
    public function get_active_translation_api(): string {
        return $this->get_option( 'pllat_translator_api', 'openai' );
    }

    /**
     * Set the active translation API
     *
     * @param string $api The API to set as active.
     * @return bool True on success, false on failure.
     */
    public function set_active_translation_api( string $api ): bool {
        return $this->update_option( 'pllat_translator_api', $api );
    }

    /**
     * Get the API key of the active translation API
     *
     * @return string The API key for the active translation API
     */
    public function get_active_translation_api_key(): string {
        return $this->get_translation_api_key( $this->get_active_translation_api() );
    }

    /**
     * Get the API key of a specific translation API
     *
     * @param string $provider The translation API (e.g., 'openai', 'claude', etc.).
     * @return string The API key for the specified API
     */
    public function get_translation_api_key( string $provider ): string {
        return $this->get_option( "pllat_{$provider}_api_key", '' );
    }

    /**
     * Set the API key for a specific translation API
     *
     * @param string $api The translation API.
     * @param string $key The API key.
     * @return bool True on success, false on failure.
     */
    public function set_translation_api_key( string $api, string $key ): bool {
        return $this->update_option( "pllat_{$api}_api_key", $key );
    }

    /**
     * Get the translation model of a specific translation API
     *
     * @param string|null $api Translation API. Defaults to the active API.
     * @return string The translation model for the specified API
     */
    public function get_translation_model( ?string $api = null ): string {
        $api ??= $this->get_active_translation_api();

        $provider      = \PLLAT\Translator\Services\AI_Provider_Registry::get_provider( $api );
        $default_model = $provider ? $provider->get_default_model() : 'gpt-4o';
        return $this->get_option( "pllat_{$api}_translation_model", $default_model );
    }

    /**
     * Set the translation model for a specific translation API
     *
     * @param string $api The translation API.
     * @param string $model The model name.
     * @return bool True on success, false on failure.
     */
    public function set_translation_model( string $api, string $model ): bool {
        return $this->update_option( "pllat_{$api}_translation_model", $model );
    }

    /**
     * Get the maximum number of output tokens for the AI translation
     *
     * @return int The maximum number of output tokens for the AI translation
     */
    public function get_max_output_tokens(): int {
        // Enforced on read, not just on save: the sites this protects already
        // have a too-low value stored and will never re-submit the form.
        return \max(
            self::MIN_OUTPUT_TOKENS,
            (int) $this->get_option( 'pllat_max_output_tokens', self::MIN_OUTPUT_TOKENS ),
        );
    }

    /**
     * Set the maximum number of output tokens
     *
     * @param int $tokens The maximum tokens.
     * @return bool True on success, false on failure.
     */
    public function set_max_output_tokens( int $tokens ): bool {
        return $this->update_option( 'pllat_max_output_tokens', $tokens );
    }

    /**
     * Get the website AI context (user-provided context to help AI with translation)
     *
     * @return string The context for the AI translation
     */
    public function get_website_ai_context(): string {
        return $this->get_option( 'pllat_website_ai_context', '' );
    }

    /**
     * Set the website AI context
     *
     * @param string $context The AI context.
     * @return bool True on success, false on failure.
     */
    public function set_website_ai_context( string $context ): bool {
        return $this->update_option( 'pllat_website_ai_context', $context );
    }

    /**
     * Check whether debug mode is enabled
     *
     * @return bool
     */
    public function is_debug_mode(): bool {
        return (bool) $this->get_option( 'pllat_debug_mode', false );
    }

    /**
     * Set debug mode
     *
     * @param bool $enabled Whether debug mode should be enabled.
     * @return bool True on success, false on failure.
     */
    public function set_debug_mode( bool $enabled ): bool {
        return $this->update_option( 'pllat_debug_mode', $enabled );
    }

    /**
     * Get translation processing mode.
     *
     * @return string 'byok' or 'credits'
     */
    public function get_translation_mode(): string {
        return $this->get_option( 'pllat_translation_mode', 'byok' );
    }

    /**
     * Set translation processing mode.
     *
     * @param string $mode 'byok' or 'credits'.
     * @return bool True on success, false on failure.
     */
    public function set_translation_mode( string $mode ): bool {
        if ( ! \in_array( $mode, array( 'byok', 'credits' ), true ) ) {
            return false;
        }
        return $this->update_option( 'pllat_translation_mode', $mode );
    }

    /**
     * Check if BYOK (Bring Your Own Key) mode is enabled.
     *
     * @return bool True if BYOK mode is enabled.
     */
    public function is_byok_mode(): bool {
        return 'byok' === $this->get_translation_mode();
    }

    /**
     * Check if Credits mode is enabled.
     *
     * @return bool True if Credits mode is enabled.
     */
    public function is_credits_mode(): bool {
        return 'credits' === $this->get_translation_mode();
    }

    /**
     * Check if BYOK mode can be used (has API key configured).
     *
     * @return bool True if user can use BYOK mode.
     */
    public function can_use_byok(): bool {
        $api_key = $this->get_active_translation_api_key();
        return ! empty( $api_key );
    }

    /**
     * Get custom IP whitelist for external processor.
     * Returns empty array if not configured (uses PLLAT_EXTERNAL_PROCESSOR_URL instead).
     *
     * @return array<string> Array of whitelisted IP addresses.
     */
    public function get_whitelisted_ips(): array {
        $ips = $this->get_option( 'pllat_whitelisted_ips', array() );
        return \is_array( $ips ) ? $ips : array();
    }

    /**
     * Set custom IP whitelist for external processor.
     *
     * @param array<string> $ips Array of IP addresses to whitelist.
     * @return bool True on success, false on failure.
     */
    public function set_whitelisted_ips( array $ips ): bool {
        // Filter valid IPs only.
        $valid_ips = \array_filter(
            $ips,
            static fn( $ip ) => \filter_var( $ip, FILTER_VALIDATE_IP ),
        );
        return $this->update_option( 'pllat_whitelisted_ips', $valid_ips );
    }

    /**
     * Get rate limit for external processor requests (requests per minute).
     *
     * @return int Rate limit (default: 100).
     */
    public function get_rate_limit(): int {
        return (int) $this->get_option( 'pllat_rate_limit', 100 );
    }

    /**
     * Set rate limit for external processor requests.
     *
     * @param int $limit Requests per minute (min: 1, max: 1000).
     * @return bool True on success, false on failure.
     */
    public function set_rate_limit( int $limit ): bool {
        // Clamp between 1 and 1000.
        $limit = \max( 1, \min( 1000, $limit ) );
        return $this->update_option( 'pllat_rate_limit', $limit );
    }

    /**
     * Validate API settings
     *
     * @return array{valid: bool, errors: string[]} Validation result
     */
    public function validate_api_settings(): array {
        $errors = array();
        $api    = $this->get_active_translation_api();

        if ( ! $api ) {
            $errors[] = \__( 'No translation API selected.', 'ai-translation-for-polylang' );
        }

        $api_key = $this->get_translation_api_key( $api );
        if ( ! $api_key ) {
            $errors[] = \sprintf(
                /* translators: %s: Provider name */
                \__( 'API key for %s is missing.', 'ai-translation-for-polylang' ),
                $api,
            );
        }

        $model = $this->get_translation_model( $api );
        if ( ! $model ) {
            $errors[] = \sprintf(
                /* translators: %s: Provider name */
                \__( 'Model for %s is not configured.', 'ai-translation-for-polylang' ),
                $api,
            );
        }

        return array(
            'errors' => $errors,
            'valid'  => 0 === \count( $errors ),
        );
    }

    /**
     * Get option value with caching
     *
     * @param string $key Option key.
     * @param mixed  $default Default value.
     * @return mixed Option value
     */
    private function get_option( string $key, $default = null ) {
        if ( ! isset( self::$data[ $key ] ) ) {
            self::$data[ $key ] = \get_option( $key, $default );
        }

        return self::$data[ $key ];
    }

    /**
     * Update option value and cache
     *
     * @param string $key Option key.
     * @param mixed  $value Option value.
     * @return bool True on success, false on failure.
     */
    private function update_option( string $key, $value ): bool {
        $result = \update_option( $key, $value );

        if ( $result ) {
            self::$data[ $key ] = $value;
        }

        return $result;
    }
}
