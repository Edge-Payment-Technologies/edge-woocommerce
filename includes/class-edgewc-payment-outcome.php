<?php

/**
 * Decides what an Edge payment state means for an order and for the shopper.
 *
 * @package WooCommerce Edge Payments Gateway
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * The judgement calls a payment outcome makes, with no WordPress in them.
 *
 * Edge settles a card payment asynchronously: confirming a demand leaves it
 * `pending`, and `succeeded` or `failed` arrives seconds later by webhook. Kept
 * apart from EDGEWC_Order_Sync, the part that writes notes and moves the order,
 * so the rules can be read - and exercised from `wp eval` - on their own.
 */
final class EDGEWC_Payment_Outcome
{

  /** Take the money: the payment succeeded and the order is not paid yet. */
  const COMPLETE = 'complete';

  /** The payment succeeded and the order already records it. Nothing to do. */
  const ALREADY_PAID = 'already-paid';

  /** The payment failed and the order should be moved to failed. */
  const FAIL = 'fail';

  /** The payment failed and the order already records it. Nothing to do. */
  const ALREADY_FAILED = 'already-failed';

  /**
   * A failure arrived for an order that is already paid. The caller records it
   * as a note and leaves the order alone - an order is never un-paid.
   */
  const IGNORED_STALE_FAILURE = 'ignored-stale-failure';

  /**
   * The money moved after the fact (reversed, refunded, disputed). WooCommerce
   * cannot derive the right end state from that, so the merchant is told to
   * reconcile it in the Edge dashboard.
   */
  const RECONCILE = 'reconcile';

  /** A legitimate state that says nothing new about the order. */
  const NO_CHANGE = 'no-change';

  /**
   * A state this plugin does not know. The caller records it rather than
   * guessing at what it meant.
   */
  const UNRECOGNISED = 'unrecognised';

  /**
   * Processor states that mean the payment was taken and then moved again.
   *
   * `ept` emits no events for these and its enum has no `refunded`, but a state
   * read back from the API costs nothing to recognise and guessing at it does.
   */
  const RECONCILE_STATES = array('reversed', 'refunded', 'disputed');

  /**
   * States that are part of the normal run-up to an outcome, or a cancellation
   * that never took money. None of them changes an order.
   *
   * The last four are not payment demand states at all. Until it is confirmed, a
   * demand is still a PaymentIntent - `incomplete`, `ready`, `confirmed`,
   * `canceled` (`lib/core/transactions/payment_intent.ex`) - and the
   * payment_demands endpoint serves it as one. Verified: reading back a freshly
   * created demand returns `incomplete`. Leave them out and a delivery that lands
   * before its confirm has taken would be UNRECOGNISED, and say so on the order.
   */
  const QUIET_STATES = array('pending', 'processing', 'incomplete', 'ready', 'confirmed', 'canceled');

  /** The order status a `failed` state may be applied from. */
  const CONFIRMED_STATUS = 'on-hold';

  /**
   * What a processor state means for an order in a given status.
   *
   * The asymmetry between success and failure is deliberate.
   *
   * A `succeeded` state is applied from any unpaid status: money that has been
   * taken is never stale, and however the order got where it is, the payment has
   * to be recorded.
   *
   * A `failed` state is applied only from `on-hold`, which is where
   * process_payment() leaves an order it has just confirmed. A declined shopper
   * can confirm the same demand again, and that retry moves the order through
   * `pending` on its way back to `on-hold`; the late `failed` delivery for the
   * previous attempt must not land on top of it. Any other status means either a
   * retry is in flight or somebody moved the order by hand, and in both cases the
   * order is not ours to change.
   *
   * @param  string $processor_state Edge processor state.
   * @param  string $order_status    WooCommerce order status, with or without the `wc-` prefix.
   * @param  bool   $is_paid         Whether WooCommerce already treats the order as paid.
   * @return string One of the outcome constants.
   */
  public static function decide($processor_state, $order_status, $is_paid)
  {
    $state = is_string($processor_state) ? $processor_state : '';
    $status = self::normalize_status($order_status);
    $is_paid = (bool) $is_paid;

    if ('succeeded' === $state) {
      return $is_paid ? self::ALREADY_PAID : self::COMPLETE;
    }

    if ('failed' === $state) {
      if ($is_paid) {
        return self::IGNORED_STALE_FAILURE;
      }

      if ('failed' === $status) {
        return self::ALREADY_FAILED;
      }

      return self::CONFIRMED_STATUS === $status ? self::FAIL : self::NO_CHANGE;
    }

    if (in_array($state, self::RECONCILE_STATES, true)) {
      return self::RECONCILE;
    }

    if (in_array($state, self::QUIET_STATES, true)) {
      return self::NO_CHANGE;
    }

    return self::UNRECOGNISED;
  }

  /**
   * An order status without WooCommerce's storage prefix.
   *
   * WC_Order::get_status() drops the `wc-` prefix, but a status read from
   * anywhere else keeps it, and a prefix that slipped through would silently turn
   * every comparison here into "some status we do not recognise".
   *
   * @param  string $order_status Order status.
   * @return string
   */
  private static function normalize_status($order_status)
  {
    $status = is_string($order_status) ? strtolower(trim($order_status)) : '';

    return 0 === strpos($status, 'wc-') ? substr($status, 3) : $status;
  }
}
