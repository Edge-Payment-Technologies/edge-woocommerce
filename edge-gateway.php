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
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
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

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );

		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Declare High-Performance Order Storage compatibility.
	 *
	 * Claimed only because it has been exercised: the gateway reads and writes
	 * order data exclusively through the CRUD API, and complete payments -
	 * binding, meta, status transitions and the webhook handler's order lookup by
	 * meta - have all run against a store with HPOS enabled.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! self::load_dependencies() ) {
			return;
		}

		require_once self::plugin_abspath() . 'includes/class-wc-edge-attempt-store.php';

		WC_Edge_Attempt_Store::install();
		update_option( WC_Edge_Attempt_Store::SCHEMA_OPTION, WC_Edge_Attempt_Store::SCHEMA_VERSION );
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
		require_once $path . 'class-wc-edge-attempt-store.php';
		require_once $path . 'class-wc-edge-fingerprint.php';
		require_once $path . 'class-wc-edge-order-mapper.php';
		require_once $path . 'class-wc-edge-logger.php';
		require_once $path . 'class-wc-edge-payment-service.php';
		require_once $path . 'class-wc-edge-rest-controller.php';
		require_once $path . 'class-wc-edge-webhook-store.php';
		require_once $path . 'class-wc-edge-webhook-controller.php';
		require_once $path . 'class-wc-edge-subscription-reconciler.php';

		add_action( 'rest_api_init', array( 'WC_Edge_REST_Controller', 'register' ) );
		add_action( 'rest_api_init', array( 'WC_Edge_Webhook_Controller', 'register' ) );

		// Housekeeping for both bounded tables.
		add_action( 'wc_edge_daily_cleanup', array( __CLASS__, 'run_cleanup' ) );

		if ( ! wp_next_scheduled( 'wc_edge_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wc_edge_daily_cleanup' );
		}

		// Bind the pre-order attempt to the order the moment one exists.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'adopt_attempt' ), 10, 1 );

		self::maybe_upgrade_settings();

		// The plugin is symlinked in development, where activation hooks do not
		// always fire, so the schema is checked here as well. The check is a
		// single option read when the version already matches.
		WC_Edge_Attempt_Store::maybe_install();
		WC_Edge_Webhook_Store::maybe_install();

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
	 * Trim both bounded tables.
	 *
	 * @return void
	 */
	public static function run_cleanup() {
		WC_Edge_Attempt_Store::purge();
		WC_Edge_Webhook_Store::purge();
	}

	/**
	 * Carry the checkout attempt onto the order that was just created.
	 *
	 * Until this point the payment demand belongs to a session, because the
	 * iframe had to mount before an order existed. From here on the order is the
	 * binding, and it is what process_payment() and the webhook handler trust -
	 * never a value supplied by the browser.
	 *
	 * @param WC_Order $order Newly created order.
	 * @return void
	 */
	public static function adopt_attempt( $order ) {
		if ( ! $order instanceof WC_Order || 'edge' !== $order->get_payment_method() ) {
			return;
		}

		if ( ! WC()->session || ! WC()->session->get_customer_id() ) {
			return;
		}

		global $wpdb;

		$table       = WC_Edge_Attempt_Store::table_name();
		$session_key = (string) WC()->session->get_customer_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attempt = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE session_key = %s AND status = %s AND demand_id IS NOT NULL
				 ORDER BY updated_at DESC LIMIT 1",
				$session_key,
				WC_Edge_Attempt_Store::STATUS_PREPARED
			)
		);
		// phpcs:enable

		if ( ! $attempt ) {
			return;
		}

		// CRUD rather than update_post_meta, so this works under HPOS.
		$order->update_meta_data( '_edge_demand_id', $attempt->demand_id );
		$order->update_meta_data( '_edge_attempt_key', $attempt->attempt_key );
		$order->update_meta_data( '_edge_mode', $attempt->mode );
		$order->update_meta_data( '_edge_amount_cents', $attempt->amount_cents );
		$order->update_meta_data( '_edge_currency', $attempt->currency );
		$order->save();

		WC_Edge_Attempt_Store::adopt( $attempt->attempt_key, $order->get_id() );
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
