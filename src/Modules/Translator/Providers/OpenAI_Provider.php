<?php
/**
 * OpenAI_Provider implementation.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Translator
 */

namespace PLLAT\Translator\Providers;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Translator\Services\AI_Client;

/**
 * OpenAI provider implementation.
 */
class OpenAI_Provider implements AI_Provider {
    /**
     * Configured API key.
     *
     * @var string|null
     */
    private ?string $api_key = null;

    /**
     * Configured model.
     *
     * @var string|null
     */
    private ?string $model = null;

    /**
     * Configured AI client.
     *
     * @var AI_Client|null
     */
    private ?AI_Client $client = null;

    /**
     * Maximum output tokens.
     *
     * @var int
     */
    private int $max_tokens = 16000;

    /**
     * Get the unique provider key/identifier.
     *
     * @return string The provider key.
     */
    public function get_provider_key(): string {
        return 'openai';
    }

    /**
     * Get the human-readable display name.
     *
     * @return string The display name.
     */
    public function get_display_name(): string {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * Get all available models for this provider.
     *
     * Labels carry input/output price per 1M tokens so the dropdown can be read
     * as the cost decision it usually is. Standard-tier list prices, correct as
     * of 2026-07-17 — they need an occasional look, since OpenAI does move them.
     *
     * @return array<string,string> Array of model_key => display_name.
     */
    public function get_available_models(): array {
        return array(
            'gpt-5.4-nano'  => 'GPT-5.4 Nano — $0.20 / $1.25 per 1M',
            'gpt-5.4-mini'  => 'GPT-5.4 Mini — $0.75 / $4.50 per 1M',
            'gpt-5.4'       => 'GPT-5.4 — $2.50 / $15.00 per 1M',
            'gpt-5.6-luna'  => 'GPT-5.6 Luna — $1.00 / $6.00 per 1M',
            'gpt-5.6-terra' => 'GPT-5.6 Terra — $2.50 / $15.00 per 1M',
            'gpt-5.2'       => 'GPT-5.2 — $1.75 / $14.00 per 1M',
            'gpt-5.1'       => 'GPT-5.1 — $1.25 / $10.00 per 1M',
            'gpt-5'         => 'GPT-5 — $1.25 / $10.00 per 1M',
            'gpt-4.1'       => 'GPT-4.1 — $2.00 / $8.00 per 1M',
            'gpt-4.1-mini'  => 'GPT-4.1 Mini — $0.40 / $1.60 per 1M',
            'gpt-4o'        => 'GPT-4o — $2.50 / $10.00 per 1M',
            'gpt-4o-mini'   => 'GPT-4o Mini — $0.15 / $0.60 per 1M',
        );
    }

    /**
     * Get the default model for this provider.
     *
     * Cheapest credible translation model on any provider ($0.20/$1.25 per 1M
     * vs gpt-4.1's $2.00/$8.00), and it does not reason by default. Only
     * applies where no model was saved; existing gpt-4.1 selections stand.
     *
     * @return string The default model key.
     */
    public function get_default_model(): string {
        return 'gpt-5.4-nano';
    }

    /**
     * Get model-specific API parameters.
     *
     * Translation needs no reasoning, and reasoning costs latency as much as
     * tokens, so every reasoning model runs at its floor. Verified live: all of
     * these report 0 reasoning tokens at "none" and still translate correctly.
     *
     * GPT-5 predates "none" and bottoms out at "minimal". GPT-5.1/5.2/5.4
     * default to "none" already; GPT-5.5/5.6 accept it but default to "medium",
     * so it has to be sent explicitly. The gpt-4.1 and gpt-4o families never
     * reason and take no parameter at all.
     *
     * Reasoning models also reject max_tokens in favour of max_completion_tokens.
     *
     * @param string $model The model key.
     * @return array Additional API parameters for this model.
     */
    public function get_model_params( string $model ): array {
        $none = array(
            'reasoning_effort' => 'none',
            'max_tokens_param' => 'max_completion_tokens',
        );

        $params = array(
            'gpt-5'         => array(
                'reasoning_effort' => 'minimal',
                'max_tokens_param' => 'max_completion_tokens',
            ),
            'gpt-5.1'       => $none,
            'gpt-5.2'       => $none,
            'gpt-5.4'       => $none,
            'gpt-5.4-mini'  => $none,
            'gpt-5.4-nano'  => $none,
            'gpt-5.6-luna'  => $none,
            'gpt-5.6-terra' => $none,
        );

        return $params[ $model ] ?? array();
    }

    /**
     * Get the base URL for API requests.
     *
     * @return string The base URL.
     */
    public function get_base_url(): string {
        return 'https://api.openai.com/v1';
    }

    /**
     * Get the URL where users can obtain an API key.
     *
     * @return string The API key signup/management URL.
     */
    public function get_api_key_url(): string {
        return 'https://platform.openai.com/api-keys';
    }

    /**
     * Get a description for the API key field.
     *
     * @return string The description text.
     */
    public function get_api_key_description(): string {
        return \__(
            'Get your API key from OpenAI Platform (https://platform.openai.com/api-keys)',
            'ai-translation-for-polylang',
        );
    }

    /**
     * Check if the provider is available for selection.
     *
     * @return bool True if provider can be selected, false otherwise.
     */
    public function is_available(): bool {
        // OpenAI is always available (primary provider for beta release)
        return true;
    }

    /**
     * Check if the provider supports function/tool calling.
     *
     * @return bool True if provider supports tool calling, false otherwise.
     */
    public function supports_tool_calling(): bool {
        return true;
    }

    /**
     * Check if the provider supports custom model input.
     *
     * @return bool False - OpenAI uses a predefined model list.
     */
    public function supports_custom_model(): bool {
        return false;
    }

    /**
     * Configure the provider with credentials and model.
     *
     * @param string $api_key    The API key.
     * @param string $model      The model to use.
     * @param int    $max_tokens Maximum output tokens (default: 16000).
     * @return void
     */
    public function configure( string $api_key, string $model, int $max_tokens = 16000 ): void {
        $this->api_key    = $api_key;
        $this->model      = $model;
        $this->max_tokens = $max_tokens;

        // Reset client so it gets recreated with new config.
        $this->client = null;
    }

    /**
     * Check if the provider is properly configured with required credentials.
     *
     * @return bool True if configured, false otherwise.
     */
    public function is_configured(): bool {
        return null !== $this->api_key && '' !== $this->api_key && null !== $this->model && '' !== $this->model;
    }

    /**
     * Get provider-specific request headers.
     *
     * OpenAI uses standard Bearer token authentication, which is already
     * handled by AI_Client's default headers. No additional headers needed.
     *
     * @return array Additional headers for API requests.
     */
    protected function get_request_headers(): array {
        return array();
    }

    /**
     * Get the configured AI client for API requests.
     *
     * @return AI_Client The configured HTTP client.
     * @throws \Exception If provider is not configured.
     */
    public function get_client(): AI_Client {
        if ( ! $this->is_configured() ) {
            throw new \Exception( 'OpenAI provider is not configured. Please set API key and model.' );
        }

        if ( null === $this->client ) {
            $this->client = new AI_Client(
                $this->api_key,
                $this->get_base_url(),
                $this->model,
                $this->get_request_headers(),
                $this->get_model_params( $this->model ),
                $this->max_tokens,
            );
        }

        return $this->client;
    }

    /**
     * Get the currently configured model.
     *
     * @return string The model identifier.
     * @throws \Exception If provider is not configured.
     */
    public function get_model(): string {
        if ( ! $this->is_configured() ) {
            throw new \Exception( 'OpenAI provider is not configured. Please set API key and model.' );
        }

        return $this->model;
    }

    /**
     * Get the configured API key.
     *
     * @return string The API key.
     * @throws \Exception If provider is not configured.
     */
    public function get_api_key(): string {
        if ( ! $this->is_configured() ) {
            throw new \Exception( 'OpenAI provider is not configured. Please set API key and model.' );
        }

        return $this->api_key;
    }

    /**
     * Validate the current configuration and return any errors.
     *
     * @return array<string> Array of validation error messages.
     */
    public function validate_configuration(): array {
        $errors = array();

        if ( null === $this->api_key || '' === $this->api_key ) {
            $errors[] = \__( 'OpenAI API key is required.', 'ai-translation-for-polylang' );
        }

        if ( null === $this->model || '' === $this->model ) {
            $errors[] = \__( 'OpenAI model selection is required.', 'ai-translation-for-polylang' );
        } elseif ( ! \array_key_exists( $this->model, $this->get_available_models() ) ) {
            $errors[] = \sprintf(
                /* translators: %s: Model name */
                \__( 'Invalid OpenAI model: %s', 'ai-translation-for-polylang' ),
                $this->model,
            );
        }

        // Basic API key format validation.
        if ( null !== $this->api_key && '' !== $this->api_key && ! \str_starts_with( $this->api_key, 'sk-' ) ) {
            $errors[] = \__( 'OpenAI API key should start with "sk-".', 'ai-translation-for-polylang' );
        }

        return $errors;
    }
}
