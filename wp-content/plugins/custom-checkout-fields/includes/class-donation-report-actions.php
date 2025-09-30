<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles actions for the Donations Report.
 */
class Donation_Report_Actions {

    /**
     * Constructor.
     */
    public function __construct() {
        // The constructor can be used to set up properties if needed.
    }

    /**
     * Example Action: Marks the '_email_sent' status as 'yes' for given items.
     *
     * @param array $item_ids An array of order item IDs to be updated.
     * @return int The number of items that were successfully updated.
     */
    public function mark_email_as_sent( $item_ids ) {
        if ( empty( $item_ids ) || !is_array( $item_ids ) ) {
            return 0;
        }

        $updated_count = 0;
        foreach ( $item_ids as $item_id ) {
            // Ensure we have a valid item ID
            $item_id = intval($item_id);
            if ( $item_id > 0 ) {
                // Use the WooCommerce function to update the item's metadata
                wc_update_order_item_meta( $item_id, '_email_sent', 'yes' );
                $updated_count++;
            }
        }

        return $updated_count;
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

