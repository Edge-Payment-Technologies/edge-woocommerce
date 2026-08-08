<?php
/**
 * PHPUnit bootstrap.
 *
 * These are plain unit tests: no WordPress is loaded. Classes under test are
 * therefore written to avoid WordPress functions, or to have those seams
 * injected. `WC_EDGE_TESTING` stands in for the `ABSPATH` direct-access guard.
 *
 * A few WordPress helpers are shimmed below. They are deliberately the real
 * implementations rather than mocks, so a test failure means our code is wrong
 * rather than the stub being wrong.
 *
 * @package WooCommerce Edge Payments Gateway
 */

define( 'WC_EDGE_TESTING', true );
define( 'WC_EDGE_VERSION', '2.0.0' );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL to parse.
	 * @param int    $component Component to retrieve.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Value to trim.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data to encode.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

require_once __DIR__ . '/../includes/class-wc-edge-money.php';
require_once __DIR__ . '/../includes/class-wc-edge-mode.php';
require_once __DIR__ . '/../includes/class-wc-edge-client-factory.php';
require_once __DIR__ . '/../includes/class-wc-edge-fingerprint.php';
