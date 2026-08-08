<?php
/**
 * Storage for pre-order checkout attempts.
 *
 * @package WooCommerce Edge Payments Gateway
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks the Edge resources created for a checkout before an order exists.
 *
 * The hosted iframe has to mount before WooCommerce creates an order, so the
 * payment demand and its prerequisites cannot be keyed on order meta at the time
 * they are made. They live here until an order adopts them.
 *
 * A custom table rather than session data, because the uniqueness constraint is
 * what makes concurrent prepare requests safe: two parallel calls both see no
 * attempt, and only the database can decide which one gets to create it.
 */
final class WC_Edge_Attempt_Store {

	/**
	 * Bump when the schema changes.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'wc_edge_attempts_schema_version';

	/** Claimed, but the Edge resources are not all created yet. */
	const STATUS_CLAIMED = 'claimed';

	/** Demand created and ready for the iframe. */
	const STATUS_PREPARED = 'prepared';

	/** Carried onto a WooCommerce order. */
	const STATUS_ADOPTED = 'adopted';

	/** Superseded or abandoned. */
	const STATUS_STALE = 'stale';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'edge_checkout_attempts';
	}

	/**
	 * Create or update the table when the schema version changes.
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

		// The unique key on (session_key, facts_hash) is the concurrency control:
		// it is what makes a duplicate prepare fail rather than double-create.
		$sql = "CREATE TABLE {$table} (
			attempt_key char(36) NOT NULL,
			session_key varchar(191) NOT NULL,
			facts_hash char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'claimed',
			mode varchar(10) NOT NULL DEFAULT '',
			amount_cents bigint(20) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			customer_id char(36) DEFAULT NULL,
			billing_address_id char(36) DEFAULT NULL,
			shipping_address_id char(36) DEFAULT NULL,
			demand_id char(36) DEFAULT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (attempt_key),
			UNIQUE KEY session_facts (session_key, facts_hash),
			KEY demand_id (demand_id),
			KEY order_id (order_id),
			KEY status_updated (status, updated_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Claim an attempt for a set of payment facts, or return the existing one.
	 *
	 * Concurrency is resolved by the database, not by a read-then-write: both
	 * callers attempt the insert, and the unique key means exactly one succeeds.
	 * The loser reads back the winner's row.
	 *
	 * @param string $session_key  Identifies the shopper's checkout session.
	 * @param string $facts_hash   Fingerprint of the payment facts.
	 * @param array  $context      mode, amount_cents, currency.
	 * @return array{attempt:object,claimed:bool}|WP_Error
	 */
	public static function claim( $session_key, $facts_hash, array $context ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = array(
			'attempt_key'  => wp_generate_uuid4(),
			'session_key'  => $session_key,
			'facts_hash'   => $facts_hash,
			'status'       => self::STATUS_CLAIMED,
			'mode'         => isset( $context['mode'] ) ? (string) $context['mode'] : '',
			'amount_cents' => isset( $context['amount_cents'] ) ? (int) $context['amount_cents'] : 0,
			'currency'     => isset( $context['currency'] ) ? (string) $context['currency'] : '',
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		// A duplicate key is an expected outcome here, not a fault, so keep it
		// out of the error log.
		$suppressed = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->insert( self::table_name(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppressed );

		if ( false !== $inserted ) {
			return array(
				'attempt' => (object) $row,
				'claimed' => true,
			);
		}

		$existing = self::find_by_facts( $session_key, $facts_hash );

		if ( $existing ) {
			return array(
				'attempt' => $existing,
				'claimed' => false,
			);
		}

		// The insert failed for a reason other than the unique key.
		return new WP_Error(
			'edge_attempt_not_stored',
			__( 'Could not start the payment. Please try again.', 'edge-gateway' )
		);
	}

	/**
	 * Find an attempt by its payment-facts fingerprint.
	 *
	 * @param string $session_key Session identifier.
	 * @param string $facts_hash  Fingerprint.
	 * @return object|null
	 */
	public static function find_by_facts( $session_key, $facts_hash ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_key = %s AND facts_hash = %s",
				$session_key,
				$facts_hash
			)
		);
		// phpcs:enable
	}

	/**
	 * Fetch an attempt by key.
	 *
	 * @param string $attempt_key Attempt key.
	 * @return object|null
	 */
	public static function get( $attempt_key ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_key = %s", $attempt_key )
		);
		// phpcs:enable
	}

	/**
	 * Update an attempt.
	 *
	 * Each Edge resource id is written as soon as it is known, so a retry after a
	 * lost response resumes from the first missing one rather than recreating
	 * everything. Customers and addresses have no idempotency key of their own,
	 * so this is the only thing standing between a retry and duplicate records.
	 *
	 * @param string $attempt_key Attempt key.
	 * @param array  $fields      Column => value.
	 * @return bool
	 */
	public static function update( $attempt_key, array $fields ) {
		global $wpdb;

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			self::table_name(),
			$fields,
			array( 'attempt_key' => $attempt_key )
		);

		return false !== $updated;
	}

	/**
	 * Bind an attempt to the order that adopted it.
	 *
	 * @param string $attempt_key Attempt key.
	 * @param int    $order_id    Order ID.
	 * @return bool
	 */
	public static function adopt( $attempt_key, $order_id ) {
		return self::update(
			$attempt_key,
			array(
				'status'   => self::STATUS_ADOPTED,
				'order_id' => (int) $order_id,
			)
		);
	}

	/**
	 * Mark every other attempt for this session as superseded.
	 *
	 * Keeps an abandoned demand from being mounted again after the shopper has
	 * changed something and moved on to a new attempt.
	 *
	 * @param string $session_key Session identifier.
	 * @param string $keep        Attempt key to leave alone.
	 * @return void
	 */
	public static function supersede_others( $session_key, $keep ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s
				 WHERE session_key = %s AND attempt_key != %s AND status IN ( %s, %s )",
				self::STATUS_STALE,
				current_time( 'mysql', true ),
				$session_key,
				$keep,
				self::STATUS_CLAIMED,
				self::STATUS_PREPARED
			)
		);
		// phpcs:enable
	}

	/**
	 * Delete attempts that were never adopted.
	 *
	 * @param int $older_than_days Age threshold.
	 * @return int Rows removed.
	 */
	public static function purge( $older_than_days = 7 ) {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status != %s AND updated_at < %s",
				self::STATUS_ADOPTED,
				$cutoff
			)
		);
		// phpcs:enable
	}
}
