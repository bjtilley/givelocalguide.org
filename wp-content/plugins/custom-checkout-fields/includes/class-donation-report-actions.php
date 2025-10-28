<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Handles actions for the Donations Report.
 */
class Donation_Report_Actions {
    
    private $mandrill_api_key;
    
    /**
     * Constructor.
     */
    public function __construct() {
        $this->mandrill_api_key = MANDRILL_API_KEY;
    }

    /**
     * Example Action: Marks the '_email_sent' status as 'yes' and send donation emails for given $order_items
     *
     * @param array $order_items An array of item_ids and order_ids to update.
     * @return int The number of items that were successfully updated.
     */
    public function mark_email_as_sent( $order_items ) {
        if ( empty( $order_items ) || !is_array( $order_items ) ) {
            return 0;
        }

        $updated_count = 0;
        foreach ( $order_items as $item_id =>  $order_id) {
            // Ensure we have a valid item ID
            $item_id = intval($item_id);
            if ( $item_id > 0 ) {
                // Send donation email
                $response = $this->send_email($item_id, $order_id);
                if($response) {
                    wc_update_order_item_meta( $item_id, '_email_sent', 'yes' );
                    $updated_count++;
                }
            }
        }

        return $updated_count;
    }
    
    
    public function send_email($item_id, $order_id) {
        if (empty($this->mandrill_api_key)) {
            throw new Exception('Mandrill API key is not set.');
        }
        
        // Get email body
        // Email fields
        $email_fields = $this->get_email_fields($item_id, $order_id);
        if(empty($email_fields) || empty($email_fields['company_email'])) {
            return false;
        }
        if($email_fields['anonymous_status'] === 'Yes') {
            $email_template_name = 'anonymous-donation-email-template';
        } else{
            $email_template_name = 'donation-email-template';
        }
        $email_template = new Email_Template();
        $email_body = $email_template->render($email_template_name, $email_fields);
        
        $post_data = [
            'key' => $this->mandrill_api_key,
            'message' => [
                'html' => $email_body,
                'subject' => 'New Give!Local Donation Received',
                'from_email' => 'givelocal@mountainx.com',
                'from_name' => 'GiveLocal Guide',
                'to' =>[
                    [
                        'email' => $email_fields['company_email'],
                        'type' => 'to',
                    ]
                ]
            ]
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://mandrillapp.com/api/1.0/messages/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($post_data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $mandrill_response = json_decode($response);
        
        if(!empty($mandrill_response[0]) && isset($mandrill_response[0]->status) && $mandrill_response[0]->status == 'sent') {
            return true;
        } else {
            return false;
        }
    }
    
    
    public function get_email_fields($item_id, $order_id) {
        
        $fields = [];
        // Gets the order object. Caches the result.
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [];
        }
        
        $fields['billing_first_name'] = $order->get_billing_first_name();
        $fields['billing_last_name'] = $order->get_billing_last_name();
        $fields['order_date'] = $order->get_date_created()->date('m/d/Y');
        $fields['order_id'] = $order_id;
        $fields['order_comments'] = $order->get_customer_note();
        $fields['customer_email'] = $order->get_billing_email();
        $fields['customer_address'] = $order->get_billing_address_1();
        $fields['customer_phone'] = $order->get_billing_phone();
        $fields['customer_city'] = $order->get_billing_city();
        $fields['customer_state'] = $order->get_billing_state();
        $fields['customer_zip'] = $order->get_billing_postcode();
        
        
        // Gets the item object from the order.
        $item = $order->get_item($item_id);
        if (!$item) {
            return [];
        }
        $fields['nonprofit_name'] = $item->get_name();
        $fields['amount_received'] = $item->get_total();
        
        
        
        // --- Product Fields ---
        $product_id = $item->get_product_id();
        if ($product_id && function_exists('get_fields')) {
            $product_fields = get_fields($product_id);
            if (is_array($product_fields) && !empty($product_fields['company_email'])) {
                $fields['company_email'] = $product_fields['company_email'];
            }
        }
        
        // Anonymous status for order item
        $anonymous = get_post_meta($order_id, '_anonymous_donation', true);
        $fields['anonymous_status'] = $this->get_anonymous_status($anonymous, $fields['order_comments']);
        // Incetives option for order item
        $incentives = get_post_meta($order_id, '_incentives_option', true);
        $fields['incentives_option'] = ($incentives === 'yes') ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields');
        
        return $fields;
    }
    
    
    public function get_anonymous_status($anonymous_field, $comments) {
        
        $anonymous_keyword_exists = stripos($comments, 'anonymous') !== false;
        if( $anonymous_field == 'yes' || $anonymous_keyword_exists ) {
            return 'Yes';
        } else {
            return 'No';
        }
    }
    

    /**
     * You can add other bulk action methods here in the future.
     * For example, a method to export selected rows to a CSV.
     *
     * @param array $item_ids
     */
    public function export_selected_to_csv( $item_ids ) {
        // Logic for exporting to CSV would go here.
    }
}
