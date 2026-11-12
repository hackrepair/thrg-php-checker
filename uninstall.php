<?php
/**
 * Uninstall handler for The Hack Repair Guy's PHP Checker.
 *
 * Cleans up all scheduled cron hooks, scan state, cached compatibility results,
 * report history, and settings on single-site and multisite installations.
 *
 * @package THRG_PHP_Checker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'thrg_pc_uninstall_site' ) ) {
    /**
     * Remove all plugin options, transients, and scheduled hooks for the current site.
     *
     * @return void
     */
    function thrg_pc_uninstall_site() {
        wp_clear_scheduled_hook( 'thrg_pc_scheduled_report' );
        wp_clear_scheduled_hook( 'thrg_pc_prescan_updates' );

        $options = array(
            'thrg_pc_results',
            'thrg_pc_update_results',
            'thrg_pc_scan_state',
            'thrg_pc_prescan_state',
            'thrg_pc_runtime_deprecations',
            'thrg_pc_history',
            'thrg_pc_history_paused',
            'thrg_pc_schedule',
            'thrg_pc_schedule_log',
            'thrg_pc_settings',
        );
        foreach ( $options as $option_name ) {
            delete_option( $option_name );
        }
        delete_transient( 'thrg_pc_schedule_lock' );
    }
}

if ( is_multisite() ) {
    foreach (
        get_sites(
            array(
                'fields' => 'ids',
                'number' => 0,
            )
        ) as $thrg_pc_site_id
    ) {
        switch_to_blog( $thrg_pc_site_id );
        thrg_pc_uninstall_site();
        restore_current_blog();
    }
} else {
    thrg_pc_uninstall_site();
}

delete_site_option( 'external_updates-' . basename( __DIR__ ) );
delete_site_transient( 'thrg_pc_github_limit' );
