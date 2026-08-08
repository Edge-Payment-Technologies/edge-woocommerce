<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WC_Edge_Mode;

/**
 * @covers \WC_Edge_Mode
 */
class ModeTest extends TestCase {

	/** A syntactically valid token body. Not a real credential. */
	private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

	private function key( string $mode, string $role ): string {
		return 'ept_' . $mode . '_' . $role . self::TOKEN;
	}

	public function test_parses_all_four_key_shapes(): void {
		$this->assertSame(
			array(
				'mode' => WC_Edge_Mode::MODE_LIVE,
				'role' => WC_Edge_Mode::ROLE_SECRET,
			),
			WC_Edge_Mode::parse( $this->key( 'live', 's' ) )
		);

		$this->assertSame(
			array(
				'mode' => WC_Edge_Mode::MODE_SANDBOX,
				'role' => WC_Edge_Mode::ROLE_PUBLISHABLE,
			),
			WC_Edge_Mode::parse( $this->key( 'sandbox', 'b' ) )
		);
	}

	public function test_derives_mode_from_the_key(): void {
		$this->assertSame( 'live', WC_Edge_Mode::mode_of( $this->key( 'live', 'b' ) ) );
		$this->assertSame( 'sandbox', WC_Edge_Mode::mode_of( $this->key( 'sandbox', 's' ) ) );
		$this->assertNull( WC_Edge_Mode::mode_of( 'not-a-key' ) );
	}

	public function test_tolerates_surrounding_whitespace(): void {
		$this->assertSame( 'live', WC_Edge_Mode::mode_of( '  ' . $this->key( 'live', 'b' ) . "\n" ) );
	}

	/**
	 * The critical guard: the Edge browser SDK's own regex is
	 * `/^ept_(sandbox|live)_(\w+)$/`, which happily accepts a *secret* key.
	 * Ours must not, or a secret could reach the browser.
	 */
	public function test_secret_key_is_never_publishable(): void {
		$secret = $this->key( 'live', 's' );

		$this->assertTrue( WC_Edge_Mode::is_secret( $secret ) );
		$this->assertFalse( WC_Edge_Mode::is_publishable( $secret ) );

		$this->assertSame(
			1,
			preg_match( '/^ept_(sandbox|live)_(\w+)$/', $secret ),
			'guard: the browser SDK regex really does accept this'
		);
	}

	public function test_publishable_key_is_never_secret(): void {
		$publishable = $this->key( 'sandbox', 'b' );

		$this->assertTrue( WC_Edge_Mode::is_publishable( $publishable ) );
		$this->assertFalse( WC_Edge_Mode::is_secret( $publishable ) );
	}

	/**
	 * @dataProvider provide_malformed_keys
	 */
	public function test_rejects_malformed_keys( $key ): void {
		$this->assertNull( WC_Edge_Mode::parse( $key ) );
	}

	public function provide_malformed_keys(): array {
		return array(
			'empty'            => array( '' ),
			'null'             => array( null ),
			'array'            => array( array() ),
			'no prefix'        => array( self::TOKEN ),
			'wrong vendor'     => array( 'sk_live_s' . self::TOKEN ),
			'unknown mode'     => array( 'ept_staging_s' . self::TOKEN ),
			'unknown role'     => array( 'ept_live_x' . self::TOKEN ),
			'missing role'     => array( 'ept_live_' . self::TOKEN ),
			'truncated token'  => array( 'ept_live_sABC' ),
			'illegal chars'    => array( 'ept_live_s' . self::TOKEN . '!!' ),
			'internal spaces'  => array( 'ept_live_s ' . self::TOKEN ),
			'uppercase prefix' => array( 'EPT_LIVE_S' . self::TOKEN ),
		);
	}

	public function test_accepts_a_valid_pair(): void {
		$this->assertNull(
			WC_Edge_Mode::validate_pair( $this->key( 'sandbox', 's' ), $this->key( 'sandbox', 'b' ) )
		);
	}

	public function test_rejects_mode_mismatch(): void {
		$this->assertSame(
			'mode_mismatch',
			WC_Edge_Mode::validate_pair( $this->key( 'live', 's' ), $this->key( 'sandbox', 'b' ) )
		);
	}

	/**
	 * Swapped fields are the dangerous misconfiguration: it would put a secret
	 * key where the browser can read it.
	 */
	public function test_rejects_swapped_roles(): void {
		$this->assertSame(
			'secret_field_holds_publishable_key',
			WC_Edge_Mode::validate_pair( $this->key( 'live', 'b' ), $this->key( 'live', 'b' ) )
		);

		$this->assertSame(
			'publishable_field_holds_secret_key',
			WC_Edge_Mode::validate_pair( $this->key( 'live', 's' ), $this->key( 'live', 's' ) )
		);
	}

	/**
	 * @dataProvider provide_invalid_pairs
	 */
	public function test_reports_pair_problems( $secret, $publishable, string $expected ): void {
		$this->assertSame( $expected, WC_Edge_Mode::validate_pair( $secret, $publishable ) );
	}

	public function provide_invalid_pairs(): array {
		$secret      = 'ept_live_s' . self::TOKEN;
		$publishable = 'ept_live_b' . self::TOKEN;

		return array(
			'no secret'          => array( '', $publishable, 'missing_secret_key' ),
			'blank secret'       => array( '   ', $publishable, 'missing_secret_key' ),
			'no publishable'     => array( $secret, '', 'missing_publishable_key' ),
			'garbage secret'     => array( 'nonsense', $publishable, 'malformed_secret_key' ),
			'garbage publishable' => array( $secret, 'nonsense', 'malformed_publishable_key' ),
		);
	}
}
