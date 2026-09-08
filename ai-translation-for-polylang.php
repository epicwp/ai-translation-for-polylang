<?php
/**
 * AI Translation for Polylang
 *
 * Plugin Name:       AI Translation for Polylang
 * Plugin URI:        https://www.epicwpsolutions.com/plugins/polylang-automatic-ai-translation/
 * Description:       Free AI translation for Polylang: translate a post, page or term into all your languages from the editor with your own OpenAI key.
 * Author:            EPIC WP
 * Author URI:        https://www.epicwpsolutions.com
 * Version:           4.21.4
 * Requires PHP:      8.1
 * Requires at least: 5.8
 * Tested up to:      7.1
 * Text Domain:       ai-translation-for-polylang
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * No "Requires Plugins" header on purpose: Polylang Pro lives in polylang-pro/, so
 * WordPress would refuse activation next to it. App::can_initialize() checks Polylang.
 *
 * @package Polylang AI Automatic Translation
 */

\defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Common/Functions/pllat-edition-guard.php';

// The free and the Pro edition share their constants, options, tables and hooks: only one may load.
if ( pllat_edition_yields( 'free', defined( 'PLLAT_EDITION' ), pllat_active_plugin_basenames() ) ) {
    pllat_edition_step_aside( __FILE__ );
    return;
}

define( 'PLLAT_PLUGIN_VERSION', '4.21.4' );
define( 'PLLAT_DB_VERSION', '3.16.0' );
// Shared with the Pro edition: options, hooks and the log directory derive from it, the text domain does not.
define( 'PLLAT_PLUGIN_SLUG', 'polylang-automatic-ai-translation' );
define( 'PLLAT_EDITION', 'free' );
define( 'PLLAT_PLUGIN_FILE', __FILE__ );
define( 'PLLAT_PLUGIN_BASE', plugin_basename( PLLAT_PLUGIN_FILE ) );
define( 'PLLAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLLAT_PLUGIN_URL', plugins_url( '/', PLLAT_PLUGIN_BASE ) );
define( 'PLATT_PLUGIN_SETTINGS_PAGE', admin_url( 'admin.php?page=polylang-ai-automatic-translation' ) );
define( 'PLLAT_PLUGIN_LOG_DIR', WP_CONTENT_DIR . '/polylang-ai-automatic-translation/logs' );
define( 'PLLAT_EXTERNAL_PROCESSOR_URL', '' );
define( 'PLLAT_PRO_URL', 'https://www.epicwpsolutions.com/upgrade/' );

// Autoloader, compatibility functions, Action Scheduler, hooks and the DI app.
require_once __DIR__ . '/src/Common/Utils/bootstrap.php';
