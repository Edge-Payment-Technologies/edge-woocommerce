<?php

/**
 * Receives Edge webhooks and moves orders to their final state.
 *
 * @package  Edge Gateway for WooCommerce
 * @since    1.0.19
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Serves `POST /wp-json/edge/v1/webhook`.
 *
 * A payment demand does not finish inside the checkout request, so the order is
 * left on hold and this is what actually completes it. Nothing here trusts the
 * delivery body: it only names a payment demand, and the state acted on is read
 * back from the Edge API with this site's own key.
 *
 * @class    WC_Edge_Webhook_Controller
 * @version  1.0.19
 */
class WC_Edge_Webhook_Controller
{
  /** REST namespace. */
  const REST_NAMESPACE = 'edge/v1';

  /** REST route, relative to the namespace. */
  const REST_ROUTE = '/webhook';

  /** Header carrying the v3 delivery signature. */
  const SIGNATURE_HEADER = 'edge-signature';

  /**
   * How far a delivery's own timestamp may sit from this site's clock, in seconds.
   *
   * The signature covers the timestamp, so without a window an intercepted
   * delivery stays replayable for good. Edge signs every retry afresh, so no
   * legitimate delivery is ever older than its own transit time.
   */
  const SIGNATURE_TOLERANCE = 300;

  /** The only Edge resource this controller acts on. */
  const HANDLED_RESOURCE = 'transaction.payment_demands';

  /** Event slugs that report a payment outcome. `created` and `updated` do not. */
  const OUTCOME_SLUGS = array('succeeded', 'failed');

  /** WooCommerce id of the Edge gateway. */
  const GATEWAY_ID = 'edge';

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
        // Edge has no WordPress identity, so there is nothing for WordPress to
        // authenticate. The signature check in handle() is what guards this.
        'permission_callback' => '__return_true',
      )
    );
  }

  /**
   * The URL Edge should deliver to.
   *
   * @return string
   */
  public static function callback_url()
  {
    return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
  }

  /**
   * Handle a delivery.
   *
   * @param  WP_REST_Request $request Incoming request.
   * @return WP_REST_Response
   */
  public static function handle(WP_REST_Request $request)
  {
    $signature = self::verify_signature($request);

    if ('stale' === $signature) {
      // Authentic, but too old to accept. Either the delivery was held up for
      // minutes somewhere or this server's clock has drifted. 500 rather than
      // 401 so the retry ladder stays alive: every retry is signed afresh, so a
      // delayed delivery still lands, and a drifting clock is worth the noise.
      self::log('Rejected an Edge webhook signed outside the accepted time window. Check the clock on this server.');

      return self::respond('stale', 500);
    }

    if ('ok' !== $signature) {
      self::log('Rejected an Edge webhook whose signature did not verify.');

      return self::respond('rejected', 401);
    }

    $event = self::read_event($request->get_json_params());

    if (!$event) {
      return self::respond('ignored');
    }

    if (
      self::HANDLED_RESOURCE !== $event['resource_type'] ||
      !in_array($event['slug'], self::OUTCOME_SLUGS, true)
    ) {
      return self::respond('ignored');
    }

    $order = self::find_order($event['resource_id']);

    if (!$order) {
      // Not ours. One subscription can serve several sites, and Edge also emits
      // for payments taken outside WooCommerce.
      return self::respond('unknown-order');
    }

    $gateway = self::gateway();

    if (!$gateway instanceof WC_Gateway_Edge) {
      self::log('An Edge webhook arrived but the gateway is not available.');

      return self::respond('retry', 500);
    }

    // The mode comes from the order, not the payload: it was written when the
    // demand was confirmed, so an unverified body cannot influence key selection.
    $private_key = $gateway->get_private_key($gateway->get_order_payment_mode($order));

    if ('' === $private_key) {
      self::log('No Edge private key is configured for order ' . $order->get_id() . '.');

      return self::respond('retry', 500);
    }

    try {
      \Edge\Auth::setApiKey($private_key);

      $response = \Edge\Client::get('v2/payment_demands/' . rawurlencode($event['resource_id']));
    } catch (Exception $e) {
      // A retry may well succeed, so ask for one rather than losing the event.
      self::log('Unable to read an Edge payment demand for order ' . $order->get_id() . '.');

      return self::respond('retry', 500);
    }

    $state = self::processor_state($response);

    if ('' === $state) {
      self::log('Edge returned an unreadable payment demand for order ' . $order->get_id() . '.');

      return self::respond('retry', 500);
    }

    return self::respond(self::transition($order, $state));
  }

  /**
   * Apply an Edge payment state to an order.
   *
   * Deliveries arrive out of order and more than once, so every transition has to
   * be safe to repeat: a paid order is never walked back by a late failure.
   *
   * @param  WC_Order $order Order to move.
   * @param  string   $state Authoritative processor state.
   * @return string   Outcome label.
   */
  private static function transition($order, $state)
  {
    if ($order->is_paid()) {
      return 'already-paid';
    }

    if ('succeeded' === $state) {
      $order->payment_complete($order->get_transaction_id());
      $order->add_order_note(__('Edge confirmed this payment succeeded.', 'edge-gateway-for-woocommerce'));

      return 'paid';
    }

    if ('failed' === $state) {
      $order->update_status('failed', __('Edge declined this payment.', 'edge-gateway-for-woocommerce'));

      return 'failed';
    }

    // pending, processing, reversed, disputed: nothing to do here.
    return 'no-change';
  }

  /**
   * Pull the fields worth having out of a v3 delivery.
   *
   * @param  mixed $body Decoded JSON body.
   * @return array|null
   */
  private static function read_event($body)
  {
    if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
      return null;
    }

    $attributes = isset($body['data']['attributes']) ? $body['data']['attributes'] : null;

    if (!is_array($attributes)) {
      return null;
    }

    $resource_id = isset($attributes['resource_id']) ? (string) $attributes['resource_id'] : '';

    if (!wp_is_uuid($resource_id)) {
      return null;
    }

    return array(
      'resource_type' => isset($attributes['resource_type']) ? (string) $attributes['resource_type'] : '',
      'resource_id' => $resource_id,
      'slug' => isset($attributes['slug']) ? (string) $attributes['slug'] : '',
    );
  }

  /**
   * Decide whether a delivery really came from Edge.
   *
   * Version 3 signs `"<timestamp>.<raw body>"` with HMAC-SHA256 under the subscription's
   * secret key, and sends it as `edge-signature: t=<unix>,v3=<hex>`. The header is
   * a comma-separated list of name=value pairs and may carry more schemes later,
   * so it is parsed rather than split into a fixed two parts.
   *
   * The timestamp is checked as well as signed. A matching MAC only proves the
   * delivery was genuine once; the window is what stops an old one being played
   * back later, and it is the sole replay defence here because nothing records
   * event ids.
   *
   * @param  WP_REST_Request $request Incoming request.
   * @return string `ok`, `stale` (authentic but outside the window), or `invalid`.
   */
  private static function verify_signature(WP_REST_Request $request)
  {
    $gateway = self::gateway();

    if (!$gateway instanceof WC_Gateway_Edge) {
      return 'invalid';
    }

    $secret = trim((string) $gateway->get_option('webhook_secret'));

    if ('' === $secret) {
      return 'invalid';
    }

    $parts = array();

    foreach (explode(',', (string) $request->get_header(self::SIGNATURE_HEADER)) as $pair) {
      $pair = explode('=', trim($pair), 2);

      if (2 === count($pair)) {
        $parts[$pair[0]] = $pair[1];
      }
    }

    // ctype_digit keeps the timestamp to the positive integer seconds Edge sends,
    // so the comparison below cannot be fed a string that casts to something odd.
    if (!isset($parts['t'], $parts['v3']) || !ctype_digit($parts['t'])) {
      return 'invalid';
    }

    $expected = hash_hmac('sha256', $parts['t'] . '.' . $request->get_body(), $secret);

    // Authenticate first: freshness only means anything once the MAC has held,
    // and an unsigned request should not be told how far off its clock is.
    if (!hash_equals($expected, $parts['v3'])) {
      return 'invalid';
    }

    // Absolute difference, so a delivery stamped in the future is rejected too.
    return abs(time() - (int) $parts['t']) > self::signature_tolerance() ? 'stale' : 'ok';
  }

  /**
   * Seconds of clock difference this site will tolerate on a delivery.
   *
   * Filterable because the failure it guards against is operational, not hostile:
   * a host whose clock drifts rejects every delivery and leaves orders on hold,
   * and widening the window is a faster fix than waiting on the host to fix NTP.
   * A non-positive filter result is ignored rather than disabling the check.
   *
   * @return int
   */
  private static function signature_tolerance()
  {
    /**
     * Filters how far an Edge webhook's timestamp may sit from this site's clock.
     *
     * @since 1.0.20
     *
     * @param int $tolerance Tolerance in seconds. Non-positive values are ignored.
     */
    $tolerance = (int) apply_filters('edge_webhook_signature_tolerance', self::SIGNATURE_TOLERANCE);

    return $tolerance > 0 ? $tolerance : self::SIGNATURE_TOLERANCE;
  }

  /**
   * Read the processor state out of an Edge payment demand response.
   *
   * @param  mixed $response Decoded SDK response.
   * @return string Empty when the response cannot be read.
   */
  private static function processor_state($response)
  {
    if (
      !is_object($response) ||
      !isset($response->data->attributes->processor_state) ||
      !is_string($response->data->attributes->processor_state)
    ) {
      return '';
    }

    return $response->data->attributes->processor_state;
  }

  /**
   * The Edge order holding a payment demand.
   *
   * `process_payment()` stores the demand id as the order's transaction id, which
   * `wc_get_orders()` can query directly, so this needs no meta of its own and
   * works the same on HPOS and legacy storage.
   *
   * @param  string $demand_id Edge payment demand id.
   * @return WC_Order|null
   */
  private static function find_order($demand_id)
  {
    $orders = wc_get_orders(
      array(
        'limit' => 1,
        'status' => 'any',
        'payment_method' => self::GATEWAY_ID,
        'transaction_id' => $demand_id,
      )
    );

    return !empty($orders) && $orders[0] instanceof WC_Order ? $orders[0] : null;
  }

  /**
   * The registered gateway instance.
   *
   * @return WC_Payment_Gateway|null
   */
  private static function gateway()
  {
    if (!function_exists('WC') || !WC()->payment_gateways) {
      return null;
    }

    $gateways = WC()->payment_gateways->payment_gateways();

    return isset($gateways[self::GATEWAY_ID]) ? $gateways[self::GATEWAY_ID] : null;
  }

  /**
   * A response carrying what was done.
   *
   * Edge stops retrying for good on 400, 401, 403, 404 and 405, so anything that a
   * retry could fix must answer 500, and everything settled must answer 200.
   *
   * @param  string $status Outcome label.
   * @param  int    $code   HTTP status code.
   * @return WP_REST_Response
   */
  private static function respond($status, $code = 200)
  {
    return new WP_REST_Response(array('status' => $status), $code);
  }

  /**
   * Write to the WooCommerce log. Identifiers only, never a response body.
   *
   * @param  string $message Message to log.
   * @return void
   */
  private static function log($message)
  {
    if (!function_exists('wc_get_logger')) {
      return;
    }

    wc_get_logger()->error($message, array('source' => 'edge-woocommerce'));
  }
}
