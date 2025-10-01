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
        $email_template = new Email_Template();
        // Email fields
        $email_fields = $this->get_email_fields($item_id, $order_id);
        $email_body = $email_template->render('donation-email-template', $email_fields);
        
        $post_data = [
            'key' => $this->mandrill_api_key,
            'message' => [
                'html' => $email_body,
                'subject' => 'New Give!Local Donation Received',
                'from_email' => 'givelocal@mountainx.com',
                'from_name' => 'GiveLocal Guide',
                'to' =>[
                    [
                        'email' => 'bjtilley+companytest@gmail.com',
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
        
        $fields['amount_received'] = $item->get_total();
        
        // Get the product ID from the item.
        $product_id = $item->get_product_id();
        
        $fields['nonprofit_name'] = $item->get_name();
        
        // Get all item meta in one go.
        /*
        $all_meta = get_metadata('order_item', $item_id);
        $meta_array = [];
        foreach ($all_meta as $key => $value) {
            // get_metadata returns an array of values for each key, we usually want the first one.
            $meta_array[$key] = $value[0] ?? null;
        }
        */
        
        return $fields;
    }
    
    
    
    public function get_email_fields_test($item_id, $order_id) {
        $order = wc_get_order($order_id);
        echo '<pre>';
        print_r($order); exit;
        $all_meta = get_metadata('order_item', $item_id);
        
        $fields = [];
        $fields['donor_name'] = wc_get_order_item_meta($item_id, '_donor_name', true);
        $fields['donor_email'] = wc_get_order_item_meta($item_id, '_donor_email', true);
        $fields['donation_amount'] = wc_get_order_item_meta($item_id, '_donation_amount', true);
        $fields['nonprofit_name'] = wc_get_order_item_meta($item_id, '_nonprofit_name', true);
        return $fields;
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

