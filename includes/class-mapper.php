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
    }

    /**
     * Create a property post from scraped data
     */
    public function create_property_post($property_data, $category_key)
    {
        try {
            // Prepare post data
            $post_data = array(
                'post_title' => $this->clean_title($property_data['title']),
                'post_content' => $this->clean_content($property_data['content']),
                'post_status' => $this->options['default_status'],
                'post_type' => 'property',
                'post_author' => 1, // Admin user
                'meta_input' => array()
            );

            // Set excerpt if content is too long
            if (strlen($post_data['post_content']) > 200) {
                $post_data['post_excerpt'] = wp_trim_words($post_data['post_content'], 30);
            }

            // Create the post
            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id)) {
                throw new Exception('Failed to create post: ' . $post_id->get_error_message());
            }

            // Set meta fields
            $this->set_property_meta($post_id, $property_data);

            // Set taxonomies
            $this->set_property_taxonomies($post_id, $category_key);

            // Handle images
            $this->handle_property_images($post_id, $property_data['images']);

            return $post_id;

        } catch (Exception $e) {
            $this->logger->error('Failed to create property post: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set property meta fields
     */
    private function set_property_meta($post_id, $property_data)
    {
        $meta_fields = array(
            'fave_property_price' => $this->clean_price($property_data['price']),
            'fave_property_size' => $this->clean_size($property_data['size']),
            'fave_property_bedrooms' => $this->clean_number($property_data['bedrooms']),
            'fave_property_bathrooms' => $this->clean_number($property_data['bathrooms']),
            'fave_property_address' => $this->clean_address($property_data['address']),
            'fave_property_map_address' => $this->clean_address($property_data['address']),
            'fave_property_source_url' => $property_data['source_url']
        );

        foreach ($meta_fields as $key => $value) {
            if (!empty($value)) {
                update_post_meta($post_id, $key, $value);
                $this->logger->debug("Set meta {$key}: {$value}");
            }
        }

        // Set additional meta fields
        update_post_meta($post_id, 'fave_featured', 0); // Not featured by default
        update_post_meta($post_id, 'fave_property_map', 1); // Show map by default
        update_post_meta($post_id, 'fave_property_map_street_view', 'show'); // Show street view
    }

    /**
     * Set property taxonomies
     */
    private function set_property_taxonomies($post_id, $category_key)
    {
        // Set property status based on category mapping
        $property_status = $this->options['category_mapping'][$category_key] ?? '';
        if (!empty($property_status)) {
            $status_term = get_term_by('id', $property_status, 'property_status');
            if ($status_term) {
                wp_set_post_terms($post_id, array($status_term->term_id), 'property_status');
                $this->logger->debug("Set property_status: {$status_term->name}");
            }
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

        $uploaded_images = array();
        $upload_dir = wp_upload_dir();

        foreach ($images as $image_url) {
            try {
                $image_id = $this->download_and_attach_image($image_url, $post_id);
                if ($image_id) {
                    $uploaded_images[] = $image_id;
                    $this->logger->debug("Downloaded and attached image: {$image_url}");
                }
            } catch (Exception $e) {
                $this->logger->warning("Failed to download image {$image_url}: " . $e->getMessage());
            }
        }

        if (!empty($uploaded_images)) {
            // Set first image as featured image
            set_post_thumbnail($post_id, $uploaded_images[0]);

            // Set all images in property_images meta
            update_post_meta($post_id, 'fave_property_images', $uploaded_images);

            $this->logger->info("Successfully processed " . count($uploaded_images) . " images");
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
        $price = trim($price);
        $price = preg_replace('/[^\d.,€EUR]/', '', $price);

        return $price;
    }

    /**
     * Clean size
     */
    private function clean_size($size)
    {
        $size = trim($size);
        $size = preg_replace('/[^\d.,mp²sq]/i', '', $size);

        return $size;
    }

    /**
     * Clean number
     */
    private function clean_number($number)
    {
        $number = trim($number);
        $number = preg_replace('/[^\d]/', '', $number);

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
}

