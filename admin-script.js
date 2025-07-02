jQuery(document).ready(function($) {
    // Tab navigation
    $('.wgs-tab-link').click(function(e) {
        e.preventDefault();
        $('.wgs-tab-link').removeClass('active');
        $(this).addClass('active');
        $('.wgs-tab-content').removeClass('active').fadeOut(200);
        $('#' + $(this).data('tab')).addClass('active').fadeIn(200);
    });

    // Manual spin
    $('#wgs-manual-spinner').click(function() {
        var $button = $(this);
        var $output = $('#wgs-manual-result');
        $output.addClass('loading').html('<span class="dashicons dashicons-update wgs-spin"></span> रिवाइट हो रहा है...');
        $button.prop('disabled', true);

        $.post(wgsAjax.ajaxurl, {
            action: 'wgs_manual_spin',
            nonce: wgsAjax.nonce,
            content: $('#wgs-manual-content').val() || '',
            language: $('#wgs-manual-language').val() || 'hindi',
            prompt: $('#wgs-manual-prompt').val() || ''
        }, function(response) {
            $output.removeClass('loading');
            $button.prop('disabled', false);
            if (response.success) {
                $output.text(response.data.content);
                $('#wgs-notice-area').html('<div class="wgs-notice success"><p>✅ कंटेंट सफलतापूर्वक रिवाइट किया गया!</p><button class="notice-dismiss">बंद करें</button></div>');
            } else {
                $output.html('<span class="error-text">' + (response.data?.message || 'Unknown error occurred') + '</span>');
                $('#wgs-notice-area').html('<div class="wgs-notice error"><p>❌ त्रुटि: ' + (response.data?.message || 'Unknown error occurred') + '</p><button class="notice-dismiss">बंद करें</button></div>');
            }
        }).fail(function(xhr, status, error) {
            $output.removeClass('loading').html('<span class="error-text">सर्वर से कनेक्ट करने में विफल।</span>');
            $button.prop('disabled', false);
            $('#wgs-notice-area').html('<div class="wgs-notice error"><p>❌ सर्वर से कनेक्ट करने में विफल: ' + error + '</p><button class="notice-dismiss">बंद करें</button></div>');
        });
    });

    // Refresh log
    $('#wgs-refresh-log').click(function() {
        var $button = $(this);
        $button.find('.dashicons').addClass('wgs-spin');
        $.post(wgsAjax.ajaxurl, {
            action: 'wgs_refresh_log',
            nonce: wgsAjax.nonce
        }, function(response) {
            $button.find('.dashicons').removeClass('wgs-spin');
            if (response.success) {
                $('#wgs-log-table').html(response.data.html);
                $('#wgs-notice-area').html('<div class="wgs-notice success"><p>✅ लॉग सफलतापूर्वक रिफ्रेश किया गया!</p><button class="notice-dismiss">बंद करें</button></div>');
            } else {
                $('#wgs-notice-area').html('<div class="wgs-notice error"><p>❌ लॉग रिफ्रेश करने में त्रुटि।</p><button class="notice-dismiss">बंद करें</button></div>');
            }
        }).fail(function(xhr, status, error) {
            $button.find('.dashicons').removeClass('wgs-spin');
            $('#wgs-notice-area').html('<div class="wgs-notice error"><p>❌ सर्वर से कनेक्ट करने में विफल: ' + error + '</p><button class="notice-dismiss">बंद करें</button></div>');
        });
    });

    // Dismiss notices
    $(document).on('click', '.notice-dismiss', function() {
        $(this).parent().fadeOut(200, function() { $(this).remove(); });
    });
});