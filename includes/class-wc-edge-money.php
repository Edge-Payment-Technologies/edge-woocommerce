<?php
/**
 * Money conversion for the Edge API.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WC_EDGE_TESTING' ) ) {
	exit;
}

/**
 * Converts WooCommerce decimal amounts to the integer minor units Edge expects.
 *
 * The Edge API takes `amount_cents` as an integer. The obvious conversion,
 * `$total * 100`, is binary-float multiplication and is wrong for money.
 * `wc_add_number_precision()` is not an alternative: it is typed `?float` and
 * computes `$value * pow( 10, wc_get_price_decimals() )` internally.
 *
 * This class works entirely on the decimal string WooCommerce already stores,
 * so no value ever passes through a float.
 */
final class WC_Edge_Money {

	/**
	 * The only currency the Edge v2 API accepts.
	 *
	 * @var string
	 */
	const CURRENCY = 'USD';

	/**
	 * Smallest chargeable amount, in cents (`@minimum_charge_cents` in the backend).
	 *
	 * @var int
	 */
	const MINIMUM_CENTS = 10;

	/**
	 * Convert a decimal amount to integer cents.
	 *
	 * Floats are rejected on purpose. WooCommerce stores monetary values as
	 * decimal strings; a float argument means precision was already lost
	 * upstream, and silently accepting it would hide that.
	 *
	 * @param string|int $value Decimal amount, e.g. "25.00" or "19.995".
	 * @return int Amount in cents.
	 * @throws InvalidArgumentException When the value is not a usable decimal.
	 */
	public static function to_cents( $value ) {
		if ( is_float( $value ) ) {
			throw new InvalidArgumentException(
				'Edge money conversion requires a decimal string, not a float. Pass $order->get_total() directly.'
			);
		}

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Edge money conversion requires a decimal string.' );
		}

		$value = trim( $value );

		if ( '' === $value ) {
			throw new InvalidArgumentException( 'Edge money conversion received an empty value.' );
		}

		if ( ! preg_match( '/^(?<sign>[+-])?(?<whole>\d+)(?:\.(?<frac>\d+))?$/', $value, $matches ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Edge money conversion received a malformed decimal: "%s".', $value )
			);
		}

		$negative = isset( $matches['sign'] ) && '-' === $matches['sign'];
		$whole    = $matches['whole'];
		$fraction = isset( $matches['frac'] ) ? $matches['frac'] : '';

		list( $whole, $cents_fraction ) = self::round_fraction( $whole, $fraction );

		$cents = ( (int) $whole * 100 ) + $cents_fraction;

		return $negative ? -$cents : $cents;
	}

	/**
	 * Reduce a fractional part to two digits, rounding half away from zero.
	 *
	 * Rounding carries into the whole part when the fraction rounds up to 100,
	 * so "0.999" becomes 100 cents rather than 0 dollars and 100 cents.
	 *
	 * @param string $whole    Whole part, digits only.
	 * @param string $fraction Fractional part, digits only, may be empty.
	 * @return array{0:string,1:int} Adjusted whole part and cents.
	 */
	private static function round_fraction( $whole, $fraction ) {
		if ( strlen( $fraction ) <= 2 ) {
			return array( $whole, (int) str_pad( $fraction, 2, '0', STR_PAD_RIGHT ) );
		}

		$cents     = (int) substr( $fraction, 0, 2 );
		$next      = (int) $fraction[2];
		$round_up  = $next >= 5;

		if ( $round_up ) {
			++$cents;
		}

		if ( 100 === $cents ) {
			return array( (string) ( (int) $whole + 1 ), 0 );
		}

		return array( $whole, $cents );
	}

	/**
	 * Render cents back as a decimal string, for notes and reconciliation.
	 *
	 * @param int $cents Amount in cents.
	 * @return string Decimal string with two places, e.g. "25.00".
	 */
	public static function from_cents( $cents ) {
		$cents    = (int) $cents;
		$negative = $cents < 0;
		$cents    = abs( $cents );

		$decimal = sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 );

		return $negative ? '-' . $decimal : $decimal;
	}

	/**
	 * Whether Edge can process the given currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return bool
	 */
	public static function is_supported_currency( $currency ) {
		return self::CURRENCY === strtoupper( (string) $currency );
	}

	/**
	 * Whether the amount clears the Edge minimum charge.
	 *
	 * @param int $cents Amount in cents.
	 * @return bool
	 */
	public static function is_chargeable( $cents ) {
		return (int) $cents >= self::MINIMUM_CENTS;
	}
}
