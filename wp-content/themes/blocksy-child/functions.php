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
            return false;
        }

        // Look for existing product in cart
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Add new amount to existing amount
                $current_amount = isset($cart_item['custom_price']) ? floatval($cart_item['custom_price']) : 0;
                $total_amount = $current_amount + $new_amount;

                // Update the existing cart item
                WC()->cart->cart_contents[$cart_item_key]['custom_price'] = $total_amount;
                WC()->cart->set_session();

                // Success message
                wc_add_notice(sprintf(
                    __('Donation amount increased to $%s.', 'woocommerce'),
                    number_format($total_amount, 2)
                ));

                // Prevent new item from being added
                return false;
            }
        }

        // Only reached if product wasn't in cart
        $cart_item_data['custom_price'] = $new_amount;
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
            if (isset($cart_item['custom_price'])) {
                $price = floatval($cart_item['custom_price']);
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

// Remove other cart validation filters that might interfere
remove_all_filters('woocommerce_add_to_cart_validation', 100);
remove_all_filters('woocommerce_add_to_cart', 100);

// Add our single validation filter
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity) {
    // Basic validation only
    return $passed;
}, 10, 3);

// Handle archive page donations
function handle_archive_donations() {
    if (!is_admin() && isset($_POST['add-to-cart']) && isset($_POST['quantity'])) {
        try {
            $product_id = absint($_POST['add-to-cart']);
            $quantity = floatval($_POST['quantity']);

            if ($quantity < 5) {
                wc_add_notice(__('Minimum donation amount is $5.00.', 'woocommerce'), 'error');
                return;
            }

            // Check if product exists in cart
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if ($cart_item['product_id'] == $product_id) {
                    $current_amount = isset($cart_item['custom_price']) ? floatval($cart_item['custom_price']) : 0;
                    $total_amount = $current_amount + $quantity;

                    // Update existing item
                    WC()->cart->cart_contents[$cart_item_key]['custom_price'] = $total_amount;
                    WC()->cart->set_session();

                    wc_add_notice(sprintf(
                        __('Donation amount increased to $%s.', 'woocommerce'),
                        number_format($total_amount, 2)
                    ));

                    // Redirect to cart to prevent double-submission
                    wp_safe_redirect(wc_get_cart_url());
                    exit;
                }
            }

            // Add new item if not found
            WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
                'custom_price' => $quantity
            ));

            // Redirect to cart to prevent double-submission
            wp_safe_redirect(wc_get_cart_url());
            exit;

        } catch (Exception $e) {
            error_log('Archive page donation error: ' . $e->getMessage());
            wc_add_notice(__('There was an error processing your donation. Please try again.', 'woocommerce'), 'error');
        }
    }
}
add_action('wp_loaded', 'handle_archive_donations', 20);

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
    if (isset($cart_item['custom_price'])) {
        $format = '<div class="input-group gl-input-group">'
                . '<span class="input-group-text gl-input-group-text">$</span>'
                . '<input type="number" name="cart_price_update[%1$s]" value="%2$.2f" class="price-update-input form-control gl-amount-input" data-cart-item-key="%1$s" inputmode="decimal" step="1" min="5" style="width:100px;" />'
                . '</div>';

        $input = sprintf(
            $format,
            esc_attr($cart_item_key),
            floatval($cart_item['custom_price'])
        );
        return $input;
    }
    return $product_subtotal;
}
add_filter('woocommerce_cart_item_subtotal', 'replace_cart_subtotal_with_input', 10, 3);

// Handle cart updates including price changes
function handle_cart_update_price() {
    if (!isset($_POST['update_cart'])) {
        return;
    }

    // Check for price updates
    if (isset($_POST['cart_price_update']) && is_array($_POST['cart_price_update'])) {
        foreach ($_POST['cart_price_update'] as $cart_item_key => $price) {
            if (isset(WC()->cart->get_cart()[$cart_item_key])) {
                $new_price = floatval($price);
                // Enforce minimum donation amount on cart update
                if ( $new_price < 5.00 ) {
                    $new_price = 5.00;
                    wc_add_notice( __( 'Donation amount adjusted to minimum of $5.00.', 'woocommerce' ), 'notice' );
                }
                WC()->cart->cart_contents[$cart_item_key]['custom_price'] = $new_price;
             }
         }
         WC()->cart->set_session();
     }
 }
 add_action('woocommerce_before_calculate_totals', 'handle_cart_update_price', 5);

// Prevent duplicate items in cart
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity) {
    if (isset($_POST['quantity'])) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Allow the add_cart_item_data filter to handle the update
                return true;
            }
        }
    }
    return $passed;
}, 10, 3);

// Handle archive page donations
add_action('wp_loaded', function() {
    if (!is_admin() && isset($_POST['add-to-cart']) && isset($_POST['quantity'])) {
        try {
            $product_id = absint($_POST['add-to-cart']);
            $quantity = floatval($_POST['quantity']);

            if ($quantity < 5) {
                wc_add_notice(__('Minimum donation amount is $5.00.', 'woocommerce'), 'error');
                return;
            }

            // Check if product exists in cart
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                if ($cart_item['product_id'] == $product_id) {
                    $current_amount = isset($cart_item['custom_price']) ? floatval($cart_item['custom_price']) : 0;
                    $total_amount = $current_amount + $quantity;

                    // Update existing item
                    WC()->cart->cart_contents[$cart_item_key]['custom_price'] = $total_amount;
                    WC()->cart->set_session();

                    wc_add_notice(sprintf(
                        __('Donation amount increased to $%s.', 'woocommerce'),
                        number_format($total_amount, 2)
                    ));

                    // Redirect to cart to prevent double-submission
                    wp_safe_redirect(wc_get_cart_url());
                    exit;
                }
            }

            // Add new item if not found
            WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
                'custom_price' => $quantity
            ));

            // Redirect to cart to prevent double-submission
            wp_safe_redirect(wc_get_cart_url());
            exit;

        } catch (Exception $e) {
            error_log('Archive page donation error: ' . $e->getMessage());
            wc_add_notice(__('There was an error processing your donation. Please try again.', 'woocommerce'), 'error');
        }
    }
}, 20);



