jQuery(document).ready(function($) {
    // ── API Provider Settings ──

    // Show only the API key field of the selected provider. With a single
    // registered provider the field is plain text and its one key row stays.
    function toggleApiKeyFields() {
        const provider = $('select#pllat_translator_api');
        if (!provider.length) {
            return;
        }

        $('.api-key-row').closest('tr').hide();
        $('.api-key-row[data-api="' + provider.val() + '"]').closest('tr').show();
    }

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
