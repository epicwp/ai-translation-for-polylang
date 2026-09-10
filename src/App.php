<?php
/**
 * App class file.
 *
 * @package Polylang AI Automatic Translation
 */

namespace PLLAT;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Activity\Activity_Module;
use PLLAT\Admin\Admin_Module;
use PLLAT\Bulk\Bulk_Module;
use PLLAT\Cleanup\Cleanup_Module;
use PLLAT\CLI\CLI_Module;
use PLLAT\Common\Logging\Logging_Module;
use PLLAT\Common\Services\Requirements;
use PLLAT\Content\Content_Module;
use PLLAT\Core\Core_Module;
use PLLAT\Debug\Debug_Module;
use PLLAT\Integrations\Integrations_Module;
use PLLAT\Internal_Links\Internal_Links_Module;
use PLLAT\License\License_Module;
use PLLAT\License\Services\License_Service;
use PLLAT\Logs\Logs_Module;
use PLLAT\Meta_Fields\Meta_Fields_Module;
use PLLAT\Pro_Providers\Pro_Providers_Module;
use PLLAT\Pro_Settings\Pro_Settings_Module;
use PLLAT\SEO\SEO_Module;
use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Settings\Settings_Module;
use PLLAT\Single_Translator\Single_Translator_Module;
use PLLAT\Status\Status_Module;
use PLLAT\Strings\Strings_Module;
use PLLAT\Support_Access\Support_Access_Module;
use PLLAT\Translation_Index\Translation_Index_Module;
use PLLAT\Translator\Services\AI_Provider_Factory;
use PLLAT\Translator\Translator_Module;
use PLLAT\Upsell\Upsell_Module;
use Psr\Container\ContainerInterface;
use XWP\DI\Decorators\Module;
use XWP\DI\Interfaces\On_Initialize;

/**
 * Main application class.
 */
#[Module(
    hook: 'pll_init',
    priority: 0,
    imports: array(
        Logging_Module::class,
        Activity_Module::class,
        Status_Module::class,
        Core_Module::class,
        Settings_Module::class,
        Upsell_Module::class,
        Cleanup_Module::class,
        Translation_Index_Module::class,
        Logs_Module::class,
        Debug_Module::class,
        Translator_Module::class,
        Content_Module::class,
        Admin_Module::class,
        Single_Translator_Module::class,
        Integrations_Module::class,
        CLI_Module::class,
    ),
)]
class App implements On_Initialize {
    /**
     * Can we initialize the module.
     *
     * @return bool
     */
    public static function can_initialize(): bool {
        if ( ! Requirements::check() ) {
            \add_action( 'admin_notices', array( Requirements::class, 'admin_notices' ) );
            return false;
        }

        if ( \pllat_is_pll_deactivating() ) {
            return false;
        }

        if ( ! \pll_default_language() ) {
            \add_action( 'admin_notices', array( self::class, 'no_languages_notice' ) );
            return false;
        }

        return true;
    }

    /**
     * Admin notice shown when Polylang is active but has no languages configured.
     *
     * Without a default language, PLLAT cannot initialize. Without this notice,
     * the plugin would activate silently with no UI surface, leaving the user
     * stuck. We point them at Polylang's languages page so they can add one.
     */
    public static function no_languages_notice(): void {
        $url = \admin_url( 'admin.php?page=mlang' );
        \printf(
            '<div class="notice notice-warning"><p><strong>%s</strong>: %s <a href="%s">%s</a></p></div>',
            \esc_html__( 'Polylang AI Translation', 'ai-translation-for-polylang' ),
            \esc_html__(
                'No Polylang languages are configured yet. AI translation will activate as soon as you add at least one language.',
                'ai-translation-for-polylang',
            ),
            \esc_url( $url ),
            \esc_html__( 'Configure Polylang languages', 'ai-translation-for-polylang' ),
        );
    }

    /**
     * Get the module configuration.
     *
     * @return array<string,mixed>
     */
    public static function configure(): array {
        return array(
            'app.name'                  => \DI\factory(
                static fn() => \__( 'Polylang AI Automatic Translation', 'ai-translation-for-polylang' ),
            ),
            'license.valid'             => \DI\factory(
                static fn( ContainerInterface $container ) => 'pro' === PLLAT_EDITION
                    && $container->get( License_Service::class )->has_valid_license(),
            ),
            'single_translator.enabled' => \DI\factory(
                static fn( ContainerInterface $container ) => 'pro' !== PLLAT_EDITION
                    || $container->get( 'license.valid' ),
            ),
            'translator.configured'     => \DI\factory(
                static fn( Settings_Service $settings_service ) => AI_Provider_Factory::can_create_from_settings(
                    $settings_service,
                ),
            ),
        );
    }

    /**
     * Register error handler for this plugin.
     */
    public function on_initialize(): void {
        /**
         * Fires when the plugin is initialized.
         *
         * @param string $default_language The default language.
         */
        \do_action( 'pllat_loaded', \pll_default_language() );
    }
}
