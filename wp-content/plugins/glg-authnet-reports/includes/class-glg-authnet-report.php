<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns getTransactionDetails payloads into export rows: one row per line item.
 *
 * The first 54 columns match Authorize.net's standard Transaction download, in the same order.
 * "Overall donation amount" and the line item columns follow. "Transaction First Row" is 1 on the
 * first row of each transaction, so amount columns can be summed without counting a transaction
 * once per line item.
 */
class GLG_Authnet_Report {
    /** Default name of the fee line added by GL_Fee_Coverage (custom-checkout-fields). */
    const FEE_COVERAGE_DEFAULT_LABEL = 'Card fee coverage';

    const SUMMARY_HEADERS = array('Overall donation amount');

    const BASE_HEADERS = array(
        'Transaction ID', 'Transaction Status', 'Settlement Amount', 'Settlement Currency', 'Settlement Date/Time',
        'Authorization Amount', 'Authorization Currency', 'Submit Date/Time', 'Authorization Code', 'Reference Transaction ID',
        'Transaction Type', 'Address Verification Status', 'Card Code Status', 'Fraudscreen Applied', 'Recurring Billing Transaction',
        'Partial Capture Status', 'Card Number', 'Expiration Date', 'Bank Account Number', 'Routing Number',
        'Total Amount', 'Currency', 'Invoice Number', 'Invoice Description', 'Customer First Name',
        'Customer Last Name', 'Company', 'Address', 'City', 'State',
        'ZIP', 'Country', 'Phone', 'Fax', 'Email',
        'Customer ID', 'Ship - To First Name', 'Ship - To Last Name', 'Ship - To Company', 'Ship - To Address',
        'Ship - To City', 'Ship - To State', 'Ship - To ZIP', 'Ship - To Country', 'L2 - Tax',
        'L2 - Freight', 'L2 - Duty', 'L2 - Tax Exempt', 'L2 - Purchase Order Number', 'Order Number',
        'Available Card Balance', 'Approved Amount', 'Market Type', 'Product',
    );

    const ITEM_HEADERS = array(
        'Line #', 'Item ID', 'Item Name', 'Item Description', 'Item Quantity',
        'Item Unit Price', 'Item Line Total', 'Item Taxable', 'Transaction First Row',
    );

    const MONEY_COLUMNS = array(
        'Settlement Amount', 'Authorization Amount', 'Total Amount', 'L2 - Tax', 'L2 - Freight',
        'L2 - Duty', 'Available Card Balance', 'Approved Amount', 'Overall donation amount', 'Item Unit Price',
        'Item Line Total',
    );

    const NUMBER_COLUMNS = array('Line #', 'Item Quantity', 'Transaction First Row');

    const STATUS_LABELS = array(
        'authorizedPendingCapture'   => 'Authorized/Pending Capture',
        'capturedPendingSettlement'  => 'Captured/Pending Settlement',
        'communicationError'         => 'Communication Error',
        'refundSettledSuccessfully'  => 'Refund Settled Successfully',
        'refundPendingSettlement'    => 'Refund/Pending Settlement',
        'approvedReview'             => 'Approved Review',
        'declined'                   => 'Declined',
        'couldNotVoid'               => 'Could Not Void',
        'expired'                    => 'Expired',
        'generalError'               => 'General Error',
        'failedReview'               => 'Failed Review',
        'settledSuccessfully'        => 'Settled Successfully',
        'settlementError'            => 'Settlement Error',
        'underReview'                => 'Under Review',
        'voided'                     => 'Voided',
        'FDSPendingReview'           => 'FDS - Pending Review',
        'FDSAuthorizedPendingReview' => 'FDS - Authorized/Pending Review',
        'returnedItem'               => 'Returned Item',
    );

    const TYPE_LABELS = array(
        'authCaptureTransaction'      => 'Authorization w/ Auto Capture',
        'authOnlyTransaction'         => 'Authorization Only',
        'priorAuthCaptureTransaction' => 'Prior Authorization Capture',
        'captureOnlyTransaction'      => 'Capture Only',
        'refundTransaction'           => 'Credit',
        'voidTransaction'             => 'Void',
    );

    const AVS_LABELS = array(
        'A' => 'A - Street Address: Match -- First 5 Digits of Zip: No Match',
        'B' => 'B - Address not provided for AVS check',
        'E' => 'E - AVS Error',
        'G' => 'G - Non U.S. Card Issuing Bank',
        'N' => 'N - Street Address: No Match -- First 5 Digits of Zip: No Match',
        'P' => 'P - AVS not applicable for this transaction',
        'R' => 'R - Retry, System Is Unavailable',
        'S' => 'S - AVS Not Supported by Card Issuing Bank',
        'U' => 'U - Address Information For This Cardholder Is Unavailable',
        'W' => 'W - Street Address: No Match -- All 9 Digits of Zip: Match',
        'X' => 'X - Street Address: Match -- All 9 Digits of Zip: Match',
        'Y' => 'Y - Street Address: Match -- First 5 Digits of Zip: Match',
        'Z' => 'Z - Street Address: No Match -- First 5 Digits of Zip: Match',
    );

    const CARD_CODE_LABELS = array(
        'M' => 'M - Match',
        'N' => 'N - No Match',
        'P' => 'P - Not Processed',
        'S' => 'S - Should be on card, but is not indicated',
        'U' => 'U - Issuer is not certified or has not provided encryption key',
    );

    public static function headers() {
        return array_merge(self::BASE_HEADERS, self::SUMMARY_HEADERS, self::ITEM_HEADERS);
    }

    /**
     * Column type by header: 'money', 'number' or 'text'.
     *
     * @return string[]
     */
    public static function column_types() {
        $types = array();
        foreach (self::headers() as $header) {
            if (in_array($header, self::MONEY_COLUMNS, true)) {
                $types[$header] = 'money';
            } elseif (in_array($header, self::NUMBER_COLUMNS, true)) {
                $types[$header] = 'number';
            } else {
                $types[$header] = 'text';
            }
        }
        return $types;
    }

    /**
     * @param array $t The `transaction` object from getTransactionDetails.
     * @return array[] Rows keyed by header.
     */
    public static function rows(array $t) {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $bill = self::get($t, 'billTo', array());
        $ship = self::get($t, 'shipTo', array());
        $card = self::get($t, array('payment', 'creditCard'), array());
        $bank = self::get($t, array('payment', 'bankAccount'), array());
        $fds = self::get($t, 'FDSFilterAction', '');

        $base = array(
            'Transaction ID'                => self::get($t, 'transId'),
            'Transaction Status'            => self::label(self::STATUS_LABELS, self::get($t, 'transactionStatus')),
            'Settlement Amount'             => self::money(self::get($t, 'settleAmount')),
            'Settlement Currency'           => $currency,
            'Settlement Date/Time'          => self::date(self::get($t, array('batch', 'settlementTimeUTC'))),
            'Authorization Amount'          => self::money(self::get($t, 'authAmount')),
            'Authorization Currency'        => $currency,
            'Submit Date/Time'              => self::date(self::get($t, 'submitTimeUTC')),
            'Authorization Code'            => self::get($t, 'authCode'),
            'Reference Transaction ID'      => self::get($t, 'refTransId') ?: '0',
            'Transaction Type'              => self::label(self::TYPE_LABELS, self::get($t, 'transactionType')),
            'Address Verification Status'   => self::label(self::AVS_LABELS, self::get($t, 'AVSResponse')),
            'Card Code Status'              => self::label(self::CARD_CODE_LABELS, self::get($t, 'cardCodeResponse')),
            'Fraudscreen Applied'           => '' !== $fds ? $fds : 'Not Applicable',
            'Recurring Billing Transaction' => self::flag(self::get($t, 'recurringBilling', false), 'Y', 'N'),
            // Not exposed by the API.
            'Partial Capture Status'        => 'Not Applicable',
            'Card Number'                   => self::get($card, 'cardNumber'),
            'Expiration Date'               => self::get($card, 'expirationDate'),
            'Bank Account Number'           => self::get($bank, 'accountNumber'),
            'Routing Number'                => self::get($bank, 'routingNumber'),
            'Total Amount'                  => self::money(self::get($t, 'authAmount')),
            'Currency'                      => $currency,
            'Invoice Number'                => self::get($t, array('order', 'invoiceNumber')),
            'Invoice Description'           => self::text(self::get($t, array('order', 'description'))),
            'Customer First Name'           => self::get($bill, 'firstName'),
            'Customer Last Name'            => self::get($bill, 'lastName'),
            'Company'                       => self::get($bill, 'company'),
            'Address'                       => self::get($bill, 'address'),
            'City'                          => self::get($bill, 'city'),
            'State'                         => self::get($bill, 'state'),
            'ZIP'                           => self::get($bill, 'zip'),
            'Country'                       => self::get($bill, 'country'),
            'Phone'                         => self::get($bill, 'phoneNumber'),
            'Fax'                           => self::get($bill, 'faxNumber'),
            'Email'                         => self::get($t, array('customer', 'email')),
            'Customer ID'                   => self::get($t, array('customer', 'id')),
            'Ship - To First Name'          => self::get($ship, 'firstName'),
            'Ship - To Last Name'           => self::get($ship, 'lastName'),
            'Ship - To Company'             => self::get($ship, 'company'),
            'Ship - To Address'             => self::get($ship, 'address'),
            'Ship - To City'                => self::get($ship, 'city'),
            'Ship - To State'               => self::get($ship, 'state'),
            'Ship - To ZIP'                 => self::get($ship, 'zip'),
            'Ship - To Country'             => self::get($ship, 'country'),
            'L2 - Tax'                      => self::money(self::get($t, array('tax', 'amount'))),
            'L2 - Freight'                  => self::money(self::get($t, array('shipping', 'amount'))),
            'L2 - Duty'                     => self::money(self::get($t, array('duty', 'amount'))),
            'L2 - Tax Exempt'               => self::flag(self::get($t, 'taxExempt', false), 'Yes', 'No'),
            'L2 - Purchase Order Number'    => self::get($t, array('order', 'purchaseOrderNumber')),
            'Order Number'                  => '',
            'Available Card Balance'        => self::money(self::get($t, 'prepaidBalanceRemaining')),
            // Only set on partial authorizations, which this site does not use.
            'Approved Amount'               => self::money(''),
            'Market Type'                   => self::get($t, 'marketType', self::get($t, array('batch', 'marketType'))),
            'Product'                       => self::get($t, 'product', self::get($t, array('batch', 'product'))),
        );

        $items = GLG_Authnet_Client::as_list(self::get($t, 'lineItems', array()), 'lineItem');
        if (empty($items)) {
            $items = array(null);
        }

        // Settlement amount minus the donor's optional card fee coverage line.
        $fee_coverage = 0.0;
        foreach ($items as $item) {
            if (is_array($item) && self::is_fee_coverage(self::text(self::get($item, 'name')))) {
                $fee_coverage += (float) self::get($item, 'quantity', 0) * (float) self::get($item, 'unitPrice', 0);
            }
        }
        $base['Overall donation amount'] = self::money(round((float) self::get($t, 'settleAmount') - $fee_coverage, 2));

        $rows = array();
        foreach ($items as $index => $item) {
            $line = array_fill_keys(self::ITEM_HEADERS, '');
            $line['Transaction First Row'] = 0 === $index ? '1' : '0';

            if (is_array($item)) {
                $quantity = (float) self::get($item, 'quantity', 0);
                $unit_price = (float) self::get($item, 'unitPrice', 0);

                $line['Line #'] = (string) ($index + 1);
                $line['Item ID'] = self::get($item, 'itemId');
                $line['Item Name'] = self::text(self::get($item, 'name'));
                $line['Item Description'] = self::text(self::get($item, 'description'));
                $line['Item Quantity'] = self::number($quantity);
                $line['Item Unit Price'] = self::money($unit_price);
                $line['Item Line Total'] = self::money($quantity * $unit_price);
                $line['Item Taxable'] = self::flag(self::get($item, 'taxable', false), 'Yes', 'No');
            }

            $rows[] = array_merge($base, $line);
        }

        return $rows;
    }

    /**
     * True when a line item is the card fee coverage fee. Matches the default name and the label
     * currently set in the Fee Coverage options, built the same way as GL_Fee_Coverage::get_label().
     */
    private static function is_fee_coverage($name) {
        static $labels = null;

        if (null === $labels) {
            $labels = array(self::FEE_COVERAGE_DEFAULT_LABEL);

            if (function_exists('get_field')) {
                $value = get_field('cover_fee_label', 'option');
                if (is_string($value) && trim($value) !== '') {
                    $labels[] = mb_substr(trim(wp_strip_all_tags($value)), 0, 31);
                }
            }

            $labels = array_map('strtolower', $labels);
        }

        return in_array(strtolower(trim($name)), $labels, true);
    }

    /**
     * Reads a nested value as a string (or the default when missing).
     *
     * @param string|string[] $path
     */
    private static function get($data, $path, $default = '') {
        foreach ((array) $path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data) || null === $data[$key]) {
                return $default;
            }
            $data = $data[$key];
        }

        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        return is_scalar($data) ? (string) $data : $data;
    }

    private static function label(array $labels, $value) {
        if ('' === $value) {
            return '';
        }
        if (isset($labels[$value])) {
            return $labels[$value];
        }
        // Unknown enum: "someNewStatus" -> "Some New Status".
        return ucwords(trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value)));
    }

    private static function flag($value, $yes, $no) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? $yes : $no;
    }

    private static function money($value) {
        return number_format((float) $value, 2, '.', '');
    }

    private static function number($value) {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * The gateway HTML-encodes names and descriptions before sending them.
     */
    private static function text($value) {
        return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Formats an API UTC timestamp like the standard export: 15-Oct-2026 06:32:16 PM EDT.
     */
    private static function date($utc) {
        if ('' === $utc) {
            return '';
        }

        try {
            $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return $utc;
        }

        return $date->setTimezone(wp_timezone())->format('d-M-Y h:i:s A T');
    }
}
