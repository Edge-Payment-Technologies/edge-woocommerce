<?php
/**
 * REST endpoint the block checkout calls before mounting the hosted form.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves `POST /wp-json/edge/v1/checkout-intent`.
 *
 * The hosted iframe needs a payment demand to mount against, and it needs one
 * before WooCommerce has created an order. This is where that demand comes from.
 */
final class WC_Edge_REST_Controller {

	const NAMESPACE_V1 = 'edge/v1';
	const ROUTE        = '/checkout-intent';

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
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permitted' ),
				'args'                => array(
					'cart_hash' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Decide whether the caller may prepare a payment.
	 *
	 * This is a state-changing call that spends real resources at Edge, so it is
	 * gated on the shopper actually being mid-checkout: a valid REST nonce, an
	 * established WooCommerce session, and a cart to pay for. A nonce alone is
	 * not enough, because it says nothing about which session is asking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permitted( WP_REST_Request $request ) {
		self::ensure_cart_loaded();

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'edge_bad_nonce',
				__( 'Your checkout session has expired. Please reload the page.', 'edge-gateway' ),
				array( 'status' => 403 )
			);
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get_customer_id() ) {
			return new WP_Error(
				'edge_no_session',
				__( 'Your checkout session could not be found. Please reload the page.', 'edge-gateway' ),
				array( 'status' => 403 )
			);
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return new WP_Error(
				'edge_cart_empty',
				__( 'Your cart is empty.', 'edge-gateway' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Prepare a demand and return what the browser needs to mount the form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		nocache_headers();

		$gateway = self::gateway();

		if ( ! $gateway instanceof WC_Gateway_Edge || ! $gateway->is_available() ) {
			return new WP_Error(
				'edge_unavailable',
				__( 'Card payments are not available for this order.', 'edge-gateway' ),
				array( 'status' => 409 )
			);
		}

		// If the browser tells us which cart it is showing, refuse to prepare for
		// a different one. The cart is still read server-side; this only catches
		// a stale page, it is not a source of truth.
		$claimed_hash = (string) $request->get_param( 'cart_hash' );

		if ( '' !== $claimed_hash && ! hash_equals( (string) WC()->cart->get_cart_hash(), $claimed_hash ) ) {
			return new WP_Error(
				'edge_cart_changed',
				__( 'Your cart has changed. Please reload the page.', 'edge-gateway' ),
				array( 'status' => 409 )
			);
		}

		$prepared = WC_Edge_Payment_Service::prepare( $gateway );

		if ( is_wp_error( $prepared ) ) {
			$prepared->add_data( array( 'status' => 400 ) );

			return $prepared;
		}

		return rest_ensure_response(
			array(
				'demandId'       => $prepared['demand_id'],
				'attemptKey'     => $prepared['attempt_key'],
				'publishableKey' => $gateway->get_publishable_key(),
				'iframeHost'     => WC_Edge_Client_Factory::dashboard_host(),
				'mode'           => $gateway->get_mode(),
			)
		);
	}

	/**
	 * Make sure the shopper's cart and session exist for this request.
	 *
	 * WooCommerce loads the session and cart automatically for the front end and
	 * for its own Store API namespace, but not for a custom REST route: there,
	 * `WC()->session` and `WC()->cart` are null and the shopper looks like they
	 * have no checkout in progress. `wc_load_cart()` is WooCommerce's own entry
	 * point for exactly this case, and it adopts the existing session from the
	 * request cookies rather than starting a new one.
	 *
	 * @return void
	 */
	private static function ensure_cart_loaded() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) ) {
			return;
		}

		if ( WC()->cart instanceof WC_Cart && WC()->session ) {
			return;
		}

		wc_load_cart();
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
}
