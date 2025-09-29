<?php
/**
 * Plugin Name: Custom Checkout Fields
 * Description: Adds custom fields to the WooCommerce checkout process
 * Version: 1.0
 * Author: Custom Development
 * Text Domain: custom-checkout-fields
 */

if (!defined('ABSPATH')) {
    exit;
}

class Custom_Checkout_Fields {
    /**
     * Constructor
     */
    public function __construct() {
        // Add the fields to checkout
        add_action('woocommerce_before_order_notes', array($this, 'add_custom_checkout_fields'));
        
        // Validate fields
        add_action('woocommerce_checkout_process', array($this, 'validate_custom_checkout_fields'));
        
        // Save fields to order
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_custom_checkout_fields'));
        
        // Display "Email Sent" field for each item in admin order view
        add_action('woocommerce_after_order_itemmeta', array($this, 'display_email_sent_field_in_admin_order_item'), 10, 3);

        // Add fields to order emails
        add_action('woocommerce_email_order_meta', array($this, 'add_custom_fields_to_emails'), 10, 3);

        // Add checkout-only styles to make the Anonymous field match the desired layout
        add_action('wp_head', array($this, 'print_anonymous_field_styles'));

        // Hook the small script into wp_footer so it runs after theme markup
        add_action('wp_footer', array($this, 'print_anonymous_field_script'));

        // Add admin menu for donations report
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Add admin menu page for the report
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Donations Report', 'custom-checkout-fields'),
            __('Donations Report', 'custom-checkout-fields'),
            'manage_woocommerce',
            'donations-report',
            array($this, 'render_donations_report_page'),
            'dashicons-chart-area',
            56
        );
    }

    /**
     * Render the donations report page
     */
    public function render_donations_report_page() {
        // Check if our form has been submitted
        if (isset($_POST['action']) && $_POST['action'] != '-1' && isset($_POST['donation_rows'])) {
            $this->process_bulk_action();
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Donations Report', 'custom-checkout-fields'); ?></h1>
            <form method="post">
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text"><?php echo esc_html__('Select bulk action', 'custom-checkout-fields'); ?></label>
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1"><?php echo esc_html__('Bulk Actions', 'custom-checkout-fields'); ?></option>
                            <option value="custom_action"><?php echo esc_html__('Custom Action', 'custom-checkout-fields'); ?></option>
                        </select>
                        <input type="submit" id="doaction" class="button action" value="<?php echo esc_attr__('Apply', 'custom-checkout-fields'); ?>">
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <label class="screen-reader-text" for="cb-select-all-1"><?php echo esc_html__('Select All'); ?></label>
                                <input id="cb-select-all-1" type="checkbox">
                            </td>
                            <th class="manage-column column-order_id" scope="col"><?php echo esc_html__('Order ID', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-order_date" scope="col"><?php echo esc_html__('Order Date', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-first_name" scope="col"><?php echo esc_html__('First Name', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-last_name" scope="col"><?php echo esc_html__('Last Name', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-email" scope="col"><?php echo esc_html__('Email Address', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-anonymous_donation" scope="col"><?php echo esc_html__('Anonymous Donation', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-email_sent" scope="col"><?php echo esc_html__('Email Sent', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-company_email" scope="col"><?php echo esc_html__('Company Email', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-product_name" scope="col"><?php echo esc_html__('Company Name', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-product_amount" scope="col"><?php echo esc_html__('Product Amount', 'custom-checkout-fields'); ?></th>
                            <th class="manage-column column-order_comments" scope="col"><?php echo esc_html__('Order Comments', 'custom-checkout-fields'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $orders = wc_get_orders(array('numberposts' => -1));
                        $has_donations = false;
                        if ($orders) {
                            foreach ($orders as $order) {
                                $order_id = $order->get_id();
                                $first_name = $order->get_billing_first_name();
                                $last_name = $order->get_billing_last_name();
                                $email = $order->get_billing_email();
                                $anonymous = get_post_meta($order_id, '_anonymous_donation', true);
                                $anonymous_display = ($anonymous === 'yes') ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields');
                                $email_sent = get_post_meta($order_id, '_email_sent', true);
                                $email_sent_display = ($email_sent === 'yes') ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields');
                                $row_class = ($email_sent !== 'yes') ? 'email-not-sent' : '';

                                foreach ($order->get_items() as $item_id => $item) {
                                    $product_id = $item->get_product_id();

                                    // Exclude products from specific categories
                                    if (has_term(array('free-product-gift', 'free-product-voucher'), 'product_cat', $product_id)) {
                                        continue;
                                    }

                                    $has_donations = true;
                                    $company_email = function_exists('get_field') ? get_field('company_email', $product_id) : '';
                                    $product_name = $item->get_name();
                                    $order_date_obj = $order->get_date_created();
                                    if ($order_date_obj) {
                                        $order_date_obj->setTimezone(new DateTimeZone('America/New_York'));
                                        $order_date = $order_date_obj->format('m/d/y h:i A');
                                    } else {
                                        $order_date = '';
                                    }
                                    $product_amount = $item->get_total();
                                    $order_comments = $order->get_customer_note();
                                    ?>
                                    <tr class="<?php echo esc_attr($row_class); ?>">
                                        <th scope="row" class="check-column">
                                            <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($item_id); ?>"><?php echo sprintf(esc_html__('Select donation from order %s'), esc_html($order_id)); ?></label>
                                            <input id="cb-select-<?php echo esc_attr($item_id); ?>" type="checkbox" name="donation_rows[]" value="<?php echo esc_attr($order_id . '|' . $item_id); ?>">
                                        </th>
                                        <td class="column-order_id"><?php echo esc_html($order_id); ?></td>
                                        <td class="column-order_date"><?php echo esc_html($order_date); ?></td>
                                        <td class="column-first_name"><?php echo esc_html($first_name); ?></td>
                                        <td class="column-last_name"><?php echo esc_html($last_name); ?></td>
                                        <td class="column-email"><?php echo esc_html($email); ?></td>
                                        <td class="column-anonymous_donation"><?php echo esc_html($anonymous_display); ?></td>
                                        <td class="column-email_sent"><?php echo esc_html($email_sent_display); ?></td>
                                        <td class="column-company_email"><?php echo esc_html($company_email); ?></td>
                                        <td class="column-product_name"><?php echo esc_html($product_name); ?></td>
                                        <td class="column-product_amount"><?php echo wp_kses_post(wc_price($product_amount)); ?></td>
                                        <td class="column-order_comments"><?php echo esc_html($order_comments); ?></td>
                                    </tr>
                                    <?php
                                }
                            }
                        }

                        if (!$has_donations) {
                            ?>
                            <tr class="no-items">
                                <td class="colspanchange" colspan="12"><?php echo esc_html__('No donations found.', 'custom-checkout-fields'); ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <style type="text/css">
            .widefat .email-not-sent {
                background-color: #EDC3BE;
            }
            .widefat tbody tr td, .widefat tbody tr th {
                border-bottom: 1px solid #ddd;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#cb-select-all-1').on('click', function() {
                    $('input[name="donation_rows[]"]').prop('checked', this.checked);
                });
            });
        </script>
        <?php
    }

    /**
     * Process bulk actions from the donations report
     */
    public function process_bulk_action() {
        // Security check
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'custom-checkout-fields'));
        }

        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : false;
        $selected_rows = isset($_POST['donation_rows']) ? (array) $_POST['donation_rows'] : array();

        if ($action === 'custom_action' && !empty($selected_rows)) {
            foreach ($selected_rows as $row) {
                // The value is in 'order_id|item_id' format
                list($order_id, $item_id) = explode('|', $row);
                $order_id = intval($order_id);
                $item_id = intval($item_id);

                if ($order_id && $item_id) {
                    // Your custom action logic will go here.
                    // For example, you can get the order and the item:
                    // $order = wc_get_order($order_id);
                    // $item = $order->get_item($item_id);
                }
            }

            // Optional: Add an admin notice to show success
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The custom bulk action has been performed on the selected rows.', 'custom-checkout-fields') . '</p></div>';
            });
        }
    }

    /**
     * Add custom fields to checkout
     */
    public function add_custom_checkout_fields($checkout) {
        // Render custom markup so we can control exact placement and styling:
        // - h5 label
        // - description/help text shown ABOVE the radio inputs
        // - inline radio buttons for Yes / No
        // We intentionally do not use woocommerce_form_field here to avoid the automatic
        // required asterisk rendering; validation is handled server-side.

        $value = $checkout->get_value('anonymous_donation');
        $description = __('If you wish to donate anonymously, please select the radio button below to yes?', 'custom-checkout-fields');

        echo '<div class="anonymous-donation-field form-row-wide">';
        echo '<h5 class="anonymous-donation-label">' . esc_html__('Anonymous?', 'custom-checkout-fields') . '</h5>';
        echo '<p class="description anonymous-donation-desc">' . esc_html($description) . '</p>';

        echo '<div class="woocommerce-input-wrapper">';

        // No
        // Do NOT pre-check any option so the field is truly required on submit
        $checked_no = ($value === 'no') ? 'checked' : '';
        echo '<label class="anonymous-option"><input type="radio" name="anonymous_donation" value="no" ' . $checked_no . ' aria-required="true" required="required" /> <span>' . esc_html__('No', 'custom-checkout-fields') . '</span></label>';

        // Yes
        $checked_yes = ($value === 'yes') ? 'checked' : '';
        echo '<label class="anonymous-option"><input type="radio" name="anonymous_donation" value="yes" ' . $checked_yes . ' aria-required="true" required="required" /> <span>' . esc_html__('Yes', 'custom-checkout-fields') . '</span></label>';

        echo '</div>'; // .woocommerce-input-wrapper
        echo '</div>'; // .anonymous-donation-field
    }

    /**
     * Validate fields
     */
    public function validate_custom_checkout_fields() {
        // anonymous_donation is required — we intentionally do NOT set the form field as required
        // so the default template won't show the '*' next to the inputs. Validate server-side here.
        if (empty($_POST['anonymous_donation']) || !in_array($_POST['anonymous_donation'], array('yes','no'), true)) {
            wc_add_notice(__('Please indicate whether you would like your donation to be anonymous.', 'custom-checkout-fields'), 'error');
        }
    }

    /**
     * Save fields to order meta
     */
    public function save_custom_checkout_fields($order_id) {
        // Only save anonymous_donation when explicitly provided and valid
        if (isset($_POST['anonymous_donation']) && in_array($_POST['anonymous_donation'], array('yes','no'), true)) {
            update_post_meta($order_id, '_anonymous_donation', sanitize_text_field($_POST['anonymous_donation']));
        } else {
            // If the value wasn't provided (shouldn't happen due to validation), make sure we don't persist a default value.
            // Remove any existing meta to avoid accidentally pre-checking an option on a subsequent load.
            delete_post_meta($order_id, '_anonymous_donation');
        }
    }

    /**
     * Display "Email Sent" field in admin order view for each item
     */
    public function display_email_sent_field_in_admin_order_item($item_id, $item, $product) {
        // Don't show on non-product items
        if (!$product) {
            return;
        }

        // Exclude products from specific categories
        if (has_term(array('free-product-gift', 'free-product-voucher'), 'product_cat', $product->get_id())) {
            return;
        }

        $email_sent = wc_get_order_item_meta($item_id, '_email_sent', true);
        $email_sent_display = ($email_sent === 'yes') ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields');

        echo '<div class="email-sent-field" style="margin-top: 10px;">';
        echo '<strong>' . __('Email Sent:', 'custom-checkout-fields') . '</strong> ' . esc_html($email_sent_display);
        echo '</div>';
    }


    /**
     * Add fields to order emails
     */
    public function add_custom_fields_to_emails($order, $sent_to_admin, $plain_text) {
        $order_id = $order->get_id();

        $anonymous = get_post_meta($order_id, '_anonymous_donation', true);
        $anonymous = $anonymous ? $anonymous : 'no';

        if ($plain_text) {
            echo "\n" . __('Anonymous?:', 'custom-checkout-fields') . ' ' . ($anonymous === 'yes' ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields'));
        } else {
            echo '<p><strong>' . __('Anonymous?:', 'custom-checkout-fields') . '</strong> ' . esc_html( $anonymous === 'yes' ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields') ) . '</p>';
        }
    }

    /**
     * Print minimal CSS for Anonymous field on checkout
     */
    public function print_anonymous_field_styles() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        ?>
        <style>
        /* Style the custom h5 label to match the mockup */

        /* Description above the radios */
        .anonymous-donation-field .anonymous-donation-desc {
            font-size: 20px !important;
            font-weight: 600 !important;
            margin: 0 0 18px 0 !important;
            color: inherit !important;
        }
        /* Hide any theme-inserted required asterisk */
        .anonymous-donation-field abbr.required,
        .anonymous-donation-field .required {
            display: none !important;
        }
        /* Hide pseudo-elements that some themes use to draw the required asterisk */
        .anonymous-donation-field .anonymous-donation-desc::after,
        .anonymous-donation-field .anonymous-donation-desc::before,
        .anonymous-donation-field .anonymous-donation-label::after,
        .anonymous-donation-field .anonymous-donation-label::before,
        .anonymous-donation-field .anonymous-option::after,
        .anonymous-donation-field .anonymous-option::before {
            content: none !important;
            display: none !important;
            visibility: hidden !important;
        }

        /* Inline radio options styling */
        .anonymous-donation-field .woocommerce-input-wrapper { display: flex !important; gap: 12px !important; align-items: center !important; flex-wrap: nowrap !important; }
        .anonymous-donation-field .woocommerce-input-wrapper .anonymous-option { display: inline-flex !important; align-items: center !important; gap: 8px !important; font-size: 20px !important; margin: 0 !important; padding: 0 !important; line-height: 1 !important; white-space: nowrap !important; }
        .anonymous-donation-field .woocommerce-input-wrapper .anonymous-option input[type="radio"] { width: 20px !important; height: 20px !important; margin: 0 !important; vertical-align: middle !important; flex: 0 0 auto; }
        .anonymous-donation-field .woocommerce-input-wrapper .anonymous-option span { display: inline-block !important; vertical-align: middle !important; margin: 0 !important; }

        /* Ensure spacing on mobile stacks if needed */
        @media (max-width: 480px) {
            .anonymous-donation-field .woocommerce-input-wrapper { flex-direction: column; align-items: flex-start; }
        }
        </style>
        <?php
    }

    /**
     * Print small JS to remove stray required asterisks or text nodes inserted by themes
     * This runs on checkout only and is defensive: it removes text nodes that are just '*'
     * or elements with common classes like .required, .woocommerce-req near our field.
     * It also adds a client-side validation check to the checkout form so submission is
     * blocked and a friendly WooCommerce-style error is shown if the anonymous field is empty.
     */
    public function print_anonymous_field_script() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        ?>
        <script>
        (function(){
            function cleanAsterisks() {
                var container = document.querySelector('.anonymous-donation-field');
                if (!container) return;

                // Remove elements commonly used for required markers
                var selectors = ['abbr.required', '.required', '.woocommerce-req', '.woocommerce-required'];
                selectors.forEach(function(sel){
                    var els = container.querySelectorAll(sel);
                    els.forEach(function(el){ el.parentNode && el.parentNode.removeChild(el); });
                });

                // Remove any immediate text nodes that consist solely of whitespace plus asterisks
                var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
                var nodesToRemove = [];
                while(walker.nextNode()){
                    var txt = walker.currentNode.nodeValue || '';
                    if (/^\s*\*+\s*(?:\.{0,3})?$/.test(txt)) {
                        nodesToRemove.push(walker.currentNode);
                    }
                }
                nodesToRemove.forEach(function(n){ n.parentNode && n.parentNode.removeChild(n); });

                // Also remove any trailing '*' characters appended to description text nodes
                var desc = container.querySelector('.anonymous-donation-desc');
                if (desc) {
                    // Trim trailing asterisks from the textContent
                    desc.textContent = desc.textContent.replace(/\*+\s*$/,'').trim();
                }

            }

            function showCheckoutError(message) {
                // Use WooCommerce error container if present (preferred)
                var noticesWrap = document.querySelector('.woocommerce-notices-wrapper');
                if (!noticesWrap) {
                    // try common fallback
                    noticesWrap = document.querySelector('.woocommerce-error') || document.body;
                }

                // Remove any existing identical message to avoid duplicates
                var existing = noticesWrap.querySelectorAll('.custom-anonymous-error');
                existing.forEach(function(el){ el.parentNode && el.parentNode.removeChild(el); });

                // Create a UL style error to match WooCommerce markup
                var ul = document.createElement('ul');
                ul.className = 'woocommerce-error custom-anonymous-error';
                var li = document.createElement('li');
                li.textContent = message;
                ul.appendChild(li);

                // Insert at top of noticesWrap if it looks like a wrapper, otherwise prepend to body
                if (noticesWrap.classList && noticesWrap.classList.contains('woocommerce-notices-wrapper')) {
                    noticesWrap.insertBefore(ul, noticesWrap.firstChild);
                } else if (noticesWrap.classList && noticesWrap.classList.contains('woocommerce-error')) {
                    noticesWrap.parentNode.insertBefore(ul, noticesWrap);
                } else {
                    document.body.insertBefore(ul, document.body.firstChild);
                }

                // Ensure viewport scrolls to notices (helps see the error)
                if (ul && typeof ul.scrollIntoView === 'function') {
                    ul.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            function attachClientValidation() {
                var container = document.querySelector('.anonymous-donation-field');
                if (!container) return;
                var checkoutForm = document.querySelector('form.checkout');
                if (!checkoutForm) return;

                // On submit, ensure a radio is selected
                checkoutForm.addEventListener('submit', function(e){
                    // If WooCommerce's checkout script has already prevented native submit and performed AJAX,
                    // this still runs and we can prevent further processing by stopping the event.
                    var checked = container.querySelector('input[name="anonymous_donation"]:checked');
                    if (!checked) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        showCheckoutError('Please indicate whether you would like your donation to be anonymous.');
                        return false;
                    }

                    return true;
                }, { capture: true });

                // Also intercept clicks on the place-order button (covers AJAX flows)
                var placeButtons = document.querySelectorAll('#place_order, button#place_order, input#place_order');
                placeButtons.forEach(function(btn){
                    btn.addEventListener('click', function(e){
                        var checked = container.querySelector('input[name="anonymous_donation"]:checked');
                        if (!checked) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            showCheckoutError('Please indicate whether you would like your donation to be anonymous.');
                            return false;
                        }
                        return true;
                    }, { capture: true });
                });

                // Also defensive delegated click handler for dynamically inserted buttons
                document.addEventListener('click', function(e){
                    var t = e.target || e.srcElement;
                    if (!t) return;
                    if (t.id === 'place_order' || t.matches && (t.matches('button#place_order') || t.matches('input#place_order'))) {
                        var checked = container.querySelector('input[name="anonymous_donation"]:checked');
                        if (!checked) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            showCheckoutError('Please indicate whether you would like your donation to be anonymous.');
                            return false;
                        }
                    }
                }, true);
            }

            document.addEventListener('DOMContentLoaded', function(){
                cleanAsterisks();
                attachClientValidation();
                // run again shortly in case other scripts mutate the DOM
                setTimeout(cleanAsterisks, 250);
                setTimeout(cleanAsterisks, 1000);
            });
        })();
        </script>
        <?php
    }
}

// Initialize the plugin
new Custom_Checkout_Fields();
