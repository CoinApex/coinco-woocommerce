<?php
/*
 *  Plugin Name:       CoinCo Bitcoin payments for WooCommerce
 *  Plugin URI:        https://coin.co
 *  Description:       Enable your WooCommerce store to accept Bitcoin with CoinCo.
 *  Author:            Coin.co
 *  Author URI:        https://coin.co
 *  Version:           1.1.0
 *  License:           Copyright 2011-2014 coinco Inc., MIT License
 */

/*
 * Documentation:
 *
 * This is a WordPress plugin.
 * Look at http://codex.wordpress.org/Plugin_API for WordPress plugin documentation.
 *
 * Also look at
 * http://docs.woothemes.com/documentation/plugins/woocommerce/woocommerce-codex/extending/
 * for documentation on how to integrate with the WooCommerce plugin. For
 * practical examples of gateways that have been built already take a look at
 * the ones that come with the WooCommerce source code.
 */

/*
 * Some useful information:
 *
 * Actions:
 *
 * Actions are triggered by specific events that take place in WordPress, such
 * as publishing a post, changing themes, or displaying an administration
 * screen. An Action is a custom PHP function defined in your plugin (or theme)
 * and hooked, i.e. set to respond, to some of these events.
 * Actions are called for their side effects. Their return value should not
 * matter.
 *
 * Filters:
 *
 * Filters are functions that WordPress passes data through, at certain points
 * in execution, just before taking some action with the data (such as adding
 * it to the database or sending it to the browser screen). Filters sit between
 * the database and the browser (when WordPress is generating pages), and
 * between the browser and the database (when WordPress is adding new posts and
 * comments to the database); most input and output in WordPress passes through
 * at least one filter.
 * Unlike actions, filters should never have side effects. They just transform
 * the data. They should be pure functions, functionally talking.
 *
 * Options:
 *
 * Options are key-value pairs which are stored in the database in a table
 * called 'wp_options'
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Register a function to run when the plugin is activated.
// The first argument is the path to the main plugin file inside the
// wp-content/plugins directory. A full path will work.
register_activation_hook(__FILE__, 'woocommerce_coinco_activate');

function woocommerce_coinco_activate() {
    woocommerce_coinco_check_requirements();
    woocommerce_coinco_deactivate();
    update_option('woocommerce_coinco_version', '1.1.0');
    update_option('secret_key', hash('sha256', uniqid()));
}

function woocommerce_coinco_deactivate() {
    function is_coinco_plugin($file, $plugin) {
        if (strtolower($plugin['Name']) === 'coinco woocommerce' && is_plugin_active($file))
            return true;
        return false;
    }

    function deactivate() {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Please delete any old "WooCommerce Coinco" plugins<br><a href="'.admin_url('plugins.php').'">Return</a>');
    }

    foreach (get_plugins() as $file => $plugin)
        if (is_coinco_plugin($file, $plugin))
            deactivate();
}

function woocommerce_coinco_check_requirements() {
    global $wp_version;
    $errors = array();

    if (version_compare(PHP_VERSION, '5.4.0', '<'))
        $errors[] = 'Your PHP version is too old. The Coin.co payment plugin requires PHP 5.4 or higher to function.';

    if (version_compare($wp_version, '3.9', '<'))
        $errors[] = 'Your WordPress version is too old. The Coin.co payment plugin requires Wordpress 3.9 or higher to function.';

    if (version_compare(WOOCOMMERCE_VERSION, '2.2', '<'))
        $errors[] = 'Your WooCommerce version is too old. The Coin.co payment plugin requires WooCommerce 2.2 or higher to function.';

    if ($errors)
        wp_die(implode("<br>\n", $errors));
}

add_action('plugins_loaded', 'woocommerce_coinco_load', 0);

function woocommerce_coinco_load() {
    if (class_exists('WC_Gateway_Coinco'))
        return;

    woocommerce_coinco_check_requirements();
    woocommerce_coinco_init_gateway_class();

    function coinco_add_gateway($methods) {
        $methods[] = 'WC_Gateway_Coinco';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'coinco_add_gateway');
}

function woocommerce_coinco_init_gateway_class() {
    /*
    * This is the real deal, where everything happens.
    *
    * The class WC_Payment_Gateway extends WC_Settings_API
    *
    * Woocommerce has a settings page. Under the 'Checkout' tab, there should be a
    * section for your plugin, which is dynamically generated by WooCommerce. The
    * class WC_Settings_API has a bunch of helper functions to deal with this
    * settings page.
    *
    * TODO: Investigate how the hell callbacks work with this thing
    */
    class WC_Gateway_Coinco extends WC_Payment_Gateway {
        public function __construct() {
            $this->setup_settings_api_stuff();
            $this->setup_payment_gateway_stuff();
            $this->logger = new WC_Logger();
            add_filter('woocommerce_checkout_fields', array($this, 'add_refund_address_checkout_field'));
            // Any requests to "http://yourdomain/?wc-api=WC_Gateway_Coinco"
            // will trigger this action.
            add_action('woocommerce_api_wc_gateway_coinco', array($this, 'coinco_callback'));
        }

        public function coinco_callback() {
            // Possible order statuses in WooCommerce are
            // 
            //  pending      Order received but is unpaid
            //  on-hold      Awaiting payment - Stock is reduced but payment needs to be confirmed
            //  processing   Payment received and stock has been reduced. The order is waiting fullfillment
            //  completed    Order fullfilled and completed
            //  failed       Payment failed or was declined
            //  cancelled    Cancelled by the admin or customer
            //
            // Possible order statuses in Coin.co are
            //
            //  new	        Brand new invoice has been created. The customer has not yet visited the invoiceURL link. No BTC conversion rate is assigned.
            //  viewed	    Invoice has been viewed by the customer and a BTC exchange rate is locked in.
            //  paid	    Payment has been seen on the Bitcoin network.
            //  confirmed	The number of confirmations is sufficient. You can supply the customer with their product or service.
            //  completed	The bitcoin transaction has accumulated 6 bitcoin network confirmations.
            //  invalid	    Paid invoices which receive no bitcoin network confirmations are permanently moved to invalid after an hour.
            //  expired	    The invoice was not fully paid during the 15 minute window after it was viewed.
            global $wpdb;
            $json = json_decode(file_get_contents('php://input'), true);
            $callback_data = json_decode($json['callbackData'], true);

            $wpdb->query("insert into wp_logs (log) values ('array keys begin');");
            $wpdb->query("insert into wp_logs (log) values ('".$callback_data['secret_key']."');");
            $wpdb->query("insert into wp_logs (log) values ('".$this->get_option('secret_key')."');");
            $wpdb->query("insert into wp_logs (log) values ('array keys end');");

            if (!array_key_exists('secret_key', $callback_data) || $callback_data['secret_key'] != $this->get_option('secret_key')) {
                $msg = 'Missing or invalid "secret_key" field from CoinCo\'s callback';
                $this->log($msg);
                wp_die($msg);
            }

            if (!array_key_exists('id', $callback_data) || !wc_get_order($callback_data['id'])) {
                $msg = 'Missing or invalid "id" field from CoinCo\'s callback';
                $this->log($msg);
                wp_die($msg);
            }

            $order = wc_get_order($callback_data['id']);

            switch (strtolower($json['invoiceStatus'])):
                case 'viewed':
                case 'paid':
                    $order->update_status('on-hold', __('Awaiting Bitcoin payment', 'coinco'));
                    break;
                case 'confirmed':
                case 'completed':
                    $order->update_status('processing', __('Awaiting Bitcoin payment', 'coinco'));
                    break;
                case 'invalid':
                case 'expired':
                    $order->update_status('failed', __('Awaiting Bitcoin payment', 'coinco'));
                    break;
                default:
                    $this->log('Got unrecognized order status from Coin.Co');
            endswitch;
        }

        public function add_refund_address_checkout_field($fields) {
            $fields['billing']['billing_refund_address'] = array(
                'label'       => __('Refund Address (Recommended)', 'woocommerce'),
                'type'        => 'text',
                'required'    => false,
                'clear'       => false
            );
            return $fields;
        }

        public function setup_settings_api_stuff() {
            // Used to auto-generate HTML and stuff
            $this->id                 = 'coinco';
            $this->method_title       = 'Coin.co';
            $this->method_description = 'Coin.co allows you to accept bitcoin on your WooCommerce store.';
            $this->init_form_fields();

            // Load settings option from the database, parse it and populate
            // "$this->settings". That way you can always do $this->settings['name'].
            //
            // A better option, though, is to call
            //
            //      $this->get_option(varname, default)
            //
            // which will try the following in order:
            //
            //      1) Get variable from $this->settings (which will call
            //         $this->init_settings() if variable $this->settings not set).
            //      2) Get variable from $this->form_fields[variable]['default']
            //      3) Get default value provided as second argument
            //      4) Return empty
            $this->init_settings();

            // If $this->enabled is 'no', Bitcoin will not appear in the
            // checkout page as a payment option.
            $this->enabled = ($this->check_requirements())? 'yes':'no';

            // Set this to false if you want to disable logging.
            $this->debug = $this->get_option('debug', 'no') === 'yes';

            // The event 'woocommerce_update_options_payment_gateways_(plugin id here)'
            // gets triggered whenever the user changes settings and saves.
            //
            // The function 'process_admin_options' (default implementation in
            // 'WC_Settings_API') will:
            //      1) Validate incoming data from the settings page.
            //      2) Store settings in database.
            //
            // If you want to get POST data from when the settings are saved, you
            // can always attach another function to this event.
            $action_name = 'woocommerce_update_options_payment_gateways_' . $this->id;
            add_action($action_name, array($this, 'process_admin_options'));
        }

        public function setup_payment_gateway_stuff() {
            $this->icon               = plugin_dir_url(__FILE__).'assets/img/icon.png';
            $this->has_fields         = false;
            $this->order_button_text  = __('Proceed to Coinco', 'coinco');
            $this->title              = $this->get_option('title');
            $this->description        = $this->get_option('description');
        }

        public function check_requirements() {
            return (strtolower(get_woocommerce_currency()) === 'usd' && $this->get_option('api_key'));
        }

        /*
        * Overridden from 'WC_Settings_API'
        *
        * Define the form fields. They are used automatically by WooCommerce for
        * both form data validation and form rendering.
        *
        * If you want to render a custom field, you need to define a function
        * which will render it with a "magic" name in the form of
        * "generate_(field name here)_html". Ex:
        *
        *  public function init_form_fields() {
        *      $this->form_fields = array(
        *           'custom' => array(
        *               'type' => 'custom_field'
        *           )
        *      )
        *  }
        *
        *  public function generate_custom_field_html() {
        *      ob_start();
        *      // Your code here. Look at WordPress functions such as
        *      'wp_enqueue_style' and 'wp_enqueue_script'
        *      return ob_get_clean();
        *  }
        *
        */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Bitcoin with Coin.co', 'coinco'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Bitcoin', 'coinco'),
                ),
                'description' => array(
                    'title'       => __('Customer Message', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('Message to explain how the customer will be paying for the purchase.', 'coinco'),
                    'default'     => 'You will be redirected to Coin.co\'s website to complete your purchase.'
                ),
                'api_key' => array(
                    'title'       => __('API Key', 'coinco'),
                    'type'        => 'text',
                    'description' => __('Generate this key through your merchant account\'s page in Coin.Co\'s site.', 'coinco'),
                ),
                'secret_key' => array(
                    'title'       => __('Secret Key', 'coinco'),
                    'type'        => 'text',
                    'description' => __('Token to authenticate CoinCo\'s payment notifications. Any long, random string will work. Normally this field does not need to be changed.', 'coinco'),
                    'default'     => get_option('secret_key'),
                ),
                'testing' => array(
                    'title'       => __('Use testnet coins?', 'coinco'),
                    'type'        => 'checkbox',
                    'description' => __('For testing purposes only', 'coinco'),
                    'default'     => 'no',
                ),
                'debug' => array(
                    'title'       => __('Debug Log', 'woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable logging', 'woocommerce'),
                    'default'     => 'no',
                    'description' => sprintf(__('Log coinco events', 'coinco'), wc_get_log_file_path('coinco'))
                ),
            );
        }

        /*
        * Overridden from 'WC_Payment_Gateway'
        *
        * Order objects have these properties that we can use (shamelessly
        * stolen from WooCommerce's codebase):
        *
        *   int    $customer_user User ID who the order belongs to. 0 for guests.
        *   string $billing_first_name The billing address first name
        *   string $billing_last_name The billing address last name
        *   string $billing_company The billing address company
        *   string $billing_address_1 The first line of the billing address
        *   string $billing_address_2 The second line of the billing address
        *   string $billing_city The city of the billing address
        *   string $billing_state The state of the billing address
        *   string $billing_postcode The postcode of the billing address
        *   string $billing_country The country of the billing address
        *   string $billing_phone The billing phone number
        *   string $billing_email The billing email
        *   string $shipping_first_name The shipping address first name
        *   string $shipping_last_name The shipping address last name
        *   string $shipping_company The shipping address company
        *   string $shipping_address_1 The first line of the shipping address
        *   string $shipping_address_2 The second line of the shipping address
        *   string $shipping_city The city of the shipping address
        *   string $shipping_state The state of the shipping address
        *   string $shipping_postcode The postcode of the shipping address
        *   string $shipping_country The country of the shipping address
        *   string $cart_discount Total amount of discount
        *   string $cart_discount_tax Total amount of discount applied to taxes
        *   string $order_shipping Total amoount of shipping
        *   string $order_shipping_tax Total amoount of shipping tax
        *   string $shipping_method_title < 2.1 was used for shipping method title. Now @deprecated.
        *   string $order_key Random key/password unqique to each order.
        *   string $order_discount Stored after tax discounts pre-2.3. Now @deprecated.
        *   string $order_tax Stores order tax total.
        *   string $order_shipping_tax Stores shipping tax total.
        *   string $order_shipping Stores shipping total.
        *   string $order_total Stores order total.
        *   string $order_currency Stores currency code used for the order.
        *   string $payment_method method ID.
        *   string $payment_method_title Name of the payment method used.
        *   string $customer_ip_address Customer IP Address
        *   string $customer_user_agent Customer User agent
        */
        public function process_payment($order_id) {
            global $wpdb;
            global $woocommerce;
            $order = wc_get_order($order_id);

            if ($this->get_option('testing') == 'no')
                $url = 'https://coin.co/1/createInvoice';
            else
                $url = 'https://sandbox.coin.co/1/createInvoice';

            // Look at https://coin.co/developers/endpoints for information on
            // the request parameters
            $resp = $this->json_post($url, array(
                'APIAccessKey'                  => $this->get_option('api_key'),
                'currencyType'                  => $order->order_currency,
                'amountInSpecifiedCurrencyType' => $order->order_total,
                // Notification URL is like "http://yourdomain/?wc-api=WC_Gateway_Coinco
                'notificationURL'               => WC()->api_request_url('WC_Gateway_Coinco'),
                'setStatusViewed'               => 'True',
                'callbackData'                  => json_encode(array('id'=>$order->id, 'secret_key'=>$this->get_option('secret_key'))),
                'customerRedirectURL'           => $this->get_return_url($order),
                'refundAddress'                 => $_POST['billing_refund_address'],
                'buyerName'                     => base64_encode($order->billing_first_name . " " . $order->billing_last_name),
                'buyerAddress1'                 => base64_encode($order->billing_address_1),
                'buyerAddress2'                 => base64_encode($order->billing_address_2),
                'buyerCity'                     => base64_encode($order->billing_city),
                'buyerState'                    => base64_encode($order->billing_state),
                'buyerZip'                      => base64_encode($order->billing_zip),
                'buyerCountry'                  => base64_encode($order->billing_country),
                'buyerEmail'                    => base64_encode($order->billing_email),
                'buyerPhone'                    => base64_encode($order->billing_phone),
            ));

            if (is_wp_error($resp)) {
                $this->log($resp->get_error_message());
                wp_die('HTTP Request to Coin.Co failed');
            }

            $body = json_decode(wp_remote_retrieve_body($resp), true);

            if (wp_remote_retrieve_response_code($resp) !== 200) {
                $msg = 'Could not create invoice with Coin.Co. They say:</br>';
                $msg = $msg . $body['message'] . '</br>';
                $this->log($msg);
                wp_die($msg);
            }

            $order->update_status('on-hold', __('Awaiting Bitcoin payment', 'coinco'));
            $order->reduce_order_stock();
            $woocommerce->cart->empty_cart();
            return array(
                'result' => 'success',
                'redirect' => $body['invoiceURL']
            );
        }

        public function json_post($url, $params) {
            return wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json'),
                'sslverify' => true,
                'body' => json_encode($params),
                'timeout' => 10,
            ));
        }

        // When WooCommerce installs itself, it defines this constant:
        // 
        //  $this->define( 'WC_LOG_DIR', $upload_dir['basedir'] . '/wc-logs/' );
        //
        // Look there for logs (Probably 'wordpress/wp-content/uploads/wc-logs')
        public function log($message) {
            if ($this->debug) {
                if (gettype($message) === 'object' || gettype($message) === 'array')
                    $this->logger->add('coinco', var_export($message, true));
                else
                    $this->logger->add('coinco', $message);
            }
        }
    }
}
