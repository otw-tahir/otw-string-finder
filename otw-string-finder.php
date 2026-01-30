<?php
/**
 * Plugin Name: OTW String Finder
 * Plugin URI: https://developer.starter.dev/
 * Description: A high-performance string search tool for WordPress files and database with batch processing, memory management, and crash prevention.
 * Version: 1.0.0
 * Author: Developer Starter
 * Author URI: https://developer.starter.dev/
 * Text Domain: otw-string-finder
 * License: GPL2
 */

namespace OTW\StringFinder;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('OTW_SF_VERSION', '1.0.0');
define('OTW_SF_PLUGIN_FILE', __FILE__);
define('OTW_SF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OTW_SF_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class Plugin {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Plugin capability for access
     */
    public static $capability = 'manage_options';
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once OTW_SF_PLUGIN_DIR . 'includes/class-file-scanner.php';
        require_once OTW_SF_PLUGIN_DIR . 'includes/class-database-scanner.php';
        require_once OTW_SF_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        require_once OTW_SF_PLUGIN_DIR . 'includes/class-admin.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'load_textdomain']);
        
        // Initialize components
        new Admin();
        new Ajax_Handler();
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('otw-string-finder', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Get plugin URL
     */
    public function plugin_url() {
        return OTW_SF_PLUGIN_URL;
    }
    
    /**
     * Get plugin path
     */
    public function plugin_path() {
        return OTW_SF_PLUGIN_DIR;
    }
    
    /**
     * Activation hook
     */
    public static function activate() {
        // Create necessary options
        add_option('otw_sf_batch_size_files', 100);
        add_option('otw_sf_batch_size_db', 500);
        add_option('otw_sf_max_execution_time', 25);
    }
    
    /**
     * Deactivation hook
     */
    public static function deactivate() {
        // Clean up transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_otw_sf_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_otw_sf_%'");
    }
}

// Activation/Deactivation hooks
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

/**
 * Get plugin instance
 */
function otw_string_finder() {
    return Plugin::instance();
}

// Initialize plugin
add_action('plugins_loaded', 'OTW\\StringFinder\\otw_string_finder');
