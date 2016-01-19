<?php
/*
Plugin Name: WooCommerce - Fondy Money
Plugin URI: http://fondy.eu
Description: Fondy Payment Gateway for WooCommerce.
Version: 1.0
Author: www.fondy.eu
Author URI: https://www.fondy.eu/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
add_action("init", "fondy");
function fondy(){
    load_plugin_textdomain("woocommerce-oplata", false, basename(dirname(__FILE__)));
}

add_action('plugins_loaded', 'woocommerce_oplata_init', 0);
define('IMGDIR', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/img/');

function woocommerce_oplata_init()
{

    //load_plugin_textdomain("woocommerce_oplata", false, basename(dirname(__FILE__)));
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (isset($_GET['msg']) && !empty($_GET['msg'])) {
        add_action('the_content', 'showOplataMessage');
    }
    function showOplataMessage($content)
    {
        return '<div class="' . htmlentities($_GET['type']) . '">' . htmlentities(urldecode($_GET['msg'])) . '</div>' . $content;
    }

    /**
     * Gateway class
     */
    class WC_oplata extends WC_Payment_Gateway
    {
        const ORDER_APPROVED = 'approved';
        const ORDER_DECLINED = 'declined';
        const SIGNATURE_SEPARATOR = '|';
        const ORDER_SEPARATOR = ":";

        public function __construct()
        {
            $this->id = 'oplata';
            $this->method_title = 'Fondy';
            $this->method_description = "Payment gateway";
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            if ($this->settings['showlogo'] == "yes") {
                $this->icon = IMGDIR . 'logo.png';
            }
            $this->title = $this->settings['title'];
            $this->redirect_page_id = $this->settings['redirect_page_id'];


            $this->merchant_id = $this->settings['merchant_id'];
            $this->salt = $this->settings['salt'];
            $this->description = $this->settings['description'];

            $this->msg['message'] = "";
            $this->msg['class'] = "";

//            add_action('init', array(&$this, 'check_oplata_response'));
//            //update for woocommerce >2.0
//            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_oplata_response'));

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                /* 2.0.0 */
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_oplata_response'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                /* 1.6.6 */
                add_action('init', array(&$this, 'check_oplata_response'));
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }

            add_action('woocommerce_receipt_oplata', array(&$this, 'receipt_page'));
        }

        function init_form_fields()
        {
            $this->form_fields = array('enabled' => array('title' => __('Enable/Disable', 'woocommerce-oplata'),
                                                          'type' => 'checkbox',
                                                          'label' => __('Enable Fondy Payment Module.', 'woocommerce-oplata'),
                                                          'default' => 'no',
                                                          'description' => 'Show in the Payment List as a payment option'),
                                       'title' => array('title' => __('Title:', 'woocommerce-oplata'),
                                                        'type' => 'text',
                                                        'default' => __('Online Payments', 'woocommerce-oplata'),
                                                        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-oplata'),
                                                        'desc_tip' => true),
                                       'description' => array('title' => __('Description:', 'woocommerce-oplata'),
                                                              'type' => 'textarea',
                                                              'default' => __('Pay securely by Credit or Debit Card or Internet Banking through fondy service.', 'woocommerce-oplata'),
                                                              'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-oplata'),
                                                              'desc_tip' => true),
                                       'merchant_id' => array('title' => __('Merchant KEY', 'woocommerce-oplata'),
                                                              'type' => 'text',
                                                              'description' => __('Given to Merchant by fondy'),
                                                              'desc_tip' => true),
                                       'salt' => array('title' => __('Merchant secretkey', 'woocommerce-oplata'),
                                                       'type' => 'text',
                                                       'description' => __('Given to Merchant by fondy', 'woocommerce-oplata'),
                                                       'desc_tip' => true),
                                       'showlogo' => array('title' => __('Show Logo', 'woocommerce-oplata'),
                                                           'type' => 'checkbox',
                                                           'label' => __('Show the "fondy" logo in the Payment Method section for the user', 'woocommerce-oplata'),
                                                           'default' => 'yes',
                                                           'description' => __('Tick to show "fondy" logo'),
                                                           'desc_tip' => true),
                                       'redirect_page_id' => array('title' => __('Return Page', 'woocommerce-oplata'),
                                                                   'type' => 'select',
                                                                   'options' => $this->oplata_get_pages('Select Page'),
                                                                   'description' => __('URL of success page', 'woocommerce-oplata'),
                                                                   'desc_tip' => true));
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options()
        {
            echo '<h3>' . __('Fondy.eu', 'woocommerce-oplata') . '</h3>';
            echo '<p>' . __('Payment gateway', 'woocommerce-oplata') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields for techpro, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay with Oplata.', 'woocommerce-oplata') . '</p>';
            echo $this->generate_oplata_form($order);
        }

        protected function getSignature($data, $password, $encoded = true)
        {
            $data = array_filter($data, function($var) {
                return $var !== '' && $var !== null;
            });
            ksort($data);

            $str = $password;
            foreach ($data as $k => $v) {
                $str .= self::SIGNATURE_SEPARATOR . $v;
            }

            if ($encoded) {
                return sha1($str);
            } else {
                return $str;
            }
        }

        private function getProductInfo($order_id)
        {
            return "Order: $order_id";
        }

        /**
         * Generate payu button link
         **/
        function generate_oplata_form($order_id)
        {
            $order = new WC_Order($order_id);

            $oplata_args = array('order_id' => $order_id . self::ORDER_SEPARATOR . time(),
                                 'merchant_id' => $this->merchant_id,
                                 'order_desc' => $this->getProductInfo($order_id),
                                 'amount' => $order->order_total,
                                 'currency' => get_woocommerce_currency(),
                                 'server_callback_url' => $this->getCallbackUrl(),
                                 'response_url' => $this->getCallbackUrl(),
                                 'lang' => $this->getLanguage(),
                                 'sender_email' => $this->getEmail($order));

            $oplata_args['signature'] = $this->getSignature($oplata_args, $this->salt);

            return '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>
                    <script src="https://api.fondy.eu/static_common/v1/checkout/ipsp.js"></script>
                    <script src="https://rawgit.com/dimsemenov/Magnific-Popup/master/dist/jquery.magnific-popup.js"></script>
                    <link href="https://rawgit.com/dimsemenov/Magnific-Popup/master/dist/magnific-popup.css" type="text/css" rel="stylesheet" media="screen">
                    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">

<style>
#checkout_wrapper a{
    font-size: 20px;
    top: 30px;
    padding: 20px;
    position: relative;
}
#checkout_wrapper {
    text-align: left;
    position: relative;
    background: #FFF;
    /* padding: 30px; */
    padding-left: 15px;
    padding-right: 15px;
    padding-bottom: 30px;
    width: auto;
    max-width: 2000px;
    margin: 9px auto;
}
.btn-lime {
  height: 61px;
  line-height: 61px;
  background: #5ac341;
  font-size: 16px!important;
  font-weight: bold;
  text-decoration: none!important;
  color: #ffffff!important;
  position: relative;
  text-transform: uppercase;
  padding: 0 30px 0 30px;
  margin: 0 auto;
  display: inline-block;
  cursor: pointer;
  margin-left: 25px;
  border-radius: 6px;
}
</style>
<div id="checkout">
 <a class="btn-lime" onclick="callmag();">Reload</a>
<div id="checkout_wrapper"><a href="javascript:void(0)" onclick=resize(480);><i class="fa fa-mobile"></i></a>
            <a href="javascript:void(0)" onclick=resize(768);><i class="fa fa-tablet"></i></a>
            <a href="javascript:void(0)" onclick=resize(993);><i class="fa fa-desktop"></i></a></div>
</div>
<script>
function resize(val) {
 if(val==480){
 checkoutInit(button.getUrl(),val);
 }
 if(val==768){
 checkoutInit(button.getUrl(),val);
 }
 if(val==993){
checkoutInit(button.getUrl(),val);
}
}
function callmag(){
$.magnificPopup.open({
showCloseBtn:false,
        items: {
            src: $("#checkout_wrapper"),
            type: "inline"
        }
    });
}
$(document).ready(function() {
 $.magnificPopup.open({
 showCloseBtn:false,
        items: {
            src: $("#checkout_wrapper"),
            type: "inline"
        }
    });
    })
</script>
<script>
function checkoutInit(url, val) {
	$ipsp("checkout").scope(function() {
		this.setCheckoutWrapper("#checkout_wrapper");
		this.addCallback(__DEFAULTCALLBACK__);
		this.action("show", function(data) {
           $("#checkout_loader").remove();
            $("#checkout").show();
        });
		this.action("hide", function(data) {
            $("#checkout").hide();
        });
        if(val){
        this.width(val);
        this.action("resize", function(data) {
        $("#checkout_wrapper").width(val).height(data.height);
            });
        }else{
         this.action("resize", function(data) {
        $("#checkout_wrapper").width(480).height(data.height);
            });
        }
		this.loadUrl(url);
	});
    };
    var button = $ipsp.get("button");
    button.setMerchantId('.$oplata_args[merchant_id].');
    button.setAmount('.$oplata_args[amount].', "'.$oplata_args[currency].'", true);
    button.setHost("api.fondy.eu");
    button.addParam("order_desc","'.$oplata_args[order_desc].'");
    button.addParam("order_id","'.$oplata_args[order_id].'");
    button.addParam("lang","'.$oplata_args[lang].'");//button.addParam("delayed","N");
    button.addParam("server_callback_url","'.$oplata_args[server_callback_url].'");
    button.addParam("sender_email","'.$oplata_args[sender_email].'");
    button.setResponseUrl("'.$oplata_args[response_url].'");
    checkoutInit(button.getUrl());
    </script>';
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url(true);
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
            }

            return array('result' => 'success',
                         'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, $checkout_payment_url)));
        }

        private function getCallbackUrl()
        {
            $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
            //For wooCoomerce 2.0
            return add_query_arg('wc-api', get_class($this), $redirect_url);
        }

        private function getLanguage()
        {
            return substr(get_bloginfo ( 'language' ), 0, 2);
        }

        private function getEmail($order)
        {
            $current_user = wp_get_current_user();
            $email = $current_user->user_email;

            if (empty($email)) {
                $email = $order->billing_email;
            }

            return $email;
        }

        protected function isPaymentValid($response)
        {
            global $woocommerce;

            list($orderId,) = explode(self::ORDER_SEPARATOR, $response['order_id']);
            $order = new WC_Order($orderId);
            if ($order === FALSE) {
                return __('An error has occurred during payment. Please contact us to ensure your order has submitted.','woocommerce-oplata');
            }

            if ($this->merchant_id != $response['merchant_id']) {
                $order->update_status('failed');
                return __('An error has occurred during payment. Merchant data is incorrect.','woocommerce-oplata');
            }

            if ($response['order_status'] == self::ORDER_DECLINED) {
                $errorMessage = __("Thank you for shopping with us. However, the transaction has been declined.",'woocommerce-oplata');
                $order->add_order_note('Transaction ERROR: order declined<br/>Fondy ID: '.$_REQUEST['payment_id']);
                $order->update_status('failed');

                wp_mail($_REQUEST['sender_email'], 'Order declined', $errorMessage);

                return $errorMessage;
            }

            $responseSignature = $response['signature'];

            $strs = explode(self::SIGNATURE_SEPARATOR,$response['response_signature_string']);
            $str = (str_replace($strs[0],$this->salt,$response['response_signature_string']));
            //print_r (sha1($str)); echo "<br>"; print_r ($responseSignature);die;
            if  (sha1($str) != $responseSignature) {
                $order->update_status('failed');
                $order->add_order_note(__('Transaction ERROR: signature is not valid','woocommerce-oplata'));
                return __('An error has occurred during payment. Signature is not valid.','woocommerce-oplata');
            }

            if ($response['order_status'] != self::ORDER_APPROVED) {
                $this->msg['class'] = 'woocommerce-error';
                $this->msg['message'] = __("Thank you for shopping with us. But your payment declined.",'woocommerce-oplata');
                $order->update_status('processing');
                $order->add_order_note("Order status: {$response['order_status']}");
            }

            if ($response['order_status'] == self::ORDER_APPROVED) {
                $order->update_status('completed');
                $order->payment_complete();
                $order->add_order_note('fondy payment successful.<br/>fondy ID: ' . ' (' . $_REQUEST['payment_id'] . ')');
            }

            $woocommerce->cart->empty_cart();

            return true;
        }

        function check_oplata_response()
        {

            if (empty($_REQUEST))
            {
                $fap = json_decode(file_get_contents("php://input"));
                $_REQUEST=array();
                foreach($fap as $key=>$val)
                {
                    $_REQUEST[$key] =  $val ;
                }
            }
            //print_r($_REQUEST); die;
            $paymentInfo = $this->isPaymentValid($_REQUEST);
            if ($paymentInfo === true) {
                if ($_REQUEST['order_status'] == self::ORDER_APPROVED) {
                $this->msg['message'] = __("Thank you for shopping with us. Your account has been charged and your transaction is successful.",'woocommerce-oplata');}
                $this->msg['class'] = 'woocommerce-message';
            } else {
                $this->msg['class'] = 'error';
                $this->msg['message'] = $paymentInfo;
            }

            $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
            //For wooCoomerce 2.0
            $redirect_url = add_query_arg(array('msg' => urlencode($this->msg['message']),
                                                'type' => $this->msg['class']), $redirect_url);

            wp_redirect($redirect_url);
            exit;
        }

        /*
        //Removed For WooCommerce 2.0
        function showMessage($content){
            return '<div class="box '.$this->msg['class'].'">'.$this->msg['message'].'</div>'.$content;
        }
        */

        // get all pages
        function oplata_get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) {
                $page_list[] = $title;
            }
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_oplata_gateway($methods)
    {
        $methods[] = 'WC_oplata';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_oplata_gateway');
}
