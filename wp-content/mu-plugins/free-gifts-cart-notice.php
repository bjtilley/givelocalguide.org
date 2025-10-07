<?php
/**
 * Plugin Name: Free Gifts Cart Notice
 * Description: Shows a cart notice when the shopper qualifies for free product gifts or free vouchers.
 * Version: 1.2
 * Author: Custom Development
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determine if the cart qualifies for at least one free product gift or free voucher.
 *
 * Returns true when:
 * - The cart already contains a free gift or free voucher (cart item flags), OR
 * - Any non-free cart item meets a configured free-product-gift threshold, OR
 * - Any non-free cart item meets any voucher thresholds (for products in category `free-product-voucher`).
 */
function fpgv_cart_has_qualifying_free_items() {
    if ( ! WC()->cart ) {
        return false;
    }
    
    $cart = WC()->cart;
    $removed_gifts = WC()->session->get( 'fpgv_removed_gifts', array() );
    
    // If we already have free gifts/vouchers in the cart, qualify immediately
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['is_free_gift'] ) || ! empty( $cart_item['is_free_voucher'] ) ) {
            return true;
        }
    }
    
    // Build voucher thresholds map (vid => threshold)
    $voucher_thresholds = array();
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => 'free-product-voucher',
            ),
        ),
        'fields' => 'ids',
    );
    
    $voucher_ids = get_posts( $args );
    if ( ! empty( $voucher_ids ) && function_exists('fvg_is_plugin_enabled') && fvg_is_plugin_enabled()) {
        foreach ( $voucher_ids as $vid ) {
            $threshold = floatval( get_field( 'free_voucher_threshold', $vid ) );
            if ( $threshold > 0 ) {
                $voucher_thresholds[ intval( $vid ) ] = $threshold;
            }
        }
    }
    
    // Loop through non-free cart items and check product gift thresholds and voucher thresholds
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['is_free_gift'] ) || ! empty( $cart_item['is_free_voucher'] ) ) {
            // skip
            continue;
        }
        
        $product_id = $cart_item['product_id'];
        $line_subtotal = floatval( $cart_item['line_subtotal'] );
        
        // Product-level free product gift field
        $free_product_id = get_field( 'free_product_gift', $product_id );
        $product_threshold = floatval( get_field( 'free_product_gift_threshold', $product_id ) );
        if ( $free_product_id && $product_threshold > 0 && $line_subtotal >= $product_threshold ) {
            // Check if user has already removed this gift
            if ( ! in_array( $free_product_id, $removed_gifts ) ) {
                return true;
            }
        }
        
        // Voucher thresholds: if any voucher threshold is met by this product's subtotal
        if ( ! empty( $voucher_thresholds ) ) {
            foreach ( $voucher_thresholds as $vid => $threshold ) {
                if ( $line_subtotal >= $threshold ) {
                    // Check if user has already removed this voucher
                    if ( ! in_array( $vid, $removed_gifts ) ) {
                        return true;
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * Add the cart notice when on the cart page and the cart qualifies.
 * Uses wc_add_notice so the message is preserved and works with AJAX-updated cart fragments.
 * Avoid adding duplicate identical notices.
 */
function fpgv_maybe_print_cart_notice() {
    if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
        return;
    }
    
    if ( fpgv_cart_has_qualifying_free_items() ) {
        // Message to shopper for free voucher or gifts
        $message = "Your donation qualifies for one or more free gifts, if you don't want to receive the free gift, simply click the trash icon below next to your free gift.";
        
        // Check for an existing identical notice to avoid duplicates
        $notices = wc_get_notices( 'notice' );
        $found = false;
        if ( ! empty( $notices ) ) {
            foreach ( $notices as $notice ) {
                if ( $notice['notice'] === $message ) {
                    $found = true;
                    break;
                }
            }
        }
        
        if ( ! $found ) {
            wc_add_notice( wp_kses_post( $message ), 'notice' );
        }
    }
}
add_action( 'woocommerce_before_cart', 'fpgv_maybe_print_cart_notice', 10 );

/**
 * When a free gift/voucher is removed from the cart, add its ID to the session
 * to prevent the qualification notice from showing again.
 *
 * @param string $cart_item_key
 * @param WC_Cart $cart
 */
function fpgv_track_removed_free_gifts( $cart_item_key, $cart ) {
    $removed_item = $cart->get_removed_cart_contents()[ $cart_item_key ];
    
    if ( ! empty( $removed_item['is_free_gift'] ) || ! empty( $removed_item['is_free_voucher'] ) ) {
        $product_id = $removed_item['product_id'];
        $removed_gifts = WC()->session->get( 'fpgv_removed_gifts', array() );
        if ( ! in_array( $product_id, $removed_gifts ) ) {
            $removed_gifts[] = $product_id;
            WC()->session->set( 'fpgv_removed_gifts', $removed_gifts );
        }
    }
}
add_action( 'woocommerce_remove_cart_item', 'fpgv_track_removed_free_gifts', 10, 2 );
