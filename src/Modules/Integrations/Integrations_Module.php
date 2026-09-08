<?php
declare(strict_types=1);

namespace PLLAT\Integrations;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Integrations\Core\Services\Integration_Registry;
use PLLAT\Integrations\Integrations\ACF\ACF_Module;
use PLLAT\Integrations\Integrations\Bricks\Bricks_Module;
use PLLAT\Integrations\Integrations\Elementor\Elementor_Module;
use PLLAT\Integrations\Integrations\Markup\Markup_Module;
use PLLAT\Integrations\Integrations\WooCommerce\WooCommerce_Module;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\Can_Initialize;

/**
 * Base Integrations module.
 *
 * Provides the registry and core services for plugin integrations.
 * Individual integrations are separate sub-modules with conditional loading.
 *
 * Sub-modules (Markup, Elementor, ACF, etc.) are imported and will only load
 * if their can_initialize() check passes (plugin/theme active check).
 *
 * Markup_Module is shared by every edition. The Elementor, Bricks, ACF and
 * WooCommerce sub-modules are pro-only: their imports sit between the
 * pro-only fence markers (the same markers App.php uses) so the free build
 * can strip them together with their directories. The `use` lines stay
 * outside the fence (an alias for an absent class is harmless and keeps the
 * block sorted). Nothing outside those directories references them; they
 * plug in through filters only.
 *
 * Note: Markup_Module loads at priority 8 (before the page builders' 10) so
 * that Elementor and Bricks can override post_content handling for the posts
 * they manage.
 */
#[Module(
    hook: 'init',
    priority: 5,
    imports: array(
        Markup_Module::class,
    ),
    services: array(
        Integration_Registry::class,
    ),
)]
class Integrations_Module implements Can_Initialize {
    /**
     * Check if the module can be initialized.
     * Only initialize if the Translator module can be initialized (has proper settings).
     *
     * @return bool
     */
    public static function can_initialize(): bool {
        return true;
    }

    /**
     * Module configuration.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array();
    }
}
