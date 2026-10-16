<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal client for the Authorize.net Transaction Reporting API (JSON).
 *
 * Credentials come from the Authorize.net CIM gateway settings, so the plugin keeps no copy.
 * Request keys must stay in schema order: the JSON API is validated against the XML schema.
 */
class GLG_Authnet_Client {
    const PRODUCTION_ENDPOINT = 'https://api.authorize.net/xml/v1/request.api';
    const TEST_ENDPOINT = 'https://apitest.authorize.net/xml/v1/request.api';
    const PAGE_LIMIT = 1000;

    private $login_id;
    private $transaction_key;
    private $environment;

    public function __construct($login_id, $transaction_key, $environment) {
        $this->login_id = $login_id;
        $this->transaction_key = $transaction_key;
        $this->environment = $environment;
    }

    /**
     * @return GLG_Authnet_Client|WP_Error
     */
    public static function from_gateway() {
        if (!function_exists('wc_authorize_net_cim') || !class_exists('WC_Authorize_Net_CIM')) {
            return new WP_Error('glg_authnet_no_gateway', __('The WooCommerce Authorize.net gateway plugin is not active.', 'glg-authnet-reports'));
        }

        $gateway = wc_authorize_net_cim()->get_gateway(WC_Authorize_Net_CIM::CREDIT_CARD_GATEWAY_ID);
        if (!$gateway) {
            return new WP_Error('glg_authnet_no_gateway', __('The Authorize.net credit card gateway could not be loaded.', 'glg-authnet-reports'));
        }

        $login_id = $gateway->get_api_login_id();
        $transaction_key = $gateway->get_api_transaction_key();
        if (!$login_id || !$transaction_key) {
            return new WP_Error('glg_authnet_no_credentials', __('Authorize.net API credentials are not set in the gateway settings.', 'glg-authnet-reports'));
        }

        return new self($login_id, $transaction_key, $gateway->get_environment());
    }

    public function is_production() {
        return 'production' === $this->environment;
    }

    /**
     * Sends one API request and returns the decoded response.
     *
     * @param string $type Request name, e.g. getTransactionDetailsRequest.
     * @param array  $body Request fields after merchantAuthentication, in schema order.
     * @return array|WP_Error
     */
    public function request($type, array $body = array()) {
        $payload = array(
            $type => array_merge(
                array(
                    'merchantAuthentication' => array(
                        'name'           => $this->login_id,
                        'transactionKey' => $this->transaction_key,
                    ),
                ),
                $body
            ),
        );

        $response = wp_remote_post($this->is_production() ? self::PRODUCTION_ENDPOINT : self::TEST_ENDPOINT, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if (200 !== $status) {
            /* translators: %d: HTTP status code */
            return new WP_Error('glg_authnet_http', sprintf(__('Authorize.net returned HTTP %d.', 'glg-authnet-reports'), $status));
        }

        // Authorize.net prefixes JSON responses with a UTF-8 byte order mark.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', wp_remote_retrieve_body($response));
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['messages']['resultCode'])) {
            return new WP_Error('glg_authnet_bad_response', __('Authorize.net returned a response that could not be read.', 'glg-authnet-reports'));
        }

        if ('Ok' !== $data['messages']['resultCode']) {
            $messages = self::as_list(isset($data['messages']['message']) ? $data['messages']['message'] : array());
            $code = isset($messages[0]['code']) ? $messages[0]['code'] : '';
            $text = isset($messages[0]['text']) ? $messages[0]['text'] : __('Unknown error.', 'glg-authnet-reports');

            /* translators: 1: error code, 2: error text */
            $message = sprintf(__('Authorize.net error %1$s: %2$s', 'glg-authnet-reports'), $code, $text);
            if ('E00011' === $code) {
                $message .= ' ' . __('Enable the Transaction Details API in the Authorize.net Merchant Interface under Account > Settings > Security Settings.', 'glg-authnet-reports');
            }

            return new WP_Error('glg_authnet_api', $message);
        }

        return $data;
    }

    /**
     * @param int $first_ts Unix timestamp. The range may span at most 31 days.
     * @param int $last_ts  Unix timestamp.
     * @return string[]|WP_Error Batch IDs.
     */
    public function get_settled_batch_ids($first_ts, $last_ts) {
        $data = $this->request('getSettledBatchListRequest', array(
            'includeStatistics'   => 'false',
            'firstSettlementDate' => gmdate('Y-m-d\TH:i:s\Z', $first_ts),
            'lastSettlementDate'  => gmdate('Y-m-d\TH:i:s\Z', $last_ts),
        ));

        if (is_wp_error($data)) {
            return $data;
        }

        $ids = array();
        foreach (self::as_list(isset($data['batchList']) ? $data['batchList'] : array(), 'batch') as $batch) {
            if (isset($batch['batchId'])) {
                $ids[] = (string) $batch['batchId'];
            }
        }

        return $ids;
    }

    /**
     * One page of transaction summaries from a settled batch.
     *
     * @return array[]|WP_Error
     */
    public function get_batch_transactions($batch_id, $page) {
        return $this->transaction_page('getTransactionListRequest', array('batchId' => (string) $batch_id), $page);
    }

    /**
     * One page of transaction summaries that have not settled yet.
     *
     * @return array[]|WP_Error
     */
    public function get_unsettled_transactions($page) {
        return $this->transaction_page('getUnsettledTransactionListRequest', array(), $page);
    }

    /**
     * @return array|WP_Error The `transaction` object.
     */
    public function get_transaction_details($trans_id) {
        $data = $this->request('getTransactionDetailsRequest', array('transId' => (string) $trans_id));

        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data['transaction']) || !is_array($data['transaction'])) {
            /* translators: %s: transaction ID */
            return new WP_Error('glg_authnet_missing_transaction', sprintf(__('Authorize.net returned no details for transaction %s.', 'glg-authnet-reports'), $trans_id));
        }

        return $data['transaction'];
    }

    private function transaction_page($type, array $body, $page) {
        $data = $this->request($type, array_merge($body, array(
            'sorting' => array(
                'orderBy'         => 'submitTimeUTC',
                'orderDescending' => 'false',
            ),
            'paging'  => array(
                'limit'  => (string) self::PAGE_LIMIT,
                'offset' => (string) $page,
            ),
        )));

        if (is_wp_error($data)) {
            return $data;
        }

        return self::as_list(isset($data['transactions']) ? $data['transactions'] : array(), 'transaction');
    }

    /**
     * Normalizes a JSON value that may be a list, a single object, or a wrapper like {"transaction": [...]}.
     *
     * @return array[]
     */
    public static function as_list($value, $inner_key = null) {
        if (null !== $inner_key && is_array($value) && isset($value[$inner_key])) {
            $value = $value[$inner_key];
        }

        if (empty($value) || !is_array($value)) {
            return array();
        }

        return wp_is_numeric_array($value) ? array_values($value) : array($value);
    }
}
