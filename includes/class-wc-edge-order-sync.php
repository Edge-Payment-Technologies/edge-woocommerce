<?php

/**
 * Applies an Edge payment demand's authoritative state to its order.
 *
 * @package WooCommerce Edge Payments Gateway
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * The one place an order learns what happened to its payment.
 *
 * Edge settles asynchronously and reports the outcome by webhook. Deliveries
 * repeat and arrive out of order, and one can land while process_payment() is
 * still writing the order it has just confirmed; the demand lock serialises all of
 * them. The checkout's status poll never comes through here - it only reads the
 * order this writes.
 *
 * The decision itself is not here - it is in WC_Edge_Payment_Outcome, which has
 * no WordPress in it. This is the part that writes.
 */
final class WC_Edge_Order_Sync
{

  /**
   * Read the demand and apply what it says to the order.
   *
   * The order handed in is used only for its id and its binding: the state is
   * re-read from the database inside the lock, because the caller may have been
   * holding its copy since before another request moved the order.
   *
   * @param  WC_Order        $order   Order to sync.
   * @param  WC_Gateway_Edge $gateway Configured gateway.
   * @return array|WP_Error `array{state: string, outcome: string}`.
   * @throws Exception When the demand cannot be read. The caller decides whether
   *                   that is worth a retry.
   */
  public static function sync($order, $gateway)
  {
    $demand_id = (string) $order->get_transaction_id();

    if ('' === $demand_id) {
      return new WP_Error(
        'edge_no_binding',
        __('This order is not bound to an Edge payment.', 'edge-gateway-for-woocommerce')
      );
    }

    $owner = WC_Edge_Demand_Lock::acquire($demand_id);

    if (false === $owner) {
      // Somebody else is applying this demand right now - a repeated delivery,
      // or process_payment() still writing the order it has just confirmed. The
      // webhook asks Edge to redeliver.
      return new WP_Error(
        'edge_sync_locked',
        __('This payment is already being updated.', 'edge-gateway-for-woocommerce')
      );
    }

    try {
      $fresh = self::reload_order($order->get_id());

      if (!($fresh instanceof WC_Order)) {
        return new WP_Error(
          'edge_no_binding',
          __('That order could not be found.', 'edge-gateway-for-woocommerce')
        );
      }

      $bound = (string) $fresh->get_transaction_id();

      if (!hash_equals($demand_id, $bound)) {
        // The order has been rebound to another demand since. News about the one
        // we were asked about is no longer news about this order.
        return new WP_Error(
          'edge_binding_moved',
          __('This order has moved on to another payment.', 'edge-gateway-for-woocommerce')
        );
      }

      $mode = $gateway->get_order_payment_mode($fresh);
      $private_key = $gateway->get_private_key($mode);

      if ('' === $private_key) {
        return new WP_Error(
          'edge_no_key',
          __('No Edge key is configured for this payment mode.', 'edge-gateway-for-woocommerce')
        );
      }

      \Edge\Auth::setApiKey($private_key);

      // The delivered payload carries a state too, but deliveries arrive out of
      // order: a late `failed` for a declined attempt can land after the shopper
      // has re-confirmed the demand. The demand's current state is the only one
      // that says what the order should be now.
      $demand = \Edge\Client::get('v2/payment_demands/' . rawurlencode($demand_id));

      $state = isset($demand->data->attributes->processor_state)
        && is_string($demand->data->attributes->processor_state)
        ? $demand->data->attributes->processor_state
        : '';

      // A response we cannot read is a transport problem, not a payment state.
      // Saying so is worth a retry; guessing at it would put a junk note on the
      // order.
      if ('' === $state) {
        return new WP_Error(
          'edge_unreadable_state',
          __('Edge returned a payment we could not read.', 'edge-gateway-for-woocommerce')
        );
      }

      $outcome = WC_Edge_Payment_Outcome::decide($state, $fresh->get_status(), $fresh->is_paid());

      self::apply($fresh, $outcome, $state, $demand_id);

      return array(
        'state' => $state,
        'outcome' => $outcome,
      );
    } finally {
      WC_Edge_Demand_Lock::release($demand_id, $owner);
    }
  }

  /**
   * Read an order back from the database, past every cache in front of it.
   *
   * A call to wc_get_order() on its own is not a fresh read. Under HPOS the
   * container's OrderCache hands back the very object this request built - the
   * `order_objects` group is non-persistent, so it is a per-request store of
   * exactly the copy we are trying to get away from - and under post storage the
   * post and post-meta caches do the same. Either way a webhook that completed the
   * order milliseconds ago is invisible: the outcome rules see `on-hold`, and
   * payment_complete() runs a second time.
   *
   * So evict first, then read. Every eviction is optional and guarded: this runs
   * on WooCommerce versions that have none of these caches, and a failure to clear
   * one must not take the sync down with it.
   *
   * @param  int $order_id Order to read.
   * @return WC_Order|WC_Order_Refund|false Whatever wc_get_order() makes of it.
   */
  public static function reload_order($order_id)
  {
    $order_id = (int) $order_id;

    if ($order_id <= 0) {
      return false;
    }

    // The HPOS order-object cache, and the thing that actually serves the stale
    // copy.
    if (function_exists('wc_get_container') && class_exists('\Automattic\WooCommerce\Caches\OrderCache')) {
      try {
        wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($order_id);
      } catch (\Throwable $e) {
        // A WooCommerce whose container does not know the class. There is no such
        // cache to evict there, so nothing is stale because of it.
        unset($e);
      }
    }

    // The HPOS data store keeps a second one of its own - the `orders_data` group
    // and the raw meta behind it - populated only when datastore caching is
    // switched on.
    if (class_exists('WC_Data_Store')) {
      try {
        $store = WC_Data_Store::load('order');

        // Only the HPOS store defines it, and WC_Data_Store reaches its backing
        // store through __call(), so ask before calling rather than relying on a
        // silently swallowed miss. On post storage there is no such cache.
        if (method_exists($store, 'has_callable') && $store->has_callable('clear_cached_data')) {
          $store->__call('clear_cached_data', array(array($order_id)));
        }
      } catch (\Throwable $e) {
        // load() throws when no order store is registered, which only happens if
        // WooCommerce is not running - in which case there is no cache either.
        unset($e);
      }
    }

    // WC_Data caches an order's raw meta rows under its own group on both
    // storages, and read_meta_data() prefers that cache over the database.
    if (method_exists('WC_Order', 'generate_meta_cache_key')) {
      wp_cache_delete(WC_Order::generate_meta_cache_key($order_id, 'orders'), 'orders');
    }

    // Post storage: the order is a post, and its meta is in the post-meta cache.
    // Harmless under HPOS, where no such entries exist.
    clean_post_cache($order_id);
    wp_cache_delete($order_id, 'post_meta');

    return wc_get_order($order_id);
  }

  /**
   * Write an outcome to the order.
   *
   * Every branch has to be safe to repeat and safe to arrive late, because
   * deliveries do both. Which branch applies was decided by
   * WC_Edge_Payment_Outcome::decide(); this only carries it out.
   *
   * @param  WC_Order $order     Order, freshly read.
   * @param  string   $outcome   One of the WC_Edge_Payment_Outcome constants.
   * @param  string   $state     Edge processor state, for the notes that quote it.
   * @param  string   $demand_id Demand id, which becomes the transaction id.
   * @return void
   */
  private static function apply($order, $outcome, $state, $demand_id)
  {
    switch ($outcome) {
      case WC_Edge_Payment_Outcome::COMPLETE:
        $order->payment_complete($demand_id);
        $order->add_order_note(__('Edge confirmed this payment succeeded.', 'edge-gateway-for-woocommerce'));

        return;

      case WC_Edge_Payment_Outcome::IGNORED_STALE_FAILURE:
        $order->add_order_note(
          __('Edge reported a failure for a payment already marked paid. Not changing the order.', 'edge-gateway-for-woocommerce')
        );

        return;

      case WC_Edge_Payment_Outcome::FAIL:
        $order->update_status('failed', __('Edge declined this payment.', 'edge-gateway-for-woocommerce'));

        return;

      case WC_Edge_Payment_Outcome::RECONCILE:
        $order->update_meta_data('_edge_processor_state', $state);
        $order->add_order_note(
          sprintf(
            /* translators: %s: Edge processor state. */
            __('Edge reported this payment as %s. Reconcile it in the Edge dashboard.', 'edge-gateway-for-woocommerce'),
            $state
          )
        );
        $order->save();

        return;

      case WC_Edge_Payment_Outcome::UNRECOGNISED:
        $order->add_order_note(
          sprintf(
            /* translators: %s: unrecognised Edge payment state. */
            __('Edge reported an unrecognised payment state: %s.', 'edge-gateway-for-woocommerce'),
            $state
          )
        );

        return;

      default:
        // ALREADY_PAID, ALREADY_FAILED and NO_CHANGE. The order already records
        // this, or the state says nothing about it. Saying so on the order would
        // only add noise, and deliveries repeat.
        return;
    }
  }
}
