<?php
/**
 * Keeps this site's Edge webhook subscription in step with its settings.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles the `webhook_subscriptions` resource rather than creating one.
 *
 * Saving settings must be repeatable. Creating a subscription on every save
 * would leave a trail of duplicates, each delivering the same events, so this
 * looks for the subscription already pointing at this site's callback and
 * adjusts it instead.
 */
final class WC_Edge_Subscription_Reconciler {

	/**
	 * Where subscription ids and secrets live.
	 *
	 * Deliberately not the gateway settings blob: that is read by the Blocks
	 * data layer, and a webhook secret has no business being anywhere near
	 * something that gets serialised into a page.
	 *
	 * @var string
	 */
	const OPTION = 'wc_edge_webhook_subscriptions';

	/**
	 * Events worth receiving.
	 *
	 * Only codes the backend actually emits. `payment_demands.refunded`,
	 * `.disputed` and the `refund_demands` terminal states are documented but
	 * appear in no emit site, so subscribing to them would imply a reliability
	 * this integration does not have.
	 *
	 * @var string[]
	 */
	const EVENTS = array(
		'transaction.payment_demands.created',
		'transaction.payment_demands.updated',
		'transaction.payment_demands.succeeded',
		'transaction.payment_demands.failed',
	);

	/**
	 * The URL Edge should deliver to.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( WC_Edge_Webhook_Controller::NAMESPACE_V1 . WC_Edge_Webhook_Controller::ROUTE );
	}

	/**
	 * Stored subscriptions, keyed by mode.
	 *
	 * @return array
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Every known secret, keyed by mode.
	 *
	 * The webhook handler uses this to work out which mode a delivery belongs
	 * to, so it never has to believe the `mode` in an unauthenticated payload.
	 *
	 * @return array<string,string>
	 */
	public static function secrets_by_mode() {
		$secrets = array();

		foreach ( self::stored() as $mode => $subscription ) {
			if ( ! empty( $subscription['secret'] ) ) {
				$secrets[ $mode ] = (string) $subscription['secret'];
			}
		}

		return $secrets;
	}

	/**
	 * Ensure a subscription exists for the gateway's current mode.
	 *
	 * @param WC_Gateway_Edge $gateway Gateway.
	 * @return true|WP_Error
	 */
	public static function reconcile( WC_Gateway_Edge $gateway ) {
		$mode = $gateway->get_mode();

		if ( ! $mode || ! $gateway->has_valid_keys() ) {
			return new WP_Error( 'edge_not_configured', __( 'Edge is not configured.', 'edge-gateway' ) );
		}

		$callback = self::callback_url();

		if ( ! self::is_deliverable( $callback ) ) {
			return new WP_Error(
				'edge_callback_unreachable',
				__( 'Edge cannot deliver webhooks to a site that is not reachable from the internet.', 'edge-gateway' )
			);
		}

		try {
			WC_Edge_Client_Factory::configure( $gateway->get_secret_key() );

			$existing = self::find_by_url( $callback );

			if ( $existing ) {
				return self::adopt( $mode, $existing, $callback );
			}

			return self::create( $mode, $callback );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Webhook reconciliation failed: ' . $e->getMessage() );

			return new WP_Error( 'edge_webhook_reconcile_failed', $e->getMessage() );
		}
	}

	/**
	 * Whether Edge could plausibly reach this URL.
	 *
	 * Saves a confusing round trip on a local site, where a subscription would
	 * be created that can never deliver.
	 *
	 * @param string $url Callback URL.
	 * @return bool
	 */
	public static function is_deliverable( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );

		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return false;
		}

		return ! preg_match( '/\.(test|local|localhost|invalid|example)$/', $host );
	}

	/**
	 * Find our subscription by callback URL.
	 *
	 * @param string $callback Callback URL.
	 * @return object|null
	 */
	private static function find_by_url( $callback ) {
		$response = \Edge\Client::get( 'webhook_subscriptions', array( 'page' => array( 'size' => 100 ) ) );

		foreach ( (array) ( isset( $response->data ) ? $response->data : array() ) as $subscription ) {
			if ( ! isset( $subscription->attributes->url ) ) {
				continue;
			}

			if ( (string) $subscription->attributes->url === $callback ) {
				return $subscription;
			}
		}

		return null;
	}

	/**
	 * Reuse an existing subscription, correcting it if it has drifted.
	 *
	 * @param string $mode     Mode.
	 * @param object $existing Subscription resource.
	 * @param string $callback Callback URL.
	 * @return true|WP_Error
	 */
	private static function adopt( $mode, $existing, $callback ) {
		$id     = (string) $existing->id;
		$events = isset( $existing->attributes->events ) ? (array) $existing->attributes->events : array();
		$status = isset( $existing->attributes->status ) ? (string) $existing->attributes->status : '';

		$needs_events = array_diff( self::EVENTS, $events );

		if ( ! empty( $needs_events ) || 'active' !== $status ) {
			\Edge\Client::patch(
				'webhook_subscriptions/' . rawurlencode( $id ),
				array(
					'data' => array(
						'id'         => $id,
						'type'       => 'webhook_subscriptions',
						'attributes' => array(
							'events' => array_values( array_unique( array_merge( $events, self::EVENTS ) ) ),
							'status' => 'active',
						),
					),
				)
			);
		}

		$secret = isset( $existing->attributes->secret_key ) ? (string) $existing->attributes->secret_key : '';

		if ( '' === $secret ) {
			return new WP_Error(
				'edge_webhook_secret_missing',
				__( 'Edge did not return the webhook signing secret.', 'edge-gateway' )
			);
		}

		self::remember( $mode, $id, $secret, $callback );

		return true;
	}

	/**
	 * Create a subscription for this site.
	 *
	 * @param string $mode     Mode.
	 * @param string $callback Callback URL.
	 * @return true|WP_Error
	 */
	private static function create( $mode, $callback ) {
		$response = \Edge\Client::create(
			'webhook_subscriptions',
			array(
				'data' => array(
					'type'       => 'webhook_subscriptions',
					'attributes' => array(
						'mode'        => $mode,
						'url'         => $callback,
						// The backend requires at least ten characters here.
						'description' => 'WooCommerce payment gateway on ' . wp_parse_url( home_url(), PHP_URL_HOST ),
						'events'      => self::EVENTS,
					),
				),
			)
		);

		$secret = isset( $response->data->attributes->secret_key )
			? (string) $response->data->attributes->secret_key
			: '';

		if ( '' === $secret ) {
			return new WP_Error(
				'edge_webhook_secret_missing',
				__( 'Edge did not return the webhook signing secret.', 'edge-gateway' )
			);
		}

		self::remember( $mode, (string) $response->data->id, $secret, $callback );

		return true;
	}

	/**
	 * Persist a subscription for a mode.
	 *
	 * @param string $mode     Mode.
	 * @param string $id       Subscription id.
	 * @param string $secret   Signing secret.
	 * @param string $callback Callback URL.
	 * @return void
	 */
	private static function remember( $mode, $id, $secret, $callback ) {
		$stored = self::stored();

		$stored[ $mode ] = array(
			'id'       => $id,
			'secret'   => $secret,
			'url'      => $callback,
			'synced_at' => current_time( 'mysql', true ),
		);

		update_option( self::OPTION, $stored, false );
	}
}
