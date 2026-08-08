<?php
/**
 * PHPUnit bootstrap.
 *
 * These are plain unit tests: no WordPress is loaded. Classes under test are
 * therefore written to avoid WordPress functions, or to have those seams
 * injected. `WC_EDGE_TESTING` stands in for the `ABSPATH` direct-access guard.
 *
 * @package WooCommerce Edge Payments Gateway
 */

define( 'WC_EDGE_TESTING', true );

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../includes/class-wc-edge-money.php';
require_once __DIR__ . '/../includes/class-wc-edge-mode.php';
