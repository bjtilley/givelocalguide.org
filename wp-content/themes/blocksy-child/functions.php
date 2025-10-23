<?php


require_once get_stylesheet_directory() . '/includes/GLAppConfig.php';

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


// Save ACF JSON locally
function gl_acf_save_json( $path ) {
    return get_stylesheet_directory() . '/acf-json';
}
add_filter('acf/settings/save_json', 'gl_acf_save_json');


// Custom style for WordPress admin
function custom_admin_styles() {
    wp_enqueue_style( 'custom-admin-css', get_stylesheet_directory_uri() . '/admin-style.css' );
}
add_action( 'admin_enqueue_scripts', 'custom_admin_styles' );


// Swiper JS and CSS from CDN
function gl_swiper_scripts() {
    wp_enqueue_style(
        'gl-swiper-css',
        'https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.css',
        array(),
        '12.0.0'
    );
}
add_action('wp_enqueue_scripts', 'gl_swiper_scripts', 1);

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
        border: 1px solid #52197E !important;
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
        border: 1px solid var(--theme-form-field-border-initial-color) !important;
        border-left: 0 !important;
        border-radius: 0 var(--theme-form-field-border-radius, 3px) var(--theme-form-field-border-radius, 3px) 0 !important;
        --theme-form-font-size: 0.9em !important;
        --theme-form-field-height: 100% !important;
        --theme-form-field-border-style: solid !important;
        --theme-form-field-border-initial-color: var(--quantity-initial-color, var(--theme-button-background-initial-color)) !important;
        --theme-form-field-background-initial-color: transparent !important;
    }

    .woocommerce .quantity input.qty:focus {
        border-color: var(--theme-palette-color-2);
        outline: 0;
        box-shadow: 0 0 0 .25rem rgba(116, 32, 180, 0.15);
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
                . '<input type="number" name="cart_price_update[%1$s]" value="%2$.2f" class="price-update-input form-control gl-amount-input" data-cart-item-key="%1$s" inputmode="decimal" step="1" min="5" style="width:110px;" />'
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

// Alter the order notes label on checkout
add_filter('woocommerce_checkout_fields', function ($fields) {
    // Change the label for the order notes textarea
    if (isset($fields['order']['order_comments'])) {
        $fields['order']['order_comments']['label'] = __('If you would like opt out of receiving your incentive package or you would like to make your donation(s) anonymously, please tell us here. You can also give us any special instructions about contacting you or delivering your incentives, offer feedback on Give!Local or tell us anything else you think might be relevant. Thank you!', 'givelocalguide');
    }
    return $fields;
});



/**
 * Custom shortcodes for product display
 */

/**
 * [acf name="field_name" prefix="Before " suffix=" After" wrapper="h3" class="my-heading" id="123" format="text" fallback=""]
 *
 * - name:     ACF field name (required)
 * - prefix:   String to prepend to the VALUE (optional)
 * - suffix:   String to append to the VALUE (optional)
 * - wrapper:  HTML tag to wrap around the output (optional, e.g. h3, div, span)
 * - class:    Class name(s) to apply to the wrapper (optional)
 * - id:       Post ID or "options". Defaults to current post.
 * - format:   "text" (escaped) or "html" (keep ACF formatting)
 * - fallback: Value to show if field is empty
 */
function gl_acf_display_render( $atts = array() ) {
    $a = shortcode_atts([
            'name'     => '',
            'prefix'   => '',
            'suffix'   => '',
            'wrapper'  => '',
            'class'    => '',
            'id'       => '',
            'format'   => 'text',
            'fallback' => '',
    ], $atts, 'acf');

    if ($a['name'] === '' || !function_exists('get_field')) {
        return $a['fallback'];
    }

    $post_id     = $a['id'] !== '' ? $a['id'] : get_the_ID();

    $format_html = ($a['format'] === 'html');

    $value = get_field($a['name'], $post_id, $format_html);


    if ($value === null || $value === '' || $value === false) {
        return $a['fallback'];
    }

    // Handle arrays (Image, Link, etc.)
    if (is_array($value)) {
        if (isset($value['url'], $value['title'])) {
            $value = $format_html
                    ? '<a href="' . esc_url($value['url']) . '">' . esc_html($value['title']) . '</a>'
                    : $value['url'];
        } elseif (isset($value['url'])) {
            $alt   = $value['alt'] ?? '';
            $value = $format_html
                    ? '<img src="' . esc_url($value['url']) . '" alt="' . esc_attr($alt) . '">'
                    : $value['url'];
        } else {
            $value = implode(', ', array_map('strval', $value));
        }
    }

    $output = $a['prefix']
            . ($format_html ? wp_kses_post((string) $value) : esc_html(wp_strip_all_tags((string) $value)))
            . $a['suffix'];

    // Apply wrapper if provided
    if ($a['wrapper'] !== '') {
        $tag   = tag_escape($a['wrapper']);
        $class = $a['class'] !== '' ? ' class="' . esc_attr($a['class']) . '"' : '';
        $output = "<{$tag}{$class}>{$output}</{$tag}>";
    }

    return $output;
}
add_shortcode( 'gl_acf_display', 'gl_acf_display_render' );

// Get Woocommerce product description
/**
 * [product_description id="123"]
 *
 * - id: Product ID. If omitted, it uses the current product (on single product page).
 */
add_shortcode('product_description', function ($atts) {
    $a = shortcode_atts([
            'id' => '',
    ], $atts, 'product_description');

    $product = null;

    if ($a['id'] !== '') {
        $product = wc_get_product(absint($a['id']));
    } else {
        global $product;
        if (!$product instanceof WC_Product) {
            $qid = get_queried_object_id();
            if ($qid) {
                $product = wc_get_product($qid);
            }
        }
    }

    if (!$product instanceof WC_Product) {
        return '';
    }

    return $product->get_description();
});


/**
 * [product_short_description id="123"]
 *
 * - id: Product ID. If omitted, it uses the current product (on single product page).
 */
add_shortcode('product_short_description', function ($atts) {
    $a = shortcode_atts([
            'id' => '',
    ], $atts, 'product_short_description');

    $product = null;

    if ($a['id'] !== '') {
        $product = wc_get_product(absint($a['id']));
    } else {
        global $product;
        if (!$product instanceof WC_Product) {
            $qid = get_queried_object_id();
            if ($qid) {
                $product = wc_get_product($qid);
            }
        }
    }

    if (!$product instanceof WC_Product) {
        return '';
    }

    return '<h5 class="gl_single_product_header">What They Do: </h5>' . $product->get_short_description();
});


// Display only certain categories on shop page
function custom_shop_page_categories($query) {
    if (!is_admin() && is_shop() && $query->is_main_query()) {
        $query->set('tax_query', array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array(
                   'community',
                   'animals',
                   'creativity-literacy',
                   'education',
                   'environment',
                   'youth',
                   'social-justice',
                   'food-security',
                   'health-and-wellness',
                ),
                'operator' => 'IN',
                'posts_per_page' => -1,
                'orderby' => 'name',
            ),
        ));
    }
}
add_action('pre_get_posts', 'custom_shop_page_categories');


add_shortcode('woocommernce_gl_donor_cards', function () {

    ob_start();
    $template_path = get_stylesheet_directory() . '/partials/gl-donor-cards.php';
    if (file_exists($template_path)) {
        include $template_path;
    }
    return ob_get_clean();

});

/**
 * Displays total donations made using shortcode [gl_donation_total]
 */
add_shortcode('gl_donation_total', function () {
    $gl_app = GLAppConfig::get_instance();
    $gl_donation_total = $gl_app->get('gl_donation_total');

    if(empty($gl_donation_total)) {
        $orders = wc_get_orders(array(
            'date_after' => '2025-09-01',
            'status' => array('wc-completed', 'wc-processing'),
        ));

        $gl_donation_total = 0;
        if (!empty($orders)) {
            foreach ($orders as $order) {
                $gl_donation_total += $order->get_total();
            }
            $gl_app->set('gl_donation_total', $gl_donation_total);
        }
    }


	return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">$' . number_format($gl_donation_total, 2) . '</span><span class="gl_donation_stats__text">In Donations Made</span></div>';
});


add_shortcode('gl_donation_count', function () {

    $gl_app = GLAppConfig::get_instance();
    $gl_donation_count = $gl_app->get('gl_donation_count');
    if (empty($gl_donation_count)) {
        $orders = wc_get_orders(array(
            'date_after' => '2025-09-01',
            'status' => array('wc-completed', 'wc-processing'),
        ));

        $gl_donation_count = 0;
        if (!empty($orders)) {
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ($item->get_subtotal() > 0) {
                        $gl_donation_count++;
                    }
                }
            }
            $gl_app->set('gl_donation_count', $gl_donation_count);
        }

    }


    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">' . $gl_donation_count . '</span><span class="gl_donation_stats__text">Donations Made</span></div>';
});


add_shortcode('gl_max_donation', function () {
    $gl_app = GLAppConfig::get_instance();
    $gl_max_donation = $gl_app->get('gl_max_donation');

    if(empty($gl_max_donation)) {
        $orders = wc_get_orders(array(
            'date_after' => '2025-09-01',
            'status' => array('wc-completed', 'wc-processing'),
        ));

        $gl_max_donation = 0;

        if (!empty($orders)) {
            foreach ($orders as $order) {
                $total = $order->get_total();
                if ($total > $gl_max_donation) {
                    $gl_max_donation = $total;
                }
            }
            $gl_app->set('$gl_max_donation', $gl_max_donation);
        }
    }


    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">$' . number_format($gl_max_donation, 2) . '</span><span class="gl_donation_stats__text">Largest Donation</span></div>';
});




add_shortcode('gl_average_donations', function () {
    $gl_app = GLAppConfig::get_instance();
    $gl_donation_count = $gl_app->get('gl_donation_count');
    $gl_donation_total = $gl_app->get('gl_donation_total');


    $gl_average_donations = 0;
    if(empty($gl_donation_count) || empty($gl_donation_total)) {
        $orders = wc_get_orders(array(
            'date_after' => '2025-09-01',
            'status' => array('wc-completed', 'wc-processing'),
        ));

        $gl_donation_count = 0;
        $gl_donation_total = 0;
        if (!empty($orders)) {
            if (!empty($orders)) {
                foreach ($orders as $order) {
                    $gl_donation_total += $order->get_total();
                }
                $gl_app->set('gl_donation_total', $gl_donation_total);
            }

            $gl_donation_count = 0;
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ($item->get_subtotal() > 0) {
                        $gl_donation_count++;
                    }
                }
            }
            $gl_app->set('gl_donation_count', $gl_donation_count);
        }
    }
    if($gl_donation_count > 0) {
        $gl_average_donations = $gl_donation_total / $gl_donation_count;
    }


    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">$' . number_format($gl_average_donations, 0) . '.00</span><span class="gl_donation_stats__text">Average Donation Made</span></div>';
});


add_shortcode('gl_matched_donations', function () {
    $gl_app = GLAppConfig::get_instance();
    $gl_matched_donations = $gl_app->get('gl_matched_donations');

    $total = 0;
    if( empty($gl_matched_donations) ) {
        global $wpdb;
        $gl_matched_donations = $wpdb->get_var(
                $wpdb->prepare(
                        "SELECT SUM(CAST(meta_value AS UNSIGNED)) 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value != ''",
                'match'
                )
        );
        $gl_app->set('gl_matched_donations', $gl_matched_donations);

    }

    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">$' . number_format($gl_matched_donations, 2) . '</span><span class="gl_donation_stats__text">Matched Donations</span></div>';
});

add_shortcode('gl_total_raised', function () {
    $gl_app = GLAppConfig::get_instance();
    $gl_total_raised = $gl_app->get('gl_total_raised');
    $gl_matched_donations = $gl_app->get('gl_matched_donations');
    $gl_donation_total = $gl_app->get('gl_donation_total');


    if(empty($gl_total_raised) || empty($gl_matched_donations) || empty($gl_donation_total)) {
        $orders = wc_get_orders(array(
                'date_after' => '2025-09-01',
                'status' => array('wc-completed', 'wc-processing'),
        ));

        $gl_donation_total = 0;
        if (!empty($orders)) {
            foreach ($orders as $order) {
                $gl_donation_total += $order->get_total();
            }
            $gl_app->set('gl_donation_total', $gl_donation_total);
        }

        global $wpdb;
        $gl_matched_donations = $wpdb->get_var(
                $wpdb->prepare(
                        "SELECT SUM(CAST(meta_value AS UNSIGNED)) 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value != ''",
                        'match'
                )
        );
        $gl_app->set('gl_matched_donations', $gl_matched_donations);
    }
    $gl_total_raised = $gl_donation_total + $gl_matched_donations;

    return '<div class="gl_donation_stats"><span class="gl_donation_stats__value">$' . number_format($gl_total_raised, 2) . '</span><span class="gl_donation_stats__text">Total Raised</span></div>';
});


/**
 * Shortcode to display Incentives and Matches sections on single product page
 * Example shortcode: [gl_incentives_and_matches]
 */
add_shortcode('gl_incentives_and_matches', function () {

    $content = '';

    $incentives = gl_acf_display_render(
        array( 'name' => 'incentives', 'format' => 'html' )
    );
    if (!empty($incentives)) {
        $content .= '<h5 class="gl_single_product_header">Incentives:</h5>';
        $content .= '<div class="gl_incentives">' . $incentives . '</div>';
    }

    $matches = gl_acf_display_render(
        array( 'name' => 'match_html', 'format' => 'html' )
    );
    if (!empty($matches)) {
        $content .= '<h5 class="gl_single_product_header">Matches:</h5>';
        $content .= '<div class="gl_matches">' . $matches . '</div>';
    }

    return $content;
});


// List of incentives to display on a page
add_shortcode('gl_incentives_list', function () {

    // Fetch all products by categories
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby' => 'name',
        'order' => 'ASC',
        'tax_query' => array(
                array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'slug',
                        'terms'    => array(
                                'community',
                                'animals',
                                'creativity-literacy',
                                'education',
                                'environment',
                                'youth',
                                'social-justice',
                                'food-security',
                                'health-and-wellness',
                        ),
                        'operator' => 'IN',
                        'posts_per_page' => -1,
                ),
        ),
    );

    $content = '';
    $products_query = new WP_Query( $args );
    if ( $products_query->have_posts() ) :
        while ( $products_query->have_posts() ) : $products_query->the_post();
            global $product;


            $incentives = get_field( 'incentives', $product->get_id() );
            if (!empty($incentives)) {
                $content .= '<h5 class="wp-block-heading gl_incentives_header">' . get_the_title() . '</h5>';
                $content .= '<div class="wp-block-list">' . $incentives . '</div>';
            }
        endwhile;
        wp_reset_postdata();
    endif;

    return $content;
});


// List of matches to display on a page
add_shortcode('gl_matches_list', function () {

    // Fetch all products by categories
    $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby' => 'name',
            'order' => 'ASC',
            'tax_query' => array(
                    array(
                            'taxonomy' => 'product_cat',
                            'field'    => 'slug',
                            'terms'    => array(
                                    'community',
                                    'animals',
                                    'creativity-literacy',
                                    'education',
                                    'environment',
                                    'youth',
                                    'social-justice',
                                    'food-security',
                                    'health-and-wellness',
                            ),
                            'operator' => 'IN',
                            'posts_per_page' => -1,
                    ),
            ),
    );

    $content = '';
    $products_query = new WP_Query( $args );
    if ( $products_query->have_posts() ) :
        while ( $products_query->have_posts() ) : $products_query->the_post();
            global $product;

            $matches = get_field( 'match_html', $product->get_id() );
            if (!empty($matches)) {
                $content .= '<h5 class="wp-block-heading gl_incentives_header">' . get_the_title() . '</h5>';
                $content .= '<div class="wp-block-list">';
                if(strpos($matches, '<ul>') === false) {
                    $content .= '<ul><li>' . $matches . '</li></ul>';
                } else {
                    $content .= $matches;
                }
                $content .= '</div>';
            }
        endwhile;
        wp_reset_postdata();
    endif;

    return $content;
});

// Disable click product in woocommerce cart
add_filter('woocommerce_cart_item_permalink','__return_false');
