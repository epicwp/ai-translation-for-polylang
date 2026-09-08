<?php
/**
 * Preflight_Scope enum file.
 *
 * @package Polylang AI Automatic Translation
 * @subpackage Status\Preflight
 */

declare(strict_types=1);

namespace PLLAT\Status\Preflight;

\defined( 'ABSPATH' ) || exit;

/**
 * Scope describing which translation path is about to start.
 */
enum Preflight_Scope: string {
    case Run_Start = 'run_start';
    case Single    = 'single';
    case Bulk      = 'bulk';
}
