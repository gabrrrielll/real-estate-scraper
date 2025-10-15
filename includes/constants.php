<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Real Estate Scraper Configuration Constants
 *
 * This file defines constants for XPath queries used in scraping.
 * You can modify these XPath values to adapt the scraper to different
 * real estate website structures without changing the core plugin code.
 *
 * How to find XPath:
 * 1. Open the target property listing page in your browser.
 * 2. Right-click on the element you want to extract (e.g., title, price, image).
 * 3. Select "Inspect" or "Inspect Element".
 * 4. In the Developer Tools, right-click on the highlighted HTML element.
 * 5. Choose "Copy" -> "Copy XPath" (or "Copy" -> "Copy full XPath" if needed).
 *    Sometimes, a simpler XPath using class names (e.g., `//h1[@class="property-title"]`)
 *    is more robust than a full XPath.
 */

// Define the scraper configuration
define('RES_SCRAPER_CONFIG', array(
    'property_list_urls_xpath' => '//a[contains(@class, "card-box") and contains(@class, "card-1") and @href]',
    'property_data' => array(
        'title_xpath' => '//h1[@data-test-id="ad-title"]/span[1]',
        'content_xpath' => '//pre[@data-test-id="ad-description"]',
        'price_xpath' => '//div[@data-test-id="ad-price"]',
        'size_xpath' => '//div[@class="box-attr second"]/p[@data-test-id="ad-attribute"]/span[2]',
        // 'bedrooms_xpath' => '//*[contains(text(), "dormitor") or contains(text(), "cameră") or contains(text(), "room")]',
        // 'bathrooms_xpath' => '//*[contains(text(), "baie") or contains(text(), "bathroom")]',
        'address_xpath' => '//div[@id="lat"] | //div[@id="lng"]', // Now extracts lat and lng to be combined
        'latitude_xpath' => '//div[@id="lat"]',
        'longitude_xpath' => '//div[@id="lng"]',
        'images_xpath' => '//div[contains(@class, "small-box-img")]//img[@src]',
        'phone_xpath' => '//p[@id="number-phone-active-format"]',
    ),
    'single_property_test_url' => 'https://homezz.ro/apartament-cu-incalzire-in-pardoseala-si-loc-de-parcare-in-b-3789471.html', // NEW: URL for testing single property parsing
    'base_url_for_relative_links' => 'https://homezz.ro', // Used for converting relative URLs to absolute. Update this if target site has relative links.
    'retry_interval' => 60, // Added: Default retry interval in seconds for category page fetching
    'max_ads_per_session' => 2 // NEW: Maximum number of ads to publish per scraping session
));
