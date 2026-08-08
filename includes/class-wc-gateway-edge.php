<?php
/**
 * WC_Gateway_Edge class
 *
 * @package  WooCommerce Edge Payments Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edge Gateway.
 *
 * @class    WC_Gateway_Edge
 * @version  2.0.0
 */
class WC_Gateway_Edge extends WC_Payment_Gateway {

	/**
	 * Secret (server-only) API key.
	 *
	 * @var string
	 */
	protected $secret_key = '';

	/**
	 * Publishable (browser-safe) API key.
	 *
	 * @var string
	 */
	protected $publishable_key = '';

	/**
	 * Derived from the key prefix: "live" or "sandbox".
	 *
	 * @var string|null
	 */
	protected $mode = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {

		$this->id = 'edge';

		// The hosted iframe collects the card, so there are no gateway-rendered
		// fields on any surface.
		$this->has_fields = false;

		$this->icon = apply_filters( 'woocommerce_edge_gateway_icon', '' );

		// Refunds and subscriptions are deliberately absent: neither can be
		// implemented correctly against the current Edge v2 contract. See the
		// migration plan for the upstream prerequisites.
		$this->supports = array( 'products' );

		$this->method_title       = _x( 'Edge Payments', 'Edge payments method', 'edge-gateway' );
		$this->method_description = __( 'Accept card payments through Edge. Requires the block-based checkout.', 'edge-gateway' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled' );
		$this->secret_key      = trim( (string) $this->get_option( 'secret_key' ) );
		$this->publishable_key = trim( (string) $this->get_option( 'publishable_key' ) );
		$this->mode            = WC_Edge_Mode::mode_of( $this->publishable_key );

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * There is one key pair, not two. The mode is part of the key itself, so a
	 * separate "test mode" toggle could only ever disagree with the credentials
	 * sitting next to it.
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'edge-gateway' ),
				'label'   => __( 'Enable Edge Payments', 'edge-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'edge-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'edge-gateway' ),
				'default'     => __( 'Credit Card', 'edge-gateway' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'edge-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'edge-gateway' ),
				'default'     => __( 'Pay securely with your card.', 'edge-gateway' ),
			),
			'publishable_key' => array(
				'title'       => __( 'Publishable key', 'edge-gateway' ),
				'type'        => 'text',
				/* translators: %s: example key prefix. */
				'description' => sprintf( __( 'Browser-safe key, starting %s. Sandbox or live is determined by this prefix.', 'edge-gateway' ), '<code>ept_live_b</code>' ),
			),
			'secret_key'      => array(
				'title'       => __( 'Secret key', 'edge-gateway' ),
				'type'        => 'password',
				/* translators: %s: example key prefix. */
				'description' => sprintf( __( 'Server-only key, starting %s. Never shared with the browser.', 'edge-gateway' ), '<code>ept_live_s</code>' ),
			),
		);
	}

	/**
	 * Save settings, refusing credential combinations that cannot work.
	 *
	 * Catching these here means a misconfiguration surfaces on the settings
	 * screen rather than as a failed payment after a shopper has entered a card.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$secret      = trim( (string) $this->get_option( 'secret_key' ) );
		$publishable = trim( (string) $this->get_option( 'publishable_key' ) );

		// An entirely blank pair is the initial state, not an error.
		if ( '' === $secret && '' === $publishable ) {
			return $saved;
		}

		$error = WC_Edge_Mode::validate_pair( $secret, $publishable );

		if ( null !== $error ) {
			WC_Admin_Settings::add_error( self::describe_key_error( $error ) );

			return $saved;
		}

		self::sync_webhook_subscription();

		return $saved;
	}

	/**
	 * Register or refresh the webhook subscription for the saved credentials.
	 *
	 * Without a subscription no order can ever leave on-hold, so a failure here
	 * is surfaced on the settings screen rather than discovered later by a
	 * merchant wondering why nothing completes.
	 *
	 * @return void
	 */
	private static function sync_webhook_subscription() {
		// Re-read the gateway so it reflects what was just saved.
		$gateway = new self();

		$result = WC_Edge_Subscription_Reconciler::reconcile( $gateway );

		if ( ! is_wp_error( $result ) ) {
			WC_Admin_Settings::add_message( __( 'Edge Payments: webhooks are registered.', 'edge-gateway' ) );

			return;
		}

		if ( 'edge_callback_unreachable' === $result->get_error_code() ) {
			// Expected on a local site; not a misconfiguration to shout about.
			WC_Admin_Settings::add_message(
				__( 'Edge Payments: settings saved. Webhooks were not registered because this site is not reachable from the internet, so orders will stay on hold until it is.', 'edge-gateway' )
			);

			return;
		}

		WC_Admin_Settings::add_error(
			sprintf(
				/* translators: %s: error detail. */
				__( 'Edge Payments: webhooks could not be registered, so orders will not complete automatically. %s', 'edge-gateway' ),
				$result->get_error_message()
			)
		);
	}

	/**
	 * Translate a WC_Edge_Mode error code for display.
	 *
	 * @param string $code Error code from WC_Edge_Mode::validate_pair().
	 * @return string
	 */
	private static function describe_key_error( $code ) {
		switch ( $code ) {
			case 'missing_secret_key':
				return __( 'Edge Payments: a secret key is required.', 'edge-gateway' );

			case 'missing_publishable_key':
				return __( 'Edge Payments: a publishable key is required.', 'edge-gateway' );

			case 'malformed_secret_key':
				return __( 'Edge Payments: that secret key is not in the expected format.', 'edge-gateway' );

			case 'malformed_publishable_key':
				return __( 'Edge Payments: that publishable key is not in the expected format.', 'edge-gateway' );

			case 'secret_field_holds_publishable_key':
				return __( 'Edge Payments: the secret key field contains a publishable key. Check the two fields are not swapped.', 'edge-gateway' );

			case 'publishable_field_holds_secret_key':
				return __( 'Edge Payments: the publishable key field contains a secret key. This key would be exposed to the browser, so it has not been accepted.', 'edge-gateway' );

			case 'mode_mismatch':
				return __( 'Edge Payments: one key is live and the other is sandbox. Both keys must be from the same mode.', 'edge-gateway' );

			default:
				return __( 'Edge Payments: the API keys could not be validated.', 'edge-gateway' );
		}
	}

	/**
	 * Whether the configured keys form a usable pair.
	 *
	 * @return bool
	 */
	public function has_valid_keys() {
		return null === WC_Edge_Mode::validate_pair( $this->secret_key, $this->publishable_key );
	}

	/**
	 * The mode the gateway is operating in.
	 *
	 * @return string|null "live", "sandbox", or null when unconfigured.
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * The secret key, for server-side calls only.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return $this->secret_key;
	}

	/**
	 * The publishable key, safe to hand to the browser.
	 *
	 * Re-checked rather than returned blindly: settings can be written by WP-CLI,
	 * a migration or direct SQL, and a secret key reaching the browser is not a
	 * mistake worth risking on the save-time check alone.
	 *
	 * @return string
	 */
	public function get_publishable_key() {
		return WC_Edge_Mode::is_publishable( $this->publishable_key ) ? $this->publishable_key : '';
	}

	/**
	 * Whether the gateway can be offered for the current request.
	 *
	 * Every reason to decline is checked here rather than at payment time, so a
	 * shopper is never offered Edge only to have it fail after entering a card.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->has_valid_keys() ) {
			return false;
		}

		// Leave the admin free to configure the gateway before it is usable.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		if ( ! $this->is_supported_checkout_surface() ) {
			return false;
		}

		if ( ! WC_Edge_Money::is_supported_currency( get_woocommerce_currency() ) ) {
			return false;
		}

		return $this->is_amount_chargeable();
	}

	/**
	 * Whether the current cart total is one Edge will accept.
	 *
	 * @return bool
	 */
	private function is_amount_chargeable() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			// No cart to judge - e.g. the gateway list on a settings screen.
			return true;
		}

		try {
			$cents = WC_Edge_Money::to_cents( WC()->cart->get_total( 'edit' ) );
		} catch ( InvalidArgumentException $e ) {
			return false;
		}

		return WC_Edge_Money::is_chargeable( $cents );
	}

	/**
	 * Whether this request is a checkout surface the gateway actually supports.
	 *
	 * The gateway is block-checkout only. `has_fields = false` alone would not
	 * achieve that: it removes the classic card fields but still lets the gateway
	 * be selected on the classic checkout, which would then submit with no
	 * hosted-form verification having happened at all.
	 *
	 * @return bool
	 */
	private function is_supported_checkout_surface() {
		// Paying for an existing order and saving a card both run outside the
		// block checkout, so neither can complete the hosted-form flow.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
			return false;
		}

		// The Store API drives the block checkout.
		if ( self::is_store_api_request() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return self::checkout_uses_blocks();
		}

		return true;
	}

	/**
	 * Whether the checkout page is the block-based one.
	 *
	 * @return bool
	 */
	private static function checkout_uses_blocks() {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) ) {
			return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );

		return $checkout_page_id > 0 && has_block( 'woocommerce/checkout', $checkout_page_id );
	}

	/**
	 * Whether the current request is a WooCommerce Store API request.
	 *
	 * @return bool
	 */
	private static function is_store_api_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return '' !== $uri && false !== strpos( $uri, '/wc/store/' );
	}

	/**
	 * Confirm the bound payment demand and hand the order over to the webhook.
	 *
	 * No card data reaches this method: the hosted iframe collected and verified
	 * the card, and all that crosses the boundary is an opaque demand id, which
	 * is only ever used to cross-check the binding the server already holds.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->fail( __( 'That order could not be found.', 'edge-gateway' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The Store API authenticates this request; this value is cross-checked against server-held order meta and never trusted on its own.
		$submitted = isset( $_POST['edge_demand_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edge_demand_id'] ) ) : '';

		if ( '' !== $submitted && ! self::is_uuid( $submitted ) ) {
			return $this->fail( __( 'That payment reference is not valid.', 'edge-gateway' ) );
		}

		$confirmed = WC_Edge_Payment_Service::confirm( $this, $order, $submitted );

		if ( is_wp_error( $confirmed ) ) {
			return $this->fail( $confirmed->get_error_message() );
		}

		$order->set_transaction_id( $confirmed );

		// Confirming means Edge accepted the payment for processing, not that it
		// succeeded. Completing the order here would mark it paid before the
		// processor has said anything, so the order waits for the webhook, which
		// is the authoritative source.
		$order->update_status(
			'on-hold',
			__( 'Awaiting confirmation from Edge.', 'edge-gateway' )
		);

		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Build a checkout failure result.
	 *
	 * @param string $message Shopper-facing message.
	 * @return array
	 */
	private function fail( $message ) {
		wc_add_notice( $message, 'error' );

		return array(
			'result'  => 'failure',
			'message' => $message,
		);
	}

	/**
	 * Whether a value is UUID-shaped.
	 *
	 * Input validation only. It says nothing about whether the resource belongs
	 * to this order, which is what the binding check in the payment service is
	 * for.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_uuid( $value ) {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			(string) $value
		);
	}
}
