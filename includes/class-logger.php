<?php

/**
 * Logger class for Real Estate Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Logger
{
    private static $instance = null;
    private $log_dir;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->log_dir = REAL_ESTATE_SCRAPER_PLUGIN_DIR . 'logs';

        // Ensure log directory exists
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }

    /**
     * Log a message
     */
    public function log($message, $level = 'INFO')
    {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        $log_file = $this->get_log_file();
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log info message
     */
    public function info($message)
    {
        $this->log($message, 'INFO');
    }

    /**
     * Log warning message
     */
    public function warning($message)
    {
        $this->log($message, 'WARNING');
    }

    /**
     * Log error message
     */
    public function error($message)
    {
        $this->log($message, 'ERROR');
    }

    /**
     * Log debug message
     */
    public function debug($message)
    {
        $this->log($message, 'DEBUG');
    }

    /**
     * Get log file path for today
     */
    private function get_log_file()
    {
        $date = current_time('Y-m-d');
        return $this->log_dir . "/scraper-{$date}.log";
    }

    /**
     * Get today's logs
     */
    public function get_today_logs()
    {
        $log_file = $this->get_log_file();

        if (!file_exists($log_file)) {
            return array();
        }

        $logs = file_get_contents($log_file);
        return array_filter(explode(PHP_EOL, $logs));
    }

    /**
     * Get all log files
     */
    public function get_log_files()
    {
        $files = glob($this->log_dir . '/scraper-*.log');
        $log_files = array();

        foreach ($files as $file) {
            $filename = basename($file);
            $date = str_replace(array('scraper-', '.log'), '', $filename);
            $log_files[] = array(
                'filename' => $filename,
                'date' => $date,
                'size' => filesize($file),
                'modified' => filemtime($file)
            );
        }

        // Sort by date descending
        usort($log_files, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $log_files;
    }

    /**
     * Get logs from specific date
     */
    public function get_logs_by_date($date)
    {
        $log_file = $this->log_dir . "/scraper-{$date}.log";

        if (!file_exists($log_file)) {
            return array();
        }

        $logs = file_get_contents($log_file);
        return array_filter(explode(PHP_EOL, $logs));
    }

    /**
     * Clean old logs (older than 4 days)
     */
    public function clean_old_logs()
    {
        $files = glob($this->log_dir . '/scraper-*.log');
        $cleaned = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            $date = str_replace(array('scraper-', '.log'), '', $filename);
            $file_date = strtotime($date);
            $four_days_ago = strtotime('-4 days');

            if ($file_date < $four_days_ago) {
                unlink($file);
                $cleaned++;
            }
        }

        return array(
            'success' => true,
            'cleaned' => $cleaned,
            'message' => sprintf(__('Cleaned %d old log files.', 'real-estate-scraper'), $cleaned)
        );
    }

    /**
     * Clear all logs
     */
    public function clear_all_logs()
    {
        $files = glob($this->log_dir . '/scraper-*.log');
        $cleared = 0;

        foreach ($files as $file) {
            unlink($file);
            $cleared++;
        }

        return array(
            'success' => true,
            'cleared' => $cleared,
            'message' => sprintf(__('Cleared %d log files.', 'real-estate-scraper'), $cleared)
        );
    }

    /**
     * Log scraper start
     */
    public function log_scraper_start($categories)
    {
        $this->info('=== SCRAPER STARTED ===');
        $this->info('Categories to scrape: ' . implode(', ', array_keys($categories)));
        $this->info('Properties to check per category: ' . get_option('real_estate_scraper_options')['properties_to_check']);
    }

    /**
     * Log scraper end
     */
    public function log_scraper_end($stats)
    {
        $this->info('=== SCRAPER FINISHED ===');
        $this->info('Total properties found: ' . $stats['total_found']);
        $this->info('New properties added: ' . $stats['new_added']);
        $this->info('Duplicates skipped: ' . $stats['duplicates_skipped']);
        $this->info('Errors encountered: ' . $stats['errors']);
        $this->info('Execution time: ' . $stats['execution_time'] . ' seconds');
    }

    /**
     * Log category processing
     */
    public function log_category_start($category_name, $url)
    {
        $this->info("--- Processing category: {$category_name} ---");
        $this->info("URL: {$url}");
    }

    /**
     * Log category end
     */
    public function log_category_end($category_name, $stats)
    {
        $this->info("Category {$category_name} completed:");
        $this->info("- Properties found: " . $stats['found']);
        $this->info("- New properties: " . $stats['new']);
        $this->info("- Duplicates: " . $stats['duplicates']);
        $this->info("- Errors: " . $stats['errors']);
    }

    /**
     * Log property processing
     */
    public function log_property_start($url)
    {
        $this->info("Processing property: {$url}");
    }

    /**
     * Log property data extraction
     */
    public function log_property_data($data)
    {
        $this->debug("Property data extracted:");
        $this->debug("- Title: " . ($data['title'] ?? 'N/A'));
        $this->debug("- Price: " . ($data['price'] ?? 'N/A'));
        $this->debug("- Size: " . ($data['size'] ?? 'N/A'));
        $this->debug("- Bedrooms: " . ($data['bedrooms'] ?? 'N/A'));
        $this->debug("- Bathrooms: " . ($data['bathrooms'] ?? 'N/A'));
        $this->debug("- Address: " . ($data['address'] ?? 'N/A'));
        $this->debug("- Images found: " . count($data['images'] ?? array()));
    }

    /**
     * Log property creation
     */
    public function log_property_created($post_id, $title)
    {
        $this->info("Property created successfully - ID: {$post_id}, Title: {$title}");
    }

    /**
     * Log duplicate found
     */
    public function log_duplicate_found($url, $existing_id)
    {
        $this->info("Duplicate found - URL: {$url}, Existing ID: {$existing_id}");
    }

    /**
     * Log error with retry
     */
    public function log_error_with_retry($message, $attempt, $max_attempts)
    {
        $this->error("Error (attempt {$attempt}/{$max_attempts}): {$message}");
    }

    /**
     * Log retry success
     */
    public function log_retry_success($message)
    {
        $this->info("Retry successful: {$message}");
    }

    /**
     * Log final error
     */
    public function log_final_error($message)
    {
        $this->error("Final error after all retries: {$message}");
    }
}


