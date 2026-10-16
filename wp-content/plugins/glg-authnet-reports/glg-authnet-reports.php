<?php
/**
 * Plugin Name: GLG Authorize.net Reports
 * Description: Exports Authorize.net transactions, including the line items sent at checkout, to CSV or Excel.
 * Version: 1.0.0
 * Author: Brandon Tilley
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce, woocommerce-gateway-authorize-net-cim
 * Text Domain: glg-authnet-reports
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GLG_AUTHNET_REPORTS_VERSION', '1.0.0');
define('GLG_AUTHNET_REPORTS_DIR', plugin_dir_path(__FILE__));
define('GLG_AUTHNET_REPORTS_URL', plugin_dir_url(__FILE__));

require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-cache.php';

register_activation_hook(__FILE__, array('GLG_Authnet_Cache', 'install'));

// The tool is admin-only. admin-ajax.php and admin-post.php also count as admin.
if (is_admin()) {
    require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-client.php';
    require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-job.php';
    require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-report.php';
    require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-exporter.php';
    require_once GLG_AUTHNET_REPORTS_DIR . 'includes/class-glg-authnet-admin.php';

    add_action('plugins_loaded', function () {
        new GLG_Authnet_Admin();
    });
}
