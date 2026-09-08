<?php
/**
 * Provider_Validation_Gateway file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

use PLLAT\Settings\Services\Settings_Service;
use PLLAT\Translator\Services\AI_Provider_Factory;

/**
 * Injectable wrapper around the static AI_Provider_Factory::get_validation_errors
 * call, so checks can mock provider validation in unit tests.
 */
class Provider_Validation_Gateway {

    public function __construct( private Settings_Service $settings ) {}

    /**
     * @return array<string>
     */
    public function get_validation_errors(): array {
        return AI_Provider_Factory::get_validation_errors( $this->settings );
    }

    public function get_active_provider(): string {
        return (string) $this->settings->get_active_translation_api();
    }
}
