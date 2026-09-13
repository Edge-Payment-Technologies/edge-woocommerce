<?php

/**
 * Plugin Name: Edge Gateway for WooCommerce
 * Plugin URI: https://github.com/Edge-Payment-Technologies/edge-woocommerce
 * Description: This is the official WordPress plugin for utilizing Edge Payment Technologies, Inc. as a payment gateway in WooCommerce stores.
 * Version: 1.0.21
 * License: GPL-3.0+
 *
 * Author: Edge Payments
 * Author URI: https://www.tryedge.io
 *
 * Text Domain: edge-gateway-for-woocommerce
 * Domain Path: /i18n/languages/
 * Requires Plugins: woocommerce
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * WC Edge Payment gateway plugin class.
 *
 * @class EDGEWC_Gateway
 */
class EDGEWC_Gateway
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

    $gateways[] = 'EDGEWC_Gateway_Edge';

    return $gateways;
  }

  /**
   * Plugin includes.
   */
  public static function includes()
  {

    // Make the EDGEWC_Gateway_Edge class available.
    if (class_exists('WC_Payment_Gateway')) {
      require_once 'includes/class-wc-gateway-edge.php';

      // WooCommerce saves the provisional refund before calling the gateway, but passes it
      // only the order ID, amount, and reason. Capture the refund object so the gateway can
      // (1) add its already-deducted amount back when validating the remaining balance, and
      // (2) attach the Edge reference to that specific refund, which is what distinguishes a
      // retry from a new partial refund. Register here, not in the gateway constructor:
      // WooCommerce may instantiate the gateway only after this hook has fired.
      add_action('woocommerce_create_refund', array('EDGEWC_Gateway_Edge', 'capture_refund_context'), 10, 2);
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
          $payment_method_registry->register(new EDGEWC_Gateway_Edge_Blocks_Support());
        }
      );
    }
  }
}

EDGEWC_Gateway::init();
