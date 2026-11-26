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

// Every table name goes in through a %i placeholder and every value through
// prepare(), so the SQL text itself is fixed. Direct queries are the point of
// this class: it is a queue in the plugin's own tables, where a cached row
// would be a stale one.
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
				"INSERT INTO %i (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, NULL, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = NULL, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
				$this->outbox_table(),
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
				"INSERT INTO %i (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, %s, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = %s, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
				$this->outbox_table(),
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
	 * One statement for many keys of one type, each row written as enqueue()
	 * writes it without a payload: a new row, or the existing one with its
	 * payload cleared, its version bumped and made due now, its attempts kept.
	 * With $keep_payload, an existing row that holds a payload (a deleted
	 * product's stored record) is left exactly as it is. Callers pass at most
	 * AGUMAIL_ENQUEUE_BATCH keys.
	 *
	 * @param string   $type         Outbox type.
	 * @param string[] $keys         Object keys.
	 * @param string   $now          Current time as a UTC DATETIME string.
	 * @param bool     $keep_payload Whether a row that holds a payload stays as it is.
	 */
	public function enqueue_many( $type, array $keys, $now, $keep_payload = false ) {
		global $wpdb;
		if ( ! $keys ) {
			return true;
		}
		$rows = array();
		$args = array( $this->outbox_table() );
		foreach ( $keys as $key ) {
			$rows[] = "(%s, %s, NULL, 1, 0, 'pending', %s, %s, %s)";
			array_push( $args, (string) $type, (string) $key, $now, $now, $now );
		}
		if ( $keep_payload ) {
			// No assignment touches `payload`, so every IF() reads the row's payload as it was.
			$update = "`version` = IF(`payload` IS NULL, `version` + 1, `version`), `status` = IF(`payload` IS NULL, 'pending', `status`), `available_at` = IF(`payload` IS NULL, %s, `available_at`), `updated_at` = IF(`payload` IS NULL, %s, `updated_at`)";
		} else {
			$update = "`payload` = NULL, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s";
		}
		array_push( $args, $now, $now );
		$sql = 'INSERT INTO %i (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`) VALUES '
			. implode( ', ', $rows )
			. ' ON DUPLICATE KEY UPDATE ' . $update;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from fixed SQL and placeholders only.
		return $this->write( $wpdb->prepare( $sql, $args ), sprintf( 'queue %d %s rows', count( $keys ), $type ) );
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
		if ( agumail_db_get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE `status` = 'pending' AND `available_at` <= %s LIMIT 1", $this->outbox_table(), $now ) ) ) {
			return true;
		}
		return (bool) agumail_db_get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE `dirty` = 1 LIMIT 1', $this->carts_table() ) );
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
			agumail_db_get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `type` = %s AND `object_key` = %s',
					$this->outbox_table(),
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
		return $this->outbox_row( agumail_db_get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', $this->outbox_table(), $id ), ARRAY_A ) );
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
		$rows = agumail_db_get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
             WHERE `status` = 'pending' AND `type` = %s AND `available_at` <= %s AND `id` > %d
             ORDER BY `id` ASC LIMIT %d",
				$this->outbox_table(),
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
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `id` = %d AND `version` = %d', $this->outbox_table(), $id, $version ) );
		if ( $deleted ) {
			return true;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET `attempts` = 0, `last_error` = NULL WHERE `id` = %d', $this->outbox_table(), $id ) );
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
		return (bool) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `id` = %d AND `version` = %d', $this->outbox_table(), $id, $version ) );
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
				"UPDATE %i SET `status` = 'failed', `last_error` = %s, `updated_at` = %s WHERE `id` = %d AND `version` = %d",
				$this->outbox_table(),
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
				'UPDATE %i SET `attempts` = %d, `available_at` = %s, `last_error` = %s, `status` = %s, `updated_at` = %s WHERE `id` = %d',
				$this->outbox_table(),
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
				"UPDATE %i SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `id` = %d",
				$this->outbox_table(),
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
				"UPDATE %i SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `status` = 'failed'",
				$this->outbox_table(),
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
			return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE `id` = %d AND `status` = 'failed' AND `version` = %d", $this->outbox_table(), $id, $version ) );
		}
		return (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE `id` = %d AND `status` = 'failed'", $this->outbox_table(), $id ) );
	}

	/**
	 * Deletes every failed row. Returns how many.
	 */
	public function discard_failed() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `status` = %s', $this->outbox_table(), 'failed' ) );
	}

	/**
	 * Waiting rows of the given types go: all of them, or with $before only
	 * those queued before it. Failed rows stay, for the merchant to see. One
	 * statement per type, each found through the (type, object_key) index.
	 *
	 * @param string[]    $types  Outbox types.
	 * @param string|null $before UTC DATETIME string, or null for all.
	 */
	public function discard_pending( array $types, $before = null ) {
		global $wpdb;
		$count = 0;
		foreach ( array_unique( array_map( 'strval', $types ) ) as $type ) {
			if ( null === $before ) {
				$count += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE `status` = 'pending' AND `type` = %s", $this->outbox_table(), $type ) );
			} else {
				$count += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE `status` = 'pending' AND `type` = %s AND `updated_at` < %s", $this->outbox_table(), $type, $before ) );
			}
		}
		return $count;
	}

	/** Every row, pending or failed: the queue belonged to another AguMail account. */
	public function clear() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->outbox_table() ) );
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
		$rows  = agumail_db_get_results(
			$wpdb->prepare(
				'SELECT `status`, `type`, COUNT(*) AS total, MIN(`created_at`) AS oldest FROM %i GROUP BY `status`, `type`',
				$this->outbox_table()
			),
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
		$stats['due'] = (int) agumail_db_get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE `status` = 'pending' AND `available_at` <= %s",
				$this->outbox_table(),
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
		$rows = agumail_db_get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE `status` = 'failed' ORDER BY `updated_at` DESC, `id` DESC LIMIT %d OFFSET %d",
				$this->outbox_table(),
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
		return $this->cart_row( agumail_db_get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %s', $this->carts_table(), $id ), ARRAY_A ) );
	}

	/**
	 * The newest cart for a WooCommerce session, among the given statuses (any when empty).
	 *
	 * @param string   $session_key WooCommerce session key.
	 * @param string[] $statuses    Cart statuses to match.
	 */
	public function cart_find_by_session( $session_key, array $statuses ) {
		global $wpdb;
		if ( ! $statuses ) {
			$row = agumail_db_get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `session_key` = %s ORDER BY `updated_at` DESC LIMIT 1',
					$this->carts_table(),
					$session_key
				),
				ARRAY_A
			);
		} else {
			$row = agumail_db_get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `session_key` = %s AND FIND_IN_SET(`status`, %s) ORDER BY `updated_at` DESC LIMIT 1',
					$this->carts_table(),
					$session_key,
					$this->set_list( $statuses )
				),
				ARRAY_A
			);
		}
		return $this->cart_row( $row );
	}

	/**
	 * A shopper's carts by email, newest first, among the given statuses (any when empty).
	 *
	 * @param string   $email    Lowercased email address.
	 * @param string[] $statuses Cart statuses to match.
	 * @param int      $limit    Most carts to return.
	 * @param int      $offset   Carts to skip.
	 */
	public function carts_by_email( $email, array $statuses, $limit, $offset = 0 ) {
		global $wpdb;
		if ( ! $statuses ) {
			$rows = agumail_db_get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `email` = %s ORDER BY `updated_at` DESC LIMIT %d OFFSET %d',
					$this->carts_table(),
					$email,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = agumail_db_get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `email` = %s AND FIND_IN_SET(`status`, %s) ORDER BY `updated_at` DESC LIMIT %d OFFSET %d',
					$this->carts_table(),
					$email,
					$this->set_list( $statuses ),
					$limit,
					$offset
				),
				ARRAY_A
			);
		}
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
		return (bool) $wpdb->delete( $this->carts_table(), array( 'id' => (string) $id ), array( '%s' ) );
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
		$args        = array( $this->carts_table(), $id );
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
		$sql         = 'INSERT INTO %i (' . implode( ', ', $columns ) . ', `version`, `queued_version`, `dirty`, `created_at`, `updated_at`)'
			. ' VALUES (' . implode( ', ', $values ) . ', 1, 0, 1, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from whitelisted column names and placeholders only.
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
		$args   = array( $this->carts_table() );
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
		$sql    = 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE `id` = %s';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from whitelisted column names and placeholders only.
		return (bool) $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Carts that changed since they were last moved into the outbox, found by their indexed flag.
	 *
	 * @param int $limit Most carts to return.
	 */
	public function carts_dirty( $limit ) {
		global $wpdb;
		$rows = agumail_db_get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `dirty` = 1 ORDER BY `updated_at` ASC LIMIT %d',
				$this->carts_table(),
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
				'UPDATE %i SET `queued_version` = %d, `dirty` = IF(`version` > %d, 1, 0) WHERE `id` = %s AND `queued_version` < %d',
				$this->carts_table(),
				$version,
				$version,
				$id,
				$version
			)
		);
	}

	/**
	 * Does what cart_mark_queued() does, for many carts in one statement: each
	 * cart records the version moved into the outbox, and its flag
	 * goes down only if it has not changed again since. Callers pass at most
	 * AGUMAIL_ENQUEUE_BATCH carts. Returns how many carts it changed.
	 *
	 * @param int[] $versions Version moved into the outbox, by cart id.
	 */
	public function carts_mark_queued( array $versions ) {
		global $wpdb;
		if ( ! $versions ) {
			return 0;
		}
		$when      = array();
		$when_args = array();
		$ids       = array();
		$id_args   = array();
		foreach ( $versions as $id => $version ) {
			$when[] = 'WHEN %s THEN %d';
			array_push( $when_args, (string) $id, (int) $version );
			$ids[]     = '%s';
			$id_args[] = (string) $id;
		}
		$version_moved = 'CASE `id` ' . implode( ' ', $when ) . ' END';
		// The flag is worked out from `version` alone and set first, so the
		// order MySQL applies the two assignments in does not matter.
		$sql  = 'UPDATE %i SET `dirty` = IF(`version` > ' . $version_moved . ', 1, 0), `queued_version` = ' . $version_moved
			. ' WHERE `id` IN (' . implode( ', ', $ids ) . ') AND `queued_version` < ' . $version_moved;
		$args = array_merge( array( $this->carts_table() ), $when_args, $when_args, $id_args, $when_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from fixed SQL and placeholders only.
		return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/** No cart waits to be moved into the outbox any more (store sync was held). */
	public function carts_clear_dirty() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `dirty` = 0 WHERE `dirty` = 1', $this->carts_table() ) );
	}

	/**
	 * How many carts were created from one address hash since $since. One indexed count.
	 *
	 * @param string $ip_hash Keyed hash of the address.
	 * @param string $since   UTC DATETIME string.
	 */
	public function carts_count_by_ip( $ip_hash, $since ) {
		global $wpdb;
		return (int) agumail_db_get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `ip_hash` = %s AND `created_at` >= %s',
				$this->carts_table(),
				$ip_hash,
				$since
			)
		);
	}

	/**
	 * Deletes carts last touched before $before, among the given statuses (any
	 * when empty). One statement per status, each a range of the
	 * (status, updated_at) index.
	 *
	 * @param string[] $statuses Cart statuses to match.
	 * @param string   $before   UTC DATETIME string.
	 */
	public function carts_purge( array $statuses, $before ) {
		global $wpdb;
		if ( ! $statuses ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `updated_at` < %s', $this->carts_table(), $before ) );
		}
		$count = 0;
		foreach ( array_unique( array_map( 'strval', $statuses ) ) as $status ) {
			$count += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `status` = %s AND `updated_at` < %s', $this->carts_table(), $status, $before ) );
		}
		return $count;
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
		$token = wp_generate_uuid4();
		$value = $token . '|' . ( (int) $now + (int) $ttl );
		// Fresh: another request may have taken or freed the lock since.
		$existing = (string) agumail_db_get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ), true );
		if ( '' === $existing ) {
			$taken = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$wpdb->options,
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
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
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
				'DELETE FROM %i WHERE option_name = %s AND option_value LIKE %s',
				$wpdb->options,
				$name,
				$wpdb->esc_like( $token . '|' ) . '%'
			)
		);
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Values as one comma-separated list for FIND_IN_SET(). Every status this
	 * class matches is one of the plugin's own fixed slugs, none with a comma;
	 * a value with one could match no stored status, so it is left out rather
	 * than split in two.
	 *
	 * @param array $values Values to match.
	 */
	private function set_list( array $values ) {
		return implode(
			',',
			array_filter(
				array_map( 'strval', $values ),
				function ( $value ) {
					return false === strpos( $value, ',' );
				}
			)
		);
	}
}
// phpcs:enable
