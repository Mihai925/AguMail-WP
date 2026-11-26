<?php
/**
 * The plugin's own tables, and the only code that reads or writes them.
 *
 * The outbox holds one row per thing AguMail must hear about (an order, a
 * product, a contact...). A storefront hook records a change with one upsert
 * and moves on; the drain builds the record and sends it later. A row keeps a
 * version that every change bumps, so a row that changed while it was being
 * sent is kept and sent again rather than deleted.
 *
 * The carts table holds each shopper's live cart. A cart change is one write to
 * its row, which bumps its version and raises its dirty flag; the drain moves
 * flagged rows into the outbox and lowers the flag only if the cart has not
 * changed again since it read it.
 *
 * Agumail_Store talks to MySQL through $wpdb. The test harness swaps in an
 * in-memory store with the same methods through agumail_set_store().
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_KEY_MAX', 191 );
define( 'AGUMAIL_ERROR_MAX', 1000 );
define( 'AGUMAIL_CART_COLUMNS', 'token,session_key,user_id,email,lines,total,currency,status,order_id,ip_hash' );
define( 'AGUMAIL_ENQUEUE_BATCH', 200 );

require_once __DIR__ . '/class-agumail-store.php';

// ─────────────────────────────────────────────────────────────────────────────
// Which store, and what time it is. The harness replaces both.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The store in use: an Agumail_Store, unless the harness set another.
 */
function agumail_store() {
	if ( ! isset( $GLOBALS['agumail_store'] ) ) {
		$GLOBALS['agumail_store'] = new Agumail_Store();
	}
	return $GLOBALS['agumail_store'];
}

/**
 * Replaces the store.
 *
 * @param object $store An object with Agumail_Store's methods.
 */
function agumail_set_store( $store ) {
	$GLOBALS['agumail_store'] = $store;
}

/** Seconds since the epoch, with fractions: the drain's clock. */
function agumail_clock() {
	return isset( $GLOBALS['agumail_clock'] ) && is_callable( $GLOBALS['agumail_clock'] )
		? (float) call_user_func( $GLOBALS['agumail_clock'] )
		: microtime( true );
}

/**
 * Replaces the clock (null restores the real one).
 *
 * @param callable|null $clock Returns seconds since the epoch.
 */
function agumail_set_clock( $clock ) {
	$GLOBALS['agumail_clock'] = $clock;
}

/**
 * Seconds since the epoch, as a whole number.
 */
function agumail_time() {
	return (int) floor( agumail_clock() );
}

/**
 * A UTC DATETIME string for the tables.
 *
 * @param int|null $timestamp Unix timestamp, or null for now.
 */
function agumail_mysql_time( $timestamp = null ) {
	return gmdate( 'Y-m-d H:i:s', null === $timestamp ? agumail_time() : (int) $timestamp );
}

// ─────────────────────────────────────────────────────────────────────────────
// The outbox.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Records that AguMail must hear about something, and nothing more: one upsert,
 * no call. It is the only write a storefront hook may make, besides the carts
 * row. $payload, when given, is stored as JSON and replaces the one before; a
 * builder usually builds the record at send time instead, from fresh data.
 *
 * Does nothing (and returns false) before the tables exist, while the store is
 * not connected, or inside the drain, whose own meta writes must not queue the
 * same things again.
 *
 * @param string     $type    Outbox type.
 * @param int|string $key     Object key: an id, or a contact's email.
 * @param array|null $payload The record, or null to build it at send time.
 */
function agumail_enqueue( $type, $key, $payload = null ) {
	if ( ! agumail_ready() || ! agumail_has_api_key() || agumail_enqueue_suppressed() || ! agumail_may_queue( $type ) ) {
		return false;
	}
	$type = (string) $type;
	$key  = agumail_outbox_key( $type, $key );
	if ( '' === $key ) {
		return false;
	}
	if ( is_array( $payload ) && agumail_is_contact_type( $type ) ) {
		$existing = agumail_store()->find( $type, $key );
		if ( $existing && 'pending' === $existing['status'] && is_array( $existing['payload'] ) ) {
			$payload = agumail_merge_queued_contact( $existing['payload'], $payload );
		}
	}
	$json = null;
	if ( null !== $payload ) {
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			agumail_log( 'error', sprintf( 'Could not queue %s %s: its data is not valid JSON.', $type, $key ) );
			return false;
		}
	}
	return agumail_store()->enqueue( $type, $key, $json, agumail_mysql_time() );
}

/**
 * Records that AguMail must hear about many objects of one type, each as
 * agumail_enqueue() would without a payload, and under the same conditions:
 * nothing before the tables exist, while the store is not connected, inside
 * the drain, or for a type the plan does not queue. One statement per
 * AGUMAIL_ENQUEUE_BATCH keys.
 *
 * With $keep_payload, a row already queued with a payload (a deleted
 * product's stored record) is left as it is.
 *
 * Returns the keys, as given, whose rows were written.
 *
 * @param string $type         Outbox type.
 * @param array  $keys         Object keys: ids, or contacts' emails.
 * @param bool   $keep_payload Whether a row that holds a payload stays as it is.
 * @return array
 */
function agumail_enqueue_many( $type, array $keys, $keep_payload = false ) {
	if ( ! $keys || ! agumail_ready() || ! agumail_has_api_key() || agumail_enqueue_suppressed() || ! agumail_may_queue( $type ) ) {
		return array();
	}
	$type  = (string) $type;
	$given = array();
	$rows  = array();
	foreach ( $keys as $key ) {
		$row_key = agumail_outbox_key( $type, $key );
		if ( '' !== $row_key ) {
			$given[] = $key;
			$rows[]  = $row_key;
		}
	}
	$queued = array();
	$now    = agumail_mysql_time();
	foreach ( array_chunk( array_keys( $rows ), AGUMAIL_ENQUEUE_BATCH ) as $chunk ) {
		$chunk_rows = array();
		foreach ( $chunk as $i ) {
			$chunk_rows[] = $rows[ $i ];
		}
		if ( agumail_store()->enqueue_many( $type, $chunk_rows, $now, (bool) $keep_payload ) ) {
			foreach ( $chunk as $i ) {
				$queued[] = $given[ $i ];
			}
		}
	}
	return $queued;
}

/**
 * Store data is queued only for a site AguMail took as a store: carts while
 * store sync is on, orders and products also while the plan holds it, so they
 * go once it is back. Everything else (contacts, accounts, signups, checkout
 * subscribers) works on every plan.
 *
 * @param string $type Outbox type.
 */
function agumail_may_queue( $type ) {
	if ( 'cart' === $type ) {
		return agumail_store_sync_on();
	}
	if ( 'order' === $type || 'product' === $type ) {
		return agumail_store_connected();
	}
	return true;
}

/**
 * The key a row is stored under: contacts by lowercased email, and anything
 * longer than the column holds shortened with a hash, so it stays unique.
 *
 * @param string     $type Outbox type.
 * @param int|string $key  Object key.
 */
function agumail_outbox_key( $type, $key ) {
	$key = trim( (string) $key );
	if ( agumail_is_contact_type( $type ) ) {
		$key = strtolower( $key );
	}
	if ( strlen( $key ) > AGUMAIL_KEY_MAX ) {
		$key = substr( $key, 0, AGUMAIL_KEY_MAX - 33 ) . '~' . md5( $key );
	}
	return $key;
}

/**
 * Whether an outbox type holds contacts.
 *
 * @param string $type Outbox type.
 */
function agumail_is_contact_type( $type ) {
	return 'contact' === $type || 'contact_backfill' === $type;
}

/**
 * While the drain runs, the changes it makes itself (sync flags, meta) queue nothing.
 *
 * @param bool|null $set True or false to set it, null to only read it.
 */
function agumail_enqueue_suppressed( $set = null ) {
	static $suppressed = false;
	if ( null !== $set ) {
		$suppressed = (bool) $set;
	}
	return $suppressed;
}

/**
 * Casts a raw outbox row and decodes its payload.
 *
 * @param array $row A raw outbox row.
 */
function agumail_outbox_row_types( array $row ) {
	$row['id']       = (int) $row['id'];
	$row['version']  = (int) $row['version'];
	$row['attempts'] = (int) $row['attempts'];
	if ( isset( $row['payload'] ) && is_string( $row['payload'] ) && '' !== $row['payload'] ) {
		$decoded        = json_decode( $row['payload'], true );
		$row['payload'] = is_array( $decoded ) ? $decoded : null;
	} else {
		$row['payload'] = null;
	}
	return $row;
}

/**
 * The outbox row with an id, or null.
 *
 * @param int $id Row id.
 */
function agumail_outbox_row( $id ) {
	return agumail_store()->get( (int) $id );
}

/**
 * The failed list, newest first.
 *
 * @param int $limit  Most rows to return.
 * @param int $offset Rows to skip.
 */
function agumail_outbox_failed( $limit = 50, $offset = 0 ) {
	return agumail_store()->failed( max( 1, (int) $limit ), max( 0, (int) $offset ) );
}

/** {pending, failed, due, oldest_pending_at (UTC DATETIME or null), by_type: {type: {pending, failed}}} */
function agumail_outbox_stats() {
	return agumail_store()->stats( agumail_mysql_time() );
}

/**
 * Puts a failed or waiting row back in line now, its attempts forgotten.
 *
 * @param int $id Row id.
 */
function agumail_outbox_retry( $id ) {
	return agumail_store()->retry( (int) $id, agumail_mysql_time() );
}

/**
 * Puts every failed row back in line now. Returns how many.
 */
function agumail_outbox_retry_failed() {
	return agumail_store()->retry_failed( agumail_mysql_time() );
}

/**
 * Deletes a failed row; with $version, only if it has not changed since.
 *
 * @param int      $id      Row id.
 * @param int|null $version Version the row must still have, or null for any.
 */
function agumail_outbox_discard( $id, $version = null ) {
	return agumail_store()->discard( (int) $id, null === $version ? null : (int) $version );
}

/**
 * Deletes every failed row. Returns how many.
 */
function agumail_outbox_discard_failed() {
	return agumail_store()->discard_failed();
}

/** Empties the outbox. Used when the store connects to another AguMail account. */
function agumail_outbox_clear() {
	return agumail_store()->clear();
}

// ─────────────────────────────────────────────────────────────────────────────
// The carts table. A row: {id, token, session_key, user_id, email, lines (a
// list of {product_id, variation_id, variation, qty, line_total}), total,
// currency, status (open, ordered, emptied), version, queued_version, dirty,
// order_id, ip_hash (a keyed hash of the address the cart came from, for the
// capture limit), created_at, updated_at}.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The cart with an id, or null.
 *
 * @param string $id Cart id.
 */
function agumail_cart_get( $id ) {
	return agumail_store()->cart_get( (string) $id );
}

/**
 * The newest cart for a WooCommerce session, among the given statuses (any when empty).
 *
 * @param string   $session_key WooCommerce session key.
 * @param string[] $statuses    Cart statuses to match.
 */
function agumail_cart_find_by_session( $session_key, array $statuses = array( 'open' ) ) {
	return agumail_store()->cart_find_by_session( (string) $session_key, $statuses );
}

/**
 * A shopper's carts by email, newest first, among the given statuses (any when empty).
 *
 * @param string   $email    Email address.
 * @param string[] $statuses Cart statuses to match.
 * @param int      $limit    Most carts to return.
 */
function agumail_carts_by_email( $email, array $statuses = array( 'open' ), $limit = 20 ) {
	return agumail_store()->carts_by_email( strtolower( trim( (string) $email ) ), $statuses, max( 1, (int) $limit ) );
}

/**
 * Inserts or updates a cart in one write, and moves its version on so the
 * drain sends it. $fields are any of token, session_key, user_id, email, lines
 * (an array), total, currency, status, order_id. A new cart without a token
 * gets a random one; an empty token is never written.
 *
 * @param string $id     Cart id.
 * @param array  $fields Column values by name.
 */
function agumail_cart_save( $id, array $fields ) {
	if ( ! agumail_ready() ) {
		return false;
	}
	$fields = agumail_cart_columns( $fields );
	if ( isset( $fields['token'] ) && '' === trim( (string) $fields['token'] ) ) {
		unset( $fields['token'] );
	}
	return agumail_store()->cart_save( (string) $id, $fields, agumail_mysql_time(), array( 'token' => agumail_new_cart_token() ) );
}

/** 32 random hex characters: the secret in a cart's restore link. */
function agumail_new_cart_token() {
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		return md5( uniqid( (string) wp_rand(), true ) );
	}
}

/**
 * Whether $token restores $cart. A cart with no token is never restored.
 *
 * @param array|null $cart  A cart row, or null.
 * @param mixed      $token Token from the restore link, as the request gave it; only a non-empty string can match.
 */
function agumail_cart_token_valid( $cart, $token ) {
	if ( ! is_array( $cart ) || empty( $cart['token'] ) || ! is_string( $token ) || '' === $token ) {
		return false;
	}
	return hash_equals( (string) $cart['token'], $token );
}

/**
 * Updates a cart; with $bump false the change is bookkeeping the drain need not send. An empty token is never written.
 *
 * @param string $id     Cart id.
 * @param array  $fields Column values by name.
 * @param bool   $bump   Whether the drain should send the change.
 */
function agumail_cart_update( $id, array $fields, $bump = true ) {
	if ( ! agumail_ready() ) {
		return false;
	}
	$fields = agumail_cart_columns( $fields );
	if ( isset( $fields['token'] ) && '' === trim( (string) $fields['token'] ) ) {
		unset( $fields['token'] );
	}
	return agumail_store()->cart_update( (string) $id, $fields, (bool) $bump, agumail_mysql_time() );
}

/**
 * Carts that changed since they were last moved into the outbox.
 *
 * @param int $limit Most carts to return.
 */
function agumail_carts_dirty( $limit = 500 ) {
	return agumail_store()->carts_dirty( max( 1, (int) $limit ) );
}

/**
 * Records the version of a cart moved into the outbox.
 *
 * @param string $id      Cart id.
 * @param int    $version Version moved into the outbox.
 */
function agumail_cart_mark_queued( $id, $version ) {
	return agumail_store()->cart_mark_queued( (string) $id, (int) $version );
}

/**
 * How many carts were created from an address hash (see the carts feature) since $since_timestamp.
 *
 * @param string $ip_hash         Keyed hash of the address.
 * @param int    $since_timestamp Unix timestamp.
 */
function agumail_carts_count_by_ip( $ip_hash, $since_timestamp ) {
	return agumail_store()->carts_count_by_ip( (string) $ip_hash, agumail_mysql_time( $since_timestamp ) );
}

/**
 * Deletes carts untouched since $before_timestamp, among the given statuses (any when empty). Returns how many.
 *
 * @param string[] $statuses         Cart statuses to match.
 * @param int      $before_timestamp Unix timestamp.
 */
function agumail_carts_purge( array $statuses, $before_timestamp ) {
	return agumail_store()->carts_purge( $statuses, agumail_mysql_time( $before_timestamp ) );
}

/**
 * Queues every cart that changed since it was last queued, and remembers the
 * version it queued. For the drain's agumail_drain_move_carts step.
 *
 * Each batch of AGUMAIL_ENQUEUE_BATCH carts is two statements: one upsert of
 * their outbox rows, then one update that records the versions queued (a
 * cart that changed again since it was read keeps its flag). A batch whose
 * upsert failed is not marked, so it is moved again on the next run.
 *
 * @param int $limit Most carts to move.
 */
function agumail_carts_move_dirty_to_outbox( $limit = 500 ) {
	$moved = 0;
	foreach ( array_chunk( agumail_carts_dirty( $limit ), AGUMAIL_ENQUEUE_BATCH ) as $carts ) {
		$versions = array();
		foreach ( $carts as $cart ) {
			$versions[ (string) $cart['id'] ] = (int) $cart['version'];
		}
		$queued = agumail_enqueue_many( 'cart', array_map( 'strval', array_keys( $versions ) ) );
		if ( ! $queued ) {
			continue;
		}
		$marked = array();
		foreach ( $queued as $id ) {
			$marked[ $id ] = $versions[ $id ];
		}
		agumail_store()->carts_mark_queued( $marked );
		$moved += count( $queued );
	}
	return $moved;
}

/**
 * Only the carts table's own columns: no other key ever reaches the SQL as a column name.
 *
 * @param array $fields Column values by name.
 */
function agumail_cart_known_columns( array $fields ) {
	return array_intersect_key( $fields, array_flip( explode( ',', AGUMAIL_CART_COLUMNS ) ) );
}

/**
 * Only known columns, with lines as JSON and money as a plain decimal.
 *
 * @param array $fields Column values by name.
 */
function agumail_cart_columns( array $fields ) {
	$allowed = array_flip( explode( ',', AGUMAIL_CART_COLUMNS ) );
	$columns = array();
	foreach ( $fields as $column => $value ) {
		if ( ! isset( $allowed[ $column ] ) ) {
			continue;
		}
		if ( 'lines' === $column ) {
			$value = wp_json_encode( array_values( is_array( $value ) ? $value : array() ) );
		} elseif ( 'total' === $column ) {
			$value = number_format( (float) $value, 4, '.', '' );
		} elseif ( 'email' === $column && null !== $value ) {
			$value = strtolower( trim( (string) $value ) );
		} elseif ( ( 'user_id' === $column || 'order_id' === $column ) && null !== $value ) {
			$value = (int) $value;
		}
		$columns[ $column ] = $value;
	}
	return $columns;
}

/**
 * Casts a raw carts row and decodes its lines.
 *
 * @param array $row A raw carts row.
 */
function agumail_cart_row_types( array $row ) {
	$row['version']        = (int) $row['version'];
	$row['queued_version'] = (int) $row['queued_version'];
	$row['dirty']          = isset( $row['dirty'] ) ? (int) $row['dirty'] : (int) ( $row['version'] > $row['queued_version'] );
	$row['user_id']        = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;
	$row['order_id']       = isset( $row['order_id'] ) ? (int) $row['order_id'] : null;
	$row['total']          = (float) $row['total'];
	$lines                 = isset( $row['lines'] ) && is_string( $row['lines'] ) ? json_decode( $row['lines'], true ) : null;
	$row['lines']          = is_array( $lines ) ? $lines : array();
	return $row;
}
