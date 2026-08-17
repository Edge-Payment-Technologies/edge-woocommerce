<?php

/**
 * Receives Edge webhooks and moves orders to their final state.
 *
 * @package  WooCommerce Edge Payments Gateway
 * @since    1.0.8
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Serves `POST /wp-json/edge/v1/webhook`.
 *
 * A payment demand does not finish inside the checkout request, so the webhook
 * is what actually completes an order. Nothing here trusts the delivery itself:
 * the payload only says that something about a demand changed, and the demand
 * is then read back from the API to find out what.
 *
 * @class    WC_Edge_Webhook_Handler
 * @version  1.0.8
 */
class WC_Edge_Webhook_Handler
{

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'edge/v1';

	/**
	 * REST route, relative to the namespace.
	 */
	const REST_ROUTE = '/webhook';

	/**
	 * Order meta holding the payment demand a webhook is matched against.
	 */
	const DEMAND_META = '_edge_payment_demand_id';

	/**
	 * Order meta holding the mode the demand was created in.
	 */
	const MODE_META = '_edge_mode';

	/**
	 * The resource this integration cares about.
	 */
	const HANDLED_RESOURCE = 'transaction.payment_demands';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register_routes()
	{
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods' => 'POST',
				'callback' => array(__CLASS__, 'handle'),
				// Edge has no WordPress identity, so there is nothing for WordPress
				// to authenticate. See verify_delivery() for what guards this route.
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
		return apply_filters(
			'woocommerce_edge_webhook_url',
			rest_url(self::REST_NAMESPACE . self::REST_ROUTE)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * Nearly every outcome is a 200. Edge stops retrying for good on 400, 401,
	 * 403, 404 and 405, so returning one of those because of a problem at this
	 * end would discard the event, and with it the order's only way out of
	 * on-hold. A 500 is kept for the case where a retry would genuinely help.
	 *
	 * @param  WP_REST_Request  $request
	 * @return WP_REST_Response
	 */
	public static function handle(WP_REST_Request $request)
	{
		if (!self::verify_delivery($request)) {
			self::log('Rejected a webhook that could not be verified.');

			return self::respond('rejected');
		}

		$event = self::read_event($request->get_json_params());

		if (!$event) {
			self::log('Rejected a webhook with an unreadable payload.');

			return self::respond('ignored');
		}

		try {
			// First writer wins. This turns away Edge's retries, and serialises
			// two deliveries of the same event arriving at once.
			if (!WC_Edge_Webhook_Events::claim($event)) {
				return self::respond('duplicate');
			}
		} catch (Throwable $e) {
			self::log('Could not record a webhook event: ' . $e->getMessage());

			return self::respond('retry', 500);
		}

		try {
			$result = self::apply($event);
		} catch (Throwable $e) {
			// Let the claim go, otherwise Edge's retry arrives and is dismissed
			// as a duplicate of an attempt that never did anything.
			WC_Edge_Webhook_Events::release($event['id']);
			self::log('Webhook handling failed: ' . $e->getMessage());

			return self::respond('retry', 500);
		}

		WC_Edge_Webhook_Events::complete($event['id'], $result['status'], $result['order_id']);

		return self::respond($result['status']);
	}

	/**
	 * Decide whether a delivery can be trusted.
	 *
	 * TODO: verify the `x-hub-signature` header against the subscription's
	 * signing secret, and add that secret to the gateway settings.
	 *
	 * Until then the endpoint is open, which is survivable only because of how
	 * apply() is written. The payload is never believed: it supplies an event id
	 * and a demand id, and every fact acted on is read back from the API with
	 * this site's own key. An unsigned caller can therefore make the site
	 * re-read a demand it already owns, but cannot invent a payment, name an
	 * amount, or mark an order paid.
	 *
	 * Note also what the header is worth today. Edge sends
	 * `base64(sha1(secret_key))`, a constant per subscription with the body not
	 * an input, so checking it authenticates the sender and nothing else. The
	 * read-back below is what protects the order either way.
	 *
	 * @param  WP_REST_Request  $request
	 * @return bool
	 */
	private static function verify_delivery(WP_REST_Request $request)
	{
		return (bool) apply_filters('woocommerce_edge_verify_webhook', true, $request);
	}

	/**
	 * Pull the fields worth having out of either envelope shape.
	 *
	 * Which shape arrives is a per-merchant setting on Edge's side that a
	 * subscription cannot choose, so both have to be understood: v2 nests the
	 * event under `data`, v1 sends the same object unwrapped.
	 *
	 * @param  mixed  $body  Decoded JSON body.
	 * @return array|null
	 */
	private static function read_event($body)
	{
		if (!is_array($body)) {
			return null;
		}

		$event = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

		if (empty($event['id']) || empty($event['attributes']) || !is_array($event['attributes'])) {
			return null;
		}

		$attributes = $event['attributes'];

		return array(
			'id' => (string) $event['id'],
			'resource_type' => isset($attributes['resource_type']) ? (string) $attributes['resource_type'] : '',
			'resource_id' => isset($attributes['resource_id']) ? (string) $attributes['resource_id'] : '',
			'slug' => isset($attributes['slug']) ? (string) $attributes['slug'] : '',
			'mode' => isset($attributes['mode']) ? (string) $attributes['mode'] : '',
		);
	}

	/**
	 * Read the demand back and move the order to match.
	 *
	 * @param  array  $event  Normalised event.
	 * @return array  Outcome label and the order it applied to.
	 * @throws Exception  When the demand cannot be read, so the caller releases the
	 *                    claim and Edge retries.
	 */
	private static function apply(array $event)
	{
		if (self::HANDLED_RESOURCE !== $event['resource_type'] || '' === $event['resource_id']) {
			return self::outcome('unhandled');
		}

		$order = self::find_order($event['resource_id']);

		if (!$order) {
			// Not ours. Several sites can share one subscription, and Edge also
			// emits for payments taken outside WooCommerce.
			return self::outcome('unknown-order');
		}

		$gateway = self::gateway();

		if (!$gateway instanceof WC_Gateway_Edge) {
			throw new Exception('The Edge gateway is not available.');
		}

		// The mode comes from the order rather than the payload. We wrote it when
		// the demand was created, so it is the one piece of routing that an
		// unverified delivery cannot influence.
		$mode = (string) $order->get_meta(self::MODE_META);

		\Edge\Auth::setApiKey($gateway->get_secret_key($mode));

		$demand = \Edge\Client::get('payment_demands/' . rawurlencode($event['resource_id']));

		$state = isset($demand->data->attributes->processor_state)
			? (string) $demand->data->attributes->processor_state
			: '';

		$reason = isset($demand->data->attributes->failure_reason)
			? (string) $demand->data->attributes->failure_reason
			: '';

		return self::outcome(self::transition($order, $state, $reason, $event['resource_id']), $order->get_id());
	}

	/**
	 * Apply a state to an order, never downgrading it.
	 *
	 * Deliveries arrive more than once and out of order, so each transition has
	 * to be safe to repeat and safe to receive late. A demand that has already
	 * been paid is never walked back by an older failure.
	 *
	 * @param  WC_Order  $order
	 * @param  string    $state      Authoritative processor state.
	 * @param  string    $reason     Failure reason, when the API gave one.
	 * @param  string    $demand_id
	 * @return string    Outcome label.
	 */
	private static function transition(WC_Order $order, $state, $reason, $demand_id)
	{
		$paid = $order->is_paid();

		switch ($state) {
			case 'succeeded':
				if ($paid) {
					return 'already-paid';
				}

				$order->payment_complete($demand_id);
				$order->add_order_note(__('Edge confirmed this payment succeeded.', 'edge-gateway'));

				return 'paid';

			case 'failed':
				if ($paid) {
					// Late, and behind the order. Worth recording, not worth acting on.
					$order->add_order_note(
						__('Edge reported a failure for a payment already marked paid. The order has been left alone.', 'edge-gateway')
					);

					return 'stale-failure';
				}

				$order->update_status(
					'failed',
					'' === $reason
						? __('Edge declined this payment.', 'edge-gateway')
						/* translators: %s: failure reason reported by Edge. */
						: sprintf(__('Edge declined this payment: %s', 'edge-gateway'), $reason)
				);

				return 'failed';

			case 'reversed':
			case 'refunded':
			case 'disputed':
				// Money moved back after the fact. WooCommerce has no truthful
				// status for this, and guessing one would misreport the order, so
				// it is recorded for a human to settle.
				$order->add_order_note(
					sprintf(
						/* translators: %s: payment state reported by Edge. */
						__('Edge reported this payment as %s. Reconcile it in the Edge dashboard.', 'edge-gateway'),
						$state
					)
				);
				$order->update_meta_data('_edge_processor_state', $state);
				$order->save();

				return $state;

			case 'pending':
			case 'processing':
				return 'no-change';

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: unrecognised payment state reported by Edge. */
						__('Edge reported a payment state this plugin does not recognise: %s', 'edge-gateway'),
						'' === $state ? '(none)' : $state
					)
				);

				return 'unrecognised-state';
		}
	}

	/**
	 * The order bound to a demand.
	 *
	 * Demand ids are unique across both modes, so the id alone is the match.
	 *
	 * @param  string  $demand_id
	 * @return WC_Order|null
	 */
	private static function find_order($demand_id)
	{
		// wc_get_orders() rather than a meta query of our own, so this works the
		// same whether the store keeps orders in posts or in HPOS tables.
		$orders = wc_get_orders(
			array(
				'limit' => 1,
				'status' => 'any',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key' => self::DEMAND_META,
						'value' => $demand_id,
					),
				),
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

		return isset($gateways['edge']) ? $gateways['edge'] : null;
	}

	/**
	 * Shape an outcome.
	 *
	 * @param  string  $status
	 * @param  int     $order_id
	 * @return array
	 */
	private static function outcome($status, $order_id = 0)
	{
		return array('status' => $status, 'order_id' => $order_id);
	}

	/**
	 * A response carrying what was done.
	 *
	 * @param  string  $status
	 * @param  int     $code
	 * @return WP_REST_Response
	 */
	private static function respond($status, $code = 200)
	{
		return new WP_REST_Response(array('status' => $status), $code);
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param  string  $message
	 * @return void
	 */
	private static function log($message)
	{
		if (!function_exists('wc_get_logger')) {
			return;
		}

		wc_get_logger()->error($message, array('source' => 'edge-gateway'));
	}
}
