<?php
/**
 * Edition guard: only one edition of the plugin may load.
 *
 * The free edition (ai-translation-for-polylang) and the Pro edition
 * (polylang-automatic-ai-translation) share every constant, option, table,
 * Action Scheduler hook and REST namespace, so the second one has to step
 * aside with an admin notice instead of loading. Plain functions: both main
 * files require this file before their defines and before the autoloader
 * (the Jetpack autoloader must never see two PLLAT trees).
 *
 * @package Polylang AI Automatic Translation
 */

\defined( 'ABSPATH' ) || exit;

// Each edition ships its own copy of this file; declared conditionally so the
// second copy does not redeclare the functions (top-level functions are hoisted).
if ( ! \function_exists( 'pllat_edition_yields' ) ) {

    /**
     * Whether the given edition must step aside.
     *
     * WordPress loads active plugins in basename order, so the free folder
     * (ai-translation-for-polylang) always loads before the Pro folder. Pro wins:
     * free also steps aside while Pro is merely active but not loaded yet, Pro
     * only once another edition has defined PLLAT_EDITION (renamed folders,
     * mu-plugins). Reading the active list in both editions would make both
     * step aside when both are active. Pro is recognised by its main file,
     * whatever its folder is called (polylang-ai-automatic-translation in the
     * release zip, the repository name in a development checkout).
     *
     * @param  string             $edition        'free' or 'pro'.
     * @param  bool               $other_loaded   Whether PLLAT_EDITION is already defined.
     * @param  array<int, string> $active_plugins Active plugin basenames, network ones included.
     * @return bool
     */
    function pllat_edition_yields( string $edition, bool $other_loaded, array $active_plugins ): bool {
        if ( $other_loaded ) {
            return true;
        }

        return 'free' === $edition
            && array() !== \preg_grep( '#(^|/)polylang-ai-automatic-translation\.php$#', $active_plugins );
    }

    /**
     * Active plugin basenames of the site, network-activated ones included.
     *
     * @return array<int, string>
     */
    function pllat_active_plugin_basenames(): array {
        $active = (array) \get_option( 'active_plugins', array() );

        if ( \is_multisite() ) {
            $active = \array_merge(
                $active,
                \array_keys( (array) \get_site_option( 'active_sitewide_plugins', array() ) ),
            );
        }

        return \array_values( \array_filter( $active, 'is_string' ) );
    }

    /**
     * Registers the conflict notice and refuses activation for the edition that steps aside.
     *
     * @param  string $plugin_file Main file of the edition that steps aside.
     * @return void
     */
    function pllat_edition_step_aside( string $plugin_file ): void {
        \add_action( 'admin_notices', 'pllat_edition_conflict_notice' );
        \add_action( 'network_admin_notices', 'pllat_edition_conflict_notice' );
        \register_activation_hook( $plugin_file, 'pllat_edition_refuse_activation' );
    }

    /**
     * The conflict message in the edition's own text domain.
     *
     * @return string
     */
    function pllat_edition_conflict_message(): string {
        return \__(
            'Both the free and the Pro edition of AI Translation for Polylang are active. Deactivate one of them.',
            'ai-translation-for-polylang',
        );
    }

    /**
     * Prints the conflict notice.
     *
     * @return void
     */
    function pllat_edition_conflict_notice(): void {
        \printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            \esc_html( pllat_edition_conflict_message() ),
        );
    }

    /**
     * Stops the activation of the second edition.
     *
     * @return void
     */
    function pllat_edition_refuse_activation(): void {
        \wp_die( \esc_html( pllat_edition_conflict_message() ), '', array( 'back_link' => true ) );
    }
}
