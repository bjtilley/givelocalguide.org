<?php
/**
 * Plugin Name: Local Wordfence DNS cache
 * Description: Keeps Wordfence active locally without the per-request DNS timeout for localhost:port.
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wordfence resolves the home URL host on plugins_loaded. The host includes the
 * port (localhost:8068), which is not a DNS name, so both lookups wait out the
 * resolver timeout. A failed lookup is not stored, so the wait repeats.
 *
 * serverDNS is Wordfence's own cache: "timestamp;ttl;ip", treated as fresh for
 * at least a day. Filling it with 127.0.0.1 skips the lookup. Production is
 * unchanged because this only runs when the environment type is local.
 */
add_action('plugins_loaded', function () {
    if ('local' !== wp_get_environment_type() || !class_exists('wfConfig')) {
        return;
    }

    $stored = wfConfig::get('serverDNS');
    if (is_string($stored) && preg_match('/^(\d+);(\d+);(.+)$/', $stored, $matches)) {
        $expires = (int) $matches[1] + max((int) $matches[2], DAY_IN_SECONDS);
        if ($expires > time()) {
            return;
        }
    }

    wfConfig::set('serverDNS', time() . ';' . DAY_IN_SECONDS . ';127.0.0.1');
}, 1);
