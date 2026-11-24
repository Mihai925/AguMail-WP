<?php
/**
 * Agumail_Store: the MySQL store behind the outbox, the carts table and the
 * locks. store.php explains the tables and holds the functions around it.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every table name is $wpdb->prefix plus a fixed name, written into the SQL;
// every value goes through prepare(). Direct queries are the point of this
// class: it is a queue in the plugin's own tables, where a cached row would be
// a stale one.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own queue tables, read and written live.

/**
 * The MySQL store: the outbox, the carts table and the locks, read and
 * written through $wpdb.
 */
class Agumail_Store {

	/**
	 * The outbox table's name, with the site's prefix.
	 */
	public function outbox_table() {
		global $wpdb;
		return $wpdb->prefix . 'agumail_outbox';
	}

	/**
	 * The carts table's name, with the site's prefix.
	 */
	public function carts_table() {
		global $wpdb;
		return $wpdb->prefix . 'agumail_carts';
	}

	// ── Outbox ──────────────────────────────────────────────────────────────

	/**
	 * One statement: a new row, or the existing (type, key) row with its
	 * payload replaced, its version bumped, and made due now. Attempts are
	 * kept, so a row that keeps failing still parks in the end.
	 *
	 * @param string      $type    Outbox type.
	 * @param string      $key     Object key.
	 * @param string|null $payload Payload as JSON, or null for none.
	 * @param string      $now     Current time as a UTC DATETIME string.
	 */
	public function enqueue( $type, $key, $payload, $now ) {
		global $wpdb;
		if ( null === $payload ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agumail_outbox (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, NULL, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = NULL, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
				$type,
				$key,
				$now,
				$now,
				$now,
				$now,
				$now
			);
		} else {
			$sql = $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}agumail_outbox (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, %s, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = %s, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
				$type,
				$key,
				$payload,
				$now,
				$now,
				$now,
				$payload,
				$now,
				$now
			);
		}
		return $this->write( $sql, sprintf( 'queue %s %s', $type, $key ) );
	}

	/**
	 * Runs a write, once more if the first try fails (a deadlock or lock wait), and logs a second failure.
	 *
	 * @param string $sql  A prepared statement.
	 * @param string $what What the write does, for the log.
	 */
	private function write( $sql, $what ) {
		global $wpdb;
		for ( $try = 1; $try <= 2; $try++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every caller passes the output of $wpdb->prepare().
			if ( false !== $wpdb->query( $sql ) ) {
				return true;
			}
		}
		agumail_log( 'error', sprintf( 'Could not %s: %s', $what, $wpdb->last_error ) );
		return false;
	}

	/**
	 * Whether a run has anything to do: a due row, or a cart that changed. Two indexed lookups.
	 *
	 * @param string $now Current time as a UTC DATETIME string.
	 */
	public function has_work( $now ) {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending' AND `available_at` <= %s LIMIT 1", $now ) ) ) {
			return true;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE `dirty` = 1 LIMIT 1', $wpdb->prefix . 'agumail_carts' ) );
	}

	/**
	 * The outbox row for a type and key, or null.
	 *
	 * @param string $type Outbox type.
	 * @param string $key  Object key.
	 */
	public function find( $type, $key ) {
		global $wpdb;
		return $this->outbox_row(
			$wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `type` = %s AND `object_key` = %s",
					$type,
					$key
				),
				ARRAY_A
			)
		);
	}

	/**
	 * The outbox row with an id, or null.
	 *
	 * @param int $id Row id.
	 */
	public function get( $id ) {
		global $wpdb;
		return $this->outbox_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d", $id ), ARRAY_A ) );
	}

	/**
	 * Pending rows of a type that are due, oldest first, after a given id.
	 *
	 * @param string $type     Outbox type.
	 * @param int    $limit    Most rows to return.
	 * @param string $now      Current time as a UTC DATETIME string.
	 * @param int    $after_id Only rows with a larger id.
	 */
	public function due( $type, $limit, $now, $after_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agumail_outbox
             WHERE `status` = 'pending' AND `type` = %s AND `available_at` <= %s AND `id` > %d
             ORDER BY `id` ASC LIMIT %d",
				$type,
				$now,
				$after_id,
				$limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'outbox_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Sent: the row goes, unless it changed meanwhile; then it stays for the next run, its failures forgiven.
	 *
	 * @param int $id      Row id.
	 * @param int $version Version that was sent.
	 */
	public function complete( $id, $version ) {
		global $wpdb;
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `version` = %d", $id, $version ) );
		if ( $deleted ) {
			return true;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}agumail_outbox SET `attempts` = 0, `last_error` = NULL WHERE `id` = %d", $id ) );
		return false;
	}

	/**
	 * Dropped by its builder: the row goes, unless it changed meanwhile.
	 *
	 * @param int $id      Row id.
	 * @param int $version Version that was built.
	 */
	public function drop( $id, $version ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `version` = %d", $id, $version ) );
	}

	/**
	 * AguMail refused the record itself: it waits in the failed list, unless it changed meanwhile.
	 *
	 * @param int    $id      Row id.
	 * @param int    $version Version that was refused.
	 * @param string $error   Why AguMail refused it.
	 * @param string $now     Current time as a UTC DATETIME string.
	 */
	public function park( $id, $version, $error, $now ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'failed', `last_error` = %s, `updated_at` = %s WHERE `id` = %d AND `version` = %d",
				$error,
				$now,
				$id,
				$version
			)
		);
	}

	/**
	 * Could not be delivered: try again at $available_at, or park it for good when $failed.
	 *
	 * @param int    $id           Row id.
	 * @param int    $attempts     Attempts so far, this one included.
	 * @param string $available_at UTC DATETIME string.
	 * @param string $error        Why it was not delivered.
	 * @param bool   $failed       Whether to park it for good.
	 * @param string $now          Current time as a UTC DATETIME string.
	 */
	public function defer( $id, $attempts, $available_at, $error, $failed, $now ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agumail_outbox SET `attempts` = %d, `available_at` = %s, `last_error` = %s, `status` = %s, `updated_at` = %s WHERE `id` = %d",
				$attempts,
				$available_at,
				$error,
				$failed ? 'failed' : 'pending',
				$now,
				$id
			)
		);
	}

	/**
	 * Puts a row back in line now, its attempts and error forgotten.
	 *
	 * @param int    $id  Row id.
	 * @param string $now Current time as a UTC DATETIME string.
	 */
	public function retry( $id, $now ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `id` = %d",
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Puts every failed row back in line now. Returns how many.
	 *
	 * @param string $now Current time as a UTC DATETIME string.
	 */
	public function retry_failed( $now ) {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `status` = 'failed'",
				$now,
				$now
			)
		);
	}

	/**
	 * A failed row goes; with $version, only if it has not changed since (a newer answer is never dropped).
	 *
	 * @param int      $id      Row id.
	 * @param int|null $version Version the row must still have, or null for any.
	 */
	public function discard( $id, $version = null ) {
		global $wpdb;
		if ( null !== $version ) {
			return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `status` = 'failed' AND `version` = %d", $id, $version ) );
		}
		return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `status` = 'failed'", $id ) );
	}

	/**
	 * Deletes every failed row. Returns how many.
	 */
	public function discard_failed() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `status` = %s', $wpdb->prefix . 'agumail_outbox', 'failed' ) );
	}

	/**
	 * Waiting rows of the given types go: all of them, or with $before only
	 * those queued before it. Failed rows stay, for the merchant to see.
	 *
	 * @param string[]    $types  Outbox types.
	 * @param string|null $before UTC DATETIME string, or null for all.
	 */
	public function discard_pending( array $types, $before = null ) {
		global $wpdb;
		list($type_sql, $type_args) = $this->in_clause( 'type', $types );
		if ( '' === $type_sql ) {
			return 0;
		}
		$before_sql = null !== $before ? ' AND `updated_at` < %s' : '';
		$args       = null !== $before ? array_merge( $type_args, array( $before ) ) : $type_args;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $type_sql is an IN () list of placeholders and $before_sql one placeholder; their values are in the arguments.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- as above.
				"DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending'{$type_sql}{$before_sql}",
				$args
			)
		);
	}

	/** Every row, pending or failed: the queue belonged to another AguMail account. */
	public function clear() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'agumail_outbox' ) );
	}

	/**
	 * Queue depth for the status panel: counts by status and type, due now, and the oldest pending row.
	 *
	 * @param string $now Current time as a UTC DATETIME string.
	 */
	public function stats( $now ) {
		global $wpdb;
		$stats = array(
			'pending'           => 0,
			'failed'            => 0,
			'due'               => 0,
			'oldest_pending_at' => null,
			'by_type'           => array(),
		);
		$rows  = $wpdb->get_results(
			"SELECT `status`, `type`, COUNT(*) AS total, MIN(`created_at`) AS oldest FROM {$wpdb->prefix}agumail_outbox GROUP BY `status`, `type`",
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status            = 'failed' === $row['status'] ? 'failed' : 'pending';
			$stats[ $status ] += (int) $row['total'];
			if ( ! isset( $stats['by_type'][ $row['type'] ] ) ) {
				$stats['by_type'][ $row['type'] ] = array(
					'pending' => 0,
					'failed'  => 0,
				);
			}
			$stats['by_type'][ $row['type'] ][ $status ] += (int) $row['total'];
			if ( 'pending' === $status && ( null === $stats['oldest_pending_at'] || $row['oldest'] < $stats['oldest_pending_at'] ) ) {
				$stats['oldest_pending_at'] = $row['oldest'];
			}
		}
		$stats['due'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending' AND `available_at` <= %s",
				$now
			)
		);
		return $stats;
	}

	/**
	 * Failed rows, newest first.
	 *
	 * @param int $limit  Most rows to return.
	 * @param int $offset Rows to skip.
	 */
	public function failed( $limit, $offset ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'failed' ORDER BY `updated_at` DESC, `id` DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		return array_map( array( $this, 'outbox_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Casts a raw outbox row, or returns null for none.
	 *
	 * @param array|null $row A row as $wpdb returns it, or null.
	 */
	public function outbox_row( $row ) {
		return $row ? agumail_outbox_row_types( $row ) : null;
	}

	// ── Carts ───────────────────────────────────────────────────────────────

	/**
	 * The cart with an id, or null.
	 *
	 * @param string $id Cart id.
	 */
	public function cart_get( $id ) {
		global $wpdb;
		return $this->cart_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `id` = %s", $id ), ARRAY_A ) );
	}

	/**
	 * The newest cart for a WooCommerce session, among the given statuses (any when empty).
	 *
	 * @param string   $session_key WooCommerce session key.
	 * @param string[] $statuses    Cart statuses to match.
	 */
	public function cart_find_by_session( $session_key, array $statuses ) {
		global $wpdb;
		list($status_sql, $status_args) = $this->in_clause( 'status', $statuses );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_sql is an IN () list of placeholders; its values are in the arguments.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
				"SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `session_key` = %s{$status_sql} ORDER BY `updated_at` DESC LIMIT 1",
				array_merge( array( $session_key ), $status_args )
			),
			ARRAY_A
		);
		return $this->cart_row( $row );
	}

	/**
	 * A shopper's carts by email, newest first, among the given statuses (any when empty).
	 *
	 * @param string   $email    Lowercased email address.
	 * @param string[] $statuses Cart statuses to match.
	 * @param int      $limit    Most carts to return.
	 */
	public function carts_by_email( $email, array $statuses, $limit ) {
		global $wpdb;
		list($status_sql, $status_args) = $this->in_clause( 'status', $statuses );
		$rows                           = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the IN () list adds placeholders; their values are in the same array.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
				"SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `email` = %s{$status_sql} ORDER BY `updated_at` DESC LIMIT %d",
				array_merge( array( $email ), $status_args, array( $limit ) )
			),
			ARRAY_A
		);
		return array_map( array( $this, 'cart_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Deletes a cart, for a shopper's erasure request.
	 *
	 * @param string $id Cart id.
	 * @return bool Whether a cart was deleted.
	 */
	public function cart_delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'agumail_carts', array( 'id' => (string) $id ), array( '%s' ) );
	}

	/**
	 * One statement: inserts the cart, or updates the given columns of an
	 * existing one; either way the version moves on and the cart is flagged
	 * as changed. $insert_only values (a new cart's token) apply to an insert.
	 *
	 * @param string $id          Cart id.
	 * @param array  $fields      Column values by name.
	 * @param string $now         Current time as a UTC DATETIME string.
	 * @param array  $insert_only Column values written only to a new cart.
	 */
	public function cart_save( $id, array $fields, $now, array $insert_only = array() ) {
		global $wpdb;
		$fields      = agumail_cart_known_columns( $fields );
		$insert_only = agumail_cart_known_columns( $insert_only );
		$defaults    = array(
			'token'       => '',
			'session_key' => '',
			'user_id'     => null,
			'email'       => null,
			'lines'       => '[]',
			'total'       => '0',
			'currency'    => '',
			'status'      => 'open',
			'order_id'    => null,
		);
		$insert      = array_merge( $defaults, $insert_only, $fields );
		$columns     = array( '`id`' );
		$values      = array( '%s' );
		$args        = array( $id );
		foreach ( $insert as $column => $value ) {
			$columns[] = '`' . $column . '`';
			if ( null === $value ) {
				$values[] = 'NULL';
			} else {
				$values[] = '%s';
				$args[]   = (string) $value;
			}
		}
		$updates = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$updates[] = '`' . $column . '` = NULL';
			} else {
				$updates[] = '`' . $column . '` = %s';
			}
		}
		$update_args = array_values(
			array_map(
				'strval',
				array_filter(
					$fields,
					function ( $value ) {
						return null !== $value;
					}
				)
			)
		);
		$updates[]   = '`version` = `version` + 1';
		$updates[]   = '`dirty` = 1';
		$updates[]   = '`updated_at` = %s';
		$sql         = "INSERT INTO {$wpdb->prefix}agumail_carts (" . implode( ', ', $columns ) . ', `version`, `queued_version`, `dirty`, `created_at`, `updated_at`)'
			. ' VALUES (' . implode( ', ', $values ) . ', 1, 0, 1, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from the table name, whitelisted column names and placeholders only.
		return $this->write( $wpdb->prepare( $sql, array_merge( $args, array( $now, $now ), $update_args, array( $now ) ) ), 'save cart ' . $id );
	}

	/**
	 * Updates some columns of a cart; $bump makes the change one the drain will send.
	 *
	 * @param string $id     Cart id.
	 * @param array  $fields Column values by name.
	 * @param bool   $bump   Whether the drain should send the change.
	 * @param string $now    Current time as a UTC DATETIME string.
	 */
	public function cart_update( $id, array $fields, $bump, $now ) {
		global $wpdb;
		$fields = agumail_cart_known_columns( $fields );
		$sets   = array();
		$args   = array();
		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$sets[] = '`' . $column . '` = NULL';
			} else {
				$sets[] = '`' . $column . '` = %s';
				$args[] = (string) $value;
			}
		}
		if ( $bump ) {
			$sets[] = '`version` = `version` + 1';
			$sets[] = '`dirty` = 1';
		}
		$sets[] = '`updated_at` = %s';
		$args[] = $now;
		$args[] = $id;
		$sql    = "UPDATE {$wpdb->prefix}agumail_carts SET " . implode( ', ', $sets ) . ' WHERE `id` = %s';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from the table name, whitelisted column names and placeholders only.
		return (bool) $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Carts that changed since they were last moved into the outbox, found by their indexed flag.
	 *
	 * @param int $limit Most carts to return.
	 */
	public function carts_dirty( $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `dirty` = 1 ORDER BY `updated_at` ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'cart_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Records the version moved into the outbox; the flag stays up if the cart changed again meanwhile.
	 *
	 * @param string $id      Cart id.
	 * @param int    $version Version moved into the outbox.
	 */
	public function cart_mark_queued( $id, $version ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}agumail_carts SET `queued_version` = %d, `dirty` = IF(`version` > %d, 1, 0) WHERE `id` = %s AND `queued_version` < %d",
				$version,
				$version,
				$id,
				$version
			)
		);
	}

	/** No cart waits to be moved into the outbox any more (store sync was held). */
	public function carts_clear_dirty() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `dirty` = 0 WHERE `dirty` = 1', $wpdb->prefix . 'agumail_carts' ) );
	}

	/**
	 * How many carts were created from one address hash since $since. One indexed count.
	 *
	 * @param string $ip_hash Keyed hash of the address.
	 * @param string $since   UTC DATETIME string.
	 */
	public function carts_count_by_ip( $ip_hash, $since ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}agumail_carts WHERE `ip_hash` = %s AND `created_at` >= %s",
				$ip_hash,
				$since
			)
		);
	}

	/**
	 * Deletes carts last touched before $before, among the given statuses (any when empty).
	 *
	 * @param string[] $statuses Cart statuses to match.
	 * @param string   $before   UTC DATETIME string.
	 */
	public function carts_purge( array $statuses, $before ) {
		global $wpdb;
		list($status_sql, $status_args) = $this->in_clause( 'status', $statuses );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_sql is an IN () list of placeholders; its values are in the arguments.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
				"DELETE FROM {$wpdb->prefix}agumail_carts WHERE `updated_at` < %s{$status_sql}",
				array_merge( array( $before ), $status_args )
			)
		);
	}

	/**
	 * Casts a raw carts row, or returns null for none.
	 *
	 * @param array|null $row A row as $wpdb returns it, or null.
	 */
	public function cart_row( $row ) {
		return $row ? agumail_cart_row_types( $row ) : null;
	}

	// ── Locks ───────────────────────────────────────────────────────────────
	// An option row holding "token|expiry", taken with an insert or, once
	// expired, a compare-and-swap, as Action Scheduler takes its own lock.

	/**
	 * Takes a lock for $ttl seconds. Returns its token, or false while someone else holds it.
	 *
	 * @param string $name Option name of the lock.
	 * @param int    $ttl  Seconds the lock holds.
	 * @param int    $now  Current Unix timestamp.
	 */
	public function acquire_lock( $name, $ttl, $now ) {
		global $wpdb;
		$token    = wp_generate_uuid4();
		$value    = $token . '|' . ( (int) $now + (int) $ttl );
		$existing = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( '' === $existing ) {
			$taken = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					$value
				)
			);
		} else {
			$parts = explode( '|', $existing );
			if ( (int) end( $parts ) >= (int) $now ) {
				return false;
			}
			$taken = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					$name,
					$existing
				)
			);
		}
		wp_cache_delete( $name, 'options' );
		return $taken ? $token : false;
	}

	/**
	 * Releases a lock, if $token still holds it.
	 *
	 * @param string $name  Option name of the lock.
	 * @param string $token Token acquire_lock() returned.
	 */
	public function release_lock( $name, $token ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$name,
				$wpdb->esc_like( $token . '|' ) . '%'
			)
		);
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * An AND ... IN () clause of placeholders and its values, or an empty clause when there are no values.
	 *
	 * @param string $column Column name.
	 * @param array  $values Values to match.
	 */
	private function in_clause( $column, array $values ) {
		if ( ! $values ) {
			return array( '', array() );
		}
		return array( ' AND `' . $column . '` IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')', array_values( $values ) );
	}
}
// phpcs:enable
