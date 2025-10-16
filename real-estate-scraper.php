<?php

error_log('RES DEBUG - real-estate-scraper.php file loaded at: ' . current_time('mysql')); // Load the plugin file

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
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/class-geocoder.php'; // NEW: Include geocoder class
require_once REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'includes/constants.php'; // NEW: Include constants file

/**
 * Main plugin class
 */
class Real_Estate_Scraper
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            error_log('RES DEBUG - Creating new plugin instance');
            self::$instance = new self();
        } else {
            error_log('RES DEBUG - Returning existing plugin instance');
        }
        return self::$instance;
    }

    private function __construct()
    {
        error_log('RES DEBUG - Real Estate Scraper plugin constructor called');
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
        add_action('wp_ajax_res_toggle_cron', array($this, 'ajax_toggle_cron'));
    }

    public function init()
    {
        error_log('RES DEBUG - Plugin init function called');

        // Load text domain
        load_plugin_textdomain('real-estate-scraper', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize components
        error_log('RES DEBUG - Initializing components');
        Real_Estate_Scraper_Logger::get_instance();
        Real_Estate_Scraper_Admin::get_instance();
        Real_Estate_Scraper_Cron::get_instance();

        error_log('RES DEBUG - All components initialized');
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
            'max_ads_per_session' => 4,
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
        error_log('RES DEBUG - ===== ADD_ADMIN_MENU CALLED =====');
        error_log('RES DEBUG - Adding admin menu at: ' . current_time('mysql'));

        $hook = add_menu_page(
            __('Real Estate Scraper', 'real-estate-scraper'),
            __('Real Estate Scraper', 'real-estate-scraper'),
            'manage_options',
            'real-estate-scraper',
            array(Real_Estate_Scraper_Admin::get_instance(), 'admin_page'),
            'dashicons-download',
            30
        );

        error_log('RES DEBUG - Admin menu added with hook: ' . $hook);
        error_log('RES DEBUG - Menu page slug: real-estate-scraper');
    }

    public function ajax_run_scraper()
    {
        error_log('RES DEBUG - AJAX run_scraper called.');
        check_ajax_referer('res_nonce', 'nonce');
        error_log('RES DEBUG - Nonce check passed for run_scraper.');

        if (!current_user_can('manage_options')) {
            error_log('RES DEBUG - User does not have manage_options capability for run_scraper.');
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }
        error_log('RES DEBUG - User permissions check passed for run_scraper.');

        // Debug: Check options before creating scraper instance
        $options = get_option('real_estate_scraper_options', array());
        error_log('RES DEBUG - Options before scraper: ' . var_export($options, true));
        error_log('RES DEBUG - Category URLs: ' . var_export($options['category_urls'] ?? 'NOT SET', true));

        // Run scraper
        error_log('RES DEBUG - Creating scraper instance...');
        try {
            $scraper = Real_Estate_Scraper_Scraper::get_instance();
            error_log('RES DEBUG - Scraper instance created successfully, type: ' . get_class($scraper));
            error_log('RES DEBUG - Calling run_scraper()...');
            $result = $scraper->run_scraper();
            error_log('RES DEBUG - run_scraper() completed without exception');
        } catch (Exception $e) {
            error_log('RES DEBUG - Exception in scraper: ' . $e->getMessage());
            $result = array('success' => 0, 'message' => 'Scraper error: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('RES DEBUG - Fatal error in scraper: ' . $e->getMessage());
            $result = array('success' => 0, 'message' => 'Fatal error: ' . $e->getMessage());
        }
        error_log('RES DEBUG - Scraper run completed. Result: ' . print_r($result, true));

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

        // Ensure we return a proper array
        if (!is_array($logs)) {
            $logs = array();
        }

        // Add explicit headers and return JSON
        header('Content-Type: application/json');
        echo json_encode($logs);
        wp_die();
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

    /**
     * AJAX handler for toggling cron job
     */
    public function ajax_toggle_cron()
    {
        check_ajax_referer('res_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'real-estate-scraper'));
        }

        error_log('RES DEBUG - AJAX toggle_cron called');

        $cron = Real_Estate_Scraper_Cron::get_instance();
        $options = get_option('real_estate_scraper_options', array());

        // Check if cron is currently active
        $is_active = wp_next_scheduled('real_estate_scraper_cron');

        if ($is_active) {
            // Stop cron
            wp_clear_scheduled_hook('real_estate_scraper_cron');
            $result = array(
                'success' => true,
                'message' => __('Cron job stopped successfully.', 'real-estate-scraper'),
                'cron_active' => false
            );
            error_log('RES DEBUG - Cron job stopped');
        } else {
            // Start cron
            $interval = $options['cron_interval'] ?? 'hourly';
            $cron->schedule_cron($interval);
            error_log('RES DEBUG - Cron job started with interval: ' . $interval);
        }

        $cron_status_data = $this->get_cron_run_times(); // Re-use the existing helper

        $result['cron_active'] = $cron_status_data['is_cron_active'];
        $result['next_run_display'] = $cron_status_data['next_run_display'];
        $result['last_run_display'] = $cron_status_data['last_run_display'];

        wp_send_json($result);
    }

    /**
     * Returns formatted next/last cron run times.
     */
    private function get_cron_run_times()
    {
        $next_run_timestamp = wp_next_scheduled('real_estate_scraper_cron');
        $last_run_timestamp = get_option('real_estate_scraper_last_run', false);

        $next_run_display = $next_run_timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run_timestamp) : __('Not scheduled', 'real-estate-scraper');
        $last_run_display = $last_run_timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_run_timestamp) : __('Never', 'real-estate-scraper');

        return [
            'next_run_display' => $next_run_display,
            'last_run_display' => $last_run_display,
            'is_cron_active' => (bool) $next_run_timestamp
        ];
    }
}

// Initialize the plugin
Real_Estate_Scraper::get_instance();
