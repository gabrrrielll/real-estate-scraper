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
        $this->logger = Real_Estate_Scraper_Logger::get_instance();
        $this->options = get_option('real_estate_scraper_options', array());

        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
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
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'toplevel_page_real-estate-scraper') {
            return;
        }

        wp_enqueue_script(
            'real-estate-scraper-admin',
            REAL_ESTATE_SCRAPER_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            REAL_ESTATE_SCRAPER_VERSION,
            true
        );

        wp_enqueue_style(
            'real-estate-scraper-admin',
            REAL_ESTATE_SCRAPER_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            REAL_ESTATE_SCRAPER_VERSION
        );

        wp_localize_script('real-estate-scraper-admin', 'realEstateScraper', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('res_nonce'),
            'strings' => array(
                'running' => __('Running...', 'real-estate-scraper'),
                'success' => __('Success!', 'real-estate-scraper'),
                'error' => __('Error!', 'real-estate-scraper'),
                'confirm' => __('Are you sure?', 'real-estate-scraper')
            )
        ));
    }

    /**
     * Admin page
     */
    public function admin_page()
    {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['res_nonce'], 'res_save_settings')) {
            $this->save_settings();
        }

        // Get current options
        $options = get_option('real_estate_scraper_options', array());

        // Get property statuses for mapping
        $property_statuses = get_terms(array(
            'taxonomy' => 'property_status',
            'hide_empty' => false
        ));

        // Get cron info
        $cron = Real_Estate_Scraper_Cron::get_instance();
        $next_run = $cron->get_next_run_time();
        $last_run = $cron->get_last_run_time();

        ?>
        <div class="wrap">
            <h1><?php _e('Real Estate Scraper', 'real-estate-scraper'); ?></h1>
            
            <div class="res-admin-container">
                <div class="res-main-content">
                    <form method="post" action="">
                        <?php wp_nonce_field('res_save_settings', 'res_nonce'); ?>
                        
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
                            </table>
                        </div>
                        
                        <div class="res-settings-section">
                            <h2><?php _e('Category Mapping', 'real-estate-scraper'); ?></h2>
                            <p class="description"><?php _e('Map each category to a property status.', 'real-estate-scraper'); ?></p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Apartamente', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[apartamente]">
                                            <option value=""><?php _e('Select Property Status', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_statuses as $status): ?>
                                                <option value="<?php echo $status->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['apartamente'] ?? '', $status->term_id); ?>>
                                                    <?php echo esc_html($status->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Garsoniere', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[garsoniere]">
                                            <option value=""><?php _e('Select Property Status', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_statuses as $status): ?>
                                                <option value="<?php echo $status->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['garsoniere'] ?? '', $status->term_id); ?>>
                                                    <?php echo esc_html($status->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Case/Vile', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[case_vile]">
                                            <option value=""><?php _e('Select Property Status', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_statuses as $status): ?>
                                                <option value="<?php echo $status->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['case_vile'] ?? '', $status->term_id); ?>>
                                                    <?php echo esc_html($status->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Spații Comerciale', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="category_mapping[spatii_comerciale]">
                                            <option value=""><?php _e('Select Property Status', 'real-estate-scraper'); ?></option>
                                            <?php foreach ($property_statuses as $status): ?>
                                                <option value="<?php echo $status->term_id; ?>" 
                                                        <?php selected($options['category_mapping']['spatii_comerciale'] ?? '', $status->term_id); ?>>
                                                    <?php echo esc_html($status->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="res-settings-section">
                            <h2><?php _e('Scraper Settings', 'real-estate-scraper'); ?></h2>
                            
                            <table class="form-table">
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
                                    <th scope="row"><?php _e('Default Status', 'real-estate-scraper'); ?></th>
                                    <td>
                                        <select name="default_status">
                                            <option value="draft" <?php selected($options['default_status'] ?? 'draft', 'draft'); ?>>
                                                <?php _e('Draft', 'real-estate-scraper'); ?>
                                            </option>
                                            <option value="publish" <?php selected($options['default_status'] ?? 'draft', 'publish'); ?>>
                                                <?php _e('Published', 'real-estate-scraper'); ?>
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <div class="res-sidebar">
                    <div class="res-status-box">
                        <h3><?php _e('Scraper Status', 'real-estate-scraper'); ?></h3>
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
     * Save settings
     */
    private function save_settings()
    {
        $options = array(
            'category_urls' => array(
                'apartamente' => sanitize_url($_POST['category_urls']['apartamente']),
                'garsoniere' => sanitize_url($_POST['category_urls']['garsoniere']),
                'case_vile' => sanitize_url($_POST['category_urls']['case_vile']),
                'spatii_comerciale' => sanitize_url($_POST['category_urls']['spatii_comerciale'])
            ),
            'category_mapping' => array(
                'apartamente' => intval($_POST['category_mapping']['apartamente']),
                'garsoniere' => intval($_POST['category_mapping']['garsoniere']),
                'case_vile' => intval($_POST['category_mapping']['case_vile']),
                'spatii_comerciale' => intval($_POST['category_mapping']['spatii_comerciale'])
            ),
            'cron_interval' => sanitize_text_field($_POST['cron_interval']),
            'properties_to_check' => intval($_POST['properties_to_check']),
            'default_status' => sanitize_text_field($_POST['default_status']),
            'retry_attempts' => 2,
            'retry_interval' => 30
        );

        update_option('real_estate_scraper_options', $options);

        // Update cron schedule
        $cron = Real_Estate_Scraper_Cron::get_instance();
        $cron->update_cron_interval($options['cron_interval']);

        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'real-estate-scraper') . '</p></div>';
    }
}

