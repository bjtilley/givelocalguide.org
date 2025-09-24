<?php
/**
 * Plugin Name: Free Voucher Gift
 * Description: Automatically adds free voucher products to cart based on threshold
 * Version: 1.0
 * Author: Custom Development
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register the voucher product category if it doesn't exist
function fvg_register_voucher_category() {
    if (!term_exists('free-product-voucher', 'product_cat')) {
        wp_insert_term(
            'Free Product Voucher',
            'product_cat',
            array(
                'slug' => 'free-product-voucher'
            )
        );
    }
}
add_action('init', 'fvg_register_voucher_category');

// Add vouchers to cart when thresholds are met
function fvg_check_and_add_vouchers() {
    if ( is_admin() || ! WC()->cart ) {
        return;
    }

    $removed_ids = WC()->session->get( 'fvg_user_removed_vouchers', array() );
    $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();

    // Collect all voucher product IDs (in category) and their thresholds
    $vouchers = array();

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

    if ( empty( $voucher_ids ) ) {
        return;
    }

    foreach ( $voucher_ids as $vid ) {
        if ( in_array( intval( $vid ), $removed_ids, true ) ) {
            // Skip vouchers the user removed this session
            continue;
        }
        $threshold = floatval( get_field( 'free_voucher_threshold', $vid ) );
        if ( $threshold > 0 ) {
            $vouchers[ intval( $vid ) ] = $threshold;
        }
    }

    if ( empty( $vouchers ) ) {
        return;
    }

    // Determine which vouchers should be present based on current cart (any non-voucher line >= threshold)
    $should_have = array();

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        // Skip vouchers already in cart
        if ( ! empty( $cart_item['is_free_voucher'] ) ) {
            continue;
        }

        $line_subtotal = floatval( $cart_item['line_subtotal'] );
        foreach ( $vouchers as $vid => $threshold ) {
            if ( $line_subtotal >= $threshold ) {
                $should_have[] = intval( $vid );
            }
        }
    }

    $should_have = array_unique( $should_have );

    // Add missing vouchers
    foreach ( $should_have as $vid ) {
        $in_cart = false;
        foreach ( WC()->cart->get_cart() as $existing_key => $existing_item ) {
            if ( $existing_item['product_id'] == $vid ) {
                $in_cart = true;
                break;
            }
        }

        if ( ! $in_cart ) {
            $product = wc_get_product( $vid );
            if ( $product ) {
                WC()->cart->add_to_cart( $vid, 1, 0, array(), array(
                    'is_free_voucher' => true,
                ) );

                wc_add_notice( sprintf( __( 'Congratulations! "%s" has been added to your cart as a free voucher!', 'woocommerce' ), $product->get_name() ), 'success' );
            }
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_check_and_add_vouchers', 20 );

// Ensure vouchers are free
function fvg_adjust_voucher_price( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( ! empty( $cart_item['is_free_voucher'] ) ) {
            $cart_item['data']->set_price( 0 );
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_adjust_voucher_price', 10 );

// Remove voucher if threshold not met anymore
function fvg_maybe_remove_vouchers() {
    if ( ! WC()->cart ) {
        return;
    }

    // Build map of voucher thresholds again
    $vouchers = array();
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
    foreach ( $voucher_ids as $vid ) {
        $threshold = floatval( get_field( 'free_voucher_threshold', $vid ) );
        if ( $threshold > 0 ) {
            $vouchers[ intval( $vid ) ] = $threshold;
        }
    }

    if ( empty( $vouchers ) ) {
        return;
    }

    // For each voucher in cart, check if any non-voucher cart item meets its threshold
    $gifts_to_remove = array();

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( empty( $cart_item['is_free_voucher'] ) ) {
            continue;
        }

        $vid = intval( $cart_item['product_id'] );
        $threshold = isset( $vouchers[ $vid ] ) ? $vouchers[ $vid ] : 0;
        if ( $threshold <= 0 ) {
            // Not a managed voucher anymore — remove
            $gifts_to_remove[] = $cart_item_key;
            continue;
        }

        $still_valid = false;
        foreach ( WC()->cart->get_cart() as $other_key => $other_item ) {
            if ( ! empty( $other_item['is_free_voucher'] ) ) {
                continue;
            }
            if ( floatval( $other_item['line_subtotal'] ) >= $threshold ) {
                $still_valid = true;
                break;
            }
        }

        if ( ! $still_valid ) {
            $gifts_to_remove[] = $cart_item_key;
        }
    }

    foreach ( $gifts_to_remove as $key ) {
        WC()->cart->remove_cart_item( $key );
        wc_add_notice( __( 'A free voucher has been removed as it no longer qualifies.', 'woocommerce' ), 'notice' );
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_maybe_remove_vouchers', 15 );

// Prevent direct quantity changes of free vouchers
function fvg_prevent_voucher_quantity_change( $cart_item_data, $cart_item_key ) {
    if ( isset( $cart_item_data['is_free_voucher'] ) && $cart_item_data['is_free_voucher'] ) {
        $cart_item_data['quantity_locked'] = true;
    }
    return $cart_item_data;
}
add_filter( 'woocommerce_cart_item_data', 'fvg_prevent_voucher_quantity_change', 10, 2 );

// Hide quantity selector for free vouchers in cart
function fvg_hide_voucher_quantity( $product_quantity, $cart_item_key, $cart_item ) {
    if ( isset( $cart_item['is_free_voucher'] ) && $cart_item['is_free_voucher'] ) {
        return '<span class="quantity">1</span>';
    }
    return $product_quantity;
}
add_filter( 'woocommerce_cart_item_quantity', 'fvg_hide_voucher_quantity', 10, 3 );

// Server-side: prevent updating voucher quantities via cart update
function fvg_block_voucher_quantity_update( $passed, $cart_item_key, $values, $quantity ) {
    if ( ! empty( $values['is_free_voucher'] ) ) {
        if ( intval( $quantity ) !== intval( $values['quantity'] ) ) {
            // Prevent change
            wc_add_notice( __( 'You cannot change the quantity of a free voucher.', 'woocommerce' ), 'error' );
            return false;
        }
    }
    return $passed;
}
add_filter( 'woocommerce_update_cart_validation', 'fvg_block_voucher_quantity_update', 10, 4 );

// Add class to voucher cart rows
function fvg_add_voucher_cart_class( $class, $cart_item, $cart_item_key ) {
    if ( ! empty( $cart_item['is_free_voucher'] ) ) {
        $class .= ' fvg-free-voucher';
    }
    return $class;
}
add_filter( 'woocommerce_cart_item_class', 'fvg_add_voucher_cart_class', 10, 3 );

// Override subtotal display for vouchers to a non-editable span
function fvg_override_voucher_subtotal( $cart_item_subtotal, $cart_item, $cart_item_key ) {
    if ( ! empty( $cart_item['is_free_voucher'] ) ) {
        $formatted = wc_price(0);
        $escaped = esc_attr( strip_tags( $formatted ) );
        return '<span class="amount fvg-subtotal" data-fvg-price="' . $escaped . '">' . $formatted . '</span>';
    }
    return $cart_item_subtotal;
}
add_filter( 'woocommerce_cart_item_subtotal', 'fvg_override_voucher_subtotal', 9999, 3 );

// Small JS fallback to clean up any inputs other code injects client-side
function fvg_print_voucher_cart_js() {
    if (!is_cart() && !is_checkout()) {
        return;
    }

    $zero_price = esc_js(strip_tags(wc_price(0)));
    ?>
    <script>
    (function(jQuery) {
        const fvgCleanup = () => {
            const vouchers = document.querySelectorAll('.fvg-free-voucher');
            vouchers.forEach((row) => {
                const subtotalEl = row.querySelector('.product-subtotal, .subtotal');
                if (subtotalEl) {
                    const priceSpan = subtotalEl.querySelector('.fvg-subtotal');
                    const priceData = priceSpan ? priceSpan.dataset.fvgPrice : '<?php echo $zero_price; ?>';
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

        document.addEventListener('DOMContentLoaded', fvgCleanup);
        document.body.addEventListener('updated_wc_div', fvgCleanup);
        document.body.addEventListener('updated_cart_totals', fvgCleanup);
    })(jQuery);
    </script>
    <?php
}
add_action( 'wp_footer', 'fvg_print_voucher_cart_js' );

// Remember vouchers a user manually removes this session so we don't auto-re-add them immediately
function fvg_record_user_removed_voucher( $cart_item_key, $cart ) {
    if ( empty( $cart ) || ! isset( $cart->removed_cart_contents ) ) {
        return;
    }

    if ( isset( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
        $removed = $cart->removed_cart_contents[ $cart_item_key ];
        if ( ! empty( $removed['is_free_voucher'] ) ) {
            $removed_ids = WC()->session->get( 'fvg_user_removed_vouchers', array() );
            $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
            $removed_ids[] = intval( $removed['product_id'] );
            $removed_ids = array_unique( $removed_ids );
            WC()->session->set( 'fvg_user_removed_vouchers', $removed_ids );
            wc_add_notice( __( 'Free voucher removed. It will not be re-added automatically.', 'woocommerce' ), 'notice' );
        }
    }
}
add_action( 'woocommerce_cart_item_removed', 'fvg_record_user_removed_voucher', 20, 2 );

// When cart is emptied or checkout happens, clear the removed-vouchers session so behavior resets
function fvg_clear_removed_vouchers_session() {
    if ( WC()->session ) {
        WC()->session->__unset( 'fvg_user_removed_vouchers' );
    }
}
add_action( 'woocommerce_cart_emptied', 'fvg_clear_removed_vouchers_session' );
add_action( 'woocommerce_thankyou', 'fvg_clear_removed_vouchers_session' );

// Cleanup removed-vouchers session when cart items change so we don't remember vouchers that no longer apply
function fvg_cleanup_removed_vouchers_on_cart_change() {
    if ( ! WC()->cart ) {
        return;
    }

    $removed_ids = WC()->session->get( 'fvg_user_removed_vouchers', array() );
    $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
    if ( empty( $removed_ids ) ) {
        return;
    }

    // Build a list of all voucher IDs that are still valid (would be auto-added) based on current cart
    $valid_voucher_ids = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['is_free_voucher'] ) ) {
            continue;
        }
        $product_id = $cart_item['product_id'];
        $line_subtotal = floatval( $cart_item['line_subtotal'] );

        // Check each voucher product for threshold
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
        foreach ( $voucher_ids as $vid ) {
            $threshold = floatval( get_field( 'free_voucher_threshold', $vid ) );
            if ( $threshold > 0 && $line_subtotal >= $threshold ) {
                $valid_voucher_ids[] = intval( $vid );
            }
        }
    }

    $kept = array_intersect( $removed_ids, $valid_voucher_ids );
    if ( empty( $kept ) ) {
        fvg_clear_removed_vouchers_session();
    } else {
        WC()->session->set( 'fvg_user_removed_vouchers', array_values( $kept ) );
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_cleanup_removed_vouchers_on_cart_change', 5 );

// Check and add vouchers immediately when products are added to cart
function fvg_check_vouchers_on_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (is_admin()) {
        return;
    }

    // Skip if the added item is itself a voucher
    if (!empty($cart_item_data['is_free_voucher'])) {
        return;
    }

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

    $voucher_ids = get_posts($args);
    if (empty($voucher_ids)) {
        return;
    }

    // Calculate total amount for this product including the new addition
    $product = wc_get_product($product_id);
    $product_total = $product->get_price() * $quantity;
    
    // Add amounts of same product already in cart
    foreach (WC()->cart->get_cart() as $cart_item) {
        if ($cart_item['product_id'] == $product_id && $cart_item_key !== $cart_item['key']) {
            $product_total += $cart_item['line_subtotal'];
        }
    }

    $removed_ids = WC()->session->get('fvg_user_removed_vouchers', array());
    $removed_ids = is_array($removed_ids) ? $removed_ids : array();

    foreach ($voucher_ids as $vid) {
        if (in_array(intval($vid), $removed_ids, true)) {
            continue;
        }

        $threshold = floatval(get_field('free_voucher_threshold', $vid));
        if ($threshold > 0 && $product_total >= $threshold) {
            // Check if this voucher is already in cart
            $voucher_in_cart = false;
            foreach (WC()->cart->get_cart() as $existing_item) {
                if ($existing_item['product_id'] == $vid) {
                    $voucher_in_cart = true;
                    break;
                }
            }

            if (!$voucher_in_cart) {
                $voucher_product = wc_get_product($vid);
                if ($voucher_product) {
                    WC()->cart->add_to_cart($vid, 1, 0, array(), array(
                        'is_free_voucher' => true
                    ));
                }
            }
        }
    }
}
add_action('woocommerce_add_to_cart', 'fvg_check_vouchers_on_add', 20, 6 );

// End of file
