<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once (plugin_dir_path(plugin_dir_path(__FILE__)) . 'vendor/autoload.php');

/**
 * EDGEWC_Gateway_Edge class
 * @package  Edge Gateway for WooCommerce
 * @since    1.0.0
 */


/**
 * Edge Gateway.
 *
 * @class    EDGEWC_Gateway_Edge
 * @version  1.0.25
 */
class EDGEWC_Gateway_Edge extends WC_Payment_Gateway
{
  /** Order meta keys that together describe one in-flight Edge refund attempt. */
  const REFUND_ATTEMPT_META = array(
    '_edge_refund_idempotency_key',
    '_edge_refund_amount_cents',
    '_edge_refund_currency',
    '_edge_refund_reason_note',
    '_edge_refund_demand_id',
    '_edge_refund_state',
    '_edge_refund_completion_noted',
    '_edge_refund_wc_id',
  );

  /** Seconds after which a refund lock left behind by a killed worker may be reclaimed. */
  const REFUND_LOCK_TTL = 600;

  /** How many times process_payment() tries for the demand lock before giving up. */
  const LOCK_ATTEMPTS = 3;

  /** Pause between those attempts, in microseconds. */
  const LOCK_RETRY_DELAY_US = 300000;

  /** Lease length for the demand lock held across confirm, in seconds. */
  const CONFIRM_LOCK_TTL = 90;

  /** How long an unsettled order blocks a second payment for the same cart, in seconds. */
  const IN_FLIGHT_WINDOW = 3600;

  /** Refund demand states Edge may report. */
  const REFUND_STATES = array('pending', 'processing', 'succeeded', 'failed', 'errored');

  /** @var array Refund objects currently being created by WooCommerce. */
  private static $refund_context = array();

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
      'refunds',
      // 'subscriptions',
      // 'subscription_cancellation',
      // 'subscription_reactivation',
      // 'subscription_suspension',
      // 'subscription_amount_changes',
      // 'subscription_payment_method_change',
      // 'subscription_date_changes',
      // 'pre-orders'
    );

    $this->method_title = _x('Edge Payments', 'Edge payments method', 'edge-gateway-for-woocommerce');
    $this->method_description = __('Allows edge payments.', 'edge-gateway-for-woocommerce');

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
      ),
      'webhook_url' => array(
        'title' => __('Webhooks', 'edge-gateway-for-woocommerce'),
        // A 'title' field renders read-only and stores nothing, which is what the
        // URL wants: it follows the site address and is shown only to be copied.
        'type' => 'title',
        'description' => sprintf(
          /* translators: %s: the URL Edge should deliver webhooks to. */
          __('Orders are placed on hold at checkout and stay there until Edge reports the outcome. In your Edge dashboard, create a webhook subscription pointing at <code>%s</code>, subscribed to <code>transaction.payment_demands.succeeded</code> and <code>transaction.payment_demands.failed</code>, then paste its secret key below.', 'edge-gateway-for-woocommerce'),
          esc_url(EDGEWC_Webhook_Controller::callback_url())
        ),
      ),
      'webhook_secret' => array(
        'title' => __('Webhook Secret', 'edge-gateway-for-woocommerce'),
        'type' => 'password',
        'description' => __('The secret key Edge returned when the webhook subscription was created. Without it, deliveries are rejected and orders stay on hold.', 'edge-gateway-for-woocommerce'),
      )
    );
  }


  public function getEdgeErrorMessage(Exception $e)
  {
    $decoded = json_decode($e->getMessage(), true);

    if (isset($decoded['errors'][0]) && is_array($decoded['errors'][0])) {
      $error = $decoded['errors'][0];

      if (isset($error['detail']) && is_string($error['detail'])) {
        return sanitize_text_field($error['detail']);
      }

      if (isset($error['title']) && is_string($error['title'])) {
        return sanitize_text_field($error['title']);
      }
    }

    $message = sanitize_text_field($e->getMessage());

    return $message
      ? $message
      : __('Edge Payments could not process the request.', 'edge-gateway-for-woocommerce');
  }

  /**
   * Build safe diagnostic context for an Edge API failure.
   *
   * @param Exception $e       The exception being logged.
   * @param array     $context Additional log context.
   * @return array
   */
  public function getEdgeLogContext(Throwable $e, $context = array())
  {
    $context = array_merge(array('source' => 'edge-woocommerce'), $context);

    if (!($e instanceof \Edge\Exception)) {
      return $context;
    }

    $status_code = method_exists($e, 'getStatusCode')
      ? $e->getStatusCode()
      : $e->getCode();
    if ($status_code) {
      $context['status_code'] = $status_code;
    }

    $request = method_exists($e, 'getRequest') ? $e->getRequest() : null;

    if ($request === null) {
      $previous = $e->getPrevious();
      $request = $previous && method_exists($previous, 'getRequest')
        ? $previous->getRequest()
        : null;
    }

    if ($request !== null) {
      $context['request_method'] = sanitize_text_field($request->getMethod());
      $context['request_path'] = sanitize_text_field($request->getUri()->getPath());
    }

    return $context;
  }

  /**
   * Create the unconfirmed payment demand required by Edge JS.
   */
  public function create_payment_demand()
  {
    check_ajax_referer('edge_create_payment_demand', 'nonce');

    if (!$this->is_available() || !WC()->cart || WC()->cart->is_empty()) {
      wp_send_json_error(
        array('message' => __('Edge Payments is not available for this order.', 'edge-gateway-for-woocommerce')),
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
      $bound_order = isset($stored_demand['order_id']) ? wc_get_order((int) $stored_demand['order_id']) : null;

      // A payment this session started is still settling. The checkout comes back
      // with a full cart while the order placed from it is on hold, so without this
      // a reload would hand the shopper a second order for the same goods - and
      // this demand cannot be reused either, since Edge only lets a `failed` one
      // back in. Past an hour, let them start again: a job stuck at Edge is not a
      // reason to refuse somebody the ability to buy.
      if (
        $bound_order instanceof WC_Order &&
        $this->id === $bound_order->get_payment_method() &&
        $bound_order->has_status('on-hold') &&
        $this->is_within_in_flight_window($bound_order)
      ) {
        wp_send_json_error(
          array('message' => __('Your previous payment is still being processed. Please wait a moment and reload this page.', 'edge-gateway-for-woocommerce')),
          409
        );
      }

      // Reuse it only while nothing has been paid against it. A declined demand is
      // the one state Edge allows back in, so a reload after a decline lands on the
      // same demand and the same order; anything else gets a fresh one.
      if (!($bound_order instanceof WC_Order) || $bound_order->has_status('failed')) {
        wp_send_json_success(array('payment_demand_id' => $stored_demand['payment_demand_id']));
      }
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
              'description' => __('WooCommerce checkout', 'edge-gateway-for-woocommerce'),
              // payment indicator
            ),
          ),
        )
      );
    } catch (Exception $e) {
      wc_get_logger()->error(
        'Unable to create an Edge payment demand: ' . $this->getEdgeErrorMessage($e),
        $this->getEdgeLogContext($e)
      );

      wp_send_json_error(
        array('message' => __('Unable to create the payment demand. Please try again.', 'edge-gateway-for-woocommerce')),
        502
      );
    }

    $payment_demand_id = isset($demand->data->id) ? sanitize_text_field($demand->data->id) : '';

    if (!$payment_demand_id) {
      wp_send_json_error(
        array('message' => __('No payment demand id found, this shouldn\'t happen.', 'edge-gateway-for-woocommerce')),
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
   * Whether an unsettled order is recent enough to still be worth waiting on.
   *
   * An order with no creation date is treated as in flight: not knowing how old it
   * is, is not knowing that it is over.
   *
   * @param  WC_Order $order Order bound to the stored demand.
   * @return bool
   */
  private function is_within_in_flight_window($order)
  {
    $created = $order->get_date_created();

    if (!$created) {
      return true;
    }

    return time() - $created->getTimestamp() < self::IN_FLIGHT_WINDOW;
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
      wp_send_json_error(array('message' => __('Invalid Edge payment reference.', 'edge-gateway-for-woocommerce')), 400);
    }

    $billing = isset($_POST['billing_address'])
      ? json_decode(wp_unslash($_POST['billing_address']), true) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is recursively sanitized below.
      : array();
    $shipping = isset($_POST['shipping_address'])
      ? json_decode(wp_unslash($_POST['shipping_address']), true) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is recursively sanitized below.
      : array();

    if (!is_array($billing) || empty($billing['email'])) {
      wp_send_json_error(array('message' => __('Please enter a valid billing address.', 'edge-gateway-for-woocommerce')), 400);
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
        $this->getEdgeLogContext($e)
      );

      wp_send_json_error(
        array('message' => __('Unable to prepare Edge Payments. Please try again.', 'edge-gateway-for-woocommerce')),
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

    // WooCommerce authenticates the checkout request before invoking the gateway.
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $payment_demand_id = isset($_POST['payment_demand_id'])
      ? sanitize_text_field(wp_unslash($_POST['payment_demand_id']))
      : '';
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    $stored_demand = WC()->session->get('edge_payment_demand');

    if (
      !$payment_demand_id ||
      !wp_is_uuid($payment_demand_id) ||
      !is_array($stored_demand) ||
      !isset($stored_demand['payment_demand_id']) ||
      empty($stored_demand['relationships']) ||
      !hash_equals((string) $stored_demand['payment_demand_id'], $payment_demand_id)
    ) {
      throw new Exception(esc_html__('Invalid Edge payment reference.', 'edge-gateway-for-woocommerce'));
    }

    // Hold the demand for the whole critical section. A webhook for this same
    // demand can arrive the instant confirm returns - before the lines below have
    // finished writing the order - and EDGEWC_Order_Sync takes the same lock, so
    // the two cannot interleave. Longer than a sync's lease because this stretch
    // is up to three calls to Edge.
    $lock_owner = $this->take_demand_lock($payment_demand_id);

    if (false === $lock_owner) {
      throw new Exception(
        esc_html__('Your payment is still being processed. Please wait a moment and try again.', 'edge-gateway-for-woocommerce')
      );
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
      // Bind the order to the demand and save it before confirming. Confirmation
      // can dispatch the outcome webhook immediately, and that delivery has to be
      // able to find this order and read the mode off it to pick the right key.
      //
      // Read the order back first: a retry after a decline reuses this demand, and
      // the previous attempt's webhook may have moved the order since the Store
      // API handed us a copy. wc_get_order() alone would not show that.
      $fresh = EDGEWC_Order_Sync::reload_order($order_id);

      if ($fresh instanceof WC_Order) {
        $order = $fresh;
      }

      // Already bound to this demand, so this order has been confirmed against it
      // once before: the shopper is retrying after a decline. Not read off the
      // status, which the Store API has already reset to `pending` by now.
      if (hash_equals($payment_demand_id, (string) $order->get_transaction_id())) {
        $order->add_order_note(__('Retrying the payment with Edge after a decline.', 'edge-gateway-for-woocommerce'));
      }

      $order->set_transaction_id($payment_demand_id);
      $order->update_meta_data('_edge_payment_mode', $this->testmode ? 'sandbox' : 'live');

      // Never walk a paid order backwards. Nothing should have paid it this early,
      // but the whole point of the guard is the case where something did.
      if (!$order->is_paid()) {
        $order->update_status(
          'on-hold',
          __('Awaiting payment confirmation from Edge.', 'edge-gateway-for-woocommerce')
        );
      }

      $order->save();

      Edge\Client::update(
        'v2/payment_demands/' . rawurlencode($payment_demand_id) . '/confirm',
        array(
          'data' => array(
            'id' => $payment_demand_id,
            'type' => 'payment_demands',
            'attributes' => (object) array(),
          ),
        )
      );
    } catch (Exception $e) {
      $message = $this->getEdgeErrorMessage($e);
      $context = $this->getEdgeLogContext(
        $e,
        array(
          'order_id' => $order_id,
          'payment_demand_id' => $payment_demand_id,
        )
      );

      wc_get_logger()->error(
        'Unable to confirm an Edge payment demand: ' . $message,
        $context
      );

      // The demand was never confirmed, so no webhook is coming. Without this the
      // order would sit on hold waiting for an outcome that cannot arrive.
      $order->update_status(
        'failed',
        __('Edge could not accept this payment.', 'edge-gateway-for-woocommerce')
      );

      throw new Exception(
        esc_html__('Order payment failed. To make a successful payment using Edge Payments, please review the gateway settings.', 'edge-gateway-for-woocommerce')
      );
    } finally {
      EDGEWC_Demand_Lock::release($payment_demand_id, $lock_owner);
    }

    // Remember which order this demand now belongs to, so that a checkout reloaded
    // mid-payment can tell "the attempt you are still waiting on" from "an
    // identical cart you are buying again".
    $stored_demand['order_id'] = $order_id;
    WC()->session->set('edge_payment_demand', $stored_demand);

    // The demand is confirmed but not yet settled, and `pending` is what tells the
    // Store API to answer 202 so the block holds the checkout open and polls
    // EDGEWC_Checkout_Controller for the outcome. Nothing is torn down here: the
    // cart is what lets WooCommerce reuse this order if the card is declined, and
    // core empties it on the thank-you page anyway (wc_clear_cart_after_payment).
    // The session record is what the retry's demand id is validated against.
    return array(
      'result' => 'pending',
      'redirect' => $this->get_return_url($order),
      // Blocks seeds its checkout store's orderId from the draft order in the
      // opening GET /wc/store/v1/checkout and never refreshes it from this
      // response, so on a fresh session the browser is holding 0. WooCommerce
      // merges everything returned here into payment_details, which is how these
      // two reach the script that has to poll for them.
      'edge_order_id' => (string) $order_id,
      'edge_demand_id' => $payment_demand_id,
    );
  }

  /**
   * Take the demand lock, allowing for a sync that is about to let go of it.
   *
   * A webhook holds it only for one read of the demand plus an order write, so a
   * brief wait is far better for the shopper than being told to try again.
   *
   * @param  string $demand_id Edge payment demand id.
   * @return string|false Owner token, or false when it could not be taken.
   */
  private function take_demand_lock($demand_id)
  {
    for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
      if ($attempt > 0) {
        usleep(self::LOCK_RETRY_DELAY_US);
      }

      $owner = EDGEWC_Demand_Lock::acquire($demand_id, self::CONFIRM_LOCK_TTL);

      if (false !== $owner) {
        return $owner;
      }
    }

    wc_get_logger()->error(
      'Gave up waiting for the Edge sync lock on a payment demand.',
      array('source' => 'edge-woocommerce', 'payment_demand_id' => $demand_id)
    );

    return false;
  }

  /**
   * Determine whether an order can be refunded through Edge.
   *
   * Both full and partial monetary refunds are supported.
   *
   * @param WC_Order $order Order object.
   * @return bool
   */
  public function can_refund_order($order)
  {
    if (!($order instanceof WC_Order) || $this->id !== $order->get_payment_method()) {
      return false;
    }

    return wp_is_uuid($order->get_transaction_id())
      && 0 < (float) $order->get_total()
      && 0 < (float) $order->get_remaining_refund_amount()
      && '' !== $this->get_private_key($this->get_order_payment_mode($order));
  }

  /**
   * Capture the provisional refund; its amount is already deducted when the gateway runs.
   *
   * @param WC_Order_Refund $refund Provisional refund.
   * @param array           $args   WooCommerce refund arguments.
   * @return void
   */
  public static function capture_refund_context($refund, $args)
  {
    if (!empty($args['refund_payment'])) {
      self::$refund_context[$refund->get_parent_id()] = $refund;
    }
  }

  /**
   * Process a full or partial refund through Edge.
   *
   * @param int        $order_id Order ID.
   * @param float|null $amount   Refund amount.
   * @param string     $reason   Refund reason.
   * @return bool|WP_Error
   */
  public function process_refund($order_id, $amount = null, $reason = '')
  {
    $refund = isset(self::$refund_context[$order_id]) ? self::$refund_context[$order_id] : null;
    unset(self::$refund_context[$order_id]);

    $lock = '_edge_refund_lock_' . absint($order_id);
    if (!$this->acquire_refund_lock($lock)) {
      return new WP_Error(
        'edge_refund_locked',
        __('Another Edge refund is being processed for this order. Please try again later. If this persists, contact support.', 'edge-gateway-for-woocommerce')
      );
    }

    try {
      return $this->process_locked_refund($order_id, $amount, $reason, $refund);
    } finally {
      delete_option($lock);
    }
  }

  /**
   * Take the per-order refund lock.
   *
   * Options have a unique key in the database, so add_option() is atomic where a
   * read-then-write transient is not. A worker killed mid-refund leaves its lock
   * behind; reclaim it once it is far older than a full polling cycle.
   *
   * @param string $lock Option name.
   * @return bool
   */
  private function acquire_refund_lock($lock)
  {
    if (add_option($lock, time(), '', false)) {
      return true;
    }

    $locked_at = (int) get_option($lock);

    if ($locked_at && time() - $locked_at > self::REFUND_LOCK_TTL) {
      delete_option($lock);
      return (bool) add_option($lock, time(), '', false);
    }

    return false;
  }

  /**
   * Submit or resume one refund while holding the order lock.
   *
   * @param int                  $order_id Order ID.
   * @param float|null           $amount   Refund amount.
   * @param string               $reason   Refund reason.
   * @param WC_Order_Refund|null $refund   Provisional WooCommerce refund.
   * @return bool|WP_Error
   */
  private function process_locked_refund($order_id, $amount, $reason, $refund)
  {
    $order = wc_get_order($order_id);

    if (!($order instanceof WC_Order) || $this->id !== $order->get_payment_method()) {
      return new WP_Error(
        'edge_refund_invalid_order',
        __('Edge Payments could not find a valid order to refund.', 'edge-gateway-for-woocommerce')
      );
    }

    // Do not cache a total containing the provisional refund: WooCommerce's CPT
    // refund deletion does not invalidate that cache on gateway failure.
    $remaining_cents = (int) round(((float) $order->get_total() -
      (float) $order->get_data_store()->get_total_refunded($order)) * 100);
    $refund_amount = null === $amount ? $remaining_cents / 100 : $amount;

    // Edge's payment and refund API use integer cents, independent of display precision.
    if (
      !is_numeric($refund_amount) || !is_finite((float) $refund_amount) ||
      (float) $refund_amount < 0 ||
      (float) $refund_amount !== (float) wc_format_decimal($refund_amount, 2) ||
      (float) $refund_amount > PHP_INT_MAX / 100
    ) {
      return new WP_Error('edge_refund_invalid_amount', __('Enter a valid refund amount in whole cents.', 'edge-gateway-for-woocommerce'));
    }

    $refund_amount = wc_format_decimal($refund_amount, 2);
    $amount_cents = (int) round((float) $refund_amount * 100);

    // WooCommerce uses zero-value refunds for restock-only operations.
    if (0.0 === (float) $refund_amount) {
      return true;
    }

    $payment_demand_id = $order->get_transaction_id();

    if (!$payment_demand_id || !wp_is_uuid($payment_demand_id)) {
      return new WP_Error(
        'edge_refund_invalid_payment',
        __('This order does not have a valid Edge payment reference.', 'edge-gateway-for-woocommerce')
      );
    }

    if ($refund instanceof WC_Order_Refund && $refund->get_id()) {
      if (
        $refund->get_parent_id() !== $order->get_id() || $refund->get_refunded_payment() ||
        $amount_cents !== (int) round((float) $refund->get_amount() * 100)
      ) {
        return new WP_Error('edge_refund_invalid_amount', __('The WooCommerce refund does not match this request.', 'edge-gateway-for-woocommerce'));
      }
      $remaining_cents += $amount_cents;
    }

    if ($amount_cents > $remaining_cents) {
      return new WP_Error(
        'edge_refund_invalid_amount',
        __('The refund amount exceeds the remaining refundable order total.', 'edge-gateway-for-woocommerce')
      );
    }

    $payment_mode = $this->get_order_payment_mode($order);
    $private_key = $this->get_private_key($payment_mode);

    if ('' === $private_key) {
      return new WP_Error(
        'edge_refund_missing_key',
        __('The Edge Payments API key for this order is not configured.', 'edge-gateway-for-woocommerce')
      );
    }

    $completed_refund_id = $order->get_meta('_edge_refund_wc_id', true);
    $completed_refund = $completed_refund_id ? wc_get_order($completed_refund_id) : false;
    if ($completed_refund instanceof WC_Order_Refund && !$completed_refund->get_refunded_payment()) {
      // The gateway succeeded, but WooCommerce has not finished saving that refund.
      // Do not attach the same Edge payment to a second provisional refund.
      return new WP_Error(
        'edge_refund_reconciliation_required',
        __('The previous Edge refund is awaiting WooCommerce confirmation. Please wait or contact support to reconcile it.', 'edge-gateway-for-woocommerce')
      );
    }
    if (
      ($completed_refund instanceof WC_Order_Refund &&
        $completed_refund->get_parent_id() === $order->get_id() &&
        $completed_refund->get_refunded_payment() &&
        $completed_refund->get_meta('_edge_refund_demand_id', true) === $order->get_meta('_edge_refund_demand_id', true)) ||
      in_array($order->get_meta('_edge_refund_state', true), array('failed', 'errored'), true)
    ) {
      // The previous attempt is accounted for, or definitively failed. Start a new one.
      foreach (self::REFUND_ATTEMPT_META as $meta_key) {
        $order->delete_meta_data($meta_key);
      }
    }

    // Edge rejects a replayed idempotency key whose amount, currency, or note differ, so an
    // unresolved attempt can only be resumed with its original values.
    $idempotency_key = $order->get_meta('_edge_refund_idempotency_key', true);
    if ($idempotency_key) {
      if (
        (int) $order->get_meta('_edge_refund_amount_cents', true) !== $amount_cents ||
        $order->get_meta('_edge_refund_currency', true) !== $order->get_currency()
      ) {
        return new WP_Error(
          'edge_refund_unresolved',
          __('A previous Edge refund is unresolved. Retry its original amount before starting another refund.', 'edge-gateway-for-woocommerce')
        );
      }
      $reason_note = (string) $order->get_meta('_edge_refund_reason_note', true);
    } else {
      $idempotency_key = wp_generate_uuid4();
      $reason_note = $this->normalize_refund_reason($reason);
      $order->update_meta_data('_edge_refund_idempotency_key', $idempotency_key);
      $order->update_meta_data('_edge_refund_amount_cents', $amount_cents);
      $order->update_meta_data('_edge_refund_currency', $order->get_currency());
      $order->update_meta_data('_edge_refund_reason_note', $reason_note);
    }

    $order->save();
    \Edge\Auth::setApiKey($private_key);

    $refund_demand_id = (string) $order->get_meta('_edge_refund_demand_id', true);

    if ($refund_demand_id && !wp_is_uuid($refund_demand_id)) {
      return new WP_Error(
        'edge_refund_invalid_reference',
        __('The stored Edge refund reference is invalid.', 'edge-gateway-for-woocommerce')
      );
    }

    if (!$refund_demand_id) {
      $attributes = array(
        'reason' => 'custom',
        'idempotency_key' => $idempotency_key,
        'amount_cents' => $amount_cents,
        'amount_currency' => $order->get_currency(),
      );

      if ('' !== $reason_note) {
        $attributes['reason_note'] = $reason_note;
      }

      try {
        $refund_demand = \Edge\Client::create(
          'v2/refund_demands',
          array(
            'data' => array(
              'type' => 'refund_demands',
              'attributes' => $attributes,
              'relationships' => array(
                'payment_demand' => array(
                  'data' => array(
                    'id' => $payment_demand_id,
                    'type' => 'payment_demands',
                  ),
                ),
              ),
            ),
          )
        );
      } catch (Throwable $e) {
        return $this->refund_exception_error(
          $e,
          __('Unable to submit the Edge refund.', 'edge-gateway-for-woocommerce'),
          $order_id,
          $payment_demand_id
        );
      }

      $refund_demand_id = isset($refund_demand->data->id)
        ? sanitize_text_field($refund_demand->data->id)
        : '';

      if (!wp_is_uuid($refund_demand_id)) {
        return $this->malformed_refund_error($order_id, $payment_demand_id);
      }

      $order->update_meta_data('_edge_refund_demand_id', $refund_demand_id);

      $order->save();
    }

    $max_poll_attempts = 16;

    for ($i = 0; $i < $max_poll_attempts; $i++) {
      try {
        $refund_demand = \Edge\Client::get(
          'v2/refund_demands/' . rawurlencode($refund_demand_id)
        );
      } catch (Throwable $e) {
        return $this->refund_exception_error(
          $e,
          __('Unable to check the Edge refund.', 'edge-gateway-for-woocommerce'),
          $order_id,
          $payment_demand_id,
          $refund_demand_id
        );
      }

      $refund_state = isset($refund_demand->data->attributes->state)
        ? sanitize_key($refund_demand->data->attributes->state)
        : '';

      if (
        !isset($refund_demand->data->id, $refund_demand->data->attributes->amount_cents, $refund_demand->data->attributes->amount_currency) ||
        $refund_demand->data->id !== $refund_demand_id ||
        !is_int($refund_demand->data->attributes->amount_cents) ||
        $refund_demand->data->attributes->amount_cents !== $amount_cents ||
        $refund_demand->data->attributes->amount_currency !== $order->get_currency() ||
        !in_array($refund_state, self::REFUND_STATES, true)
      ) {
        return $this->malformed_refund_error(
          $order_id,
          $payment_demand_id,
          $refund_demand_id
        );
      }

      if ($refund_state !== $order->get_meta('_edge_refund_state', true)) {
        $order->update_meta_data('_edge_refund_state', $refund_state);
        $order->save();
      }

      if ('succeeded' === $refund_state) {
        if ($refund instanceof WC_Order_Refund) {
          $refund->update_meta_data('_edge_refund_demand_id', $refund_demand_id);
          $refund->update_meta_data('_edge_refund_idempotency_key', $idempotency_key);
          $refund->save();
          $order->update_meta_data('_edge_refund_wc_id', $refund->get_id());
        }
        $this->record_refund_success($order, $refund_amount, $refund_demand_id);
        $order->save();
        return true;
      }

      if (in_array($refund_state, array('failed', 'errored'), true)) {
        wc_get_logger()->error(
          'Edge refund did not succeed.',
          array(
            'source' => 'edge-woocommerce',
            'order_id' => $order_id,
            'payment_demand_id' => $payment_demand_id,
            'refund_demand_id' => $refund_demand_id,
            'refund_state' => $refund_state,
          )
        );

        return new WP_Error(
          'edge_refund_failed',
          __('Edge Payments could not complete the refund.', 'edge-gateway-for-woocommerce')
        );
      }

      if ($i < $max_poll_attempts - 1) {
        sleep(2);
      }
    }

    return new WP_Error(
      'edge_refund_pending',
      __('The Edge refund is still processing. Please try again later to check its status.', 'edge-gateway-for-woocommerce')
    );
  }

  /**
   * Resolve the mode used when an order was paid.
   *
   * Older orders fall back to the gateway's current mode.
   *
   * @param WC_Order $order Order object.
   * @return string
   */
  public function get_order_payment_mode($order)
  {
    $payment_mode = sanitize_key($order->get_meta('_edge_payment_mode', true));

    if (in_array($payment_mode, array('sandbox', 'live'), true)) {
      return $payment_mode;
    }

    return $this->testmode ? 'sandbox' : 'live';
  }

  /**
   * Return the private API key for a payment mode.
   *
   * @param string $payment_mode Edge payment mode.
   * @return string
   */
  public function get_private_key($payment_mode)
  {
    $private_key = 'sandbox' === $payment_mode
      ? $this->get_option('test_private_key')
      : $this->get_option('private_key');

    return is_string($private_key) ? trim($private_key) : '';
  }

  /**
   * Normalize the free-form WooCommerce refund reason for Edge.
   *
   * @param string $reason Refund reason.
   * @return string
   */
  private function normalize_refund_reason($reason)
  {
    $reason = sanitize_text_field($reason);

    if (function_exists('mb_substr')) {
      return mb_substr($reason, 0, 500);
    }

    return substr($reason, 0, 500);
  }

  /**
   * Log a failed Edge refund call and return a WooCommerce error.
   *
   * Only the status code, request method, and path of an Edge API exception are
   * logged; response bodies never are.
   *
   * @param Throwable $exception         Edge API, transport, or SDK failure.
   * @param string    $summary           Safe error summary.
   * @param int       $order_id          WooCommerce order ID.
   * @param string    $payment_demand_id Edge payment demand ID.
   * @param string    $refund_demand_id  Edge refund demand ID, if known.
   * @return WP_Error
   */
  private function refund_exception_error($exception, $summary, $order_id, $payment_demand_id, $refund_demand_id = '')
  {
    $context = $this->getEdgeLogContext($exception, array('exception' => get_class($exception)));

    return $this->refund_error(
      $exception instanceof \Edge\Exception ? 'edge_refund_api_error' : 'edge_refund_transport_error',
      $summary,
      $order_id,
      $payment_demand_id,
      $refund_demand_id,
      $context
    );
  }

  /**
   * Return an error for an invalid refund response without logging response data.
   *
   * @param int    $order_id          WooCommerce order ID.
   * @param string $payment_demand_id Edge payment demand ID.
   * @param string $refund_demand_id  Edge refund demand ID, if known.
   * @return WP_Error
   */
  private function malformed_refund_error($order_id, $payment_demand_id, $refund_demand_id = '')
  {
    return $this->refund_error(
      'edge_refund_invalid_response',
      __('Edge Payments returned an invalid refund response.', 'edge-gateway-for-woocommerce'),
      $order_id,
      $payment_demand_id,
      $refund_demand_id
    );
  }

  /**
   * Log a refund failure with its identifiers and return a WooCommerce error.
   *
   * @param string $code              WP_Error code.
   * @param string $summary           Safe error summary, logged and shown to the admin.
   * @param int    $order_id          WooCommerce order ID.
   * @param string $payment_demand_id Edge payment demand ID.
   * @param string $refund_demand_id  Edge refund demand ID, if known.
   * @param array  $context           Additional safe log context.
   * @return WP_Error
   */
  private function refund_error($code, $summary, $order_id, $payment_demand_id, $refund_demand_id = '', $context = array())
  {
    $context = array_merge(
      array(
        'source' => 'edge-woocommerce',
        'order_id' => $order_id,
        'payment_demand_id' => $payment_demand_id,
      ),
      $context
    );

    if ($refund_demand_id) {
      $context['refund_demand_id'] = $refund_demand_id;
    }

    wc_get_logger()->error($summary, $context);

    return new WP_Error($code, $summary);
  }

  /**
   * Add a single private order note for a completed Edge refund.
   *
   * The caller saves the order.
   *
   * @param WC_Order $order            WooCommerce order.
   * @param string   $amount           Refunded amount.
   * @param string   $refund_demand_id Edge refund demand ID.
   * @return void
   */
  private function record_refund_success($order, $amount, $refund_demand_id)
  {
    if ('yes' === $order->get_meta('_edge_refund_completion_noted', true)) {
      return;
    }

    $formatted_amount = wp_strip_all_tags(
      wc_price($amount, array('currency' => $order->get_currency()))
    );

    $order->add_order_note(
      sprintf(
        /* translators: 1: Refunded amount, 2: Edge refund demand ID. */
        __('Edge refund of %1$s succeeded. Refund Demand ID: %2$s', 'edge-gateway-for-woocommerce'),
        $formatted_amount,
        $refund_demand_id
      )
    );
    $order->update_meta_data('_edge_refund_completion_noted', 'yes');
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
      throw new Exception(
        esc_html__('Order payment failed. To make a successful payment using Edge Payments, please review the gateway settings.', 'edge-gateway-for-woocommerce')
      );
    }
  }
}
