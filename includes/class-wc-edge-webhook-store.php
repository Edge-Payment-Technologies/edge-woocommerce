<?php
/**
 * Bounded record of webhook events already handled.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deduplicates and serialises webhook delivery.
 *
 * Edge retries on failure and does not enforce its own concurrency limit, so the
 * same event can arrive more than once and two copies can arrive at once. The
 * primary key is what resolves both: the first insert wins and processes, later
 * ones are turned away without touching the order.
 *
 * A table rather than order meta because this has to be prunable. An
 * ever-growing array on an order is not a design that survives a busy store.
 */
final class WC_Edge_Webhook_Store {

	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'wc_edge_webhook_events_schema_version';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'edge_webhook_events';
	}

	/**
	 * Create the table when the schema version changes.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			event_id char(36) NOT NULL,
			resource_type varchar(64) NOT NULL DEFAULT '',
			resource_id char(36) DEFAULT NULL,
			slug varchar(64) NOT NULL DEFAULT '',
			mode varchar(10) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned DEFAULT NULL,
			received_at datetime NOT NULL,
			PRIMARY KEY  (event_id),
			KEY resource_id (resource_id),
			KEY received_at (received_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Claim an event for processing.
	 *
	 * @param string $event_id Event id.
	 * @param array  $meta     resource_type, resource_id, slug, mode.
	 * @return bool True when this caller should process the event.
	 */
	public static function claim( $event_id, array $meta ) {
		global $wpdb;

		// A duplicate delivery is routine, so keep the constraint violation out
		// of the error log.
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'event_id'      => $event_id,
				'resource_type' => isset( $meta['resource_type'] ) ? (string) $meta['resource_type'] : '',
				'resource_id'   => isset( $meta['resource_id'] ) ? (string) $meta['resource_id'] : null,
				'slug'          => isset( $meta['slug'] ) ? (string) $meta['slug'] : '',
				'mode'          => isset( $meta['mode'] ) ? (string) $meta['mode'] : '',
				'received_at'   => current_time( 'mysql', true ),
			)
		);

		$wpdb->suppress_errors( $suppressed );

		return false !== $inserted;
	}

	/**
	 * Note which order an event was applied to.
	 *
	 * @param string $event_id Event id.
	 * @param int    $order_id Order id.
	 * @return void
	 */
	public static function attach_order( $event_id, $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table_name(),
			array( 'order_id' => (int) $order_id ),
			array( 'event_id' => $event_id )
		);
	}

	/**
	 * Release a claim so a retry can be processed.
	 *
	 * Used when handling failed before the order was touched; without this a
	 * transient error would make Edge's retries no-ops.
	 *
	 * @param string $event_id Event id.
	 * @return void
	 */
	public static function release( $event_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'event_id' => $event_id ) );
	}

	/**
	 * Drop old rows.
	 *
	 * Edge stops retrying after roughly three hours, so a month is generous.
	 *
	 * @param int $older_than_days Age threshold.
	 * @return int Rows removed.
	 */
	public static function purge( $older_than_days = 30 ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %s", $cutoff )
		);
		// phpcs:enable
	}
}
