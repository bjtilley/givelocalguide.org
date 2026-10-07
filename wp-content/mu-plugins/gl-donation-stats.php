<?php
/**
 * Plugin Name: Donation Stats Cache
 * Description: Refreshes campaign and per-product donation totals every five minutes and serves them from a JSON file.
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign orders created on or after this local time are included.
 * Statuses: wc-completed, wc-processing.
 */
function gl_donation_stats_cutoff_local()
{
    return '2025-09-01 00:00:00';
}

function gl_donation_stats_directory()
{
    if (!function_exists('wp_upload_dir')) {
        return '';
    }

    $uploads = wp_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    return trailingslashit($uploads['basedir']) . 'gl-donation-stats';
}

function gl_donation_stats_file_path()
{
    $directory = gl_donation_stats_directory();
    if ('' === $directory) {
        return '';
    }

    return $directory . '/totals.json';
}

function gl_donation_stats_hpos_enabled()
{
    return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Zeros used only when the cache file and the aggregate queries both fail.
 *
 * @return array
 */
function gl_donation_stats_empty()
{
    return array(
        'generated_at' => null,
        'source' => 'unavailable',
        'hpos' => null,
        'campaign' => array(
            'donation_total' => 0,
            'donation_count' => 0,
            'max_donation' => 0,
            'average_donation' => 0,
            'matched_donations' => 0,
            'total_raised' => 0,
        ),
        'products' => array(),
    );
}

/**
 * Local Docker has no cron runner, so the cache file is never written.
 *
 * @return bool
 */
function gl_donation_stats_is_local()
{
    return function_exists('wp_get_environment_type') && 'local' === wp_get_environment_type();
}

/**
 * Fixed campaign totals for local development. Production reads the cache file.
 *
 * @return array
 */
function gl_donation_stats_placeholders()
{
    return array(
        'generated_at' => null,
        'source' => 'local',
        'hpos' => null,
        'campaign' => array(
            'donation_total' => 417495,
            'donation_count' => 2412,
            'max_donation' => 75000,
            'average_donation' => 173,
            'matched_donations' => 145168,
            'total_raised' => 562663,
        ),
        'products' => array(),
    );
}

/**
 * Read the cache once per request. A missing or invalid file is rebuilt from SQL.
 * Local environments return placeholders and do not open the file or query.
 *
 * @return array
 */
function gl_donation_stats_read()
{
    static $stats = null;

    if (null !== $stats) {
        return $stats;
    }

    if (gl_donation_stats_is_local()) {
        $stats = gl_donation_stats_placeholders();
        return $stats;
    }

    $decoded = gl_donation_stats_read_file();
    if (null !== $decoded) {
        $stats = $decoded;
        return $stats;
    }

    $stats = gl_donation_stats_backup_from_sql();
    return $stats;
}

/**
 * @return array|null
 */
function gl_donation_stats_read_file()
{
    $path = gl_donation_stats_file_path();
    if ('' === $path || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($decoded)
        || !isset($decoded['campaign'])
        || !is_array($decoded['campaign'])
        || !array_key_exists('donation_total', $decoded['campaign'])
    ) {
        return null;
    }

    $decoded['source'] = 'file';
    if (!isset($decoded['products']) || !is_array($decoded['products'])) {
        $decoded['products'] = array();
    }

    return $decoded;
}

/**
 * Run the same aggregates as the cron job and write the cache when they succeed.
 * A recent query failure returns zeros without querying again.
 *
 * @return array
 */
function gl_donation_stats_backup_from_sql()
{
    if (get_transient('gl_donation_stats_refresh_failed') || !class_exists('WooCommerce')) {
        return gl_donation_stats_empty();
    }

    $payload = gl_donation_stats_collect();
    if (null === $payload) {
        set_transient('gl_donation_stats_refresh_failed', 1, 5 * MINUTE_IN_SECONDS);
        return gl_donation_stats_empty();
    }

    delete_transient('gl_donation_stats_refresh_failed');
    $directory = gl_donation_stats_ensure_storage();
    if ('' !== $directory) {
        gl_donation_stats_write($directory, $payload);
    }

    $payload['source'] = 'sql';
    if (!isset($payload['products']) || !is_array($payload['products'])) {
        $payload['products'] = array();
    }

    return $payload;
}

function gl_donation_stats_campaign_value($key)
{
    $stats = gl_donation_stats_read();
    if (!isset($stats['campaign'][$key])) {
        return 0;
    }

    return $stats['campaign'][$key];
}

function gl_donation_stats_card($value, $label)
{
    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">'
        . esc_html($value)
        . '</span><span class="gl_donation_stats__text">'
        . esc_html($label)
        . '</span></div>';
}

function gl_donation_stats_money($amount)
{
    return '$' . number_format((float) $amount, 2);
}

function gl_donation_stats_product_id($atts)
{
    $product_id = isset($atts['product_id']) ? (int) $atts['product_id'] : 0;
    if ($product_id) {
        return $product_id;
    }

    global $product;
    if (is_object($product) && is_a($product, 'WC_Product')) {
        return (int) $product->get_id();
    }

    return 0;
}

function gl_donation_stats_product_total($product_id)
{
    $stats = gl_donation_stats_read();
    $products = $stats['products'];
    if (isset($products[$product_id])) {
        return (float) $products[$product_id];
    }

    $key = (string) $product_id;
    if (isset($products[$key])) {
        return (float) $products[$key];
    }

    return 0.0;
}

add_shortcode('gl_donation_total', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    return gl_donation_stats_card(
        gl_donation_stats_money(gl_donation_stats_campaign_value('donation_total')),
        'In Donations Made'
    );
});

add_shortcode('gl_donation_count', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    return gl_donation_stats_card(
        (string) (int) gl_donation_stats_campaign_value('donation_count'),
        'Donations Made'
    );
});

add_shortcode('gl_max_donation', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    return gl_donation_stats_card(
        gl_donation_stats_money(gl_donation_stats_campaign_value('max_donation')),
        'Largest Donation'
    );
});

add_shortcode('gl_average_donations', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    $average = (float) gl_donation_stats_campaign_value('average_donation');
    return gl_donation_stats_card(
        '$' . number_format($average, 0) . '.00',
        'Average Donation Made'
    );
});

add_shortcode('gl_matched_donations', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    return gl_donation_stats_card(
        gl_donation_stats_money(gl_donation_stats_campaign_value('matched_donations')),
        'Matched Donations'
    );
});

add_shortcode('gl_total_raised', function () {
    if (wp_doing_ajax()) {
        return '';
    }

    return gl_donation_stats_card(
        gl_donation_stats_money(gl_donation_stats_campaign_value('total_raised')),
        'Total Raised'
    );
});

add_shortcode('gl_nonprofit_total_sales', function ($atts) {
    $atts = shortcode_atts(array(
        'product_id' => 0,
    ), $atts, 'gl_nonprofit_total_sales');

    $product_id = gl_donation_stats_product_id($atts);
    if (!$product_id) {
        return '';
    }

    $total_sales = gl_donation_stats_product_total($product_id);
    if ($total_sales <= 0) {
        return '';
    }

    return sprintf(
        '<h6 class="gl__raised_so_far">$%s Raised so far</h6>',
        esc_html(number_format($total_sales, 2))
    );
});

add_shortcode('gl_debug_product_sales', function ($atts) {
    if (!current_user_can('manage_options')) {
        return '';
    }

    $atts = shortcode_atts(array(
        'product_id' => 0,
    ), $atts, 'gl_debug_product_sales');

    $product_id = gl_donation_stats_product_id($atts);
    if (!$product_id) {
        return '<div style="background: #fff; padding: 15px; border: 1px solid #ccc;">No product ID found</div>';
    }

    $stats = gl_donation_stats_read();
    $total = gl_donation_stats_product_total($product_id);
    $generated = !empty($stats['generated_at']) ? $stats['generated_at'] : 'not generated yet';
    if (isset($stats['hpos'])) {
        $hpos_label = $stats['hpos'] ? 'Yes' : 'No';
    } else {
        $hpos_label = 'Unknown';
    }
    $total_label = function_exists('wc_price') ? wc_price($total) : esc_html(gl_donation_stats_money($total));

    $output = '<div style="background: #fff; padding: 15px; border: 1px solid #ccc; margin: 20px 0; font-family: monospace; font-size: 12px;">';
    $output .= '<h3>Debug: Product Sales Data</h3>';
    $output .= '<p><strong>Product ID:</strong> ' . esc_html((string) $product_id) . '</p>';
    $output .= '<p><strong>Generated:</strong> ' . esc_html($generated) . '</p>';
    $output .= '<p><strong>HPOS Enabled:</strong> ' . esc_html($hpos_label) . '</p>';
    $output .= '<p><strong>Total Sales Amount:</strong> ' . wp_kses_post($total_label) . '</p>';
    $output .= '<p>Totals are read from the donation stats cache.</p>';
    $output .= '</div>';

    return $output;
});

add_filter('cron_schedules', function ($schedules) {
    $schedules['gl_five_minutes'] = array(
        'interval' => 300,
        'display' => 'Every 5 minutes',
    );

    return $schedules;
});

/**
 * Schedule the recurring refresh. If the cache file is missing and the next
 * run is still in the future, also queue an immediate event.
 */
function gl_donation_stats_ensure_schedule()
{
    if (wp_installing()) {
        return;
    }

    if (!wp_next_scheduled('gl_refresh_donation_stats')) {
        wp_schedule_event(time(), 'gl_five_minutes', 'gl_refresh_donation_stats');
    }

    $path = gl_donation_stats_file_path();
    if ('' !== $path && is_readable($path)) {
        return;
    }

    // A failed refresh sets this so a broken query is not retried on every page view.
    if (get_transient('gl_donation_stats_refresh_failed')) {
        return;
    }

    $next = wp_next_scheduled('gl_refresh_donation_stats');
    if (false === $next || $next > time() + 30) {
        wp_schedule_single_event(time(), 'gl_refresh_donation_stats');
    }
}
add_action('init', 'gl_donation_stats_ensure_schedule', 1);

add_action('gl_refresh_donation_stats', 'gl_donation_stats_refresh');

function gl_donation_stats_refresh()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    $directory = gl_donation_stats_ensure_storage();
    if ('' === $directory) {
        return;
    }

    $payload = gl_donation_stats_collect();
    if (null === $payload) {
        set_transient('gl_donation_stats_refresh_failed', 1, 5 * MINUTE_IN_SECONDS);
        return;
    }

    delete_transient('gl_donation_stats_refresh_failed');
    gl_donation_stats_write($directory, $payload);
}

function gl_donation_stats_ensure_storage()
{
    $directory = gl_donation_stats_directory();
    if ('' === $directory) {
        return '';
    }

    if (!wp_mkdir_p($directory)) {
        return '';
    }

    $htaccess = $directory . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = <<<'HTACCESS'
# Deny web access to cached donation totals.
Options -Indexes
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Deny from all
</IfModule>
HTACCESS;
        file_put_contents($htaccess, $rules);
    }

    return $directory;
}

/**
 * Aggregate orders and product totals. Returns null when a query fails so a
 * previous cache file is left in place.
 *
 * @return array|null
 */
function gl_donation_stats_collect()
{
    global $wpdb;

    $hpos = gl_donation_stats_hpos_enabled();
    $cutoff_local = gl_donation_stats_cutoff_local();
    $cutoff_gmt = get_gmt_from_date($cutoff_local);
    $wpdb->last_error = '';

    $order_totals_sql = gl_donation_stats_order_totals_sql($hpos, $cutoff_local, $cutoff_gmt);
    $order_totals = $wpdb->get_row($order_totals_sql);
    if (!is_object($order_totals) || '' !== $wpdb->last_error) {
        gl_donation_stats_log_query_error();
        return null;
    }

    $wpdb->last_error = '';
    $donation_count = $wpdb->get_var(gl_donation_stats_donation_count_sql($hpos, $cutoff_local, $cutoff_gmt));
    if (null === $donation_count || '' !== $wpdb->last_error) {
        gl_donation_stats_log_query_error();
        return null;
    }

    $wpdb->last_error = '';
    $matched = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(CAST(meta_value AS UNSIGNED))
         FROM {$wpdb->postmeta}
         WHERE meta_key = %s
           AND meta_value != ''",
        'match'
    ));
    if ('' !== $wpdb->last_error) {
        gl_donation_stats_log_query_error();
        return null;
    }

    $wpdb->last_error = '';
    $product_rows = $wpdb->get_results(gl_donation_stats_product_totals_sql($hpos));
    if (!is_array($product_rows) || '' !== $wpdb->last_error) {
        gl_donation_stats_log_query_error();
        return null;
    }

    $donation_total = (float) $order_totals->donation_total;
    $donation_count = (int) $donation_count;
    $matched = (float) $matched;
    $average = $donation_count > 0 ? $donation_total / $donation_count : 0;

    $products = array();
    foreach ($product_rows as $row) {
        $product_id = isset($row->product_id) ? (int) $row->product_id : 0;
        if ($product_id <= 0) {
            continue;
        }
        $products[$product_id] = (float) $row->total_sales;
    }

    return array(
        'generated_at' => wp_date('c'),
        'hpos' => $hpos,
        'campaign' => array(
            'donation_total' => $donation_total,
            'donation_count' => $donation_count,
            'max_donation' => (float) $order_totals->max_donation,
            'average_donation' => $average,
            'matched_donations' => $matched,
            'total_raised' => $donation_total + $matched,
        ),
        'products' => $products,
    );
}

function gl_donation_stats_log_query_error()
{
    global $wpdb;

    if ('' === $wpdb->last_error) {
        return;
    }

    error_log('gl-donation-stats: ' . $wpdb->last_error);
}

function gl_donation_stats_order_totals_sql($hpos, $cutoff_local, $cutoff_gmt)
{
    global $wpdb;

    if ($hpos) {
        $orders = $wpdb->prefix . 'wc_orders';

        return $wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS donation_total,
                    COALESCE(MAX(total_amount), 0) AS max_donation
             FROM {$orders}
             WHERE type = 'shop_order'
               AND status IN ('wc-completed', 'wc-processing')
               AND date_created_gmt >= %s",
            $cutoff_gmt
        );
    }

    return $wpdb->prepare(
        "SELECT COALESCE(SUM(CAST(order_total.meta_value AS DECIMAL(20, 4))), 0) AS donation_total,
                COALESCE(MAX(CAST(order_total.meta_value AS DECIMAL(20, 4))), 0) AS max_donation
         FROM {$wpdb->posts} AS posts
         INNER JOIN {$wpdb->postmeta} AS order_total
             ON posts.ID = order_total.post_id
            AND order_total.meta_key = '_order_total'
         WHERE posts.post_type = 'shop_order'
           AND posts.post_status IN ('wc-completed', 'wc-processing')
           AND posts.post_date >= %s",
        $cutoff_local
    );
}

function gl_donation_stats_donation_count_sql($hpos, $cutoff_local, $cutoff_gmt)
{
    global $wpdb;

    $items = $wpdb->prefix . 'woocommerce_order_items';
    $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

    if ($hpos) {
        $orders = $wpdb->prefix . 'wc_orders';

        return $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$items} AS order_items
             INNER JOIN {$itemmeta} AS line_subtotal
                 ON order_items.order_item_id = line_subtotal.order_item_id
                AND line_subtotal.meta_key = '_line_subtotal'
             INNER JOIN {$orders} AS orders
                 ON order_items.order_id = orders.id
             WHERE order_items.order_item_type = 'line_item'
               AND CAST(line_subtotal.meta_value AS DECIMAL(20, 4)) > 0
               AND orders.type = 'shop_order'
               AND orders.status IN ('wc-completed', 'wc-processing')
               AND orders.date_created_gmt >= %s",
            $cutoff_gmt
        );
    }

    return $wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$items} AS order_items
         INNER JOIN {$itemmeta} AS line_subtotal
             ON order_items.order_item_id = line_subtotal.order_item_id
            AND line_subtotal.meta_key = '_line_subtotal'
         INNER JOIN {$wpdb->posts} AS posts
             ON order_items.order_id = posts.ID
         WHERE order_items.order_item_type = 'line_item'
           AND CAST(line_subtotal.meta_value AS DECIMAL(20, 4)) > 0
           AND posts.post_type = 'shop_order'
           AND posts.post_status IN ('wc-completed', 'wc-processing')
           AND posts.post_date >= %s",
        $cutoff_local
    );
}

function gl_donation_stats_product_totals_sql($hpos)
{
    global $wpdb;

    $items = $wpdb->prefix . 'woocommerce_order_items';
    $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

    if ($hpos) {
        $orders = $wpdb->prefix . 'wc_orders';

        return "SELECT order_item_meta_product.meta_value AS product_id,
                       COALESCE(SUM(order_item_meta.meta_value), 0) AS total_sales
                FROM {$items} AS order_items
                INNER JOIN {$itemmeta} AS order_item_meta_product
                    ON order_items.order_item_id = order_item_meta_product.order_item_id
                   AND order_item_meta_product.meta_key = '_product_id'
                INNER JOIN {$itemmeta} AS order_item_meta
                    ON order_items.order_item_id = order_item_meta.order_item_id
                   AND order_item_meta.meta_key = '_line_total'
                INNER JOIN {$orders} AS orders
                    ON order_items.order_id = orders.id
                WHERE order_items.order_item_type = 'line_item'
                  AND orders.status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                GROUP BY order_item_meta_product.meta_value";
    }

    return "SELECT order_item_meta_product.meta_value AS product_id,
                   COALESCE(SUM(order_item_meta.meta_value), 0) AS total_sales
            FROM {$items} AS order_items
            INNER JOIN {$itemmeta} AS order_item_meta_product
                ON order_items.order_item_id = order_item_meta_product.order_item_id
               AND order_item_meta_product.meta_key = '_product_id'
            INNER JOIN {$itemmeta} AS order_item_meta
                ON order_items.order_item_id = order_item_meta.order_item_id
               AND order_item_meta.meta_key = '_line_total'
            INNER JOIN {$wpdb->posts} AS posts
                ON order_items.order_id = posts.ID
            WHERE order_items.order_item_type = 'line_item'
              AND posts.post_type = 'shop_order'
              AND posts.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            GROUP BY order_item_meta_product.meta_value";
}

function gl_donation_stats_write($directory, $payload)
{
    if (empty($payload['products'])) {
        $payload['products'] = new stdClass();
    }

    $json = wp_json_encode($payload, JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return;
    }

    $tmp = $directory . '/totals.json.' . wp_generate_password(12, false) . '.tmp';
    $written = file_put_contents($tmp, $json, LOCK_EX);
    if (false === $written) {
        return;
    }

    $target = $directory . '/totals.json';
    if (!rename($tmp, $target)) {
        wp_delete_file($tmp);
    }
}
