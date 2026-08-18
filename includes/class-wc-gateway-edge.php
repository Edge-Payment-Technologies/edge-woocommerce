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
    add_action('wp_ajax_edge_create_payment_demand', array($this, 'create_payment_demand'));
    add_action('wp_ajax_nopriv_edge_create_payment_demand', array($this, 'create_payment_demand'));
    add_action('wp_ajax_edge_prepare_payment_demand', array($this, 'prepare_payment_demand'));
    add_action('wp_ajax_nopriv_edge_prepare_payment_demand', array($this, 'prepare_payment_demand'));
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
    if ($e instanceof \Edge\Exception) {
      $message = sanitize_text_field($e->getMessage());

      return $message
        ? $message
        : __('Edge Payments could not process the request.', 'edge-gateway');
    }

    $decoded = json_decode($e->getMessage(), true);
    return isset($decoded['errors'][0]['detail']) && is_string($decoded['errors'][0]['detail'])
      ? sanitize_text_field($decoded['errors'][0]['detail'])
      : __('Edge Payments could not process the request.', 'edge-gateway');
  }

  /**
   * Create the unconfirmed payment demand required by Edge JS.
   */
  public function create_payment_demand()
  {
    check_ajax_referer('edge_create_payment_demand', 'nonce');

    if (!$this->is_available() || !WC()->cart || WC()->cart->is_empty()) {
      wp_send_json_error(
        array('message' => __('Edge Payments is not available for this order.', 'edge-gateway')),
        400
      );
    }

    $cart_hash = WC()->cart->get_cart_hash();
    $stored_demand = WC()->session->get('edge_payment_demand');

    if (
      is_array($stored_demand) &&
      isset($stored_demand['cart_hash'], $stored_demand['payment_demand_id']) &&
      $stored_demand['cart_hash'] === $cart_hash
    ) {
      wp_send_json_success(array('payment_demand_id' => $stored_demand['payment_demand_id']));
    }

    \Edge\Auth::setApiKey($this->private_key);

    try {
      $demand = \Edge\Client::create(
        'v2/payment_demands',
        array(
          'data' => array(
            'type' => 'payment_demands',
            'attributes' => array(
              'amount_cents' => (int) round((float) WC()->cart->get_total('edit') * 100),
              'amount_currency' => get_woocommerce_currency(),
              'idempotency_key' => wp_generate_uuid4(),
              'description' => __('WooCommerce checkout', 'edge-gateway'),
              // payment indicator
            ),
          ),
        )
      );
    } catch (Exception $e) {
      wc_get_logger()->error(
        'Unable to create an Edge payment demand: ' . $this->getEdgeErrorMessage($e),
        array('source' => 'edge-woocommerce')
      );

      wp_send_json_error(
        array('message' => __('Unable to create the payment demand: ' . $this->getEdgeErrorMessage($e), 'edge-gateway')),
        502
      );
    }

    $payment_demand_id = isset($demand->data->id) ? sanitize_text_field($demand->data->id) : '';

    if (!$payment_demand_id) {
      wp_send_json_error(
        array('message' => __('No payment demand id found, this shouldn\'t happen.', 'edge-gateway')),
        502
      );
    }

    WC()->session->set(
      'edge_payment_demand',
      array(
        'cart_hash' => $cart_hash,
        'payment_demand_id' => $payment_demand_id,
      )
    );

    wp_send_json_success(array('payment_demand_id' => $payment_demand_id));
  }

  /**
   * Attach checkout customer and address relationships before Edge verifies the card.
   */
  public function prepare_payment_demand()
  {
    check_ajax_referer('edge_create_payment_demand', 'nonce');

    $payment_demand_id = isset($_POST['payment_demand_id'])
      ? sanitize_text_field(wp_unslash($_POST['payment_demand_id']))
      : '';
    $stored_demand = WC()->session->get('edge_payment_demand');

    if (
      !$payment_demand_id ||
      !wp_is_uuid($payment_demand_id) ||
      !is_array($stored_demand) ||
      !isset($stored_demand['payment_demand_id']) ||
      !hash_equals((string) $stored_demand['payment_demand_id'], $payment_demand_id)
    ) {
      wp_send_json_error(array('message' => __('Invalid Edge payment reference.', 'edge-gateway')), 400);
    }

    $billing = isset($_POST['billing_address'])
      ? json_decode(wp_unslash($_POST['billing_address']), true)
      : array();
    $shipping = isset($_POST['shipping_address'])
      ? json_decode(wp_unslash($_POST['shipping_address']), true)
      : array();

    if (!is_array($billing) || empty($billing['email'])) {
      wp_send_json_error(array('message' => __('Please enter a valid billing address.', 'edge-gateway')), 400);
    }

    $billing = wc_clean($billing);
    $shipping = is_array($shipping) ? wc_clean($shipping) : array();
    $billing['email'] = sanitize_email($billing['email']);
    $checkout_hash = hash('sha256', wp_json_encode(array($billing, $shipping)));

    if (
      isset($stored_demand['checkout_hash']) &&
      $stored_demand['checkout_hash'] === $checkout_hash &&
      !empty($stored_demand['relationships'])
    ) {
      wp_send_json_success();
    }

    \Edge\Auth::setApiKey($this->private_key);

    try {
      $relationships = $this->create_checkout_relationships($billing, $shipping);

      \Edge\Client::update(
        'v2/payment_demands/' . rawurlencode($payment_demand_id),
        array(
          'data' => array(
            'id' => $payment_demand_id,
            'type' => 'payment_demands',
            'relationships' => $relationships,
          ),
        )
      );
    } catch (Exception $e) {
      wc_get_logger()->error(
        'Unable to prepare an Edge payment demand: ' . $this->getEdgeErrorMessage($e),
        array('source' => 'edge-woocommerce')
      );

      wp_send_json_error(
        array('message' => __('Unable to prepare Edge Payments. Please try again.', 'edge-gateway')),
        502
      );
    }

    $stored_demand['relationships'] = $relationships;
    $stored_demand['checkout_hash'] = $checkout_hash;
    WC()->session->set('edge_payment_demand', $stored_demand);

    wp_send_json_success();
  }

  /**
   * Create the Edge customer and addresses represented by Checkout Block data.
   *
   * @param array $billing Billing address data.
   * @param array $shipping Shipping address data.
   * @return array
   */
  private function create_checkout_relationships($billing, $shipping)
  {
    $customers = \Edge\Client::get('v2/customers', array('filter' => array('email' => $billing['email'])));

    if (!empty($customers->data[0]->id)) {
      $customer_id = $customers->data[0]->id;
    } else {
      $customer = \Edge\Client::create(
        'v2/customers',
        array(
          'data' => array(
            'type' => 'customers',
            'attributes' => array(
              'name' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
              'email' => $billing['email'],
            ),
          ),
        )
      );
      $customer_id = $customer->data->id;
    }

    $billing_address = $this->create_edge_address($billing);
    $shipping_address = $this->create_edge_address(array_merge($billing, array_filter($shipping)));

    return array(
      'payer' => array(
        'data' => array('id' => (string) $customer_id, 'type' => 'customers'),
      ),
      'billing_address' => array(
        'data' => array('id' => (string) $billing_address->data->id, 'type' => 'consumer_addresses'),
      ),
      'shipping_address' => array(
        'data' => array('id' => (string) $shipping_address->data->id, 'type' => 'consumer_addresses'),
      ),
    );
  }

  /**
   * Create an Edge address from a WooCommerce address array.
   *
   * @param array $address WooCommerce address data.
   * @return object
   */
  private function create_edge_address($address)
  {
    $country = isset($address['country']) ? $address['country'] : '';

    return \Edge\Client::create(
      'v2/consumer_addresses',
      array(
        'data' => array(
          'type' => 'consumer_addresses',
          'attributes' => array(
            'line_1' => $address['address_1'] ?? '',
            'line_2' => $address['address_2'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'zip' => $address['postcode'] ?? '',
            'country' => \Edge\Helpers::convertAlpha2ToAlpha3($country),
          ),
        ),
      )
    );
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
    $payment_demand_id = isset($_POST['payment_demand_id'])
      ? sanitize_text_field(wp_unslash($_POST['payment_demand_id']))
      : '';
    $stored_demand = WC()->session->get('edge_payment_demand');

    if (
      !$payment_demand_id ||
      !wp_is_uuid($payment_demand_id) ||
      !is_array($stored_demand) ||
      !isset($stored_demand['payment_demand_id']) ||
      empty($stored_demand['relationships']) ||
      !hash_equals((string) $stored_demand['payment_demand_id'], $payment_demand_id)
    ) {
      throw new Exception(__('Invalid Edge payment reference.', 'edge-gateway'));
    }

    \Edge\Auth::setApiKey($this->private_key);

    try {
      Edge\Client::update(
        'v2/payment_demands/' . rawurlencode($payment_demand_id),
        array(
          'data' => array(
            'id' => $payment_demand_id,
            'type' => 'payment_demands',
            'attributes' => array(
              'amount_cents' => (int) round((float) $order->get_total() * 100),
              'amount_currency' => $order->get_currency(),
              'description' => 'WooCommerce Order #' . $order_id,
              'purchase_reference' => (string) $order_id,
              'purchase_kind' => 'order',
            ),
          ),
        )
      );
      Edge\Client::confirm('v2/payment_demands', $payment_demand_id);
    } catch (Exception $e) {
      $message = $this->getEdgeErrorMessage($e);
      $context = array(
        'source' => 'edge-woocommerce',
        'order_id' => $order_id,
        'payment_demand_id' => $payment_demand_id,
      );

      if ($e instanceof \Edge\Exception) {
        $context['status_code'] = $e->getStatusCode();
      }

      wc_get_logger()->error(
        'Unable to confirm an Edge payment demand: ' . $message,
        $context
      );

      throw new Exception($message);
    }

    $payment_result = "pending";

    $max_poll_attempts = 16;

    for ($i = 0; $i < $max_poll_attempts; $i++) {
      $response = Edge\Client::get('v2/payment_demands/' . rawurlencode($payment_demand_id));
      if (in_array($response->data->attributes->processor_state, ['succeeded', 'failed'])) {
        $payment_result = $response->data->attributes->processor_state;
        break;
      }

      if ($i < $max_poll_attempts - 1) {
        sleep(2);
      }
    }

    $order->set_transaction_id($payment_demand_id);

    if ('succeeded' === $payment_result) {
      $order->payment_complete();
      WC()->cart->empty_cart();
      WC()->session->__unset('edge_payment_demand');

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
