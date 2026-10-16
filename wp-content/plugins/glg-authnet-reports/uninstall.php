<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'glg_authnet_txn');
delete_option('glg_authnet_reports_db_version');
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_glg\\_authnet\\_job\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_glg\\_authnet\\_job\\_%'"
);
