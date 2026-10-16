<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce > Authorize.net Export page, its AJAX steps and the download handler.
 */
class GLG_Authnet_Admin {
    const PAGE_SLUG = 'glg-authnet-export';
    const CAPABILITY = 'manage_woocommerce';
    const NONCE_ACTION = 'glg_authnet_export';

    private $hook_suffix = '';

    public function __construct() {
        add_action('admin_init', array('GLG_Authnet_Cache', 'maybe_install'));
        add_action('admin_menu', array($this, 'add_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_glg_authnet_start', array($this, 'ajax_start'));
        add_action('wp_ajax_glg_authnet_step', array($this, 'ajax_step'));
        add_action('admin_post_glg_authnet_download', array($this, 'download'));
    }

    public function add_menu() {
        $this->hook_suffix = add_submenu_page(
            'woocommerce',
            __('Authorize.net Export', 'glg-authnet-reports'),
            __('Authorize.net Export', 'glg-authnet-reports'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    public function enqueue_assets($hook_suffix) {
        if ($hook_suffix !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_script(
            'glg-authnet-export',
            GLG_AUTHNET_REPORTS_URL . 'assets/admin.js',
            array(),
            GLG_AUTHNET_REPORTS_VERSION,
            true
        );

        wp_localize_script('glg-authnet-export', 'glgAuthnetExport', array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'downloadUrl' => wp_nonce_url(admin_url('admin-post.php?action=glg_authnet_download'), self::NONCE_ACTION),
            'i18n'        => array(
                'starting'  => __('Starting…', 'glg-authnet-reports'),
                'download'  => __('Download started.', 'glg-authnet-reports'),
                'badResult' => __('Unexpected response from the server.', 'glg-authnet-reports'),
            ),
        ));
    }

    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $client = GLG_Authnet_Client::from_gateway();
        $today = current_time('Y-m-d');
        $first_of_month = current_time('Y-m-01');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Authorize.net Export', 'glg-authnet-reports'); ?></h1>
            <p>
                <?php esc_html_e('Downloads transactions from Authorize.net in the same columns as its standard Transaction export, with one row per line item sent at checkout. Dates are settlement dates, plus submission dates for unsettled transactions.', 'glg-authnet-reports'); ?>
            </p>

            <?php if (is_wp_error($client)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($client->get_error_message()); ?></p></div>
            <?php else : ?>
                <?php if (!$client->is_production()) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e('The Authorize.net gateway is in test mode, so this export reads from the sandbox account.', 'glg-authnet-reports'); ?></p></div>
                <?php endif; ?>

                <form id="glg-authnet-export-form">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="glg-authnet-from"><?php esc_html_e('From', 'glg-authnet-reports'); ?></label></th>
                            <td><input type="date" id="glg-authnet-from" name="from" value="<?php echo esc_attr($first_of_month); ?>" max="<?php echo esc_attr($today); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="glg-authnet-to"><?php esc_html_e('To', 'glg-authnet-reports'); ?></label></th>
                            <td><input type="date" id="glg-authnet-to" name="to" value="<?php echo esc_attr($today); ?>" max="<?php echo esc_attr($today); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Unsettled', 'glg-authnet-reports'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="include_unsettled" value="1" checked>
                                    <?php esc_html_e('Include transactions that have not settled yet', 'glg-authnet-reports'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Format', 'glg-authnet-reports'); ?></th>
                            <td>
                                <fieldset>
                                    <label><input type="radio" name="format" value="xlsx" checked> <?php esc_html_e('Excel (.xlsx)', 'glg-authnet-reports'); ?></label><br>
                                    <label><input type="radio" name="format" value="csv"> <?php esc_html_e('CSV', 'glg-authnet-reports'); ?></label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Build export', 'glg-authnet-reports'); ?></button>
                        <button type="button" class="button" id="glg-authnet-retry" hidden><?php esc_html_e('Retry', 'glg-authnet-reports'); ?></button>
                    </p>
                </form>

                <div id="glg-authnet-progress" hidden>
                    <progress max="100" value="0" style="width: 100%; max-width: 480px;"></progress>
                    <p id="glg-authnet-status" aria-live="polite"></p>
                </div>

                <p class="description">
                    <?php esc_html_e('The first export of a date range fetches each transaction from Authorize.net and can take a few minutes. Settled transactions are saved locally, so later exports of the same range are fast.', 'glg-authnet-reports'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_start() {
        $this->verify_ajax();

        $from = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : '';
        $to = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';

        if (!self::valid_date($from) || !self::valid_date($to)) {
            wp_send_json_error(array('message' => __('Enter valid From and To dates.', 'glg-authnet-reports')));
        }
        if ($from > $to) {
            wp_send_json_error(array('message' => __('The From date must be on or before the To date.', 'glg-authnet-reports')));
        }

        $client = GLG_Authnet_Client::from_gateway();
        if (is_wp_error($client)) {
            wp_send_json_error(array('message' => $client->get_error_message()));
        }

        $job_id = GLG_Authnet_Job::create($from, $to, !empty($_POST['include_unsettled']));
        $progress = GLG_Authnet_Job::step($job_id, $client);

        if (is_wp_error($progress)) {
            wp_send_json_error(array('message' => $progress->get_error_message(), 'job' => $job_id));
        }

        wp_send_json_success(array_merge($progress, array('job' => $job_id)));
    }

    public function ajax_step() {
        $this->verify_ajax();

        $job_id = isset($_POST['job']) ? GLG_Authnet_Job::sanitize_id((string) wp_unslash($_POST['job'])) : '';

        $client = GLG_Authnet_Client::from_gateway();
        if (is_wp_error($client)) {
            wp_send_json_error(array('message' => $client->get_error_message()));
        }

        $progress = GLG_Authnet_Job::step($job_id, $client);
        if (is_wp_error($progress)) {
            wp_send_json_error(array('message' => $progress->get_error_message(), 'job' => $job_id));
        }

        wp_send_json_success(array_merge($progress, array('job' => $job_id)));
    }

    public function download() {
        check_admin_referer(self::NONCE_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to export transactions.', 'glg-authnet-reports'), 403);
        }

        $job_id = isset($_GET['job']) ? GLG_Authnet_Job::sanitize_id((string) wp_unslash($_GET['job'])) : '';
        $format = isset($_GET['format']) && 'csv' === $_GET['format'] ? 'csv' : 'xlsx';

        $state = GLG_Authnet_Job::load($job_id);
        if (is_wp_error($state)) {
            wp_die(esc_html($state->get_error_message()));
        }
        if ('done' !== $state['phase']) {
            wp_die(esc_html__('This export is still running. Return to the export page to finish it.', 'glg-authnet-reports'));
        }

        $rows = array();
        foreach (GLG_Authnet_Cache::get_many(GLG_Authnet_Job::transaction_ids($state)) as $transaction) {
            foreach (GLG_Authnet_Report::rows($transaction) as $row) {
                $rows[] = $row;
            }
        }

        GLG_Authnet_Exporter::send(
            $format,
            sprintf('Transaction_Lines_%s_%s', $state['from'], $state['to']),
            GLG_Authnet_Report::headers(),
            $rows,
            GLG_Authnet_Report::column_types()
        );
    }

    private function verify_ajax() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('You do not have permission to export transactions.', 'glg-authnet-reports')), 403);
        }
    }

    private static function valid_date($date) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
