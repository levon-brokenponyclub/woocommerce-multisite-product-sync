<?php
if (!current_user_can('manage_options')) return;

$sites = get_sites(['public' => 1]);
$selected = get_site_option('wcpsm_selected_sites', []);

if (isset($_POST['wcpsm_save_settings']) && check_admin_referer('wcpsm_save_settings')) {
    $selected = isset($_POST['wcpsm_sites']) ? array_map('intval', $_POST['wcpsm_sites']) : [];
    update_site_option('wcpsm_selected_sites', $selected);
    echo '<div class="updated"><p>Settings saved.</p></div>';
}
?>

<div class="wrap">
    <h1>Multisite Product Sync Settings</h1>

    <form method="post" style="margin-bottom:2em">
        <?php wp_nonce_field('wcpsm_save_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">Subsites to Sync</th>
                <td>
                    <label>
                        <input type="checkbox" id="wcpsm_check_all" /> Select All
                    </label><br>
                    <?php foreach ($sites as $site): if ($site->blog_id == 1) continue; ?>
                        <label>
                            <input type="checkbox" name="wcpsm_sites[]" value="<?= esc_attr($site->blog_id) ?>"
                                <?= in_array($site->blog_id, $selected) ? 'checked' : '' ?>>
                            <?= esc_html(get_blog_details($site->blog_id)->blogname) ?>
                        </label><br>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <p>
            <input type="submit" class="button-primary" name="wcpsm_save_settings" value="Save Changes">
        </p>
    </form>

    <hr>

    <h2>Manual Sync</h2>
    <p>
        <button id="wcpsm-sync-button" class="button button-secondary" type="button">🔁 Chunked Sync All Products (AJAX)</button>
        <button id="wcpsm-cancel-sync" class="button" style="display:none;">Cancel</button>
    </p>

    <div id="wcpsm-progress-container">
        <div id="wcpsm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
        <div id="wcpsm-progress-text"></div>
    </div>
    <div id="wcpsm-sync-errors"></div>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:2em;">
        <?php wp_nonce_field('wcpsm_manual_sync'); ?>
        <input type="hidden" name="action" value="wcpsm_manual_sync">
        <input type="submit" class="button" value="Run Legacy Manual Sync (blocking)">
    </form>

    <?php if (isset($_GET['wcpsm_sync_started'])): ?>
        <div class="notice notice-info is-dismissible"><p>Sync started! Progress will be tracked above.</p></div>
    <?php elseif (isset($_GET['wcpsm_synced'])): ?>
        <div class="updated"><p>Manual sync completed successfully.</p></div>
    <?php endif; ?>
</div>

<style>
#wcpsm-progress-container {
    margin: 20px 0;
    display: none;
}
#wcpsm-progress-bar {
    height: 20px;
    background-color: #e0e0e0;
    border-radius: 3px;
    position: relative;
    margin-bottom: 10px;
}
#wcpsm-progress-bar:before {
    content: '';
    position: absolute;
    height: 100%;
    background-color: #0073aa;
    border-radius: 3px;
    width: 0%;
    transition: width 0.3s ease;
}
#wcpsm-progress-text {
    font-weight: 500;
}
#wcpsm-sync-errors {
    margin-top: 15px;
    color: #d63638;
}
</style>

<script>
document.getElementById('wcpsm_check_all').addEventListener('change', function () {
    document.querySelectorAll('input[name="wcpsm_sites[]"]').forEach(cb => cb.checked = this.checked);
});

(function() {
    const syncButton = document.getElementById('wcpsm-sync-button');
    const cancelButton = document.getElementById('wcpsm-cancel-sync');
    const progressContainer = document.getElementById('wcpsm-progress-container');
    const progressBar = document.getElementById('wcpsm-progress-bar');
    const progressText = document.getElementById('wcpsm-progress-text');
    const errorsContainer = document.getElementById('wcpsm-sync-errors');
    
    let isSyncing = false;
    let shouldCancel = false;
    
    syncButton.addEventListener('click', function() {
        if (isSyncing) return;
        
        isSyncing = true;
        shouldCancel = false;
        syncButton.disabled = true;
        cancelButton.style.display = 'inline-block';
        progressContainer.style.display = 'block';
        errorsContainer.innerHTML = '';
        
        // Initialize progress
        updateProgress(0, 'Starting sync...');
        
        // Start the sync process
        startSync();
    });
    
    cancelButton.addEventListener('click', function() {
        shouldCancel = true;
        cancelButton.disabled = true;
        progressText.textContent += ' (Cancelling...)';
    });
    
    function updateProgress(percent, message) {
        progressBar.setAttribute('aria-valuenow', percent);
        progressBar.style.setProperty('--progress', percent + '%');
        progressBar.style.width = percent + '%';
        progressText.textContent = message;
    }
    
    function startSync() {
        // Get the total number of products first
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wcpsm_get_product_count',
                nonce: '<?php echo wp_create_nonce('wcpsm_ajax_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const totalProducts = response.data.count;
                    processProducts(0, totalProducts);
                } else {
                    handleError('Failed to get product count: ' + response.data.message);
                }
            },
            error: function() {
                handleError('Network error when getting product count');
            }
        });
    }
    
    function processProducts(offset, total, processed = 0) {
        if (shouldCancel) {
            finishSync('Sync cancelled');
            return;
        }
        
        const batchSize = 10; // Process 10 products at a time
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wcpsm_sync_products_batch',
                offset: offset,
                limit: batchSize,
                nonce: '<?php echo wp_create_nonce('wcpsm_ajax_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const newProcessed = processed + response.data.processed;
                    const percent = Math.min(Math.round((newProcessed / total) * 100), 100);
                    
                    updateProgress(percent, `Processed ${newProcessed} of ${total} products (${percent}%)`);
                    
                    if (response.data.errors && response.data.errors.length) {
                        response.data.errors.forEach(error => {
                            const errorEl = document.createElement('p');
                            errorEl.textContent = error;
                            errorsContainer.appendChild(errorEl);
                        });
                    }
                    
                    if (newProcessed < total && offset + batchSize < total) {
                        // Continue with next batch
                        processProducts(offset + batchSize, total, newProcessed);
                    } else {
                        // All done
                        finishSync('Sync completed successfully!');
                    }
                } else {
                    handleError('Error during sync: ' + response.data.message);
                }
            },
            error: function() {
                handleError('Network error during sync');
            }
        });
    }
    
    function handleError(message) {
        const errorEl = document.createElement('p');
        errorEl.textContent = message;
        errorsContainer.appendChild(errorEl);
        finishSync('Sync failed');
    }
    
    function finishSync(message) {
        isSyncing = false;
        syncButton.disabled = false;
        cancelButton.style.display = 'none';
        progressText.textContent = message;
    }
})();
</script>
