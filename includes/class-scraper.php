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
        $this->logger = Real_Estate_Scraper_Logger::get_instance();
        $this->mapper = Real_Estate_Scraper_Mapper::get_instance();
        $this->options = get_option('real_estate_scraper_options', array());
    }

    /**
     * Run the scraper
     */
    public function run_scraper()
    {
        $start_time = microtime(true);

        try {
            error_log('=== SCRAPER STARTED ===');
            error_log('Categories: ' . implode(', ', array_keys($this->options['category_urls'])));

            $stats = array(
                'total_found' => 0,
                'new_added' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0
            );

            // Global limit for total ads processed across all categories
            $max_ads_global = $this->options['max_ads_per_session'] ?? 4;
            $ads_processed_global = 0;

            // Get valid categories
            $valid_categories = array();
            foreach ($this->options['category_urls'] as $category_key => $url) {
                if (!empty($url)) {
                    $valid_categories[$category_key] = $url;
                }
            }

            if (empty($valid_categories)) {
                return array(
                    'success' => false,
                    'message' => __('No valid category URLs configured.', 'real-estate-scraper'),
                    'stats' => $stats
                );
            }

            // Process categories in rotation
            $category_index = 0;
            $category_keys = array_keys($valid_categories);

            while ($max_ads_global == 0 || $ads_processed_global < $max_ads_global) {
                // Determine next category
                if ($category_index == 0) {
                    $next_category = $this->get_next_category_for_rotation();
                    error_log("[ROTATION] Start: {$next_category}");
                } else {
                    $next_category = $category_keys[$category_index % count($category_keys)];
                }

                if (!empty($next_category) && isset($valid_categories[$next_category])) {
                    $url = $valid_categories[$next_category];

                    // Process one property from this category
                    $category_stats = $this->process_category_rotation($next_category, $url, $max_ads_global, $ads_processed_global);

                    $stats['total_found'] += $category_stats['found'];
                    $stats['new_added'] += $category_stats['new'];
                    $stats['duplicates_skipped'] += $category_stats['duplicates'];
                    $stats['errors'] += $category_stats['errors'];

                    $ads_processed_global += $category_stats['found'];
                } else {
                    break;
                }

                $category_index++;

                if ($category_index >= count($category_keys) && $stats['new_added'] == 0) {
                    break;
                }
            }

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
        $stats = array(
            'found' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => 0
        );

        try {
            // Get property URLs from category page
            $property_urls = $this->get_property_urls_from_category($url);
            error_log("[CATEGORY] {$category_key} ({$url}) → Found " . count($property_urls) . " ads");

            if (empty($property_urls)) {
                return $stats;
            }

            // Process first property
            $property_url = $property_urls[0];
            $result = $this->process_property($property_url, $category_key);

            if ($result['success']) {
                $stats['found'] = 1;
                if ($result['is_new']) {
                    $stats['new'] = 1;
                } else {
                    $stats['duplicates'] = 1;
                }
            } else {
                $stats['errors'] = 1;
            }

        } catch (Exception $e) {
            $this->logger->error("[ERROR] Category {$category_key}: " . $e->getMessage());
            $stats['errors'] = 1;
        }

        return $stats;
    }

    /**
     * Process a single category (original method - kept for compatibility)
     */
    private function process_category($category_key, $url, $max_ads_global = 0, $ads_processed_global = 0)
    {
        $stats = array(
            'found' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => 0
        );

        try {
            $property_urls = $this->get_property_urls_from_category($url);

            // Limit based on global limit
            $remaining_ads = $max_ads_global - $ads_processed_global;
            if ($max_ads_global > 0 && $remaining_ads > 0) {
                if (count($property_urls) > $remaining_ads) {
                    $property_urls = array_slice($property_urls, 0, $remaining_ads);
                }
            } elseif ($max_ads_global > 0 && $remaining_ads <= 0) {
                $property_urls = array();
            }

            $stats['found'] = count($property_urls);

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
            $this->logger->error("[ERROR] Category {$category_key}: " . $e->getMessage());
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
        $max_attempts = $this->options['retry_attempts'];
        $retry_interval = RES_SCRAPER_CONFIG['retry_interval'];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response = wp_remote_get($category_url, array(
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

                // Parse HTML
                $property_urls = $this->parse_property_urls_from_html($body);

                // Limit to configured number
                $limit = $this->options['properties_to_check'];
                $property_urls = array_slice($property_urls, 0, $limit);

                return $property_urls;

            } catch (Exception $e) {
                $this->logger->log_error_with_retry($e->getMessage(), $attempt, $max_attempts);

                if ($attempt < $max_attempts) {
                    // sleep disabled for testing
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
        try {
            // Check if property already exists
            if ($this->is_duplicate($property_url)) {
                error_log("[AD] {$property_url} → Duplicate (Skip)");
                return array('success' => true, 'is_new' => false);
            }

            error_log("[AD] {$property_url} → New (Extracting...)");

            // Fetch property data
            $property_data = $this->fetch_property_data($property_url);

            if (empty($property_data)) {
                throw new Exception('Failed to fetch property data');
            }

            // Map and create WordPress post
            $post_id = $this->mapper->create_property_post($property_data, $category_key);

            if ($post_id) {
                error_log("[INSERT] Post ID={$post_id}, Cat={$category_key}, Title=\"{$property_data['title']}\"");
                return array('success' => true, 'is_new' => true, 'post_id' => $post_id);
            } else {
                throw new Exception('Failed to create property post');
            }

        } catch (Exception $e) {
            error_log("[ERROR] {$property_url}: " . $e->getMessage());
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

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
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

                // Parse property data
                $property_data = $this->parse_property_data_from_html($body, $property_url);
                return $property_data;

            } catch (Exception $e) {
                $this->logger->log_error_with_retry($e->getMessage(), $attempt, $max_attempts);

                if ($attempt >= $max_attempts) {
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

        // Extract phone number
        $phone_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['phone_xpath']);
        if ($phone_nodes->length > 0) {
            $property_data['phone_number'] = trim($phone_nodes->item(0)->textContent);
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
        $specifications = array();
        $spec_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['specifications_xpath']);

        if ($spec_nodes->length > 0) {
            foreach ($spec_nodes as $node) {
                $spans = $xpath->query('.//span', $node);
                if ($spans->length >= 2) {
                    $attribute = trim($spans->item(0)->textContent);
                    $value = trim($spans->item(1)->textContent);
                    $specifications[$attribute] = $value;
                }
            }
        }

        return $specifications;
    }

    /**
     * Get geocoded address from coordinates
     */
    private function get_geocoded_address($latitude, $longitude)
    {
        $geocoder = Real_Estate_Scraper_Geocoder::get_instance();
        return $geocoder->reverse_geocode($latitude, $longitude);
    }

    /**
     * Get next category for rotation based on last added property
     */
    private function get_next_category_for_rotation()
    {
        $category_order = array('apartamente', 'garsoniere', 'case_vile', 'spatii_comerciale');

        // Get last added property
        $last_property = $this->get_last_added_property();

        if (!$last_property) {
            return 'apartamente';
        }

        // Get category of last property
        $last_category = $this->get_property_category($last_property);

        if (!$last_category) {
            return 'apartamente';
        }

        error_log("[ROTATION] Last: ID={$last_property->ID}, Cat={$last_category}");

        // Find next category in rotation
        $current_index = array_search($last_category, $category_order);
        if ($current_index === false) {
            return 'apartamente';
        }

        $next_index = ($current_index + 1) % count($category_order);
        $next_category = $category_order[$next_index];

        error_log("[ROTATION] Next: {$next_category}");
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

        return !empty($last_property) ? $last_property[0] : null;
    }

    /**
     * Get category of a property based on its property_type taxonomy
     */
    private function get_property_category($property)
    {
        $property_types = wp_get_post_terms($property->ID, 'property_type');

        if (is_wp_error($property_types) || empty($property_types)) {
            return null;
        }

        $property_type_id = $property_types[0]->term_id;

        // Map property type ID to category
        foreach ($this->options['category_mapping'] as $category_key => $type_id) {
            if ($type_id == $property_type_id) {
                return $category_key;
            }
        }

        return null;
    }

}
