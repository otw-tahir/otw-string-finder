<?php
/**
 * AJAX Handler Class
 * 
 * Handles all AJAX/REST API requests for the plugin.
 */

namespace OTW\StringFinder;

if (!defined('ABSPATH')) {
    exit;
}

class Ajax_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        // File search endpoints
        add_action('wp_ajax_otw_sf_init_file_search', [$this, 'init_file_search']);
        add_action('wp_ajax_otw_sf_process_file_batch', [$this, 'process_file_batch']);
        add_action('wp_ajax_otw_sf_cancel_file_search', [$this, 'cancel_file_search']);
        
        // Database search endpoints
        add_action('wp_ajax_otw_sf_init_db_search', [$this, 'init_db_search']);
        add_action('wp_ajax_otw_sf_process_db_batch', [$this, 'process_db_batch']);
        add_action('wp_ajax_otw_sf_cancel_db_search', [$this, 'cancel_db_search']);
        
        // File editing endpoints
        add_action('wp_ajax_otw_sf_get_file_content', [$this, 'get_file_content']);
        add_action('wp_ajax_otw_sf_save_file', [$this, 'save_file']);
        
        // Database editing endpoints
        add_action('wp_ajax_otw_sf_get_db_value', [$this, 'get_db_value']);
        add_action('wp_ajax_otw_sf_save_db_value', [$this, 'save_db_value']);
        
        // Get results
        add_action('wp_ajax_otw_sf_get_results', [$this, 'get_results']);
    }
    
    /**
     * Verify request
     */
    private function verify_request() {
        if (!current_user_can(Plugin::$capability)) {
            wp_send_json_error(['message' => __('Permission denied', 'otw-string-finder')], 403);
        }
        
        if (!check_ajax_referer('otw_sf_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid nonce', 'otw-string-finder')], 403);
        }
        
        return true;
    }
    
    /**
     * Initialize file search
     */
    public function init_file_search() {
        $this->verify_request();
        
        $directory = sanitize_text_field($_POST['directory'] ?? '');
        $search_string = wp_unslash($_POST['search_string'] ?? '');
        $is_regex = !empty($_POST['is_regex']);
        
        if (empty($search_string)) {
            wp_send_json_error(['message' => __('Search string is required', 'otw-string-finder')]);
        }
        
        // Validate regex if needed
        if ($is_regex && @preg_match($search_string, '') === false) {
            wp_send_json_error(['message' => __('Invalid regular expression', 'otw-string-finder')]);
        }
        
        $scanner = new File_Scanner();
        $session = $scanner->init_search($directory, $search_string, $is_regex);
        
        wp_send_json_success($session);
    }
    
    /**
     * Process file search batch
     */
    public function process_file_batch() {
        $this->verify_request();
        
        $search_id = sanitize_text_field($_POST['search_id'] ?? '');
        
        if (empty($search_id)) {
            wp_send_json_error(['message' => __('Search ID is required', 'otw-string-finder')]);
        }
        
        $scanner = new File_Scanner();
        $result = $scanner->process_batch($search_id);
        
        if (isset($result['error'])) {
            wp_send_json_error($result);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Cancel file search
     */
    public function cancel_file_search() {
        $this->verify_request();
        
        $search_id = sanitize_text_field($_POST['search_id'] ?? '');
        
        if (empty($search_id)) {
            wp_send_json_error(['message' => __('Search ID is required', 'otw-string-finder')]);
        }
        
        $scanner = new File_Scanner();
        $result = $scanner->cancel_search($search_id);
        
        wp_send_json_success($result);
    }
    
    /**
     * Initialize database search
     */
    public function init_db_search() {
        $this->verify_request();
        
        $search_string = wp_unslash($_POST['search_string'] ?? '');
        $is_regex = !empty($_POST['is_regex']);
        $tables = isset($_POST['tables']) ? array_map('sanitize_text_field', (array) $_POST['tables']) : [];
        
        if (empty($search_string)) {
            wp_send_json_error(['message' => __('Search string is required', 'otw-string-finder')]);
        }
        
        // Validate regex if needed
        if ($is_regex && @preg_match($search_string, '') === false) {
            wp_send_json_error(['message' => __('Invalid regular expression', 'otw-string-finder')]);
        }
        
        $scanner = new Database_Scanner();
        $session = $scanner->init_search($search_string, $is_regex, $tables);
        
        wp_send_json_success($session);
    }
    
    /**
     * Process database search batch
     */
    public function process_db_batch() {
        $this->verify_request();
        
        $search_id = sanitize_text_field($_POST['search_id'] ?? '');
        
        if (empty($search_id)) {
            wp_send_json_error(['message' => __('Search ID is required', 'otw-string-finder')]);
        }
        
        $scanner = new Database_Scanner();
        $result = $scanner->process_batch($search_id);
        
        if (isset($result['error'])) {
            wp_send_json_error($result);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Cancel database search
     */
    public function cancel_db_search() {
        $this->verify_request();
        
        $search_id = sanitize_text_field($_POST['search_id'] ?? '');
        
        if (empty($search_id)) {
            wp_send_json_error(['message' => __('Search ID is required', 'otw-string-finder')]);
        }
        
        $scanner = new Database_Scanner();
        $result = $scanner->cancel_search($search_id);
        
        wp_send_json_success($result);
    }
    
    /**
     * Get file content for editing
     */
    public function get_file_content() {
        $this->verify_request();
        
        $file = sanitize_text_field($_POST['file'] ?? '');
        $line = absint($_POST['line'] ?? 0);
        
        if (empty($file)) {
            wp_send_json_error(['message' => __('File path is required', 'otw-string-finder')]);
        }
        
        // Security check - file must be within WordPress
        $real_path = realpath($file);
        $abspath = realpath(ABSPATH);
        
        if (!$real_path || strpos($real_path, $abspath) !== 0) {
            wp_send_json_error(['message' => __('Invalid file path', 'otw-string-finder')]);
        }
        
        if (!is_readable($real_path)) {
            wp_send_json_error(['message' => __('File is not readable', 'otw-string-finder')]);
        }
        
        $content = file_get_contents($real_path);
        
        if ($content === false) {
            wp_send_json_error(['message' => __('Could not read file', 'otw-string-finder')]);
        }
        
        // Determine file type for syntax highlighting
        $extension = pathinfo($real_path, PATHINFO_EXTENSION);
        $language = $this->get_language_from_extension($extension);
        
        wp_send_json_success([
            'content' => $content,
            'language' => $language,
            'file' => $file,
            'line' => $line,
            'writable' => is_writable($real_path),
        ]);
    }
    
    /**
     * Save file content
     */
    public function save_file() {
        $this->verify_request();
        
        if (!current_user_can('edit_themes')) {
            wp_send_json_error(['message' => __('You do not have permission to edit files', 'otw-string-finder')]);
        }
        
        $file = sanitize_text_field($_POST['file'] ?? '');
        $content = wp_unslash($_POST['content'] ?? '');
        
        if (empty($file)) {
            wp_send_json_error(['message' => __('File path is required', 'otw-string-finder')]);
        }
        
        // Security check - file must be within WordPress
        $real_path = realpath($file);
        $abspath = realpath(ABSPATH);
        
        if (!$real_path || strpos($real_path, $abspath) !== 0) {
            wp_send_json_error(['message' => __('Invalid file path', 'otw-string-finder')]);
        }
        
        if (!is_writable($real_path)) {
            wp_send_json_error(['message' => __('File is not writable', 'otw-string-finder')]);
        }
        
        // Create backup
        $backup_dir = WP_CONTENT_DIR . '/otw-string-finder-backups/';
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $backup_file = $backup_dir . basename($file) . '.' . time() . '.bak';
        copy($real_path, $backup_file);
        
        // Save file
        $result = file_put_contents($real_path, $content);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Could not save file', 'otw-string-finder')]);
        }
        
        wp_send_json_success([
            'message' => __('File saved successfully', 'otw-string-finder'),
            'backup' => $backup_file,
        ]);
    }
    
    /**
     * Get database value for editing
     */
    public function get_db_value() {
        $this->verify_request();
        
        $table = sanitize_text_field($_POST['table'] ?? '');
        $column = sanitize_text_field($_POST['column'] ?? '');
        $primary_key = sanitize_text_field($_POST['primary_key'] ?? '');
        $primary_value = sanitize_text_field($_POST['primary_value'] ?? '');
        
        if (empty($table) || empty($column) || empty($primary_key)) {
            wp_send_json_error(['message' => __('Missing required parameters', 'otw-string-finder')]);
        }
        
        $scanner = new Database_Scanner();
        $value = $scanner->get_value($table, $column, $primary_key, $primary_value);
        
        if (is_wp_error($value)) {
            wp_send_json_error(['message' => $value->get_error_message()]);
        }
        
        wp_send_json_success([
            'value' => $value,
            'table' => $table,
            'column' => $column,
            'primary_key' => $primary_key,
            'primary_value' => $primary_value,
        ]);
    }
    
    /**
     * Save database value
     */
    public function save_db_value() {
        $this->verify_request();
        
        if (!current_user_can('edit_themes')) {
            wp_send_json_error(['message' => __('You do not have permission to edit the database', 'otw-string-finder')]);
        }
        
        $table = sanitize_text_field($_POST['table'] ?? '');
        $column = sanitize_text_field($_POST['column'] ?? '');
        $primary_key = sanitize_text_field($_POST['primary_key'] ?? '');
        $primary_value = sanitize_text_field($_POST['primary_value'] ?? '');
        $new_value = wp_unslash($_POST['value'] ?? '');
        
        if (empty($table) || empty($column) || empty($primary_key)) {
            wp_send_json_error(['message' => __('Missing required parameters', 'otw-string-finder')]);
        }
        
        $scanner = new Database_Scanner();
        $result = $scanner->update_value($table, $column, $primary_key, $primary_value, $new_value);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => __('Database value saved successfully', 'otw-string-finder'),
        ]);
    }
    
    /**
     * Get all results for a search
     */
    public function get_results() {
        $this->verify_request();
        
        $search_id = sanitize_text_field($_POST['search_id'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'file');
        
        if (empty($search_id)) {
            wp_send_json_error(['message' => __('Search ID is required', 'otw-string-finder')]);
        }
        
        if ($type === 'database') {
            $scanner = new Database_Scanner();
        } else {
            $scanner = new File_Scanner();
        }
        
        $results = $scanner->get_results($search_id);
        
        wp_send_json_success([
            'results' => $results,
            'count' => count($results),
        ]);
    }
    
    /**
     * Get language from file extension
     */
    private function get_language_from_extension($extension) {
        $map = [
            'php' => 'php',
            'js' => 'javascript',
            'jsx' => 'javascript',
            'ts' => 'typescript',
            'tsx' => 'typescript',
            'css' => 'css',
            'scss' => 'scss',
            'sass' => 'sass',
            'less' => 'less',
            'html' => 'html',
            'htm' => 'html',
            'json' => 'json',
            'xml' => 'xml',
            'sql' => 'sql',
            'md' => 'markdown',
            'txt' => 'plaintext',
            'yaml' => 'yaml',
            'yml' => 'yaml',
        ];
        
        return $map[strtolower($extension)] ?? 'plaintext';
    }
}
