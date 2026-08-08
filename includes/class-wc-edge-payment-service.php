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
	 * Revalidate the bound demand and confirm it.
	 *
	 * @param WC_Gateway_Edge $gateway   Gateway.
	 * @param WC_Order        $order     Order being paid.
	 * @param string          $submitted Demand id the browser sent, for cross-checking only.
	 * @return string|WP_Error The confirmed demand id.
	 */
	public static function confirm( WC_Gateway_Edge $gateway, WC_Order $order, $submitted ) {
		$bound = (string) $order->get_meta( '_edge_demand_id' );

		if ( '' === $bound ) {
			WC_Edge_Logger::error( 'Order ' . $order->get_id() . ' has no bound Edge demand.' );

			return new WP_Error( 'edge_no_binding', self::generic_failure() );
		}

		// The browser's value never selects the resource; it only has to agree
		// with the binding the server already made. A mismatch means the page and
		// the order have diverged.
		if ( '' !== (string) $submitted && ! hash_equals( $bound, (string) $submitted ) ) {
			WC_Edge_Logger::error( 'Submitted demand does not match the order binding on order ' . $order->get_id() );

			return new WP_Error(
				'edge_binding_mismatch',
				__( 'Your payment session no longer matches this order. Please reload and try again.', 'edge-gateway' )
			);
		}

		try {
			WC_Edge_Client_Factory::configure( $gateway->get_secret_key() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_not_configured', self::generic_failure() );
		}

		$demand = self::fetch_demand( $bound );

		if ( is_wp_error( $demand ) ) {
			return $demand;
		}

		$mismatch = self::revalidate( $demand, $order );

		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		return self::do_confirm( $bound );
	}

	/**
	 * Fetch a demand with its payment method included.
	 *
	 * @param string $demand_id Demand id.
	 * @return object|WP_Error
	 */
	private static function fetch_demand( $demand_id ) {
		try {
			return \Edge\Client::get(
				'payment_demands/' . rawurlencode( $demand_id ),
				array( 'include' => 'payment_method' )
			);
		} catch ( \Throwable $e ) {
			WC_Edge_Logger::error( 'Could not read demand ' . $demand_id . ': ' . $e->getMessage() );

			return new WP_Error( 'edge_demand_unreadable', self::generic_failure() );
		}
	}

	/**
	 * Check the remote resource still matches the order it is bound to.
	 *
	 * A UUID being well formed says nothing about ownership, so every fact that
	 * defines the payment is compared against the order before any money moves.
	 *
	 * @param object   $demand Demand document.
	 * @param WC_Order $order  Order.
	 * @return true|WP_Error
	 */
	private static function revalidate( $demand, WC_Order $order ) {
		$attributes = isset( $demand->data->attributes ) ? $demand->data->attributes : null;

		if ( ! $attributes ) {
			return new WP_Error( 'edge_demand_malformed', self::generic_failure() );
		}

		try {
			$expected_cents = WC_Edge_Money::to_cents( $order->get_total() );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'edge_amount_invalid', self::generic_failure() );
		}

		$actual_cents = isset( $attributes->amount_cents ) ? (int) $attributes->amount_cents : -1;

		if ( $actual_cents !== $expected_cents ) {
			WC_Edge_Logger::error(
				sprintf(
					'Demand amount %d does not match order %d total %d.',
					$actual_cents,
					$order->get_id(),
					$expected_cents
				)
			);

			return new WP_Error(
				'edge_amount_mismatch',
				__( 'Your order total changed. Please reload the checkout and try again.', 'edge-gateway' )
			);
		}

		$currency = isset( $attributes->amount_currency ) ? strtoupper( (string) $attributes->amount_currency ) : '';

		if ( $currency !== strtoupper( $order->get_currency() ) ) {
			return new WP_Error( 'edge_currency_mismatch', self::generic_failure() );
		}

		$attempt_key = (string) $order->get_meta( '_edge_attempt_key' );
		$remote_key  = isset( $attributes->idempotency_key ) ? (string) $attributes->idempotency_key : '';

		if ( '' !== $attempt_key && ! hash_equals( $attempt_key, $remote_key ) ) {
			return new WP_Error( 'edge_attempt_mismatch', self::generic_failure() );
		}

		// The hosted form is what attaches a payment method, and only a confirmed
		// one is chargeable. Without this the confirm would fail at Edge with a
		// 422 listing the 3DS fields the iframe was supposed to supply.
		if ( ! self::has_confirmed_payment_method( $demand ) ) {
			return new WP_Error(
				'edge_card_not_verified',
				__( 'Your card has not been verified yet. Please complete the card form and try again.', 'edge-gateway' )
			);
		}

		return true;
	}

	/**
	 * Whether a chargeable payment method is attached.
	 *
	 * @param object $demand Demand document with `payment_method` included.
	 * @return bool
	 */
	private static function has_confirmed_payment_method( $demand ) {
		$related = isset( $demand->data->relationships->payment_method->data->id )
			? (string) $demand->data->relationships->payment_method->data->id
			: '';

		if ( '' === $related ) {
			return false;
		}

		foreach ( (array) ( isset( $demand->included ) ? $demand->included : array() ) as $resource ) {
			if ( ! isset( $resource->type, $resource->id ) || 'payment_methods' !== $resource->type ) {
				continue;
			}

			if ( (string) $resource->id !== $related ) {
				continue;
			}

			return isset( $resource->attributes->external_state )
				&& 'confirmed' === $resource->attributes->external_state;
		}

		// Related but not returned: treat as unverified rather than assuming.
		return false;
	}

	/**
	 * Confirm, resolving an ambiguous response rather than retrying blindly.
	 *
	 * A transport failure or a 5xx does not mean the confirm did not happen, so
	 * the authoritative state is read back before deciding anything. Retrying a
	 * confirm that already succeeded would be a second charge.
	 *
	 * @param string $demand_id Demand id.
	 * @return string|WP_Error
	 */
	private static function do_confirm( $demand_id ) {
		try {
			\Edge\Client::confirm( 'payment_demands', $demand_id );

			return $demand_id;
		} catch ( \Edge\Exception $e ) {
			$status = $e->getStatusCode();

			if ( 422 === $status ) {
				WC_Edge_Logger::error( 'Confirm rejected for ' . $demand_id . ': ' . $e->getMessage() );

				return new WP_Error( 'edge_confirm_rejected', self::describe( $e ) );
			}

			// 0 is a transport failure; 405 means the state moved under us; 5xx
			// may have applied. All three are ambiguous until we look.
			if ( 0 === $status || 405 === $status || $status >= 500 ) {
				return self::resolve_ambiguous_confirm( $demand_id );
			}

			WC_Edge_Logger::error( 'Confirm failed for ' . $demand_id . ' (HTTP ' . $status . ')' );

			return new WP_Error( 'edge_confirm_failed', self::generic_failure() );
		} catch ( \Throwable $e ) {
			return self::resolve_ambiguous_confirm( $demand_id );
		}
	}

	/**
	 * Read the demand back and decide whether the confirm took effect.
	 *
	 * @param string $demand_id Demand id.
	 * @return string|WP_Error
	 */
	private static function resolve_ambiguous_confirm( $demand_id ) {
		$demand = self::fetch_demand( $demand_id );

		if ( is_wp_error( $demand ) ) {
			return new WP_Error( 'edge_confirm_unresolved', self::generic_failure() );
		}

		$state = isset( $demand->data->attributes->processor_state )
			? (string) $demand->data->attributes->processor_state
			: '';

		WC_Edge_Logger::info( 'Resolving ambiguous confirm for ' . $demand_id . '; state is ' . $state );

		// Already moving through the processor: the confirm landed.
		if ( in_array( $state, array( 'pending', 'processing', 'succeeded' ), true ) ) {
			return $demand_id;
		}

		// Still an unconfirmed intent, so nothing was applied. One retry only.
		if ( in_array( $state, array( 'incomplete', 'ready' ), true ) ) {
			try {
				\Edge\Client::confirm( 'payment_demands', $demand_id );

				return $demand_id;
			} catch ( \Throwable $e ) {
				WC_Edge_Logger::error( 'Confirm retry failed for ' . $demand_id . ': ' . $e->getMessage() );

				return new WP_Error( 'edge_confirm_failed', self::generic_failure() );
			}
		}

		if ( 'failed' === $state ) {
			return new WP_Error(
				'edge_payment_failed',
				__( 'Your payment was declined. Please try another card.', 'edge-gateway' )
			);
		}

		return new WP_Error( 'edge_confirm_unresolved', self::generic_failure() );
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
