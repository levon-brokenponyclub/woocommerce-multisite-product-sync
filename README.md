# WooCommerce Multisite Product Sync

## Overview

**Author**: Levon Gravett  
**Developed For**: [https://www.supersonicplayground.com](https://www.supersonicplayground.com)  
**Version**: 1.0.2

Sync WooCommerce products from a master site to selected subsites in real time and via cron jobs within a Multisite Network.

## Description

The plugin supports both real-time syncing—triggered by product changes—and scheduled syncing via cron jobs, ensuring that product data remains consistent across your network. With an intuitive network admin interface, you can easily configure which subsites receive updates, monitor sync status, and manage synchronization settings. Ideal for businesses and agencies managing multiple WooCommerce stores within a single WordPress multisite installation.

## Features
- Real-time product synchronization from the master site to subsites.
- Scheduled synchronization via cron jobs.
- Easy configuration through the network admin settings page.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/sspg-multisite-product-sync` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin network-wide through the 'Plugins' screen in WordPress.
3. Use the Network Admin menu to configure the plugin settings.

## Usage

- The plugin automatically initializes the sync manager on the main site.
- Use the **Product Sync** menu in Network Admin to configure syncing options, trigger manual syncs, and monitor sync status.

## Changelog

### 1.0.2
- Improved synchronization performance.
- Added AJAX-based sync progress tracking.
- Enhanced error handling during sync operations.
- Implemented selected product test sync functionality.
- Added logging for synced products on the settings page.
- Improved UI for sync progress and cancellation.
- **New Feature:** Automatic Sync Retry for failed sync operations.
- **UI Update:** Separated manual sync and full sync buttons for clarity.

### 1.0.1
- Initial release with basic product synchronization features.

## License

GPL v2 or later  
[https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

## Support

For technical support or custom development:  
- 🌐 [https://www.supersonicplayground.com](https://www.supersonicplayground.com)  
- 📧 Levon Gravett: levon.gravett@supersonicplayground.com

[![Build Status](https://img.shields.io/github/workflow/status/user/repo/CI)](https://github.com/user/repo/actions)
[![License](https://img.shields.io/github/license/user/repo)](LICENSE)