<?php

/**
 * Scraper class for Real Estate Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Scraper
{
    private static $instance = null;
    private $logger;
    private $mapper;
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
        error_log('RES DEBUG - Scraper constructor called at: ' . current_time('mysql'));
        $this->logger = Real_Estate_Scraper_Logger::get_instance();
        error_log('RES DEBUG - Logger instance created');
        $this->mapper = Real_Estate_Scraper_Mapper::get_instance();
        error_log('RES DEBUG - Mapper instance created');
        $this->options = get_option('real_estate_scraper_options', array());
        error_log('RES DEBUG - Options loaded in constructor: ' . var_export($this->options, true));
        $this->logger->debug('SCRAPER DEBUG - Scraper constructor options: ' . var_export($this->options, true));
        error_log('RES DEBUG - Scraper constructor completed');
    }

    /**
     * Run the scraper
     */
    public function run_scraper()
    {
        error_log('RES DEBUG - run_scraper() method called at: ' . current_time('mysql'));
        $start_time = microtime(true);
        error_log('RES DEBUG - run_scraper() options: ' . var_export($this->options, true));
        error_log('SCRAPER DEBUG - Running scraper. Current options: ' . var_export($this->options, true));

        try {
            error_log('RES DEBUG - About to log scraper start');
            error_log('=== SCRAPER STARTED ===');
            error_log('Categories to scrape: ' . implode(', ', array_keys($this->options['category_urls'])));
            error_log('Properties to check per category: ' . $this->options['properties_to_check']);
            error_log('RES DEBUG - Scraper start logged successfully');

            $stats = array(
                'total_found' => 0,
                'new_added' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0
            );

            // Global limit for total ads processed across all categories
            $max_ads_global = $this->options['max_ads_per_session'] ?? 4;
            $ads_processed_global = 0;

            // --- TEMPORARY TEST: Process single property directly ---
            // $test_url = RES_SCRAPER_CONFIG['single_property_test_url'];
            // if (!empty($test_url)) {
            //     $this->logger->info("--- TEMPORARY TEST: Processing single property: {$test_url} ---");
            //     $result = $this->process_property($test_url, 'test_category'); // Use a dummy category key
            //     if ($result['success']) {
            //         $stats['total_found'] = 1;
            //         if ($result['is_new']) {
            //             $stats['new_added'] = 1;
            //         } else {
            //             $stats['duplicates_skipped'] = 1;
            //         }
            //     } else {
            //         $stats['errors'] = 1;
            //     }
            // } else {
            // --- NEW LOGIC: Rotate through categories ---
            error_log('SCRAPER DEBUG - Category URLs before rotation: ' . var_export($this->options['category_urls'], true));
            error_log('RES DEBUG - Starting category rotation...');

            // Get valid categories (non-empty URLs)
            $valid_categories = array();
            foreach ($this->options['category_urls'] as $category_key => $url) {
                if (!empty($url)) {
                    $valid_categories[$category_key] = $url;
                }
            }

            if (empty($valid_categories)) {
                error_log('RES DEBUG - No valid categories found');
                return array(
                    'success' => false,
                    'message' => __('No valid category URLs configured.', 'real-estate-scraper'),
                    'stats' => $stats
                );
            }

            // NEW LOGIC: Determine next category based on last added property
            $next_category = $this->get_next_category_for_rotation();
            error_log("RES DEBUG - Next category to process: {$next_category}");

            if (!empty($next_category) && isset($valid_categories[$next_category])) {
                $url = $valid_categories[$next_category];
                error_log("RES DEBUG - Processing category: {$next_category}, URL: {$url}");

                // Process one property from this category
                $category_stats = $this->process_category_rotation($next_category, $url, $max_ads_global, $ads_processed_global);

                $stats['total_found'] += $category_stats['found'];
                $stats['new_added'] += $category_stats['new'];
                $stats['duplicates_skipped'] += $category_stats['duplicates'];
                $stats['errors'] += $category_stats['errors'];
            } else {
                error_log("RES DEBUG - No valid next category found or category not in valid categories");
            }
            // }
            // --- END TEMPORARY TEST ---

            $execution_time = round(microtime(true) - $start_time, 2);
            $stats['execution_time'] = $execution_time;

            $this->logger->log_scraper_end($stats);

            return array(
                'success' => true,
                'message' => __('Scraper completed successfully.', 'real-estate-scraper'),
                'stats' => $stats
            );

        } catch (Exception $e) {
            $this->logger->error('Scraper failed: ' . $e->getMessage());

            return array(
                'success' => false,
                'message' => __('Scraper failed: ', 'real-estate-scraper') . $e->getMessage(),
                'stats' => array()
            );
        }
    }

    /**
     * Process one property from a category (for rotation)
     */
    private function process_category_rotation($category_key, $url, $max_ads_global = 0, $ads_processed_global = 0)
    {
        error_log("RES DEBUG - process_category_rotation() called for: {$category_key}, URL: {$url}");
        error_log("--- Processing one property from category: {$category_key} ---");

        $stats = array(
            'found' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => 0
        );

        try {
            // Get property URLs from category page
            $property_urls = $this->get_property_urls_from_category($url);
            error_log('RES DEBUG - get_property_urls_from_category returned: ' . count($property_urls) . ' URLs');

            if (empty($property_urls)) {
                error_log("RES DEBUG - No properties found in category {$category_key}");
                return $stats;
            }

            // Process only the first property from this category
            $property_url = $property_urls[0];
            error_log("RES DEBUG - Processing first property from category {$category_key}: {$property_url}");

            $result = $this->process_property($property_url, $category_key);

            if ($result['success']) {
                $stats['found'] = 1;
                if ($result['is_new']) {
                    $stats['new'] = 1;
                    error_log("RES DEBUG - New property added from category {$category_key}");
                } else {
                    $stats['duplicates'] = 1;
                    error_log("RES DEBUG - Duplicate property found in category {$category_key}");
                }
            } else {
                $stats['errors'] = 1;
                error_log("RES DEBUG - Error processing property from category {$category_key}: " . $result['message']);
            }

        } catch (Exception $e) {
            $this->logger->error("Error processing category {$category_key}: " . $e->getMessage());
            $stats['errors'] = 1;
        }

        return $stats;
    }

    /**
     * Process a single category (original method - kept for compatibility)
     */
    private function process_category($category_key, $url, $max_ads_global = 0, $ads_processed_global = 0)
    {
        error_log("RES DEBUG - process_category() called for: {$category_key}, URL: {$url}");
        error_log("--- Processing category: {$category_key} ---");
        error_log("URL: {$url}");

        $stats = array(
            'found' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => 0
        );

        try {
            error_log('RES DEBUG - About to get property URLs from category page');
            // Get property URLs from category page
            $property_urls = $this->get_property_urls_from_category($url);
            error_log('RES DEBUG - get_property_urls_from_category returned: ' . count($property_urls) . ' URLs');

            // Limit the number of properties to process based on global limit
            $remaining_ads = $max_ads_global - $ads_processed_global;
            if ($max_ads_global > 0 && $remaining_ads > 0) {
                if (count($property_urls) > $remaining_ads) {
                    error_log("RES DEBUG - Limiting processing to {$remaining_ads} properties out of " . count($property_urls) . " found for category {$category_key} (global limit: {$max_ads_global}, already processed: {$ads_processed_global})");
                    $property_urls = array_slice($property_urls, 0, $remaining_ads);
                }
            } elseif ($max_ads_global > 0 && $remaining_ads <= 0) {
                error_log("RES DEBUG - Global limit reached, skipping category {$category_key}");
                $property_urls = array();
            }

            $stats['found'] = count($property_urls);

            $this->logger->info("Found " . count($property_urls) . " properties in category {$category_key}");

            // Process each property
            foreach ($property_urls as $property_url) {
                $result = $this->process_property($property_url, $category_key);

                if ($result['success']) {
                    if ($result['is_new']) {
                        $stats['new']++;
                    } else {
                        $stats['duplicates']++;
                    }
                } else {
                    $stats['errors']++;
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Error processing category {$category_key}: " . $e->getMessage());
            $stats['errors']++;
        }

        $this->logger->log_category_end($category_key, $stats);

        return $stats;
    }

    /**
     * Get property URLs from category page
     */
    private function get_property_urls_from_category($category_url)
    {
        error_log("RES DEBUG - get_property_urls_from_category() called for: {$category_url}");
        $max_attempts = $this->options['retry_attempts'];
        $retry_interval = RES_SCRAPER_CONFIG['retry_interval']; // Use retry_interval from constants.php
        error_log("RES DEBUG - Max attempts: {$max_attempts}, Retry interval: {$retry_interval}");

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                error_log("RES DEBUG - Fetching category page: {$category_url} (attempt {$attempt})");

                $response = wp_remote_get($category_url, array(
                    'timeout' => 30,
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ));

                if (is_wp_error($response)) {
                    error_log('RES DEBUG - wp_remote_get returned WP_Error: ' . $response->get_error_message());
                    throw new Exception($response->get_error_message());
                }

                error_log('RES DEBUG - wp_remote_get successful, response code: ' . wp_remote_retrieve_response_code($response));
                $body = wp_remote_retrieve_body($response);
                error_log('RES DEBUG - Response body length: ' . strlen($body) . ' bytes');

                if (empty($body)) {
                    error_log('RES DEBUG - Response body is empty!');
                    throw new Exception('Empty response body');
                }

                // Parse HTML to extract property URLs
                error_log('RES DEBUG - About to parse property URLs from HTML');
                $property_urls = $this->parse_property_urls_from_html($body);
                error_log('RES DEBUG - parse_property_urls_from_html returned: ' . count($property_urls) . ' URLs');

                // Limit to configured number
                $limit = $this->options['properties_to_check'];
                $property_urls = array_slice($property_urls, 0, $limit);

                $this->logger->info("Successfully extracted " . count($property_urls) . " property URLs");

                return $property_urls;

            } catch (Exception $e) {
                $this->logger->log_error_with_retry($e->getMessage(), $attempt, $max_attempts);

                if ($attempt < $max_attempts) {
                    // TEMPORARILY DISABLED: sleep($retry_interval);
                    error_log('RES DEBUG - Retry sleep disabled for testing');
                } else {
                    $this->logger->log_final_error($e->getMessage());
                    throw $e;
                }
            }
        }

        return array();
    }

    /**
     * Parse property URLs from HTML content
     */
    private function parse_property_urls_from_html($html)
    {
        $property_urls = array();

        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);


        // Look for property links - this will need to be adjusted based on actual HTML structure
        // Use XPath from constants file
        $links = $xpath->query(RES_SCRAPER_CONFIG['property_list_urls_xpath']);

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            // Convert relative URLs to absolute
            if (strpos($href, 'http') !== 0) {
                $href = RES_SCRAPER_CONFIG['base_url_for_relative_links'] . $href; // Use base_url from constants
            }

            if (!in_array($href, $property_urls)) {
                $property_urls[] = $href;
            }
        }

        return $property_urls;
    }

    /**
     * Process a single property
     */
    private function process_property($property_url, $category_key)
    {
        $this->logger->log_property_start($property_url);

        try {
            // Check if property already exists
            if ($this->is_duplicate($property_url)) {
                $this->logger->log_duplicate_found($property_url, 'existing');
                return array('success' => true, 'is_new' => false);
            }

            // Fetch property data
            $property_data = $this->fetch_property_data($property_url);

            if (empty($property_data)) {
                throw new Exception('Failed to fetch property data');
            }

            $this->logger->log_property_data($property_data);

            // Map and create WordPress post
            $post_id = $this->mapper->create_property_post($property_data, $category_key);

            if ($post_id) {
                $this->logger->log_property_created($post_id, $property_data['title']);
                return array('success' => true, 'is_new' => true, 'post_id' => $post_id);
            } else {
                throw new Exception('Failed to create property post');
            }

        } catch (Exception $e) {
            $this->logger->error("Error processing property {$property_url}: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Check if property is duplicate
     */
    private function is_duplicate($property_url)
    {
        // Search for existing posts with this URL in meta
        $existing_posts = get_posts(array(
            'post_type' => 'property',
            'meta_key' => 'fave_property_source_url',
            'meta_value' => $property_url,
            'posts_per_page' => 1,
            'post_status' => array('publish', 'draft', 'private')
        ));

        return !empty($existing_posts);
    }

    /**
     * Fetch property data from URL
     */
    private function fetch_property_data($property_url)
    {
        $max_attempts = $this->options['retry_attempts'];
        $retry_interval = $this->options['retry_interval'];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $this->logger->debug("Fetching property data: {$property_url} (attempt {$attempt})");

                $response = wp_remote_get($property_url, array(
                    'timeout' => 30,
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ));

                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }

                $body = wp_remote_retrieve_body($response);
                if (empty($body)) {
                    throw new Exception('Empty response body');
                }

                // Parse property data from HTML
                $property_data = $this->parse_property_data_from_html($body, $property_url);

                $this->logger->info("Successfully parsed property data");

                return $property_data;

            } catch (Exception $e) {
                $this->logger->log_error_with_retry($e->getMessage(), $attempt, $max_attempts);

                if ($attempt < $max_attempts) {
                    // TEMPORARILY DISABLED: sleep($retry_interval);
                    error_log('RES DEBUG - Retry sleep disabled for testing (fetch_property_data)');
                } else {
                    $this->logger->log_final_error($e->getMessage());
                    throw $e;
                }
            }
        }

        return array();
    }

    /**
     * Parse property data from HTML content
     */
    private function parse_property_data_from_html($html, $source_url)
    {
        $property_data = array(
            'title' => '',
            'content' => '',
            'price' => '',
            'size' => '',
            'bedrooms' => '',
            'bathrooms' => '',
            'address' => '',
            'latitude' => '', // Added for latitude
            'longitude' => '', // Added for longitude
            'phone_number' => '', // NEW: Added for phone number
            'images' => array(),
            'source_url' => $source_url
        );

        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Extract title
        $title_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['title_xpath']);
        if ($title_nodes->length > 0) {
            $property_data['title'] = trim($title_nodes->item(0)->textContent);
        }

        // Extract price
        $price_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['price_xpath']);
        foreach ($price_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/[\d.,]+\s*[€EUR]/', $text)) {
                $property_data['price'] = $text;
                break;
            }
        }

        // Extract size
        $size_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['size_xpath']);
        foreach ($size_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/[\d.,]+\s*(mp|m²|sq)/i', $text)) {
                $property_data['size'] = $text;
                break;
            }
        }

        // Extract bedrooms (commented out - XPath not defined)
        // $bedroom_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['bedrooms_xpath']);
        // foreach ($bedroom_nodes as $node) {
        //     $text = trim($node->textContent);
        //     if (preg_match('/(\d+)\s*(dormitor|cameră|room)/i', $text, $matches)) {
        //         $property_data['bedrooms'] = $matches[1];
        //         break;
        //     }
        // }

        // Extract bathrooms (commented out - XPath not defined)
        // $bathroom_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['bathrooms_xpath']);
        // foreach ($bathroom_nodes as $node) {
        //     $text = trim($node->textContent);
        //     if (preg_match('/(\d+)\s*(baie|bathroom)/i', $text, $matches)) {
        //         $property_data['bathrooms'] = $matches[1];
        //         break;
        //     }
        // }

        // Extract address
        $address_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['address_xpath']);
        foreach ($address_nodes as $node) {
            $text = trim($node->textContent);
            if (strlen($text) > 10 && strlen($text) < 200) {
                $property_data['address'] = $text;
                break;
            }
        }

        // Extract latitude
        $latitude_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['latitude_xpath']);
        if ($latitude_nodes->length > 0) {
            $property_data['latitude'] = trim($latitude_nodes->item(0)->textContent);
        }

        // Extract longitude
        $longitude_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['longitude_xpath']);
        if ($longitude_nodes->length > 0) {
            $property_data['longitude'] = trim($longitude_nodes->item(0)->textContent);
        }

        // Extract phone number using existing XPath from constants.php
        $phone_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['phone_xpath']);
        if ($phone_nodes->length > 0) {
            $phone_number = trim($phone_nodes->item(0)->textContent);
            $property_data['phone_number'] = $phone_number;
            error_log('RES DEBUG - PHONE NUMBER EXTRACTED: ' . $phone_number);
        } else {
            error_log('RES DEBUG - No phone number found using XPath: ' . RES_SCRAPER_CONFIG['property_data']['phone_xpath']);
        }

        // Extract images
        $image_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['images_xpath']);
        foreach ($image_nodes as $node) {
            $src = $node->getAttribute('src');

            // Convert relative URLs to absolute
            if (strpos($src, 'http') !== 0) {
                $src = RES_SCRAPER_CONFIG['base_url_for_relative_links'] . $src; // Use base_url from constants
            }

            // Filter out small images and icons
            if (strlen($src) > 20 && !in_array(basename($src), array('logo.png', 'icon.png'))) {
                $property_data['images'][] = $src;
            }
        }

        // Extract content/description
        $content_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['content_xpath']);
        foreach ($content_nodes as $node) {
            $text = trim($node->textContent);
            if (strlen($text) > 50) {
                $property_data['content'] = $text;
                break;
            }
        }

        // Extract specifications
        $property_data['specifications'] = $this->extract_specifications($xpath);

        // Get geocoded address if we have coordinates
        if (!empty($property_data['latitude']) && !empty($property_data['longitude'])) {
            $property_data['geocoded_address'] = $this->get_geocoded_address($property_data['latitude'], $property_data['longitude']);
        }

        return $property_data;
    }

    /**
     * Extract specifications from property page
     */
    private function extract_specifications($xpath)
    {
        error_log('RES DEBUG - === EXTRACTING SPECIFICATIONS ===');

        $specifications = array();
        $spec_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['specifications_xpath']);
        error_log('RES DEBUG - Found ' . $spec_nodes->length . ' specification nodes');

        if ($spec_nodes->length > 0) {
            foreach ($spec_nodes as $node) {
                $spans = $xpath->query('.//span', $node);
                if ($spans->length >= 2) {
                    $attribute = trim($spans->item(0)->textContent);
                    $value = trim($spans->item(1)->textContent);

                    // Add to specifications array
                    $specifications[$attribute] = $value;
                    error_log('RES DEBUG - SPECIFICATION: "' . $attribute . '" = "' . $value . '"');
                }
            }
        } else {
            error_log('RES DEBUG - No specifications found');
        }

        error_log('RES DEBUG - === SPECIFICATIONS EXTRACTION COMPLETE - Found ' . count($specifications) . ' specifications ===');
        return $specifications;
    }

    /**
     * Get geocoded address from coordinates
     */
    private function get_geocoded_address($latitude, $longitude)
    {
        error_log('RES DEBUG - === GETTING GEOCODED ADDRESS ===');
        error_log('RES DEBUG - Coordinates: lat=' . $latitude . ', lon=' . $longitude);

        $geocoder = Real_Estate_Scraper_Geocoder::get_instance();
        $address = $geocoder->reverse_geocode($latitude, $longitude);

        if ($address) {
            error_log('RES DEBUG - Geocoding successful!');
            error_log('RES DEBUG - Display Name: ' . $address['display_name']);
            error_log('RES DEBUG - City: ' . $address['city']);
            error_log('RES DEBUG - Street: ' . $address['street']);
            error_log('RES DEBUG - House Number: ' . $address['house_number']);
            error_log('RES DEBUG - Postal Code: ' . $address['postal_code']);
            error_log('RES DEBUG - Country: ' . $address['country']);
            error_log('RES DEBUG - County: ' . $address['county']);
            error_log('RES DEBUG - === GEOCODING COMPLETE ===');
            return $address;
        } else {
            error_log('RES DEBUG - Geocoding failed!');
            error_log('RES DEBUG - === GEOCODING COMPLETE ===');
            return null;
        }
    }

    /**
     * Get next category for rotation based on last added property
     */
    private function get_next_category_for_rotation()
    {
        error_log('RES DEBUG - === DETERMINING NEXT CATEGORY FOR ROTATION ===');

        // Define category order
        $category_order = array('apartamente', 'garsoniere', 'case_vile', 'spatii_comerciale');

        // Get last added property
        $last_property = $this->get_last_added_property();

        if (!$last_property) {
            error_log('RES DEBUG - No properties found, starting with first category: apartamente');
            return 'apartamente';
        }

        // Get category of last property
        $last_category = $this->get_property_category($last_property);

        if (!$last_category) {
            error_log('RES DEBUG - Could not determine category of last property, starting with first category: apartamente');
            return 'apartamente';
        }

        error_log('RES DEBUG - Last property category: ' . $last_category);

        // Find next category in rotation
        $current_index = array_search($last_category, $category_order);
        if ($current_index === false) {
            error_log('RES DEBUG - Last category not found in order, starting with first category: apartamente');
            return 'apartamente';
        }

        $next_index = ($current_index + 1) % count($category_order);
        $next_category = $category_order[$next_index];

        error_log('RES DEBUG - Next category in rotation: ' . $next_category);
        return $next_category;
    }

    /**
     * Get last added property from database
     */
    private function get_last_added_property()
    {
        $last_property = get_posts(array(
            'post_type' => 'property',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => 'fave_property_source_url',
                    'compare' => 'EXISTS'
                )
            )
        ));

        if (!empty($last_property)) {
            error_log('RES DEBUG - Last property found: ID ' . $last_property[0]->ID . ', Title: ' . $last_property[0]->post_title);
            return $last_property[0];
        }

        error_log('RES DEBUG - No properties found in database');
        return null;
    }

    /**
     * Get category of a property based on its source URL
     */
    private function get_property_category($property)
    {
        $source_url = get_post_meta($property->ID, 'fave_property_source_url', true);

        if (empty($source_url)) {
            error_log('RES DEBUG - Property has no source URL');
            return null;
        }

        error_log('RES DEBUG - Property source URL: ' . $source_url);

        // Check which category URL this property belongs to
        error_log('RES DEBUG - Available category URLs: ' . var_export($this->options['category_urls'], true));
        foreach ($this->options['category_urls'] as $category_key => $category_url) {
            error_log('RES DEBUG - Checking if source URL contains: ' . $category_url);
            if (strpos($source_url, $category_url) !== false) {
                error_log('RES DEBUG - Property belongs to category: ' . $category_key);
                return $category_key;
            }
        }

        error_log('RES DEBUG - Could not determine property category from URL');
        return null;
    }

}
