<?php

/**
 * Cron class for Real Estate Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class Real_Estate_Scraper_Cron
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

        // Add cron hook
        add_action('real_estate_scraper_cron', array($this, 'run_cron_job'));
    }

    /**
     * Schedule cron job
     */
    public function schedule_cron()
    {
        $interval = $this->options['cron_interval'] ?? 'hourly';

        // Clear existing schedule
        $this->clear_cron();

        // Schedule new cron
        if (!wp_next_scheduled('real_estate_scraper_cron')) {
            wp_schedule_event(time(), $interval, 'real_estate_scraper_cron');
            $this->logger->info("Cron scheduled with interval: {$interval}");
        }
    }

    /**
     * Clear cron job
     */
    public function clear_cron()
    {
        $timestamp = wp_next_scheduled('real_estate_scraper_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'real_estate_scraper_cron');
            $this->logger->info("Cron cleared");
        }
    }

    /**
     * Run cron job
     */
    public function run_cron_job()
    {
        $this->logger->info("=== CRON JOB STARTED ===");

        try {
            $scraper = Real_Estate_Scraper_Scraper::get_instance();
            $result = $scraper->run_scraper();

            if ($result['success']) {
                $this->logger->info("Cron job completed successfully");
            } else {
                $this->logger->error("Cron job failed: " . $result['message']);
            }

        } catch (Exception $e) {
            $this->logger->error("Cron job error: " . $e->getMessage());
        }

        $this->logger->info("=== CRON JOB FINISHED ===");
    }

    /**
     * Get available cron intervals
     */
    public function get_cron_intervals()
    {
        return array(
            '15min' => array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes', 'real-estate-scraper')
            ),
            '30min' => array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display' => __('Every 30 minutes', 'real-estate-scraper')
            ),
            'hourly' => array(
                'interval' => HOUR_IN_SECONDS,
                'display' => __('Every hour', 'real-estate-scraper')
            ),
            '6hours' => array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 hours', 'real-estate-scraper')
            ),
            '12hours' => array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display' => __('Every 12 hours', 'real-estate-scraper')
            ),
            'daily' => array(
                'interval' => DAY_IN_SECONDS,
                'display' => __('Daily', 'real-estate-scraper')
            )
        );
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules)
    {
        $custom_intervals = $this->get_cron_intervals();

        foreach ($custom_intervals as $key => $interval) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $interval;
            }
        }

        return $schedules;
    }

    /**
     * Get next cron run time
     */
    public function get_next_run_time()
    {
        $timestamp = wp_next_scheduled('real_estate_scraper_cron');

        if ($timestamp) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return __('Not scheduled', 'real-estate-scraper');
    }

    /**
     * Get last cron run time
     */
    public function get_last_run_time()
    {
        $logs = $this->logger->get_today_logs();

        foreach (array_reverse($logs) as $log) {
            if (strpos($log, 'CRON JOB STARTED') !== false) {
                preg_match('/\[(.*?)\]/', $log, $matches);
                if (isset($matches[1])) {
                    return $matches[1];
                }
            }
        }

        return __('Never', 'real-estate-scraper');
    }

    /**
     * Update cron interval
     */
    public function update_cron_interval($new_interval)
    {
        $this->options['cron_interval'] = $new_interval;

        // Reschedule cron
        $this->schedule_cron();

        $this->logger->info("Cron interval updated to: {$new_interval}");
    }

    /**
     * Test cron functionality
     */
    public function test_cron()
    {
        $this->logger->info("=== CRON TEST STARTED ===");

        try {
            // Test if cron can run
            $this->run_cron_job();

            $this->logger->info("Cron test completed successfully");
            return array(
                'success' => true,
                'message' => __('Cron test completed successfully.', 'real-estate-scraper')
            );

        } catch (Exception $e) {
            $this->logger->error("Cron test failed: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => __('Cron test failed: ', 'real-estate-scraper') . $e->getMessage()
            );
        }
    }
}


