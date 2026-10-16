<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * An export in progress, advanced a few seconds at a time by AJAX so no single request times out.
 *
 * Phases:
 *   batches - list settled batches, one date window (max 31 days per API call) at a time
 *   lists   - list each batch's transactions, plus unsettled ones when requested
 *   details - fetch getTransactionDetails for anything not already cached
 *   done    - ready to download
 *
 * State lives in a transient owned by the user who started it. If a step fails the state is kept,
 * so a retry resumes where it stopped.
 */
class GLG_Authnet_Job {
    const TRANSIENT_PREFIX = 'glg_authnet_job_';
    const TTL = DAY_IN_SECONDS;
    const WINDOW_DAYS = 30;
    const TIME_BUDGET = 10;
    const UNSETTLED = 'unsettled';

    /**
     * @param string $from Y-m-d in the site timezone.
     * @param string $to   Y-m-d in the site timezone.
     * @return string Job ID.
     */
    public static function create($from, $to, $include_unsettled) {
        $tz = wp_timezone();
        $start = (new DateTimeImmutable($from . ' 00:00:00', $tz))->getTimestamp();
        $end = (new DateTimeImmutable($to . ' 23:59:59', $tz))->getTimestamp();

        // Batch searches past the current time return nothing useful.
        $search_end = min($end, time());
        $windows = array();
        for ($first = $start; $first <= $search_end; $first += self::WINDOW_DAYS * DAY_IN_SECONDS) {
            $windows[] = array($first, min($first + self::WINDOW_DAYS * DAY_IN_SECONDS - 1, $search_end));
        }

        $state = array(
            'user'              => get_current_user_id(),
            'from'              => $from,
            'to'                => $to,
            'start'             => $start,
            'end'               => $end,
            'include_unsettled' => (bool) $include_unsettled,
            'phase'             => 'batches',
            'windows'           => $windows,
            'windows_total'     => count($windows),
            'batches'           => array(),
            'batches_total'     => 0,
            'batch_page'        => 1,
            // trans_id => 1 if from a settled batch, 0 if unsettled. Keeps API order.
            'transactions'      => array(),
            'queue'             => array(),
            'fetched'           => 0,
        );

        $id = wp_generate_password(20, false);
        self::save($id, $state);

        return $id;
    }

    /**
     * @return array|WP_Error
     */
    public static function load($id) {
        $state = get_transient(self::TRANSIENT_PREFIX . $id);

        if (!is_array($state) || (int) $state['user'] !== get_current_user_id()) {
            return new WP_Error('glg_authnet_job_missing', __('This export has expired. Please start it again.', 'glg-authnet-reports'));
        }

        return $state;
    }

    private static function save($id, array $state) {
        set_transient(self::TRANSIENT_PREFIX . $id, $state, self::TTL);
    }

    /**
     * Advances the job for up to TIME_BUDGET seconds.
     *
     * @return array|WP_Error Progress, see progress().
     */
    public static function step($id, GLG_Authnet_Client $client) {
        $state = self::load($id);
        if (is_wp_error($state)) {
            return $state;
        }

        $deadline = microtime(true) + self::TIME_BUDGET;
        $result = true;

        while ('done' !== $state['phase'] && microtime(true) < $deadline) {
            switch ($state['phase']) {
                case 'batches':
                    $result = self::step_batches($state, $client);
                    break;
                case 'lists':
                    $result = self::step_lists($state, $client);
                    break;
                case 'details':
                    $result = self::step_details($state, $client);
                    break;
            }

            if (is_wp_error($result)) {
                break;
            }
        }

        self::save($id, $state);

        return is_wp_error($result) ? $result : self::progress($state);
    }

    private static function step_batches(array &$state, GLG_Authnet_Client $client) {
        if (empty($state['windows'])) {
            if ($state['include_unsettled']) {
                $state['batches'][] = self::UNSETTLED;
                $state['batches_total']++;
            }
            $state['phase'] = 'lists';
            return true;
        }

        list($first, $last) = $state['windows'][0];
        $batch_ids = $client->get_settled_batch_ids($first, $last);
        if (is_wp_error($batch_ids)) {
            return $batch_ids;
        }

        array_shift($state['windows']);
        $state['batches'] = array_merge($state['batches'], $batch_ids);
        $state['batches_total'] += count($batch_ids);

        return true;
    }

    private static function step_lists(array &$state, GLG_Authnet_Client $client) {
        if (empty($state['batches'])) {
            $state['phase'] = empty($state['queue']) ? 'done' : 'details';
            return true;
        }

        $batch_id = $state['batches'][0];
        $page = $state['batch_page'];
        $settled = self::UNSETTLED !== $batch_id;

        $summaries = $settled
            ? $client->get_batch_transactions($batch_id, $page)
            : $client->get_unsettled_transactions($page);

        if (is_wp_error($summaries)) {
            return $summaries;
        }

        $page_ids = array();
        foreach ($summaries as $summary) {
            if (empty($summary['transId'])) {
                continue;
            }

            $trans_id = (string) $summary['transId'];

            // The unsettled list ignores dates, so keep only submissions inside the requested range.
            if (!$settled) {
                $submitted = isset($summary['submitTimeUTC']) ? strtotime($summary['submitTimeUTC']) : false;
                if (!$submitted || $submitted < $state['start'] || $submitted > $state['end']) {
                    continue;
                }
            }

            if (isset($state['transactions'][$trans_id])) {
                continue;
            }

            $state['transactions'][$trans_id] = $settled ? 1 : 0;
            $page_ids[] = $trans_id;
        }

        $cached = $settled ? GLG_Authnet_Cache::settled_ids($page_ids) : array();
        $state['queue'] = array_merge($state['queue'], array_values(array_diff($page_ids, $cached)));

        if (count($summaries) < GLG_Authnet_Client::PAGE_LIMIT) {
            array_shift($state['batches']);
            $state['batch_page'] = 1;
        } else {
            $state['batch_page']++;
        }

        return true;
    }

    private static function step_details(array &$state, GLG_Authnet_Client $client) {
        if (empty($state['queue'])) {
            $state['phase'] = 'done';
            return true;
        }

        $trans_id = (string) $state['queue'][0];
        $details = $client->get_transaction_details($trans_id);
        if (is_wp_error($details)) {
            return $details;
        }

        GLG_Authnet_Cache::save($details, !empty($state['transactions'][$trans_id]));

        array_shift($state['queue']);
        $state['fetched']++;

        return true;
    }

    /**
     * @return array{phase: string, done: bool, percent: int, message: string}
     */
    public static function progress(array $state) {
        $found = count($state['transactions']);

        switch ($state['phase']) {
            case 'batches':
                $done = $state['windows_total'] - count($state['windows']);
                $percent = $state['windows_total'] ? 5 * $done / $state['windows_total'] : 5;
                /* translators: 1: windows searched, 2: total windows */
                $message = sprintf(__('Finding settlement batches (%1$d of %2$d date ranges searched)…', 'glg-authnet-reports'), $done, $state['windows_total']);
                break;

            case 'lists':
                $done = $state['batches_total'] - count($state['batches']);
                $percent = 5 + ($state['batches_total'] ? 25 * $done / $state['batches_total'] : 25);
                /* translators: 1: batches listed, 2: total batches, 3: transactions found */
                $message = sprintf(__('Listing transactions (%1$d of %2$d batches, %3$d transactions found)…', 'glg-authnet-reports'), $done, $state['batches_total'], $found);
                break;

            case 'details':
                $total = $state['fetched'] + count($state['queue']);
                $percent = 30 + ($total ? 70 * $state['fetched'] / $total : 70);
                /* translators: 1: details fetched, 2: details to fetch, 3: transactions found */
                $message = sprintf(__('Fetching line items (%1$d of %2$d new, %3$d transactions total)…', 'glg-authnet-reports'), $state['fetched'], $total, $found);
                break;

            default:
                $percent = 100;
                /* translators: %d: number of transactions */
                $message = sprintf(_n('Ready: %d transaction.', 'Ready: %d transactions.', $found, 'glg-authnet-reports'), $found);
        }

        return array(
            'phase'   => $state['phase'],
            'done'    => 'done' === $state['phase'],
            'percent' => (int) floor($percent),
            'message' => $message,
        );
    }

    /**
     * @return string[]
     */
    public static function transaction_ids(array $state) {
        return array_map('strval', array_keys($state['transactions']));
    }
}
