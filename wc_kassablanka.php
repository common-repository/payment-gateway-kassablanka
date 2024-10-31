<?php

/*
  Plugin Name: Payment gateway Kassablanka
  Description: Позволяет использовать платежный шлюз Кассабланка с WooCommerce
  Version: 1.0.0
 */
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

add_action('plugins_loaded', 'woocommerce_kassablanka', 0);

function woocommerce_kassablanka() {
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class is not available, do nothing
    if (class_exists('WC_Kassablanka'))
        return;

    /**
     * Description of Kassablanka
     *
     * @author Andrey Nekrylov
     */
    class WC_Kassablanka extends WC_Payment_Gateway {

        function kassablanka_logger($var) {
            if(!WP_DEBUG)
                return TRUE;
            if ($var) {
                $date = '>>>> ' . date('Y-m-d H:i:s') . "\n";
                $result = $var;
                if (is_array($var) || is_object($var)) {
                    $result = print_r($var, TRUE);
                }
                $result .= "\n\n";
                $path = plugin_dir_path(__FILE__) . 'kassablanka.log';
                error_log($date . $result, 3, $path);
                return TRUE;
            }
            return FALSE;
        }

        function __construct() {

            $plugin_dir = plugin_dir_url(__FILE__);

            global $woocommerce;

            $this->id = 'kassablanka';
            $this->icon = apply_filters('woocommerce_kassablanka_icon', '' . $plugin_dir . 'kassablanka.png');
            $this->has_fields = false;

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
//            $this->title = $this->get_option('title');
            $this->b2c_client = $this->get_option('b2c_client', 'test');
            $this->b2c_key = $this->get_option('b2c_key', 'test');
            $this->title = $this->get_option('title', 'Оплата картой через B2CPL');
            $this->description = $this->get_option('description');

            // Save options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_ipn_response'));
        }

        function process_payment($order_id) {
//            parent::process_payment($order_id);
            $order = wc_get_order($order_id);
            $items = $order->get_items();

            foreach ($items as $item) {
                if ($item instanceof WC_Order_Item)
                    $products[] = array(
                        "partid" => $item->get_id(),
                        "partname" => $item->get_name(),
                        "quantity" => $item->get_quantity(),
                        "price" => $order->get_item_total($item)
                    );
            }
            if ((float) $order->get_shipping_total() > 0) {
                $products[] = array(
                    "partname" => 'Доставка',
                    "quantity" => 1,
                    "price" => (float) $order->get_shipping_total()
                );
            }

//            $b2c_order_id = $order_id . '___' . time();
//            $this->b2c_logger('Request: ' . $action_adr . ': ' . print_r($args, true) . 'Response: ' . $response);
            $pay_reg_params = array(
                "client" => $this->b2c_client,
                "key" => $this->b2c_key,
                "func_name" => "register",
                "code" => $order_id,
                "description" => "Описание заказа.",
                "amount_topay" => intval($order->get_total() * 100),
                "return_uri" => home_url( '/', is_ssl()?'https':'http') . '/?wc-api=wc_kassablanka&b2cpay=result&orderim=' . $order_id,//$this->get_return_url($order),
                "check_create" => true,
                "phone" => preg_replace("/[^0-9]/", '', $order->get_billing_phone()), //delete all characters except digits
                "email" => $order->get_billing_email(),
                "products" => $products,
            );
            $json_param = json_encode($pay_reg_params);
            $this->kassablanka_logger('$json_pparam' . ': ' . print_r($json_pparam, true));
            if (json_last_error()) {
                return array(
                    'result' => 'falure',
                    'redirect' => $this->get_return_url($order)
                );
            }
//            $ret_json = $this->send_post_answer($json_param);
            $ret_json = $this->register_pay($json_param);
            $this->kassablanka_logger('$ret_json' . ': ' . print_r($ret_json, true));
            $pay_obj = json_decode($ret_json);
            if (is_object($pay_obj) && $pay_obj->success) {

                $order = new WC_Order($order_id);

                // Mark as on-hold (we're awaiting the cheque)
                $order->update_status('on-hold', __('Awaiting cheque payment', 'woocommerce'));

                // Reduce stock levels
                wc_reduce_stock_levels($order_id);

                // Remove cart
//                $woocommerce->cart->empty_cart();
                

                $this->kassablanka_logger('Request: : ' . print_r($this->get_return_url($order), true));

                return array(
                    'result' => 'success',
                    'redirect' => $pay_obj->pay_uri
                );
            }
        }



        function register_pay($param) {
            $url = 'https://pay.b2cpl.ru/services/pay_api.ashx';
            $args = array(
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(),
                'body' => $param,
                'cookies' => array()
            );
            $response = wp_remote_post(esc_url_raw($url), $args);
            return $response['body'];
        }

        function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Включить/Выключить', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включен', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Название', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Это название, которое пользователь видит во время проверки.', 'woocommerce'),
                    'default' => __('Кассабланка', 'woocommerce')
                ),
                'b2c_client' => array(
                    'title' => __('Клиент B2CPL', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста введите имя клиента B2CPL API', 'woocommerce'),
                    'default' => ''
                ),
                'b2c_key' => array(
                    'title' => __('API ключ', 'woocommerce'),
                    'type' => 'password',
                    'description' => __('Пожалуйста введите ключ B2CPL API.', 'woocommerce'),
                    'default' => ''
                ),
                'description' => array(
                    'title' => __('Описание', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce'),
                    'default' => 'Оплата банковской картой'
                )
            );
        }

        function check_ipn_response() {
            global $woocommerce;

            $this->kassablanka_logger('Request: : ' . print_r($_GET, true));
            if (isset($_GET['b2cpay']) AND sanitize_text_field($_GET['b2cpay']) == 'result') {
                @ob_clean();
                $orderim = sanitize_text_field($_GET['orderim']);
                $orderid = sanitize_text_field($_GET['orderId']);
                
                
                if (isset($_GET['orderim']) && isset($_GET['orderId'])) {
                    
                    $order = new WC_Order($orderim);
                    $this->kassablanka_logger('Request: : ' . print_r(array('Попал удачно', $order), true));

                    // Payment completed
                    $order->add_order_note(__('Платеж успешно Совершен.', 'woocommerce'));
                    $order->payment_complete();
                    // Clear cart and redirect to thank you 
                    $order->update_status('processing', __('Заказ успешно оплачен', 'woocommerce'));
                    wc_clear_cart_after_payment();
                    $this->kassablanka_logger('Request: : ' . print_r(array('Попал удачно После обработки', $order), true));
//                    WC()->cart->empty_cart();
                    wp_redirect($this->get_return_url($order));
                    exit;
                } elseif(isset($_GET['orderim'])) {
                    
                    $order = new WC_Order($orderim);
                    $this->kassablanka_logger('Request: : ' . print_r(array('Попал не удачно', $order), true));

                    // Payment completed
                    $order->add_order_note(__('Платеж завершен неудачно.', 'woocommerce'));
                    $order->update_status('failed', __('Заказ не оплачен', 'woocommerce'));
//                    $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));

                    wp_redirect($order->get_cancel_order_url());
                    exit;
                }
                wp_die('IPN Request Failure');
                exit;
            }
        }

        

    }

    /**
     * Add the gateway to WooCommerce
     * */
    function add_Kassablanka($methods) {
        $methods[] = 'WC_Kassablanka';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_Kassablanka');
}
