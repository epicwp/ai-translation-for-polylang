<?php
/**
 * Upsell_Service class file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Upsell
 */

declare(strict_types=1);

namespace PLLAT\Upsell\Services;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns the upgrade URL every free-edition upsell links to.
 */
class Upsell_Service {
    private const BASE_URL = 'https://www.epicwpsolutions.com/upgrade/';

    /**
     * The upgrade page URL tagged with the placement the link sits in.
     *
     * @param string $placement Where the link is shown (utm_medium), e.g. `dashboard`.
     * @return string
     */
    public function upgrade_url( string $placement ): string {
        return self::BASE_URL . '?' . \http_build_query(
            array(
                'utm_campaign' => 'free',
                'utm_medium'   => $placement,
                'utm_source'   => 'plugin',
            ),
        );
    }
}
