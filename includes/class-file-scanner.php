<?php
/**
 * File Scanner Class
 * 
 * Handles searching through files with proper batch processing,
 * memory management, and timeout prevention.
 */

namespace OTW\StringFinder;

if (!defined('ABSPATH')) {
    exit;
}

class File_Scanner {
    
    /**
     * Batch size for file processing
     */
    private $batch_size = 100;
    
    /**
     * Max execution time (in seconds)
     */
    private $max_execution_time = 25;
    
    /**
     * Start time
     */
    private $start_time;
    
    /**
     * Memory limit buffer (256KB)
     */
    private $memory_buffer = 262144;
    
    /**
     * Max memory limit
     */
    private $max_memory;
    
    /**
     * File extensions to skip
     */
    private $skip_extensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm',
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'ttf', 'woff', 'woff2', 'eot', 'otf',
        'exe', 'dll', 'so', 'dylib',
        'min.js', 'min.css', 'map'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->batch_size = get_option('otw_sf_batch_size_files', 100);
        $this->max_execution_time = get_option('otw_sf_max_execution_time', 25);
        $this->start_time = microtime(true);
        $this->set_memory_limit();
    }
    
    /**
     * Set memory limit from PHP config
     */
    private function set_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        $this->max_memory = $this->parse_size($memory_limit);
    }
    
    /**
     * Parse PHP size string to bytes
     */
    private function parse_size($size) {
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }
    
    /**
     * Check if we're nearing execution time limit
     */
    private function is_nearing_timeout() {
        $elapsed = microtime(true) - $this->start_time;
        return $elapsed >= ($this->max_execution_time - 2);
    }
    
    /**
     * Check if we're nearing memory limit
     */
    private function is_nearing_memory_limit() {
        if ($this->max_memory <= 0) {
            return false;
        }
        $current_memory = memory_get_usage(true);
        return ($current_memory + $this->memory_buffer) >= $this->max_memory;
    }
    
    /**
     * Should we stop processing?
     */
    private function should_stop() {
        return $this->is_nearing_timeout() || $this->is_nearing_memory_limit();
    }
    
    /**
     * Initialize a new search
     * 
     * @param string $directory Directory to search
     * @param string $search_string Search string
     * @param bool $is_regex Is regex search
     * @return array Search session data
     */
    public function init_search($directory, $search_string, $is_regex = false) {
        $search_id = wp_generate_uuid4();
        
        // Get file list
        $files = $this->get_file_list($directory);
        $total_files = count($files);
        
        // Store files in chunks to avoid large transients
        $chunks = array_chunk($files, 500);
        foreach ($chunks as $index => $chunk) {
            set_transient('otw_sf_files_' . $search_id . '_' . $index, $chunk, HOUR_IN_SECONDS);
        }
        
        // Store search session data
        $session = [
            'search_id' => $search_id,
            'directory' => $directory,
            'search_string' => $search_string,
            'is_regex' => $is_regex,
            'total_files' => $total_files,
            'processed_files' => 0,
            'current_file_index' => 0,
            'total_chunks' => count($chunks),
            'results' => [],
            'status' => 'running',
            'started_at' => time(),
        ];
        
        set_transient('otw_sf_session_' . $search_id, $session, HOUR_IN_SECONDS);
        
        return $session;
    }
    
    /**
     * Get list of all files in directory
     */
    private function get_file_list($directory) {
        $files = [];
        $path = $this->resolve_directory($directory);
        
        if (!$path || !is_dir($path)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !$this->should_skip_file($file->getPathname())) {
                $files[] = $file->getPathname();
            }
            
            // Check memory during file listing
            if ($this->is_nearing_memory_limit()) {
                break;
            }
        }
        
        return $files;
    }
    
    /**
     * Resolve directory identifier to actual path
     */
    private function resolve_directory($directory) {
        switch ($directory) {
            case 'core':
                return ABSPATH;
            case 'wp-content':
                return WP_CONTENT_DIR;
            case 't--': // All themes
                return get_theme_root();
            case 'p--': // All plugins
                return WP_PLUGIN_DIR;
            case 'mu-plugins':
                return WPMU_PLUGIN_DIR;
            default:
                // Theme: t-theme-slug
                if (strpos($directory, 't-') === 0) {
                    $theme_slug = substr($directory, 2);
                    return get_theme_root() . '/' . $theme_slug;
                }
                // Plugin: p-plugin-slug
                if (strpos($directory, 'p-') === 0) {
                    $plugin_slug = substr($directory, 2);
                    return WP_PLUGIN_DIR . '/' . $plugin_slug;
                }
                return null;
        }
    }
    
    /**
     * Check if file should be skipped
     */
    private function should_skip_file($filepath) {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        
        // Skip by extension
        if (in_array($extension, $this->skip_extensions)) {
            return true;
        }
        
        // Skip minified files
        $filename = basename($filepath);
        if (preg_match('/\.min\.(js|css)$/', $filename)) {
            return true;
        }
        
        // Skip map files
        if (preg_match('/\.(js|css)\.map$/', $filename)) {
            return true;
        }
        
        // Skip vendor/node_modules directories
        if (strpos($filepath, '/vendor/') !== false || 
            strpos($filepath, '/node_modules/') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Process a batch of files
     * 
     * @param string $search_id Search session ID
     * @return array Updated session data
     */
    public function process_batch($search_id) {
        $session = get_transient('otw_sf_session_' . $search_id);
        
        if (!$session) {
            return ['error' => 'Session not found', 'status' => 'error'];
        }
        
        if ($session['status'] === 'cancelled') {
            return $session;
        }
        
        $this->start_time = microtime(true);
        $processed_count = 0;
        $batch_results = [];
        
        $current_index = $session['current_file_index'];
        $chunk_index = floor($current_index / 500);
        
        while ($processed_count < $this->batch_size && !$this->should_stop()) {
            // Get current chunk
            $files = get_transient('otw_sf_files_' . $search_id . '_' . $chunk_index);
            
            if (!$files) {
                // No more files
                $session['status'] = 'completed';
                break;
            }
            
            $local_index = $current_index % 500;
            
            if (!isset($files[$local_index])) {
                // Move to next chunk
                $chunk_index++;
                $current_index = $chunk_index * 500;
                continue;
            }
            
            $file_path = $files[$local_index];
            
            // Search the file
            $matches = $this->search_file($file_path, $session['search_string'], $session['is_regex']);
            
            if (!empty($matches)) {
                $batch_results = array_merge($batch_results, $matches);
            }
            
            $current_index++;
            $processed_count++;
            $session['processed_files']++;
        }
        
        // Update session
        $session['current_file_index'] = $current_index;
        
        // Store batch results
        if (!empty($batch_results)) {
            $existing_results = get_transient('otw_sf_results_' . $search_id) ?: [];
            $all_results = array_merge($existing_results, $batch_results);
            set_transient('otw_sf_results_' . $search_id, $all_results, HOUR_IN_SECONDS);
        }
        
        // Check if completed
        if ($session['processed_files'] >= $session['total_files']) {
            $session['status'] = 'completed';
        }
        
        set_transient('otw_sf_session_' . $search_id, $session, HOUR_IN_SECONDS);
        
        // Return response
        return [
            'search_id' => $search_id,
            'status' => $session['status'],
            'total_files' => $session['total_files'],
            'processed_files' => $session['processed_files'],
            'progress' => $session['total_files'] > 0 
                ? round(($session['processed_files'] / $session['total_files']) * 100, 1) 
                : 0,
            'batch_results' => $batch_results,
            'batch_count' => count($batch_results),
        ];
    }
    
    /**
     * Search within a single file
     */
    private function search_file($filepath, $search_string, $is_regex = false) {
        $results = [];
        
        if (!is_file($filepath) || !is_readable($filepath)) {
            return $results;
        }
        
        // Skip files larger than 5MB
        if (filesize($filepath) > 5 * 1024 * 1024) {
            return $results;
        }
        
        $handle = @fopen($filepath, 'r');
        if (!$handle) {
            return $results;
        }
        
        $line_number = 0;
        $relative_path = str_replace(ABSPATH, '', $filepath);
        
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            
            // Check for match
            if ($is_regex) {
                if (@preg_match($search_string, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    $position = $matches[0][1];
                    $results[] = $this->create_result($filepath, $relative_path, $line_number, $position, $line, $search_string, true);
                }
            } else {
                $position = stripos($line, $search_string);
                if ($position !== false) {
                    $results[] = $this->create_result($filepath, $relative_path, $line_number, $position, $line, $search_string, false);
                }
            }
        }
        
        fclose($handle);
        
        // Also check if filename matches
        $filename = basename($filepath);
        if (stripos($filename, $search_string) !== false || 
            ($is_regex && @preg_match($search_string, $filename))) {
            array_unshift($results, [
                'type' => 'file',
                'path' => $filepath,
                'relative_path' => $relative_path,
                'line' => 0,
                'position' => 0,
                'preview' => __('Filename matches search', 'otw-string-finder'),
                'edit_url' => $this->get_edit_url($filepath, 0),
            ]);
        }
        
        return $results;
    }
    
    /**
     * Create a result entry
     */
    private function create_result($filepath, $relative_path, $line, $position, $content, $search_string, $is_regex) {
        return [
            'type' => 'file',
            'path' => $filepath,
            'relative_path' => $relative_path,
            'line' => $line,
            'position' => $position,
            'preview' => $this->create_preview($content, $search_string, $is_regex),
            'edit_url' => $this->get_edit_url($filepath, $line),
        ];
    }
    
    /**
     * Create a preview snippet
     */
    private function create_preview($content, $search_string, $is_regex = false) {
        $content = trim($content);
        
        // Limit length
        if (strlen($content) > 200) {
            if ($is_regex) {
                preg_match($search_string, $content, $matches, PREG_OFFSET_CAPTURE);
                $pos = isset($matches[0][1]) ? $matches[0][1] : 0;
            } else {
                $pos = stripos($content, $search_string);
            }
            
            $start = max(0, $pos - 50);
            $content = ($start > 0 ? '...' : '') . substr($content, $start, 200) . '...';
        }
        
        // Highlight match
        if ($is_regex) {
            $content = preg_replace($search_string, '<mark>$0</mark>', $content);
        } else {
            $content = preg_replace('/(' . preg_quote($search_string, '/') . ')/i', '<mark>$1</mark>', $content);
        }
        
        return esc_html($content);
    }
    
    /**
     * Get edit URL for a file
     */
    private function get_edit_url($filepath, $line = 0) {
        $file_type = 'core';
        $file_reference = '';
        
        // Determine file type
        if (strpos($filepath, WP_PLUGIN_DIR) !== false) {
            $file_type = 'plugin';
            $relative = str_replace(WP_PLUGIN_DIR . '/', '', $filepath);
            $parts = explode('/', $relative);
            $file_reference = $parts[0];
        } elseif (strpos($filepath, get_theme_root()) !== false) {
            $file_type = 'theme';
            $relative = str_replace(get_theme_root() . '/', '', $filepath);
            $parts = explode('/', $relative);
            $file_reference = $parts[0];
        }
        
        return add_query_arg([
            'page' => 'otw-string-finder',
            'action' => 'edit',
            'file' => urlencode($filepath),
            'line' => $line,
            'file_type' => $file_type,
            'file_reference' => $file_reference,
        ], admin_url('tools.php'));
    }
    
    /**
     * Cancel a search
     */
    public function cancel_search($search_id) {
        $session = get_transient('otw_sf_session_' . $search_id);
        if ($session) {
            $session['status'] = 'cancelled';
            set_transient('otw_sf_session_' . $search_id, $session, HOUR_IN_SECONDS);
        }
        return ['status' => 'cancelled'];
    }
    
    /**
     * Get all results for a search
     */
    public function get_results($search_id) {
        return get_transient('otw_sf_results_' . $search_id) ?: [];
    }
    
    /**
     * Clean up a search session
     */
    public function cleanup_search($search_id) {
        $session = get_transient('otw_sf_session_' . $search_id);
        
        if ($session) {
            // Delete file chunks
            for ($i = 0; $i < $session['total_chunks']; $i++) {
                delete_transient('otw_sf_files_' . $search_id . '_' . $i);
            }
        }
        
        delete_transient('otw_sf_session_' . $search_id);
        delete_transient('otw_sf_results_' . $search_id);
    }
    
    /**
     * Get available search locations
     */
    public static function get_search_locations() {
        $locations = [
            'core' => [
                'label' => __('Core', 'otw-string-finder'),
                'options' => [
                    'core' => __('Entire WordPress Directory', 'otw-string-finder'),
                    'wp-content' => __('wp-content Directory', 'otw-string-finder'),
                ]
            ],
            'themes' => [
                'label' => __('Themes', 'otw-string-finder'),
                'options' => [
                    't--' => __('All Themes', 'otw-string-finder'),
                ]
            ],
            'plugins' => [
                'label' => __('Plugins', 'otw-string-finder'),
                'options' => [
                    'p--' => __('All Plugins', 'otw-string-finder'),
                ]
            ],
        ];
        
        // Add individual themes
        $themes = wp_get_themes();
        foreach ($themes as $slug => $theme) {
            $locations['themes']['options']['t-' . $slug] = $theme->get('Name');
        }
        
        // Add individual plugins
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        foreach ($plugins as $file => $plugin) {
            $slug = dirname($file);
            if ($slug !== '.') {
                $locations['plugins']['options']['p-' . $slug] = $plugin['Name'];
            }
        }
        
        // Add mu-plugins if exists
        if (is_dir(WPMU_PLUGIN_DIR)) {
            $locations['plugins']['options']['mu-plugins'] = __('Must-Use Plugins', 'otw-string-finder');
        }
        
        return $locations;
    }
}
