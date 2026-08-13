<?php

require_once (plugin_dir_path(plugin_dir_path(__FILE__)) . 'vendor/autoload.php');


/**
 * WC_Gateway_Edge class
 * @package  WooCommerce Edge Payments Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Edge Gateway.
 *
 * @class    WC_Gateway_Edge
 * @version  1.0.7
 */
class WC_Gateway_Edge extends WC_Payment_Gateway
{

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{

		$this->id = 'edge';
		$this->icon = apply_filters('woocommerce_edge_gateway_icon', '');
		$this->has_fields = true;
		$this->supports = array(
			'products',
			/*'payment_subscriptions',
												'subscription_cancellation',
												'subscription_suspension',
												'subscription_reactivation',
												'subscription_amount_changes',
												'subscription_date_changes',
												'multiple_subscriptions'*/
		);

		$this->method_title = _x('Edge Payments', 'Edge payments method', 'edge-gateway');
		$this->method_description = __('Allows edge payments.', 'edge-gateway');

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = $this->get_option('enabled');
		$this->testmode = 'yes' === $this->get_option('testmode');
		$this->private_key = $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
		$this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');


		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_scheduled_subscription_payment_edge', array($this, 'process_subscription_payment'), 10, 2);
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{

		$this->form_fields = array(
			'enabled' => array(
				'title' => 'Enable/Disable',
				'label' => 'Enable Edge Gateway',
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no'
			),
			'title' => array(
				'title' => 'Title',
				'type' => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default' => 'Credit Card (Edge)',
				'desc_tip' => true,
			),
			'description' => array(
				'title' => 'Description',
				'type' => 'textarea',
				'description' => 'This controls the description which the user sees during checkout.',
				'default' => 'Pay with your credit card.',
			),
			'testmode' => array(
				'title' => 'Test mode',
				'label' => 'Enable Test Mode',
				'type' => 'checkbox',
				'description' => 'Place the payment gateway in test mode using test API keys.',
				'default' => 'yes',
				'desc_tip' => true,
			),
			'test_publishable_key' => array(
				'title' => 'Sandbox Publishable Key',
				'type' => 'text'
			),
			'test_private_key' => array(
				'title' => 'Sandbox Private Key',
				'type' => 'password',
			),
			'publishable_key' => array(
				'title' => 'Live Publishable Key',
				'type' => 'text'
			),
			'private_key' => array(
				'title' => 'Live Private Key',
				'type' => 'password'
			)
		);
	}


	public function getEdgeErrorMessage(Exception $e)
	{
		$decoded = json_decode($e->getMessage(), true);
		return $decoded['errors'][0]['detail'];
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int  $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{

		$order = wc_get_order($order_id);

		\Edge\Auth::setApiKey($this->get_option('test_private_key'));

		try {
			//Fetch all customers with this email
			$getCustomer = \Edge\Client::get('customers', [
				'filter' =>
					['email' => $order->get_billing_email()]
			]);
		} catch (Exception $e) {
			throw new Exception(self::getEdgeErrorMessage($e));
		}


		//Do they exist?
		if (!empty($getCustomer->data[0]->id)) {
			$edgeCustomerId = $getCustomer->data[0]->id;

			//No? Create a new customer
		} else {

			$createEdgeCustomer = [
				'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email' => $order->get_billing_email()
			];


			try {
				$createEdgeCustomer = \Edge\Client::create('customers', ['data' => ['attributes' => $createEdgeCustomer]]);
			} catch (Exception $e) {
				throw new Exception(self::getEdgeErrorMessage($e));
			}


			//Set customer ID
			$edgeCustomerId = $createEdgeCustomer->data->id;
		}

		//Next add the billing address
		$edgeBillingAddress = [
			'line_1' => $order->get_billing_address_1(),
			'line_2' => $order->get_billing_address_2(),
			'city' => $order->get_billing_city(),
			'state' => $order->get_billing_state(),
			'zip' => $order->get_billing_postcode(),
			'country' => \Edge\Helpers::convertAlpha2ToAlpha3($order->get_shipping_country()),
		];

		try {
			$createAddress = Edge\Client::create('consumer_addresses', ['data' => ['attributes' => $edgeBillingAddress]]);
		} catch (Exception $e) {
			throw new Exception(self::getEdgeErrorMessage($e));
		}

		//Next add the shipping address
		$edgeShippingAddress = [
			'line_1' => $order->get_shipping_address_1(),
			'line_2' => $order->get_shipping_address_2(),
			'city' => $order->get_shipping_city(),
			'state' => $order->get_shipping_state(),
			'zip' => $order->get_shipping_postcode(),
			'country' => \Edge\Helpers::convertAlpha2ToAlpha3($order->get_shipping_country()),
		];

		try {
			$createShippingAddress = Edge\Client::create('consumer_addresses', ['data' => ['attributes' => $edgeShippingAddress]]);
		} catch (Exception $e) {
			throw new Exception(self::getEdgeErrorMessage($e));
		}

		$edgeAddressId = $createAddress->data->id;
		$edgeShippingAddressId = $createShippingAddress->data->id;

		$expiry_year = (int) 20 . $_POST['year'];

		$edgePaymentMethod = [
			'card_pan_token' => (string) $_POST['number'],
			'card_cvv_token' => (string) $_POST['cvc'],
			'expiry_year' => (int) $expiry_year,
			'expiry_month' => (int) $_POST['month'],
		];

		$relationships = [
			'customer' => [
				'data' => [
					'id' => (string) $edgeCustomerId,
					'type' => 'string'
				]
			],
			'address' => [
				'data' => [
					'id' => (string) $edgeAddressId,
					'type' => 'string'
				]
			]
		];


		//Link payment card
		$linkPaymentMethod = Edge\Client::create(
			'payment_methods',
			[
				'data' =>
					[
						'attributes' => $edgePaymentMethod,
						'relationships' => $relationships
					]
			]
		);

		$paymentMethodId = $linkPaymentMethod->data->id;

		//PaymentSubscriptions logic here
		$edgeCreatePaymentDemand = [
			'amount_cents' => (float) $order->get_total() * 100,
			'captured' => true,
			'currency' => $order->get_currency(),
			'description' => 'WooCommerce Order #' . $order_id,
			'idempotency_key' => "",
			'purchase_identifier' => $order_id
		];

		$relationships = [
			'customer' => [
				'data' => [
					'id' => (string) $edgeCustomerId,
					'type' => 'string'
				]
			],
			'payment_method' => [
				'data' => [
					'id' => (string) $paymentMethodId,
					'type' => 'string'
				]
			],
			'shipping_address' => [
				'data' => [
					'id' => (string) $edgeShippingAddressId,
					'type' => 'string'
				]
			]
		];
		try {
			//Link payment card
			$chargeCustomer = Edge\Client::create(
				'payment_demands',
				[
					'data' =>
						[
							'attributes' => $edgeCreatePaymentDemand,
							'relationships' => $relationships
						]
				]
			);
		} catch (Exception $e) {
			throw new Exception(self::getEdgeErrorMessage($e));
		}

		$edgePaymentDemandId = $chargeCustomer->data->id;

		//Call get PaymentDemand endpoint to check status, do it 3 more times if its still pending
		$payment_result = "pending";

		for ($i = 0; $i < 5; $i++) {
			$response = Edge\Client::get('payment_demands/' . $edgePaymentDemandId);
			if (in_array($response->data->attributes->processor_state, ['succeeded', 'failed'])) {
				$payment_result = $response->data->attributes->processor_state;
				break;
			}
			sleep(2);
		}

		$order->set_transaction_id($edgePaymentDemandId);

		if ('succeeded' === $payment_result) {
			$order = wc_get_order($order_id);

			$order->payment_complete();

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order)
			);
		} else {
			$message = __('Order payment failed. To make a successful payment using Edge Payments, please review the gateway settings.', 'edge-gateway');
			throw new Exception($message);
		}
	}

	/**
	 * Process subscription payment.
	 *
	 * @param  float     $amount
	 * @param  WC_Order  $order
	 * @return void
	 */
	public function process_subscription_payment($amount, $order)
	{
		$payment_result = $this->get_option('result');

		if ('success' === $payment_result) {
			$order->payment_complete();
		} else {
			$message = __('Order payment failed. To make a successful payment using Edge Payments, please review the gateway settings.', 'edge-gateway');
			throw new Exception($message);
		}
	}
}
