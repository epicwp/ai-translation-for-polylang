jQuery(document).ready(function($) {
    // ── API Provider Settings ──

    // Show/hide API key fields based on selected provider
    function toggleApiKeyFields() {
        const activeApi = $('#pllat_translator_api').val();

        // Check if the selected provider is disabled
        const selectedOption = $('#pllat_translator_api option:selected');
        if (selectedOption.prop('disabled')) {
            return;
        }

        $('.api-key-row').closest('tr').hide();
        $('.api-key-row[data-api="' + activeApi + '"]').closest('tr').show();
    }

    // Add styling for disabled options
    $('#pllat_translator_api option:disabled').css({
        'color': '#999',
        'font-style': 'italic'
    });

    $('#pllat_translator_api').on('change', toggleApiKeyFields);
    toggleApiKeyFields();

    // Test connection: one tiny billable request against the saved active provider.
    $(document).on('click', '.pllat-test-connection', function() {
        var btn = $(this);
        var result = btn.siblings('.pllat-test-connection-result');
        var i18n = pllat_settings.i18n;

        btn.prop('disabled', true);
        result.text(i18n.testing).css('color', '');

        $.ajax({
            url: pllat_settings.restUrl + 'preflight/test-connection',
            method: 'POST',
            headers: { 'X-WP-Nonce': pllat_settings.nonce },
        })
        .done(function(data) {
            if (data.success) {
                result.text(i18n.connected).css('color', '#00a32a');
                return;
            }
            result.text(i18n.failed.replace('%s', data.error || i18n.unknown)).css('color', '#d63638');
        })
        .fail(function(xhr) {
            var message = (xhr.responseJSON && xhr.responseJSON.message) || i18n.unknown;
            result.text(i18n.failed.replace('%s', message)).css('color', '#d63638');
        })
        .always(function() {
            btn.prop('disabled', false);
        });
    });
});
