jQuery(document).ready(function($) {
    // ── Meta Field Management ──

    // Tab switching.
    $(document).on('click', '.pllat-meta-tab', function() {
        var ct = $(this).data('content-type');

        // Update tab styles.
        $('.pllat-meta-tab').css({
            'border-color': 'transparent',
            'border-bottom-color': 'transparent',
            'background': 'transparent',
            'font-weight': 'normal'
        });
        $(this).css({
            'border-color': '#ccc',
            'border-bottom-color': '#fff',
            'background': '#fff',
            'font-weight': '600'
        });

        // Show/hide panels.
        $('.pllat-meta-panel').hide();
        $('.pllat-meta-panel[data-content-type="' + ct + '"]').show();
    });

    // Remove field (move to ignore).
    $(document).on('click', '.pllat-meta-remove', function() {
        var chip = $(this).closest('.pllat-meta-chip');
        var panel = chip.closest('.pllat-meta-panel');
        var contentType = panel.data('content-type');
        var metaKey = chip.data('key');

        chip.css('opacity', '0.5');

        $.ajax({
            url: pllat_settings.restUrl + 'meta-fields/classify',
            method: 'DELETE',
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': pllat_settings.nonce },
            data: JSON.stringify({ content_type: contentType, meta_key: metaKey }),
        })
        .done(function() {
            chip.fadeOut(200, function() { chip.remove(); });
        })
        .fail(function() {
            chip.css('opacity', '1');
            alert('Failed to remove field.');
        });
    });

    // Add field.
    $(document).on('click', '.pllat-meta-add', function() {
        var btn = $(this);
        var category = btn.data('category');
        var panel = btn.closest('.pllat-meta-panel');
        var contentType = panel.data('content-type');

        var metaKey = prompt('Enter meta key name:');
        if (!metaKey || !metaKey.trim()) {
            return;
        }
        metaKey = metaKey.trim();

        btn.prop('disabled', true);

        $.ajax({
            url: pllat_settings.restUrl + 'meta-fields/classify',
            method: 'PUT',
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': pllat_settings.nonce },
            data: JSON.stringify({ content_type: contentType, meta_key: metaKey, category: category }),
        })
        .done(function() {
            // Reload to show updated chips. Simple and reliable.
            location.reload();
        })
        .fail(function() {
            alert('Failed to add field.');
            btn.prop('disabled', false);
        });
    });

    // Scan for new fields.
    $('#pllat-scan-meta-fields').on('click', function() {
        var btn = $(this);
        var status = $('#pllat-scan-status');

        btn.prop('disabled', true);
        status.text('Scanning...');

        $.ajax({
            url: pllat_settings.restUrl + 'meta-fields/scan',
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-WP-Nonce': pllat_settings.nonce },
            data: JSON.stringify({ force: false }),
        })
        .done(function(data) {
            if (data.success) {
                status.text(
                    'Found ' + data.translate_count + ' translate, ' +
                    data.copy_count + ' copy, ' +
                    data.ignore_count + ' ignore fields.'
                );
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                status.text(data.message || 'Scan failed.');
                btn.prop('disabled', false);
            }
        })
        .fail(function() {
            status.text('Error connecting to server.');
            btn.prop('disabled', false);
        });
    });

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
});
