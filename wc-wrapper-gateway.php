<?php
/*
Plugin Name: Wrapper Checkout for WooCommerce and HPP
Plugin URI: https://wordpress.org/plugins/wrapper-checkout-for-cardpointe-and-hpp
Description: Use Wrapper enables a WooCommerce payment option to the CardPointe gateway by the HPP front end.
Version: 1.3.4
Author: Use Wrapper
Author URI: https://UseWrapper.com
License: A "Slug" license name e.g. GPL2
*/


add_filter( 'woocommerce_payment_gateways', 'wrap_wrapper_add_gateway_class' );
function wrap_wrapper_add_gateway_class( $gateways ) {
	$gateways[] = 'WRAP_WC_Wrapper_Gateway';
	return $gateways;
}

add_action( 'plugins_loaded', 'wrap_wrapper_init_gateway_class' );
function wrap_wrapper_init_gateway_class() {
	class WRAP_WC_Wrapper_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'wrapper';
			$this->icon               = "https://ibb.co/S0fN6DP";
			$this->has_fields         = true;
			$this->method_title       = 'Wrapper Gateway';
			$this->method_description = 'Connect your Cardpointe HPP Page with WooCommerce checkout features with this plugin. Configure your merchant specific settings below to continue!';
			//support for woocommerce products here
			$this->supports = array(
				'products'
			);

			// Method with all the option fields
			$this->init_form_fields();

			// Load all the settings
			$this->init_settings();
			if ( $this->get_option( 'ach' ) != 'no' ) {
				$this->title = 'Cardpointe HPP for ACH and CC';
			} else {
				$this->title = 'Cardpointe HPP for CC';
			}
			$this->description = $this->get_option( 'description' );
			$this->tkapi       = $this->get_option( 'token' );
			$this->tax_desc = $this->get_option('tax_descriptor');
			$this->shipping_desc = $this->get_option('shipping_descriptor');
			$this->enabled     = $this->get_option( 'enabled' );
			$this->redirect    = 'yes' === $this->get_option( 'redirect' );
			$this->testmode    = 'yes' === $this->get_option( 'testmode' );
			$this->hpp_url = $this->testmode ? $this->get_option( 'test_hpp_url' ) : $this->get_option( 'prod_hpp_url' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			add_action( 'woocommerce_api_wrapper_webhook', array( $this, 'webhook' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'token'        => array(
					'title'       => 'API token',
					'type'        => 'text',
					'description' => 'Please enter the API token',
					'default'     => '',
				),
				'enabled'      => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Wrapper Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'description'  => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout',
					'default'     => 'Youll navigate to an external link to complete the payment request.',
				),
				'ach'          => array(
					'title'       => 'ACH Support?',
					'label'       => 'Does your payment page support ACH as a payment method?',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'redirect'        => array(
					'title'       => 'Redirect Locally',
					'label'       => 'Build HPP URL locally on web server',
					'type'        => 'checkbox',
					'description' => '(Default enabled) Disable to implement server side validation and redirection',
					'default'     => 'yes'
				),
				'testmode'     => array(
					'title'       => 'Test mode',
					'label'       => 'Enable Test Mode',
					'type'        => 'checkbox',
					'description' => 'Place the payment gateway in test mode using test API keys.',
					'default'     => 'yes',
					'desc_tip'    => true
				),
				'tax_descriptor' => array(
					'title' => 'Tax Description',
					'type'  => 'text'
				),
				'shipping_descriptor' => array(
					'title' => 'Shipping Description',
					'type'  => 'text'
				),
				'test_hpp_url' => array(
					'title' => 'Test HPP page',
					'type'  => 'text'
				),
				'prod_hpp_url' => array(
					'title' => 'Live environment  HPP page',
					'type'  => 'text'
				)
			);
		}

		public function payment_fields() {
			if ( strlen( $this->hpp_url ) < 2 ) {
				$this->description .= 'HPP URL IS NOT VALID OR ENTERED. PLEASE DONT MAKE A PAYMENT!';
				$this->description = trim( $this->description );
			}
			echo wpautop( wp_kses_post( $this->description ) );
		}

		public function payment_scripts() {
		}

		public function validate_fields() {
		}

		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			wc_add_order_item_meta( $order_id, 'ipn_nonce', $order_id );
			echo($order);

			$types            = array( 'line_item', 'fee', 'shipping', 'coupon' );
			$order_tax_amount = $order->get_total_tax();
			$hppOrder         = "";
			// were going through each item here, what info do we grab?
			foreach ( $order->get_items( $types ) as $item_id => $item ) {
				// line_item | fee |shipping | coupon
				echo($item);
				$item_type = $item->get_type();
				$item_order = $item->get_order();
				$item_order_id = $item->get_order_id();
				$item_name = sanitize_text_field( $item_name );
				
				if ( $item->is_type( 'shipping' ) ) {
					if ($order->get_shipping_total() > 0) {
						$hppOrder = $this->calculateLink($hppOrder, $item->get_name(), $item->get_quantity(), $order->get_shipping_total());
					}
				}

				if ( $item->is_type( 'line_item' ) ) {
					$item_quantity     = $item->get_quantity();
					$item_subtotal     = $item->get_subtotal();
					$itemized_subtotal = $item_subtotal / $item_quantity;
					$item_name         = $item->get_name();
					$item_name_linted  = str_replace( "&", "%26", $item_name );
					$hppOrder = $this->calculateLink($hppOrder, $item_name_linted, absint($item_quantity), floatval($itemized_subtotal));
					}
				}
			
				if ( $order_tax_amount >= '0.01' ) {
					if (preg_match('/^[A-Za-z0-9 ]+$/', $this->tax_desc)) {
						$hppOrder = $this->calculateLink($hppOrder, $this->tax_desc, 1, $order->get_total_tax());
					}
					else {
						$hppOrder = $this->calculateLink($hppOrder, "Tax Amount", 1, $order->get_total_tax());
					}
				}
			// passed to the
			$data          = array(
				'woo_id'         => $order->get_id(),
				'hpp_url'        => $this->hpp_url,
				'total_amount'   => $order->get_total(),
				'order_details'  => $hppOrder,
				'shipping_total' => $order->get_shipping_total(),
				'tax_total'      => $order->get_total_tax()
			);
			//$hppOrderFinal = "https://" . $this->hpp_url . ".securepayments.cardpointe.com/pay?total=" . $order->get_total() . "&cf_hidden_woo_id=" . $order->get_id() . "&details=" . $hppOrder;
			$hppOrderFinal = sanitize_url($this->hpp_url . ".securepayments.cardpointe.com/pay?total=" . $order->get_total() . "&cf_hidden_woo_id=" . $order->get_id() . "&details=" . $hppOrder);
			$redirectString = $this->redirect;
			if ( $redirectString == "yes" ) {
				return array(
					'result'   => 'success',
					'redirect' => $hppOrderFinal
				);
			}
			else {
				$response      = wp_remote_post( "https://tranquil-oasis-93890.herokuapp.com/data",
					array(
						'method'    => 'POST',
						'headers'   => array(
							'Content-type: application/x-www-form-urlencoded',
							'X-Api-key: 123456789'
						),
						'timeout'   => 90,
						'sslverify' => false,
						'body'      => $data
					)
				);
				$forwardURL    = trim( wp_remote_retrieve_body( $response ) );
				return array(
					'result'   => 'success',
					'redirect' => $forwardURL
				);
			}
		}

		public function webhook() {
			header( 'HTTP/1.1 200 OK' );
			$order_id = isset( $_REQUEST['cf_woo_id'] ) ? $_REQUEST['cf_woo_id'] : null;
			if ( is_null( $order_id ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			$order->payment_complete();
			wc_reduce_stock_levels( $order_id );
		}

		//This function expects all the values to be linted before invoking it.
		function calculateLink( $url, $item_name, $quantity, $price ) {
			// invalid order or line item
			// TODO: Additional logging
			if (!$item_name || !$quantity || !$price) {
				wc_add_notice(__('There was a problem processing your order due to a product configuration error. Please try again later or contact support.', 'woocommerce'), 'error');
				return;
			}
			else {
				$newURL = "";
				if ( strlen( $url ) > 2 ) {
					$newURL = $url . "%3C%3E" . $item_name . "%7C" . $quantity . "%7C" . $price;
					echo("display a URL");
					echo($newURL);
				}
				if ( strlen( $url ) < 2 ) {
					$newURL = $item_name . "%7C" . $quantity . "%7C" . $price;
					echo("display a different URL");
					echo($newURL);
				}
				return $newURL;
			}
		}
	}
}
