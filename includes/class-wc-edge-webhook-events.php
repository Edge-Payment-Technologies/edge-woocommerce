<?php

/**
 * Record of the webhook deliveries Edge has made to this site.
 *
 * @package  WooCommerce Edge Payments Gateway
 * @since    1.0.8
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * One row per Edge event id.
 *
 * The event id is the primary key, so the insert *is* the claim: the first
 * request to get a row in owns the event, and every retry or concurrent
 * duplicate of it is turned away by the database rather than by a check that
 * two requests could both pass at the same moment.
 *
 * The rows double as a delivery log. Edge's own `GET /v2/webhook_deliveries`
 * is not dependable, so without this there is no record on either side of what
 * actually arrived.
 *
 * @class    WC_Edge_Webhook_Events
 * @version  1.0.8
 */
class WC_Edge_Webhook_Events
{

	/**
	 * Bumped whenever the schema below changes.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'wc_edge_webhook_events_db_version';

	/**
	 * Cron hook that trims old rows.
	 */
	const PRUNE_HOOK = 'wc_edge_prune_webhook_events';

	/**
	 * How long a delivery record is kept, in days.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Table name, with the site's prefix.
	 *
	 * @return string
	 */
	public static function table_name()
	{
		global $wpdb;

		return $wpdb->prefix . 'wc_edge_webhook_events';
	}

	/**
	 * Create the table, or bring it up to date.
	 *
	 * @return void
	 */
	public static function install()
	{
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// Ids are Edge UUIDs. 191 is the widest a key can be under utf8mb4 on
		// the older MySQL versions WordPress still supports.
		dbDelta(
			"CREATE TABLE {$table} (
				event_id varchar(191) NOT NULL,
				resource_type varchar(64) NOT NULL DEFAULT '',
				resource_id varchar(191) NOT NULL DEFAULT '',
				slug varchar(64) NOT NULL DEFAULT '',
				mode varchar(16) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'claimed',
				received_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY resource_id (resource_id),
				KEY received_at (received_at)
			) {$collate};"
		);

		update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
	}

	/**
	 * Install on first run, and after an upgrade.
	 *
	 * Activation alone is not enough: during development the plugin directory is
	 * a symlink that is already active, so a new table would never appear.
	 *
	 * @return void
	 */
	public static function maybe_install()
	{
		if (self::DB_VERSION === get_option(self::DB_VERSION_OPTION)) {
			return;
		}

		self::install();
	}

	/**
	 * Take ownership of an event.
	 *
	 * @param  array  $event  Normalised event, as built by the webhook handler.
	 * @return bool   True when this request owns the event, false when another already does.
	 * @throws RuntimeException  When the row can be neither written nor found, so the
	 *                           caller can ask Edge to retry instead of losing the event.
	 */
	public static function claim(array $event)
	{
		global $wpdb;

		if (self::exists($event['id'])) {
			return false;
		}

		$suppress = $wpdb->suppress_errors();

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'event_id' => $event['id'],
				'resource_type' => $event['resource_type'],
				'resource_id' => $event['resource_id'],
				'slug' => $event['slug'],
				'mode' => $event['mode'],
				'status' => 'claimed',
				'received_at' => current_time('mysql', true),
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		$error = $wpdb->last_error;

		$wpdb->suppress_errors($suppress);

		if (false !== $inserted) {
			return true;
		}

		// The insert can fail because another request won the race, or because
		// something is wrong with the table. Only the first is a duplicate, and
		// asking again is a surer way to tell them apart than reading the
		// driver's error text.
		if (self::exists($event['id'])) {
			return false;
		}

		throw new RuntimeException('Could not record the Edge webhook event: ' . $error);
	}

	/**
	 * Give up a claim, so a retry is not silently swallowed as a duplicate.
	 *
	 * @param  string  $event_id
	 * @return void
	 */
	public static function release($event_id)
	{
		global $wpdb;

		$wpdb->delete(self::table_name(), array('event_id' => $event_id), array('%s'));
	}

	/**
	 * Record what handling the event did.
	 *
	 * @param  string  $event_id
	 * @param  string  $status    Outcome label.
	 * @param  int     $order_id  Order the event was applied to, if any.
	 * @return void
	 */
	public static function complete($event_id, $status, $order_id = 0)
	{
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status' => $status,
				'order_id' => (int) $order_id,
			),
			array('event_id' => $event_id),
			array('%s', '%d'),
			array('%s')
		);
	}

	/**
	 * Drop records past their retention window.
	 *
	 * @return void
	 */
	public static function prune()
	{
		global $wpdb;

		$table = self::table_name();
		$cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE received_at < %s", $cutoff));
	}

	/**
	 * Whether an event has already been recorded.
	 *
	 * @param  string  $event_id
	 * @return bool
	 */
	private static function exists($event_id)
	{
		global $wpdb;

		$table = self::table_name();

		$suppress = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name cannot be a placeholder, and this table is ours.
		$found = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE event_id = %s", $event_id));

		$wpdb->suppress_errors($suppress);

		return null !== $found;
	}
}
