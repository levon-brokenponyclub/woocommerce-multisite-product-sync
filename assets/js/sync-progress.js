/**
 * WCPSM Sync Progress JS
 * Handles chunked sync AJAX operations and shows a progress bar in the WP admin.
 */

jQuery(document).ready(function($){
    let running = false;
    let pollInterval = null;

    function updateProgressBar(progress) {
        $('#wcpsm-progress-bar')
            .css('width', progress.percentage + '%')
            .attr('aria-valuenow', progress.percentage);

        $('#wcpsm-progress-text').text(
            `${progress.processed} / ${progress.total} (${progress.percentage}%)`
        );

        if (progress.errors && progress.errors.length) {
            $('#wcpsm-sync-errors').html(
                progress.errors.map(e => `<div class="error">${e.product_id ? 'Product ' + e.product_id + ': ' : ''}${e.error}</div>`).join('')
            ).show();
        } else {
            $('#wcpsm-sync-errors').hide();
        }
    }

    function checkProgress() {
        $.post(wcpsm_ajax.ajax_url, {
            action: 'wcpsm_get_progress',
            nonce: wcpsm_ajax.nonce
        }, function(resp){
            if (resp.success && resp.data) {
                updateProgressBar(resp.data);

                if (resp.data.status === 'completed' || resp.data.status === 'cancelled') {
                    running = false;
                    clearInterval(pollInterval);
                    $('#wcpsm-sync-button').prop('disabled', false);
                    $('#wcpsm-cancel-sync').hide();
                }
            }
        });
    }

    function processChunk() {
        if (!running) return;
        $.post(wcpsm_ajax.ajax_url, {
            action: 'wcpsm_process_chunk',
            nonce: wcpsm_ajax.nonce
        }, function(resp){
            checkProgress(); // also updates the bar
            // If still running, process next chunk automatically
            if (resp.success && resp.data && resp.data.status === 'processing') {
                setTimeout(processChunk, 200); // tune chunk delay as needed
            }
        });
    }

    $('#wcpsm-sync-button').on('click', function(e){
        e.preventDefault();
        running = true;
        $('#wcpsm-sync-errors').hide();
        $(this).prop('disabled', true);
        $('#wcpsm-cancel-sync').show();

        // Start sync
        $.post(wcpsm_ajax.ajax_url, {
            action: 'wcpsm_start_sync',
            nonce: wcpsm_ajax.nonce
        }, function(resp){
            if (resp.success) {
                checkProgress();
                // Start chunk processing in JS loop
                processChunk();
                pollInterval = setInterval(checkProgress, 2000);
            } else {
                $('#wcpsm-progress-text').text('Error starting sync');
                $('#wcpsm-sync-button').prop('disabled', false);
            }
        });
    });

    $('#wcpsm-cancel-sync').on('click', function(e){
        e.preventDefault();
        $.post(wcpsm_ajax.ajax_url, {
            action: 'wcpsm_cancel_sync',
            nonce: wcpsm_ajax.nonce
        }, function(){
            running = false;
            clearInterval(pollInterval);
            $('#wcpsm-cancel-sync').hide();
            checkProgress();
        });
    });

    // Initial state
    $('#wcpsm-cancel-sync').hide();
    $('#wcpsm-sync-errors').hide();
});