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
            $this->logger->log_scraper_start($this->options['category_urls']);

            $stats = array(
                'total_found' => 0,
                'new_added' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0
            );

            // Process each category
            foreach ($this->options['category_urls'] as $category_key => $url) {
                if (empty($url)) {
                    continue;
                }

                $category_stats = $this->process_category($category_key, $url);

                $stats['total_found'] += $category_stats['found'];
                $stats['new_added'] += $category_stats['new'];
                $stats['duplicates_skipped'] += $category_stats['duplicates'];
                $stats['errors'] += $category_stats['errors'];
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
     * Process a single category
     */
    private function process_category($category_key, $url)
    {
        $this->logger->log_category_start($category_key, $url);

        $stats = array(
            'found' => 0,
            'new' => 0,
            'duplicates' => 0,
            'errors' => 0
        );

        try {
            // Get property URLs from category page
            $property_urls = $this->get_property_urls_from_category($url);
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
        $max_attempts = $this->options['retry_attempts'];
        $retry_interval = $this->options['retry_interval'];

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $this->logger->debug("Fetching category page: {$category_url} (attempt {$attempt})");

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

                // Parse HTML to extract property URLs
                $property_urls = $this->parse_property_urls_from_html($body);

                // Limit to configured number
                $limit = $this->options['properties_to_check'];
                $property_urls = array_slice($property_urls, 0, $limit);

                $this->logger->info("Successfully extracted " . count($property_urls) . " property URLs");

                return $property_urls;

            } catch (Exception $e) {
                $this->logger->log_error_with_retry($e->getMessage(), $attempt, $max_attempts);

                if ($attempt < $max_attempts) {
                    sleep($retry_interval);
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
        $links = $xpath->query('//a[contains(@href, "/property/") or contains(@href, "/listing/") or contains(@href, "/anunt/")]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            // Convert relative URLs to absolute
            if (strpos($href, 'http') !== 0) {
                $href = 'https://example.com' . $href;
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
                    sleep($retry_interval);
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
            'images' => array(),
            'source_url' => $source_url
        );

        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Extract title
        $title_nodes = $xpath->query('//h1 | //title');
        if ($title_nodes->length > 0) {
            $property_data['title'] = trim($title_nodes->item(0)->textContent);
        }

        // Extract price
        $price_nodes = $xpath->query('//*[contains(@class, "price") or contains(text(), "€") or contains(text(), "EUR")]');
        foreach ($price_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/[\d.,]+\s*[€EUR]/', $text)) {
                $property_data['price'] = $text;
                break;
            }
        }

        // Extract size
        $size_nodes = $xpath->query('//*[contains(text(), "mp") or contains(text(), "m²") or contains(text(), "sq")]');
        foreach ($size_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/[\d.,]+\s*(mp|m²|sq)/i', $text)) {
                $property_data['size'] = $text;
                break;
            }
        }

        // Extract bedrooms
        $bedroom_nodes = $xpath->query('//*[contains(text(), "dormitor") or contains(text(), "cameră") or contains(text(), "room")]');
        foreach ($bedroom_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/(\d+)\s*(dormitor|cameră|room)/i', $text, $matches)) {
                $property_data['bedrooms'] = $matches[1];
                break;
            }
        }

        // Extract bathrooms
        $bathroom_nodes = $xpath->query('//*[contains(text(), "baie") or contains(text(), "bathroom")]');
        foreach ($bathroom_nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/(\d+)\s*(baie|bathroom)/i', $text, $matches)) {
                $property_data['bathrooms'] = $matches[1];
                break;
            }
        }

        // Extract address
        $address_nodes = $xpath->query('//*[contains(@class, "address") or contains(@class, "location")]');
        foreach ($address_nodes as $node) {
            $text = trim($node->textContent);
            if (strlen($text) > 10 && strlen($text) < 200) {
                $property_data['address'] = $text;
                break;
            }
        }

        // Extract images
        $image_nodes = $xpath->query('//img[@src]');
        foreach ($image_nodes as $node) {
            $src = $node->getAttribute('src');

            // Convert relative URLs to absolute
            if (strpos($src, 'http') !== 0) {
                $src = 'https://example.com' . $src;
            }

            // Filter out small images and icons
            if (strlen($src) > 20 && !in_array(basename($src), array('logo.png', 'icon.png'))) {
                $property_data['images'][] = $src;
            }
        }

        // Extract content/description
        $content_nodes = $xpath->query('//*[contains(@class, "description") or contains(@class, "content") or contains(@class, "details")]');
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
