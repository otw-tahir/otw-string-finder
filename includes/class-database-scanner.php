<?php
/**
 * Database Scanner Class
 * 
 * Handles searching through database with proper batch processing,
 * memory management, serialized data support, and timeout prevention.
 */

namespace OTW\StringFinder;

if (!defined('ABSPATH')) {
    exit;
}

class Database_Scanner {
    
    /**
     * Batch size for database rows (very small for memory safety)
     */
    private $batch_size = 10;
    
    /**
     * Max results per batch to prevent memory issues
     */
    private $max_results_per_batch = 50;
    
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
     * Tables to skip
     */
    private $skip_tables = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->batch_size = get_option('otw_sf_batch_size_db', 50);
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
     * Initialize a database search
     * 
     * @param string $search_string Search string
     * @param bool $is_regex Is regex search
     * @param array $tables Tables to search (empty = all)
     * @return array Search session data
     */
    public function init_search($search_string, $is_regex = false, $tables = []) {
        global $wpdb;
        
        $search_id = wp_generate_uuid4();
        
        // Get list of tables and their info
        $table_info = $this->get_table_info($tables);
        
        // Calculate total rows across all tables
        $total_rows = 0;
        foreach ($table_info as $table) {
            $total_rows += $table['row_count'];
        }
        
        // Store session data
        $session = [
            'search_id' => $search_id,
            'search_string' => $search_string,
            'is_regex' => $is_regex,
            'tables' => $table_info,
            'current_table_index' => 0,
            'current_row_offset' => 0,
            'total_rows' => $total_rows,
            'processed_rows' => 0,
            'results' => [],
            'status' => 'running',
            'started_at' => time(),
        ];
        
        set_transient('otw_sf_db_session_' . $search_id, $session, HOUR_IN_SECONDS);
        
        return $session;
    }
    
    /**
     * Get table information
     */
    private function get_table_info($filter_tables = []) {
        global $wpdb;
        
        $tables = [];
        $all_tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        
        foreach ($all_tables as $table_row) {
            $table_name = $table_row[0];
            
            // Apply filter if specified
            if (!empty($filter_tables) && !in_array($table_name, $filter_tables)) {
                continue;
            }
            
            // Skip tables in skip list
            if (in_array($table_name, $this->skip_tables)) {
                continue;
            }
            
            // Get column info
            $columns = $wpdb->get_results("DESCRIBE `{$table_name}`", ARRAY_A);
            
            // Find primary key and text columns
            $primary_key = null;
            $primary_type = null;
            $searchable_columns = [];
            
            foreach ($columns as $column) {
                if ($column['Key'] === 'PRI') {
                    $primary_key = $column['Field'];
                    $primary_type = strpos(strtolower($column['Type']), 'int') !== false ? 'int' : 'string';
                }
                
                // Only search text-based columns
                $type = strtolower($column['Type']);
                if (strpos($type, 'char') !== false || 
                    strpos($type, 'text') !== false || 
                    strpos($type, 'blob') !== false ||
                    strpos($type, 'json') !== false) {
                    $searchable_columns[] = $column['Field'];
                }
            }
            
            // Get row count
            $count_result = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
            
            if (!empty($searchable_columns)) {
                $tables[] = [
                    'name' => $table_name,
                    'primary_key' => $primary_key,
                    'primary_type' => $primary_type,
                    'columns' => $searchable_columns,
                    'row_count' => (int) $count_result,
                ];
            }
        }
        
        return $tables;
    }
    
    /**
     * Process a batch of database rows
     * 
     * @param string $search_id Search session ID
     * @return array Updated session data
     */
    public function process_batch($search_id) {
        global $wpdb;
        
        $session = get_transient('otw_sf_db_session_' . $search_id);
        
        if (!$session) {
            return ['error' => 'Session not found', 'status' => 'error'];
        }
        
        if ($session['status'] === 'cancelled') {
            return $session;
        }
        
        $this->start_time = microtime(true);
        $batch_results = [];
        $processed_in_batch = 0;
        
        $current_table_index = $session['current_table_index'];
        $current_offset = $session['current_row_offset'];
        
        while (!$this->should_stop() && $current_table_index < count($session['tables'])) {
            // Also stop if we have too many results in this batch
            if (count($batch_results) >= $this->max_results_per_batch) {
                break;
            }
            
            $table = $session['tables'][$current_table_index];
            $table_name = $table['name'];
            $primary_key = $table['primary_key'];
            
            // If table has no primary key, skip it (we can't safely paginate)
            if (!$primary_key) {
                $current_table_index++;
                $current_offset = 0;
                continue;
            }
            
            // First, just get the primary keys for this batch (lightweight query)
            $pk_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT `{$primary_key}` FROM `{$table_name}` LIMIT %d OFFSET %d",
                    $this->batch_size,
                    $current_offset
                ),
                ARRAY_A
            );
            
            if (empty($pk_rows)) {
                // Move to next table
                $current_table_index++;
                $current_offset = 0;
                continue;
            }
            
            // Store row count before processing (for offset calculation)
            $fetched_rows_count = count($pk_rows);
            
            // Process each row individually to control memory
            foreach ($pk_rows as $pk_row) {
                if ($this->should_stop() || count($batch_results) >= $this->max_results_per_batch) {
                    break;
                }
                
                $primary_value = $pk_row[$primary_key];
                
                // Fetch each column separately for this row
                foreach ($table['columns'] as $column) {
                    if ($this->should_stop() || count($batch_results) >= $this->max_results_per_batch) {
                        break;
                    }
                    
                    // Get just this one column value
                    $value = $wpdb->get_var($wpdb->prepare(
                        "SELECT `{$column}` FROM `{$table_name}` WHERE `{$primary_key}` = %s LIMIT 1",
                        $primary_value
                    ));
                    
                    if (empty($value)) {
                        continue;
                    }
                    
                    // Skip very large values (> 500KB) to prevent memory issues
                    if (strlen($value) > 524288) {
                        continue;
                    }
                    
                    $matches = $this->search_value($value, $session['search_string'], $session['is_regex']);
                    
                    // Free the value immediately
                    unset($value);
                    
                    if (!empty($matches)) {
                        // Limit matches per column to prevent explosion
                        $match_count = 0;
                        foreach ($matches as $match) {
                            if ($match_count >= 3) break; // Max 3 matches per cell
                            
                            $batch_results[] = [
                                'type' => 'database',
                                'table' => $table_name,
                                'column' => $column,
                                'primary_key' => $primary_key,
                                'primary_value' => $primary_value,
                                'primary_type' => $table['primary_type'],
                                'preview' => $match['preview'],
                                'is_serialized' => $match['is_serialized'],
                                'path' => $match['path'] ?? '',
                                'edit_url' => $this->get_edit_url($table_name, $column, $primary_key, $primary_value),
                            ];
                            $match_count++;
                        }
                        unset($matches);
                    }
                }
                
                $processed_in_batch++;
                $session['processed_rows']++;
            }
            
            // Free memory
            unset($pk_rows);
            
            $current_offset += $fetched_rows_count;
            
            // Check if we've processed all rows in this table
            if ($fetched_rows_count < $this->batch_size) {
                $current_table_index++;
                $current_offset = 0;
            }
        }
        
        // Update session
        $session['current_table_index'] = $current_table_index;
        $session['current_row_offset'] = $current_offset;
        
        // Store batch results in chunks to avoid memory issues
        if (!empty($batch_results)) {
            // Get current result chunk index
            $result_chunk_index = isset($session['result_chunk_index']) ? $session['result_chunk_index'] : 0;
            
            // Store this batch as a new chunk
            set_transient('otw_sf_db_results_' . $search_id . '_' . $result_chunk_index, $batch_results, HOUR_IN_SECONDS);
            
            // Update chunk index and total count
            $session['result_chunk_index'] = $result_chunk_index + 1;
            $session['total_results'] = ($session['total_results'] ?? 0) + count($batch_results);
        }
        
        // Check if completed
        if ($current_table_index >= count($session['tables'])) {
            $session['status'] = 'completed';
        }
        
        set_transient('otw_sf_db_session_' . $search_id, $session, HOUR_IN_SECONDS);
        
        // Return response
        return [
            'search_id' => $search_id,
            'status' => $session['status'],
            'total_rows' => $session['total_rows'],
            'processed_rows' => $session['processed_rows'],
            'total_results' => $session['total_results'] ?? 0,
            'current_table' => isset($session['tables'][$current_table_index]) 
                ? $session['tables'][$current_table_index]['name'] 
                : 'completed',
            'progress' => $session['total_rows'] > 0 
                ? round(($session['processed_rows'] / $session['total_rows']) * 100, 1) 
                : 0,
            'batch_results' => $batch_results,
            'batch_count' => count($batch_results),
        ];
    }
    
    /**
     * Search within a value (handles serialized data)
     */
    private function search_value($value, $search_string, $is_regex = false) {
        $results = [];
        
        // Check if value is serialized
        $unserialized = @unserialize($value);
        
        if ($unserialized !== false || $value === 'b:0;') {
            // It's serialized data - search recursively
            $matches = $this->search_serialized($unserialized, $search_string, $is_regex, '');
            foreach ($matches as $match) {
                $match['is_serialized'] = true;
                $results[] = $match;
            }
        } else {
            // Regular string search
            if ($this->string_matches($value, $search_string, $is_regex)) {
                $results[] = [
                    'preview' => $this->create_preview($value, $search_string, $is_regex),
                    'is_serialized' => false,
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Search recursively through serialized data
     */
    private function search_serialized($data, $search_string, $is_regex, $path) {
        $results = [];
        
        if (is_string($data)) {
            if ($this->string_matches($data, $search_string, $is_regex)) {
                $results[] = [
                    'preview' => $this->create_preview($data, $search_string, $is_regex),
                    'path' => $path,
                ];
            }
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $new_path = $path ? "{$path}[{$key}]" : "[{$key}]";
                $results = array_merge($results, $this->search_serialized($value, $search_string, $is_regex, $new_path));
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                $new_path = $path ? "{$path}->{$key}" : "->{$key}";
                $results = array_merge($results, $this->search_serialized($value, $search_string, $is_regex, $new_path));
            }
        }
        
        return $results;
    }
    
    /**
     * Check if string matches search
     */
    private function string_matches($haystack, $needle, $is_regex = false) {
        if ($is_regex) {
            return @preg_match($needle, $haystack) === 1;
        }
        return stripos($haystack, $needle) !== false;
    }
    
    /**
     * Create a preview snippet
     */
    private function create_preview($content, $search_string, $is_regex = false) {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
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
        
        return esc_html($content);
    }
    
    /**
     * Get edit URL for a database entry
     */
    private function get_edit_url($table, $column, $primary_key, $primary_value) {
        return add_query_arg([
            'page' => 'otw-string-finder',
            'action' => 'edit-db',
            'table' => $table,
            'column' => $column,
            'primary_key' => $primary_key,
            'primary_value' => $primary_value,
        ], admin_url('tools.php'));
    }
    
    /**
     * Cancel a search
     */
    public function cancel_search($search_id) {
        $session = get_transient('otw_sf_db_session_' . $search_id);
        if ($session) {
            $session['status'] = 'cancelled';
            set_transient('otw_sf_db_session_' . $search_id, $session, HOUR_IN_SECONDS);
        }
        return ['status' => 'cancelled'];
    }
    
    /**
     * Get all results for a search (collected from all chunks)
     */
    public function get_results($search_id) {
        $session = get_transient('otw_sf_db_session_' . $search_id);
        $all_results = [];
        
        if ($session && isset($session['result_chunk_index'])) {
            $chunk_count = $session['result_chunk_index'];
            for ($i = 0; $i < $chunk_count; $i++) {
                $chunk_results = get_transient('otw_sf_db_results_' . $search_id . '_' . $i);
                if ($chunk_results) {
                    $all_results = array_merge($all_results, $chunk_results);
                }
            }
        }
        
        return $all_results;
    }
    
    /**
     * Clean up a search session
     */
    public function cleanup_search($search_id) {
        $session = get_transient('otw_sf_db_session_' . $search_id);
        
        // Clean up all result chunks
        if ($session && isset($session['result_chunk_index'])) {
            $chunk_count = $session['result_chunk_index'];
            for ($i = 0; $i < $chunk_count; $i++) {
                delete_transient('otw_sf_db_results_' . $search_id . '_' . $i);
            }
        }
        
        delete_transient('otw_sf_db_session_' . $search_id);
    }
    
    /**
     * Get available tables for searching
     */
    public static function get_available_tables() {
        global $wpdb;
        
        $tables = [];
        $all_tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        
        foreach ($all_tables as $table_row) {
            $table_name = $table_row[0];
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
            $tables[$table_name] = sprintf('%s (%s rows)', $table_name, number_format($count));
        }
        
        return $tables;
    }
    
    /**
     * Get a single database value for editing
     */
    public function get_value($table, $column, $primary_key, $primary_value) {
        global $wpdb;
        
        // Validate table and column exist
        $tables = $wpdb->get_col('SHOW TABLES');
        if (!in_array($table, $tables)) {
            return new \WP_Error('invalid_table', __('Invalid table name', 'otw-string-finder'));
        }
        
        $columns = $wpdb->get_col("DESCRIBE `{$table}`", 0);
        if (!in_array($column, $columns) || !in_array($primary_key, $columns)) {
            return new \WP_Error('invalid_column', __('Invalid column name', 'otw-string-finder'));
        }
        
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `{$column}` FROM `{$table}` WHERE `{$primary_key}` = %s",
                $primary_value
            )
        );
        
        return $value;
    }
    
    /**
     * Update a database value
     */
    public function update_value($table, $column, $primary_key, $primary_value, $new_value) {
        global $wpdb;
        
        // Validate table and column exist
        $tables = $wpdb->get_col('SHOW TABLES');
        if (!in_array($table, $tables)) {
            return new \WP_Error('invalid_table', __('Invalid table name', 'otw-string-finder'));
        }
        
        $columns = $wpdb->get_col("DESCRIBE `{$table}`", 0);
        if (!in_array($column, $columns) || !in_array($primary_key, $columns)) {
            return new \WP_Error('invalid_column', __('Invalid column name', 'otw-string-finder'));
        }
        
        $result = $wpdb->update(
            $table,
            [$column => $new_value],
            [$primary_key => $primary_value],
            ['%s'],
            ['%s']
        );
        
        if ($result === false) {
            return new \WP_Error('update_failed', $wpdb->last_error);
        }
        
        return true;
    }
}
