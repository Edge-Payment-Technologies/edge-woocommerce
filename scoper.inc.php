<?php
/**
 * PHP-Scoper configuration for the distributable build.
 *
 * WordPress loads every plugin into one PHP process, so two plugins that vendor
 * different versions of the same library fight over whichever class name is
 * declared first. Guzzle is the classic case, and this plugin ships it via the
 * Edge SDK. Prefixing our copy means we always get the version we were tested
 * against, and no other plugin is affected by ours.
 *
 * @package WooCommerce Edge Payments Gateway
 */

declare( strict_types = 1 );

return array(
	'prefix' => 'EdgePayments\\EdgeWoocommerce\\Vendor',

	'finders' => array(),

	/*
	 * Everything this plugin defines lives in the global namespace and must stay
	 * there. WooCommerce registers the gateway by class name string, WordPress
	 * hooks reference callbacks the same way, and neither would find a prefixed
	 * class. Vendor code is still prefixed; only our own symbols are exposed.
	 */
	'expose-global-classes'   => true,
	'expose-global-functions' => true,
	'expose-global-constants' => true,

	// Plugin sources are patched too, so `\Edge\Client` in our code resolves to
	// the prefixed class. Without this the plugin would reference names that no
	// longer exist in the build.
	'patchers' => array(),

	/*
	 * Symbols that must keep their global names.
	 *
	 * Everything WordPress and WooCommerce provide is defined by the host, not
	 * by us: prefixing a reference to WC_Payment_Gateway or wp_remote_request
	 * would point at a class or function that does not exist at runtime.
	 */
	'exclude-namespaces' => array(
		'Automattic',
	),

	'exclude-classes' => array(
		'/^WC_/',
		'/^WP_/',
		'/^Woo/',
		'wpdb',
	),

	'exclude-functions' => array(
		'/^wp_/',
		'/^wc_/',
		'/^is_/',
		'/^get_/',
		'/^add_/',
		'/^update_/',
		'/^delete_/',
		'/^esc_/',
		'/^sanitize_/',
		'/^register_/',
		'/^current_/',
		'/^has_/',
		'/^apply_/',
		'/^do_/',
		'/^rest_/',
		'/^untrailingslashit$/',
		'/^trailingslashit$/',
		'/^plugin_dir_path$/',
		'/^plugins_url$/',
		'/^home_url$/',
		'/^dbDelta$/',
		'/^__$/',
		'/^_x$/',
		'/^wpautop$/',
		'/^hash_equals$/',
		'/^nocache_headers$/',
	),

	'exclude-constants' => array(
		'/^ABSPATH$/',
		'/^WP_/',
		'/^WC_/',
		'/^EDGE_/',
		'/^DAY_IN_SECONDS$/',
		'/^HOUR_IN_SECONDS$/',
		'/^REST_REQUEST$/',
		'/^PHP_/',
		'/^ENT_/',
		'/^STR_PAD_RIGHT$/',
	),
);
