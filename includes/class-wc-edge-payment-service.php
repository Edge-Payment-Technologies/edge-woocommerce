<?php
/**
 * Creates and binds the Edge resources a checkout needs.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares a payment demand for the hosted form to mount against.
 *
 * Orchestration only: documents come from WC_Edge_Order_Mapper, the fingerprint
 * from WC_Edge_Fingerprint, persistence from WC_Edge_Attempt_Store.
 */
final class WC_Edge_Payment_Service {

	/**
	 * Prepare a demand for the current cart.
	 *
	 * @param WC_Gateway_Edge $gateway Configured gateway.
	 * @return array|WP_Error `array{demand_id:string, attempt_key:string}`.
	 */
	public static function prepare( WC_Gateway_Edge $gateway ) {
		$facts = self::collect_facts( $gateway );

		if ( is_wp_error( $facts ) ) {
			return $facts;
		}

		$claim = WC_Edge_Attempt_Store::claim(
			$facts['session_key'],
			WC_Edge_Fingerprint::of( $facts ),
			array(
				'mode'         => $facts['mode'],
				'amount_cents' => $facts['amount_cents'],
				'currency'     => $facts['currency'],
			)
		);

		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		$attempt = $claim['attempt'];

		// Already complete: the same facts always map to the same demand, so
		// refreshes and remounts reuse it rather than creating another.
		if ( ! empty( $attempt->demand_id ) ) {
			return array(
				'demand_id'   => $attempt->demand_id,
				'attempt_key' => $attempt->attempt_key,
			);
		}

		try {
			WC_Edge_Client_Factory::configure( $gateway->get_secret_key() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		$built = self::build_resources( $attempt, $facts );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		WC_Edge_Attempt_Store::update(
			$attempt->attempt_key,
			array( 'status' => WC_Edge_Attempt_Store::STATUS_PREPARED )
		);

		// A demand left over from superseded facts must not be mountable.
		WC_Edge_Attempt_Store::supersede_others( $facts['session_key'], $attempt->attempt_key );

		return array(
			'demand_id'   => $built['demand_id'],
			'attempt_key' => $attempt->attempt_key,
		);
	}

	/**
	 * Create whatever the attempt is still missing.
	 *
	 * Each id is persisted the moment it is known, so a retry after a lost
	 * response resumes from the first gap instead of creating a second customer
	 * or address. Only payment_demands carries an idempotency key; customers and
	 * consumer_addresses do not, so this is the only protection they get.
	 *
	 * @param object $attempt Attempt row.
	 * @param array  $facts   Collected facts.
	 * @return array|WP_Error
	 */
	private static function build_resources( $attempt, array $facts ) {
		$customer_id = $attempt->customer_id;

		if ( empty( $customer_id ) ) {
			$created = self::create(
				'customers',
				WC_Edge_Order_Mapper::customer_document(
					trim( $facts['billing_first_name'] . ' ' . $facts['billing_last_name'] ),
					$facts['billing_email'],
					$facts['billing_phone']
				)
			);

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$customer_id = $created;
			WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'customer_id' => $customer_id ) );
		}

		$billing_id = $attempt->billing_address_id;

		if ( empty( $billing_id ) ) {
			$document = WC_Edge_Order_Mapper::address_document( $facts['billing'], $customer_id );

			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$created = self::create( 'consumer_addresses', $document );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$billing_id = $created;
			WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'billing_address_id' => $billing_id ) );
		}

		$shipping_id = $attempt->shipping_address_id;

		if ( empty( $shipping_id ) && $facts['has_distinct_shipping'] ) {
			$document = WC_Edge_Order_Mapper::address_document( $facts['shipping'], $customer_id );

			// A shipping address we cannot map is not worth failing checkout
			// over: the API falls back to billing, which is what a
			// non-shippable order would use anyway.
			if ( ! is_wp_error( $document ) ) {
				$created = self::create( 'consumer_addresses', $document );

				if ( ! is_wp_error( $created ) ) {
					$shipping_id = $created;
					WC_Edge_Attempt_Store::update(
						$attempt->attempt_key,
						array( 'shipping_address_id' => $shipping_id )
					);
				}
			}
		}

		$demand_id = self::create(
			'payment_demands',
			WC_Edge_Order_Mapper::demand_document(
				array(
					'amount_cents'        => $facts['amount_cents'],
					'currency'            => $facts['currency'],
					'description'         => $facts['description'],
					'reference'           => $attempt->attempt_key,
					'idempotency_key'     => $attempt->attempt_key,
					'customer_id'         => $customer_id,
					'billing_address_id'  => $billing_id,
					'shipping_address_id' => $shipping_id,
				)
			)
		);

		if ( is_wp_error( $demand_id ) ) {
			return $demand_id;
		}

		// The attempt key *is* the idempotency key - see demand_document() above -
		// so there is nothing else to store. Keeping a second copy would only
		// create a way for the two to disagree.
		WC_Edge_Attempt_Store::update( $attempt->attempt_key, array( 'demand_id' => $demand_id ) );

		return array( 'demand_id' => $demand_id );
	}

	/**
	 * POST a document and return the new resource id.
	 *
	 * @param string $endpoint Resource endpoint.
	 * @param array  $document JSON:API document.
	 * @return string|WP_Error
	 */
	private static function create( $endpoint, array $document ) {
		try {
			$response = \Edge\Client::create( $endpoint, $document );
		} catch ( \Edge\Exception $e ) {
			WC_Edge_Logger::error(
				sprintf( 'Creating %s failed (HTTP %d): %s', $endpoint, $e->getStatusCode(), $e->getMessage() )
			);

			return new WP_Error( 'edge_request_failed', self::describe( $e ) );
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( sprintf( 'Creating %s errored: %s', $endpoint, $e->getMessage() ) );

			return new WP_Error( 'edge_request_failed', self::generic_failure() );
		}

		if ( empty( $response->data->id ) ) {
			return new WP_Error( 'edge_response_malformed', self::generic_failure() );
		}

		return (string) $response->data->id;
	}

	/**
	 * Turn an Edge validation failure into something a shopper can act on.
	 *
	 * Edge\Exception::getMessage() returns the first error's `title`, which for a
	 * changeset failure is bare text like "can't be blank" - true but useless
	 * without knowing which field. The pointer carries that, so the message is
	 * built from it instead.
	 *
	 * @param \Edge\Exception $e Exception.
	 * @return string
	 */
	private static function describe( \Edge\Exception $e ) {
		if ( 422 !== $e->getStatusCode() ) {
			return self::generic_failure();
		}

		$fields = array();

		foreach ( (array) $e->getErrors() as $error ) {
			$pointer = isset( $error['source']['pointer'] ) ? $error['source']['pointer'] : '';
			$field   = self::field_from_pointer( $pointer );

			if ( '' !== $field ) {
				$fields[ $field ] = true;
			}
		}

		if ( empty( $fields ) ) {
			return self::generic_failure();
		}

		return sprintf(
			/* translators: %s: comma separated list of checkout field names. */
			__( 'Please check these details and try again: %s.', 'edge-gateway' ),
			implode( ', ', array_keys( $fields ) )
		);
	}

	/**
	 * Map a JSON:API pointer to a shopper-facing field name.
	 *
	 * @param string $pointer e.g. /data/attributes/amount_cents.
	 * @return string
	 */
	private static function field_from_pointer( $pointer ) {
		$known = array(
			'line_1'          => __( 'address', 'edge-gateway' ),
			'line_2'          => __( 'address', 'edge-gateway' ),
			'city'            => __( 'town or city', 'edge-gateway' ),
			'state'           => __( 'state or county', 'edge-gateway' ),
			'zip'             => __( 'postcode', 'edge-gateway' ),
			'country'         => __( 'country', 'edge-gateway' ),
			'email'           => __( 'email address', 'edge-gateway' ),
			'name'            => __( 'name', 'edge-gateway' ),
			'phone_number'    => __( 'phone number', 'edge-gateway' ),
			'amount_cents'    => __( 'order total', 'edge-gateway' ),
			'amount_currency' => __( 'currency', 'edge-gateway' ),
		);

		$leaf = basename( (string) $pointer );

		return isset( $known[ $leaf ] ) ? $known[ $leaf ] : '';
	}

	/**
	 * Copy for failures a shopper cannot diagnose.
	 *
	 * @return string
	 */
	private static function generic_failure() {
		return __( 'We could not start your card payment. Please try again in a moment.', 'edge-gateway' );
	}

	/**
	 * Read every fact the payment depends on, server-side.
	 *
	 * Nothing here comes from the request body: the browser is not trusted with
	 * the amount, the currency, the mode, or the cart.
	 *
	 * @param WC_Gateway_Edge $gateway Gateway.
	 * @return array|WP_Error
	 */
	private static function collect_facts( WC_Gateway_Edge $gateway ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return new WP_Error( 'edge_cart_empty', __( 'Your cart is empty.', 'edge-gateway' ) );
		}

		if ( ! WC()->session || ! WC()->session->get_customer_id() ) {
			return new WP_Error( 'edge_no_session', self::generic_failure() );
		}

		$currency = get_woocommerce_currency();

		if ( ! WC_Edge_Money::is_supported_currency( $currency ) ) {
			return new WP_Error(
				'edge_currency_unsupported',
				__( 'Card payments are only available for orders in US dollars.', 'edge-gateway' )
			);
		}

		try {
			$amount_cents = WC_Edge_Money::to_cents( WC()->cart->get_total( 'edit' ) );
		} catch ( InvalidArgumentException $e ) {
			WC_Edge_Logger::error( 'Cart total could not be converted: ' . $e->getMessage() );

			return new WP_Error( 'edge_amount_invalid', self::generic_failure() );
		}

		if ( ! WC_Edge_Money::is_chargeable( $amount_cents ) ) {
			return new WP_Error(
				'edge_amount_too_small',
				__( 'This order is below the minimum for card payments.', 'edge-gateway' )
			);
		}

		$customer = WC()->customer;

		$billing = array(
			'address_1' => $customer->get_billing_address_1(),
			'address_2' => $customer->get_billing_address_2(),
			'city'      => $customer->get_billing_city(),
			'state'     => $customer->get_billing_state(),
			'postcode'  => $customer->get_billing_postcode(),
			'country'   => $customer->get_billing_country(),
		);

		$shipping = array(
			'address_1' => $customer->get_shipping_address_1(),
			'address_2' => $customer->get_shipping_address_2(),
			'city'      => $customer->get_shipping_city(),
			'state'     => $customer->get_shipping_state(),
			'postcode'  => $customer->get_shipping_postcode(),
			'country'   => $customer->get_shipping_country(),
		);

		$email = $customer->get_billing_email();

		if ( '' === trim( (string) $email ) ) {
			return new WP_Error(
				'edge_email_required',
				__( 'Please enter your email address before paying.', 'edge-gateway' )
			);
		}

		return array(
			// Fingerprint inputs.
			'cart_hash'           => WC()->cart->get_cart_hash(),
			'amount_cents'        => $amount_cents,
			'currency'            => $currency,
			'mode'                => (string) $gateway->get_mode(),
			'publishable_key'     => $gateway->get_publishable_key(),
			'billing_first_name'  => $customer->get_billing_first_name(),
			'billing_last_name'   => $customer->get_billing_last_name(),
			'billing_email'       => $email,
			'billing_phone'       => $customer->get_billing_phone(),
			'billing_address_1'   => $billing['address_1'],
			'billing_address_2'   => $billing['address_2'],
			'billing_city'        => $billing['city'],
			'billing_state'       => $billing['state'],
			'billing_postcode'    => $billing['postcode'],
			'billing_country'     => $billing['country'],
			'shipping_first_name' => $customer->get_shipping_first_name(),
			'shipping_last_name'  => $customer->get_shipping_last_name(),
			'shipping_address_1'  => $shipping['address_1'],
			'shipping_address_2'  => $shipping['address_2'],
			'shipping_city'       => $shipping['city'],
			'shipping_state'      => $shipping['state'],
			'shipping_postcode'   => $shipping['postcode'],
			'shipping_country'    => $shipping['country'],

			// Working values.
			'session_key'           => (string) WC()->session->get_customer_id(),
			'billing'               => $billing,
			'shipping'              => $shipping,
			'has_distinct_shipping' => WC()->cart->needs_shipping()
				&& '' !== trim( (string) $shipping['address_1'] )
				&& $shipping !== $billing,
			'description'           => sprintf(
				/* translators: %s: site name. */
				__( '%s order', 'edge-gateway' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
		);
	}
}
