<?php
/**
 * Plugin Name:     WooCommerce Multisite Product Sync
 * Description:     Sync WooCommerce products from a master site to selected subsites in real time and via cron.
 * Version:         1.0.2
 * Author:          Levon Gravett
 * Developed for:   Supersonic Playground
 * Author URI:      https://www.supersonicplayground.com
 * License:         GPL-2.0+
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     woocommerce-multisite-product-sync
 * Domain Path:     /languages
 *
 * Developed by Levon Gravett
 * @package WooCommerceMultisiteProductSync
 */

if (!defined('ABSPATH')) exit;

// Load core files
require_once plugin_dir_path(__FILE__) . 'includes/class-wcpsm-sync-manager.php';

// Initialize sync manager on master site only
add_action('plugins_loaded', function () {
    if (is_multisite() && is_main_site()) {
        new WCPSM_Sync_Manager();
    }
});

// Register network admin menu
add_action('network_admin_menu', function () {
    add_menu_page(
        'Product Sync Settings',
        'Product Sync',
        'manage_network',
        'wcpsm-sync-settings',
        'wcpsm_render_settings_page'
    );
});

// Render settings page callback
function wcpsm_render_settings_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
}
