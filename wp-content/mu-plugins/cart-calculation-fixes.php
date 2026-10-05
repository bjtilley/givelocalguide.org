<?php
/**
 * Plugin Name: Cart Calculation Fixes
 * Description: Ensures correct cart calculations for products with free gifts and vouchers
 * Version: 1.0
 * Author: Custom Development
 */

if (!defined('ABSPATH')) {
    exit;
}

// Store original prices and ensure they're maintained
function ccf_init_cart_prices() {
    if (!WC()->cart) {
        return;
    }

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        // Store original price in the cart item data if not already set
        if (!isset($cart_item['original_price'])) {
            $cart_item['original_price'] = $cart_item['data']->get_price();
        }

        // Force correct price based on item type
        if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
            $cart_item['data']->set_price(0);
            $cart_item['line_subtotal'] = 0;
            $cart_item['line_total'] = 0;
        } else {
            // Ensure regular items maintain their original price
            $cart_item['data']->set_price($cart_item['original_price']);
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'ccf_init_cart_prices', 1);

// Prevent price changes during cart calculations
function ccf_preserve_cart_prices($cart_object) {
    if (!is_admin()) {
        foreach ($cart_object->get_cart() as $cart_item_key => $cart_item) {
            if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
                $cart_item['data']->set_price(0);
            } elseif (isset($cart_item['original_price'])) {
                $cart_item['data']->set_price($cart_item['original_price']);
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'ccf_preserve_cart_prices', 99);

// Force correct line item subtotals
function ccf_line_subtotal($price, $cart_item, $cart_item_key) {
    if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
        return 0;
    }
    
    if (isset($cart_item['original_price'])) {
        return $cart_item['original_price'] * $cart_item['quantity'];
    }
    
    return $price;
}
add_filter('woocommerce_cart_item_subtotal', 'ccf_line_subtotal', 99, 3);

// Ensure cart totals are calculated correctly
function ccf_cart_totals($total) {
    if (!WC()->cart) {
        return $total;
    }

    $real_total = 0;
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (empty($cart_item['is_free_gift']) && empty($cart_item['is_free_voucher'])) {
            if (isset($cart_item['original_price'])) {
                $real_total += $cart_item['original_price'] * $cart_item['quantity'];
            } else {
                $real_total += $cart_item['data']->get_price() * $cart_item['quantity'];
            }
        }
    }

    // Fees are calculated before this filter runs. Include them so an optional
    // checkout fee is part of the charged total. Tax and shipping stay excluded.
    if (method_exists(WC()->cart, 'get_fee_total')) {
        $real_total += (float) WC()->cart->get_fee_total();
    }

    return $real_total;
}
add_filter('woocommerce_calculated_total', 'ccf_cart_totals', 99, 1);

// Prevent any price modifications for free items
function ccf_disable_price_modifications($price, $cart_item, $cart_item_key) {
    if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
        return 0;
    }
    return $price;
}
add_filter('woocommerce_cart_item_price', 'ccf_disable_price_modifications', 99, 3);
add_filter('woocommerce_cart_item_subtotal', 'ccf_disable_price_modifications', 99, 3);

// Clear any stored prices when cart is emptied
function ccf_clear_stored_prices() {
    if (WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            unset($cart_item['original_price']);
        }
    }
}
add_action('woocommerce_cart_emptied', 'ccf_clear_stored_prices');
add_action('woocommerce_before_checkout_process', 'ccf_clear_stored_prices');
