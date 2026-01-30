/**
 * OTW String Finder - Admin JavaScript
 */

(function($) {
    'use strict';

    // State
    var state = {
        searchType: 'files',
        searchId: null,
        isSearching: false,
        results: []
    };

    // DOM Elements
    var $form, $searchBtn, $cancelBtn, $progress, $results;

    /**
     * Initialize
     */
    function init() {
        $form = $('#otw-sf-search-form');
        $searchBtn = $('#otw-sf-search-btn');
        $cancelBtn = $('#otw-sf-cancel-btn');
        $progress = $('#otw-sf-progress');
        $results = $('#otw-sf-results');

        bindEvents();
    }

    /**
     * Bind events
     */
    function bindEvents() {
        // Tab switching
        $('.otw-sf-tab').on('click', function() {
            var tab = $(this).data('tab');
            switchTab(tab);
        });

        // Form submission
        $form.on('submit', function(e) {
            e.preventDefault();
            startSearch();
        });

        // Cancel button
        $cancelBtn.on('click', function() {
            if (confirm(otwSF.strings.confirmCancel)) {
                cancelSearch();
            }
        });

        // File editor form
        $('#otw-sf-editor-form').on('submit', function(e) {
            e.preventDefault();
            saveFile();
        });

        // Database editor form
        $('#otw-sf-db-editor-form').on('submit', function(e) {
            e.preventDefault();
            saveDatabaseValue();
        });
    }

    /**
     * Switch tabs
     */
    function switchTab(tab) {
        state.searchType = tab;

        $('.otw-sf-tab').removeClass('active');
        $('.otw-sf-tab[data-tab="' + tab + '"]').addClass('active');

        $('.otw-sf-tab-content').removeClass('active');
        $('.otw-sf-tab-content[data-tab="' + tab + '"]').addClass('active');
    }

    /**
     * Start search
     */
    function startSearch() {
        var searchString = $('#otw-sf-search-string').val().trim();

        if (!searchString) {
            alert('Please enter a search string');
            return;
        }

        state.isSearching = true;
        state.results = [];

        // Update UI
        $searchBtn.prop('disabled', true).text(otwSF.strings.preparing);
        $cancelBtn.show();
        $progress.show();
        $results.hide();
        updateProgress(0, otwSF.strings.preparing);

        // Clear previous results
        $('#otw-sf-results-body').empty();

        if (state.searchType === 'files') {
            initFileSearch(searchString);
        } else {
            initDbSearch(searchString);
        }
    }

    /**
     * Initialize file search
     */
    function initFileSearch(searchString) {
        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_init_file_search',
                nonce: otwSF.nonce,
                search_string: searchString,
                directory: $('#otw-sf-directory').val(),
                is_regex: $('#otw-sf-regex').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    state.searchId = response.data.search_id;
                    processFileBatch();
                } else {
                    showError(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                showError(otwSF.strings.error);
            }
        });
    }

    /**
     * Process file search batch
     */
    function processFileBatch() {
        if (!state.isSearching || !state.searchId) {
            return;
        }

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_process_file_batch',
                nonce: otwSF.nonce,
                search_id: state.searchId
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    // Update progress
                    updateProgress(
                        data.progress,
                        otwSF.strings.filesProcessed
                            .replace('%d', data.processed_files)
                            .replace('%d', data.total_files)
                    );

                    // Add batch results
                    if (data.batch_results && data.batch_results.length > 0) {
                        addResults(data.batch_results);
                    }

                    // Continue or finish
                    if (data.status === 'completed') {
                        searchComplete();
                    } else if (data.status === 'running') {
                        processFileBatch();
                    } else {
                        searchCancelled();
                    }
                } else {
                    showError(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                showError(otwSF.strings.error);
            }
        });
    }

    /**
     * Initialize database search
     */
    function initDbSearch(searchString) {
        var tables = $('#otw-sf-tables').val();
        
        // Filter out empty values
        if (tables) {
            tables = tables.filter(function(t) { return t !== ''; });
        }

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_init_db_search',
                nonce: otwSF.nonce,
                search_string: searchString,
                tables: tables,
                is_regex: $('#otw-sf-regex').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    state.searchId = response.data.search_id;
                    processDbBatch();
                } else {
                    showError(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                showError(otwSF.strings.error);
            }
        });
    }

    /**
     * Process database search batch
     */
    function processDbBatch() {
        if (!state.isSearching || !state.searchId) {
            return;
        }

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_process_db_batch',
                nonce: otwSF.nonce,
                search_id: state.searchId
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    // Update progress
                    var progressText = otwSF.strings.rowsProcessed
                        .replace('%d', data.processed_rows)
                        .replace('%d', data.total_rows);

                    if (data.current_table && data.current_table !== 'completed') {
                        progressText += ' - ' + otwSF.strings.currentTable.replace('%s', data.current_table);
                    }

                    updateProgress(data.progress, progressText);

                    // Add batch results
                    if (data.batch_results && data.batch_results.length > 0) {
                        addResults(data.batch_results);
                    }

                    // Continue or finish
                    if (data.status === 'completed') {
                        searchComplete();
                    } else if (data.status === 'running') {
                        processDbBatch();
                    } else {
                        searchCancelled();
                    }
                } else {
                    showError(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                showError(otwSF.strings.error);
            }
        });
    }

    /**
     * Cancel search
     */
    function cancelSearch() {
        state.isSearching = false;

        var action = state.searchType === 'files' 
            ? 'otw_sf_cancel_file_search' 
            : 'otw_sf_cancel_db_search';

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: action,
                nonce: otwSF.nonce,
                search_id: state.searchId
            }
        });

        searchCancelled();
    }

    /**
     * Search complete
     */
    function searchComplete() {
        state.isSearching = false;
        state.searchId = null;

        $searchBtn.prop('disabled', false).text('Search');
        $cancelBtn.hide();

        updateProgress(100, otwSF.strings.completed);

        if (state.results.length === 0) {
            showNoResults();
        }

        updateResultsCount();
    }

    /**
     * Search cancelled
     */
    function searchCancelled() {
        state.isSearching = false;
        state.searchId = null;

        $searchBtn.prop('disabled', false).text('Search');
        $cancelBtn.hide();

        $('#otw-sf-progress-details').text(otwSF.strings.cancelled);

        updateResultsCount();
    }

    /**
     * Update progress bar
     */
    function updateProgress(percent, text) {
        $('.otw-sf-progress-fill').css('width', percent + '%');
        $('#otw-sf-progress-percent').text(Math.round(percent) + '%');
        $('#otw-sf-progress-details').text(text);
    }

    /**
     * Add results to table
     */
    function addResults(results) {
        var template = wp.template('otw-sf-result-row');
        var $tbody = $('#otw-sf-results-body');

        results.forEach(function(result) {
            state.results.push(result);
            $tbody.append(template(result));
        });

        $results.show();
        updateResultsCount();
    }

    /**
     * Update results count
     */
    function updateResultsCount() {
        var count = state.results.length;
        $('#otw-sf-results-count').text(
            '(' + otwSF.strings.resultsFound.replace('%d', count) + ')'
        );
    }

    /**
     * Show no results message
     */
    function showNoResults() {
        var $tbody = $('#otw-sf-results-body');
        $tbody.html(
            '<tr><td colspan="4" class="otw-sf-no-results">' +
            '<span class="dashicons dashicons-search"></span><br>' +
            otwSF.strings.noResults +
            '</td></tr>'
        );
        $results.show();
    }

    /**
     * Show error
     */
    function showError(message) {
        state.isSearching = false;
        state.searchId = null;

        $searchBtn.prop('disabled', false).text('Search');
        $cancelBtn.hide();

        alert(message);
    }

    /**
     * Save file
     */
    function saveFile() {
        var $btn = $('#otw-sf-save-btn');
        var $status = $('#otw-sf-save-status');
        var content;

        // Get content from CodeMirror if available
        if (window.wp && window.wp.codeEditor) {
            var editor = $('.CodeMirror')[0];
            if (editor && editor.CodeMirror) {
                content = editor.CodeMirror.getValue();
            }
        }

        if (!content) {
            content = $('#otw-sf-editor').val();
        }

        $btn.prop('disabled', true).text(otwSF.strings.saving);
        $status.removeClass('success error').text('');

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_save_file',
                nonce: otwSF.nonce,
                file: $('input[name="file"]').val(),
                content: content
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Save Changes');

                if (response.success) {
                    $status.addClass('success').text(otwSF.strings.saved);
                } else {
                    $status.addClass('error').text(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Save Changes');
                $status.addClass('error').text(otwSF.strings.error);
            }
        });
    }

    /**
     * Save database value
     */
    function saveDatabaseValue() {
        var $btn = $('#otw-sf-save-db-btn');
        var $status = $('#otw-sf-save-status');
        var $form = $('#otw-sf-db-editor-form');

        $btn.prop('disabled', true).text(otwSF.strings.saving);
        $status.removeClass('success error').text('');

        $.ajax({
            url: otwSF.ajaxUrl,
            type: 'POST',
            data: {
                action: 'otw_sf_save_db_value',
                nonce: otwSF.nonce,
                table: $form.find('input[name="table"]').val(),
                column: $form.find('input[name="column"]').val(),
                primary_key: $form.find('input[name="primary_key"]').val(),
                primary_value: $form.find('input[name="primary_value"]').val(),
                value: $('#otw-sf-db-editor').val()
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Save Changes');

                if (response.success) {
                    $status.addClass('success').text(otwSF.strings.saved);
                } else {
                    $status.addClass('error').text(response.data.message || otwSF.strings.error);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Save Changes');
                $status.addClass('error').text(otwSF.strings.error);
            }
        });
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
