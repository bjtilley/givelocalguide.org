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

// Add admin settings page
function fvg_add_admin_settings_page() {
    add_options_page(
        __('Free Voucher Settings', 'free-voucher-gift'),
        __('Free Voucher Settings', 'free-voucher-gift'),
        'manage_options',
        'free-voucher-settings',
        'fvg_render_settings_page'
    );
}
add_action('admin_menu', 'fvg_add_admin_settings_page');

// Render the settings page
function fvg_render_settings_page() {
    // Handle form submission
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['fvg_settings_nonce'], 'fvg_save_settings')) {
        $enabled = isset($_POST['fvg_enabled']) ? 1 : 0;
        update_option('fvg_plugin_enabled', $enabled);
        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'free-voucher-gift') . '</p></div>';
    }

    $enabled = get_option('fvg_plugin_enabled', 1); // Default to enabled
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Free Voucher Gift Settings', 'free-voucher-gift'); ?></h1>

        <form method="post" action="">
            <?php wp_nonce_field('fvg_save_settings', 'fvg_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fvg_enabled"><?php echo esc_html__('Enable Free Voucher Gifts', 'free-voucher-gift'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="fvg_enabled" name="fvg_enabled" value="1" <?php checked($enabled, 1); ?> />
                        <p class="description">
                            <?php echo esc_html__('When enabled, free voucher products will be automatically added to the cart when the total subtotal meets the threshold requirements.', 'free-voucher-gift'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2><?php echo esc_html__('How It Works', 'free-voucher-gift'); ?></h2>
        <p><?php echo esc_html__('This plugin automatically adds free voucher products to the cart when the total cart subtotal (excluding free gifts and vouchers) meets or exceeds the threshold set for each voucher product.', 'free-voucher-gift'); ?></p>

        <h3><?php echo esc_html__('Configuration', 'free-voucher-gift'); ?></h3>
        <ol>
            <li><?php echo esc_html__('Create products and assign them to the "Free Product Voucher" category', 'free-voucher-gift'); ?></li>
            <li><?php echo esc_html__('Set the "Free Voucher Threshold" field for each voucher product using ACF', 'free-voucher-gift'); ?></li>
            <li><?php echo esc_html__('When customers add products to their cart totaling the threshold amount, the voucher will be automatically added', 'free-voucher-gift'); ?></li>
        </ol>
    </div>
    <?php
}

// Helper function to check if plugin is enabled
if (!function_exists('fvg_is_plugin_enabled')) {
    function fvg_is_plugin_enabled() {
        return get_option('fvg_plugin_enabled', 1) == 1;
    }
}

// Helper: safe retrieval of a cart item's line subtotal even early in lifecycle
if (!function_exists('fvg_get_line_subtotal')) {
    function fvg_get_line_subtotal($cart_item) {
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

// Helper: compute subtotal of all non-voucher, non-free-gift items
if (!function_exists('fvg_get_cart_qualified_subtotal')) {
    function fvg_get_cart_qualified_subtotal() {
        if (!WC()->cart) return 0.0;
        $total = 0.0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['is_free_voucher']) || !empty($cart_item['is_free_gift'])) continue;
            $total += fvg_get_line_subtotal($cart_item);
        }
        return $total;
    }
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

// Ensure vouchers are actually free and maintain correct subtotals
function fvg_adjust_voucher_price($cart) {
    if (!WC()->cart || !fvg_is_plugin_enabled()) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (!isset($cart_item['original_price'])) {
            $cart_item['original_price'] = $cart_item['data']->get_price();
        }

        if (!empty($cart_item['is_free_voucher'])) {
            $cart_item['data']->set_price(0);
            $cart_item['line_subtotal'] = 0;
            $cart_item['line_total'] = 0;
        } else {
            $cart_item['data']->set_price($cart_item['original_price']);
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'fvg_adjust_voucher_price', 10);

// Add vouchers to cart when overall qualified subtotal meets thresholds
function fvg_check_and_add_vouchers() {
    if (!WC()->cart || !fvg_is_plugin_enabled()) {
        return;
    }

    // Build once per invocation
    $qualified_subtotal = fvg_get_cart_qualified_subtotal();
    if ($qualified_subtotal <= 0) return;

    $removed_ids = WC()->session->get('fvg_user_removed_vouchers', array());
    $removed_ids = is_array($removed_ids) ? $removed_ids : array();

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => 'free-product-voucher',
            ),
        ),
        'fields' => 'ids',
    );
    $voucher_ids = get_posts($args);

    foreach ($voucher_ids as $vid) {
        $vid_int = intval($vid);
        if (in_array($vid_int, $removed_ids, true)) continue; // user removed this voucher earlier this session

        $threshold = floatval(get_field('free_voucher_threshold', $vid_int));
        if ($threshold <= 0) continue;
        if ($qualified_subtotal < $threshold) continue; // Not yet reached overall threshold

        // Already in cart?
        $voucher_in_cart = false;
        foreach (WC()->cart->get_cart() as $existing_item) {
            if ($existing_item['product_id'] == $vid_int) {
                $voucher_in_cart = true;
                break;
            }
        }
        if ($voucher_in_cart) continue;

        $voucher_product = wc_get_product($vid_int);
        if ($voucher_product) {
            WC()->cart->add_to_cart($vid_int, 1, 0, array(), array(
                'is_free_voucher' => true,
                'original_price' => 0
            ));
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_check_and_add_vouchers', 20 );

// Remember vouchers a user manually removes this session so we don't auto-re-add them immediately
function fvg_record_user_removed_voucher($cart_item_key, $cart) {
    if (!fvg_is_plugin_enabled()) {
        return;
    }

    if (empty($cart) || !isset($cart->removed_cart_contents)) {
        return;
    }

    if (isset($cart->removed_cart_contents[$cart_item_key])) {
        $removed = $cart->removed_cart_contents[$cart_item_key];
        if (!empty($removed['is_free_voucher'])) {
            $removed_ids = WC()->session->get('fvg_user_removed_vouchers', array());
            $removed_ids = is_array($removed_ids) ? $removed_ids : array();
            $removed_ids[] = intval($removed['product_id']);
            $removed_ids = array_unique($removed_ids);
            WC()->session->set('fvg_user_removed_vouchers', $removed_ids);

            if (!wp_doing_ajax()) {
                wc_add_notice(__('Free voucher removed. It will not be re-added automatically.', 'woocommerce'), 'notice');
            }
        }
    }
}
add_action( 'woocommerce_cart_item_removed', 'fvg_record_user_removed_voucher', 20, 2 );

// Remove voucher if overall threshold not met anymore
function fvg_maybe_remove_vouchers() {
    if ( ! WC()->cart || !fvg_is_plugin_enabled()) {
        return;
    }

    $qualified_subtotal = fvg_get_cart_qualified_subtotal();

    // Map of voucher => threshold
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

    $gifts_to_remove = array();

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( empty( $cart_item['is_free_voucher'] ) ) continue;
        $vid = intval( $cart_item['product_id'] );
        $threshold = isset( $vouchers[ $vid ] ) ? $vouchers[ $vid ] : 0;
        if ($threshold <= 0) {
            $gifts_to_remove[] = $cart_item_key;
            continue;
        }
        if ($qualified_subtotal < $threshold) {
            $gifts_to_remove[] = $cart_item_key;
        }
    }

    foreach ( $gifts_to_remove as $key ) {
        WC()->cart->remove_cart_item( $key );
        if (!wp_doing_ajax()) {
            wc_add_notice(__('A free voucher has been removed as it no longer qualifies.', 'woocommerce'), 'notice');
        }
    }
}
add_action( 'woocommerce_before_calculate_totals', 'fvg_maybe_remove_vouchers', 15 );

// Prevent direct quantity changes of free vouchers
function fvg_prevent_voucher_quantity_change( $cart_item_data, $cart_item_key ) {
    if (!fvg_is_plugin_enabled()) {
        return $cart_item_data;
    }

    if ( isset( $cart_item_data['is_free_voucher'] ) && $cart_item_data['is_free_voucher'] ) {
        $cart_item_data['quantity_locked'] = true;
    }
    return $cart_item_data;
}
add_filter( 'woocommerce_cart_item_data', 'fvg_prevent_voucher_quantity_change', 10, 2 );

// Hide quantity selector for free vouchers in cart
function fvg_hide_voucher_quantity( $product_quantity, $cart_item_key, $cart_item ) {
    if (!fvg_is_plugin_enabled()) {
        return $product_quantity;
    }

    if ( isset( $cart_item['is_free_voucher'] ) && $cart_item['is_free_voucher'] ) {
        return '<span class="quantity">1</span>';
    }
    return $product_quantity;
}
add_filter( 'woocommerce_cart_item_quantity', 'fvg_hide_voucher_quantity', 10, 3 );

// Server-side: prevent updating voucher quantities via cart update
function fvg_block_voucher_quantity_update( $passed, $cart_item_key, $values, $quantity ) {
    if (!fvg_is_plugin_enabled()) {
        return $passed;
    }

    if ( ! empty( $values['is_free_voucher'] ) ) {
        if ( intval( $quantity ) !== intval( $values['quantity'] ) ) {
            wc_add_notice( __( 'You cannot change the quantity of a free voucher.', 'woocommerce' ), 'error' );
            return false;
        }
    }
    return $passed;
}
add_filter( 'woocommerce_update_cart_validation', 'fvg_block_voucher_quantity_update', 10, 4 );

// Add class to voucher cart rows
function fvg_add_voucher_cart_class( $class, $cart_item, $cart_item_key ) {
    if (!fvg_is_plugin_enabled()) {
        return $class;
    }

    if ( ! empty( $cart_item['is_free_voucher'] ) ) {
        $class .= ' fvg-free-voucher';
    }
    return $class;
}
add_filter( 'woocommerce_cart_item_class', 'fvg_add_voucher_cart_class', 10, 3 );

// Override subtotal display for vouchers to a non-editable span
function fvg_override_voucher_subtotal( $cart_item_subtotal, $cart_item, $cart_item_key ) {
    if (!fvg_is_plugin_enabled()) {
        return $cart_item_subtotal;
    }

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
    if (!is_cart() && !is_checkout() || !fvg_is_plugin_enabled()) {
        return;
    }

    $zero_price = esc_js(strip_tags(wc_price(0)));
    ?>
    <script>
    (() => {
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
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'fvg_print_voucher_cart_js' );

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
    if ( ! WC()->cart || !fvg_is_plugin_enabled()) {
        return;
    }

    $removed_ids = WC()->session->get( 'fvg_user_removed_vouchers', array() );
    $removed_ids = is_array( $removed_ids ) ? $removed_ids : array();
    if ( empty( $removed_ids ) ) {
        return;
    }

    // Determine which vouchers would still qualify based on current overall subtotal
    $qualified_subtotal = fvg_get_cart_qualified_subtotal();

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
    $valid_voucher_ids = array();
    foreach ($voucher_ids as $vid) {
        $threshold = floatval(get_field('free_voucher_threshold', $vid));
        if ($threshold > 0 && $qualified_subtotal >= $threshold) {
            $valid_voucher_ids[] = intval($vid);
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

// Check vouchers when quantity is updated in cart or other cart validations occur
function fvg_check_vouchers_on_quantity_update($cart) {
    if (is_admin() || !WC()->cart || !fvg_is_plugin_enabled()) {
        return;
    }
    // Re-run add/remove logic by leveraging existing hooks in the next cycle.
    // Force recalculation so hooks fire with updated data.
    WC()->cart->calculate_totals();
    // Directly invoke add + maybe remove to be immediate.
    fvg_check_and_add_vouchers();
    fvg_maybe_remove_vouchers();
}
add_action('woocommerce_after_cart_item_quantity_update', 'fvg_check_vouchers_on_quantity_update', 20, 1);
add_action('woocommerce_check_cart_items', 'fvg_check_vouchers_on_quantity_update', 20);
add_action('woocommerce_cart_loaded_from_session', 'fvg_check_vouchers_on_quantity_update', 20);
add_action('woocommerce_update_cart_action_cart_updated', 'fvg_check_vouchers_on_quantity_update', 20);

// Also check when AJAX quantity is updated
function fvg_check_vouchers_on_ajax_quantity_update() {
    if (!fvg_is_plugin_enabled()) {
        return;
    }
    fvg_check_vouchers_on_quantity_update(WC()->cart);
}
add_action('woocommerce_ajax_cart_item_quantities_updated', 'fvg_check_vouchers_on_ajax_quantity_update', 20);

// Modify the add to cart handler to ensure proper timing (overall subtotal based)
function fvg_check_vouchers_on_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (is_admin() || !fvg_is_plugin_enabled()) {
        return;
    }
    if (!empty($cart_item_data['is_free_voucher'])) {
        return; // ignore voucher itself
    }
    WC()->cart->calculate_totals();
    fvg_check_and_add_vouchers();
    fvg_maybe_remove_vouchers();
}
remove_action('woocommerce_add_to_cart', 'fvg_check_vouchers_on_add', 20);
add_action('woocommerce_add_to_cart', 'fvg_check_vouchers_on_add', 100, 6 );

// Add settings link to plugins page
function fvg_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=free-voucher-settings') . '">' . __('Settings', 'free-voucher-gift') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fvg_add_settings_link');


// End of file
