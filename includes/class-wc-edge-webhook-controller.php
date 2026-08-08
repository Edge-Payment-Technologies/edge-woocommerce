<?php
/**
 * Receives Edge webhooks and moves orders to their final state.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves `POST /wp-json/edge/v1/webhook`.
 *
 * Webhooks are what actually complete an order: confirming only tells Edge to
 * start processing. Everything here is built around not trusting the delivery
 * itself.
 */
final class WC_Edge_Webhook_Controller {

	const NAMESPACE_V1 = 'edge/v1';
	const ROUTE        = '/webhook';

	/**
	 * Header carrying Edge's signature.
	 *
	 * @var string
	 */
	const SIGNATURE_HEADER = 'x-hub-signature';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'handle' ),
				// Authentication is the signature check inside the handler: this
				// endpoint is called by Edge, which has no WordPress identity.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * Almost every outcome is a 200. Edge treats 400, 401, 403, 404 and 405 as
	 * terminal and permanently stops retrying, so returning one of those for a
	 * problem on our side would silently discard the event - and with it, the
	 * order's only route out of on-hold. A 5xx is reserved for authenticated
	 * events that genuinely deserve a retry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$signature = (string) $request->get_header( self::SIGNATURE_HEADER );
		$mode      = self::trusted_mode( $signature );

		if ( null === $mode ) {
			// Never log the supplied signature: it is a bearer credential.
			WC_Edge_Logger::error( 'Rejected a webhook with an unrecognised signature.' );

			return self::ok( 'ignored' );
		}

		$event = self::extract_event( $request->get_json_params() );

		if ( ! $event ) {
			WC_Edge_Logger::error( 'Rejected a webhook with an unreadable payload.' );

			return self::ok( 'ignored' );
		}

		// First insert wins, which both deduplicates retries and serialises
		// concurrent deliveries of the same event.
		if ( ! WC_Edge_Webhook_Store::claim( $event['id'], $event + array( 'mode' => $mode ) ) ) {
			return self::ok( 'duplicate' );
		}

		try {
			$applied = self::apply( $event, $mode );
		} catch ( \Throwable $e ) {
			// Let the claim go so Edge's retry is not a no-op.
			WC_Edge_Webhook_Store::release( $event['id'] );
			WC_Edge_Logger::error( 'Webhook handling failed: ' . $e->getMessage() );

			return new WP_REST_Response( array( 'status' => 'retry' ), 500 );
		}

		return self::ok( $applied );
	}

	/**
	 * Work out which mode a delivery belongs to from its signature.
	 *
	 * The signature is compared against every stored subscription secret, and
	 * the one that matches decides the mode. The payload also carries a `mode`,
	 * but it is unauthenticated, so using it to choose which API key to trust
	 * would let a caller pick their own credentials.
	 *
	 * Note this signature is `base64(sha1(secret))` - a constant per
	 * subscription, with the body not an input. It is a bearer token, not proof
	 * of integrity, which is exactly why the resource is re-fetched below.
	 *
	 * @param string $signature Supplied signature.
	 * @return string|null Trusted mode, or null.
	 */
	private static function trusted_mode( $signature ) {
		if ( '' === $signature ) {
			return null;
		}

		foreach ( WC_Edge_Subscription_Reconciler::secrets_by_mode() as $mode => $secret ) {
			if ( hash_equals( base64_encode( sha1( $secret, true ) ), $signature ) ) {
				return $mode;
			}
		}

		return null;
	}

	/**
	 * Normalise the two envelope shapes Edge sends.
	 *
	 * Which one arrives is a per-merchant flag on Edge's side, not something a
	 * subscription can select, so both have to be accepted.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array|null
	 */
	private static function extract_event( $body ) {
		if ( ! is_array( $body ) ) {
			return null;
		}

		// v2 nests under `data`; v1 is the same object unwrapped.
		$event = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

		if ( empty( $event['id'] ) || empty( $event['attributes'] ) ) {
			return null;
		}

		$attributes = $event['attributes'];

		return array(
			'id'            => (string) $event['id'],
			'resource_type' => isset( $attributes['resource_type'] ) ? (string) $attributes['resource_type'] : '',
			'resource_id'   => isset( $attributes['resource_id'] ) ? (string) $attributes['resource_id'] : '',
			'slug'          => isset( $attributes['slug'] ) ? (string) $attributes['slug'] : '',
		);
	}

	/**
	 * Re-read the resource and move the order accordingly.
	 *
	 * @param array  $event Normalised event.
	 * @param string $mode  Trusted mode.
	 * @return string Outcome label.
	 */
	private static function apply( array $event, $mode ) {
		if ( 'transaction.payment_demands' !== $event['resource_type'] || '' === $event['resource_id'] ) {
			return 'unhandled';
		}

		$order = self::find_order( $event['resource_id'], $mode );

		if ( ! $order ) {
			// Not ours - another site can share a subscription, and Edge also
			// emits for payments made outside WooCommerce.
			return 'unknown-order';
		}

		WC_Edge_Webhook_Store::attach_order( $event['id'], $order->get_id() );

		$gateway = self::gateway();

		if ( ! $gateway instanceof WC_Gateway_Edge ) {
			throw new RuntimeException( 'Edge gateway unavailable while handling a webhook.' );
		}

		WC_Edge_Client_Factory::configure( $gateway->get_secret_key() );

		// The payload carries a state, but the signature does not authenticate
		// the body, so it is only a hint that something changed. This is the
		// authoritative read.
		$demand = \Edge\Client::get( 'payment_demands/' . rawurlencode( $event['resource_id'] ) );

		$state = isset( $demand->data->attributes->processor_state )
			? (string) $demand->data->attributes->processor_state
			: '';

		self::record_risk_signals( $order, $demand );

		return self::transition( $order, $state, $event['resource_id'] );
	}

	/**
	 * Apply a state to an order, never downgrading it.
	 *
	 * Deliveries can arrive out of order and more than once, so every transition
	 * has to be safe to repeat and safe to receive late. A paid order is never
	 * moved backwards by an older failure.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $state     Authoritative processor state.
	 * @param string   $demand_id Demand id.
	 * @return string Outcome label.
	 */
	private static function transition( WC_Order $order, $state, $demand_id ) {
		$already_paid = $order->is_paid();

		switch ( $state ) {
			case 'succeeded':
				if ( $already_paid ) {
					return 'already-paid';
				}

				$order->payment_complete( $demand_id );
				$order->add_order_note( __( 'Edge confirmed this payment succeeded.', 'edge-gateway' ) );

				return 'completed';

			case 'failed':
				if ( $already_paid ) {
					// Arriving after a success means it is stale. Record it, but
					// do not un-pay an order.
					$order->add_order_note(
						__( 'Edge reported a failure for a payment already marked paid. Not changing the order.', 'edge-gateway' )
					);

					return 'ignored-stale-failure';
				}

				$order->update_status( 'failed', __( 'Edge declined this payment.', 'edge-gateway' ) );

				return 'failed';

			case 'reversed':
			case 'refunded':
			case 'disputed':
				$order->update_meta_data( '_edge_processor_state', $state );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Edge processor state. */
						__( 'Edge reported this payment as %s. Reconcile it in the Edge dashboard.', 'edge-gateway' ),
						$state
					)
				);
				$order->save();

				return $state;

			case 'pending':
			case 'processing':
			case 'incomplete':
			case 'ready':
			case 'confirmed':
			case 'canceled':
				return 'no-change';

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: unrecognised state. */
						__( 'Edge reported an unrecognised payment state: %s.', 'edge-gateway' ),
						$state
					)
				);

				return 'unrecognised-state';
		}
	}

	/**
	 * Keep the fraud and authentication results with the order.
	 *
	 * These are the evidence a merchant needs if a payment is ever disputed, and
	 * they are not retrievable once the order is closed out.
	 *
	 * @param WC_Order $order  Order.
	 * @param object   $demand Demand document.
	 * @return void
	 */
	private static function record_risk_signals( WC_Order $order, $demand ) {
		$attributes = isset( $demand->data->attributes ) ? $demand->data->attributes : null;

		if ( ! $attributes ) {
			return;
		}

		$signals = array(
			'cvc2_check',
			'address_line1_verification',
			'postal_code_verification',
			'threeds_status',
			'threeds_version',
			'eci',
		);

		$changed = false;

		foreach ( $signals as $signal ) {
			if ( isset( $attributes->$signal ) && '' !== (string) $attributes->$signal ) {
				$order->update_meta_data( '_edge_' . $signal, (string) $attributes->$signal );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Find the order bound to a demand, in the trusted mode.
	 *
	 * @param string $demand_id Demand id.
	 * @param string $mode      Trusted mode.
	 * @return WC_Order|null
	 */
	private static function find_order( $demand_id, $mode ) {
		// wc_get_orders() rather than a meta query, so this works under HPOS as
		// well as post storage.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_edge_demand_id',
						'value' => $demand_id,
					),
					array(
						'key'   => '_edge_mode',
						'value' => $mode,
					),
				),
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * The registered gateway instance.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return null;
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		return isset( $gateways['edge'] ) ? $gateways['edge'] : null;
	}

	/**
	 * A 200 carrying what was done.
	 *
	 * @param string $status Outcome label.
	 * @return WP_REST_Response
	 */
	private static function ok( $status ) {
		return new WP_REST_Response( array( 'status' => $status ), 200 );
	}
}
