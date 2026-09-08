<?php //phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
/**
 * Compatibility functions and helpers
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Common
 */

\defined( 'ABSPATH' ) || exit;

/**
 * Check if Polylang is deactivating
 *
 * @return bool
 */
function pllat_is_pll_deactivating(): bool {
    $pll = array( 'polylang/polylang.php', 'polylang-pro/polylang.php' );
    $def = array( 'action' => '', 'plugin' => '' );
    $get = xwp_get_arr( $def );

    // @phpstan-ignore-next-line
    return 'deactivate' === $get['action'] && in_array( $get['plugin'], $pll, true );
}

/**
 * Is WooCommerce active?
 *
 * @return bool
 */
function pllat_has_wc(): bool {
    return did_action( 'woocommerce_loaded' ) || function_exists( 'WC' );
}

/**
 * Get external processor URL from constant or filter.
 *
 * @return string External processor URL.
 */
function pllat_get_external_processor_url(): string {
    $default_url = \defined( 'PLLAT_EXTERNAL_PROCESSOR_URL' )
        ? PLLAT_EXTERNAL_PROCESSOR_URL
        : 'http://localhost:3000';

    /**
     * Filter the external processor API base URL.
     *
     * @param string $base_url Default base URL from PLLAT_EXTERNAL_PROCESSOR_URL constant.
     */
    return \apply_filters( 'pllat_external_processor_url', $default_url );
}
