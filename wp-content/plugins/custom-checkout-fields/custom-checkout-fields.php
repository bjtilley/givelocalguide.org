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
    }

    /**
     * Add custom fields to checkout
     */
    public function add_custom_checkout_fields($checkout) {
        // Example fields - customize these as needed
        woocommerce_form_field('donation_in_honor_of', array(
            'type' => 'text',
            'class' => array('donation-honor-field form-row-wide'),
            'label' => __('Donation in Honor of', 'custom-checkout-fields'),
            'placeholder' => __('Enter name', 'custom-checkout-fields'),
            'required' => false,
            'clear' => true
        ), $checkout->get_value('donation_in_honor_of'));

        woocommerce_form_field('donation_message', array(
            'type' => 'textarea',
            'class' => array('donation-message-field form-row-wide'),
            'label' => __('Donation Message', 'custom-checkout-fields'),
            'placeholder' => __('Enter your message', 'custom-checkout-fields'),
            'required' => false,
            'clear' => true
        ), $checkout->get_value('donation_message'));

        woocommerce_form_field('donation_display_name', array(
            'type' => 'select',
            'class' => array('donation-display-name-field form-row-wide'),
            'label' => __('How would you like your name displayed?', 'custom-checkout-fields'),
            'options' => array(
                'full' => __('Full Name', 'custom-checkout-fields'),
                'anonymous' => __('Anonymous', 'custom-checkout-fields'),
                'initials' => __('Initials Only', 'custom-checkout-fields')
            ),
            'required' => true,
            'clear' => true
        ), $checkout->get_value('donation_display_name'));
    }

    /**
     * Validate fields
     */
    public function validate_custom_checkout_fields() {
        if (!empty($_POST['donation_in_honor_of']) && strlen($_POST['donation_in_honor_of']) > 100) {
            wc_add_notice(__('Honor name must be less than 100 characters', 'custom-checkout-fields'), 'error');
        }

        if (!empty($_POST['donation_message']) && strlen($_POST['donation_message']) > 500) {
            wc_add_notice(__('Donation message must be less than 500 characters', 'custom-checkout-fields'), 'error');
        }

        if (empty($_POST['donation_display_name'])) {
            wc_add_notice(__('Please select how you would like your name displayed', 'custom-checkout-fields'), 'error');
        }
    }

    /**
     * Save fields to order meta
     */
    public function save_custom_checkout_fields($order_id) {
        if (!empty($_POST['donation_in_honor_of'])) {
            update_post_meta($order_id, '_donation_in_honor_of', sanitize_text_field($_POST['donation_in_honor_of']));
        }
        
        if (!empty($_POST['donation_message'])) {
            update_post_meta($order_id, '_donation_message', sanitize_textarea_field($_POST['donation_message']));
        }
        
        if (!empty($_POST['donation_display_name'])) {
            update_post_meta($order_id, '_donation_display_name', sanitize_text_field($_POST['donation_display_name']));
        }
    }

    /**
     * Display fields in admin order view
     */
    public function display_custom_fields_in_admin($order) {
        $order_id = $order->get_id();
        
        echo '<h3>' . __('Donation Information', 'custom-checkout-fields') . '</h3>';
        
        $honor_of = get_post_meta($order_id, '_donation_in_honor_of', true);
        if ($honor_of) {
            echo '<p><strong>' . __('In Honor of:', 'custom-checkout-fields') . '</strong> ' . esc_html($honor_of) . '</p>';
        }
        
        $message = get_post_meta($order_id, '_donation_message', true);
        if ($message) {
            echo '<p><strong>' . __('Message:', 'custom-checkout-fields') . '</strong> ' . esc_html($message) . '</p>';
        }
        
        $display_name = get_post_meta($order_id, '_donation_display_name', true);
        if ($display_name) {
            $display_options = array(
                'full' => __('Full Name', 'custom-checkout-fields'),
                'anonymous' => __('Anonymous', 'custom-checkout-fields'),
                'initials' => __('Initials Only', 'custom-checkout-fields')
            );
            echo '<p><strong>' . __('Display Name Preference:', 'custom-checkout-fields') . '</strong> ' . esc_html($display_options[$display_name]) . '</p>';
        }
    }

    /**
     * Add fields to order emails
     */
    public function add_custom_fields_to_emails($order, $sent_to_admin, $plain_text) {
        $order_id = $order->get_id();
        
        if ($plain_text) {
            $honor_of = get_post_meta($order_id, '_donation_in_honor_of', true);
            if ($honor_of) {
                echo "\n" . __('In Honor of:', 'custom-checkout-fields') . ' ' . $honor_of;
            }
            
            $message = get_post_meta($order_id, '_donation_message', true);
            if ($message) {
                echo "\n" . __('Message:', 'custom-checkout-fields') . ' ' . $message;
            }
            
            $display_name = get_post_meta($order_id, '_donation_display_name', true);
            if ($display_name) {
                $display_options = array(
                    'full' => __('Full Name', 'custom-checkout-fields'),
                    'anonymous' => __('Anonymous', 'custom-checkout-fields'),
                    'initials' => __('Initials Only', 'custom-checkout-fields')
                );
                echo "\n" . __('Display Name Preference:', 'custom-checkout-fields') . ' ' . $display_options[$display_name];
            }
        } else {
            $honor_of = get_post_meta($order_id, '_donation_in_honor_of', true);
            if ($honor_of) {
                echo '<p><strong>' . __('In Honor of:', 'custom-checkout-fields') . '</strong> ' . esc_html($honor_of) . '</p>';
            }
            
            $message = get_post_meta($order_id, '_donation_message', true);
            if ($message) {
                echo '<p><strong>' . __('Message:', 'custom-checkout-fields') . '</strong> ' . esc_html($message) . '</p>';
            }
            
            $display_name = get_post_meta($order_id, '_donation_display_name', true);
            if ($display_name) {
                $display_options = array(
                    'full' => __('Full Name', 'custom-checkout-fields'),
                    'anonymous' => __('Anonymous', 'custom-checkout-fields'),
                    'initials' => __('Initials Only', 'custom-checkout-fields')
                );
                echo '<p><strong>' . __('Display Name Preference:', 'custom-checkout-fields') . '</strong> ' . esc_html($display_options[$display_name]) . '</p>';
            }
        }
    }
}

// Initialize the plugin
new Custom_Checkout_Fields();
