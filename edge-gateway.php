<?php
/**
 * Plugin Name: Edge Payments Gateway
 * Plugin URI: https://github.com/Edge-Payment-Technologies/edge-woocommerce
 * Description: Adds the Edge Payments gateway to your WooCommerce website.
 * Version: 2.0.0
 *
 * Author: Edge Payments
 * Author URI: https://tryedge.io
 *
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 11.0
 * WC tested up to: 11.0
 *
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Text Domain: edge-gateway
 * Domain Path: /languages
 *
 * @package WooCommerce Edge Payments Gateway
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_EDGE_VERSION', '2.0.0' );
define( 'WC_EDGE_PLUGIN_FILE', __FILE__ );

/**
 * WC Edge Payment gateway plugin class.
 *
 * @class WC_Edge_Payments
 */
class WC_Edge_Payments {

	/**
	 * Plugin bootstrapping.
	 */
	public static function init() {
		// Composer dependencies must be present before anything else is registered.
		if ( ! self::load_dependencies() ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_dependencies_notice' ) );

			return;
		}

		// Edge Payments gateway class.
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );

		// Make the Edge Payments gateway available to WC.
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );

		// Registers WooCommerce Blocks integration.
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'woocommerce_gateway_edge_woocommerce_block_support' ) );
	}

	/**
	 * Load the Composer autoloader.
	 *
	 * `vendor/` is a build artifact and is not committed, so a checkout without
	 * `composer install` is a normal state rather than an exceptional one. Failing
	 * soft here keeps that from taking the whole site down.
	 *
	 * @return bool Whether the Edge SDK is available.
	 */
	private static function load_dependencies() {
		if ( class_exists( '\Edge\Client' ) ) {
			return true;
		}

		$autoload = self::plugin_abspath() . 'vendor/autoload.php';

		if ( ! is_readable( $autoload ) ) {
			return false;
		}

		require_once $autoload;

		return class_exists( '\Edge\Client' );
	}

	/**
	 * Tell the administrator why the gateway did not load.
	 */
	public static function render_missing_dependencies_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'Edge Payments Gateway could not start because its dependencies are missing. Run "composer install" in the plugin directory.',
			'edge-gateway'
		);
		echo '</p></div>';
	}

	/**
	 * Add the Edge Payment gateway to the list of available gateways.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {

		$gateways[] = 'WC_Gateway_Edge';

		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes() {

		$path = self::plugin_abspath() . 'includes/';

		require_once $path . 'class-wc-edge-money.php';
		require_once $path . 'class-wc-edge-mode.php';
		require_once $path . 'class-wc-edge-client-factory.php';

		self::maybe_upgrade_settings();

		// Make the WC_Gateway_Edge class available.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once $path . 'class-wc-gateway-edge.php';
		}
	}

	/**
	 * Collapse the old four-key settings into a single key pair.
	 *
	 * The previous layout stored separate sandbox and live pairs alongside a
	 * `testmode` flag. The new layout keeps one pair and derives the mode from
	 * the key prefix.
	 *
	 * The field name `publishable_key` existed before and meant "the live
	 * publishable key". Adopting it verbatim would silently promote a store that
	 * was running in test mode to live credentials, so the old flag decides which
	 * pair is carried over. Anything that does not migrate to a valid, matching
	 * pair is cleared and the gateway disabled: refusing to run is the safe
	 * direction when the intended mode is ambiguous.
	 *
	 * @return void
	 */
	private static function maybe_upgrade_settings() {
		$settings = get_option( 'woocommerce_edge_settings', array() );

		// `testmode` is the marker of the old layout.
		if ( ! is_array( $settings ) || ! array_key_exists( 'testmode', $settings ) ) {
			return;
		}

		// The old blocks class treated anything other than "no" as test mode.
		$was_sandbox = 'no' !== ( isset( $settings['testmode'] ) ? $settings['testmode'] : 'yes' );

		$publishable = $was_sandbox
			? ( isset( $settings['test_publishable_key'] ) ? $settings['test_publishable_key'] : '' )
			: ( isset( $settings['publishable_key'] ) ? $settings['publishable_key'] : '' );

		$secret = $was_sandbox
			? ( isset( $settings['test_private_key'] ) ? $settings['test_private_key'] : '' )
			: ( isset( $settings['private_key'] ) ? $settings['private_key'] : '' );

		unset(
			$settings['testmode'],
			$settings['test_publishable_key'],
			$settings['test_private_key'],
			$settings['private_key']
		);

		$settings['publishable_key'] = trim( (string) $publishable );
		$settings['secret_key']      = trim( (string) $secret );

		if ( null !== WC_Edge_Mode::validate_pair( $settings['secret_key'], $settings['publishable_key'] ) ) {
			$settings['publishable_key'] = '';
			$settings['secret_key']      = '';
			$settings['enabled']         = 'no';
		}

		update_option( 'woocommerce_edge_settings', $settings );
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin path.
	 *
	 * @return string
	 */
	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 */
	public static function woocommerce_gateway_edge_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once self::plugin_abspath() . 'includes/blocks/class-wc-edge-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_Gateway_Edge_Blocks_Support() );
				}
			);
		}
	}
}

WC_Edge_Payments::init();
