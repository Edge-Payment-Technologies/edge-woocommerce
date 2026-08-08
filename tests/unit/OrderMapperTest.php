<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Order_Mapper;
use WP_Error;

/**
 * @covers \WC_Edge_Order_Mapper
 */
class OrderMapperTest extends TestCase {

	private function address(): array {
		return array(
			'address_1' => '1 Test Street',
			'address_2' => '',
			'city'      => 'Portland',
			'state'     => 'OR',
			'postcode'  => '97205',
			'country'   => 'US',
		);
	}

	public function test_customer_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::customer_document( 'Ada Lovelace', 'ada@example.test' );

		$this->assertSame( 'customers', $doc['data']['type'] );
		$this->assertSame( 'Ada Lovelace', $doc['data']['attributes']['name'] );
		$this->assertSame( 'ada@example.test', $doc['data']['attributes']['email'] );
		$this->assertArrayNotHasKey( 'phone_number', $doc['data']['attributes'] );
	}

	public function test_customer_document_includes_phone_when_given(): void {
		$doc = WC_Edge_Order_Mapper::customer_document( 'Ada', 'ada@example.test', '+15035550100' );
		$this->assertSame( '+15035550100', $doc['data']['attributes']['phone_number'] );
	}

	public function test_address_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::address_document( $this->address(), 'cust-1' );

		$this->assertSame( 'consumer_addresses', $doc['data']['type'] );
		$this->assertSame( '1 Test Street', $doc['data']['attributes']['line_1'] );
		$this->assertSame( '97205', $doc['data']['attributes']['zip'] );

		// Edge wants alpha-3; WooCommerce stores alpha-2.
		$this->assertSame( 'USA', $doc['data']['attributes']['country'] );

		// line_2 is the one optional field; omit rather than send empty.
		$this->assertArrayNotHasKey( 'line_2', $doc['data']['attributes'] );

		$this->assertSame(
			array( 'data' => array( 'type' => 'customers', 'id' => 'cust-1' ) ),
			$doc['data']['relationships']['customer']
		);
	}

	public function test_address_document_includes_line_2_when_present(): void {
		$address              = $this->address();
		$address['address_2'] = 'Apt 4';

		$doc = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );
		$this->assertSame( 'Apt 4', $doc['data']['attributes']['line_2'] );
	}

	/**
	 * @dataProvider provide_missing_required_fields
	 */
	public function test_address_document_rejects_incomplete_addresses( string $field ): void {
		$address           = $this->address();
		$address[ $field ] = '';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_address_incomplete', $result->get_error_code() );
	}

	public function provide_missing_required_fields(): array {
		return array(
			array( 'address_1' ),
			array( 'city' ),
			array( 'state' ),
			array( 'postcode' ),
		);
	}

	/**
	 * An unknown country must become a checkout validation message, not an
	 * uncaught exception out of the SDK helper.
	 */
	public function test_unknown_country_becomes_an_error(): void {
		$address            = $this->address();
		$address['country'] = 'ZZ';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_country_unsupported', $result->get_error_code() );
	}

	public function test_missing_country_becomes_an_error(): void {
		$address            = $this->address();
		$address['country'] = '';

		$result = WC_Edge_Order_Mapper::address_document( $address, 'cust-1' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edge_country_missing', $result->get_error_code() );
	}

	/**
	 * @dataProvider provide_country_conversions
	 */
	public function test_country_conversion( string $input, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_Order_Mapper::to_alpha3( $input ) );
	}

	public function provide_country_conversions(): array {
		return array(
			'US'              => array( 'US', 'USA' ),
			'GB'              => array( 'GB', 'GBR' ),
			'CA'              => array( 'CA', 'CAN' ),
			'lowercase'       => array( 'us', 'USA' ),
			'already alpha-3' => array( 'USA', 'USA' ),
		);
	}

	public function test_demand_document_shape(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'usd',
				'description'         => 'Order 123',
				'reference'           => 'wc-123',
				'idempotency_key'     => 'attempt-abc',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
			)
		);

		$attributes = $doc['data']['attributes'];

		$this->assertSame( 'payment_demands', $doc['data']['type'] );
		$this->assertFalse( $attributes['confirmed'], 'must create an intent, never a confirmed demand' );
		$this->assertSame( 2500, $attributes['amount_cents'] );
		$this->assertSame( 'USD', $attributes['amount_currency'] );
		$this->assertSame( 'order', $attributes['purchase_kind'] );
		$this->assertSame( 'wc-123', $attributes['purchase_reference'] );
		$this->assertSame( 'attempt-abc', $attributes['idempotency_key'] );

		// The iframe supplies this from the shopper's browser during
		// verification; sending the site timezone would overwrite a real value.
		$this->assertArrayNotHasKey( 'payer_timezone', $attributes );

		// The API back-fills these from payer and billing_address.
		$this->assertSame( array( 'payer', 'billing_address' ), array_keys( $doc['data']['relationships'] ) );
	}

	/**
	 * The identifier type was literally "string" in the previous version, which
	 * was never valid JSON:API.
	 */
	public function test_relationships_carry_real_resource_types(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'       => 2500,
				'currency'           => 'USD',
				'reference'          => 'wc-1',
				'idempotency_key'    => 'k',
				'customer_id'        => 'cust-1',
				'billing_address_id' => 'addr-1',
			)
		);

		$this->assertSame( 'customers', $doc['data']['relationships']['payer']['data']['type'] );
		$this->assertSame( 'consumer_addresses', $doc['data']['relationships']['billing_address']['data']['type'] );
	}

	public function test_distinct_shipping_address_is_sent(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'USD',
				'reference'           => 'wc-1',
				'idempotency_key'     => 'k',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
				'shipping_address_id' => 'addr-2',
			)
		);

		$this->assertSame( 'addr-2', $doc['data']['relationships']['shipping_address']['data']['id'] );
	}

	/**
	 * When shipping equals billing, let the API default it rather than sending
	 * the same id twice.
	 */
	public function test_identical_shipping_address_is_omitted(): void {
		$doc = WC_Edge_Order_Mapper::demand_document(
			array(
				'amount_cents'        => 2500,
				'currency'            => 'USD',
				'reference'           => 'wc-1',
				'idempotency_key'     => 'k',
				'customer_id'         => 'cust-1',
				'billing_address_id'  => 'addr-1',
				'shipping_address_id' => 'addr-1',
			)
		);

		$this->assertArrayNotHasKey( 'shipping_address', $doc['data']['relationships'] );
	}
}
