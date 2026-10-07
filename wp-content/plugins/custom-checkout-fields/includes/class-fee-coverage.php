<?php
/**
 * Optional checkout fee that covers card transaction fees.
 *
 * The donor opts in with a checkbox. The amount is a WooCommerce fee
 * (not a product line) so Authorize.net receives it as its own line item.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GL_Fee_Coverage {
    const SESSION_KEY = 'cover_transaction_fee';
    const META_OPT_IN = '_cover_transaction_fee';
    const META_PERCENT = '_cover_transaction_fee_percent';

    const DEFAULT_PERCENT = 2;
    const DEFAULT_LABEL = 'Card fee coverage';
    const DEFAULT_EXPLANATION = "I'd like to add 2% to help cover the credit card transaction fees.";

    public function __construct() {
        add_action('woocommerce_review_order_before_order_total', array($this, 'render_checkbox'));
        add_action('woocommerce_checkout_update_order_review', array($this, 'store_opt_in_from_post_data'));
        add_action('woocommerce_checkout_process', array($this, 'store_opt_in_from_checkout_post'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_fee'));
        add_action('woocommerce_checkout_create_order', array($this, 'save_order_meta'), 10, 2);
        add_action('woocommerce_checkout_create_order_fee_item', array($this, 'set_fee_nontaxable'), 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_order_meta'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Checkbox sits in the order review, above the order total.
     * WooCommerce prints fee rows just above this hook.
     */
    public function render_checkbox() {
        if (!$this->should_offer() || !WC()->cart) {
            return;
        }

        $label = $this->get_label();
        $explanation = $this->get_explanation();
        $amount = $this->calculate_amount(WC()->cart);
        $opted = $this->is_opted_in();
        ?>
        <tr class="cover-transaction-fee<?php echo $opted ? ' is-opted-in' : ''; ?>">
            <th colspan="2">
                <label class="cover-transaction-fee__card" for="cover_transaction_fee">
                    <input
                        type="checkbox"
                        name="cover_transaction_fee"
                        id="cover_transaction_fee"
                        value="yes"
                        autocomplete="off"
                        <?php checked($opted); ?>
                    />
                    <span class="cover-transaction-fee__copy">
                        <span class="cover-transaction-fee__title"><?php echo esc_html($label); ?></span>
                        <?php if ($explanation !== '') : ?>
                            <span class="cover-transaction-fee__text"><?php echo esc_html($explanation); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="cover-transaction-fee__amount">+<?php echo wp_kses_post(wc_price($amount)); ?></span>
                </label>
            </th>
        </tr>
        <?php
    }

    /**
     * Checkout AJAX sends the form as post_data before totals are recalculated.
     *
     * @param string $post_data Serialized checkout form.
     */
    public function store_opt_in_from_post_data($post_data) {
        if (!$this->session_ready() || !is_string($post_data)) {
            return;
        }

        $posted = array();
        parse_str($post_data, $posted);
        $this->set_opt_in(!empty($posted['cover_transaction_fee']));
    }

    /**
     * Place-order posts the checkbox directly. This runs before checkout
     * recalculates totals, so the fee is included in the charged amount.
     */
    public function store_opt_in_from_checkout_post() {
        if (!$this->session_ready()) {
            return;
        }

        $posted = isset($_POST['cover_transaction_fee'])
            ? sanitize_text_field(wp_unslash($_POST['cover_transaction_fee']))
            : '';
        $this->set_opt_in($posted === 'yes');
    }

    /**
     * @param WC_Cart $cart
     */
    public function add_fee($cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (!$this->is_opted_in()) {
            return;
        }

        $amount = $this->calculate_amount($cart);
        if ($amount <= 0) {
            return;
        }

        $cart->add_fee($this->get_label(), $amount, false);
    }

    /**
     * Cart fees are non-taxable, but order fee items default to taxable.
     * Authorize.net reads that tax status when it builds the line item.
     *
     * @param WC_Order_Item_Fee $item
     * @param string            $fee_key
     */
    public function set_fee_nontaxable($item, $fee_key) {
        if ($item->get_name() === $this->get_label()) {
            $item->set_tax_status('none');
        }
    }

    /**
     * @param WC_Order $order
     * @param array    $data
     */
    public function save_order_meta($order, $data) {
        $opted = $this->is_opted_in() ? 'yes' : 'no';
        $order->update_meta_data(self::META_OPT_IN, $opted);

        if ($opted === 'yes') {
            $order->update_meta_data(self::META_PERCENT, $this->get_percent());
        } else {
            $order->delete_meta_data(self::META_PERCENT);
        }
    }

    /**
     * @param WC_Order $order
     */
    public function display_admin_order_meta($order) {
        $opted = $order->get_meta(self::META_OPT_IN);
        if ($opted !== 'yes' && $opted !== 'no') {
            return;
        }

        echo '<p><strong>' . esc_html__('Card fee coverage:', 'custom-checkout-fields') . '</strong> ';
        echo esc_html($opted === 'yes' ? __('Yes', 'custom-checkout-fields') : __('No', 'custom-checkout-fields'));

        if ($opted === 'yes') {
            $percent = $order->get_meta(self::META_PERCENT);
            if ($percent !== '' && $percent !== null) {
                echo ' (' . esc_html($percent) . '%)';
            }
        }

        echo '</p>';
    }

    public function enqueue_assets() {
        if (!$this->is_checkout_page()) {
            return;
        }

        $plugin_file = dirname(__FILE__) . '/../custom-checkout-fields.php';
        $style = plugin_dir_path($plugin_file) . 'assets/css/checkout-fields.css';
        if (file_exists($style)) {
            wp_enqueue_style(
                'gl-checkout-fields',
                plugins_url('assets/css/checkout-fields.css', $plugin_file),
                array(),
                (string) filemtime($style)
            );
        }

        $script = plugin_dir_path($plugin_file) . 'assets/js/fee-coverage.js';
        if (!file_exists($script)) {
            return;
        }

        wp_enqueue_script(
            'gl-fee-coverage',
            plugins_url('assets/js/fee-coverage.js', $plugin_file),
            array('jquery'),
            (string) filemtime($script),
            true
        );
    }

    /**
     * @param WC_Cart $cart
     * @return float
     */
    private function calculate_amount($cart) {
        $percent = $this->get_percent();
        if ($percent <= 0) {
            return 0.0;
        }

        $base = $this->get_fee_base($cart);
        $amount = (float) wc_format_decimal($base * ($percent / 100), wc_get_price_decimals());

        return $amount > 0 ? $amount : 0.0;
    }

    /**
     * Paid donation lines only. Free gifts and vouchers are excluded, and the
     * fee is not included in its own base. Mirrors ccf_cart_totals.
     *
     * @param WC_Cart $cart
     * @return float
     */
    private function get_fee_base($cart) {
        $base = 0.0;

        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['is_free_gift']) || !empty($cart_item['is_free_voucher'])) {
                continue;
            }

            $quantity = isset($cart_item['quantity']) ? (float) $cart_item['quantity'] : 0;
            if (isset($cart_item['original_price'])) {
                $base += (float) $cart_item['original_price'] * $quantity;
            } elseif (isset($cart_item['data']) && is_object($cart_item['data'])) {
                $base += (float) $cart_item['data']->get_price() * $quantity;
            }
        }

        return $base;
    }

    /**
     * @return bool
     */
    private function should_offer() {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        return $this->calculate_amount(WC()->cart) > 0;
    }

    /**
     * @return float
     */
    private function get_percent() {
        if (!function_exists('get_field')) {
            return 0.0;
        }

        $value = get_field('cover_fee_percent', 'option');
        if ($value === null || $value === false || $value === '') {
            return (float) self::DEFAULT_PERCENT;
        }

        return max(0.0, (float) $value);
    }

    /**
     * @return string
     */
    private function get_label() {
        $label = self::DEFAULT_LABEL;

        if (function_exists('get_field')) {
            $value = get_field('cover_fee_label', 'option');
            if (is_string($value) && trim($value) !== '') {
                $label = trim(wp_strip_all_tags($value));
            }
        }

        if (function_exists('mb_substr')) {
            return mb_substr($label, 0, 31);
        }

        return substr($label, 0, 31);
    }

    /**
     * @return string
     */
    private function get_explanation() {
        if (!function_exists('get_field')) {
            return '';
        }

        $value = get_field('cover_fee_explanation', 'option');
        if ($value === null || $value === false) {
            return self::DEFAULT_EXPLANATION;
        }
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param bool $opted
     */
    private function set_opt_in($opted) {
        WC()->session->set(self::SESSION_KEY, $opted ? 'yes' : 'no');
    }

    /**
     * @return bool
     */
    private function is_opted_in() {
        return $this->session_ready() && WC()->session->get(self::SESSION_KEY) === 'yes';
    }

    /**
     * @return bool
     */
    private function session_ready() {
        return function_exists('WC') && WC()->session;
    }

    /**
     * @return bool
     */
    private function is_checkout_page() {
        return function_exists('is_checkout') && is_checkout() && !is_order_received_page();
    }
}

new GL_Fee_Coverage();
