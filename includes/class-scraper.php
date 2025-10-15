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
            // --- ORIGINAL LOGIC: Process each category ---
            error_log('SCRAPER DEBUG - Category URLs before loop: ' . var_export($this->options['category_urls'], true));
            error_log('RES DEBUG - Starting category loop...');
            foreach ($this->options['category_urls'] as $category_key => $url) {
                error_log('RES DEBUG - Loop iteration - Category: ' . $category_key . ', URL: ' . $url);
                if (empty($url)) {
                    continue;
                }

                // // Limit the number of properties to process per session (temporarily commented out for debugging)
                // $max_ads = RES_SCRAPER_CONFIG['max_ads_per_session'];
                // if ($max_ads > 0 && count($property_urls) > $max_ads) {
                //     $this->logger->info("Limiting processing to {$max_ads} properties out of " . count($property_urls) . " found for category {$category_key}");
                //     $property_urls = array_slice($property_urls, 0, $max_ads);
                // }

                $category_stats = $this->process_category($category_key, $url);

                $stats['total_found'] += $category_stats['found'];
                $stats['new_added'] += $category_stats['new'];
                $stats['duplicates_skipped'] += $category_stats['duplicates'];
                $stats['errors'] += $category_stats['errors'];
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
     * Process a single category
     */
    private function process_category($category_key, $url)
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

            // // Limit the number of properties to process per session (temporarily commented out for debugging)
            // $max_ads = RES_SCRAPER_CONFIG['max_ads_per_session'];
            // if ($max_ads > 0 && count($property_urls) > $max_ads) {
            //     $this->logger->info("Limiting processing to {$max_ads} properties out of " . count($property_urls) . " found for category {$category_key}");
            //     $property_urls = array_slice($property_urls, 0, $max_ads);
            // }

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

        // --- TEMPORARY DEBUG: Log all links found ---
        error_log('RES DEBUG - Using XPath from constants: ' . RES_SCRAPER_CONFIG['property_list_urls_xpath']);
        $test_links = $xpath->query(RES_SCRAPER_CONFIG['property_list_urls_xpath']);
        error_log('RES DEBUG - XPath found ' . $test_links->length . ' total links');

        // Log all links for debugging
        foreach ($test_links as $index => $link) {
            $href = $link->getAttribute('href');
            error_log('RES DEBUG - Link[' . $index . ']: ' . $href);
        }

        // TODO: TEMPORARY - Remove this return to enable actual scraping after debugging links
        // error_log('RES DEBUG - Stopping execution here for link debugging. Remove this return to continue.');
        // return array(); // Stop here for debugging - prevents actual property parsing
        // --- END TEMPORARY DEBUG ---

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

        // Extract bedrooms
        $bedroom_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['bedrooms_xpath']);
        foreach ($bedroom_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/(\d+)\s*(dormitor|cameră|room)/i', $text, $matches)) {
                $property_data['bedrooms'] = $matches[1];
                break;
            }
        }

        // Extract bathrooms
        $bathroom_nodes = $xpath->query(RES_SCRAPER_CONFIG['property_data']['bathrooms_xpath']);
        foreach ($bathroom_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/(\d+)\s*(baie|bathroom)/i', $text, $matches)) {
                $property_data['bathrooms'] = $matches[1];
                break;
            }
        }

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

        return $property_data;
    }
}
