<?php

/*
Plugin Name:  ITEXPay  Payment Gateway
Plugin URI: https://wordpress.org/plugins/itex-woocommerce-payment-gateway/
Description: ITEXPay Gateway for WooCommerce
Version: 1.1
Author: ITEXPay
Author URI: https://itexpay.com
Requires at least: 3.0
Tested up to: 10.0.4
WC requires at least: 3.0
WC tested up to: 10.0.4
*/

if (!defined('ABSPATH')) {

    exit("Unauthorized access. Permission denied");
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
{
    exit("Woocommerce is not defined or active. Kindly active or install Woocommerce.");

}

add_action('plugins_loaded', 'itex_woocommerce_init', 0);

function itex_woocommerce_init() {

    if (!class_exists('WC_Payment_Gateway') )
    {   
        exit("Payment Gateway does not exist.");
        
    }

    

    class WC_ItexPay extends WC_Payment_Gateway {

        /**
     * Whether or not logging is enabled
     *
     * @var bool
     */
        public static $log_enabled = true;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    public static $log = false;


    public function __construct() {

        $this->id = 'itexpay';

        $this->method_title   = __( 'ItexPay', 'woocommerce' );

        $this->method_description = __( 'Pay with Visa/ MasterCard / Verve, QR Code , Bank Transfer, E-Naira via Checkout.', 'woocommerce' );

        $this->icon = apply_filters('itex_icon', plugins_url('assets/images/logo.png', __FILE__));

        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();


        $this->apikey = $this->settings['apikey'];
        $this->go_live = $this->settings['go_live'];


            //Checking environment..
        if ($this->settings['go_live'] == "yes") {
            $this->api_base_url = 'https://api.itexpay.com/api/pay';

        } else {
           $this->api_base_url = 'https://staging.itexpay.com/api/pay';
       }

       $this->msg['message'] = "";
       $this->msg['class'] = "";



       if (isset($_GET["itex-response-notice"]) || isset($_GET["itex-response-notice"]) != null ) {
        wc_add_notice(isset($_GET["itex-response-notice"]), "error");
    }

    if (isset($_GET["itex-error-notice"]) || isset($_GET["itex-error-notice"]) != null ) {
        wc_add_notice(isset($_GET["itex-error-notice"]), "error");
    }


    if (isset($_GET["order_id"]) || isset($_GET["order_id"]) != null && isset($_GET["transaction_id"]) || isset($_GET["transaction_id"]) != null) {

        //Check API Response...
        $this->check_response();

    }

            //check for at least Woocommerce 3.0...
    if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>=')) {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
    } else {
        add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
    }
}

        //Iniatialization of config form...
function init_form_fields() {
    $this->form_fields = array(

        
        'go_live' => array(
          'title'       => __( 'Go Live', 'itexpay' ),
          'label'       => __( 'Check to live environment', 'client' ),
          'type'        => 'checkbox',
          'description' => __( 'Ensure that you have all your credentials details set.', 'client' ),
          'default'     => 'no',
          'desc_tip'    => true
      ),

        'apikey' => array(
            'title' => __('API Key', 'itexpay'),
            'type' => 'text',
            'description' => __('API Key given during registration.', 'itexpay')
        ),

    );

 

}

public function admin_options() {
    echo '<h3>' . __('ITEXPay Gateway Configuration ', 'itexpay') . '</h3>';
    echo '<p>' . __('With a simple configuration, you can accept payments from cards to QR with ItexPay.') . '</p>';
    echo '<table class="form-table">';

    // Generate the HTML For the settings form.
    $this->generate_settings_html();
    echo '</table>';

}

function payment_fields() {
    if ($this->description)
        echo wpautop(wptexturize($this->description));
}


//Sending Request to API...
function send_request_to_api($order_id) {

    global $woocommerce;

    //Getting settings...
    $api_base_url = $this->api_base_url;       
    $apikey = $this->apikey; 

    //Order...
    $order = wc_get_order($order_id);
    $amount = $order->get_total();
    $currency = $order->get_currency();

    // Get the billing information
    //$billing_info = $order->get_billing_address();
    $customer_firstname = $order->get_billing_first_name();

    //Remove space between firstname...
    $customer_firstname = preg_replace('/\s+/', '', $customer_firstname);


    $customer_lastname = $order->get_billing_last_name();
    $customer_email = $order->get_billing_email();  

     //Getting customer phonenumber from billing info..
    $phonenumber = $order->get_billing_phone();

    //Remove first zero of number...
    $phonenumber = ltrim($phonenumber, '0');

    //Casting number into integer...
    $phonenumber = (int)$phonenumber;

    //Customer International number...
    $customer_phonenumber = $order->get_billing_postcode().$phonenumber; 

//Redirect url..
$redirect_url = wc_get_checkout_url().'?order_id='.$order_id.'&itexpay_response';


//Generating 12 unique random transaction id...
    $transaction_id='';
    $allowed_characters = array(1,2,3,4,5,6,7,8,9,0); 
    for($i = 1;$i <= 12; $i++){ 
        $transaction_id .= $allowed_characters[rand(0, count($allowed_characters) - 1)]; 
        WC()->session->set('wc_itex_transaction_id', $transaction_id);
    } 


//Hashing order details...
    $key_options = $transaction_id.$amount.$customer_email;
    $wc_itex_hash_key = hash('sha512', $key_options);
    WC()->session->set('wc_itex_hash_key', $wc_itex_hash_key);



//Payload to send to API...
$postdata = array(
    'body' => json_encode(array(
        'amount'  => $amount,
        'currency'  => $currency,
        'redirecturl' => $redirect_url,
        'customer' =>  array('email' => $customer_email, 'first_name' => $customer_firstname, 
                           'last_name' => $customer_lastname, 'phone_number' => $customer_phonenumber),
        'reference' => $transaction_id
    )),
    'timeout' => '60',
    'redirection' => '5',
    'httpversion' => '1.0',
    'blocking' => true,
    'headers' => array( 
        'Content-Type' => 'application/json',
        'cache-control' => 'no-cache',
        'Expect' => '',
        'Authorization' => 'Bearer '.$apikey.'' 
    ), 
    
);


//Making Request...
$response = wp_remote_post($api_base_url, $postdata);

//Checking for error..
if( is_wp_error( $response ) ) {

    $this->log( 'API Request Failed: '. $response->get_error_message(), 'error' );
    $error_message = "An error occured while processing request";

    wc_add_notice($error_message, 'error');
   // echo $error_message;
}

else
{
    //Getting response body...
    $reponse_body = wp_remote_retrieve_body( $response );
    $response_data = json_decode( $reponse_body, true );

}



//Getting Response...
if (!isset($response_data['amount'])) {
    $response_data['amount'] = null;
}

else
{
    $amount = $response_data['amount'];
}

if (!isset($response_data['currency'])) {
    $response_data['currency'] = null;
}

else
{
    $currency = $response_data['currency'];
}


if (!isset($response_data['paid'])) {
    $response_data['paid'] = null;
}

else
{
    $paid = $response_data['paid'];
}

if (!isset($response_data['status'])) {
    $response_data['status'] = null;
}

else
{
    $status = $response_data['status'];
}

if (!isset($response_data['env'])) {
    $response_data['env'] = null;
}

else
{
    $env = $response_data['env'];
}

if (!isset($response_data['reference'])) {
    $response_data['reference'] = null;
}

else
{
    $reference = $response_data['reference'];
}


if (!isset($response_data['paymentid'])) {
    $response_data['paymentid'] = null;
}

else
{
    $paymentid = $response_data['paymentid'];
}

if (!isset($response_data['authorization_url'])) {
    $response_data['authorization_url'] = null;
}

else
{
    $authorization_url = $response_data['authorization_url'];
}

if (!isset($response_data['failure_message'])) {
    $response_data['failure_message'] = null;
}

else
{
    $failure_message = $response_data['failure_message'];
}



if($status == "successful" && $paid == false)
{ 

      //Redirect to checkout page...
    return $authorization_url;


}

else
{

    wc_add_notice($failure_message, 'error');

    
}




  }//end of send_request_to_api()...



        //Processing payment...
  function process_payment($order_id) {
    WC()->session->set('wc_itex_order_id', $order_id);
    $order = wc_get_order($order_id);

    return array(
        'result' => 'success',
        'redirect' => $this->send_request_to_api($order_id)
    );
}


        //show message either error or success...
function showMessage($content) {
    return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
}


function get_pages($title = false, $indent = true) {
    $wp_pages = get_posts('sort_column=menu_order');
    $page_list = array();
    if ($title)
        $page_list[] = $title;
    foreach ($wp_pages as $page) {
        $prefix = '';
                // show indented child pages?
        if ($indent) {
            $has_parent = $page->post_parent;
            while ($has_parent) {
                $prefix .= ' - ';
                $next_page = get_post($has_parent);
                $has_parent = $next_page->post_parent;
            }
        }
                // add to page list array array
        $page_list[$page->ID] = $prefix . $page->post_title;
    }
    return $page_list;
}


        //Checking Transaction status...
function check_response() {

    global $woocommerce;

            //Checking for Order ID...
    if (!isset($_GET["order_id"])) {

        $_GET["order_id"] = null;

    }

    else{

        $order_id = $_GET["order_id"];
    }

                 //Checking for Response code...
    if (!isset($_GET["code"])) {

        $_GET["code"] = null;

    }

    else{

        $code = $_GET["code"];
    }


                 //Checking for Response status...
    if (!isset($_GET["status"])) {

        $_GET["status"] = null;

    }

    else{

        $status = $_GET["status"];
    }


                //Checking for Response Transaction ID...
    if (!isset($_GET["status"])) {

        $_GET["transaction_id"] = null;

    }

    else{

        $transaction_id = $_GET["transaction_id"];
    }


                 //Checking for Response Transaction Reason...
    if (!isset($_GET["reason"])) {

        $_GET["reason"] = null;

    }

    else{

        $reason = $_GET["reason"];
    }


            //Getting Order ID from Session...
    $wc_order_id = WC()->session->get('wc_itex_order_id');
    $order = new WC_Order($wc_order_id);

            //Getting Transaction ID from Session...
    $wc_transaction_id = WC()->session->get('wc_itex_transaction_id');
    $wc_itex_hash_key = WC()->session->get('wc_itex_hash_key');


    if(empty($wc_itex_hash_key) || $wc_itex_hash_key == null || $wc_itex_hash_key == "")
    {   
        $this->log( 'Checking Response: Invalid hash key or empty', 'error' );
        die("<h2 style=color:red>Ooups ! something went wrong </h2>");
    }


    if(empty($wc_order_id) || $wc_order_id == null || $wc_order_id == "")
    {


       $message = "Code 0001 : Data has been tampered . 
       Order ID is ".$wc_order_id."";

       $message_type = "error"; 

       $this->log( 'Order ID does not exist in session', 'error' );

       $order->add_order_note($message);

       $redirect_url = $order->get_cancel_order_url();

       wp_redirect($redirect_url);

       exit();

   }

   if(empty($wc_transaction_id) || $wc_transaction_id == null || $wc_transaction_id == "")
   {   


       $message = "Code 0002 : Data has been tampered . 
       Order ID is ".$wc_order_id."";

       $message_type = "error";

       $this->log( 'Transaction ID does not exist in session ', 'error' );

       $order->add_order_note($message);

       $redirect_url = $order->get_cancel_order_url();

       wp_redirect($redirect_url);

       exit();

   }


   // if the order is pending or in process...
   if($order->get_status() == 'pending' || $order->get_status() == 'processing'){


      try {

        //Status check base url...
if ($this->settings['go_live'] == "yes") {
            $status_check_base_url = 'https://api.itexpay.com/api/v1/transaction/status?merchantreference='.$wc_transaction_id;

        } else {
           $status_check_base_url = 'https://staging.itexpay.com/api/v1/transaction/status?merchantreference='.$wc_transaction_id;
       }

       

//Sending Request...
       $response = wp_remote_get( $status_check_base_url ,
         array( 'timeout' => 60, 'redirection' => '5', 
            'httpversion' => '1.0', 'blocking' => true,
            'headers' => array( 'Authorization' => 'Bearer '.$this->apikey.'') ));

//Checking no error..
       if( is_wp_error( $response ) ) {

        $this->log( 'API Request Failed: '. $response->get_error_message(), 'error' );
        $error_message = "An error occured while processing request";
       // echo $error_message;

        wc_add_notice($error_message, 'error');
    }

    else
    {

    //Getting response body...
        $reponse_body = wp_remote_retrieve_body( $response );
        $this->log( 'ItexPay API Response: ' . $reponse_body, 'info' );
        $response_data = json_decode( $reponse_body, true );

    }
  

 if (!isset($response_data['code'])) {
     $transaction_code = null;
 }

 else
 {
     $transaction_code = $response_data['code'];
 }

 if (!isset($response_data['message'])) {
     $transaction_message = null;
 }

 else
 {
     $transaction_message = $response_data['message'];
 }




if($transaction_message == "approved" || $transaction_message == "Approved" && $transaction_code == "00")
{

 $message = "Thank you for shopping with us. 
 Your transaction was successful, payment has been received. 
 You order is currently being processed. 
 Your Order ID is ".$wc_order_id."";

 $message_type = "success";

 $order->payment_complete($wc_transaction_id);
 $order->update_status('processing');
 $order->add_order_note('ItexPay Responses : <br /> 
     Code : '.$transaction_code.'<br/>
     Status : '.$transaction_message.'<br/>
     ');



$woocommerce->cart->empty_cart();
WC()->session->__unset('wc_itex_hash_key');
WC()->session->__unset('wc_itex_order_id');
WC()->session->__unset('wc_itex_transaction_id');

wp_redirect($this->get_return_url($order));
exit();


    } // end if transaction is successful...

    else
    {

        $message = "Thank you for shopping with us. However, 
        the transaction has been declined.";
        $message_type = "error";

        $order->payment_complete($wc_transaction_id);
        $order->update_status('failed');
       $order->add_order_note('ItexPay Responses : <br /> 
     Code : '.$transaction_code.'<br/>
     Status : '.$transaction_message.'<br/>
     ');


        $woocommerce->cart->empty_cart();
        WC()->session->__unset('wc_itex_hash_key');
        WC()->session->__unset('wc_itex_order_id');
        WC()->session->__unset('wc_itex_transaction_id');
        
        wp_redirect($this->get_return_url($order));
        exit();


    } // end of else if transaction is not successful....



    $notification_message = array(
        'message' => $message,
        'message_type' => $message_type
    );

    if (version_compare(WOOCOMMERCE_VERSION, "3.0") >= 0) {
        add_post_meta($wc_order_id, '_itex_hash', $wc_itex_hash_key, true);
    }
    update_post_meta($wc_order_id, '_itex_wc_message', $notification_message);


                    } // end of try...


                    catch (Exception $e) {

                        $this->log( 'Payment Exception '.$e->getMessage(), 'error' );

                        $order->add_order_note('Error: ' . $e->getMessage());
                        $redirect_url = $order->get_cancel_order_url();
                        wp_redirect($redirect_url);
                        exit();

                    } // end of catch..




             } // end of if $order->get_status() == pending...

             else
             {

               $this->log( 'Order does not exist or already proccessed ', 'error' );

               die("<h2 style=color:red>Order has been proccessed or expired. Try another one </h2>");

             } // end of else if $order->get_status() == pending...



        } // end of the check_response...
        


      /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level Optional. Default 'info'. Possible values:
     *                      emergency|alert|critical|error|warning|notice|info|debug.
     */
      public static function log( $message, $level = 'info' ) {
        if ( self::$log_enabled ) {
            if ( empty( self::$log ) ) {
                self::$log = wc_get_logger();
            }
            self::$log->log( $level, $message, array( 'source' => 'itexpay' ) );
        }
    }

    static function woocommerce_add_gateway($methods) {
        $methods[] = 'WC_ItexPay';
        return $methods;
    }

    static function woocommerce_add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=wc_itexpay">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

}

$plugin = plugin_basename(__FILE__);


function custom_payment_gateway_title($title, $payment_method) {

    if ($payment_method === 'itexpay') {

        $title = 'ITEXPay';
    }

    return $title;
}

add_filter('woocommerce_gateway_title', 'custom_payment_gateway_title', 10, 2);


add_filter("plugin_action_links_".$plugin."", array('WC_ItexPay', 'woocommerce_add_settings_link'));
add_filter('woocommerce_payment_gateways', array('WC_ItexPay', 'woocommerce_add_gateway'));

} 
