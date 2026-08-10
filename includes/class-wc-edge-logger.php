<?php
/**
 * Logging with redaction.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to the WooCommerce log, with credentials stripped.
 *
 * Payment integrations accumulate log statements during debugging, and it is
 * easy for a key or a card token to end up in one. Everything goes through here
 * so redaction is not left to whoever wrote the call site.
 */
final class WC_Edge_Logger {

	/**
	 * Log channel.
	 *
	 * @var string
	 */
	const SOURCE = 'edge-payments';

	/**
	 * Record an informational message.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra detail.
	 * @return void
	 */
	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Record an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra detail.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Write a line.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 * @param array  $context Extra detail.
	 * @return void
	 */
	private static function log( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$line = self::redact( (string) $message );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact_deep( $context ) );
		}

		wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Remove anything that must never reach a log file.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function redact( $value ) {
		$value = (string) $value;

		// API keys, whichever mode or role.
		$value = preg_replace( '/ept_(live|sandbox)_[bs][A-Za-z0-9]+/', 'ept_$1_[redacted]', $value );

		// Anything that looks like a PAN, even though the server should never
		// see one - the point is that a mistake elsewhere does not become a
		// compliance problem here.
		$value = preg_replace( '/\b(?:\d[ -]?){13,19}\b/', '[redacted-pan]', $value );

		return $value;
	}

	/**
	 * Redact recursively, dropping keys that are sensitive by name.
	 *
	 * @param array $context Context data.
	 * @return array
	 */
	private static function redact_deep( array $context ) {
		$blocked = array(
			'secret_key',
			'publishable_key',
			'authorization',
			'x-hub-signature',
			'signature',
			'card_pan_token',
			'card_cvv_token',
			'number',
			'cvc',
		);

		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $blocked, true ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact_deep( $value );
				continue;
			}

			$clean[ $key ] = is_scalar( $value ) ? self::redact( (string) $value ) : '';
		}

		return $clean;
	}
}
