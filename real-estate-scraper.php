<?php

/**
 * Plugin Name: Real Estate Scraper
 * Plugin URI: https://github.com/gabrrrielll/real-estate-scraper
 * Description: Automatically scrapes property listings from real estate websites and imports them to WordPress property post type.
 * Version: 1.0.0
 * Author: Gabriel Sandu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: real-estate-scraper
 * Domain Path: /languages
 * Requires at least: 6.6
 * Tested up to: 6.6
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('REAL_ESTATE_SCRAPER_VERSION', '1.0.0');
define('REAL_ESTATE_SCRAPER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REAL_ESTATE_SCRAPER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REAL_ESTATE_SCRAPER_PLUGIN_FILE', __FILE__);

// Check WordPress version
add_action('admin_init', 'real_estate_scraper_check_wp_version');
function real_estate_scraper_check_wp_version()
{
    if (version_compare(get_bloginfo('version'), '6.6', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Real Estate Scraper requires WordPress 6.6 or higher.', 'real-estate-scraper'));
    }
}

// Include required files
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-logger.php';
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-scraper.php';
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-mapper.php';
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-cron.php';
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Main plugin class
 */
class Real_Estate_Scraper
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize components
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_res_run_scraper', array($this, 'ajax_run_scraper'));
        add_action('wp_ajax_res_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_res_clean_logs', array($this, 'ajax_clean_logs'));
        add_action('wp_ajax_res_test_cron', array($this, 'ajax_test_cron'));
    }

    public function init()
    {
        // Load text domain
        load_plugin_textdomain('real-estate-scraper', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize components
        Real_Estate_Scraper_Logger::get_instance();
        Real_Estate_Scraper_Admin::get_instance();
        Real_Estate_Scraper_Cron::get_instance();
    }

    public function activate()
    {
        // Create logs directory
        $logs_dir = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }

        // Add .htaccess to protect logs directory
        $htaccess_content = "Order Deny,Allow\nDeny from all";
        file_put_contents($logs_dir . '/.htaccess', $htaccess_content);

        // Set default options
        $default_options = array(
            'category_urls' => array(
                'apartamente' => 'https://example.com/apartamente',
                'garsoniere' => 'https://example.com/garsoniere',
                'case_vile' => 'https://example.com/case-vile',
                'spatii_comerciale' => 'https://example.com/spatii-comerciale'
            ),
            'category_mapping' => array(
                'apartamente' => '',
                'garsoniere' => '',
                'case_vile' => '',
                'spatii_comerciale' => ''
            ),
            'cron_interval' => 'hourly',
            'properties_to_check' => 10,
            'default_status' => 'draft',
            'retry_attempts' => 2,
            'retry_interval' => 30
        );

        add_option('real_estate_scraper_options', $default_options);

        // Schedule cron
        Real_Estate_Scraper_Cron::get_instance()->schedule_cron();
    }

    public function deactivate()
    {
        // Clear scheduled cron
        Real_Estate_Scraper_Cron::get_instance()->clear_cron();

        // Clean old logs
        Real_Estate_Scraper_Logger::get_instance()->clean_old_logs();
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Real Estate Scraper', 'real-estate-scraper'),
            __('Real Estate Scraper', 'real-estate-scraper'),
            'manage_options',
            'real-estate-scraper',
            array(Real_Estate_Scraper_Admin::get_instance(), 'admin_page'),
            'dashicons-download',
            30
        );
    }

    public function ajax_run_scraper()
    {
        check_ajax_referer('res_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }

        // Run scraper
        $scraper = Real_Estate_Scraper_Scraper::get_instance();
        $result = $scraper->run_scraper();

        wp_send_json($result);
    }

    public function ajax_get_logs()
    {
        check_ajax_referer('res_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }

        $logger = Real_Estate_Scraper_Logger::get_instance();
        $logs = $logger->get_today_logs();

        wp_send_json($logs);
    }

    public function ajax_clean_logs()
    {
        check_ajax_referer('res_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }

        $logger = Real_Estate_Scraper_Logger::get_instance();
        $result = $logger->clean_old_logs();

        wp_send_json($result);
    }
    
    public function ajax_test_cron()
    {
        check_ajax_referer('res_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }

        $cron = Real_Estate_Scraper_Cron::get_instance();
        $result = $cron->test_cron();

        wp_send_json($result);
    }
}

// Initialize the plugin
Real_Estate_Scraper::get_instance();
