<?php

/**
 * Mapper class for Real Estate Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Mapper
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
        $this->options['category_mapping'] = $this->options['category_mapping'] ?? array();
        $this->options['category_status_mapping'] = $this->options['category_status_mapping'] ?? array();
    }

    /**
     * Create a property post from scraped data
     */
    public function create_property_post($property_data, $category_key)
    {
        try {
            $mapper_message = "[MAPPER] Creating property for category={$category_key}";
            // $this->logger->info($mapper_message);
            // error_log($mapper_message);

            $property_type_id = intval($this->options['category_mapping'][$category_key] ?? 0);
            $property_status_id = intval($this->options['category_status_mapping'][$category_key] ?? 0);

            $tax_input = array();
            if ($property_type_id > 0) {
                $tax_input['property_type'] = array($property_type_id);
            }
            if ($property_status_id > 0) {
                $tax_input['property_status'] = array($property_status_id);
            }
            $tax_message = "[MAPPER] tax_input=" . wp_json_encode($tax_input);
            // $this->logger->info($tax_message);
            // error_log($tax_message);

            // Prepare post data
            $post_data = array(
                'post_title' => $this->clean_title($property_data['title']),
                'post_content' => $this->clean_content($property_data['content']),
                'post_status' => $this->options['default_status'],
                'post_type' => 'property',
                'post_author' => 1, // Admin user
                'meta_input' => array()
            );

            if (!empty($tax_input)) {
                $post_data['tax_input'] = $tax_input;
            }

            // Set excerpt if content is too long
            if (strlen($post_data['post_content']) > 200) {
                $post_data['post_excerpt'] = wp_trim_words($post_data['post_content'], 30);
            }

            // Create the post
            $post_id = wp_insert_post($post_data);
            $insert_message = "[MAPPER] wp_insert_post → {$post_id}";
            // $this->logger->info($insert_message);
            // error_log($insert_message);

            if (is_wp_error($post_id)) {
                throw new Exception('Failed to create post: ' . $post_id->get_error_message());
            }

            // Set meta fields
            $this->set_property_meta($post_id, $property_data);

            // Set taxonomies
            $this->set_property_taxonomies($post_id, $category_key);

            // Set location taxonomies from geocoded address
            $this->set_location_taxonomies($post_id, $property_data);

            // Save dynamic specifications
            $this->save_dynamic_specifications($post_id, $property_data['specifications'] ?? array());

            // Handle images
            $this->handle_property_images($post_id, $property_data['images']);

            return $post_id;

        } catch (Exception $e) {
            // $this->logger->error('Failed to create property post: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update property text fields and meta from scraped data
     */
    public function update_property_text($post_id, $property_data)
    {
        $post_update = array('ID' => $post_id);
        $has_update = false;

        if (!empty($property_data['title'])) {
            $post_update['post_title'] = $this->clean_title($property_data['title']);
            $has_update = true;
        }

        if (!empty($property_data['content'])) {
            $clean_content = $this->clean_content($property_data['content']);
            $post_update['post_content'] = $clean_content;
            $has_update = true;

            if (strlen($clean_content) > 200) {
                $post_update['post_excerpt'] = wp_trim_words($clean_content, 30);
            }
        }

        if ($has_update) {
            $result = wp_update_post($post_update, true);

            if (is_wp_error($result)) {
                throw new Exception('Failed to update post: ' . $result->get_error_message());
            }
        }

        $meta_changes = $this->set_property_meta($post_id, $property_data);
        $this->save_dynamic_specifications($post_id, $property_data['specifications'] ?? array());
        $this->set_location_taxonomies($post_id, $property_data);

        return array(
            'meta' => $meta_changes,
            'specifications' => $property_data['specifications'] ?? array()
        );
    }

    /**
     * Refresh property images with latest scraped data
     */
    public function refresh_property_images($post_id, $property_data)
    {
        $images = $property_data['images'] ?? array();
        $this->handle_property_images($post_id, $images);
    }

    /**
     * Set property meta fields
     */
    private function set_property_meta($post_id, $property_data)
    {
        // Prepare coordinates for map location field
        $map_location = '';
        if (!empty($property_data['latitude']) && !empty($property_data['longitude'])) {
            $map_location = $this->clean_coordinate($property_data['latitude']) . ',' . $this->clean_coordinate($property_data['longitude']);
        }

        $mapped_specs = $property_data['mapped_specifications'] ?? array();

        $meta_fields = array(
            'fave_property_price' => $this->clean_price($property_data['price']),
            'fave_property_size' => $this->clean_size($mapped_specs['fave_property_size'] ?? $property_data['size']),
            'fave_property_size_prefix' => 'mp',
            'fave_land_area' => $this->clean_size($mapped_specs['fave_land_area'] ?? ''),
            'fave_property_land_postfix' => 'mp',
            'fave_property_bedrooms' => $this->clean_number($mapped_specs['fave_property_bedrooms'] ?? $property_data['bedrooms']),
            'fave_property_bathrooms' => $this->clean_number($mapped_specs['fave_property_bathrooms'] ?? $property_data['bathrooms']),
            'fave_property_rooms' => $this->clean_number($mapped_specs['fave_property_rooms'] ?? ''),
            'fave_property_garages' => $this->clean_number($mapped_specs['fave_property_garages'] ?? ''),
            'fave_property_garage_size' => $this->clean_size($mapped_specs['fave_property_garage_size'] ?? ''),
            'fave_property_address' => $this->get_geocoded_address_for_save($property_data),
            'fave_property_map_address' => $this->get_geocoded_address_for_save($property_data),
            'fave_property_map_latitude' => $this->clean_coordinate($property_data['latitude']),
            'fave_property_map_longitude' => $this->clean_coordinate($property_data['longitude']),
            'fave_property_location' => $map_location, // NEW: Add coordinates for map display
            'fave_property_source_url' => $property_data['source_url'],
            'fave_private_note' => 'Vezi anuntul original aici: @' . $property_data['source_url'],
            'fave_telefon-proprietar' => trim($property_data['phone_number'] ?? ''), // NEW: Add phone number
            'fave_property_year' => $this->clean_number($mapped_specs['fave_property_year'] ?? '')
        );

        foreach ($meta_fields as $key => $value) {
            if ($value !== '' && $value !== null) {
                update_post_meta($post_id, $key, $value);
            } else {
                delete_post_meta($post_id, $key);
            }
        }

        // Set additional meta fields
        update_post_meta($post_id, 'fave_featured', 0); // Not featured by default
        update_post_meta($post_id, 'fave_property_map', 1); // Show map by default
        update_post_meta($post_id, 'fave_property_map_street_view', 'show'); // Show street view

        return $meta_fields;
    }

    /**
     * Set property taxonomies
     */
    private function set_property_taxonomies($post_id, $category_key)
    {
        // Set property type based on category mapping
        $property_type = intval($this->options['category_mapping'][$category_key] ?? 0);
        if ($property_type > 0) {
            $type_message = "[MAPPER] wp_set_post_terms(type) post={$post_id} term={$property_type}";
            // $this->logger->info($type_message);
            // error_log($type_message);
            wp_set_post_terms($post_id, array($property_type), 'property_type', false);
        }

        // Set property status based on category mapping
        $property_status = intval($this->options['category_status_mapping'][$category_key] ?? 0);
        if ($property_status > 0) {
            $status_message = "[MAPPER] wp_set_post_terms(status) post={$post_id} term={$property_status}";
            // $this->logger->info($status_message);
            // error_log($status_message);
            wp_set_post_terms($post_id, array($property_status), 'property_status', false);
        }
    }

    /**
     * Handle property images
     */
    private function handle_property_images($post_id, $images)
    {
        if (empty($images)) {
            return;
        }

        // Skip if all available images are placeholders (single or multiple duplicates)
        $placeholder_count = 0;
        foreach ($images as $image_url) {
            if (stripos($image_url, 'placeholder') !== false) {
                $placeholder_count++;
            }
        }

        if ($placeholder_count > 0 && $placeholder_count === count($images)) {
            // All images are placeholders - let the site use its own placeholder
            return;
        }

        // Remove duplicate URLs before processing
        $images = array_values(array_unique($images));

        $uploaded_images = array();
        $upload_dir = wp_upload_dir();

        foreach ($images as $image_url) {
            try {
                // Check if image already exists in media library by URL
                $existing_attachment_id = $this->get_attachment_id_by_url($image_url);

                if ($existing_attachment_id) {
                    $image_id = $existing_attachment_id;
                } else {
                    $image_id = $this->download_and_attach_image($image_url, $post_id);
                    if ($image_id) {
                        update_post_meta($image_id, '_real_estate_scraper_original_image_url', $image_url);
                    }
                }

                if ($image_id) {
                    $uploaded_images[] = $image_id;
                }
            } catch (Exception $e) {
                // $this->logger->warning("Failed to download image {$image_url}: " . $e->getMessage());
            }
        }

        if (!empty($uploaded_images)) {
            // Set first image as featured image
            set_post_thumbnail($post_id, $uploaded_images[0]);

            // Delete any existing fave_property_images meta
            delete_post_meta($post_id, 'fave_property_images');

            // Add each image ID individually for Houzez gallery
            foreach ($uploaded_images as $image_id) {
                add_post_meta($post_id, 'fave_property_images', $image_id);
            }
        }
    }

    /**
     * Download and attach image
     */
    private function download_and_attach_image($image_url, $post_id)
    {
        // Download image
        $response = wp_remote_get($image_url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ));

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            throw new Exception('Empty image data');
        }

        // Get file extension
        $file_extension = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($file_extension)) {
            $file_extension = 'jpg'; // Default
        }

        // Generate filename
        $filename = sanitize_file_name($post_id . '_' . uniqid() . '.' . $file_extension);
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['path'] . '/' . $filename;

        // Save image
        file_put_contents($file_path, $image_data);

        // Create attachment
        $attachment = array(
            'post_mime_type' => wp_check_filetype($filename)['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);

        if (is_wp_error($attach_id)) {
            unlink($file_path); // Clean up
            throw new Exception('Failed to create attachment: ' . $attach_id->get_error_message());
        }

        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Get attachment ID by original image URL
     */
    private function get_attachment_id_by_url($image_url)
    {
        $args = array(
            'post_type' => 'attachment',
            'meta_query' => array(
                array(
                    'key' => '_real_estate_scraper_original_image_url',
                    'value' => $image_url,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids',
        );

        $attachments = get_posts($args);

        if (!empty($attachments)) {
            return $attachments[0];
        }

        return false;
    }

    /**
     * Get or create taxonomy term
     */
    private function get_or_create_term($taxonomy, $term_name)
    {
        $term = get_term_by('name', $term_name, $taxonomy);

        if (!$term) {
            $result = wp_insert_term($term_name, $taxonomy);
            if (!is_wp_error($result)) {
                $term = get_term($result['term_id'], $taxonomy);
            }
        }

        return $term;
    }

    /**
     * Clean title
     */
    private function clean_title($title)
    {
        $title = trim($title);
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = wp_strip_all_tags($title);
        $title = sanitize_text_field($title);

        return $title;
    }

    /**
     * Clean content
     */
    private function clean_content($content)
    {
        $content = trim($content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = wp_kses_post($content);

        return $content;
    }

    /**
     * Clean price
     */
    private function clean_price($price)
    {
        return $this->normalize_numeric_value($price, false);
    }

    /**
     * Clean size
     */
    private function clean_size($size)
    {
        return $this->normalize_numeric_value($size, true);
    }

    /**
     * Clean number
     */
    private function clean_number($number)
    {
        return $this->normalize_numeric_value($number, false);
    }

    /**
     * Normalize numeric string (remove units/currency)
     */
    private function normalize_numeric_value($value, $allow_decimal = true)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (!preg_match('/\d+(?:[.,]\d+)?/', $value, $matches)) {
            return '';
        }

        $number = $matches[0];
        $number = str_replace(' ', '', $number);

        if ($allow_decimal) {
            $number = str_replace(',', '.', $number);
            $last_dot = strrpos($number, '.');

            if ($last_dot !== false) {
                $integer_part = substr($number, 0, $last_dot);
                $decimal_part = substr($number, $last_dot + 1);
                $integer_part = str_replace('.', '', $integer_part);
                $decimal_part = str_replace('.', '', $decimal_part);
                $number = $integer_part . '.' . $decimal_part;
            } else {
                $number = str_replace('.', '', $number);
            }
        } else {
            $number = str_replace(array('.', ','), '', $number);
            $number = preg_replace('/[^\d]/', '', $number);
        }

        return $number;
    }

    /**
     * Clean address
     */
    private function clean_address($address)
    {
        $address = trim($address);
        $address = html_entity_decode($address, ENT_QUOTES, 'UTF-8');
        $address = wp_strip_all_tags($address);
        $address = sanitize_text_field($address);

        return $address;
    }

    /**
     * Clean coordinate (latitude or longitude)
     */
    private function clean_coordinate($coordinate)
    {
        $coordinate = trim($coordinate);
        // Allow digits, decimal point, and negative sign
        $coordinate = preg_replace('/[^\d.-]/', '', $coordinate);
        // Ensure it looks like a valid coordinate, e.g., no multiple decimal points or invalid chars
        if (is_numeric($coordinate)) {
            return (string) floatval($coordinate);
        }
        return '';
    }

    /**
     * Get geocoded address for saving to Houzez
     */
    private function get_geocoded_address_for_save($property_data)
    {
        // If we have geocoded address, format it properly
        if (isset($property_data['geocoded_address']) && !empty($property_data['geocoded_address'])) {
            $address = $property_data['geocoded_address'];
            $formatted_address = $this->format_address_components($address);

            if (!empty($formatted_address)) {
                return $this->clean_address($formatted_address);
            }
        }

        // Fallback to original address
        return $this->clean_address($property_data['address']);
    }

    /**
     * Format address components for main address field (without city and country)
     */
    private function format_address_components($address)
    {
        $components = array();

        // Extract components
        $street = trim($address['street'] ?? '');
        $house_number = trim($address['house_number'] ?? '');
        $postal_code = trim($address['postal_code'] ?? '');

        // Extract sector from display_name if available (for Bucharest)
        $sector = '';
        if (!empty($address['display_name'])) {
            if (preg_match('/Sector (\d+)/', $address['display_name'], $matches)) {
                $sector = 'Sector ' . $matches[1];
            }
        }

        // Build address in desired order: Street, Number, Sector, Postal Code (NO CITY, NO COUNTRY)

        // 1. Street
        if (!empty($street)) {
            $components[] = $street;
        }

        // 2. House number
        if (!empty($house_number)) {
            $components[] = $house_number;
        }

        // 3. Sector
        if (!empty($sector)) {
            $components[] = $sector;
        }

        // 4. Postal code
        if (!empty($postal_code)) {
            $components[] = $postal_code;
        }

        // Join components with comma and space
        return implode(', ', $components);
    }

    /**
     * Save dynamic specifications as Additional Features
     */
    private function save_dynamic_specifications($post_id, $specifications)
    {
        if (empty($specifications)) {
            return;
        }

        $additional_features = array();

        foreach ($specifications as $attribute => $value) {
            if (empty(trim($value))) {
                continue;
            }

            $additional_features[] = array(
                'fave_additional_feature_title' => trim($attribute),
                'fave_additional_feature_value' => trim($value)
            );
        }

        if (!empty($additional_features)) {
            update_post_meta($post_id, 'additional_features', $additional_features);
        }
    }

    /**
     * Set location taxonomies from geocoded address
     */
    private function set_location_taxonomies($post_id, $property_data)
    {
        if (!isset($property_data['geocoded_address']) || empty($property_data['geocoded_address'])) {
            return;
        }

        $address = $property_data['geocoded_address'];
        $state_term = null;
        $city_term = null;

        // Set Country taxonomy
        if (!empty($address['country'])) {
            $country_name = $this->clean_taxonomy_name($address['country']);
            $country_term = $this->get_or_create_term('property_country', $country_name);
            if ($country_term) {
                wp_set_object_terms($post_id, array($country_term->term_id), 'property_country');
            }
        }

        // Set City taxonomy first (needed for special cases like Bucharest)
        $city_name = '';
        if (!empty($address['city'])) {
            $city_name = $this->clean_taxonomy_name($address['city']);
            $city_term = $this->get_or_create_term('property_city', $city_name);
            if ($city_term) {
                wp_set_object_terms($post_id, array($city_term->term_id), 'property_city');
            }
        }

        // Set State/County taxonomy
        $state_name = '';

        // First, try to get county from geocoded address
        if (!empty($address['county'])) {
            $state_name = $this->clean_taxonomy_name($address['county']);
        }

        // Special handling for Bucharest: if city is Bucharest but county is missing, set county to Bucharest
        if (empty($state_name) && !empty($city_name)) {
            // Check if city is Bucharest (handle different spellings)
            $city_lower = mb_strtolower($city_name, 'UTF-8');
            if (in_array($city_lower, array('bucurești', 'bucuresti', 'bucharest'))) {
                $state_name = 'București'; // Set county to Bucharest if city is Bucharest
            }
        }

        // Always set State if we have a state_name (either from county or from special case)
        if (!empty($state_name)) {
            $state_term = $this->get_or_create_term('property_state', $state_name);
            if ($state_term) {
                wp_set_object_terms($post_id, array($state_term->term_id), 'property_state');
            }
        }

        // Set parent_state relationship for City (required by Houzez theme)
        // This allows State selection to work when editing properties
        if ($city_term && $state_term && !empty($state_term->slug)) {
            update_option('_houzez_property_city_' . $city_term->term_id, array(
                'parent_state' => $state_term->slug
            ));
        }
    }

    /**
     * Clean taxonomy name for WordPress
     */
    private function clean_taxonomy_name($name)
    {
        $name = trim($name);
        $name = sanitize_text_field($name);
        return $name;
    }

    /**
     * Fix missing parent_state relationships for existing properties
     * This function updates all cities that don't have parent_state set
     * by finding properties with both city and state and setting the relationship
     */
    public function fix_missing_parent_state_relationships()
    {
        $updated_count = 0;
        $errors = array();

        // Get all properties
        $properties = get_posts(array(
            'post_type' => 'property',
            'posts_per_page' => -1,
            'post_status' => array('publish', 'draft', 'private', 'pending')
        ));

        foreach ($properties as $property) {
            // Get city terms for this property
            $city_terms = wp_get_post_terms($property->ID, 'property_city', array('fields' => 'all'));

            if (empty($city_terms) || is_wp_error($city_terms)) {
                continue;
            }

            // Get state terms for this property
            $state_terms = wp_get_post_terms($property->ID, 'property_state', array('fields' => 'all'));

            if (empty($state_terms) || is_wp_error($state_terms)) {
                // Special case: if city is Bucharest but no state, set state to Bucharest
                foreach ($city_terms as $city_term) {
                    $city_name_lower = mb_strtolower($city_term->name, 'UTF-8');
                    if (in_array($city_name_lower, array('bucurești', 'bucuresti', 'bucharest'))) {
                        $state_term = $this->get_or_create_term('property_state', 'București');
                        if ($state_term) {
                            wp_set_object_terms($property->ID, array($state_term->term_id), 'property_state', true);
                            $state_terms = array($state_term);
                        }
                    }
                }

                if (empty($state_terms) || is_wp_error($state_terms)) {
                    continue;
                }
            }

            // For each city, check if parent_state is set
            foreach ($city_terms as $city_term) {
                $city_meta = get_option('_houzez_property_city_' . $city_term->term_id);
                $has_parent_state = !empty($city_meta['parent_state']);

                if (!$has_parent_state) {
                    // Use the first state term found for this property
                    $state_term = $state_terms[0];

                    if ($state_term && !empty($state_term->slug)) {
                        update_option('_houzez_property_city_' . $city_term->term_id, array(
                            'parent_state' => $state_term->slug
                        ));
                        $updated_count++;
                    }
                }
            }
        }

        return array(
            'success' => true,
            'updated' => $updated_count,
            'errors' => $errors
        );
    }


}
