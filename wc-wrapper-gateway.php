<?php
/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'wrapper_add_gateway_class' );
function wrapper_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Wrapper_Gateway'; // your class name is here
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'wrapper_init_gateway_class' );
function wrapper_init_gateway_class() {

	class WC_Wrapper_Gateway extends WC_Payment_Gateway {

		/**
		 * Class constructor, more about it in Step 3
		 */
		public function __construct() {
			$this->id = 'wrapper';
			$this->icon ='';
			$this->has_fields = true;
			$this->method_title = 'Wrapper Gateway';
			$this->method_description= 'Connect your Cardpointe HPP Page with WooCommerce checkout features with this plugin. Configure your merchant specific settings below to continue!';

			//support for woocommerce products here
			$this->supports = array(
				'products'
			);

			// Method with all the option fields
			$this->init_form_fields();

			// Load all the settings
			$this->init_settings();
			if($this->get_option('ach') != 'no') {
				$this->title = 'Cardpointe HPP for ACH and CC';
			} else {
				$this->title = 'Cardpointe HPP for CC';
			}
			$this->description = $this->get_option(  'description' );
			$this->enabled = $this->get_option(  'enabled' );
			$this->testmode = 'yes' === $this->get_option(  'testmode' );
			$this->hpp_url = $this->testmode ? $this->get_option( 'test_hpp_url' ) : $this->get_option( 'prod_hpp_url' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action( 'woocommerce_api_receivedOrder', array( $this, 'webhook'));
		}

		/**
		 * Plugin options, we deal with it in Step 3 too
		 */
		public function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title' => 'Enable/Disable',
					'label' => 'Enable Wrapper Gateway',
					'type' => 'checkbox',
					'description' => '',
					'default' => 'no'
				),
				'description' => array(
					'title' => 'Description',
					'type' => 'textarea',
					'description' => 'This controls the description which the user sees during checkout',
					'default' => 'Youll navigate to an external link to complete the payment request.',
				),
				'ach' => array(
					'title' => 'ACH Support?',
					'label' => 'Does your payment page support ACH as a payment method?',
					'type' => 'checkbox',
					'description' => '',
					'default' => 'no'
				),
				'testmode' => array(
					'title' => 'Test mode',
					'label' => 'Enable Test Mode',
					'type' => 'checkbox',
					'description' => 'Place the payment gateway in test mode using test API keys.',
					'default' => 'yes',
					'desc_tip' => true
				),
				'test_hpp_url' => array(
					'title' => 'Test HPP page',
					'type' => 'text'
				),
				'prod_hpp_url' => array(
					'title' => 'Live environment  HPP page',
					'type' => 'text'
				)
			);
		}

		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
				if( strlen($this->hpp_url) < 2) {
					$this->description .='HPP URL IS NOT VALID OR ENTERED. PLEASE DONT MAKE A PAYMENT!';
					$this->description = trim($this->description);
				}
				echo wpautop(wp_kses_post( $this->description ));
		}

		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
		public function payment_scripts() {
		}

		/*
 		 * Fields validation, more in Step 5
		 */
		public function validate_fields() {
		}

		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );

			$types = array( 'line_item', 'fee', 'shipping', 'coupon' );
			$hppOrder = "";

			foreach( $order->get_items( $types ) as $item_id => $item ) {

				// line_item | fee |shipping | coupon
				$item_type = $item->get_type();

				// WC_order object
				$item_order = $item->get_order();

				//order ID
				$item_order_id = $item-> get_order_id();

				//order item name (product title, name of shipping method)
				$item_name = $item->get_name();

				//product only
				if ($item-> is_type( 'line_item' )) {
					//product quantity
					$item_quantity = $item->get_quantity();

					// product subtotal without discounts
					$item_subtotal = $item->get_subtotal();
					$product = $item->get_product();

				}
				echo $product;
				if(strlen($hppOrder) > 2) {
					$hppOrder = $hppOrder."%3C%3E".$item->get_name()."%7C".$item->get_quantity()."%7C".$product->get_price();
				}
				if(strlen($hppOrder) < 2) {
					$hppOrder = $item->get_name()."%7C".$item->get_quantity()."%7C".$product->get_price();
				}
			}
			echo $this->hpp_url;
			$hppOrderFinal =  "https://".$this->hpp_url.".securepayments.cardpointe.com/pay?total=".$order->get_total()."&cf_woo_id=".$order->get_id()."&details=".$hppOrder;
			$order->update_status('on-hold',__('Awaiting for Completed Payment', 'woocommerce'));

			$woocommerce->cart->empty_cart();
			return array(
				'result' => 'success',
				'redirect' => $hppOrderFinal
			);
		}

		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {
			$order = wc_get_order( $_GET['cf_woo_id'] );
			$order->payment_complete();

			update_option('webhook_debug', $_GET);
		}
	}
}

/*
Plugin Name: WooCommerce Wrapper Payment Gateway
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: A Wrapper of the Cardpointe HPP along with WooCommerce for ultimate compatability
Version: 1.0
Author: Mitchell LaBenski
Author URI: http://URI_Of_The_Plugin_Author
License: A "Slug" license name e.g. GPL2
*/
