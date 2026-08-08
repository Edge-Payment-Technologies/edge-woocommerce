<?php
/**
 * Configures the Edge PHP SDK for this request.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * The only place that touches the Edge SDK's global configuration.
 *
 * `Edge\Auth` and `Edge\Client` hold process-global static state: one API key,
 * one base URI and one HTTP client per PHP process. Funnelling every mutation
 * through this class keeps that from being set from several places with
 * different values within a single request.
 */
final class WC_Edge_Client_Factory {

	/**
	 * Hosted payment form origin, used by the browser SDK for the iframe.
	 *
	 * @var string
	 */
	const DEFAULT_DASHBOARD_HOST = 'https://dashboard.tryedge.io';

	/**
	 * Canonical URL of Edge's hosted browser SDK.
	 *
	 * Edge's developer page hands out a content-hashed path
	 * (`.../edge-<digest>.js?vsn=d`), which is cache-optimal but changes on every
	 * deploy. The previous version of this plugin hard-coded one such digest and
	 * broke when it moved. This undigested path serves a byte-identical file -
	 * its ETag is the digest - and survives redeploys, which matters more here
	 * than the cache headers on an 8 KB script.
	 *
	 * @var string
	 */
	const DEFAULT_BROWSER_SDK_URL = 'https://assets.tryedge.io/assets/js/edge.js';

	/**
	 * Suffix of Edge's production hostnames. TLS verification is never
	 * negotiable for these.
	 *
	 * @var string
	 */
	const PRODUCTION_HOST_SUFFIX = 'tryedge.io';

	/**
	 * Seconds to wait for a connection.
	 *
	 * @var int
	 */
	const CONNECT_TIMEOUT = 5;

	/**
	 * Seconds to wait for a complete response.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Whether the HTTP client has been installed this request.
	 *
	 * @var bool
	 */
	private static $http_client_installed = false;

	/**
	 * Point the SDK at Edge and authenticate it.
	 *
	 * Safe to call repeatedly; the SDK reads the API key per request, so
	 * re-configuring between calls is how mode switches take effect.
	 *
	 * @param string $secret_key An `ept_{live|sandbox}_s...` key.
	 * @return void
	 * @throws InvalidArgumentException When handed a key that is not a secret key.
	 */
	public static function configure( $secret_key ) {
		if ( ! WC_Edge_Mode::is_secret( $secret_key ) ) {
			// Never let a publishable key authenticate server-side calls: the API
			// accepts it as a bearer token but with different permissions, which
			// would fail confusingly and much later.
			throw new InvalidArgumentException( 'Edge API calls require a secret key.' );
		}

		self::install_http_client();

		\Edge\Auth::setApiKey( trim( $secret_key ) );
		\Edge\Client::setBaseUri( self::api_base_uri() );
		\Edge\Client::setUserAgentSuffix( self::user_agent_suffix() );
		\Edge\Client::setVerifySsl( self::should_verify_tls() );
	}

	/**
	 * Give the SDK an HTTP client with timeouts.
	 *
	 * The SDK builds `new GuzzleClient()` with no configuration, so it has no
	 * connect or read timeout: a hung Edge API would hold a checkout request
	 * open until PHP's own limit. `setHttpClient()` is the documented seam.
	 *
	 * @return void
	 */
	private static function install_http_client() {
		if ( self::$http_client_installed ) {
			return;
		}

		\Edge\Client::setHttpClient(
			new \GuzzleHttp\Client(
				array(
					'connect_timeout' => self::CONNECT_TIMEOUT,
					'timeout'         => self::TIMEOUT,
					'http_errors'     => true,
				)
			)
		);

		self::$http_client_installed = true;
	}

	/**
	 * The Edge API root.
	 *
	 * Overridable only through a constant, never through a stored setting. An
	 * admin-editable API host would send the merchant's secret key to an
	 * arbitrary origin and turn the gateway into an SSRF primitive.
	 *
	 * @return string
	 */
	public static function api_base_uri() {
		if ( defined( 'EDGE_API_BASE_URI' ) && is_string( EDGE_API_BASE_URI ) && '' !== EDGE_API_BASE_URI ) {
			return EDGE_API_BASE_URI;
		}

		return \Edge\Client::DEFAULT_BASE_URI;
	}

	/**
	 * The hosted payment form origin handed to the browser SDK.
	 *
	 * @return string
	 */
	public static function dashboard_host() {
		if ( defined( 'EDGE_DASHBOARD_HOST' ) && is_string( EDGE_DASHBOARD_HOST ) && '' !== EDGE_DASHBOARD_HOST ) {
			return untrailingslashit( EDGE_DASHBOARD_HOST );
		}

		return self::DEFAULT_DASHBOARD_HOST;
	}

	/**
	 * URL of the hosted browser SDK.
	 *
	 * Overridable for local development, where the SDK is served unminified from
	 * the dashboard host rather than the assets CDN.
	 *
	 * @return string
	 */
	public static function browser_sdk_url() {
		if ( defined( 'EDGE_BROWSER_SDK_URL' ) && is_string( EDGE_BROWSER_SDK_URL ) && '' !== EDGE_BROWSER_SDK_URL ) {
			return EDGE_BROWSER_SDK_URL;
		}

		return self::DEFAULT_BROWSER_SDK_URL;
	}

	/**
	 * Whether TLS certificates must be verified.
	 *
	 * Verification can only be waived for a non-production host, and only when a
	 * developer has explicitly opted in. A misconfigured constant can therefore
	 * never expose live credentials to an unverified connection.
	 *
	 * @return bool
	 */
	public static function should_verify_tls() {
		if ( ! defined( 'EDGE_DISABLE_TLS_VERIFY' ) || ! EDGE_DISABLE_TLS_VERIFY ) {
			return true;
		}

		return self::is_production_host( self::api_base_uri() );
	}

	/**
	 * Whether a URI points at Edge production infrastructure.
	 *
	 * @param string $uri Absolute URI.
	 * @return bool
	 */
	public static function is_production_host( $uri ) {
		$host = wp_parse_url( (string) $uri, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			// Unparseable means "assume production" - the safe direction.
			return true;
		}

		$host   = strtolower( $host );
		$suffix = '.' . self::PRODUCTION_HOST_SUFFIX;

		return self::PRODUCTION_HOST_SUFFIX === $host
			|| substr( $host, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Identify this integration to Edge.
	 *
	 * @return string
	 */
	public static function user_agent_suffix() {
		$parts = array( 'EdgeWooCommerce/' . ( defined( 'WC_EDGE_VERSION' ) ? WC_EDGE_VERSION : 'dev' ) );

		if ( defined( 'WC_VERSION' ) ) {
			$parts[] = 'WooCommerce/' . WC_VERSION;
		}

		if ( function_exists( 'get_bloginfo' ) ) {
			$parts[] = 'WordPress/' . get_bloginfo( 'version' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Reset memoised state. Test seam.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$http_client_installed = false;

		if ( class_exists( '\Edge\Client' ) ) {
			\Edge\Client::reset();
		}
	}
}
