# Real Estate Scraper

A WordPress plugin that automatically scrapes property listings from real estate websites and imports them to WordPress property post type.

## Features

- **Automatic Scraping**: Scrapes multiple categories of property listings from real estate websites
- **Smart Mapping**: Maps scraped data to WordPress property fields and taxonomies
- **Duplicate Detection**: Prevents duplicate listings based on source URL
- **Image Handling**: Downloads and uploads all property images
- **Cron Scheduling**: Configurable automatic scraping intervals
- **Live Monitoring**: Real-time logging and progress tracking
- **Admin Interface**: Easy-to-use settings panel

## Requirements

- WordPress 6.6 or higher
- PHP 8.0 or higher
- Houzez theme or compatible property post type setup

## Installation

1. Upload the plugin files to `/wp-content/plugins/real-estate-scraper/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings in the admin panel

## Configuration

### Category URLs
Set the URLs for each property category:
- Apartamente (Apartments)
- Garsoniere (Studios)
- Case/Vile (Houses/Villas)
- Spații Comerciale (Commercial Spaces)

### Category Mapping
Map each category to a property type taxonomy term in WordPress.

### Scraper Settings
- **Cron Interval**: Choose how often to run the scraper (15min to daily)
- **Properties to Check**: Number of properties to check per category
- **Default Status**: Whether to create properties as draft or published

## Usage

### Manual Scraping
Click "Run Scraper Now" in the admin panel to run the scraper immediately.

### Automatic Scraping
The scraper runs automatically based on the configured cron interval.

### Monitoring
View live logs and progress in the admin panel during scraping operations.

## Data Mapping

The plugin maps scraped data to the following WordPress fields:

### Post Data
- Title → Post Title
- Content → Post Content
- Images → Featured Image + Gallery

### Meta Fields (fave_ prefix)
- `fave_property_price` → Property Price
- `fave_property_size` → Property Size
- `fave_property_bedrooms` → Bedrooms
- `fave_property_bathrooms` → Bathrooms
- `fave_property_address` → Address
- `fave_property_map_address` → Map Address
- `fave_property_images` → Image Gallery

### Taxonomies
- `property_type` → Based on category mapping
- `property_status` → Set to "Închiriere" (Rent)

## Logging

The plugin creates detailed logs for:
- Scraping operations
- Data extraction
- Image downloads
- Post creation
- Errors and retries

Logs are stored in separate files per day and automatically cleaned after 4 days.

## Troubleshooting

### Common Issues

1. **No properties found**: Check if the category URLs are correct and accessible
2. **Images not downloading**: Verify server has write permissions to uploads directory
3. **Cron not running**: Check if WordPress cron is enabled and working
4. **Memory issues**: Increase PHP memory limit for large scraping operations

### Debug Mode

Enable WordPress debug mode to see additional error information:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Support

For support and bug reports, please contact [Gabriel Sandu](https://github.com/gabrrrielll).

## Changelog

### Version 1.0.0
- Initial release
- Basic scraping functionality
- Admin interface
- Cron scheduling
- Image handling
- Logging system

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Gabriel Sandu](https://github.com/gabrrrielll) for WordPress with the Houzez theme compatibility in mind.
