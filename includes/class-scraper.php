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

            $stats = array(
                'total_found' => 0,
                'new_added' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0
            );

            // Global limit for total ads processed across all categories
            $max_ads_global = $this->options['max_ads_per_session'] ?? 4;
            $ads_processed_global = 0;

            // Log configuration
            $category_count = count($this->options['category_urls']);
            $max_display = ($max_ads_global == 0) ? 'unlimited' : $max_ads_global;
            error_log("Config: {$category_count} categories, Max: {$max_display} ads/session");

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

                    // Log progress
                    if ($max_ads_global > 0) {
                        $progress_status = ($ads_processed_global >= $max_ads_global) ? ' (LIMIT REACHED)' : '';
                        error_log("[PROGRESS] {$ads_processed_global}/{$max_ads_global} ads processed{$progress_status}");
                    }
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

            // Log scraper completion
            error_log('=== SCRAPER COMPLETED ===');
            error_log("Stats: Processed={$ads_processed_global}, New={$stats['new_added']}, Duplicates={$stats['duplicates_skipped']}, Errors={$stats['errors']}");
            error_log("Time: {$execution_time}s");

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
            $category_upper = strtoupper($category_key);
            error_log("[CATEGORY] {$category_upper} ({$url}) → " . count($property_urls) . " ads");

            // Log all extracted URLs for debugging
            if (!empty($property_urls)) {
                error_log("[LINKS] Extracted " . count($property_urls) . " links from {$category_upper}:");
                foreach ($property_urls as $index => $link) {
                    error_log("  [{$index}] {$link}");
                }
            }

            if (empty($property_urls)) {
                return $stats;
            }

            // Try to find a new (non-duplicate) property from this category
            // Use properties_to_check setting to determine how deep to check
            $max_attempts = min($this->options['properties_to_check'], count($property_urls));
            $attempt = 0;

            while ($attempt < $max_attempts && $stats['new'] == 0) {
                $property_url = $property_urls[$attempt];
                $result = $this->process_property($property_url, $category_key);

                if ($result['success']) {
                    if ($result['is_new']) {
                        // Found a new property, increment counter and stop
                        $stats['found'] = 1;
                        $stats['new'] = 1;
                        break;
                    } else {
                        // Duplicate found, try next one
                        $stats['duplicates']++;
                    }
                } else {
                    $stats['errors']++;
                }

                $attempt++;
            }

            // If all attempts were duplicates or errors, no "found" increment
            if ($stats['new'] == 0) {
                error_log("[CATEGORY] {$category_upper} → No new ads found (checked {$attempt} ads: {$stats['duplicates']} duplicates, {$stats['errors']} errors)");
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
                $term_id = $this->options['category_mapping'][$category_key] ?? 'N/A';
                $category_upper = strtoupper($category_key);
                error_log("[INSERT] Post ID={$post_id}, Cat={$category_upper} (Term ID={$term_id}), Title=\"{$property_data['title']}\"");
                return array('success' => true, 'is_new' => true, 'post_id' => $post_id);
            } else {
                throw new Exception('Failed to create property post');
            }

        } catch (Exception $e) {
            // Enhanced error logging
            error_log("[ERROR] {$property_url}");
            // Check if extraction was successful by verifying title exists (main indicator)
            if (!empty($property_data) && !empty($property_data['title'])) {
                error_log("  → Extraction: OK");
                error_log("  → Title: \"{$property_data['title']}\"");
                if (!empty($property_data['price'])) {
                    error_log("  → Price: \"{$property_data['price']}\"");
                }
            } else {
                error_log("  → Extraction: FAILED (No title found - ad may be inactive/expired)");
            }
            error_log("  → Reason: " . $e->getMessage());
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
        $property_data['mapped_specifications'] = $this->map_specifications_to_meta($property_data['specifications']);

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

        if (!empty($specifications)) {
            error_log('[SPECS RAW] Found ' . count($specifications) . ' items');
            foreach ($specifications as $attribute => $value) {
                error_log('  ' . $attribute . ': ' . $value);
            }
        } else {
            error_log('[SPECS RAW] No specifications found');
        }

        return $specifications;
    }

    /**
     * Map extracted specifications to Houzez meta fields
     */
    private function map_specifications_to_meta($specifications)
    {
        $mapped = array();

        if (empty($specifications)) {
            return $mapped;
        }

        $mapping_config = RES_SCRAPER_CONFIG['property_data']['specifications_mapping'] ?? array();

        if (empty($mapping_config) || !is_array($mapping_config)) {
            return $mapped;
        }

        // Normalize specification labels for comparison
        $normalized_specs = array();
        foreach ($specifications as $label => $value) {
            $normalized_label = $this->normalize_spec_label($label);
            $normalized_specs[$normalized_label] = $value;
        }

        foreach ($mapping_config as $meta_key => $mapping) {
            $labels = $mapping['labels'] ?? array();
            $type = $mapping['type'] ?? 'string';

            foreach ($labels as $label) {
                $normalized_label = $this->normalize_spec_label($label);
                if (isset($normalized_specs[$normalized_label])) {
                    $value = $this->normalize_spec_value($normalized_specs[$normalized_label], $type);
                    if ($value === '') {
                        continue;
                    }
                    $mapped[$meta_key] = $value;
                    break;
                }
            }
        }

        if (!empty($mapped)) {
            error_log('[SPECS MAPPED] ' . count($mapped) . ' matches');
            foreach ($mapped as $meta_key => $value) {
                error_log('  ' . $meta_key . ': ' . $value);
            }
        } else {
            error_log('[SPECS MAPPED] No matches found');
        }

        return $mapped;
    }

    /**
     * Fetch property data for admin refresh actions
     */
    public function fetch_property_data_for_admin($property_url)
    {
        return $this->fetch_property_data($property_url);
    }

    /**
     * Normalize specification label for matching
     */
    private function normalize_spec_label($label)
    {
        $normalized = trim($label);

        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        }

        if (function_exists('mb_strtolower')) {
            $normalized = mb_strtolower($normalized);
        } else {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }

    /**
     * Normalize specification value based on type
     */
    private function normalize_spec_value($value, $type = 'string')
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if ($type === 'number') {
            $numeric = $this->extract_numeric_value($value, true);
            return $numeric;
        }

        return $value;
    }

    /**
     * Extract numeric value from string
     */
    private function extract_numeric_value($value, $allow_decimal = true)
    {
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

        // Find next category in rotation
        $current_index = array_search($last_category, $category_order);
        if ($current_index === false) {
            return 'apartamente';
        }

        $next_index = ($current_index + 1) % count($category_order);
        $next_category = $category_order[$next_index];

        // Log rotation with uppercase categories
        $last_cat_upper = strtoupper($last_category);
        $next_cat_upper = strtoupper($next_category);
        error_log("[ROTATION] Last post: ID={$last_property->ID} ({$last_cat_upper}) → Next: {$next_cat_upper}");

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
