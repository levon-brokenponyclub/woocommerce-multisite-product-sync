<?php
if (!defined('ABSPATH')) exit;

class WCPSM_Sync_Manager {
    private $batch_size = 10; // Process 10 products at a time
    private $sync_option_key = 'wcpsm_sync_progress';

    public function __construct() {
        // Real-time sync hooks
        add_action('save_post_product', [$this, 'sync_product'], 10, 3);
        add_action('before_delete_post', [$this, 'delete_product'], 10);

        // Cron and manual sync hooks
        add_action('wcpsm_cron_sync_chunk', [$this, 'process_sync_chunk']);
        add_action('admin_post_wcpsm_manual_sync', [$this, 'handle_manual_sync']);

        // AJAX handlers for progress tracking
        add_action('wp_ajax_wcpsm_start_sync', [$this, 'ajax_start_sync']);
        add_action('wp_ajax_wcpsm_get_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_wcpsm_process_chunk', [$this, 'ajax_process_chunk']);
        add_action('wp_ajax_wcpsm_cancel_sync', [$this, 'ajax_cancel_sync']);

        // Schedule chunked sync if not already scheduled
        if (!wp_next_scheduled('wcpsm_cron_sync_chunk')) {
            wp_schedule_event(time(), 'wcpsm_every_5_minutes', 'wcpsm_cron_sync_chunk');
        }

        // Add custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_cron_schedules($schedules) {
        $schedules['wcpsm_every_5_minutes'] = [
            'interval' => 300,
            'display' => 'Every 5 minutes'
        ];
        return $schedules;
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_wcpsm-sync-settings' && $hook !== 'settings_page_wcpsm-sync-settings') {
            return;
        }

        wp_enqueue_script(
            'wcpsm-sync-script',
            plugin_dir_url(__DIR__) . 'assets/js/sync-progress.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wcpsm-sync-script', 'wcpsm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcpsm_sync_nonce')
        ]);

        wp_enqueue_style(
            'wcpsm-sync-style',
            plugin_dir_url(__DIR__) . 'assets/css/sync-progress.css',
            [],
            '1.0.0'
        );
    }

    private function get_target_sites() {
        return get_site_option('wcpsm_selected_sites', []);
    }

    private function get_sync_progress() {
        return get_site_option($this->sync_option_key, [
            'status' => 'idle',
            'current' => 0,
            'total' => 0,
            'processed' => 0,
            'errors' => [],
            'start_time' => 0,
            'current_offset' => 0
        ]);
    }

    private function update_sync_progress($data) {
        $current = $this->get_sync_progress();
        $updated = array_merge($current, $data);
        update_site_option($this->sync_option_key, $updated);
        return $updated;
    }

    public function sync_product($post_id, $post, $update) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

        $product = wc_get_product($post_id);
        if (!$product) return;

        $target_sites = $this->get_target_sites();
        foreach ($target_sites as $blog_id) {
            $this->sync_single_product_to_site($post_id, $post, $blog_id);
        }
    }

    private function sync_single_product_to_site($post_id, $post, $blog_id) {
        try {
            switch_to_blog($blog_id);

            // Check if product exists
            $existing = get_posts([
                'meta_key' => '_wcpsm_master_id',
                'meta_value' => $post_id,
                'post_type' => 'product',
                'post_status' => 'any',
                'posts_per_page' => 1
            ]);

            $target_id = !empty($existing) ? $existing[0]->ID : 0;

            $args = [
                'ID'           => $target_id,
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status'  => $post->post_status,
                'post_type'    => 'product',
                'menu_order'   => $post->menu_order
            ];

            $new_id = wp_insert_post($args);

            if (!is_wp_error($new_id)) {
                update_post_meta($new_id, '_wcpsm_master_id', $post_id);
                $this->copy_meta_terms($post_id, $new_id);
                $this->copy_product_images($post_id, $new_id);
                $this->log("Synced product ID {$post_id} to site {$blog_id} as {$new_id}");
            } else {
                throw new Exception($new_id->get_error_message());
            }

            restore_current_blog();

        } catch (Exception $e) {
            restore_current_blog();
            $this->log("Error syncing product ID {$post_id} to site {$blog_id}: " . $e->getMessage());
            throw $e;
        }
    }

    public function delete_product($post_id) {
        if (get_post_type($post_id) !== 'product') return;

        foreach ($this->get_target_sites() as $blog_id) {
            switch_to_blog($blog_id);
            $posts = get_posts([
                'meta_key' => '_wcpsm_master_id',
                'meta_value' => $post_id,
                'post_type' => 'product',
                'post_status' => 'any',
                'posts_per_page' => -1
            ]);

            foreach ($posts as $post) {
                wp_delete_post($post->ID, true);
                $this->log("Deleted product ID {$post->ID} on site {$blog_id} synced from master ID {$post_id}");
            }
            restore_current_blog();
        }
    }

    public function ajax_start_sync() {
        check_ajax_referer('wcpsm_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Count total products
        $count_query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $total = $count_query->found_posts;

        // Initialize sync progress
        $this->update_sync_progress([
            'status' => 'processing',
            'current' => 0,
            'total' => $total,
            'processed' => 0,
            'errors' => [],
            'start_time' => time(),
            'current_offset' => 0
        ]);

        wp_send_json_success([
            'total' => $total,
            'message' => 'Sync started successfully'
        ]);
    }

    public function ajax_get_progress() {
        check_ajax_referer('wcpsm_sync_nonce', 'nonce');

        $progress = $this->get_sync_progress();
        $elapsed = $progress['start_time'] ? time() - $progress['start_time'] : 0;

        wp_send_json_success([
            'status' => $progress['status'],
            'current' => $progress['current'],
            'total' => $progress['total'],
            'processed' => $progress['processed'],
            'percentage' => $progress['total'] > 0 ? round(($progress['processed'] / $progress['total']) * 100) : 0,
            'errors' => $progress['errors'],
            'elapsed' => $elapsed,
            'estimated' => $progress['processed'] > 0 ? round($elapsed / $progress['processed'] * $progress['total']) : 0
        ]);
    }

    public function ajax_process_chunk() {
        check_ajax_referer('wcpsm_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $progress = $this->get_sync_progress();

        if ($progress['status'] !== 'processing') {
            wp_send_json_error('Sync not in progress');
        }

        $result = $this->process_sync_chunk();

        wp_send_json_success($result);
    }

    public function ajax_cancel_sync() {
        check_ajax_referer('wcpsm_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $this->update_sync_progress([
            'status' => 'cancelled'
        ]);

        wp_send_json_success(['message' => 'Sync cancelled']);
    }

    public function process_sync_chunk() {
        $progress = $this->get_sync_progress();

        // Check if sync is active
        if ($progress['status'] !== 'processing') {
            return ['status' => 'idle', 'message' => 'No sync in progress'];
        }

        // Get products to sync
        $args = [
            'post_type' => 'product',
            'posts_per_page' => $this->batch_size,
            'post_status' => 'publish',
            'offset' => $progress['current_offset'],
            'orderby' => 'ID',
            'order' => 'ASC'
        ];

        $query = new WP_Query($args);
        $products_synced = 0;
        $errors = $progress['errors'];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product_id = get_the_ID();

                try {
                    foreach ($this->get_target_sites() as $blog_id) {
                        $this->sync_single_product_to_site($product_id, get_post($product_id), $blog_id);
                    }
                    $products_synced++;
                } catch (Exception $e) {
                    $errors[] = [
                        'product_id' => $product_id,
                        'error' => $e->getMessage(),
                        'time' => current_time('mysql')
                    ];
                    $errors = array_slice($errors, -50);
                }

                $this->update_sync_progress([
                    'current' => $progress['current_offset'] + $products_synced,
                    'processed' => $progress['processed'] + $products_synced,
                    'errors' => $errors
                ]);
            }

            wp_reset_postdata();

            $new_offset = $progress['current_offset'] + $query->post_count;
            $this->update_sync_progress([
                'current_offset' => $new_offset
            ]);

            if ($new_offset >= $progress['total']) {
                $this->update_sync_progress([
                    'status' => 'completed',
                    'processed' => $progress['total']
                ]);
                return [
                    'status' => 'completed',
                    'message' => 'Sync completed successfully',
                    'synced' => $products_synced,
                    'total_processed' => $progress['total']
                ];
            }

            return [
                'status' => 'processing',
                'message' => "Processed {$products_synced} products",
                'synced' => $products_synced,
                'remaining' => $progress['total'] - $new_offset
            ];

        } else {
            $this->update_sync_progress([
                'status' => 'completed'
            ]);

            return [
                'status' => 'completed',
                'message' => 'Sync completed',
                'synced' => 0,
                'total_processed' => $progress['processed']
            ];
        }
    }

    public function handle_manual_sync() {
        if (!current_user_can('manage_options') || !check_admin_referer('wcpsm_manual_sync')) {
            wp_die('Unauthorized');
        }

        $count_query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $total = $count_query->found_posts;

        $this->update_sync_progress([
            'status' => 'processing',
            'current' => 0,
            'total' => $total,
            'processed' => 0,
            'errors' => [],
            'start_time' => time(),
            'current_offset' => 0
        ]);

        wp_redirect(add_query_arg('wcpsm_sync_started', '1', network_admin_url('admin.php?page=wcpsm-sync-settings')));
        exit;
    }

    private function copy_meta_terms($from_id, $to_id) {
        // Copy all product meta (except sync marker)
        $meta = get_post_meta($from_id);
        foreach ($meta as $key => $values) {
            if ($key === '_wcpsm_master_id') continue;
            foreach ($values as $value) {
                update_post_meta($to_id, $key, maybe_unserialize($value));
            }
        }

        // Copy taxonomies
        $taxonomies = get_object_taxonomies('product');
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($from_id, $taxonomy, ['fields' => 'slugs']);
            wp_set_post_terms($to_id, $terms, $taxonomy);
        }
    }

    /**
     * Duplicate all images (featured + gallery) to the target blog, update product meta accordingly.
     */
    private function copy_product_images($from_id, $to_id) {
        // 1. Copy main featured image
        $thumbnail_id = get_post_thumbnail_id($from_id);
        if ($thumbnail_id) {
            $new_thumb_id = $this->duplicate_attachment_to_blog($thumbnail_id, $to_id);
            if ($new_thumb_id) {
                set_post_thumbnail($to_id, $new_thumb_id);
            }
        }

        // 2. Copy gallery images
        $gallery = get_post_meta($from_id, '_product_image_gallery', true);
        if ($gallery) {
            $gallery_ids = array_filter(explode(',', $gallery));
            $new_gallery_ids = [];

            foreach ($gallery_ids as $old_id) {
                $new_id = $this->duplicate_attachment_to_blog((int)$old_id, $to_id);
                if ($new_id) {
                    $new_gallery_ids[] = $new_id;
                }
            }
            update_post_meta($to_id, '_product_image_gallery', implode(',', $new_gallery_ids));
        }
    }

    /**
     * Clone an attachment to the current blog. Returns new attachment ID (or false).
     */
    private function duplicate_attachment_to_blog($attachment_id, $product_post_id) {
        $file = get_attached_file($attachment_id);
        if (!file_exists($file)) return false;

        // Get the attachment post
        $att = get_post($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);
        $orig_title = $att->post_title;

        // Get upload dir
        $upload_dir = wp_upload_dir();
        $dest_path = $upload_dir['path'] . '/' . basename($file);

        // Avoid overwriting
        $new_file = $this->unique_filename($upload_dir['path'], basename($file));
        $dest_path = $upload_dir['path'] . '/' . $new_file;

        // Copy file to new blog uploads dir
        copy($file, $dest_path);

        // Insert new attachment post
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title'     => $orig_title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $dest_path, $product_post_id);

        // Generate attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $dest_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Ensure a unique file name for uploads/ directory in case of repeat syncs.
     */
    private function unique_filename($dir, $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $name = basename($file, '.' . $ext);
        $number = 1;
        $new_file = $file;
        while (file_exists($dir . '/' . $new_file)) {
            $new_file = $name . '-' . $number . '.' . $ext;
            $number++;
        }
        return $new_file;
    }

    private function log($message) {
        $log_dir = plugin_dir_path(__DIR__) . 'logs/sync-log.txt';
        $timestamp = current_time('mysql');
        if (!file_exists(dirname($log_dir))) {
            wp_mkdir_p(dirname($log_dir));
        }
        file_put_contents($log_dir, "[$timestamp] $message\n", FILE_APPEND);
    }
}
