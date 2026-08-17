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
		add_filter('woocommerce_settings_api_sanitized_fields_' . $this->id, array($this, 'drop_display_only_settings'));
	}

	/**
	 * The mode the gateway is currently transacting in.
	 *
	 * Named to match the `mode` Edge puts on its own records, so the two can be
	 * compared without translating between them.
	 *
	 * @return string  Either 'sandbox' or 'live'.
	 */
	public function get_mode()
	{
		return $this->testmode ? 'sandbox' : 'live';
	}

	/**
	 * The secret key for a mode.
	 *
	 * The mode is asked for rather than assumed because a webhook can arrive long
	 * after the settings were switched from sandbox to live. Reading that order's
	 * payment demand back with the wrong key would 404 and strand it.
	 *
	 * @param  string  $mode  'sandbox', 'live', or empty for the current mode.
	 * @return string
	 */
	public function get_secret_key($mode = '')
	{
		if ('' === $mode) {
			$mode = $this->get_mode();
		}

		return 'live' === $mode ? $this->get_option('private_key') : $this->get_option('test_private_key');
	}

	/**
	 * Keep fields that only exist to be read out of the saved settings.
	 *
	 * WooCommerce writes back every form field it is given, and the webhook URL
	 * is derived from the site address rather than entered, so a stored copy
	 * could only ever go stale.
	 *
	 * @param  array  $settings
	 * @return array
	 */
	public function drop_display_only_settings($settings)
	{
		unset($settings['webhook_url']);

		return $settings;
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
			),
			'webhook_url' => array(
				'title' => __('Webhook URL', 'edge-gateway'),
				'type' => 'edge_webhook_url',
				'description' => __('Create a webhook in your Edge dashboard pointing at this URL, subscribed to <code>transaction.payment_demands.succeeded</code> and <code>transaction.payment_demands.failed</code>. Orders are placed on hold at checkout and stay there until Edge reports the outcome here, so no order completes without it.', 'edge-gateway'),
			)
		);
	}

	/**
	 * Render the webhook URL, with a button to copy it.
	 *
	 * Read-only: the URL follows the site address, and is shown here only so it
	 * can be pasted into the Edge dashboard.
	 *
	 * @param  string  $key   Field key.
	 * @param  array   $data  Field definition.
	 * @return string
	 */
	public function generate_edge_webhook_url_html($key, $data)
	{
		$field_key = $this->get_field_key($key);
		$url = WC_Edge_Webhook_Handler::callback_url();

		$this->enqueue_copy_to_clipboard();

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
					<input
						type="text"
						id="<?php echo esc_attr($field_key); ?>"
						class="input-text regular-input code"
						value="<?php echo esc_attr($url); ?>"
						readonly="readonly"
						onfocus="this.select();"
					/>
					<button
						type="button"
						class="button wc-edge-copy-webhook-url"
						data-clipboard-target="#<?php echo esc_attr($field_key); ?>"
					><?php esc_html_e('Copy URL', 'edge-gateway'); ?></button>
					<span class="wc-edge-copy-webhook-url-feedback" aria-hidden="true"></span>
					<p class="description"><?php echo wp_kses_post($data['description']); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Wire up the copy button.
	 *
	 * clipboard.js ships with WordPress and is what core's own copy buttons use.
	 * `navigator.clipboard` would be less code but is unavailable over plain
	 * HTTP, which a fair number of admin screens still run on.
	 */
	private function enqueue_copy_to_clipboard()
	{
		wp_enqueue_script('clipboard');
		wp_enqueue_script('wp-a11y');

		wp_add_inline_script(
			'clipboard',
			'( function() {
				if ( typeof ClipboardJS === "undefined" ) {
					return;
				}

				new ClipboardJS( ".wc-edge-copy-webhook-url" ).on( "success", function( event ) {
					var feedback = event.trigger.parentNode.querySelector( ".wc-edge-copy-webhook-url-feedback" );

					event.clearSelection();

					if ( feedback ) {
						feedback.textContent = ' . wp_json_encode(__('Copied!', 'edge-gateway')) . ';
					}

					if ( window.wp && window.wp.a11y ) {
						window.wp.a11y.speak( ' . wp_json_encode(__('The webhook URL has been copied to your clipboard.', 'edge-gateway')) . ' );
					}
				} );
			} )();'
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

		\Edge\Auth::setApiKey($this->get_secret_key());

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

		// Bind the demand to the order and save it before doing anything else.
		// Edge can deliver the webhook for this demand before the shopper's
		// browser has finished being redirected, and a delivery that arrives with
		// no order to match is discarded as belonging to somewhere else.
		$order->set_transaction_id($edgePaymentDemandId);
		$order->update_meta_data(WC_Edge_Webhook_Handler::DEMAND_META, $edgePaymentDemandId);
		$order->update_meta_data(WC_Edge_Webhook_Handler::MODE_META, $this->get_mode());
		$order->save();

		// A demand is rarely settled by the time it is created, so the webhook is
		// what decides the outcome. The state already on the create response is
		// read anyway: it costs no extra request, and it spares the shopper a
		// thank-you page for a card that was turned down outright. The webhook
		// still has the last word in every case.
		$processor_state = isset($chargeCustomer->data->attributes->processor_state)
			? (string) $chargeCustomer->data->attributes->processor_state
			: '';

		if ('failed' === $processor_state) {
			$order->update_status('failed', __('Edge declined this payment.', 'edge-gateway'));

			throw new Exception(__('Your payment was declined. Please check your card details or try another card.', 'edge-gateway'));
		}

		if ('succeeded' === $processor_state) {
			$order->payment_complete($edgePaymentDemandId);
		} else {
			$order->update_status(
				'on-hold',
				__('Awaiting confirmation from Edge. The order will complete when Edge reports that the payment succeeded.', 'edge-gateway')
			);
		}

		// Remove cart
		if (WC()->cart) {
			WC()->cart->empty_cart();
		}

		// Return thankyou redirect
		return array(
			'result' => 'success',
			'redirect' => $this->get_return_url($order)
		);
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
