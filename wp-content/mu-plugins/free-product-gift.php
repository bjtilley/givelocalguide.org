<?php
/**
 * Plugin Name: Free Product Gift
 * Description: Automatically adds free gift products to cart based on threshold
 * Version: 1.0
 * Author: Custom Development
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register the product category if it doesn't exist
function fpg_register_product_category() {
    if (!term_exists('free-product-gift', 'product_cat')) {
        wp_insert_term(
            'Free Product Gift',
            'product_cat',
            array(
                'slug' => 'free-product-gift'
            )
        );
    }
}
add_action('init', 'fpg_register_product_category');

// Check cart and add free gifts
function fpg_check_and_add_free_gifts() {
    if (!is_admin() && WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $free_product_id = get_field('free_product_gift', $product_id);
            $threshold = floatval(get_field('free_product_gift_threshold', $product_id));
            
            if ($free_product_id && $threshold > 0) {
                // Get the subtotal for this specific product
                $product_subtotal = $cart_item['line_subtotal'];
                
                // Check if we meet the threshold
                if ($product_subtotal >= $threshold) {
                    // Check if the free product is already in cart
                    $free_product_in_cart = false;
                    foreach (WC()->cart->get_cart() as $existing_item) {
                        if ($existing_item['product_id'] == $free_product_id) {
                            $free_product_in_cart = true;
                            break;
                        }
                    }
                    
                    // Add the free product if not already in cart
                    if (!$free_product_in_cart) {
                        $free_product = wc_get_product($free_product_id);
                        if ($free_product && has_term('free-product-gift', 'product_cat', $free_product_id)) {
                            WC()->cart->add_to_cart($free_product_id, 1, 0, array(), array(
                                'is_free_gift' => true,
                                'parent_product' => $product_id
                            ));
                            
                            wc_add_notice(
                                sprintf(
                                    __('Congratulations! "%s" has been added to your cart as a free gift!', 'woocommerce'),
                                    $free_product->get_name()
                                ),
                                'success'
                            );
                        }
                    }
                }
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'fpg_check_and_add_free_gifts', 20);

// Ensure free gifts are actually free
function fpg_adjust_free_gift_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['is_free_gift']) && $cart_item['is_free_gift']) {
            $cart_item['data']->set_price(0);
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'fpg_adjust_free_gift_price', 10);

// Remove free gift if parent product is removed or threshold is no longer met
function fpg_maybe_remove_free_gifts() {
    if (!WC()->cart) {
        return;
    }

    $gifts_to_remove = array();
    $valid_parent_products = array();

    // First pass: collect valid parent products
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $threshold = floatval(get_field('free_product_gift_threshold', $product_id));
        
        if ($threshold > 0 && $cart_item['line_subtotal'] >= $threshold) {
            $valid_parent_products[] = $product_id;
        }
    }

    // Second pass: check free gifts
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['is_free_gift']) && $cart_item['is_free_gift']) {
            if (!in_array($cart_item['parent_product'], $valid_parent_products)) {
                $gifts_to_remove[] = $cart_item_key;
            }
        }
    }

    // Remove invalid free gifts
    foreach ($gifts_to_remove as $cart_item_key) {
        WC()->cart->remove_cart_item($cart_item_key);
        wc_add_notice(__('A free gift has been removed as it no longer qualifies.', 'woocommerce'), 'notice');
    }
}
add_action('woocommerce_before_calculate_totals', 'fpg_maybe_remove_free_gifts', 15);

// Prevent direct quantity changes of free gifts
function fpg_prevent_free_gift_quantity_change($cart_item_data, $cart_item_key) {
    if (isset($cart_item_data['is_free_gift']) && $cart_item_data['is_free_gift']) {
        $cart_item_data['quantity_locked'] = true;
    }
    return $cart_item_data;
}
add_filter('woocommerce_cart_item_data', 'fpg_prevent_free_gift_quantity_change', 10, 2);

// Hide quantity selector for free gifts in cart
function fpg_hide_free_gift_quantity($product_quantity, $cart_item_key, $cart_item) {
    if (isset($cart_item['is_free_gift']) && $cart_item['is_free_gift']) {
        return '<span class="quantity">1</span>';
    }
    return $product_quantity;
}
add_filter('woocommerce_cart_item_quantity', 'fpg_hide_free_gift_quantity', 10, 3);

// Add a CSS class to free-gift cart rows so we can target them reliably on the front end
function fpg_add_free_gift_cart_class( $class, $cart_item, $cart_item_key ) {
    if ( ! empty( $cart_item['is_free_gift'] ) ) {
        $class .= ' fpg-free-gift';
    }
    return $class;
}
add_filter( 'woocommerce_cart_item_class', 'fpg_add_free_gift_cart_class', 10, 3 );

// Override subtotal display for free gifts
function fpg_override_free_gift_subtotal($cart_item_subtotal, $cart_item, $cart_item_key) {
    if (!empty($cart_item['is_free_gift'])) {
        // Return a simple, non-editable span containing the formatted zero price and a data attribute
        $formatted = wc_price(0);
        $escaped = esc_attr( strip_tags( $formatted ) );
        return '<span class="amount fpg-subtotal" data-fpg-price="' . $escaped . '">' . $formatted . '</span>';
    }
    return $cart_item_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'fpg_override_free_gift_subtotal', 9999, 3);

// Small JS fallback for themes/plugins that convert subtotal into inputs client-side.
// Targets rows we marked with .fpg-free-gift and replaces/removes any input controls so free gifts stay non-editable.
function fpg_print_free_gift_cart_js() {
    if (!is_cart() && !is_checkout()) {
        return;
    }

    $zero_price = esc_js(strip_tags(wc_price(0)));
    ?>
    <script>
    (function(jQuery) {
        const fpgCleanup = () => {
            const giftRows = document.querySelectorAll('.fpg-free-gift');
            giftRows.forEach((row) => {
                const subtotalEl = row.querySelector('.product-subtotal, .subtotal');
                if (subtotalEl) {
                    const priceSpan = subtotalEl.querySelector('.fpg-subtotal');
                    const priceData = priceSpan ? priceSpan.dataset.fpgPrice : '<?php echo $zero_price; ?>';
                    subtotalEl.innerHTML = `<span class="amount">${priceData}</span>`;
                }

                const qtyEl = row.querySelector('.product-quantity, .quantity');
                if (qtyEl) {
                    const inputs = qtyEl.querySelectorAll('input, select');
                    inputs.forEach(input => input.remove());
                    if (!qtyEl.querySelector('span.quantity')) {
                        qtyEl.insertAdjacentHTML('beforeend', '<span class="quantity">1</span>');
                    }
                }
            });
        };

        document.addEventListener('DOMContentLoaded', fpgCleanup);
        document.body.addEventListener('updated_wc_div', fpgCleanup);
        document.body.addEventListener('updated_cart_totals', fpgCleanup);
    })(jQuery);
    </script>
    <?php
}
add_action( 'wp_footer', 'fpg_print_free_gift_cart_js' );

// --- NEW: prevent immediately re-adding a free gift that the user manually removed ---
// When a user removes a free gift, remember it in the session so auto-add will skip that product
function fpg_record_user_removed_gift( $cart_item_key, $cart ) {
    if ( empty( $cart ) || ! isset( $cart->removed_cart_contents ) ) {
        return;
    }

    if ( isset( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
        $removed = $cart->removed_cart_contents[ $cart_item_key ];
        if ( ! empty( $removed['is_free_gift'] ) ) {
            $removed_ids = WC()->session->get( 'fpg_user_removed_gifts', array() );
            $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
            $removed_ids[] = intval( $removed['product_id'] );
            $removed_ids = array_unique( $removed_ids );
            WC()->session->set( 'fpg_user_removed_gifts', $removed_ids );
            // Inform the user
            wc_add_notice( __( 'Free gift removed. It will not be re-added automatically.', 'woocommerce' ), 'notice' );
        }
    }
}
add_action( 'woocommerce_cart_item_removed', 'fpg_record_user_removed_gift', 20, 2 );

// When auto-adding gifts, skip any product IDs the user removed this session
function fpg_check_and_add_free_gifts_filtered() {
    if ( ! is_admin() && WC()->cart ) {
        $removed_ids = WC()->session->get( 'fpg_user_removed_gifts', array() );
        $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $free_product_id = get_field( 'free_product_gift', $product_id );
            $threshold = floatval( get_field( 'free_product_gift_threshold', $product_id ) );

            if ( $free_product_id && $threshold > 0 ) {
                // If user removed this free product earlier in this session, skip auto-adding
                if ( in_array( intval( $free_product_id ), $removed_ids, true ) ) {
                    continue;
                }

                $product_subtotal = $cart_item['line_subtotal'];

                if ( $product_subtotal >= $threshold ) {
                    $free_product_in_cart = false;
                    foreach ( WC()->cart->get_cart() as $existing_item ) {
                        if ( $existing_item['product_id'] == $free_product_id ) {
                            $free_product_in_cart = true;
                            break;
                        }
                    }

                    if ( ! $free_product_in_cart ) {
                        $free_product = wc_get_product( $free_product_id );
                        if ( $free_product && has_term( 'free-product-gift', 'product_cat', $free_product_id ) ) {
                            WC()->cart->add_to_cart( $free_product_id, 1, 0, array(), array(
                                'is_free_gift'   => true,
                                'parent_product' => $product_id,
                            ) );

                            wc_add_notice( sprintf( __( 'Congratulations! "%s" has been added to your cart as a free gift!', 'woocommerce' ), $free_product->get_name() ), 'success' );
                        }
                    }
                }
            }
        }
    }
}
// Replace original auto-add hook with filtered version
remove_action( 'woocommerce_before_calculate_totals', 'fpg_check_and_add_free_gifts', 20 );
add_action( 'woocommerce_before_calculate_totals', 'fpg_check_and_add_free_gifts_filtered', 20 );

// When cart is emptied or checkout happens, clear the removed-gifts session so behavior resets
function fpg_clear_removed_gifts_session() {
    if ( WC()->session ) {
        WC()->session->__unset( 'fpg_user_removed_gifts' );
    }
}
add_action( 'woocommerce_cart_emptied', 'fpg_clear_removed_gifts_session' );
add_action( 'woocommerce_thankyou', 'fpg_clear_removed_gifts_session' );

// Also, if a parent product that previously triggered the free gift is changed/removed and the threshold no longer met,
// clear any removed-gift entries that correspond to gifts that are no longer relevant.
function fpg_cleanup_removed_gifts_on_cart_change() {
    if ( ! WC()->cart ) {
        return;
    }

    $removed_ids = WC()->session->get( 'fpg_user_removed_gifts', array() );
    $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
    if ( empty( $removed_ids ) ) {
        return;
    }

    // Build a list of all gift IDs that are still valid (would be auto-added) based on current cart
    $valid_gift_ids = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product_id = $cart_item['product_id'];
        $free_product_id = get_field( 'free_product_gift', $product_id );
        $threshold = floatval( get_field( 'free_product_gift_threshold', $product_id ) );
        if ( $free_product_id && $threshold > 0 && $cart_item['line_subtotal'] >= $threshold ) {
            $valid_gift_ids[] = intval( $free_product_id );
        }
    }

    // Remove any removed_ids that are no longer relevant (i.e., not in valid_gift_ids)
    $kept = array_intersect( $removed_ids, $valid_gift_ids );
    // If there are kept entries, keep them; otherwise clear all
    if ( empty( $kept ) ) {
        fpg_clear_removed_gifts_session();
    } else {
        WC()->session->set( 'fpg_user_removed_gifts', array_values( $kept ) );
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fpg_cleanup_removed_gifts_on_cart_change', 5 );

// Check and add free gifts when products are added to cart
function fpg_check_free_gifts_on_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (!is_admin()) {
        $free_product_id = get_field('free_product_gift', $product_id);
        $threshold = floatval(get_field('free_product_gift_threshold', $product_id));
        
        if ($free_product_id && $threshold > 0) {
            // Calculate total amount for this product including the new addition
            $product = wc_get_product($product_id);
            $product_total = $product->get_price() * $quantity;
            
            // Add amounts of same product already in cart
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product_id && $cart_item_key !== $cart_item['key']) {
                    $product_total += $cart_item['line_subtotal'];
                }
            }
            
            // Check if we meet the threshold
            if ($product_total >= $threshold) {
                // Check if the free product is already in cart
                $free_product_in_cart = false;
                foreach (WC()->cart->get_cart() as $existing_item) {
                    if ($existing_item['product_id'] == $free_product_id) {
                        $free_product_in_cart = true;
                        break;
                    }
                }
                
                // Add the free product if not already in cart
                if (!$free_product_in_cart) {
                    $free_product = wc_get_product($free_product_id);
                    if ($free_product && has_term('free-product-gift', 'product_cat', $free_product_id)) {
                        WC()->cart->add_to_cart($free_product_id, 1, 0, array(), array(
                            'is_free_gift' => true,
                            'parent_product' => $product_id
                        ));
                    }
                }
            }
        }
    }
}
add_action('woocommerce_add_to_cart', 'fpg_check_free_gifts_on_add', 20, 6);

