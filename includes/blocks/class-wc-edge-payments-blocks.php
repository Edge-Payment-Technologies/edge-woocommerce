<?php
/**
 * Edge Payments Blocks integration
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   1.0.3
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the gateway to the block checkout.
 *
 * @since 1.0.3
 */
final class WC_Gateway_Edge_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Handle for Edge's hosted browser SDK.
	 *
	 * @var string
	 */
	const EDGE_JS_HANDLE = 'edge-js';

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Edge|null
	 */
	private $gateway = null;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'edge';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_edge_settings', array() );

		$gateways = WC()->payment_gateways->payment_gateways();

		if ( isset( $gateways[ $this->name ] ) ) {
			$this->gateway = $gateways[ $this->name ];
		}
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway instanceof WC_Gateway_Edge && $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		// Register the hosted SDK as a real dependency rather than letting React
		// append a script tag: one canonical load path, correct ordering, and
		// WordPress handles deduplication across remounts.
		wp_register_script(
			self::EDGE_JS_HANDLE,
			WC_Edge_Client_Factory::browser_sdk_url(),
			array(),
			null, // Version is Edge's to manage; a query string would bust their cache.
			true
		);

		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = WC_Edge_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => WC_EDGE_VERSION,
			);

		$script_url = WC_Edge_Payments::plugin_url() . $script_path;

		wp_register_script(
			'wc-edge-payments-blocks',
			$script_url,
			array_merge( $script_asset['dependencies'], array( self::EDGE_JS_HANDLE ) ),
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-edge-payments-blocks', 'edge-gateway', WC_Edge_Payments::plugin_abspath() . 'languages/' );
		}

		return array( 'wc-edge-payments-blocks' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * Everything returned here is public: it is serialised into the page. Only
	 * the publishable key is ever included, and it is re-checked rather than
	 * trusted to be in the right field.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {

		$publishable_key = trim( (string) $this->get_setting( 'publishable_key' ) );

		// Defence in depth. process_admin_options() already rejects a secret key
		// in this field, but settings can be written by other means - WP-CLI, a
		// migration, direct SQL - and a secret key reaching the browser is not a
		// mistake worth risking on a single check.
		if ( ! WC_Edge_Mode::is_publishable( $publishable_key ) ) {
			$publishable_key = '';
		}

		return array(
			'title'           => $this->get_setting( 'title' ),
			'description'     => $this->build_description(),
			'mode'            => WC_Edge_Mode::mode_of( $publishable_key ),
			'publishableKey'  => $publishable_key,
			'iframeHost'      => WC_Edge_Client_Factory::dashboard_host(),
			'supports'        => $this->gateway instanceof WC_Gateway_Edge
				? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) )
				: array(),
		);
	}

	/**
	 * Build the shopper-facing description.
	 *
	 * @return string
	 */
	private function build_description() {
		$description = (string) $this->get_setting( 'description' );

		if ( WC_Edge_Mode::MODE_SANDBOX === WC_Edge_Mode::mode_of( trim( (string) $this->get_setting( 'publishable_key' ) ) ) ) {
			$description = trim(
				$description . ' ' . sprintf(
					/* translators: 1: Visa test card number, 2: declining test card number. */
					__( 'Sandbox mode: no real payment is taken. Use %1$s for an approval or %2$s for a decline.', 'edge-gateway' ),
					'4005519200000004',
					'4124939999999990'
				)
			);
		}

		if ( '' === $description ) {
			return '';
		}

		return wpautop( wp_kses_post( $description ) );
	}
}
