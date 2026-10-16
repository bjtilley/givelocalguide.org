<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores getTransactionDetails responses.
 *
 * Transactions from a settled batch never change, so their details are fetched once.
 * Unsettled transactions are stored too, but flagged so the next export refreshes them.
 */
class GLG_Authnet_Cache {
    const DB_VERSION = '1';
    const DB_VERSION_OPTION = 'glg_authnet_reports_db_version';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'glg_authnet_txn';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            trans_id varchar(20) NOT NULL,
            settled tinyint(1) NOT NULL DEFAULT 0,
            submit_time_utc datetime DEFAULT NULL,
            status varchar(40) NOT NULL DEFAULT '',
            invoice_number varchar(40) NOT NULL DEFAULT '',
            batch_id varchar(20) NOT NULL DEFAULT '',
            details_json longtext NOT NULL,
            fetched_at datetime NOT NULL,
            PRIMARY KEY  (trans_id),
            KEY submit_time_utc (submit_time_utc)
        ) {$charset_collate};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Deploys copy files without running the activation hook, so check on admin_init too.
     */
    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * Returns the IDs from $trans_ids whose cached details came from a settled batch.
     *
     * @param string[] $trans_ids
     * @return string[]
     */
    public static function settled_ids(array $trans_ids) {
        global $wpdb;

        $found = array();
        foreach (array_chunk(array_map('strval', $trans_ids), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $sql = $wpdb->prepare(
                'SELECT trans_id FROM ' . self::table() . " WHERE settled = 1 AND trans_id IN ({$placeholders})",
                $chunk
            );
            $found = array_merge($found, $wpdb->get_col($sql));
        }

        return $found;
    }

    /**
     * @param array $transaction The `transaction` object from getTransactionDetails.
     * @param bool  $settled     True when the transaction came from a settled batch.
     */
    public static function save(array $transaction, $settled) {
        global $wpdb;

        $submit = isset($transaction['submitTimeUTC']) ? strtotime($transaction['submitTimeUTC']) : false;

        $wpdb->replace(self::table(), array(
            'trans_id'        => (string) $transaction['transId'],
            'settled'         => $settled ? 1 : 0,
            'submit_time_utc' => $submit ? gmdate('Y-m-d H:i:s', $submit) : null,
            'status'          => isset($transaction['transactionStatus']) ? (string) $transaction['transactionStatus'] : '',
            'invoice_number'  => isset($transaction['order']['invoiceNumber']) ? (string) $transaction['order']['invoiceNumber'] : '',
            'batch_id'        => isset($transaction['batch']['batchId']) ? (string) $transaction['batch']['batchId'] : '',
            'details_json'    => wp_json_encode($transaction),
            'fetched_at'      => current_time('mysql', true),
        ));
    }

    /**
     * Returns cached details for $trans_ids, oldest submission first.
     *
     * @param string[] $trans_ids
     * @return array[]
     */
    public static function get_many(array $trans_ids) {
        global $wpdb;

        $rows = array();
        foreach (array_chunk(array_map('strval', $trans_ids), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $sql = $wpdb->prepare(
                'SELECT trans_id, submit_time_utc, details_json FROM ' . self::table() . " WHERE trans_id IN ({$placeholders})",
                $chunk
            );
            $rows = array_merge($rows, $wpdb->get_results($sql, ARRAY_A));
        }

        usort($rows, function ($a, $b) {
            return array((string) $a['submit_time_utc'], $a['trans_id']) <=> array((string) $b['submit_time_utc'], $b['trans_id']);
        });

        $transactions = array();
        foreach ($rows as $row) {
            $decoded = json_decode($row['details_json'], true);
            if (is_array($decoded)) {
                $transactions[] = $decoded;
            }
        }

        return $transactions;
    }
}
