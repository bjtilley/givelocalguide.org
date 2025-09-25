<?php

if (! defined('WP_DEBUG')) {
	die( 'Direct access forbidden.' );
}



function blocksy_child_scripts() {
    // Enqueue parent theme style first
    wp_enqueue_style(
        'blocksy-parent',
        get_template_directory_uri() . '/style.css'
    );
    
    // Then enqueue child theme style
    wp_enqueue_style(
        'blocksy-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('blocksy-parent'),
        filemtime(get_stylesheet_directory() . '/style.css')
    );
}
add_action('wp_enqueue_scripts', 'blocksy_child_scripts', 200);

// Change add to cart text on single product page
add_filter( 'woocommerce_product_single_add_to_cart_text', 'woocommerce_add_to_cart_button_text_single' );
function woocommerce_add_to_cart_button_text_single() {
    return __( 'Add Donation to Cart', 'woocommerce' );
}

// Change add to cart text on product archives page
add_filter( 'woocommerce_product_add_to_cart_text', 'woocommerce_add_to_cart_button_text_archives' );
function woocommerce_add_to_cart_button_text_archives() {
    return __('Add Donation to Cart', 'woocommerce');
}

// Force quantity to 1 when adding to cart
function force_single_quantity($quantity, $product_id) {
    return 1;
}
add_filter('woocommerce_add_to_cart_quantity', 'force_single_quantity', 10, 2);


// Add custom price handling to cart
function handle_custom_price_add_to_cart($cart_item_data, $product_id) {
    if (isset($_POST['quantity'])) {
        $new_amount = floatval($_POST['quantity']);

        // Server-side sanity: ensure minimum donation amount
        if ($new_amount < 5.00) {
            wc_add_notice( __( 'Minimum donation amount is $5.00.', 'woocommerce' ), 'error' );
            return $cart_item_data;
        }

        // Look for existing product in cart
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Add new amount to existing amount
                $current_amount = isset($cart_item['gl_custom_price']) ? floatval($cart_item['gl_custom_price']) : 0;
                $total_amount = $current_amount + $new_amount;

                // Update the existing cart item
                WC()->cart->cart_contents[$cart_item_key]['gl_custom_price'] = $total_amount;
                WC()->cart->cart_contents[$cart_item_key]['gl_is_donation'] = true; // mark explicitly as donation
                WC()->cart->set_session();

                // Success message
                wc_add_notice(sprintf(
                    __('Donation amount increased to $%s.', 'woocommerce'),
                    number_format($total_amount, 2)
                ));

                // Prevent default add (we set session flag) — return cart data to keep filter contract
                return $cart_item_data;
             }
         }

         // Only reached if product wasn't in cart
         $cart_item_data['gl_custom_price'] = $new_amount;
         $cart_item_data['gl_is_donation'] = true; // mark new items as donation

         // For newly added donation, let WooCommerce's add-to-cart process proceed; our
         // add_cart_item_data will attach the custom_price during the add.
     }
     return $cart_item_data;
 }
 add_filter('woocommerce_add_cart_item_data', 'handle_custom_price_add_to_cart', 10, 2);

// Apply custom price to cart item and clean up phantom items
function apply_custom_price_to_cart($cart) {
    static $is_running = false;

    if ($is_running || (is_admin() && !defined('DOING_AJAX'))) {
        return;
    }

    try {
        $is_running = true;

        // First pass: remove phantom items and set prices
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            // Only operate on items explicitly marked as donations
            if (!empty($cart_item['gl_is_donation'])) {
                $price = floatval(isset($cart_item['gl_custom_price']) ? $cart_item['gl_custom_price'] : 0);
                if ($price <= 0) {
                    $cart->remove_cart_item($cart_item_key);
                    continue;
                }

                // Set price on the product object
                $cart_item['data']->set_price($price);
                $cart_item['data']->set_regular_price($price);
                $cart_item['data']->set_sale_price($price);

                // Ensure quantity is 1
                if ($cart_item['quantity'] !== 1) {
                    WC()->cart->cart_contents[$cart_item_key]['quantity'] = 1;
                }
            }
        }

        // Ensure session is updated
        $cart->set_session();

    } finally {
        $is_running = false;
    }
}
add_action('woocommerce_before_calculate_totals', 'apply_custom_price_to_cart', 10, 1);

// NOTE: removed session-flag based duplicate prevention — we now handle existing donation
// updates in validation (see `gl_handle_existing_donation_before_add`) and avoid
// calling add_to_cart manually for archive forms so WooCommerce's normal flow can run.

// Note: archive/loop form submissions are handled by WooCommerce's native add-to-cart
// flow. We intentionally do not call add_to_cart() manually here because that can
// result in the product being added twice (once manually and once by WooCommerce).
// Existing donation updates are handled in `gl_handle_existing_donation_before_add`
// (validation-time) which updates the line item and prevents the default add. For
// new donations, `handle_custom_price_add_to_cart` attaches a `custom_price` entry
// to the cart item data so the item's price will be set correctly in
// `apply_custom_price_to_cart`.

// Remove quantity controls from product pages and set constraints
add_filter('woocommerce_quantity_input_args', function($args, $product = null) {
    $args['min_value'] = 5;
    $args['step'] = '1';
    $args['input_value'] = 20; // Set default value to 20
    return $args;
}, 10, 2);

// Add wrapper div for quantity input to create input group
function add_quantity_wrapper_open() {
    echo '<div class="gl-quantity-wrapper input-group">';
    echo '<span class="input-group-text">$</span>';
}
function add_quantity_wrapper_close() {
    echo '</div>';
}
add_action('woocommerce_before_quantity_input_field', 'add_quantity_wrapper_open');
add_action('woocommerce_after_quantity_input_field', 'add_quantity_wrapper_close');

// Move add to cart button below quantity input and style elements
add_action('wp_head', function() {
    ?><style>
    /* Form layouts - handle both archive and single product pages */
    form.cart {
        display: flex !important;
        flex-direction: column !important;
        gap: 15px !important;
    }

    .woocommerce div.product form.cart .ct-cart-actions {
        display: flex !important;
        flex-direction: column !important;
        gap: 15px !important;
    }

    /* Input group text ($ sign) */
    .gl-quantity-wrapper .input-group-text {
        display: flex !important;
        align-items: center !important;
        padding: 0.375rem 0.75rem !important;
        font-size: var(--theme-form-font-size) !important;
        font-weight: 400 !important;
        line-height: 1.5 !important;
        text-align: center !important;
        white-space: nowrap !important;
        background-color: #d0bde5 !important;
        border: 2px solid #52197E !important;
        border-right: 0 !important;
        border-radius: var(--theme-form-field-border-radius, 3px) 0 0 var(--theme-form-field-border-radius, 3px) !important;
    }

    /* Quantity container */
    .woocommerce div.product form.cart div.quantity,
    .woocommerce form.cart div.quantity {
        float: none !important;
        margin: 0 !important;
        width: 160px !important; /* Explicitly set width */
    }

    /* Input styling */
    .woocommerce .quantity input.qty {
        font-weight: 500 !important;
        text-align: center !important;
        width: 100% !important;
        max-width: none !important;
        height: inherit !important;
        border: 2px solid var(--theme-form-field-border-initial-color) !important;
        border-left: 0 !important;
        border-radius: 0 var(--theme-form-field-border-radius, 3px) var(--theme-form-field-border-radius, 3px) 0 !important;
        --theme-form-font-size: 0.9em !important;
        --theme-form-field-height: 100% !important;
        --theme-form-field-border-style: solid !important;
        --theme-form-field-border-initial-color: var(--quantity-initial-color, var(--theme-button-background-initial-color)) !important;
        --theme-form-field-background-initial-color: transparent !important;
    }

    /* Button styling */
    .woocommerce div.product form.cart .single_add_to_cart_button,
    .woocommerce form.cart .single_add_to_cart_button {
        order: 2 !important;
        width: 100% !important;
        margin: 0 !important;
    }

    /* Input wrapper styling */
    .gl-quantity-wrapper {
        display: flex !important;
        width: 100% !important;
        order: 1 !important;
    }

    /* Ensure button is always last */
    form.cart > *:not(:last-child) {
        margin-bottom: 15px !important;
    }

    /* Add validation styling */
    .woocommerce .quantity input.qty:invalid {
        border-color: #dc3545 !important;
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const quantityInputs = document.querySelectorAll('.quantity input.qty');

        quantityInputs.forEach(input => {
            // Set minimum value constraint
            input.setAttribute('min', '5');

            // Add validation on change
            input.addEventListener('change', function() {
                if (this.value < 5) {
                    this.value = 5;
                    alert('Minimum donation amount is $5.00');
                }
            });

            // Prevent typing in values less than 5
            input.addEventListener('input', function() {
                if (this.value < 5) {
                    this.setCustomValidity('Minimum donation amount is $5.00');
                } else {
                    this.setCustomValidity('');
                }
            });
        });
    });
    </script><?php
});

// Only hide quantity field if viewing cart or checkout
function custom_remove_cart_quantity_fields($return, $product) {
    if (is_cart() || is_checkout()) {
        return true;
    }
    return $return;
}
add_filter('woocommerce_is_sold_individually', 'custom_remove_cart_quantity_fields', 10, 2);


// Cart price update functionality is handled by enqueued JS (assets/js/gl-donation.js)

// Replace subtotal column with input field
function replace_cart_subtotal_with_input($product_subtotal, $cart_item, $cart_item_key) {
    $has_donation_price = !empty($cart_item['gl_is_donation']) && isset($cart_item['gl_custom_price']) && floatval($cart_item['gl_custom_price']) > 0;
    if ($has_donation_price) {
        // On checkout we should not show an editable input — only display the amount as text
        if (is_checkout()) {
            $price = floatval($cart_item['gl_custom_price']);
            // Use WooCommerce formatter for consistent currency formatting
            return wc_price($price);
        }

        // Fixed: close the div opening tag so HTML is valid and doesn't bleed into other cells
        $format = '<div class="input-group gl-input-group">'
                . '<span class="input-group-text gl-input-group-text">$</span>'
                . '<input type="number" name="cart_price_update[%1$s]" value="%2$.2f" class="price-update-input form-control gl-amount-input" data-cart-item-key="%1$s" inputmode="decimal" step="1" min="5" style="width:100px;" />'
                . '</div>';

        $input = sprintf(
            $format,
            esc_attr($cart_item_key),
            floatval($cart_item['gl_custom_price'])
        );
        return $input;
    }
    return $product_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'replace_cart_subtotal_with_input', 10, 3);

// Ensure checkout displays non-editable price for donation line items (run early)
function gl_disable_subtotal_input_on_checkout($product_subtotal, $cart_item, $cart_item_key) {
    if (is_checkout() && !empty($cart_item['gl_is_donation']) && isset($cart_item['gl_custom_price']) && floatval($cart_item['gl_custom_price']) > 0) {
        $price = floatval($cart_item['gl_custom_price']);
        return wc_price($price);
    }
    return $product_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'gl_disable_subtotal_input_on_checkout', 1, 3);

// Handle cart updates including price changes
function handle_cart_update_price() {
    if (!isset($_POST['update_cart'])) {
        return;
    }

    // Check for price updates
    if (isset($_POST['cart_price_update']) && is_array($_POST['cart_price_update'])) {
        foreach ($_POST['cart_price_update'] as $cart_item_key => $price) {
            $cart_contents = WC()->cart->get_cart();
            if (isset($cart_contents[$cart_item_key])) {
                // Only apply updates to items that are marked as donation items
                if (empty($cart_contents[$cart_item_key]['gl_is_donation'])) {
                    continue;
                }

                $new_price = floatval($price);
                // Enforce minimum donation amount on cart update
                if ( $new_price < 5.00 ) {
                    $new_price = 5.00;
                    wc_add_notice( __( 'Donation amount adjusted to minimum of $5.00.', 'woocommerce' ), 'notice' );
                }
                WC()->cart->cart_contents[$cart_item_key]['gl_custom_price'] = $new_price;
             }
         }
         WC()->cart->set_session();
     }
 }
 add_action('woocommerce_before_calculate_totals', 'handle_cart_update_price', 5);

// Validation: if this product already exists in the cart and the add is coming from our
// donation quantity input, update the existing line item's custom_price and prevent
// WooCommerce from adding a new duplicate line item.
function gl_handle_existing_donation_before_add($passed, $product_id, $quantity) {
    if (!empty($_POST['quantity'])) {
        $amount = floatval($_POST['quantity']);

        // Enforce minimum
        if ($amount < 5) {
            wc_add_notice(__('Minimum donation amount is $5.00.', 'woocommerce'), 'error');
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                $current_amount = isset($cart_item['gl_custom_price']) ? floatval($cart_item['gl_custom_price']) : 0;
                $total_amount = $current_amount + $amount;

                // Update existing item
                WC()->cart->cart_contents[$cart_item_key]['gl_custom_price'] = $total_amount;
                WC()->cart->cart_contents[$cart_item_key]['gl_is_donation'] = true; // ensure marked as donation
                WC()->cart->set_session();

                wc_add_notice(sprintf(
                    __('Donation amount increased to $%s.', 'woocommerce'),
                    number_format($total_amount, 2)
                ));

                // Prevent WooCommerce from performing the default add-to-cart
                return false;
            }
        }
    }

    return $passed;
}
add_filter('woocommerce_add_to_cart_validation', 'gl_handle_existing_donation_before_add', 5, 3);

// Prevent redirect-to-cart when adding donations (keep shopper on same page)
function gl_disable_redirect_to_cart_for_donations($url) {
    if (!empty($_POST['quantity'])) {
        // Returning false/empty tells WooCommerce not to redirect
        return false;
    }
    return $url;
}
add_filter('woocommerce_add_to_cart_redirect', 'gl_disable_redirect_to_cart_for_donations');

// NOTE: removed anonymous add_to_cart_validation filter that returned `true` for existing
// donation products because it conflicted with our `gl_prevent_duplicate_addition_for_handled_donation`

// Fallback: on checkout, replace any donation price inputs with non-editable text via JS
function gl_disable_price_inputs_on_checkout_js() {
    if (!is_checkout()) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Select known input shapes we created or others that use the cart_price_update name
        var selectors = ['input.price-update-input', 'input[name^="cart_price_update"]'];
        var inputs = document.querySelectorAll(selectors.join(','));
        inputs.forEach(function(input) {
            try {
                var value = parseFloat(input.value || input.getAttribute('value') || 0);
                if (isNaN(value)) value = 0;
                var formatted = '$' + value.toFixed(2);

                // Prefer replacing the entire input group if present
                var wrapper = input.closest('.gl-input-group') || input.closest('.input-group') || input.parentNode;
                var span = document.createElement('span');
                span.className = 'gl-checkout-price-text';
                span.textContent = formatted;
                // Keep styling consistent: mimic wc_price by using the element's font and spacing
                wrapper.parentNode.replaceChild(span, wrapper);
            } catch (e) {
                // silent
                console.error('gl_disable_price_inputs_on_checkout_js error', e);
            }
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'gl_disable_price_inputs_on_checkout_js', 20);

// Last-resort override: make sure checkout always shows plain text for donation subtotals
function gl_force_subtotal_text_on_checkout($product_subtotal, $cart_item, $cart_item_key) {
    if (is_checkout() && !empty($cart_item['gl_is_donation']) && isset($cart_item['gl_custom_price']) && floatval($cart_item['gl_custom_price']) > 0) {
        return wc_price(floatval($cart_item['gl_custom_price']));
    }
    return $product_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'gl_force_subtotal_text_on_checkout', 9999, 3);

// High-priority safety: ensure free gifts and vouchers always display $0.00 for subtotal
function gl_force_free_item_zero_subtotal($product_subtotal, $cart_item, $cart_item_key) {
    if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
        return wc_price(0);
    }
    return $product_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'gl_force_free_item_zero_subtotal', 10001, 3);

// Add CSS on checkout to hide any remaining donation amount inputs (safety net)
function gl_hide_price_inputs_on_checkout_css() {
    if (!is_checkout()) {
        return;
    }
    echo "<style>.price-update-input, input[name^=\"cart_price_update\"], .gl-input-group .gl-amount-input{display:none!important} .gl-checkout-price-text{font-weight:600; display:inline-block; margin-left:4px;}</style>";
}
add_action('wp_head', 'gl_hide_price_inputs_on_checkout_css', 20);

// Early cleanup: ensure free gifts/vouchers never carry donation flags or prices
function gl_clean_free_items_flags($cart) {
    if (!WC()->cart) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
            // Remove any donation metadata that might have been set erroneously
            if (isset(WC()->cart->cart_contents[$cart_item_key]['gl_custom_price'])) {
                unset(WC()->cart->cart_contents[$cart_item_key]['gl_custom_price']);
            }
            if (isset(WC()->cart->cart_contents[$cart_item_key]['gl_is_donation'])) {
                unset(WC()->cart->cart_contents[$cart_item_key]['gl_is_donation']);
            }

            // Explicitly force the item price to zero
            if (isset($cart_item['data']) && is_object($cart_item['data'])) {
                $cart_item['data']->set_price(0);
                // also clear any stored original_price if present
                if (isset(WC()->cart->cart_contents[$cart_item_key]['original_price'])) {
                    WC()->cart->cart_contents[$cart_item_key]['original_price'] = 0;
                }
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'gl_clean_free_items_flags', 1);

