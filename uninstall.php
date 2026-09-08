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

// The free (ai-translation-for-polylang) and the Pro edition share every
// pllat_* table and option, and both ship this file. When the other edition
// is still installed, active or not, its data has to survive: deleting the
// deactivated free plugin after an upgrade to Pro must not wipe the Pro site.
// Pro is recognised by its main file whatever its folder is called.
$pllat_other_main_file = 'ai-translation-for-polylang.php' === basename( WP_UNINSTALL_PLUGIN )
    ? 'polylang-ai-automatic-translation.php'
    : 'ai-translation-for-polylang.php';
foreach ( glob( WP_PLUGIN_DIR . '/*/' . $pllat_other_main_file ) ?: array() as $pllat_other_edition ) {
    if ( dirname( $pllat_other_edition ) !== __DIR__ ) {
        return;
    }
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
        $wpdb->prefix . 'pllat_support_access_audit',
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

    // Clean up support access user
    $support_access = get_option( 'pllat_support_access' );
    if ( is_array( $support_access ) && isset( $support_access['user_id'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $support_access['user_id'] );
    }
    delete_option( 'pllat_support_access' );

    // Safety net: also clean up by username in case option was lost
    $support_user = get_user_by( 'login', 'epicwpsolutions' );
    if ( $support_user ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $support_user->ID );
    }

    // Remove the custom support diagnostic role. Self-contained: the
    // Composer autoloader and plugin bootstrap are NOT loaded in the
    // uninstall context, so we must not require() plugin class files here
    // (a require() of a class the lean rewrite had removed previously made
    // deleting the plugin fatal with a critical error). remove_role() is
    // WordPress core and is available by the time uninstall.php runs.
    remove_role( 'pllat_support_diagnostic' );
}

/**
 * Main uninstall routine
 */
if ( is_multisite() ) {
    // For multisite, clean up each site's tables
    global $wpdb;

    // Get all blog IDs
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        pllat_delete_database_tables();
        restore_current_blog();
    }
} else {
    // Single site installation
    pllat_delete_database_tables();
}
