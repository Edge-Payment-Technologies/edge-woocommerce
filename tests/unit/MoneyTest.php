<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WC_Edge_Money;

/**
 * @covers \WC_Edge_Money
 */
class MoneyTest extends TestCase {

	/**
	 * @dataProvider provide_amounts
	 */
	public function test_converts_decimal_strings_to_cents( string $input, int $expected ): void {
		$this->assertSame( $expected, WC_Edge_Money::to_cents( $input ) );
	}

	public function provide_amounts(): array {
		return array(
			'whole dollars'          => array( '25.00', 2500 ),
			'no decimal point'       => array( '25', 2500 ),
			'one decimal place'      => array( '25.5', 2550 ),
			'typical price'          => array( '19.99', 1999 ),
			'zero'                   => array( '0.00', 0 ),
			'edge minimum'           => array( '0.10', 10 ),
			'sub-minimum'            => array( '0.09', 9 ),
			'leading zeros'          => array( '007.50', 750 ),
			'explicit plus'          => array( '+19.99', 1999 ),
			'large total'            => array( '123456.78', 12345678 ),
			'negative refund'        => array( '-19.99', -1999 ),
			'negative whole'         => array( '-5', -500 ),
		);
	}

	/**
	 * The classic `(float) $total * 100` bug: 19.99 * 100 is 1998.9999...
	 * in binary floating point, so an int cast yields 1998 rather than 1999.
	 */
	public function test_avoids_the_float_truncation_bug(): void {
		$this->assertSame( 1998, (int) ( (float) '19.99' * 100 ), 'guard: the float path really is wrong' );
		$this->assertSame( 1999, WC_Edge_Money::to_cents( '19.99' ) );
	}

	/**
	 * @dataProvider provide_rounding
	 */
	public function test_rounds_half_away_from_zero( string $input, int $expected ): void {
		$this->assertSame( $expected, WC_Edge_Money::to_cents( $input ) );
	}

	public function provide_rounding(): array {
		return array(
			'rounds down'            => array( '19.994', 1999 ),
			'rounds up at half'      => array( '19.995', 2000 ),
			'rounds up above half'   => array( '19.996', 2000 ),
			'carries into dollars'   => array( '0.999', 100 ),
			'carries repeatedly'     => array( '9.999', 1000 ),
			'negative rounds away'   => array( '-19.995', -2000 ),
			'long fraction'          => array( '1.23456789', 123 ),
		);
	}

	/**
	 * Floats are refused outright: WooCommerce stores money as decimal strings,
	 * so a float argument means precision was already lost upstream.
	 */
	public function test_rejects_floats(): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Money::to_cents( 19.99 );
	}

	/**
	 * @dataProvider provide_malformed
	 */
	public function test_rejects_malformed_values( $input ): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Money::to_cents( $input );
	}

	public function provide_malformed(): array {
		return array(
			'empty'            => array( '' ),
			'whitespace only'  => array( '   ' ),
			'not a number'     => array( 'free' ),
			'thousands comma'  => array( '1,234.00' ),
			'currency symbol'  => array( '$25.00' ),
			'trailing dot'     => array( '25.' ),
			'double dot'       => array( '2.5.0' ),
			'null'             => array( null ),
			'array'            => array( array() ),
			'scientific'       => array( '1e3' ),
		);
	}

	public function test_accepts_integers(): void {
		$this->assertSame( 2500, WC_Edge_Money::to_cents( 25 ) );
	}

	/**
	 * @dataProvider provide_round_trips
	 */
	public function test_from_cents_round_trips( string $decimal ): void {
		$cents = WC_Edge_Money::to_cents( $decimal );
		$this->assertSame( $cents, WC_Edge_Money::to_cents( WC_Edge_Money::from_cents( $cents ) ) );
	}

	public function provide_round_trips(): array {
		return array(
			array( '0.00' ),
			array( '0.05' ),
			array( '25.00' ),
			array( '19.99' ),
			array( '123456.78' ),
			array( '-19.99' ),
		);
	}

	public function test_from_cents_pads_correctly(): void {
		$this->assertSame( '0.05', WC_Edge_Money::from_cents( 5 ) );
		$this->assertSame( '0.50', WC_Edge_Money::from_cents( 50 ) );
		$this->assertSame( '25.00', WC_Edge_Money::from_cents( 2500 ) );
		$this->assertSame( '-19.99', WC_Edge_Money::from_cents( -1999 ) );
	}

	public function test_currency_support(): void {
		$this->assertTrue( WC_Edge_Money::is_supported_currency( 'USD' ) );
		$this->assertTrue( WC_Edge_Money::is_supported_currency( 'usd' ) );
		$this->assertFalse( WC_Edge_Money::is_supported_currency( 'EUR' ) );
		$this->assertFalse( WC_Edge_Money::is_supported_currency( '' ) );
	}

	public function test_minimum_charge(): void {
		$this->assertFalse( WC_Edge_Money::is_chargeable( 9 ) );
		$this->assertTrue( WC_Edge_Money::is_chargeable( 10 ) );
		$this->assertTrue( WC_Edge_Money::is_chargeable( 2500 ) );
	}
}
