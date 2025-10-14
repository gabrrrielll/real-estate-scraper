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
    'property_list_urls_xpath' => '//a[contains(@class, "card-box-link")]',
    'property_data' => array(
        'title_xpath' => '//h1 | //title',
        'content_xpath' => '//*[contains(@class, "description") or contains(@class, "content") or contains(@class, "details")]',
        'price_xpath' => '//*[contains(@class, "price") or contains(text(), "€") or contains(text(), "EUR")]',
        'size_xpath' => '//*[contains(text(), "mp") or contains(text(), "m²") or contains(text(), "sq")]',
        'bedrooms_xpath' => '//*[contains(text(), "dormitor") or contains(text(), "cameră") or contains(text(), "room")]',
        'bathrooms_xpath' => '//*[contains(text(), "baie") or contains(text(), "bathroom")]',
        'address_xpath' => '//*[contains(@class, "address") or contains(@class, "location")]',
        'images_xpath' => '//img[@src]',
        'phone_xpath' => '//a[contains(@href, "tel:")]', // Placeholder, needs specific XPath for each site
    ),
    'base_url_for_relative_links' => 'https://homezz.ro' // Used for converting relative URLs to absolute. Update this if target site has relative links.
));
