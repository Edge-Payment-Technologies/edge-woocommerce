<?php

/**
 * Plugin Name: Edge Payments Gateway
 * Plugin URI: https://github.com/Edge-Payment-Technologies/edge-woocommerce
 * Description: Adds the Edge Payments gateway to your WooCommerce website.
 * Version: 1.0.7
 *
 * Author: Edge Payments
 * Author URI: https://tryedge.com
 *
 * Text Domain: edge-gateway
 * Domain Path: /i18n/languages/
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC Edge Payment gateway plugin class.
 *
 * @class WC_Edge_Payments
 */
class WC_Edge_Payments
{

	/**
	 * Plugin bootstrapping.
	 */
	public static function init()
	{

		// Webhook handling has no WooCommerce dependency, and the hooks below name
		// these classes, so it loads ahead of everything else.
		require_once 'includes/class-wc-edge-webhook-events.php';
		require_once 'includes/class-wc-edge-webhook-handler.php';

		// Edge Payments gateway class.
		add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);

		// Make the Edge Payments gateway available to WC.
		add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

		// Registers WooCommerce Blocks integration.
		add_action('woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_gateway_edge_woocommerce_block_support'));

		// Receive payment outcomes from Edge.
		add_action('rest_api_init', array('WC_Edge_Webhook_Handler', 'register_routes'));

		// The delivery log's table, and the job that trims it.
		register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
		register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
		add_action('plugins_loaded', array(__CLASS__, 'maybe_install'), 5);
		add_action(WC_Edge_Webhook_Events::PRUNE_HOOK, array('WC_Edge_Webhook_Events', 'prune'));
	}

	/**
	 * Run when the plugin is activated.
	 */
	public static function activate()
	{
		self::maybe_install();
	}

	/**
	 * Run when the plugin is deactivated.
	 *
	 * The delivery log is left in place: it is the only record of what Edge sent,
	 * and losing it because a plugin was toggled would be its own problem.
	 */
	public static function deactivate()
	{
		wp_clear_scheduled_hook(WC_Edge_Webhook_Events::PRUNE_HOOK);
	}

	/**
	 * Create the delivery log and schedule its cleanup, if they are not there yet.
	 *
	 * Activation alone would not be enough. During development the plugin
	 * directory is a symlink that is already active, so a table added in a later
	 * version would never appear.
	 */
	public static function maybe_install()
	{
		WC_Edge_Webhook_Events::maybe_install();

		if (!wp_next_scheduled(WC_Edge_Webhook_Events::PRUNE_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', WC_Edge_Webhook_Events::PRUNE_HOOK);
		}
	}

	/**
	 * Add the Edge Payment gateway to the list of available gateways.
	 *
	 * @param array
	 */
	public static function add_gateway($gateways)
	{

		$gateways[] = 'WC_Gateway_Edge';

		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes()
	{

		// Make the WC_Gateway_Edge class available.
		if (class_exists('WC_Payment_Gateway')) {
			require_once 'includes/class-wc-gateway-edge.php';
		}
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url()
	{
		return untrailingslashit(plugins_url('/', __FILE__));
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_abspath()
	{
		return trailingslashit(plugin_dir_path(__FILE__));
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 *
	 */
	public static function woocommerce_gateway_edge_woocommerce_block_support()
	{
		if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			require_once 'includes/blocks/class-wc-edge-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register(new WC_Gateway_Edge_Blocks_Support());
				}
			);
		}
	}
}

WC_Edge_Payments::init();
