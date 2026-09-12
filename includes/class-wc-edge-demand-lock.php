<?php

/**
 * Short-lived exclusive lease over one Edge payment demand.
 *
 * @package WooCommerce Edge Payments Gateway
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Serialises the requests that apply a payment demand's outcome to its order.
 *
 * Two of them race for the same order: the webhook, and process_payment(). A
 * webhook for a demand can land the instant confirm returns, and between the Store
 * API setting the order `pending` and process_payment() writing `on-hold`, a fast
 * delivery would otherwise see a status the outcome rules read as stale, or
 * complete the order only to have the in-memory copy in process_payment() write
 * `on-hold` back over it. Deliveries also repeat, so two webhooks can race each
 * other as well.
 *
 * Built on add_option() for the same reason as the refund lock in
 * WC_Gateway_Edge: options have a unique key in the database, so the insert is
 * atomic where a read-then-write transient is not, and its failure *is* the
 * mutual exclusion. Keyed on the demand rather than the order, because a retry
 * after a decline reuses the demand while the order moves under it.
 *
 * The lease expires by itself, because a request that dies mid-sync must not wedge
 * an order until somebody notices. It is handed back as an owner token: once a
 * lease has expired and been taken over, the original holder releasing it would
 * otherwise free somebody else's lock.
 */
final class WC_Edge_Demand_Lock
{

  /** Default lease length. Only has to outlast one read of the demand plus the order write. */
  const DEFAULT_TTL = 30;

  /** Option name prefix. A UUID demand id keeps this well inside the 191-character column. */
  const OPTION_PREFIX = '_edge_demand_lock_';

  /**
   * Take the lease on a demand.
   *
   * @param  string $demand_id   Edge payment demand id.
   * @param  int    $ttl_seconds How long the lease is honoured for.
   * @return string|false The owner token to release with, or false when somebody else holds it.
   */
  public static function acquire($demand_id, $ttl_seconds = self::DEFAULT_TTL)
  {
    $demand_id = (string) $demand_id;

    if ('' === $demand_id || !wp_is_uuid($demand_id)) {
      return false;
    }

    $option = self::option_name($demand_id);
    $owner = wp_generate_uuid4();
    $value = $owner . '|' . (time() + max(1, (int) $ttl_seconds));

    if (add_option($option, $value, '', false)) {
      return $owner;
    }

    // A row already there is the expected miss. Reclaim it only once its own
    // deadline has passed, so a holder that is merely slow is left alone.
    $held = explode('|', (string) get_option($option));
    $expires = isset($held[1]) ? (int) $held[1] : 0;

    if ($expires && time() > $expires) {
      delete_option($option);

      return add_option($option, $value, '', false) ? $owner : false;
    }

    return false;
  }

  /**
   * Give the lease back.
   *
   * The token is checked so a holder whose lease expired and was taken over
   * cannot free the lock somebody else is now working under.
   *
   * @param  string $demand_id Edge payment demand id.
   * @param  string $owner     Token returned by acquire().
   * @return void
   */
  public static function release($demand_id, $owner)
  {
    $demand_id = (string) $demand_id;

    if ('' === $demand_id || !is_string($owner) || '' === $owner) {
      return;
    }

    $option = self::option_name($demand_id);
    $held = explode('|', (string) get_option($option));

    if (isset($held[0]) && hash_equals($held[0], $owner)) {
      delete_option($option);
    }
  }

  /**
   * Option name for a demand.
   *
   * @param  string $demand_id Edge payment demand id.
   * @return string
   */
  private static function option_name($demand_id)
  {
    return self::OPTION_PREFIX . $demand_id;
  }
}
