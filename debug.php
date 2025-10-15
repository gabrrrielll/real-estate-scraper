<?php

/**
 * Debug file for Real Estate Scraper
 * Access this file directly to check plugin status
 */

// Define WordPress constants if not defined
if (!defined('ABSPATH')) {
    // Try to find WordPress root
    $wp_path = dirname(__FILE__);
    for ($i = 0; $i < 10; $i++) {
        if (file_exists($wp_path . '/wp-config.php')) {
            require_once($wp_path . '/wp-config.php');
            break;
        }
        $wp_path = dirname($wp_path);
    }
}

if (!defined('ABSPATH')) {
    die('WordPress not found. Please place this file in the plugin directory.');
}

echo "<h1>Real Estate Scraper Debug</h1>";

echo "<h2>Plugin Constants</h2>";
echo "<p><strong>REAL_ESTATE_SCRAPER_VERSION:</strong> " . (defined('REAL_ESTATE_SCRAPER_VERSION') ? REAL_ESTATE_SCRAPER_VERSION : 'NOT DEFINED') . "</p>";
echo "<p><strong>REAL_ESTATE_SCRAPER_PLUGIN_DIR:</strong> " . (defined('REAL_ESTATE_SCRAPER_PLUGIN_DIR') ? REAL_ESTATE_SCRAPER_PLUGIN_DIR : 'NOT DEFINED') . "</p>";
echo "<p><strong>REAL_ESTATE_SCRAPER_PLUGIN_URL:</strong> " . (defined('REAL_ESTATE_SCRAPER_PLUGIN_URL') ? REAL_ESTATE_SCRAPER_PLUGIN_URL : 'NOT DEFINED') . "</p>";

echo "<h2>File Existence Check</h2>";
$files_to_check = array(
    'real-estate-scraper.php',
    'admin/css/admin.css',
    'admin/js/admin.js',
    'includes/class-admin.php',
    'includes/class-logger.php',
    'includes/class-scraper.php',
    'includes/class-mapper.php',
    'includes/class-cron.php'
);

foreach ($files_to_check as $file) {
    $full_path = plugin_dir_path(__FILE__) . $file;
    $exists = file_exists($full_path);
    $size = $exists ? filesize($full_path) : 0;
    echo "<p><strong>$file:</strong> " . ($exists ? "EXISTS ($size bytes)" : "NOT FOUND") . "</p>";
}

echo "<h2>WordPress Info</h2>";
echo "<p><strong>WordPress Version:</strong> " . get_bloginfo('version') . "</p>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Plugin Directory:</strong> " . plugin_dir_path(__FILE__) . "</p>";
echo "<p><strong>Plugin URL:</strong> " . plugin_dir_url(__FILE__) . "</p>";

echo "<h2>Active Plugins</h2>";
$active_plugins = get_option('active_plugins');
$res_active = false;
foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'real-estate-scraper') !== false) {
        $res_active = true;
        echo "<p><strong>Real Estate Scraper:</strong> ACTIVE ($plugin)</p>";
        break;
    }
}
if (!$res_active) {
    echo "<p><strong>Real Estate Scraper:</strong> NOT ACTIVE</p>";
}

echo "<h2>Plugin Options</h2>";
$options = get_option('real_estate_scraper_options');
if ($options) {
    echo "<pre>" . print_r($options, true) . "</pre>";
} else {
    echo "<p>No options found</p>";
}

echo "<h2>Admin Menu Check</h2>";
global $menu, $submenu;
$menu_found = false;
foreach ($menu as $item) {
    if (is_array($item) && isset($item[2]) && $item[2] === 'real-estate-scraper') {
        $menu_found = true;
        echo "<p><strong>Admin Menu:</strong> FOUND</p>";
        break;
    }
}
if (!$menu_found) {
    echo "<p><strong>Admin Menu:</strong> NOT FOUND</p>";
}

echo "<h2>CSS/JS URLs</h2>";
if (defined('REAL_ESTATE_SCRAPER_PLUGIN_URL')) {
    echo "<p><strong>CSS URL:</strong> " . REAL_ESTATE_SCRAPER_PLUGIN_URL . "admin/css/admin.css</p>";
    echo "<p><strong>JS URL:</strong> " . REAL_ESTATE_SCRAPER_PLUGIN_URL . "admin/js/admin.js</p>";

    // Test URLs
    $css_url = REAL_ESTATE_SCRAPER_PLUGIN_URL . 'admin/css/admin.css';
    $js_url = REAL_ESTATE_SCRAPER_PLUGIN_URL . 'admin/js/admin.js';

    echo "<p><strong>CSS Accessible:</strong> " . (file_get_contents($css_url) !== false ? 'YES' : 'NO') . "</p>";
    echo "<p><strong>JS Accessible:</strong> " . (file_get_contents($js_url) !== false ? 'YES' : 'NO') . "</p>";
}


