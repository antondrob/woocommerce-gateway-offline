<?php
/**
 * Plugin Name: WooCommerce Q-R Payment Gateway
 * Plugin URI:
 * Description:
 * Author: OnePix
 * Author URI: https://onepix.net/
 * Version: 1.0.2
 * Text Domain: wc-gateway-offline
 * Domain Path: /i18n/languages/
 *
 *
 * @package   WC-Gateway-Offline
 * @author    SkyVerge
 * @category  Admin
 *
 * This offline gateway payment method.
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce ion-holds active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_filter('woocommerce_get_order_item_totals', function ($total_rows, $order, $tax_display) {
    if ($order->get_payment_method() === 'offline_gateway' && $order->get_transaction_id()) {
        $total_rows['payment_method']['value'] .= '<p><b>Transaction ID:</b> ' . $order->get_transaction_id() . '</p>';
    }
    return $total_rows;
}, 10, 3);

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 * @since 1.0.0
 */
function wc_offline_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Offline';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_offline_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 */
function wc_offline_gateway_plugin_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=offline_gateway') . '">' . __('Configure', 'wc-gateway-offline') . '</a>'
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_offline_gateway_plugin_links');


/**
 * Offline Payment Gateway
 *
 * Provides an Offline Payment Gateway;
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class        WC_Gateway_Offline
 * @extends        WC_Payment_Gateway
 * @version        1.0.0
 * @package        WooCommerce/Classes/Payment
 * @author
 */
add_action('plugins_loaded', 'wc_offline_gateway_init', 11);

function wc_offline_gateway_init()
{

    class WC_Gateway_Offline extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = 'offline_gateway';
            $this->icon = $this->get_option('logo');
            $this->has_fields = true;
            $this->method_title = __('Q-R Payment Gateway', 'wc-gateway-offline');
            $this->method_description = __('Allows offline payments. Orders are marked as "on-hold" when received.', 'wc-gateway-offline');


            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->customer_note = $this->get_option('customer_note');
            $this->instructions = $this->get_option('instructions', $this->description);
            $this->qr_code = $this->get_option('qr_code');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
            add_filter('woocommerce_payment_complete_order_status', [$this, 'set_on_hold'], 10, 3);

            // Customer Emails
            add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
            add_action('wp_enqueue_scripts', [$this, 'wp_enqueue_scripts']);
        }


        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = apply_filters('wc_offline_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-offline'),
                    'type' => 'checkbox',
                    'label' => __('Enable Q-R Payment Gateway', 'wc-gateway-offline'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'wc-gateway-offline'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-offline'),
                    'default' => __('Q-R Payment Gateway', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc-gateway-offline'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc-gateway-offline'),
                    'default' => __('Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),
                'customer_note' => array(
                    'title' => __('Customer Note', 'wc-gateway-offline'),
                    'type' => 'textarea',
                    'description' => __('Customer Note that the customer will see on your checkout.', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'wc-gateway-offline'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-gateway-offline'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'qr_code' => array(
                    'title' => __('QR code image url', 'wc-gateway-offline'),
                    'type' => 'url',
                    'description' => __('This controls the QR code image for the payment method the customer sees during checkout.', 'wc-gateway-offline'),
                    'desc_tip' => true,
                ),
                'logo' => array(
                    'title' => __('Logo url', 'wc-gateway-offline'),
                    'type' => 'url',
                    'description' => __('This controls payment method logo the customer sees during checkout.', 'wc-gateway-offline'),
                    'desc_tip' => true,
                )
            ));
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false)
        {

            if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        public function payment_fields()
        {
            parent::payment_fields();
            if (!empty($this->qr_code)) {
                echo '<img class="qr-code-img" src="' . $this->qr_code . '">';
                if (!empty($this->customer_note)) {
                    echo '<p class="customer-note">' . $this->customer_note . '</p>';
                }
                woocommerce_form_field('transaction-id', [
                    'label' => 'Transaction ID',
                    'required' => true
                ]);
            }
        }

        public function validate_fields()
        {
            if (empty($_POST['transaction-id'])) {
                wc_add_notice(__('Transaction ID is missing.', 'wc-gateway-offline'), 'error');
                return false;
            }
            return true;
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->payment_complete($_POST['transaction-id']);
            $order->add_order_note(__('Transaction to be checked.', 'wc-gateway-offline'));
            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function set_on_hold($status, $order_id, $order)
        {
            if ($order->get_payment_method() === 'offline_gateway') {
                $order->add_order_note(__('Transaction to be checked.', 'wc-gateway-offline'));
                $status = 'on-hold';
            }
            return $status;
        }

        public function wp_enqueue_scripts(){
            wp_enqueue_style('qr-pay', plugin_dir_url(__FILE__) . 'assets/css/qr-pay.css');
        }

    } // end \WC_Gateway_Offline class
}