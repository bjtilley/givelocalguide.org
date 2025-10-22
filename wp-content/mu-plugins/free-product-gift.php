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

// Helper: safe retrieval of a cart item's line subtotal even early in lifecycle
if (!function_exists('fpg_get_line_subtotal')) {
    function fpg_get_line_subtotal($cart_item) {
        if (isset($cart_item['line_subtotal'])) {
            return (float)$cart_item['line_subtotal'];
        }
        // Fallback: product price * quantity
        if (isset($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'get_price')) {
            $price = (float)$cart_item['data']->get_price();
            $qty = isset($cart_item['quantity']) ? (int)$cart_item['quantity'] : 1;
            return $price * $qty;
        }
        return 0.0;
    }
}

// Helper: get all eligible gifts for a product based on its subtotal
if (!function_exists('fpg_get_eligible_gifts')) {
    function fpg_get_eligible_gifts($product_id, $product_subtotal) {
        $eligible_gifts = array();

        // Check if ACF repeater field exists
        if (function_exists('get_field')) {
            $free_product_gifts = get_field('free_product_gifts', $product_id);

            if ($free_product_gifts && is_array($free_product_gifts)) {
                foreach ($free_product_gifts as $gift_row) {
                    $gift_product_id = isset($gift_row['nonprofit_free_product_gift']) ? intval($gift_row['nonprofit_free_product_gift']) : 0;
                    $gift_threshold = isset($gift_row['nonprofit_gift_threshold']) ? floatval($gift_row['nonprofit_gift_threshold']) : 0;
                    // New optional max threshold field (admin may leave empty)
                    $gift_threshold_max = isset($gift_row['nonprofit_gift_threshold_max']) ? floatval($gift_row['nonprofit_gift_threshold_max']) : 0;

                    // Only add gift when product subtotal is >= threshold and (if max is set) <= max
                    if ($gift_product_id > 0 && $gift_threshold > 0) {
                        $meets_min = ($product_subtotal >= $gift_threshold);
                        $meets_max = ($gift_threshold_max > 0) ? ($product_subtotal <= $gift_threshold_max) : true;

                        if ($meets_min && $meets_max) {
                            $eligible_gifts[] = array(
                                'gift_id' => $gift_product_id,
                                'threshold' => $gift_threshold,
                                'threshold_max' => $gift_threshold_max,
                                'parent_product' => $product_id
                            );
                        }
                    }
                }
            }
        }

        // Fallback to old single gift field for backward compatibility
        if (empty($eligible_gifts)) {
            $free_product_id = get_field('free_product_gift', $product_id);
            $threshold = floatval(get_field('free_product_gift_threshold', $product_id));

            if ($free_product_id && $threshold > 0 && $product_subtotal >= $threshold) {
                $eligible_gifts[] = array(
                    'gift_id' => intval($free_product_id),
                    'threshold' => $threshold,
                    'parent_product' => $product_id
                );
            }
        }

        return $eligible_gifts;
    }
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

// Ensure free gifts are actually free and maintain correct subtotals
function fpg_adjust_free_gift_price($cart) {
    if (!WC()->cart) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (!isset($cart_item['original_price'])) {
            $cart_item['original_price'] = $cart_item['data']->get_price();
        }

        if (isset($cart_item['is_free_gift']) && $cart_item['is_free_gift']) {
            $cart_item['data']->set_price(0);
            $cart_item['line_subtotal'] = 0;
            $cart_item['line_total'] = 0;
        } else {
            $cart_item['data']->set_price($cart_item['original_price']);
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'fpg_adjust_free_gift_price', 10);

// Check cart and add free gifts based on new repeater field structure
function fpg_check_and_add_free_gifts() {
    if (!WC()->cart) {
        return;
    }

    $processed_products = array();
    $removed_ids = WC()->session->get('fpg_user_removed_gifts', array());
    $removed_ids = is_array($removed_ids) ? $removed_ids : array();

    foreach (WC()->cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];

        // Skip if already processed or is a free item
        if (in_array($product_id, $processed_products) ||
            !empty($cart_item['is_free_gift']) ||
            !empty($cart_item['is_free_voucher'])) {
            continue;
        }

        $product_subtotal = fpg_get_line_subtotal($cart_item);
        $eligible_gifts = fpg_get_eligible_gifts($product_id, $product_subtotal);

        foreach ($eligible_gifts as $gift_data) {
            $gift_id = $gift_data['gift_id'];

            // Skip if user removed this gift earlier in session
            if (in_array($gift_id, $removed_ids, true)) {
                continue;
            }

            // Check if this gift is already in cart
            $gift_in_cart = false;
            foreach (WC()->cart->get_cart() as $existing_item) {
                if ($existing_item['product_id'] == $gift_id && !empty($existing_item['is_free_gift'])) {
                    $gift_in_cart = true;
                    break;
                }
            }

            if (!$gift_in_cart) {
                $gift_product = wc_get_product($gift_id);
                if ($gift_product && $gift_product->get_status() === 'publish' && $gift_product->is_purchasable()) {
                    wc_clear_notices();
                    WC()->cart->add_to_cart($gift_id, 1, 0, array(), array(
                        'is_free_gift' => true,
                        'parent_product' => $product_id,
                        'gift_threshold' => $gift_data['threshold'],
                        'gift_threshold_max' => isset($gift_data['threshold_max']) ? $gift_data['threshold_max'] : 0,
                        'original_price' => 0
                    ));
                    wc_clear_notices(); // Clear any notices to prevent user confusion
                }
            }
        }

        $processed_products[] = $product_id;
    }
}
add_action('woocommerce_before_calculate_totals', 'fpg_check_and_add_free_gifts', 20);

// When a user removes a free gift, remember it in the session so auto-add will skip that product
function fpg_record_user_removed_gift($cart_item_key, $cart) {
    if (empty($cart) || !isset($cart->removed_cart_contents)) {
        return;
    }

    if (isset($cart->removed_cart_contents[$cart_item_key])) {
        $removed = $cart->removed_cart_contents[$cart_item_key];
        if (!empty($removed['is_free_gift'])) {
            $removed_ids = WC()->session->get('fpg_user_removed_gifts', array());
            $removed_ids = is_array($removed_ids) ? $removed_ids : array();
            $removed_ids[] = intval($removed['product_id']);
            $removed_ids = array_unique($removed_ids);
            WC()->session->set('fpg_user_removed_gifts', $removed_ids);

            if (!wp_doing_ajax()) {
                wc_add_notice(__('Free gift removed. It will not be re-added automatically.', 'woocommerce'), 'notice');
            }
        }
    }
}
add_action( 'woocommerce_cart_item_removed', 'fpg_record_user_removed_gift', 20, 2 );

// Remove free gift if parent product is removed or threshold is no longer met
function fpg_maybe_remove_free_gifts() {
    if (!WC()->cart) {
        return;
    }

    $gifts_to_remove = array();
    $valid_gifts = array(); // Track which gifts should stay

    // First pass: determine which gifts should be valid based on current cart
    foreach (WC()->cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];

        // Skip free gifts and vouchers
        if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
            continue;
        }

        $product_subtotal = fpg_get_line_subtotal($cart_item);
        $eligible_gifts = fpg_get_eligible_gifts($product_id, $product_subtotal);

        foreach ($eligible_gifts as $gift_data) {
            $valid_gifts[] = $gift_data['gift_id'];
        }
    }

    // Second pass: check free gifts and mark invalid ones for removal
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['is_free_gift']) && $cart_item['is_free_gift']) {
            $gift_id = $cart_item['product_id'];

            // Remove if this gift is no longer valid
            if (!in_array($gift_id, $valid_gifts)) {
                $gifts_to_remove[] = $cart_item_key;
            }
        }
    }

    // Remove invalid free gifts
    foreach ($gifts_to_remove as $cart_item_key) {
        WC()->cart->remove_cart_item($cart_item_key);
        if (!wp_doing_ajax()) {
            wc_add_notice(__('A free gift has been removed as it no longer qualifies.', 'woocommerce'), 'notice');
        }
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

// Server-side: prevent updating free gift quantities via cart update
function fpg_block_free_gift_quantity_update($passed, $cart_item_key, $values, $quantity) {
    if (!empty($values['is_free_gift'])) {
        if (intval($quantity) !== intval($values['quantity'])) {
            wc_add_notice(__('You cannot change the quantity of a free gift.', 'woocommerce'), 'error');
            return false;
        }
    }
    return $passed;
}
add_filter('woocommerce_update_cart_validation', 'fpg_block_free_gift_quantity_update', 10, 4);

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
    (() => {
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
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'fpg_print_free_gift_cart_js' );

// When cart is emptied or checkout happens, clear the removed-gifts session so behavior resets
function fpg_clear_removed_gifts_session() {
    if ( WC()->session ) {
        WC()->session->__unset( 'fpg_user_removed_gifts' );
    }
}
add_action( 'woocommerce_cart_emptied', 'fpg_clear_removed_gifts_session' );
add_action( 'woocommerce_thankyou', 'fpg_clear_removed_gifts_session' );

// Cleanup removed-gifts session when cart items change
function fpg_cleanup_removed_gifts_on_cart_change() {
    if ( ! WC()->cart ) {
        return;
    }

    $removed_ids = WC()->session->get( 'fpg_user_removed_gifts', array() );
    $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
    if ( empty( $removed_ids ) ) {
        return;
    }

    // Build a list of all gift IDs that are still valid based on current cart
    $valid_gift_ids = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
            continue;
        }

        $product_id = $cart_item['product_id'];
        $product_subtotal = fpg_get_line_subtotal($cart_item);
        $eligible_gifts = fpg_get_eligible_gifts($product_id, $product_subtotal);

        foreach ($eligible_gifts as $gift_data) {
            $valid_gift_ids[] = $gift_data['gift_id'];
        }
    }

    // Remove any removed_ids that are no longer relevant
    $kept = array_intersect( $removed_ids, $valid_gift_ids );
    if ( empty( $kept ) ) {
        fpg_clear_removed_gifts_session();
    } else {
        WC()->session->set( 'fpg_user_removed_gifts', array_values( $kept ) );
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fpg_cleanup_removed_gifts_on_cart_change', 5 );

// Check and add free gifts when products are added to cart
function fpg_check_free_gifts_on_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (is_admin() || !empty($cart_item_data['is_free_gift'])) {
        return;
    }

    // Force cart totals calculation to ensure accurate numbers
    WC()->cart->calculate_totals();

    // Calculate total amount for this specific product (aggregate all instances)
    $product_total = 0;
    foreach (WC()->cart->get_cart() as $cart_item) {
        if ($cart_item['product_id'] == $product_id && empty($cart_item['is_free_gift']) && empty($cart_item['is_free_voucher'])) {
            $product_total += fpg_get_line_subtotal($cart_item);
        }
    }

    $eligible_gifts = fpg_get_eligible_gifts($product_id, $product_total);
    $removed_ids = WC()->session->get('fpg_user_removed_gifts', array());
    $removed_ids = is_array($removed_ids) ? $removed_ids : array();

    foreach ($eligible_gifts as $gift_data) {
        $gift_id = $gift_data['gift_id'];

        // Skip if user removed this gift
        if (in_array($gift_id, $removed_ids, true)) {
            continue;
        }
        
        // Check if the free product is already in cart
        $free_product_in_cart = false;
        foreach (WC()->cart->get_cart() as $existing_item) {
            if ($existing_item['product_id'] == $gift_id && !empty($existing_item['is_free_gift'])) {
                $free_product_in_cart = true;
                break;
            }
        }

        if (!$free_product_in_cart) {
            $free_product = wc_get_product($gift_id);
            if ($free_product && $free_product->get_status() === 'publish' && $free_product->is_purchasable()) {
                wc_clear_notices();
                WC()->cart->add_to_cart($gift_id, 1, 0, array(), array(
                    'is_free_gift' => true,
                    'parent_product' => $product_id,
                    'gift_threshold' => $gift_data['threshold'],
                    'gift_threshold_max' => isset($gift_data['threshold_max']) ? $gift_data['threshold_max'] : 0
                ));
                wc_clear_notices(); // Clear any notices to prevent user confusion
            }
        }
    }
}
add_action('woocommerce_add_to_cart', 'fpg_check_free_gifts_on_add', 10, 6);

// Check free gifts when quantity is updated in cart
function fpg_check_gifts_on_quantity_update($cart) {
    if (is_admin() || !WC()->cart) {
        return;
    }

    // Force a recalculation of cart totals
    WC()->cart->calculate_totals();

    $removed_ids = WC()->session->get('fpg_user_removed_gifts', array());
    $removed_ids = is_array($removed_ids) ? $removed_ids : array();

    foreach (WC()->cart->get_cart() as $cart_item) {
        if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
            continue;  // Skip free gift items
        }

        $product_id = $cart_item['product_id'];
        $product_subtotal = fpg_get_line_subtotal($cart_item);
        $eligible_gifts = fpg_get_eligible_gifts($product_id, $product_subtotal);

        foreach ($eligible_gifts as $gift_data) {
            $gift_id = $gift_data['gift_id'];

            // Skip if user removed this gift
            if (in_array($gift_id, $removed_ids, true)) {
                continue;
            }

            // Check if the free product is already in cart
            $free_product_in_cart = false;
            foreach (WC()->cart->get_cart() as $existing_item) {
                if ($existing_item['product_id'] == $gift_id && !empty($existing_item['is_free_gift'])) {
                    $free_product_in_cart = true;
                    break;
                }
            }

            if (!$free_product_in_cart) {
                $free_product = wc_get_product($gift_id);
                if ($free_product && $free_product->get_status() === 'publish' && $free_product->is_purchasable()) {
                    wc_clear_notices();
                    WC()->cart->add_to_cart($gift_id, 1, 0, array(), array(
                        'is_free_gift' => true,
                        'parent_product' => $product_id,
                        'gift_threshold' => $gift_data['threshold'],
                        'gift_threshold_max' => isset($gift_data['threshold_max']) ? $gift_data['threshold_max'] : 0
                    ));
                    wc_clear_notices(); // Clear any notices to prevent user confusion
                }
            }
        }
    }
}

// Attach to various cart update hooks
add_action('woocommerce_after_cart_item_quantity_update', 'fpg_check_gifts_on_quantity_update', 20, 1);
add_action('woocommerce_check_cart_items', 'fpg_check_gifts_on_quantity_update', 20);
add_action('woocommerce_cart_loaded_from_session', 'fpg_check_gifts_on_quantity_update', 20);
add_action('woocommerce_update_cart_action_cart_updated', 'fpg_check_gifts_on_quantity_update', 20);

// Also check when AJAX quantity is updated
function fpg_check_gifts_on_ajax_quantity_update() {
    fpg_check_gifts_on_quantity_update(WC()->cart);
}
add_action('woocommerce_ajax_cart_item_quantities_updated', 'fpg_check_gifts_on_ajax_quantity_update', 20);

// Also add immediate trigger on cart updates for better reliability
function fpg_immediate_gift_check() {
    if (is_admin() || !WC()->cart) {
        return;
    }
    fpg_check_and_add_free_gifts();
}
add_action('woocommerce_cart_updated', 'fpg_immediate_gift_check');
add_action('wp_loaded', function() {
    if (!is_admin() && WC()->cart && !WC()->cart->is_empty()) {
        fpg_immediate_gift_check();
    }
});
