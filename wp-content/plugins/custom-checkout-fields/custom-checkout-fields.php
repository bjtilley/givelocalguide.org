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
        
        // Display fields in admin order view
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_custom_fields_in_admin'));
        
        // Add fields to order emails
        add_action('woocommerce_email_order_meta', array($this, 'add_custom_fields_to_emails'), 10, 3);

        // Add checkout-only styles to make the Anonymous field match the desired layout
        add_action('wp_head', array($this, 'print_anonymous_field_styles'));

        // Hook the small script into wp_footer so it runs after theme markup
        add_action('wp_footer', array($this, 'print_anonymous_field_script'));
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
     * Display fields in admin order view
     */
    public function display_custom_fields_in_admin($order) {
        $order_id = $order->get_id();

        echo '<h3>' . __('Donation Information', 'custom-checkout-fields') . '</h3>';

        $anonymous = get_post_meta($order_id, '_anonymous_donation', true);
        $anonymous = $anonymous ? $anonymous : 'no';
        echo '<p><strong>' . __('Anonymous?:', 'custom-checkout-fields') . '</strong> ' . esc_html( $anonymous === 'yes' ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields') ) . '</p>';
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
