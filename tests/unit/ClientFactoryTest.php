<?php
/**
 * @package WooCommerce Edge Payments Gateway
 */

namespace EdgePayments\EdgeWoocommerce\Tests\Unit;

use Edge\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WC_Edge_Client_Factory;

/**
 * @covers \WC_Edge_Client_Factory
 */
class ClientFactoryTest extends TestCase {

	private const SECRET      = 'ept_sandbox_sAbCdEfGhIjKlMnOpQrStUvWxYz0123456789';
	private const PUBLISHABLE = 'ept_sandbox_bAbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

	/** @var array<int,array> */
	private $history = array();

	protected function setUp(): void {
		parent::setUp();
		WC_Edge_Client_Factory::reset();
		$this->history = array();
	}

	protected function tearDown(): void {
		WC_Edge_Client_Factory::reset();
		parent::tearDown();
	}

	/**
	 * A publishable key is accepted by the API as a bearer token, but with
	 * different permissions - so the failure would surface much later and far
	 * from its cause. Refuse it at the boundary instead.
	 */
	public function test_refuses_to_authenticate_with_a_publishable_key(): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Client_Factory::configure( self::PUBLISHABLE );
	}

	public function test_refuses_garbage_keys(): void {
		$this->expectException( InvalidArgumentException::class );
		WC_Edge_Client_Factory::configure( 'not-a-key' );
	}

	public function test_defaults_to_the_v2_production_api(): void {
		$this->assertSame( 'https://api.tryedge.io/v2/', WC_Edge_Client_Factory::api_base_uri() );
		$this->assertSame( 'https://dashboard.tryedge.io', WC_Edge_Client_Factory::dashboard_host() );
	}

	/**
	 * @dataProvider provide_production_hosts
	 */
	public function test_recognises_production_hosts( string $uri, bool $expected ): void {
		$this->assertSame( $expected, WC_Edge_Client_Factory::is_production_host( $uri ) );
	}

	public function provide_production_hosts(): array {
		return array(
			'api'              => array( 'https://api.tryedge.io/v2/', true ),
			'dashboard'        => array( 'https://dashboard.tryedge.io', true ),
			'apex'             => array( 'https://tryedge.io', true ),
			'uppercase'        => array( 'https://API.TRYEDGE.IO/v2/', true ),
			'local dev'        => array( 'https://api.tryedge.test:4001/v2/', false ),
			'localhost'        => array( 'http://localhost:4001', false ),
			// Must not be fooled by a lookalike domain that merely ends in the
			// same characters.
			'suffix lookalike' => array( 'https://api.nottryedge.io', false ),
			'domain in path'   => array( 'https://evil.example/tryedge.io', false ),
			'unparseable'      => array( 'not a url', true ),
			'empty'            => array( '', true ),
		);
	}

	/**
	 * Without the opt-in constant, verification is always on.
	 */
	public function test_tls_verification_is_on_by_default(): void {
		$this->assertTrue( WC_Edge_Client_Factory::should_verify_tls() );
	}

	public function test_user_agent_identifies_the_integration(): void {
		$this->assertStringContainsString( 'EdgeWooCommerce/2.0.0', WC_Edge_Client_Factory::user_agent_suffix() );
	}

	/**
	 * The end-to-end check that matters: a configured client must emit a
	 * correctly shaped Edge v2 request. Asserts on the actual bytes.
	 */
	public function test_produces_a_correctly_shaped_v2_request(): void {
		WC_Edge_Client_Factory::configure( self::SECRET );
		$this->install_mock_transport( new Response( 201, array(), '{"data":{"id":"abc","type":"payment_demands"}}' ) );

		Client::create(
			'payment_demands',
			array(
				'data' => array(
					'type'       => 'payment_demands',
					'attributes' => array( 'amount_cents' => 2500 ),
				),
			)
		);

		$request = $this->history[0]['request'];

		$this->assertSame( 'POST', $request->getMethod() );
		$this->assertSame( 'https://api.tryedge.io/v2/payment_demands', (string) $request->getUri() );
		$this->assertSame( 'Bearer ' . self::SECRET, $request->getHeaderLine( 'Authorization' ) );
		$this->assertSame( 'application/vnd.api+json', $request->getHeaderLine( 'Accept' ) );
		$this->assertSame( 'application/vnd.api+json', $request->getHeaderLine( 'Content-Type' ) );
		$this->assertStringContainsString( 'EdgeWooCommerce/2.0.0', $request->getHeaderLine( 'User-Agent' ) );

		$body = json_decode( (string) $request->getBody(), true );
		$this->assertSame( 'payment_demands', $body['data']['type'] );
		$this->assertSame( 2500, $body['data']['attributes']['amount_cents'] );
	}

	/**
	 * Confirm targets PATCH .../confirm with a body carrying a matching id, and
	 * an object rather than an array for empty attributes.
	 */
	public function test_confirm_targets_the_confirm_route(): void {
		WC_Edge_Client_Factory::configure( self::SECRET );
		$this->install_mock_transport( new Response( 200, array(), '{"data":{"id":"abc","type":"payment_demands"}}' ) );

		Client::confirm( 'payment_demands', 'abc' );

		$request = $this->history[0]['request'];

		$this->assertSame( 'PATCH', $request->getMethod() );
		$this->assertSame( 'https://api.tryedge.io/v2/payment_demands/abc/confirm', (string) $request->getUri() );

		$raw = (string) $request->getBody();
		$this->assertStringContainsString( '"attributes":{}', $raw, 'empty attributes must encode as an object' );

		$body = json_decode( $raw, true );
		$this->assertSame( 'abc', $body['data']['id'] );
		$this->assertSame( 'payment_demands', $body['data']['type'] );
	}

	/**
	 * Guards the mitigation for the SDK having no timeout of its own: an
	 * unbounded request would hold a checkout open until PHP's limit.
	 */
	public function test_installs_timeouts_on_the_http_client(): void {
		WC_Edge_Client_Factory::configure( self::SECRET );
		$this->install_mock_transport( new Response( 200, array(), '{"data":[]}' ) );

		Client::get( 'customers' );

		$options = $this->history[0]['options'];

		$this->assertSame( WC_Edge_Client_Factory::CONNECT_TIMEOUT, $options['connect_timeout'] );
		$this->assertSame( WC_Edge_Client_Factory::TIMEOUT, $options['timeout'] );
		$this->assertTrue( $options['verify'], 'TLS verification must reach the transport' );
	}

	/**
	 * Swap in a recording transport while keeping the timeouts the factory set,
	 * so this asserts the factory's configuration rather than the test's.
	 *
	 * @param Response $response Canned response.
	 * @return void
	 */
	private function install_mock_transport( Response $response ) {
		$stack = HandlerStack::create( new MockHandler( array( $response ) ) );
		$stack->push( Middleware::history( $this->history ) );

		Client::setHttpClient(
			new GuzzleClient(
				array(
					'handler'         => $stack,
					'connect_timeout' => WC_Edge_Client_Factory::CONNECT_TIMEOUT,
					'timeout'         => WC_Edge_Client_Factory::TIMEOUT,
				)
			)
		);
	}
}
