/**
 * Support Tab JavaScript
 * Handles AJAX interactions for the support tab
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Handle log date selection
        $('#pllat-log-date-select').on('change', function() {
            const selectedDate = $(this).val();
            const logType = $(this).data('log-type') || 'error';
            const currentUrl = new URL(window.location.href);

            // Update URL with selected date and log type
            currentUrl.searchParams.set('log_date', selectedDate);
            currentUrl.searchParams.set('log_type', logType);

            // Reload page with new date
            window.location.href = currentUrl.toString();
        });

        // Handle clear logs button click
        $('#pllat-clear-logs-btn').on('click', function(e) {
            e.preventDefault();

            const $button = $(this);
            const $spinner = $('#pllat-clear-logs-spinner');
            const $message = $('#pllat-clear-logs-message');
            const nonce = $button.data('nonce');
            const date = $button.data('date');

            // Check if pllat_support is defined
            if (typeof pllat_support === 'undefined' || !pllat_support.ajax_url) {
                alert('AJAX configuration error. Please refresh the page and try again.');
                return;
            }

            // Confirm action
            if (!confirm('Are you sure you want to clear all debug logs? This action cannot be undone.')) {
                return;
            }

            // Disable button and show spinner
            $button.prop('disabled', true);
            $spinner.show();
            $message.hide();

            // Make AJAX request
            $.ajax({
                url: pllat_support.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'pllat_clear_logs',
                    nonce: nonce,
                    date: date
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $message
                            .removeClass('notice-error')
                            .addClass('notice-success')
                            .html('<p>' + response.data.message + '</p>')
                            .show();

                        // Clear the textarea
                        $('#pllat-log-preview').val('');

                        // Update file size in button text
                        $button.html('<span class="dashicons dashicons-trash"></span>Clear Logs (0 B)');

                        // Reload page after 2 seconds
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        $message
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .html('<p>' + (response.data.message || 'An error occurred while clearing logs.') + '</p>')
                            .show();
                    }
                },
                error: function(xhr, status, error) {
                    // Show error message
                    var errorMsg = 'An error occurred while clearing logs.';
                    if (error) {
                        errorMsg += ' Error: ' + error;
                    }
                    if (xhr.responseText) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                            }
                        } catch (e) {
                            // Keep default error message
                        }
                    }
                    $message
                        .removeClass('notice-success')
                        .addClass('notice-error')
                        .html('<p>' + errorMsg + '</p>')
                        .show();
                },
                complete: function() {
                    // Re-enable button and hide spinner
                    $button.prop('disabled', false);
                    $spinner.hide();
                }
            });
        });
    });
})(jQuery);
