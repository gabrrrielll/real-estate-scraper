<?php

/**
 * Geocoder class for Real Estate Scraper
 * Handles reverse geocoding using OpenStreetMap Nominatim API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Geocoder
{
    private static $instance = null;
    private $logger;
    private $api_url = 'https://nominatim.openstreetmap.org/reverse';

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
    }

    /**
     * Reverse geocode coordinates to address
     */
    public function reverse_geocode($latitude, $longitude)
    {
        if (empty($latitude) || empty($longitude)) {
            error_log('RES DEBUG - Geocoder: Missing coordinates');
            return null;
        }

        // Clean coordinates
        $lat = $this->clean_coordinate($latitude);
        $lon = $this->clean_coordinate($longitude);

        if (empty($lat) || empty($lon)) {
            error_log('RES DEBUG - Geocoder: Invalid coordinates - lat: ' . $latitude . ', lon: ' . $longitude);
            return null;
        }

        error_log('RES DEBUG - Geocoder: Requesting address for coordinates - lat: ' . $lat . ', lon: ' . $lon);

        // Prepare API request
        $url = $this->api_url . '?' . http_build_query(array(
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'json',
            'addressdetails' => '1',
            'zoom' => '18',
            'accept-language' => 'ro'
        ));

        error_log('RES DEBUG - Geocoder: API URL: ' . $url);

        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'Real Estate Scraper/1.0 (WordPress Plugin)',
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('RES DEBUG - Geocoder: API request failed - ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('RES DEBUG - Geocoder: Invalid JSON response - ' . json_last_error_msg());
            return null;
        }

        if (empty($data)) {
            error_log('RES DEBUG - Geocoder: Empty response from API');
            return null;
        }

        // Extract address information
        $address = $this->extract_address_info($data);

        error_log('RES DEBUG - Geocoder: Extracted address - ' . json_encode($address, JSON_UNESCAPED_UNICODE));

        return $address;
    }

    /**
     * Extract address information from API response
     */
    private function extract_address_info($data)
    {
        $address = array(
            'display_name' => '',
            'city' => '',
            'street' => '',
            'house_number' => '',
            'postal_code' => '',
            'country' => '',
            'county' => '',
            'village' => '',
            'town' => '',
            'full_address' => ''
        );

        // Get display name
        if (isset($data['display_name'])) {
            $address['display_name'] = $data['display_name'];
            $address['full_address'] = $data['display_name'];
        }

        // Extract address components
        if (isset($data['address'])) {
            $addr = $data['address'];

            // City (try multiple fields)
            $address['city'] = $this->get_address_component($addr, array('city', 'town', 'village', 'municipality'));

            // Street
            $address['street'] = $this->get_address_component($addr, array('road', 'street', 'street_name'));

            // House number
            $address['house_number'] = $this->get_address_component($addr, array('house_number', 'house'));

            // Postal code
            $address['postal_code'] = $this->get_address_component($addr, array('postcode', 'postal_code'));

            // Country
            $address['country'] = $this->get_address_component($addr, array('country'));

            // County
            $address['county'] = $this->get_address_component($addr, array('county', 'state'));

            // Village/Town (for rural areas)
            $address['village'] = $this->get_address_component($addr, array('village'));
            $address['town'] = $this->get_address_component($addr, array('town'));
        }

        return $address;
    }

    /**
     * Get address component from multiple possible keys
     */
    private function get_address_component($address, $keys)
    {
        foreach ($keys as $key) {
            if (isset($address[$key]) && !empty($address[$key])) {
                return $address[$key];
            }
        }
        return '';
    }

    /**
     * Clean coordinate value
     */
    private function clean_coordinate($coordinate)
    {
        $coordinate = trim($coordinate);
        // Allow digits, decimal point, and negative sign
        $coordinate = preg_replace('/[^\d.-]/', '', $coordinate);

        if (is_numeric($coordinate)) {
            return (string) floatval($coordinate);
        }
        return '';
    }

    /**
     * Test geocoding with sample coordinates
     */
    public function test_geocoding()
    {
        error_log('RES DEBUG - Geocoder: Testing with sample coordinates');
        $result = $this->reverse_geocode('45.9432', '24.9668');

        if ($result) {
            error_log('RES DEBUG - Geocoder: Test successful');
            return $result;
        } else {
            error_log('RES DEBUG - Geocoder: Test failed');
            return null;
        }
    }
}
