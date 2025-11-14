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
        // error_log('RES DEBUG - Admin class constructor called');

        $this->logger = Real_Estate_Scraper_Logger::get_instance();
        $this->options = get_option('real_estate_scraper_options', array());

        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));

        // Ensure jQuery is loaded in admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_jquery'));

        add_action('admin_post_res_save_settings', array($this, 'handle_save_settings'));

        add_action('add_meta_boxes_property', array($this, 'register_property_tools_metabox'));
        add_action('wp_ajax_res_refresh_property_text', array($this, 'ajax_refresh_property_text'));
        add_action('wp_ajax_res_refresh_property_images', array($this, 'ajax_refresh_property_images'));
        add_action('save_post_property', array($this, 'save_property_source_url'));

        // error_log('RES DEBUG - Admin class hooks added');
    }

    /**
     * Register property metabox for scraper tools
     */
    public function register_property_tools_metabox()
    {
        add_meta_box(
            'res-property-scraper-tools',
            __('Real Estate Scraper Tools', 'real-estate-scraper'),
            array($this, 'render_property_tools_metabox'),
            'property',
            'side',
            'high'
        );
    }

    /**
     * Render metabox with original link and refresh buttons
     */
    public function render_property_tools_metabox($post)
    {
        $source_url = get_post_meta($post->ID, 'fave_property_source_url', true);
        $nonce = wp_create_nonce('res_property_tools');
        wp_nonce_field('res_property_tools_save', 'res_property_tools_nonce');
        ?>
        <div id="res-property-tools-box">
            <p>
                <strong><?php esc_html_e('Original Listing URL', 'real-estate-scraper'); ?></strong><br>
                <textarea name="res_property_source_url" id="res-property-source-url" rows="3" style="width:100%;"><?php echo esc_textarea($source_url); ?></textarea>
            </p>
            <p>
                <button type="button" class="button button-secondary res-view-original">
                    <?php esc_html_e('Vezi anunțul original', 'real-estate-scraper'); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button button-primary res-property-btn" data-action="res_refresh_property_text">
                    <?php esc_html_e('Extrage textele', 'real-estate-scraper'); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button button-primary res-property-btn" data-action="res_refresh_property_images">
                    <?php esc_html_e('Extrage imaginile', 'real-estate-scraper'); ?>
                </button>
            </p>
            <div class="res-property-tools-status" style="margin-top:10px;"></div>
        </div>
        <script type="text/javascript">
            jQuery(function($) {
                var nonce = '<?php echo esc_js($nonce); ?>';
                var postId = <?php echo (int) $post->ID; ?>;
                var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                var $sourceInput = $('#res-property-source-url');

                $('#res-property-tools-box').on('click', '.res-view-original', function(e) {
                    e.preventDefault();
                    var url = $sourceInput.val().trim();
                    if (url) {
                        window.open(url, '_blank');
                    } else {
                        alert('<?php echo esc_js(__('Link indisponibil. Adăugați mai întâi URL-ul.', 'real-estate-scraper')); ?>');
                    }
                });

                $('#res-property-tools-box').on('click', '.res-property-btn', function(e) {
                    e.preventDefault();

                    var $button = $(this);
                    var sourceUrl = $sourceInput.val().trim();
                    var action = $button.data('action');
                    var $status = $('#res-property-tools-box').find('.res-property-tools-status');

                    if (!sourceUrl) {
                        $status.addClass('error').text('<?php echo esc_js(__('Introduceți URL-ul anunțului înainte de a continua.', 'real-estate-scraper')); ?>');
                        return;
                    }

                    $status.removeClass('updated error').text('<?php echo esc_js(__('Processing...', 'real-estate-scraper')); ?>');
                    $button.prop('disabled', true).addClass('updating-message');

                    $.post(ajaxUrl, {
                        action: action,
                        nonce: nonce,
                        post_id: postId,
                        source_url: sourceUrl
                    }).done(function(response) {
                        if (response && response.success) {
                            $status.addClass('updated').text(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Operation completed successfully.', 'real-estate-scraper')); ?>');
                        } else {
                            var errorMessage = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js(__('Operation failed.', 'real-estate-scraper')); ?>';
                            $status.addClass('error').text(errorMessage);
                        }
                    }).fail(function() {
                        $status.addClass('error').text('<?php echo esc_js(__('AJAX request failed.', 'real-estate-scraper')); ?>');
                    }).always(function() {
                        $button.prop('disabled', false).removeClass('updating-message');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX: Refresh property text content and meta
     */
    public function ajax_refresh_property_text()
    {
        check_ajax_referer('res_property_tools', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'real-estate-scraper')));
        }

        try {
            $source_input = isset($_POST['source_url']) ? esc_url_raw(trim($_POST['source_url'])) : '';
            $property_data = $this->get_property_data_for_refresh($post_id, $source_input);

            if (empty($property_data)) {
                throw new Exception(__('Failed to extract property data.', 'real-estate-scraper'));
            }

            $mapper = Real_Estate_Scraper_Mapper::get_instance();
            $mapper->update_property_text($post_id, $property_data);

            wp_send_json_success(array('message' => __('Text content updated successfully.', 'real-estate-scraper')));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Refresh property images
     */
    public function ajax_refresh_property_images()
    {
        check_ajax_referer('res_property_tools', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'real-estate-scraper')));
        }

        try {
            $source_input = isset($_POST['source_url']) ? esc_url_raw(trim($_POST['source_url'])) : '';
            $property_data = $this->get_property_data_for_refresh($post_id, $source_input);

            if (empty($property_data)) {
                throw new Exception(__('Failed to extract property data.', 'real-estate-scraper'));
            }

            $mapper = Real_Estate_Scraper_Mapper::get_instance();
            $mapper->refresh_property_images($post_id, $property_data);

            wp_send_json_success(array('message' => __('Images updated successfully.', 'real-estate-scraper')));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Prepare property data for refresh actions
     */
    private function get_property_data_for_refresh($post_id, $override_url = '')
    {
        $source_url = '';

        if (!empty($override_url)) {
            if (!filter_var($override_url, FILTER_VALIDATE_URL)) {
                throw new Exception(__('Invalid URL provided.', 'real-estate-scraper'));
            }
            $source_url = $override_url;
        } else {
            $source_url = get_post_meta($post_id, 'fave_property_source_url', true);
        }

        if (empty($source_url)) {
            throw new Exception(__('Source URL not found for this property.', 'real-estate-scraper'));
        }

        $scraper = Real_Estate_Scraper_Scraper::get_instance();
        $property_data = $scraper->fetch_property_data_for_admin($source_url);

        if (empty($property_data)) {
            throw new Exception(__('Could not retrieve data from source URL.', 'real-estate-scraper'));
        }

        $property_data['source_url'] = $source_url;
        update_post_meta($post_id, 'fave_property_source_url', $source_url);

        return $property_data;
    }

    /**
     * Save property source URL from metabox
     */
    public function save_property_source_url($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['res_property_tools_nonce']) || !wp_verify_nonce($_POST['res_property_tools_nonce'], 'res_property_tools_save')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['res_property_source_url'])) {
            $source_url = esc_url_raw(trim($_POST['res_property_source_url']));

            if (!empty($source_url)) {
                update_post_meta($post_id, 'fave_property_source_url', $source_url);
            } else {
                delete_post_meta($post_id, 'fave_property_source_url');
            }
        }
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
        // error_log('RES DEBUG - enqueue_jquery called with hook: ' . $hook);
        if (strpos($hook, 'real-estate-scraper') !== false) {
            wp_enqueue_script('jquery');
            // error_log('RES DEBUG - jQuery enqueued for hook: ' . $hook);
        } else {
            // error_log('RES DEBUG - jQuery NOT enqueued - hook does not contain real-estate-scraper');
        }
    }

    /**
     * Add inline assets directly in admin page
     */
    public function add_inline_assets()
    {
        // Load jQuery first
        wp_enqueue_script('jquery');

        // CSS
        $css_path = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'admin/css/admin.css';
        if (file_exists($css_path)) {
            echo '<style type="text/css">';
            echo file_get_contents($css_path);
            echo '</style>';
        }

        // JS Configuration
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

        // JS Code with jQuery dependency check
        $js_path = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'admin/js/admin.js';
        if (file_exists($js_path)) {
            echo '<script type="text/javascript">';
            echo 'jQuery(document).ready(function($) {';
            echo file_get_contents($js_path);
            echo '});';
            echo '</script>';
        }
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
        // Show success message if redirected after save
        if (isset($_GET['settings-saved']) && $_GET['settings-saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'real-estate-scraper') . '</p></div>';
        } elseif (isset($_GET['settings-error']) && $_GET['settings-error'] == '1') {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Error: Settings could not be saved or verified. Please check logs.', 'real-estate-scraper') . '</p></div>';
        }

        // Check permissions first
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get current options
        wp_cache_delete('real_estate_scraper_options', 'options');
        $options = get_option('real_estate_scraper_options', array());

        // Get property types for mapping
        $property_types = get_terms(array(
            'taxonomy' => 'property_type',
            'hide_empty' => false
        ));

        if (is_wp_error($property_types)) {
            $property_types = array();
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
        ob_start();

        // Verify nonce
        if (!isset($_POST['res_nonce']) || !wp_verify_nonce($_POST['res_nonce'], 'res_save_settings')) {
            ob_end_clean();
            wp_die(__('Security check failed. Please try again.', 'real-estate-scraper'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_die(__('You do not have sufficient permissions to save settings.', 'real-estate-scraper'));
        }

        // Call the actual save settings logic
        $this->save_settings();

        // After saving, reload options for verification
        wp_cache_delete('real_estate_scraper_options', 'options');
        $options = get_option('real_estate_scraper_options', array());

        // Determine redirect URL
        $redirect_url = admin_url('admin.php?page=real-estate-scraper');

        // Verify save
        $post_category_urls = isset($_POST['category_urls']) ? array_map('sanitize_url', $_POST['category_urls']) : [];
        $post_cron_interval = isset($_POST['cron_interval']) ? $_POST['cron_interval'] : 'hourly';
        $post_properties_to_check = isset($_POST['properties_to_check']) ? intval($_POST['properties_to_check']) : 10;
        $post_default_status = isset($_POST['default_status']) ? $_POST['default_status'] : 'draft';
        $post_enable_cron = isset($_POST['enable_cron']) ? 1 : 0;

        $db_category_urls = $options['category_urls'] ?? [];
        $db_cron_interval = $options['cron_interval'] ?? 'hourly';
        $db_properties_to_check = $options['properties_to_check'] ?? 10;
        $db_default_status = $options['default_status'] ?? 'draft';
        $db_enable_cron = $options['enable_cron'] ?? 0;

        $save_successful = (
            (isset($post_category_urls['apartamente']) && isset($db_category_urls['apartamente']) && $post_category_urls['apartamente'] == $db_category_urls['apartamente']) &&
            (isset($post_category_urls['garsoniere']) && isset($db_category_urls['garsoniere']) && $post_category_urls['garsoniere'] == $db_category_urls['garsoniere']) &&
            (isset($post_category_urls['case_vile']) && isset($db_category_urls['case_vile']) && $post_category_urls['case_vile'] == $db_category_urls['case_vile']) &&
            (isset($post_category_urls['spatii_comerciale']) && isset($db_category_urls['spatii_comerciale']) && $post_category_urls['spatii_comerciale'] == $db_category_urls['spatii_comerciale']) &&
            ($post_properties_to_check == $db_properties_to_check) &&
            ($post_cron_interval == $db_cron_interval) &&
            ($post_default_status == $db_default_status) &&
            ($post_enable_cron == $db_enable_cron)
        );

        if ($save_successful) {
            $redirect_url = add_query_arg('settings-saved', '1', $redirect_url);
        } else {
            $redirect_url = add_query_arg('settings-error', '1', $redirect_url);
        }

        ob_end_clean();
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Save settings
     */
    private function save_settings()
    {
        // Check if required fields are present
        $required_fields = array('category_urls', 'properties_to_check', 'cron_interval', 'default_status');
        $missing_fields = array();

        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            return;
        }

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
        }

        // Sanitize category mapping
        if (isset($_POST['category_mapping']) && is_array($_POST['category_mapping'])) {
            $options['category_mapping'] = array(
                'apartamente' => isset($_POST['category_mapping']['apartamente']) ? intval($_POST['category_mapping']['apartamente']) : 0,
                'garsoniere' => isset($_POST['category_mapping']['garsoniere']) ? intval($_POST['category_mapping']['garsoniere']) : 0,
                'case_vile' => isset($_POST['category_mapping']['case_vile']) ? intval($_POST['category_mapping']['case_vile']) : 0,
                'spatii_comerciale' => isset($_POST['category_mapping']['spatii_comerciale']) ? intval($_POST['category_mapping']['spatii_comerciale']) : 0
            );
        }

        // Other options
        $options['cron_interval'] = isset($_POST['cron_interval']) ? $_POST['cron_interval'] : 'hourly';
        $options['properties_to_check'] = isset($_POST['properties_to_check']) ? intval($_POST['properties_to_check']) : 10;
        $options['max_ads_per_session'] = isset($_POST['max_ads_per_session']) ? intval($_POST['max_ads_per_session']) : 4;
        $options['default_status'] = isset($_POST['default_status']) ? $_POST['default_status'] : 'draft';
        $options['enable_cron'] = isset($_POST['enable_cron']) ? 1 : 0;
        $options['retry_attempts'] = 2;
        $options['retry_interval'] = 30;

        // Update options
        update_option('real_estate_scraper_options', $options, false);

        // Manage cron based on enable_cron setting
        try {
            $cron = Real_Estate_Scraper_Cron::get_instance();

            if ($options['enable_cron'] == 1) {
                $cron->schedule_cron($options['cron_interval']);
            } else {
                $cron->clear_cron();
            }
        } catch (Exception $e) {
            error_log('[CRON ERROR] ' . $e->getMessage());
        }
    }
}
