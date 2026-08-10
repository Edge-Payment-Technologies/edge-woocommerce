<?php
/**
 * Edge API key parsing and live/sandbox mode derivation.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Understands the shape of an Edge API key.
 *
 * Keys look like `ept_{live|sandbox}_{b|s}{token}`, where `b` is a publishable
 * (browser) key and `s` is a secret (confidential) one. The mode is part of the
 * key, and on the Edge side it selects the database schema, so live and sandbox
 * data are completely partitioned.
 *
 * The gateway therefore derives mode from the key rather than storing a separate
 * "test mode" flag, which could disagree with the key it is used alongside.
 *
 * Returns error *codes* rather than WP_Error or translated strings so the class
 * stays free of WordPress and can be unit tested directly.
 */
final class WC_Edge_Mode {

	const MODE_LIVE    = 'live';
	const MODE_SANDBOX = 'sandbox';

	const ROLE_SECRET      = 'secret';
	const ROLE_PUBLISHABLE = 'publishable';

	/**
	 * Shortest credible token body. Guards against truncated pastes.
	 *
	 * @var int
	 */
	const MIN_TOKEN_LENGTH = 20;

	/**
	 * Split a key into its mode and role.
	 *
	 * @param string $key Candidate API key.
	 * @return array|null `array{mode:string, role:string}`, or null if malformed.
	 */
	public static function parse( $key ) {
		if ( ! is_string( $key ) ) {
			return null;
		}

		$key = trim( $key );

		$pattern = '/^ept_(?<mode>live|sandbox)_(?<role>[bs])(?<token>[A-Za-z0-9]{' . self::MIN_TOKEN_LENGTH . ',})$/';

		if ( ! preg_match( $pattern, $key, $matches ) ) {
			return null;
		}

		return array(
			'mode' => $matches['mode'],
			'role' => 's' === $matches['role'] ? self::ROLE_SECRET : self::ROLE_PUBLISHABLE,
		);
	}

	/**
	 * The mode a key belongs to.
	 *
	 * @param string $key Candidate API key.
	 * @return string|null "live", "sandbox", or null if malformed.
	 */
	public static function mode_of( $key ) {
		$parsed = self::parse( $key );

		return $parsed ? $parsed['mode'] : null;
	}

	/**
	 * Whether the key is a secret (server-only) key.
	 *
	 * @param string $key Candidate API key.
	 * @return bool
	 */
	public static function is_secret( $key ) {
		$parsed = self::parse( $key );

		return $parsed && self::ROLE_SECRET === $parsed['role'];
	}

	/**
	 * Whether the key is a publishable (browser-safe) key.
	 *
	 * The Edge browser SDK's own check is `/^ept_(sandbox|live)_(\w+)$/`, which
	 * also accepts a secret key. This one does not, so a secret key can never be
	 * handed to the browser by mistake.
	 *
	 * @param string $key Candidate API key.
	 * @return bool
	 */
	public static function is_publishable( $key ) {
		$parsed = self::parse( $key );

		return $parsed && self::ROLE_PUBLISHABLE === $parsed['role'];
	}

	/**
	 * Validate that a secret and publishable key form a usable pair.
	 *
	 * @param string $secret      Secret key.
	 * @param string $publishable Publishable key.
	 * @return string|null Error code, or null when the pair is valid.
	 */
	public static function validate_pair( $secret, $publishable ) {
		if ( ! is_string( $secret ) || '' === trim( (string) $secret ) ) {
			return 'missing_secret_key';
		}

		if ( ! is_string( $publishable ) || '' === trim( (string) $publishable ) ) {
			return 'missing_publishable_key';
		}

		$parsed_secret      = self::parse( $secret );
		$parsed_publishable = self::parse( $publishable );

		if ( null === $parsed_secret ) {
			return 'malformed_secret_key';
		}

		if ( null === $parsed_publishable ) {
			return 'malformed_publishable_key';
		}

		if ( self::ROLE_SECRET !== $parsed_secret['role'] ) {
			return 'secret_field_holds_publishable_key';
		}

		if ( self::ROLE_PUBLISHABLE !== $parsed_publishable['role'] ) {
			return 'publishable_field_holds_secret_key';
		}

		if ( $parsed_secret['mode'] !== $parsed_publishable['mode'] ) {
			return 'mode_mismatch';
		}

		return null;
	}
}
