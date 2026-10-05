<?php

/**
 * Plugin Name: Disable Purchases (Catalog Mode)
 * Description: Visitors can browse products and categories; add-to-cart and checkout are blocked.
 */

if (!defined('ABSPATH')) {
    exit;
}

function gl_purchases_closed_user_is_exempt()
{
    return is_user_logged_in() && current_user_can('manage_options');
}

function gl_purchases_are_closed()
{
    return false;
}

function gl_purchases_closed_message()
{
    return __('Donations are currently closed. You can still browse nonprofits.', 'givelocalguide');
}

function gl_purchases_closed_redirect_url()
{
    return home_url('/thank-you/');
}

/**
 * Hide add-to-cart and make WooCommerce reject purchases.
 */
add_filter('woocommerce_is_purchasable', function ($purchasable) {
    return gl_purchases_are_closed() ? false : $purchasable;
}, 99);

add_filter('woocommerce_variation_is_purchasable', function ($purchasable) {
    return gl_purchases_are_closed() ? false : $purchasable;
}, 99);

add_filter('woocommerce_add_to_cart_validation', function ($valid) {
    if (!gl_purchases_are_closed()) {
        return $valid;
    }

    wc_add_notice(gl_purchases_closed_message(), 'notice');
    return false;
}, 1);

/**
 * Drop any leftover cart so mini-cart / checkout cannot continue a session.
 */
add_action('woocommerce_cart_loaded_from_session', function ($cart) {
    if (!gl_purchases_are_closed() || !$cart || $cart->is_empty()) {
        return;
    }

    $cart->empty_cart(false);
}, 1);

/**
 * Send cart, checkout, and unpaid-order payment to the closed page.
 * Order-received stays available so completed donations still thank the donor.
 */
add_action('template_redirect', function () {
    if (!gl_purchases_are_closed() || !function_exists('is_checkout')) {
        return;
    }

    $block_page = is_cart()
        || (is_checkout() && !is_order_received_page())
        || is_wc_endpoint_url('order-pay');

    if ($block_page) {
        wp_safe_redirect(gl_purchases_closed_redirect_url());
        exit;
    }

    $endpoint = isset($_REQUEST['wc-ajax']) ? sanitize_key(wp_unslash($_REQUEST['wc-ajax'])) : '';
    $blocked_ajax = [
        'add_to_cart',
        'checkout',
        'update_order_review',
        'apply_coupon',
        'remove_coupon',
    ];

    if ($endpoint && in_array($endpoint, $blocked_ajax, true)) {
        wp_send_json([
            'error'       => true,
            'product_url' => gl_purchases_closed_redirect_url(),
            'notices'     => '<div class="woocommerce-info">' . esc_html(gl_purchases_closed_message()) . '</div>',
        ]);
    }
}, 0);

/**
 * Block WooCommerce Blocks / Store API cart and checkout writes.
 */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (!gl_purchases_are_closed() || !($request instanceof WP_REST_Request)) {
        return $result;
    }

    $route = $request->get_route();
    $is_store_cart_or_checkout = (bool) preg_match('#^/wc/store(/v\d+)?/(cart|checkout)#', $route);

    if (!$is_store_cart_or_checkout) {
        return $result;
    }

    $method = strtoupper($request->get_method());
    $is_write = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    $is_checkout = strpos($route, '/checkout') !== false;

    if ($is_write || $is_checkout) {
        return new WP_Error(
            'gl_purchases_closed',
            gl_purchases_closed_message(),
            ['status' => 403]
        );
    }

    return $result;
}, 10, 3);

add_action('woocommerce_before_shop_loop', function () {
    if (!gl_purchases_are_closed()) {
        return;
    }

    wc_print_notice(gl_purchases_closed_message(), 'notice');
}, 5);

add_action('woocommerce_single_product_summary', function () {
    if (!gl_purchases_are_closed()) {
        return;
    }

    echo '<p class="woocommerce-info gl-purchases-closed">' . esc_html(gl_purchases_closed_message()) . '</p>';
}, 30);
