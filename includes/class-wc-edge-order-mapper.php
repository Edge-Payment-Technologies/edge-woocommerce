<?php
/**
 * Builds the JSON:API documents Edge expects.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Turns WooCommerce values into Edge v2 request documents.
 *
 * Pure: every method takes plain values and returns an array. Nothing here
 * touches WooCommerce or the network, so the exact bytes sent to Edge can be
 * asserted in a unit test.
 */
final class WC_Edge_Order_Mapper {

	/**
	 * Build a `customers` creation document.
	 *
	 * @param string $name  Customer name.
	 * @param string $email Customer email.
	 * @param string $phone Optional phone number.
	 * @return array
	 */
	public static function customer_document( $name, $email, $phone = '' ) {
		$attributes = array(
			'name'  => self::clean( $name ),
			'email' => self::clean( $email ),
		);

		$phone = self::clean( $phone );

		if ( '' !== $phone ) {
			$attributes['phone_number'] = $phone;
		}

		return array(
			'data' => array(
				'type'       => 'customers',
				'attributes' => $attributes,
			),
		);
	}

	/**
	 * Build a `consumer_addresses` creation document.
	 *
	 * @param array  $address     WooCommerce address parts.
	 * @param string $customer_id Edge customer id to attach to.
	 * @return array|WP_Error
	 */
	public static function address_document( array $address, $customer_id ) {
		$country = self::to_alpha3( isset( $address['country'] ) ? $address['country'] : '' );

		if ( is_wp_error( $country ) ) {
			return $country;
		}

		$attributes = array(
			'line_1'  => self::clean( isset( $address['address_1'] ) ? $address['address_1'] : '' ),
			'city'    => self::clean( isset( $address['city'] ) ? $address['city'] : '' ),
			'state'   => self::clean( isset( $address['state'] ) ? $address['state'] : '' ),
			'zip'     => self::clean( isset( $address['postcode'] ) ? $address['postcode'] : '' ),
			'country' => $country,
		);

		$line_2 = self::clean( isset( $address['address_2'] ) ? $address['address_2'] : '' );

		// The backend requires everything except line_2, so send it only when set
		// rather than as an empty string.
		if ( '' !== $line_2 ) {
			$attributes['line_2'] = $line_2;
		}

		foreach ( array( 'line_1', 'city', 'state', 'zip' ) as $required ) {
			if ( '' === $attributes[ $required ] ) {
				return new WP_Error(
					'edge_address_incomplete',
					__( 'Please complete your billing address before paying.', 'edge-gateway' ),
					array( 'field' => $required )
				);
			}
		}

		return array(
			'data' => array(
				'type'          => 'consumer_addresses',
				'attributes'    => $attributes,
				'relationships' => array(
					'customer' => self::identifier( 'customers', $customer_id ),
				),
			),
		);
	}

	/**
	 * Build an unconfirmed `payment_demands` creation document.
	 *
	 * Only `payer` and `billing_address` are sent. The API fills in `receiver`,
	 * `buyer` and `shipping_address` from those - confirmed against the running
	 * backend - so sending them again would only add ways to disagree.
	 *
	 * `payer_timezone` is deliberately absent: it is not required at create, and
	 * the hosted iframe sets it from the shopper's own browser during
	 * verification. Sending the site's timezone would overwrite a real value
	 * with a meaningless one.
	 *
	 * @param array $args amount_cents, currency, description, reference,
	 *                    idempotency_key, customer_id, billing_address_id, and
	 *                    optionally shipping_address_id.
	 * @return array
	 */
	public static function demand_document( array $args ) {
		$attributes = array(
			'confirmed'          => false,
			'amount_cents'       => (int) $args['amount_cents'],
			'amount_currency'    => strtoupper( (string) $args['currency'] ),
			'purchase_kind'      => 'order',
			'purchase_reference' => (string) $args['reference'],
			'idempotency_key'    => (string) $args['idempotency_key'],
		);

		if ( ! empty( $args['description'] ) ) {
			$attributes['description'] = self::clean( $args['description'] );
		}

		$relationships = array(
			'payer'           => self::identifier( 'customers', $args['customer_id'] ),
			'billing_address' => self::identifier( 'consumer_addresses', $args['billing_address_id'] ),
		);

		if ( ! empty( $args['shipping_address_id'] )
			&& $args['shipping_address_id'] !== $args['billing_address_id'] ) {
			$relationships['shipping_address'] = self::identifier(
				'consumer_addresses',
				$args['shipping_address_id']
			);
		}

		return array(
			'data' => array(
				'type'          => 'payment_demands',
				'attributes'    => $attributes,
				'relationships' => $relationships,
			),
		);
	}

	/**
	 * Build a JSON:API resource identifier object.
	 *
	 * The previous implementation sent `"type": "string"` here, which was never
	 * valid JSON:API.
	 *
	 * @param string $type Resource type.
	 * @param string $id   Resource id.
	 * @return array
	 */
	public static function identifier( $type, $id ) {
		return array(
			'data' => array(
				'type' => $type,
				'id'   => (string) $id,
			),
		);
	}

	/**
	 * Convert an ISO 3166-1 alpha-2 country to the alpha-3 Edge requires.
	 *
	 * @param string $alpha2 Two letter country code.
	 * @return string|WP_Error
	 */
	public static function to_alpha3( $alpha2 ) {
		$alpha2 = strtoupper( self::clean( $alpha2 ) );

		if ( '' === $alpha2 ) {
			return new WP_Error(
				'edge_country_missing',
				__( 'Please choose a billing country before paying.', 'edge-gateway' )
			);
		}

		// Already alpha-3.
		if ( 3 === strlen( $alpha2 ) ) {
			return $alpha2;
		}

		try {
			return \Edge\Helpers::convertAlpha2ToAlpha3( $alpha2 );
		} catch ( \Throwable $e ) {
			// An unknown country must surface as a checkout validation message,
			// not as an uncaught exception from a helper deep in the SDK.
			return new WP_Error(
				'edge_country_unsupported',
				__( 'That billing country is not supported for card payments.', 'edge-gateway' ),
				array( 'country' => $alpha2 )
			);
		}
	}

	/**
	 * Trim and collapse whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
