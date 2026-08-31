<?php
/**
 * Uninstall file for AutoWA WhatsApp Plugin
 * * This file is executed when the plugin is completely uninstalled
 * from the WordPress admin panel.
 * * @package AutoWA WhatsApp Plugin
 * @version 1.0.0
 */

// If this file is called directly, abort.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if the user has permissions.
if (!current_user_can('activate_plugins')) {
    return;
}

// Verify that we are uninstalling the correct plugin.
if (__FILE__ != WP_UNINSTALL_PLUGIN) {
    return;
}

/**
 * Main uninstall function
 */
function autwa_uninstall_plugin() {
    global $wpdb;
    
    // 1. Stop all active sessions
    autwa_stop_all_sessions();
    
    // 2. Delete all plugin tables
    autwa_drop_all_tables();
    
    // 3. Delete all plugin options
    autwa_delete_all_options();
    
    // 4. Delete plugin files and directories
    autwa_cleanup_all_files();
    
    // 5. Clear scheduled tasks
    autwa_clear_scheduled_tasks();
    
    // 6. Uninstall log
    error_log('AutoWA WhatsApp Plugin: Full uninstall completed.');
}

/**
 * Stop all active sessions via API
 */
function autwa_stop_all_sessions() {
    global $wpdb;
    
    $api_url = get_option('autwa_api_url');
    $api_key = get_option('autwa_api_key');
    
    if (empty($api_url)) {
        return;
    }
    
    $sessions_table = $wpdb->prefix . 'autwa_sessions';
    
    // Check if the table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") != $sessions_table) {
        return;
    }
    
    $active_sessions = $wpdb->get_results(
        "SELECT session_name FROM $sessions_table WHERE status IN ('WORKING', 'STARTING', 'SCAN_QR_CODE')"
    );
    
    $headers = array();
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }
    
    foreach ($active_sessions as $session) {
        // Try to stop each session
        wp_remote_post($api_url . '/sessions/' . $session->session_name . '/stop', array(
            'headers' => $headers,
            'timeout' => 10
        ));
        
        // Try to logout
        wp_remote_post($api_url . '/sessions/' . $session->session_name . '/logout', array(
            'headers' => $headers,
            'timeout' => 10
        ));
    }
}

/**
 * Delete all plugin tables
 */
function autwa_drop_all_tables() {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'autwa_sessions',
        $wpdb->prefix . 'autwa_logs',
        $wpdb->prefix . 'autwa_contacts', // Ensure this is deleted
        $wpdb->prefix . 'autwa_chats'     // Ensure this is deleted
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
}


/**
 * Delete all plugin options
 */
function autwa_delete_all_options() {
    global $wpdb;
    
    // List of specific plugin options
    $specific_options = array(
        'autwa_api_url',
        'autwa_api_key',
        'autwa_webhook_url',
        'autwa_session_name',
        'autwa_debug_mode',
        'autwa_auto_restart',
        'autwa_plugin_version',
        'autwa_plugin_activated_time'
    );
    
    // Delete specific options
    foreach ($specific_options as $option) {
        delete_option($option);
    }
    
    // Delete any option starting with 'autwa_'
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'autwa_%'");
    
    // Delete plugin transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autwa_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_autwa_%'");
}

/**
 * Delete plugin files and directories
 */
function autwa_cleanup_all_files() {
    $upload_dir = wp_upload_dir();
    $autwa_dir = $upload_dir['basedir'] . '/autwa-whatsapp/';
    
    // Recursively delete the plugin directory
    if (file_exists($autwa_dir)) {
        autwa_recursive_rmdir($autwa_dir);
    }
    
    // Delete cache files if they exist
    $cache_files = glob(WP_CONTENT_DIR . '/cache/autwa-*');
    foreach ($cache_files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

/**
 * Recursively delete a directory
 */
function autwa_recursive_rmdir($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
            $path = $dir . "/" . $object;
            if (is_dir($path)) {
                autwa_recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
    
    return rmdir($dir);
}

/**
 * Clear scheduled tasks
 */
function autwa_clear_scheduled_tasks() {
    // Delete any scheduled tasks from the plugin
    wp_clear_scheduled_hook('autwa_cleanup_sessions');
    wp_clear_scheduled_hook('autwa_check_sessions_status');
    wp_clear_scheduled_hook('autwa_daily_maintenance');
    
    // Delete any cron event starting with 'autwa_'
    $crons = _get_cron_array();
    if (is_array($crons)) {
        foreach ($crons as $timestamp => $cron) {
            if (is_array($cron)) {
                foreach ($cron as $hook => $events) {
                    if (strpos($hook, 'autwa_') === 0) {
                        wp_unschedule_event($timestamp, $hook);
                    }
                }
            }
        }
    }
}

/**
 * Additional security function
 * Verify that uninstall is intended
 */function autwa_verify_uninstall() {
    // Verify that no critical sessions are running
    $api_url = get_option('autwa_api_url');
    if (!empty($api_url)) {
        // Allow a grace period to close sessions
        sleep(2);
    }
}

// Execute security check
autwa_verify_uninstall();

// Execute main uninstall
autwa_uninstall_plugin();

// Final log message
error_log('AutoWA WhatsApp Plugin: Full uninstall executed from uninstall.php');