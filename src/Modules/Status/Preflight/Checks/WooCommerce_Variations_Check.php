<?php
/**
 * WooCommerce_Variations_Check file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight\Checks
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight\Checks;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Status\Preflight\Preflight_Check;
use PLLAT\Status\Preflight\Preflight_Check_Result;
use PLLAT\Status\Preflight\Preflight_Level;
use PLLAT\Status\Preflight\Preflight_Scope;

/**
 * Warn when a single-translate request targets a WooCommerce variable
 * product on a site that doesn't have Polylang for WooCommerce active.
 *
 * Background: Polylang Free's copy_post pathway emits the
 * `pll_created_sync_post` action — PLLWC listens on that hook and clones
 * the variations under the new parent. Without PLLWC the parent gets
 * translated but the variations are not cloned, leaving an empty
 * "variable product without variations" in the target language. From the
 * UI everything looks fine; the failure is silent until a customer hits
 * an unbuyable product on the front-end.
 *
 * Resolution path for the user is to install Polylang for WooCommerce.
 * Surface the recommendation as a Warn (not Fail) so power-users on
 * non-WC stacks aren't blocked.
 */
class WooCommerce_Variations_Check implements Preflight_Check {
    private const PURCHASE_URL = 'https://polylang.pro/pricing/polylang-for-woocommerce/';

    public function get_name(): string {
        return 'woocommerce_variations';
    }

    public function applies_to( Preflight_Scope $scope ): bool {
        return Preflight_Scope::Single === $scope;
    }

    public function run( Preflight_Scope $scope, array $context = array() ): Preflight_Check_Result {
        $post_id = (int) ( $context['post_id'] ?? 0 );
        if ( 0 === $post_id ) {
            // Only meaningful for post (specifically WC product) translation;
            // terms and other contexts skip this check.
            return $this->pass( 'Not a single-post context.' );
        }

        if ( ! \class_exists( 'WooCommerce' ) ) {
            return $this->pass( 'WooCommerce inactive — variation sync not relevant.' );
        }

        $post = \get_post( $post_id );
        if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
            return $this->pass( 'Source is not a WooCommerce product.' );
        }

        if ( ! $this->is_variable_product( $post_id ) ) {
            return $this->pass( 'Product has no variations.' );
        }

        if ( \function_exists( 'PLLWC' ) ) {
            return $this->pass( 'Polylang for WooCommerce active — variations will be cloned.' );
        }

        // Variable product + WC active + PLLWC inactive: variations will not
        // be cloned into the target language.
        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Warn,
            'Variable product detected, but Polylang for WooCommerce is not active. Variations will not be cloned to the target language.',
            'Install and activate Polylang for WooCommerce so variation sync runs automatically when this product is translated.',
            self::PURCHASE_URL,
        );
    }

    /**
     * A product is "variable" when at least one published variation has it
     * as parent.
     *
     * @param int $product_id Source product post ID.
     */
    private function is_variable_product( int $product_id ): bool {
        global $wpdb;
        return 1 === (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->posts}
				 WHERE post_parent = %d
				   AND post_type = 'product_variation'
				   AND post_status IN ('publish','private')
				 LIMIT 1",
                $product_id,
            ),
        );
    }

    /**
     * Build a Pass-level result with the given human-readable reason.
     *
     * @param string $message Reason the check did not apply / passed.
     */
    private function pass( string $message ): Preflight_Check_Result {
        return new Preflight_Check_Result(
            $this->get_name(),
            Preflight_Level::Pass,
            $message,
            null,
        );
    }
}
