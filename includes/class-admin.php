<?php
/**
 * Admin Class
 * 
 * Handles admin menu, pages, and assets.
 */

namespace OTW\StringFinder;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        add_management_page(
            __('OTW String Finder', 'otw-string-finder'),
            __('String Finder', 'otw-string-finder'),
            Plugin::$capability,
            'otw-string-finder',
            [$this, 'render_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_otw-string-finder') {
            return;
        }
        
        // Enqueue CodeMirror for file editing
        $cm_settings = wp_enqueue_code_editor(['type' => 'text/x-php']);
        
        // Enqueue our CSS
        wp_enqueue_style(
            'otw-string-finder',
            OTW_SF_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OTW_SF_VERSION
        );
        
        // Enqueue our JS
        wp_enqueue_script(
            'otw-string-finder',
            OTW_SF_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            OTW_SF_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('otw-string-finder', 'otwSF', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('otw_sf_nonce'),
            'strings' => [
                'searching' => __('Searching...', 'otw-string-finder'),
                'preparing' => __('Preparing search...', 'otw-string-finder'),
                'processing' => __('Processing...', 'otw-string-finder'),
                'completed' => __('Search completed', 'otw-string-finder'),
                'cancelled' => __('Search cancelled', 'otw-string-finder'),
                'noResults' => __('No results found', 'otw-string-finder'),
                'error' => __('An error occurred', 'otw-string-finder'),
                'confirmCancel' => __('Are you sure you want to cancel the search?', 'otw-string-finder'),
                'saving' => __('Saving...', 'otw-string-finder'),
                'saved' => __('Saved successfully', 'otw-string-finder'),
                'resultsFound' => __('%d results found', 'otw-string-finder'),
                'filesProcessed' => __('%d of %d files processed', 'otw-string-finder'),
                'rowsProcessed' => __('%d of %d rows processed', 'otw-string-finder'),
                'currentTable' => __('Scanning table: %s', 'otw-string-finder'),
            ],
        ]);
    }
    
    /**
     * Render admin page
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'search';
        
        switch ($action) {
            case 'edit':
                $this->render_file_editor();
                break;
            case 'edit-db':
                $this->render_db_editor();
                break;
            default:
                $this->render_search_page();
        }
    }
    
    /**
     * Render search page
     */
    private function render_search_page() {
        $file_locations = File_Scanner::get_search_locations();
        $db_tables = Database_Scanner::get_available_tables();
        ?>
        <div class="wrap otw-string-finder">
            <h1><?php esc_html_e('OTW String Finder', 'otw-string-finder'); ?></h1>
            
            <div class="otw-sf-search-container">
                <!-- Search Form -->
                <div class="otw-sf-search-form card">
                    <h2><?php esc_html_e('Search', 'otw-string-finder'); ?></h2>
                    
                    <form id="otw-sf-search-form">
                        <!-- Search Type Tabs -->
                        <div class="otw-sf-tabs">
                            <button type="button" class="otw-sf-tab active" data-tab="files">
                                <?php esc_html_e('Files', 'otw-string-finder'); ?>
                            </button>
                            <button type="button" class="otw-sf-tab" data-tab="database">
                                <?php esc_html_e('Database', 'otw-string-finder'); ?>
                            </button>
                        </div>
                        
                        <!-- Search String -->
                        <div class="otw-sf-field">
                            <label for="otw-sf-search-string"><?php esc_html_e('Search String', 'otw-string-finder'); ?></label>
                            <input type="text" id="otw-sf-search-string" name="search_string" class="regular-text" required>
                        </div>
                        
                        <!-- File Search Options -->
                        <div class="otw-sf-tab-content active" data-tab="files">
                            <div class="otw-sf-field">
                                <label for="otw-sf-directory"><?php esc_html_e('Search Location', 'otw-string-finder'); ?></label>
                                <select id="otw-sf-directory" name="directory">
                                    <?php foreach ($file_locations as $group_key => $group): ?>
                                        <optgroup label="<?php echo esc_attr($group['label']); ?>">
                                            <?php foreach ($group['options'] as $value => $label): ?>
                                                <option value="<?php echo esc_attr($value); ?>">
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Database Search Options -->
                        <div class="otw-sf-tab-content" data-tab="database">
                            <div class="otw-sf-field">
                                <label for="otw-sf-tables"><?php esc_html_e('Tables to Search', 'otw-string-finder'); ?></label>
                                <select id="otw-sf-tables" name="tables[]" multiple size="5">
                                    <option value="" selected><?php esc_html_e('All Tables', 'otw-string-finder'); ?></option>
                                    <?php foreach ($db_tables as $table => $label): ?>
                                        <option value="<?php echo esc_attr($table); ?>">
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple tables', 'otw-string-finder'); ?></p>
                            </div>
                        </div>
                        
                        <!-- Regex Option -->
                        <div class="otw-sf-field">
                            <label>
                                <input type="checkbox" id="otw-sf-regex" name="is_regex">
                                <?php esc_html_e('Use Regular Expression', 'otw-string-finder'); ?>
                            </label>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div class="otw-sf-buttons">
                            <button type="submit" class="button button-primary" id="otw-sf-search-btn">
                                <?php esc_html_e('Search', 'otw-string-finder'); ?>
                            </button>
                            <button type="button" class="button" id="otw-sf-cancel-btn" style="display:none;">
                                <?php esc_html_e('Cancel', 'otw-string-finder'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Progress Section -->
                <div class="otw-sf-progress card" id="otw-sf-progress" style="display:none;">
                    <h3><?php esc_html_e('Search Progress', 'otw-string-finder'); ?></h3>
                    <div class="otw-sf-progress-bar">
                        <div class="otw-sf-progress-fill" style="width: 0%"></div>
                    </div>
                    <div class="otw-sf-progress-text">
                        <span id="otw-sf-progress-percent">0%</span>
                        <span id="otw-sf-progress-details"></span>
                    </div>
                </div>
                
                <!-- Results Section -->
                <div class="otw-sf-results" id="otw-sf-results" style="display:none;">
                    <h3>
                        <?php esc_html_e('Results', 'otw-string-finder'); ?>
                        <span id="otw-sf-results-count"></span>
                    </h3>
                    
                    <table class="wp-list-table widefat fixed striped" id="otw-sf-results-table">
                        <thead>
                            <tr>
                                <th class="column-preview"><?php esc_html_e('Match', 'otw-string-finder'); ?></th>
                                <th class="column-location"><?php esc_html_e('Location', 'otw-string-finder'); ?></th>
                                <th class="column-line"><?php esc_html_e('Line/Row', 'otw-string-finder'); ?></th>
                                <th class="column-actions"><?php esc_html_e('Actions', 'otw-string-finder'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="otw-sf-results-body">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Result Row Template -->
        <script type="text/html" id="tmpl-otw-sf-result-row">
            <tr data-type="{{ data.type }}">
                <td class="column-preview">
                    <code>{{{ data.preview }}}</code>
                    <# if (data.is_serialized) { #>
                        <span class="otw-sf-badge"><?php esc_html_e('Serialized', 'otw-string-finder'); ?></span>
                    <# } #>
                </td>
                <td class="column-location">
                    <# if (data.type === 'file') { #>
                        {{ data.relative_path }}
                    <# } else { #>
                        <code>{{ data.table }}.{{ data.column }}</code>
                        <# if (data.path) { #>
                            <br><small>{{ data.path }}</small>
                        <# } #>
                    <# } #>
                </td>
                <td class="column-line">
                    <# if (data.type === 'file') { #>
                        {{ data.line }}
                    <# } else { #>
                        {{ data.primary_value }}
                    <# } #>
                </td>
                <td class="column-actions">
                    <a href="{{ data.edit_url }}" class="button button-small">
                        <?php esc_html_e('Edit', 'otw-string-finder'); ?>
                    </a>
                </td>
            </tr>
        </script>
        <?php
    }
    
    /**
     * Render file editor page
     */
    private function render_file_editor() {
        $file = isset($_GET['file']) ? urldecode($_GET['file']) : '';
        $line = isset($_GET['line']) ? absint($_GET['line']) : 0;
        
        // Security check
        $real_path = realpath($file);
        $abspath = realpath(ABSPATH);
        
        if (!$real_path || strpos($real_path, $abspath) !== 0) {
            wp_die(__('Invalid file path', 'otw-string-finder'));
        }
        
        $content = file_get_contents($real_path);
        $extension = pathinfo($real_path, PATHINFO_EXTENSION);
        $writable = is_writable($real_path);
        
        $back_url = add_query_arg('page', 'otw-string-finder', admin_url('tools.php'));
        ?>
        <div class="wrap otw-string-finder">
            <h1>
                <?php esc_html_e('Edit File', 'otw-string-finder'); ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php esc_html_e('Back to Search', 'otw-string-finder'); ?>
                </a>
            </h1>
            
            <div class="otw-sf-editor-container">
                <div class="otw-sf-editor-header">
                    <strong><?php esc_html_e('File:', 'otw-string-finder'); ?></strong>
                    <code><?php echo esc_html(str_replace(ABSPATH, '', $file)); ?></code>
                    <?php if ($line > 0): ?>
                        <span class="otw-sf-line-indicator">
                            <?php printf(esc_html__('Line %d', 'otw-string-finder'), $line); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!$writable): ?>
                        <span class="otw-sf-badge warning"><?php esc_html_e('Read-only', 'otw-string-finder'); ?></span>
                    <?php endif; ?>
                </div>
                
                <form id="otw-sf-editor-form">
                    <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
                    <textarea id="otw-sf-editor" name="content"><?php echo esc_textarea($content); ?></textarea>
                    
                    <?php if ($writable): ?>
                        <div class="otw-sf-editor-buttons">
                            <button type="submit" class="button button-primary" id="otw-sf-save-btn">
                                <?php esc_html_e('Save Changes', 'otw-string-finder'); ?>
                            </button>
                            <span id="otw-sf-save-status"></span>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var editor = wp.codeEditor.initialize($('#otw-sf-editor'), {
                codemirror: {
                    lineNumbers: true,
                    mode: '<?php echo esc_js($this->get_codemirror_mode($extension)); ?>',
                    theme: 'default',
                    lineWrapping: true,
                }
            });
            
            // Jump to line if specified
            <?php if ($line > 0): ?>
            setTimeout(function() {
                editor.codemirror.setCursor(<?php echo $line - 1; ?>, 0);
                editor.codemirror.scrollIntoView({line: <?php echo $line - 1; ?>, ch: 0}, 200);
                editor.codemirror.addLineClass(<?php echo $line - 1; ?>, 'background', 'otw-sf-highlight-line');
            }, 100);
            <?php endif; ?>
        });
        </script>
        <?php
    }
    
    /**
     * Render database editor page
     */
    private function render_db_editor() {
        $table = isset($_GET['table']) ? sanitize_text_field($_GET['table']) : '';
        $column = isset($_GET['column']) ? sanitize_text_field($_GET['column']) : '';
        $primary_key = isset($_GET['primary_key']) ? sanitize_text_field($_GET['primary_key']) : '';
        $primary_value = isset($_GET['primary_value']) ? sanitize_text_field($_GET['primary_value']) : '';
        
        $scanner = new Database_Scanner();
        $value = $scanner->get_value($table, $column, $primary_key, $primary_value);
        
        if (is_wp_error($value)) {
            wp_die($value->get_error_message());
        }
        
        $back_url = add_query_arg('page', 'otw-string-finder', admin_url('tools.php'));
        ?>
        <div class="wrap otw-string-finder">
            <h1>
                <?php esc_html_e('Edit Database Value', 'otw-string-finder'); ?>
                <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">
                    <?php esc_html_e('Back to Search', 'otw-string-finder'); ?>
                </a>
            </h1>
            
            <div class="otw-sf-editor-container">
                <div class="otw-sf-editor-header">
                    <strong><?php esc_html_e('Table:', 'otw-string-finder'); ?></strong>
                    <code><?php echo esc_html($table); ?></code>
                    <strong><?php esc_html_e('Column:', 'otw-string-finder'); ?></strong>
                    <code><?php echo esc_html($column); ?></code>
                    <strong><?php echo esc_html($primary_key); ?>:</strong>
                    <code><?php echo esc_html($primary_value); ?></code>
                </div>
                
                <form id="otw-sf-db-editor-form">
                    <input type="hidden" name="table" value="<?php echo esc_attr($table); ?>">
                    <input type="hidden" name="column" value="<?php echo esc_attr($column); ?>">
                    <input type="hidden" name="primary_key" value="<?php echo esc_attr($primary_key); ?>">
                    <input type="hidden" name="primary_value" value="<?php echo esc_attr($primary_value); ?>">
                    
                    <textarea id="otw-sf-db-editor" name="value" rows="20" class="large-text code"><?php echo esc_textarea($value); ?></textarea>
                    
                    <div class="otw-sf-editor-buttons">
                        <button type="submit" class="button button-primary" id="otw-sf-save-db-btn">
                            <?php esc_html_e('Save Changes', 'otw-string-finder'); ?>
                        </button>
                        <span id="otw-sf-save-status"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get CodeMirror mode from file extension
     */
    private function get_codemirror_mode($extension) {
        $map = [
            'php' => 'application/x-httpd-php',
            'js' => 'javascript',
            'jsx' => 'jsx',
            'ts' => 'text/typescript',
            'tsx' => 'text/typescript-jsx',
            'css' => 'css',
            'scss' => 'text/x-scss',
            'sass' => 'text/x-sass',
            'less' => 'text/x-less',
            'html' => 'htmlmixed',
            'htm' => 'htmlmixed',
            'json' => 'application/json',
            'xml' => 'xml',
            'sql' => 'text/x-sql',
            'md' => 'markdown',
            'yaml' => 'yaml',
            'yml' => 'yaml',
        ];
        
        return $map[strtolower($extension)] ?? 'text/plain';
    }
}
