<?php
/**
 * Uninstall script for Polylang Automatic AI Translation plugin
 *
 * This file is automatically executed by WordPress when the plugin is deleted.
 * It removes all database tables created by the plugin.
 *
 * @package PolylangAutomaticAITranslation
 */

// Exit if uninstall not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// A module that owns data of its own brings an uninstall file next to its
// code and hooks its per-site cleanup on `pllat_uninstall_site`. Composition
// by file presence: the free zip does not contain the pro modules, so a module
// uninstall file removes pro-only data (data of a module both editions ship
// belongs to the shared cleanup below). It runs whether or not the other
// edition stays installed: the free tree carries no trace of the pro modules
// and could never remove their data later.
foreach ( glob( __DIR__ . '/src/Modules/*/uninstall.php' ) ?: array() as $pllat_module_uninstall ) {
    require_once $pllat_module_uninstall;
}

// The free (epicwp-ai-translation-for-polylang) and the Pro edition share every
// pllat_* table and option, and both ship this file. When the other edition
// is still installed, active or not, the shared data has to survive: deleting
// the deactivated free plugin after an upgrade to Pro must not wipe the Pro
// site. Pro is recognised by its main file whatever its folder is called.
$pllat_other_main_file         = 'epicwp-ai-translation-for-polylang.php' === basename( WP_UNINSTALL_PLUGIN )
    ? 'polylang-ai-automatic-translation.php'
    : 'epicwp-ai-translation-for-polylang.php';
$pllat_other_edition_installed = false;
foreach ( glob( WP_PLUGIN_DIR . '/*/' . $pllat_other_main_file ) ?: array() as $pllat_other_edition ) {
    if ( dirname( $pllat_other_edition ) === __DIR__ ) {
        continue;
    }
    $pllat_other_edition_installed = true;
}

/**
 * Delete all plugin database tables for a single site
 *
 * @return void
 */
function pllat_delete_database_tables() {
    global $wpdb;

    // All current tables plus every legacy table that may still be around
    // on installs that never made it through every migration. DROP TABLE IF
    // EXISTS is idempotent, so listing the long tail costs nothing.
    $tables = array(
        // Current schema (v3.13).
        $wpdb->prefix . 'pllat_bulk_runs',
        $wpdb->prefix . 'pllat_claims',
        $wpdb->prefix . 'pllat_provider_health',
        $wpdb->prefix . 'pllat_activity_log',
        $wpdb->prefix . 'pllat_translation_index',
        $wpdb->prefix . 'pllat_translation_field_state',
        // Legacy (dropped during prior version upgrades; included here as a
        // safety net for installs whose upgrade path stalled).
        $wpdb->prefix . 'pllat_tasks',
        $wpdb->prefix . 'pllat_jobs',
        $wpdb->prefix . 'pllat_source_hashes',
        $wpdb->prefix . 'pllat_string_jobs',
        $wpdb->prefix . 'pllat_string_runs',
    );

    foreach ( $tables as $table ) {
        $wpdb->query(
            "DROP TABLE IF EXISTS `{$table}`",
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // Delete database version tracking options
    delete_option( 'pllat_db_version' );
    delete_option( 'pllat_db_installed_at' );

    // Lightweight strings/menus path (no tables).
    delete_option( 'pllat_lw_source_hashes' );
    delete_option( 'pllat_cached_strings' );
    delete_transient( 'pllat_strings_progress' );

    // Unified meta field options.
    delete_option( 'pllat_meta_translate_keys' );
    delete_option( 'pllat_meta_copy_keys' );
    delete_option( 'pllat_meta_ignore_keys' );
    delete_option( 'pllat_meta_field_activation_scan_done' );
    delete_option( 'pllat_meta_field_last_scan' );

    // Legacy meta field options (pre-3.1.0).
    delete_option( 'pllat_custom_post_meta_keys' );
    delete_option( 'pllat_custom_term_meta_keys' );
    delete_option( 'pllat_explored_translate_meta_keys' );
    delete_option( 'pllat_explored_copy_meta_keys' );
    delete_option( 'pllat_explored_ignore_meta_keys' );
}

/**
 * Uninstall the current site: the shared data unless the other edition still
 * uses it, then the data of the modules that ship their own uninstall file.
 *
 * @param bool $keep_shared_data Whether the other edition is still installed.
 * @return void
 */
function pllat_uninstall_current_site( $keep_shared_data ) {
    if ( ! $keep_shared_data ) {
        pllat_delete_database_tables();
    }

    /**
     * Fires once per site during uninstall, after the shared cleanup. Fires
     * also while the other edition stays installed: the modules hooked here
     * ship in one edition only.
     */
    do_action( 'pllat_uninstall_site' );
}

/**
 * Main uninstall routine
 */
if ( is_multisite() ) {
    // For multisite, clean up each site's tables
    global $wpdb;

    // Get all blog IDs
    $pllat_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

    foreach ( $pllat_blog_ids as $pllat_blog_id ) {
        switch_to_blog( $pllat_blog_id );
        pllat_uninstall_current_site( $pllat_other_edition_installed );
        restore_current_blog();
    }
} else {
    // Single site installation
    pllat_uninstall_current_site( $pllat_other_edition_installed );
}
