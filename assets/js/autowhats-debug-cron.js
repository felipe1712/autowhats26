jQuery(document).ready(function($) {
    const { __, sprintf } = wp.i18n;
    // Diagnostic message to confirm the file loaded.
    console.log('autowhats-debug-cron.js: File loaded and ready.');

    // --- Element Selectors ---
    const triggerBtn = $('#manual-cron-trigger');
    const rescheduleBtn = $('#reschedule-cron-trigger');
    const responseDiv = $('#manual-cron-response');


    function formatCronTime() {
        const nextRunElement = $('#next-run-time');
        if (nextRunElement.length) {
            const timestamp = parseInt(nextRunElement.data('timestamp'), 10);
            if (!isNaN(timestamp)) {
                // PHP timestamp is in seconds, JS needs milliseconds.
                const date = new Date(timestamp * 1000);
                // toLocaleString() converts the date to the browser's timezone and format.
                const localTimeString = date.toLocaleString();
                nextRunElement.text(localTimeString);
            } else {
                 // In case the timestamp is invalid.
                 nextRunElement.text(__('Invalid date', 'autowa-whatsapp'));
            }
        }
    }

    // Run the function to format the time as soon as the document is ready.
    formatCronTime();
    
    
    // Check to ensure the buttons exist on the page.
    if (triggerBtn.length === 0 || rescheduleBtn.length === 0) {
        console.error('autowhats-debug-cron.js: Error, action buttons (#manual-cron-trigger, #reschedule-cron-trigger) not found on the page.');
        return;
    }

    // --- Event for "Run Task Manually" button ---
    triggerBtn.on('click', function() {
        console.log('Button "Run Task Manually" clicked.');
        
        // Disable buttons to prevent multiple clicks
        $(this).prop('disabled', true).text(__('Executing...', 'autowa-whatsapp'));
        rescheduleBtn.prop('disabled', true);
        responseDiv.html(`<p>${__('Processing...', 'autowa-whatsapp')}</p>`);

        // AJAX request to the WordPress backend
        $.post(autwa_ajax.ajax_url, {
            action: 'autwa_manual_cron_trigger',
            nonce: autwa_ajax.nonce
        })
        .done(function(response) {
            console.log('Server response (Run Manually):', response);
            if (response.success) {
                responseDiv.html(`<div class="notice notice-success is-dismissible"><p>${response.data.message}</p></div>`);
            } else {
                responseDiv.html(`<div class="notice notice-error is-dismissible"><p>${response.data.message || __('An error occurred.', 'autowa-whatsapp')}</p></div>`);
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX error (Run Manually):', textStatus, errorThrown);
            responseDiv.html(`<div class="notice notice-error is-dismissible"><p>${__('Server communication error.', 'autowa-whatsapp')}</p></div>`);
        })
        .always(function() {
            // Re-enable the buttons
            triggerBtn.prop('disabled', false).text(__('Run Task Manually', 'autowa-whatsapp'));
            rescheduleBtn.prop('disabled', false);
        });
    });

    // --- Event for "Force Reschedule Task" button ---
    rescheduleBtn.on('click', function() {
        console.log('Button "Force Reschedule" clicked.');

        // Disable buttons
        $(this).prop('disabled', true).text(__('Rescheduling...', 'autowa-whatsapp'));
        triggerBtn.prop('disabled', true);
        responseDiv.html(`<p>${__('Processing...', 'autowa-whatsapp')}</p>`);

        // AJAX request to the backend
        $.post(autwa_ajax.ajax_url, {
            action: 'autwa_reschedule_cron',
            nonce: autwa_ajax.nonce
        })
        .done(function(response) {
            console.log('Server response (Reschedule):', response);
            if (response.success) {
                responseDiv.html(`<div class="notice notice-success is-dismissible"><p>${response.data.message}</p></div>`);
            } else {
                responseDiv.html(`<div class="notice notice-error is-dismissible"><p>${response.data.message || __('An error occurred.', 'autowa-whatsapp')}</p></div>`);
            }
        })
        .fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX error (Reschedule):', textStatus, errorThrown);
            responseDiv.html(`<div class="notice notice-error is-dismissible"><p>${__('Server communication error.', 'autowa-whatsapp')}</p></div>`);
        })
        .always(function() {
            // Re-enable the buttons
            rescheduleBtn.prop('disabled', false).text(__('Force Reschedule Task', 'autowa-whatsapp'));
            triggerBtn.prop('disabled', false);
        });
    });
});
