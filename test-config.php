<?php

/**
 * Test configuration file
 * Upload this to your WordPress root and access via browser to check configuration
 */

// Load WordPress
require_once('wp-config.php');

echo "<h1>WordPress Configuration Test</h1>";

echo "<h2>WordPress Info</h2>";
echo "WordPress Version: " . get_bloginfo('version') . "<br>";
echo "Site URL: " . get_site_url() . "<br>";
echo "Admin URL: " . admin_url() . "<br>";

echo "<h2>PHP Info</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . "<br>";

echo "<h2>Plugin Status</h2>";
$active_plugins = get_option('active_plugins', array());
$res_active = false;
foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'real-estate-scraper') !== false) {
        $res_active = true;
        echo "Real Estate Scraper: <strong>ACTIVE</strong> ($plugin)<br>";
        break;
    }
}
if (!$res_active) {
    echo "Real Estate Scraper: <strong>NOT ACTIVE</strong><br>";
}

echo "<h2>Error Logging</h2>";
echo "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'TRUE' : 'FALSE') . "<br>";
echo "WP_DEBUG_LOG: " . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'TRUE' : 'FALSE') . "<br>";
echo "WP_DEBUG_DISPLAY: " . (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY ? 'TRUE' : 'FALSE') . "<br>";

echo "<h2>Log File Test</h2>";
$test_message = "Test log entry - " . current_time('mysql');
error_log("TEST LOG: $test_message");
echo "Test message logged: $test_message<br>";

echo "<h2>Plugin Constants Test</h2>";
if (defined('REAL_ESTATE_SCRAPER_VERSION')) {
    echo "Plugin loaded: YES<br>";
    echo "Version: " . REAL_ESTATE_SCRAPER_VERSION . "<br>";
    echo "Plugin DIR: " . REAL_ESTATE_SCRAPER_PLUGIN_DIR . "<br>";
    echo "Plugin URL: " . REAL_ESTATE_SCRAPER_PLUGIN_URL . "<br>";
} else {
    echo "Plugin loaded: NO<br>";
}

echo "<h2>File Permissions</h2>";
$wp_content = WP_CONTENT_DIR;
echo "WP Content DIR: $wp_content<br>";
echo "Writable: " . (is_writable($wp_content) ? 'YES' : 'NO') . "<br>";

$uploads_dir = wp_upload_dir();
echo "Uploads DIR: " . $uploads_dir['basedir'] . "<br>";
echo "Uploads Writable: " . (is_writable($uploads_dir['basedir']) ? 'YES' : 'NO') . "<br>";

echo "<h2>Admin Menu Test</h2>";
global $menu;
$menu_found = false;
foreach ($menu as $item) {
    if (is_array($item) && isset($item[2]) && $item[2] === 'real-estate-scraper') {
        $menu_found = true;
        echo "Real Estate Scraper menu: <strong>FOUND</strong><br>";
        break;
    }
}
if (!$menu_found) {
    echo "Real Estate Scraper menu: <strong>NOT FOUND</strong><br>";
}

echo "<h2>Current User</h2>";
echo "User ID: " . get_current_user_id() . "<br>";
echo "Can manage options: " . (current_user_can('manage_options') ? 'YES' : 'NO') . "<br>";

echo "<h2>Test Complete</h2>";
echo "Check your error logs for the test message above.<br>";
echo "Delete this file after testing for security.";



