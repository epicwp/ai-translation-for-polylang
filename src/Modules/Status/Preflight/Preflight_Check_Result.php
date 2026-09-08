<?php
/**
 * Preflight_Check_Result value object.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Result of a single preflight check.
 *
 * `help_url` is an optional documentation URL that the dashboard renders
 * as a "Read the guide →" link below the actionable text. `admin_link`
 * is an optional wp-admin URL (with anchor) that takes the user straight
 * to the field they need to edit — rendered as a primary action so the
 * user has a one-click jump instead of a verbal "go to settings".
 */
final class Preflight_Check_Result {

    public function __construct(
        public readonly string $name,
        public readonly Preflight_Level $level,
        public readonly string $message,
        public readonly ?string $actionable,
        public readonly ?string $help_url = null,
        public readonly ?string $admin_link = null,
        public readonly ?string $admin_link_label = null,
    ) {}

    /**
     * @return array{name:string,level:string,message:string,actionable:string|null,help_url:string|null,admin_link:string|null,admin_link_label:string|null}
     */
    public function to_array(): array {
        return array(
            'name'             => $this->name,
            'level'            => $this->level->value,
            'message'          => $this->message,
            'actionable'       => $this->actionable,
            'help_url'         => $this->help_url,
            'admin_link'       => $this->admin_link,
            'admin_link_label' => $this->admin_link_label,
        );
    }
}
