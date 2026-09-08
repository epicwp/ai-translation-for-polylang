<?php
declare(strict_types=1);

namespace PLLAT\Integrations\Core\Interfaces;

\defined( 'ABSPATH' ) || exit;

/**
 * Base interface for all integrations (page builders, field plugins, SEO tools, etc.).
 *
 * This contract defines the common behavior all integrations must implement.
 * Specific integration types extend this with additional requirements.
 */
interface Integration {
    /**
     * Get the unique integration key.
     * Used for identification and registry lookups.
     *
     * @return string Integration key (e.g., 'elementor', 'bricks', 'acf')
     */
    public function get_key(): string;

    /**
     * Get the human-readable integration name.
     *
     * @return string Display name (e.g., 'Elementor', 'Bricks Builder')
     */
    public function get_name(): string;

    /**
     * Get the plugin file path for activation checks.
     *
     * @return string Plugin file (e.g., 'elementor/elementor.php')
     */
    public function get_plugin_file(): string;

    /**
     * Get the integration type.
     *
     * @return string Type identifier ('page_builder', 'field_plugin', 'seo', etc.)
     */
    public function get_type(): string;

    /**
     * Check if the integration's plugin is active.
     *
     * @return bool True if plugin is active
     */
    public function is_active(): bool;

    /**
     * Check if this integration handles a specific post.
     *
     * @param int $post_id Post ID to check.
     * @return bool True if this integration manages the post
     */
    public function handles_post( int $post_id ): bool;

    /**
     * Check if this integration handles a specific term.
     *
     * @param int $term_id Term ID to check.
     * @return bool True if this integration manages the term
     */
    public function handles_term( int $term_id ): bool;
}
