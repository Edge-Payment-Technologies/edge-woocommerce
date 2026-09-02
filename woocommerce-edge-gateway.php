<?php

/**
 * Plugin Name: Edge Payments Gateway
 * Plugin URI: https://github.com/Edge-Payment-Technologies/edge-woocommerce
 * Description: Adds the Edge Payments gateway to your WooCommerce website.
 * Version: 1.0.15
 *
 * Author: Edge Payments
 * Author URI: https://www.tryedge.io
 *
 * Text Domain: woocommerce-edge-gateway
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

    // Edge Payments gateway class.
    add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);

    // Make the Edge Payments gateway available to WC.
    add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

    // Registers WooCommerce Blocks integration.
    add_action('woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_gateway_edge_woocommerce_block_support'));
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
      
      // WooCommerce saves the provisional refund before calling the gateway, but passes
      // only the order ID, amount, and reason. Capture the refund object so we can account
      // for its already-deducted amount and link the Edge reference to that specific refund,
      // distinguishing retries from new partial refunds. Register here, not in the gateway
      // constructor: WooCommerce may instantiate the gateway only after this hook fires.

      // WooCommerce passes our gateway only the order ID, amount, and reason—not the refund object. This hook captures
      // that object before the gateway runs. We need it for two things:
      // 1. Correct balance validation. WooCommerce has already saved the provisional refund when it calls us. For a 
      //    $100 full refund, the remaining balance therefore reads $0. Knowing the current refund lets us add its 
      //    $100 back when validating. Without the hook, that legitimate refund is rejected.
      // 2. Distinguishing successive partial refunds. We attach the Edge reference to the captured WooCommerce refund.
      //    That association tells us whether the next request is a retry or a new refund. Without it, another refund
      //    could incorrectly reuse the previous Edge attempt.

      // Registering the hook during plugin initialization ensures it before WooCommerce creates the refund.
      add_action('woocommerce_create_refund', array('WC_Gateway_Edge', 'capture_refund_context'), 10, 2);
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
