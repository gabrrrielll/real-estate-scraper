<?php
/**
 * Admin class for Real Estate Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Admin
{
    private static $instance = null;
    private $logger;
    private $options;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        error_log('RES DEBUG - Admin class constructor called');

        $this->logger = Real_Estate_Scraper_Logger::get_instance();
        $this->options = get_option('real_estate_scraper_options', array());

        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));

        // Ensure jQuery is loaded in admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_jquery'));

        add_action('admin_post_res_save_settings', array($this, 'handle_save_settings'));

        error_log('RES DEBUG - Admin class hooks added');
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules)
    {
        $cron = Real_Estate_Scraper_Cron::get_instance();
        return $cron->add_cron_intervals($schedules);
    }

    /**
     * Enqueue jQuery for admin pages
     */
    public function enqueue_jquery($hook)
    {
        error_log('RES DEBUG - enqueue_jquery called with hook: ' . $hook);
        if (strpos($hook, 'real-estate-scraper') !== false) {
            wp_enqueue_script('jquery');
            error_log('RES DEBUG - jQuery enqueued for hook: ' . $hook);
        } else {
            error_log('RES DEBUG - jQuery NOT enqueued - hook does not contain real-estate-scraper');
        }
    }

    /**
     * Add inline assets directly in admin page
     */
    public function add_inline_assets()
    {
        error_log('RES DEBUG - ===== ADD_INLINE_ASSETS CALLED =====');
        error_log('RES DEBUG - Current screen: ' . (get_current_screen() ? get_current_screen()->id : 'NO SCREEN'));
        error_log('RES DEBUG - Plugin DIR: ' . REAL_ESTATE_SCRAPER_PLUGIN_DIR);

        // Load jQuery first
        wp_enqueue_script('jquery');
        error_log('RES DEBUG - jQuery enqueued in add_inline_assets');

        // CSS
        $css_path = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'admin/css/admin.css';
        error_log('RES DEBUG - CSS path: ' . $css_path);
        error_log('RES DEBUG - CSS exists: ' . (file_exists($css_path) ? 'YES' : 'NO'));

        if (file_exists($css_path)) {
            echo '<style type="text/css">';
            echo file_get_contents($css_path);
            echo '</style>';
            error_log('RES DEBUG - CSS loaded inline successfully');
        } else {
            error_log('RES DEBUG - CSS file not found!');
        }

        // JS Configuration
        error_log('RES DEBUG - Creating JS configuration');
        echo '<script type="text/javascript">';

        $cron_status_data = $this->_get_cron_status_data();

        echo 'window.realEstateScraper = ' . json_encode(array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('res_nonce'),
            'strings' => array(
                'running' => __('Running...', 'real-estate-scraper'),
                'success' => __('Success!', 'real-estate-scraper'),
                'error' => __('Error!', 'real-estate-scraper'),
                'confirm' => __('Are you sure?', 'real-estate-scraper'),
                'stopCron' => __('Stop Cron', 'real-estate-scraper'),
                'startCron' => __('Start Cron', 'real-estate-scraper'),
                'processing' => __('Processing...', 'real-estate-scraper'),
                'nextRun' => __('Next Run:', 'real-estate-scraper'),
                'lastRun' => __('Last Run:', 'real-estate-scraper')
            ),
            'initial_cron_status' => array(
                'cron_active' => $cron_status_data['is_cron_active'],
                'button_text' => $cron_status_data['button_text'],
                'button_class' => $cron_status_data['button_class'],
                'next_run_display' => $cron_status_data['next_run_display'],
                'last_run_display' => $cron_status_data['last_run_display']
            )
        )) . ';';
        echo '</script>';
        error_log('RES DEBUG - JS configuration created');

        // JS Code with jQuery dependency check
        $js_path = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'admin/js/admin.js';
        error_log('RES DEBUG - JS path: ' . $js_path);
        error_log('RES DEBUG - JS exists: ' . (file_exists($js_path) ? 'YES' : 'NO'));

        if (file_exists($js_path)) {
            echo '<script type="text/javascript">';
            echo 'jQuery(document).ready(function($) {';
            echo file_get_contents($js_path);
            echo '});';
            echo '</script>';
            error_log('RES DEBUG - JS loaded inline with jQuery wrapper successfully');
        } else {
            error_log('RES DEBUG - JS file not found!');
        }

        error_log('RES DEBUG - ===== ADD_INLINE_ASSETS COMPLETED =====');
    }

    /**
     * Returns formatted next/last cron run times and active status.
     */
    private function _get_cron_status_data()
    {
        $next_run_timestamp = wp_next_scheduled('real_estate_scraper_cron');
        $last_run_timestamp = get_option('real_estate_scraper_last_run', false);

        $next_run_display = $next_run_timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run_timestamp) : __('Not scheduled', 'real-estate-scraper');
        $last_run_display = $last_run_timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_run_timestamp) : __('Never', 'real-estate-scraper');

        return [
            'next_run_display' => $next_run_display,
            'last_run_display' => $last_run_display,
            'is_cron_active' => (bool) $next_run_timestamp,
            'button_text' => (bool) $next_run_timestamp ? __('Stop Cron', 'real-estate-scraper') : __('Start Cron', 'real-estate-scraper'),
            'button_class' => (bool) $next_run_timestamp ? 'button-danger' : 'button-secondary'
        ];
    }

    /**
     * Admin page
     */
    public function admin_page()
    {
        error_log('RES DEBUG - ===== ADMIN PAGE FUNCTION CALLED =====');
        error_log('RES DEBUG - Function called at: ' . current_time('mysql'));
        error_log('RES DEBUG - Current user ID: ' . get_current_user_id());
        error_log('RES DEBUG - User can manage options: ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
        error_log('RES DEBUG - Request method: ' . $_SERVER['REQUEST_METHOD']);
        error_log('RES DEBUG - POST data count: ' . count($_POST));
        error_log('RES DEBUG - POST keys: ' . implode(', ', array_keys($_POST)));
        error_log('RES DEBUG - Current screen ID: ' . (get_current_screen() ? get_current_screen()->id : 'NO SCREEN'));

        // Show success message if redirected after save
        if (isset($_GET['settings-saved']) && $_GET['settings-saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'real-estate-scraper') . '</p></div>';
        } elseif (isset($_GET['settings-error']) && $_GET['settings-error'] == '1') {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Error: Settings could not be saved or verified. Please check logs.', 'real-estate-scraper') . '</p></div>';
        }

        // Check permissions first
        if (!current_user_can('manage_options')) {
            error_log('RES DEBUG - User does not have manage_options capability');
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // No direct form processing here, it's handled by admin_post hook
        error_log('RES DEBUG - Admin page loaded. Form processing handled by admin_post.');

        // Get current options - force refresh from database for display
        wp_cache_delete('real_estate_scraper_options', 'options');
        $options = get_option('real_estate_scraper_options', array());
        error_log('RES DEBUG - Current options loaded for display (after targeted cache delete): ' . print_r($options, true));

        // Debugging: Log options values right before form rendering
        error_log('RES DEBUG - Options values just before form rendering:');
        error_log('RES DEBUG - URL Apartamente: ' . ($options['category_urls']['apartamente'] ?? 'N/A'));
        error_log('RES DEBUG - Mapping Apartamente: ' . ($options['category_mapping']['apartamente'] ?? 'N/A'));
        error_log('RES DEBUG - Cron Interval: ' . ($options['cron_interval'] ?? 'N/A'));
        error_log('RES DEBUG - Properties to Check: ' . ($options['properties_to_check'] ?? 'N/A'));
        error_log('RES DEBUG - Default Status: ' . ($options['default_status'] ?? 'N/A'));
        error_log('RES DEBUG - Enable Cron (from DB): ' . ($options['enable_cron'] ?? 'N/A')); // NEW LOG

        // Get property types for mapping
        $property_types = get_terms(array(
            'taxonomy' => 'property_type',
            'hide_empty' => false
        ));

        error_log('RES DEBUG - Property types found: ' . count($property_types));
        if (is_wp_error($property_types)) {
            error_log('RES DEBUG - Error getting property types: ' . $property_types->get_error_message());
            $property_types = array();
        } else {
            foreach ($property_types as $type) {
                error_log('RES DEBUG - Property type: ID=' . $type->term_id . ', Name=' . $type->name);
            }
        }

        // Get cron info
        $cron = Real_Estate_Scraper_Cron::get_instance();
        $next_run = $cron->get_next_run_time();
        $last_run = $cron->get_last_run_time();

        ?>
        <div class="wrap">
            <h1><?php _e('Real Estate Scraper', 'real-estate-scraper'); ?></h1>
            
            <?php $this->add_inline_assets(); ?>
            
            <div class="res-admin-container">
                <div class="res-main-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="res_save_settings" />
                <?php
                $nonce = wp_create_nonce('res_save_settings');
        error_log('RES DEBUG - Generated nonce for form: ' . $nonce);
        wp_nonce_field('res_save_settings', 'res_nonce');
        error_log('RES DEBUG - Nonce field added to form');
        ?>
                        
                        <div class="res-settings-section">
                            <h2><?php _e('Category URLs', 'real-estate-scraper'); ?></h2>
                            <p class="description"><?php _e('Enter the URLs for each category to scrape.', 'real-estate-scraper'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Apartamente', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="url" name="category_urls[apartamente]" 
                                               value="<?php echo esc_attr($options['category_urls']['apartamente'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <?php
                                // /*
        ?>
                                <tr>
                                    <th scope="row"><?php _e('Garsoniere', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="url" name="category_urls[garsoniere]" 
                                               value="<?php echo esc_attr($options['category_urls']['garsoniere'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Case/Vile', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="url" name="category_urls[case_vile]" 
                                               value="<?php echo esc_attr($options['category_urls']['case_vile'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Spații Comerciale', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="url" name="category_urls[spatii_comerciale]" 
                                               value="<?php echo esc_attr($options['category_urls']['spatii_comerciale'] ?? ''); ?>" 
                                               class="regular-text" />
                                    </td>
                                </tr>
                                <?php
        // */
        ?>
                            </table>
                        </div>
                        
                        <div class="res-settings-section">
                            <h2><?php _e('Category Mapping', 'real-estate-scraper'); ?></h2>
                            <p class="description"><?php _e('Map each category to a property type.', 'real-estate-scraper'); ?></p>
                            
                            <table class="form-table">
                                <?php
        // /*
        ?>
                                <tr>
                                    <th scope="row"><?php _e('Apartamente', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[apartamente]">
                                            <option value=""><?php _e('Select Property Type', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_types as $type): ?>
                                                <option value="<?php echo $type->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['apartamente'] ?? '', $type->term_id); ?>>
                                                    <?php echo esc_html($type->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Garsoniere', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[garsoniere]">
                                            <option value=""><?php _e('Select Property Type', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_types as $type): ?>
                                                <option value="<?php echo $type->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['garsoniere'] ?? '', $type->term_id); ?>>
                                                    <?php echo esc_html($type->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Case/Vile', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[case_vile]">
                                            <option value=""><?php _e('Select Property Type', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_types as $type): ?>
                                                <option value="<?php echo $type->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['case_vile'] ?? '', $type->term_id); ?>>
                                                    <?php echo esc_html($type->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Spații Comerciale', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[spatii_comerciale]">
                                            <option value=""><?php _e('Select Property Type', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_types as $type): ?>
                                                <option value="<?php echo $type->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['spatii_comerciale'] ?? '', $type->term_id); ?>>
                                                    <?php echo esc_html($type->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php
        // */
        ?>
                            </table>
                        </div>
                        
                        <div class="res-settings-section">
                            <h2><?php _e('Scraper Settings', 'real-estate-scraper'); ?></h2>
                            
                            <table class="form-table">
                                <?php
        // /*
        ?>
                                <tr>
                                    <th scope="row"><?php _e('Cron Interval', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="cron_interval">
                                            <?php
            $intervals = array(
                '15min' => __('Every 15 minutes', 'real-estate-scraper'),
                '30min' => __('Every 30 minutes', 'real-estate-scraper'),
                'hourly' => __('Every hour', 'real-estate-scraper'),
                '6hours' => __('Every 6 hours', 'real-estate-scraper'),
                '12hours' => __('Every 12 hours', 'real-estate-scraper'),
                'daily' => __('Daily', 'real-estate-scraper')
            );
        foreach ($intervals as $value => $label):
            ?>
                                                <option value="<?php echo $value; ?>" 
                                                        <?php selected($options['cron_interval'] ?? 'hourly', $value); ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Properties to Check', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="number" name="properties_to_check" 
                                               value="<?php echo esc_attr($options['properties_to_check'] ?? 10); ?>" 
                                               min="1" max="100" />
                                        <p class="description"><?php _e('Number of properties to check per category.', 'real-estate-scraper'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Max Ads per Session', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <input type="number" name="max_ads_per_session" 
                                               value="<?php echo esc_attr($options['max_ads_per_session'] ?? 4); ?>" 
                                               min="1" max="50" />
                                        <p class="description"><?php _e('Maximum number of ads to publish per scraping session (across all categories).', 'real-estate-scraper'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Enable Cron', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <label for="res_enable_cron">
                                            <input type="checkbox" id="res_enable_cron" name="enable_cron" value="1" <?php checked(1, $options['enable_cron'] ?? 0); ?> />
                                            <?php _e('Check to enable automatic scraping via cron job.', 'real-estate-scraper'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <?php
                                // */
        ?>
                            </table>
                        </div>
                        
                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <div class="res-sidebar">
                    <div class="res-status-box">
                        <h3><?php _e('Scraper Status', 'real-estate-scraper'); ?></h3>
                        <p><strong><?php _e('Cron Status:', 'real-estate-scraper'); ?></strong> <?php echo ($options['enable_cron'] ?? 1) ? __('Active', 'real-estate-scraper') : __('Inactive', 'real-estate-scraper'); ?></p>
                        <p><strong><?php _e('Next Run:', 'real-estate-scraper'); ?></strong> <?php echo $next_run; ?></p>
                        <p><strong><?php _e('Last Run:', 'real-estate-scraper'); ?></strong> <?php echo $last_run; ?></p>
                        
                        <div class="res-actions">
                            <button type="button" id="run-scraper-now" class="button button-primary">
                                <?php _e('Run Scraper Now', 'real-estate-scraper'); ?>
                            </button>
                            <button type="button" id="test-cron" class="button">
                                <?php _e('Test Cron', 'real-estate-scraper'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="res-logs-box">
                        <h3><?php _e('Live Logs', 'real-estate-scraper'); ?></h3>
                        <div id="live-logs" class="res-logs-container">
                            <p><?php _e('Click "Run Scraper Now" to see live logs.', 'real-estate-scraper'); ?></p>
                        </div>
                        <div class="res-logs-actions">
                            <button type="button" id="refresh-logs" class="button">
                                <?php _e('Refresh Logs', 'real-estate-scraper'); ?>
                            </button>
                            <button type="button" id="clean-logs" class="button">
                                <?php _e('Clean Old Logs', 'real-estate-scraper'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle form submission for saving settings
     */
    public function handle_save_settings()
    {
        // Start output buffering to catch any premature output
        ob_start();
        error_log('RES DEBUG - ===== HANDLE_SAVE_SETTINGS CALLED VIA ADMIN_POST HOOK =====');

        // Verify nonce
        if (!isset($_POST['res_nonce']) || !wp_verify_nonce($_POST['res_nonce'], 'res_save_settings')) {
            error_log('RES DEBUG - Nonce verification failed in handle_save_settings');
            ob_end_clean(); // Clean buffer before dying
            wp_die(__('Security check failed. Please try again.', 'real-estate-scraper'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            error_log('RES DEBUG - User does not have manage_options capability in handle_save_settings');
            ob_end_clean(); // Clean buffer before dying
            wp_die(__('You do not have sufficient permissions to save settings.', 'real-estate-scraper'));
        }

        // Call the actual save settings logic
        $this->save_settings();

        // After saving, reload options to ensure we have the latest data for verification
        wp_cache_delete('real_estate_scraper_options', 'options');
        $options = get_option('real_estate_scraper_options', array()); // This will now fetch the fresh data

        // Determine redirect URL
        $redirect_url = admin_url('admin.php?page=real-estate-scraper');

        // The save_settings() function already logs if options match.
        // We'll check if the options in the DB match the POST data for a more robust check.
        $post_category_urls = isset($_POST['category_urls']) ? array_map('sanitize_url', $_POST['category_urls']) : [];
        $post_category_mapping = []; // Commented out
        $post_cron_interval = isset($_POST['cron_interval']) ? $_POST['cron_interval'] : 'hourly'; // Activated, no sanitization
        $post_properties_to_check = isset($_POST['properties_to_check']) ? intval($_POST['properties_to_check']) : 10;
        $post_default_status = isset($_POST['default_status']) ? $_POST['default_status'] : 'draft'; // Activated, no sanitization
        $post_enable_cron = isset($_POST['enable_cron']) ? 1 : 0; // Correctly handle checkbox value: 1 if checked, 0 if unchecked
        error_log('RES DEBUG - POST['enable_cron'] raw: ' . (isset($_POST['enable_cron']) ? $_POST['enable_cron'] : 'NOT SET')); // NEW LOG
        error_log('RES DEBUG - $post_enable_cron after processing: ' . $post_enable_cron); // NEW LOG

        $db_category_urls = $options['category_urls'] ?? [];
        $db_category_mapping = []; // Commented out
        $db_cron_interval = $options['cron_interval'] ?? 'hourly'; // Activated
        $db_properties_to_check = $options['properties_to_check'] ?? 10;
        $db_default_status = $options['default_status'] ?? 'draft'; // Activated
        $db_enable_cron = $options['enable_cron'] ?? 0; // Activated

        $save_successful = (
            (isset($post_category_urls['apartamente']) && isset($db_category_urls['apartamente']) && $post_category_urls['apartamente'] == $db_category_urls['apartamente']) &&
            (isset($post_category_urls['garsoniere']) && isset($db_category_urls['garsoniere']) && $post_category_urls['garsoniere'] == $db_category_urls['garsoniere']) &&
            (isset($post_category_urls['case_vile']) && isset($db_category_urls['case_vile']) && $post_category_urls['case_vile'] == $db_category_urls['case_vile']) &&
            (isset($post_category_urls['spatii_comerciale']) && isset($db_category_urls['spatii_comerciale']) && $post_category_urls['spatii_comerciale'] == $db_category_urls['spatii_comerciale']) &&
            ($post_properties_to_check == $db_properties_to_check) &&
            ($post_cron_interval == $db_cron_interval) &&
            ($post_default_status == $db_default_status) && // Added for Default Status
            ($post_enable_cron == $db_enable_cron) // Added for Enable Cron
        );

        // Old logic: $save_successful = (
        //     $post_category_urls == $db_category_urls &&
        //     $post_category_mapping == $db_category_mapping &&
        //     $post_cron_interval == $db_cron_interval &&
        //     $post_properties_to_check == $db_properties_to_check &&
        //     $post_default_status == $db_default_status
        // );

        if ($save_successful) {
            $redirect_url = add_query_arg('settings-saved', '1', $redirect_url);
            error_log('RES DEBUG - Settings successfully verified in DB. Redirecting to: ' . $redirect_url);
        } else {
            $redirect_url = add_query_arg('settings-error', '1', $redirect_url);
            error_log('RES DEBUG - Settings NOT VERIFIED in DB. Redirecting to: ' . $redirect_url);
            error_log('RES DEBUG - POST Data: ' . print_r($_POST, true));
            error_log('RES DEBUG - DB Data: ' . print_r($options, true));
        }

        // Clean (aggressively) and end output buffering
        ob_end_clean();
        error_log('RES DEBUG - Aggressive output buffer cleaned before redirect.');

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Save settings
     */
    private function save_settings()
    {
        error_log('RES DEBUG - ===== SAVE SETTINGS FUNCTION CALLED =====');
        error_log('RES DEBUG - Function entry time: ' . current_time('mysql'));
        error_log('RES DEBUG - Current user ID: ' . get_current_user_id());
        error_log('RES DEBUG - User capabilities: ' . print_r(wp_get_current_user()->allcaps, true));

        // Log all POST data in detail
        error_log('RES DEBUG - POST data received:');
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                error_log('RES DEBUG - POST[' . $key . '] = ' . print_r($value, true));
            } else {
                error_log('RES DEBUG - POST[' . $key . '] = ' . $value);
            }
        }

        // Check if required fields are present
        $required_fields = array('category_urls', 'properties_to_check', 'cron_interval', 'default_status'); // Added default_status
        $missing_fields = array();

        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            error_log('RES DEBUG - Missing required POST fields: ' . implode(', ', $missing_fields));
            error_log('RES DEBUG - Available POST keys: ' . implode(', ', array_keys($_POST)));
            // No echo here, redirect happens in handle_save_settings
            return;
        }

        error_log('RES DEBUG - All required fields present, proceeding with sanitization');

        // Sanitize and prepare options
        $options = array();

        // Sanitize category URLs
        if (isset($_POST['category_urls']) && is_array($_POST['category_urls'])) {
            $options['category_urls'] = array(
                'apartamente' => isset($_POST['category_urls']['apartamente']) ? sanitize_url($_POST['category_urls']['apartamente']) : '',
                'garsoniere' => isset($_POST['category_urls']['garsoniere']) ? sanitize_url($_POST['category_urls']['garsoniere']) : '',
                'case_vile' => isset($_POST['category_urls']['case_vile']) ? sanitize_url($_POST['category_urls']['case_vile']) : '',
                'spatii_comerciale' => isset($_POST['category_urls']['spatii_comerciale']) ? sanitize_url($_POST['category_urls']['spatii_comerciale']) : ''
            );
            error_log('RES DEBUG - All Category URLs processed with sanitization: ' . print_r($options['category_urls'], true));
        }

        // Sanitize category mapping
        if (isset($_POST['category_mapping']) && is_array($_POST['category_mapping'])) {
            $options['category_mapping'] = array(
                'apartamente' => isset($_POST['category_mapping']['apartamente']) ? intval($_POST['category_mapping']['apartamente']) : 0,
                'garsoniere' => isset($_POST['category_mapping']['garsoniere']) ? intval($_POST['category_mapping']['garsoniere']) : 0,
                'case_vile' => isset($_POST['category_mapping']['case_vile']) ? intval($_POST['category_mapping']['case_vile']) : 0,
                'spatii_comerciale' => isset($_POST['category_mapping']['spatii_comerciale']) ? intval($_POST['category_mapping']['spatii_comerciale']) : 0
            );
            error_log('RES DEBUG - Category mapping sanitized: ' . print_r($options['category_mapping'], true));
        }

        // Other options (all activated for testing)
        $options['cron_interval'] = isset($_POST['cron_interval']) ? $_POST['cron_interval'] : 'hourly';
        $options['properties_to_check'] = isset($_POST['properties_to_check']) ? intval($_POST['properties_to_check']) : 10;
        $options['max_ads_per_session'] = isset($_POST['max_ads_per_session']) ? intval($_POST['max_ads_per_session']) : 4;
        $options['default_status'] = isset($_POST['default_status']) ? $_POST['default_status'] : 'draft';
        $options['enable_cron'] = isset($_POST['enable_cron']) ? 1 : 0; // Correctly handle checkbox value: 1 if checked, 0 if unchecked
        $options['retry_attempts'] = 2;
        $options['retry_interval'] = 30;

        error_log('RES DEBUG - Final options['enable_cron'] before update_option: ' . ($options['enable_cron'] ?? 'NOT SET')); // NEW LOG
        error_log('RES DEBUG - Final options array: ' . print_r($options, true)); // Reverted log message

        // Get current options for comparison
        $current_options = get_option('real_estate_scraper_options', array());
        error_log('RES DEBUG - Current options: ' . print_r($current_options, true)); // Reverted log message

        // Check if options actually changed
        $options_changed = ($current_options !== $options);
        error_log('RES DEBUG - Options changed: ' . ($options_changed ? 'YES' : 'NO'));

        // Update options
        error_log('RES DEBUG - Calling update_option...');
        error_log('RES DEBUG - Options being saved: ' . print_r($options, true));

        // Try to update the option
        $result = update_option('real_estate_scraper_options', $options, false);

        error_log('RES DEBUG - update_option result: ' . var_export($result, true));

        // Always verify what was actually saved
        $saved_options = get_option('real_estate_scraper_options', array());
        error_log('RES DEBUG - Options after update_option: ' . print_r($saved_options, true));

        error_log('RES DEBUG - Saved options['enable_cron'] after update_option: ' . ($saved_options['enable_cron'] ?? 'NOT SET')); // NEW LOG

        // Check if the options match what we tried to save
        $options_match = ($saved_options == $options);
        error_log('RES DEBUG - Saved options match what we tried to save: ' . ($options_match ? 'YES' : 'NO'));

        if ($options_match) {
            error_log('RES DEBUG - Settings updated successfully, updating cron schedule...');
            // Update cron schedule (now active)
            try {
                $cron = Real_Estate_Scraper_Cron::get_instance();
                $cron->update_cron_interval($options['cron_interval']);
                error_log('RES DEBUG - Cron schedule updated');
            } catch (Exception $e) {
                error_log('RES DEBUG - Error updating cron: ' . $e->getMessage());
            }

            // No echo here, redirect happens in handle_save_settings
        } else {
            error_log('RES DEBUG - Settings were not saved correctly');
            error_log('RES DEBUG - Difference: Expected=' . print_r($options, true) . ' Got=' . print_r($saved_options, true));

            // Try to save again with autoload = yes
            error_log('RES DEBUG - Trying to save again with autoload=yes');
            $result2 = update_option('real_estate_scraper_options', $options, true);
            error_log('RES DEBUG - Second attempt result: ' . var_export($result2, true));

            $saved_options2 = get_option('real_estate_scraper_options', array());
            if ($saved_options2 == $options) {
                // No echo here, redirect happens in handle_save_settings
            } else {
                // No echo here, redirect happens in handle_save_settings
            }
        }

        error_log('RES DEBUG - ===== SAVE SETTINGS FUNCTION COMPLETED =====');
    }
}
