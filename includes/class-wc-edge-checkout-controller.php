<?php

/**
 * Serves the checkout's poll for a payment outcome.
 *
 * @package WooCommerce Edge Payments Gateway
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Tells a shopper waiting at the checkout what became of their payment.
 *
 * Confirming a demand only means Edge accepted the payment for processing; the
 * card network's answer lands seconds later. Rather than send the shopper to the
 * receipt and let a decline reach an empty page, the block holds the checkout
 * open and polls this route until it hears `succeeded` or `failed`.
 *
 * This route only ever reads the order. It never asks Edge: the webhook is the one
 * thing that moves an order, and this reports where the webhook has got to. A
 * shopper whose webhook has not landed inside the block's wait is sent on to the
 * receipt with the order still on hold.
 *
 * A REST route rather than a third admin-ajax action, for one specific reason: a
 * shopper who ticks "create an account" is logged in by the time the poll runs,
 * holding a nonce minted for the guest they no longer are. apiFetch's middleware
 * refreshes the nonce and replays the request only on the exact error code
 * `rest_cookie_invalid_nonce`, which is why this returns that code rather than
 * one of its own. check_ajax_referer() would simply fail those shoppers, and they
 * would never see their decline.
 */
final class WC_Edge_Checkout_Controller
{

  /** REST namespace, shared with the webhook. */
  const REST_NAMESPACE = 'edge/v1';

  /** REST route, relative to the namespace. */
  const REST_ROUTE = '/checkout-status';

  /** WooCommerce id of the Edge gateway. */
  const GATEWAY_ID = 'edge';

  /** Answer: done, whatever happens next is the merchant's business. */
  const STATUS_SUCCEEDED = 'succeeded';

  /** Answer: declined. The shopper can try another card on this order. */
  const STATUS_FAILED = 'failed';

  /** Answer: not settled yet. Keep waiting. */
  const STATUS_PROCESSING = 'processing';

  /**
   * Register the route.
   *
   * @return void
   */
  public static function register()
  {
    register_rest_route(
      self::REST_NAMESPACE,
      self::REST_ROUTE,
      array(
        'methods' => 'POST',
        'callback' => array(__CLASS__, 'handle'),
        'permission_callback' => array(__CLASS__, 'permitted'),
        'args' => array(
          'order_id' => array(
            'type' => 'integer',
            'required' => true,
            'sanitize_callback' => 'absint',
          ),
        ),
      )
    );
  }

  /**
   * Decide whether this request may ask about this order.
   *
   * @param  WP_REST_Request $request Incoming request.
   * @return bool|WP_Error
   */
  public static function permitted(WP_REST_Request $request)
  {
    self::ensure_session_loaded();

    $nonce = $request->get_header('X-WP-Nonce');

    if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
      // WordPress's own code on purpose - see the class comment.
      return new WP_Error(
        'rest_cookie_invalid_nonce',
        __('Your checkout session has expired. Please reload the page.', 'edge-gateway-for-woocommerce'),
        array('status' => 403)
      );
    }

    $order = wc_get_order(absint($request->get_param('order_id')));
    $stored_demand = WC()->session ? WC()->session->get('edge_payment_demand') : null;

    // The session's demand id is the whole session-to-order binding: this order
    // is only ours to report on if it is the one this browser just paid for.
    if (
      !($order instanceof WC_Order) ||
      !is_array($stored_demand) ||
      empty($stored_demand['payment_demand_id']) ||
      '' === (string) $order->get_transaction_id() ||
      !hash_equals((string) $stored_demand['payment_demand_id'], (string) $order->get_transaction_id())
    ) {
      return new WP_Error(
        'edge_not_your_order',
        __('Your checkout session could not be found. Please reload the page.', 'edge-gateway-for-woocommerce'),
        array('status' => 403)
      );
    }

    return true;
  }

  /**
   * Report where the payment has got to.
   *
   * @param  WP_REST_Request $request Incoming request.
   * @return WP_REST_Response|WP_Error
   */
  public static function handle(WP_REST_Request $request)
  {
    nocache_headers();

    $gateway = self::gateway();
    $order = wc_get_order(absint($request->get_param('order_id')));

    // Deliberately not is_available(): that asks whether a new payment could be
    // started, none of which has any bearing on an order already placed.
    if (!($gateway instanceof WC_Gateway_Edge)) {
      return new WP_Error(
        'edge_unavailable',
        __('Card payments are not available for this order.', 'edge-gateway-for-woocommerce'),
        array('status' => 409)
      );
    }

    if (!($order instanceof WC_Order) || self::GATEWAY_ID !== $order->get_payment_method()) {
      return new WP_Error(
        'edge_not_pending',
        __('This order is not waiting on an Edge payment.', 'edge-gateway-for-woocommerce'),
        array('status' => 409)
      );
    }

    if ($order->is_paid()) {
      return self::settled($gateway, $order);
    }

    if ($order->has_status('failed')) {
      // The webhook has recorded a decline. The order being `failed` is also what
      // lets the block checkout reuse it for the next attempt rather than
      // orphaning it, and the session record stays because the retry is
      // confirmed against this same demand.
      return rest_ensure_response(
        array(
          'status' => self::STATUS_FAILED,
          'message' => __('Your payment was declined. Please check your card details or try another card.', 'edge-gateway-for-woocommerce'),
        )
      );
    }

    // Still on hold: the webhook has not landed yet.
    return self::still_processing();
  }

  /**
   * Answer a payment that is done, and retire the session's demand record.
   *
   * Clearing it here is what stops a later checkout of an identical cart reusing
   * a demand that has already been paid - create_payment_demand() keys its reuse
   * on the cart hash, which an identical cart reproduces exactly.
   *
   * @param  WC_Gateway_Edge $gateway Gateway, for the return URL.
   * @param  WC_Order        $order   Paid order.
   * @return WP_REST_Response
   */
  private static function settled($gateway, $order)
  {
    if (WC()->session) {
      WC()->session->__unset('edge_payment_demand');
    }

    return rest_ensure_response(
      array(
        'status' => self::STATUS_SUCCEEDED,
        'redirectUrl' => $gateway->get_return_url($order),
      )
    );
  }

  /**
   * Answer "keep waiting".
   *
   * @return WP_REST_Response
   */
  private static function still_processing()
  {
    return rest_ensure_response(array('status' => self::STATUS_PROCESSING));
  }

  /**
   * Make sure the WooCommerce session and cart exist.
   *
   * A route in our own namespace does not get the Store API's session bootstrap,
   * and the session is where the demand this order is checked against lives.
   *
   * @return void
   */
  private static function ensure_session_loaded()
  {
    if (!function_exists('WC') || !function_exists('wc_load_cart')) {
      return;
    }

    if (WC()->session && WC()->cart instanceof WC_Cart) {
      return;
    }

    wc_load_cart();
  }

  /**
   * The Edge gateway instance.
   *
   * @return WC_Payment_Gateway|null
   */
  private static function gateway()
  {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
      return null;
    }

    $gateways = WC()->payment_gateways()->payment_gateways();

    return isset($gateways[self::GATEWAY_ID]) ? $gateways[self::GATEWAY_ID] : null;
  }
}
