<?php
/*
 * Plugin Name: WordPress Vendreo Payment Gateway
 * Plugin URI: https://github.com/vendreo/wordpress-vendreo-payment-gateway
 * Description: Take Vendreo payments on your store.
 * Author: Vendreo
 * Author URI: https://vendreo.com
 * Version: 1.1.0
 */

add_filter('woocommerce_payment_gateways', 'vendreo_add_gateway_class');
add_action('plugins_loaded', 'vendreo_init_gateway_class');

/**
 * @param $gateways
 * @return mixed
 */
function vendreo_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Vendreo_Gateway';

    return $gateways;
}

/**
 * @return void
 */
function vendreo_init_gateway_class()
{
    class WC_Vendreo_Gateway extends WC_Payment_Gateway
    {
        /**
         * @var string
         */
        public $id;

        /**
         * @var string
         */
        public $icon;

        /**
         * @var bool
         */
        public $has_fields;

        /**
         * @var string
         */
        public $method_title;

        /**
         * @var string
         */
        public $method_description;

        /**
         * @var string[]
         */
        public $supports;

        /**
         * @var string
         */
        public $title;

        /**
         * @var string
         */
        public $description;

        /**
         * @var bool
         */
        public $enabled;

        /**
         * @var bool
         */
        public $testmode;

        /**
         * @var string
         */
        public $application_key;

        /**
         * @var string
         */
        public $secret_key;

        /**
         * @var array
         */
        public $form_fields;

        public function __construct()
        {
            $this->id = 'vendreo';
            $this->icon = 'https://app.vendreo.com/images/vendreo-fullcolour.svg';
            $this->has_fields = true;
            $this->method_title = 'Fast Bank Transfer (Vendreo)';
            $this->method_description = 'Accept payments via bank transfer using Vendreo\'s Payment Gateway';

            $this->supports = ['products'];

            $this->init_form_fields();

            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->application_key = $this->testmode ? $this->get_option('test_application_key') : $this->get_option('application_key');
            $this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('secret_key');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_vendreo_gateway', [$this, 'callback_handler']);
            add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
        }

        /**
         * Setup plugin options.
         *
         * @return void
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Vendreo Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Fast Bank Transfer (Vendreo)',
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay directly from your banking app.',
                ],
                'testmode' => [
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ],
                'test_application_key' => [
                    'title' => 'Test Application Key',
                    'type' => 'text'
                ],
                'test_secret_key' => [
                    'title' => 'Test Secret Key',
                    'type' => 'password',
                ],
                'application_key' => [
                    'title' => 'Live Application Key',
                    'type' => 'text'
                ],
                'secret_key' => [
                    'title' => 'Live Secret Key',
                    'type' => 'password'
                ],
            ];
        }

        /**
         * @return void
         */
        public function payment_fields()
        {
        }

        /**
         * @return void
         */
        public function payment_scripts()
        {
        }

        /**
         * @return void
         */
        public function validate_fields()
        {
        }

        /**
         * @param $order_id
         * @return array|false
         */
        public function process_payment($order_id)
        {
            global $woocommerce;

            $order = wc_get_order($order_id);

            $order->update_status('pending-payment', __('Awaiting Vendreo Payment', 'wc-gateway-vendreo'));

            $post = [
                'application_key' => $this->application_key,
                'amount' => (int)($order->get_total() * 100),
                'country_code' => 'GB',
                'currency' => 'GBP',
                "description" => "Order #{$order_id}",
                'payment_type' => 'single',
                "redirect_url" => $this->get_return_url($order),
                "reference_id" => $order_id,
                "basket_items" => $this->get_basket_details(),
            ];

            header('Content-Type: application/json');
            $ch = curl_init('https://api.vendreo.com/v1/request-payment');
            $authorization = "Authorization: Bearer " . $this->secret_key;
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Accept: application/json', $authorization]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $result = curl_exec($ch);
            curl_close($ch);

            if (!$result) {
                return false;
            }

            $result = json_decode($result);

            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $result->redirect_url
            ];
        }

        /**
         * Returns itemised basket details.
         *
         * @return array[]
         */
        public function get_basket_details(): array
        {
            $basket = [];

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];

                $basket[] = [
                    'description' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => (int) ($product->get_price() * 100),
                    'total' => (int)(($product->get_price() * 100) * $cart_item['quantity']),
                ];
            }

            return $basket;
        }

        /**
         * @return void
         */
        public function callback_handler()
        {
            $json = file_get_contents('php://input');
            $data = json_decode($json);

            $order = wc_get_order($data->reference_id);

            if ($data->act == 'payment_completed') {
                $order->payment_complete();
                wc_reduce_stock_levels($order->get_id());
            }
        }

        /**
         * @return void
         */
        public function check_response()
        {
        }

        /**
         * @return void
         */
        public function webhook()
        {
            echo "TEST";
        }
    }

    /**
     * @return mixed
     */
    function at_rest_testing_endpoint()
    {
        global $woocommerce;

        $order = wc_get_order(wc_get_order_id_by_order_key($_GET['key']));
        $order->update_status('on-hold', __('Awaiting Vendreo Payment Confirmation', 'wc-gateway-vendreo'));

        return wp_redirect($order->get_checkout_order_received_url());
    }

    /**
     * @return void
     */
    function at_rest_init()
    {
        $namespace = 'vendreo/v1';
        $route = 'postback';

        register_rest_route(
            $namespace,
            $route,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => 'at_rest_testing_endpoint'
            ]
        );
    }

    add_action('rest_api_init', 'at_rest_init');
}
