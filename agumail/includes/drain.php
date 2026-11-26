<?php
/**
 * The drain: the one place queued changes are sent to AguMail.
 *
 * Action Scheduler runs it every minute (agumail_drain), and again straight
 * away (agumail_drain_now) when a run stops for time or memory with work left.
 * A run:
 *   1. ends at once when nothing is due and no cart changed;
 *   2. takes a lock, so two runs never send the same rows;
 *   3. lets the carts feature move changed carts into the outbox;
 *   4. sends due rows type by type in phase order (contacts, form signups,
 *      products, orders, carts), a batch per request of at most the builder's
 *      size (500 at most) and 4 MB, round after round until nothing is due,
 *      about 20 seconds have gone, or memory runs low.
 *
 * A builder, registered per outbox type with agumail_register_builder(), turns a
 * row into the record AguMail takes, and names the contacts that must exist
 * first. Those contacts go in their own request before the batch; a record
 * whose contact AguMail refused is never sent. Within one run, every contact
 * sent is remembered, so an older answer can never overtake a newer one.
 *
 * What AguMail answers decides what happens to the rows:
 *   2xx            sent: rows that did not change meanwhile are deleted
 *   400, 422       a record the error names ("orders.3.email: ...") is parked
 *                  as failed and the rest go again; an error about the batch as
 *                  a whole ("orders: ...") halves it; any other error is about
 *                  the request, not a record, so the batch waits and retries
 *   413            the batch is halved; a record too large alone is parked
 *   401, 402, 403  the key or plan was refused: everything waits 15 minutes and
 *                  the settings page shows why; no row counts an attempt
 *   402 storeSync  the plan has no store sync: only store data (orders, carts,
 *                  products) is held, and everything else carries on; see
 *                  "Store sync" below
 *   429            everything waits for Retry-After
 *   5xx, network   the batch retries after 30 s, doubling to at most 6 h, and
 *                  parks after 20 attempts; a timeout also halves that type's
 *                  batch for a day
 *
 * For the features that build on it:
 *   - agumail_enqueue() does nothing while the drain runs, so a builder or an
 *     on_success callback that saves an order or a user queues nothing.
 *   - An Action Scheduler callback that calls AguMail must be registered with
 *     agumail_add_background_action(); the HTTP guard refuses any other.
 *   - The $key a builder receives is the stored object_key: a contact's email
 *     comes lowercased, and a key longer than 191 characters comes shortened
 *     with a hash, so such a builder reads what it needs from the payload.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_DRAIN_BUDGET', 20 );
define( 'AGUMAIL_DRAIN_TIMEOUT', 60 );
define( 'AGUMAIL_DRAIN_LOCK', 'agumail_drain_lock' );
define( 'AGUMAIL_DRAIN_LOCK_TTL', 300 );
define( 'AGUMAIL_MAX_ATTEMPTS', 20 );
define( 'AGUMAIL_RETRY_BASE', 30 );
define( 'AGUMAIL_RETRY_CAP', 6 * HOUR_IN_SECONDS );
define( 'AGUMAIL_AUTH_PAUSE', 15 * MINUTE_IN_SECONDS );
define( 'AGUMAIL_SMALLER_BATCH_FOR', DAY_IN_SECONDS );

// ─────────────────────────────────────────────────────────────────────────────
// Builders.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Registers how an outbox type is sent. $spec:
 *   endpoint    '/api/v1/orders'
 *   collection  'orders', the body key the records go under
 *   build       callable($key, $row): the record, or null to drop the row unsent
 *   contacts    callable($key, $row): contact records to send first. Required,
 *               except for records that are contacts or products themselves,
 *               and for signups: AguMail makes the contact from the signup, as
 *               a signup form does, and the contacts sync must never add tags
 *               to someone who is already subscribed.
 *   on_success  callable($key, $row, $response) once AguMail accepted it (optional)
 *   phase       contacts 10, signups 15, products 20, orders 30, carts 40
 *   backfill    true for store history: every request says backfill: true
 *   woocommerce true when building the record needs WooCommerce: without it
 *               the type's rows wait
 *   store       true for store data (orders, carts, products), which is sent
 *               only while store sync is on (agumail_store_sync_on()); the
 *               type's rows wait otherwise
 *   batch       records per request, at most 500. AguMail stores records one
 *               by one, so the defaults are small enough to answer within a
 *               minute: contacts and products 250, orders and carts 100.
 * $row is the outbox row: id, type, object_key, payload (decoded), version,
 * attempts, status, available_at, last_error, created_at, updated_at.
 *
 * @param string $type Outbox type.
 * @param array  $spec How the type is sent, as above.
 */
function agumail_register_builder( $type, array $spec ) {
	$spec = array_merge(
		array(
			'endpoint'    => '',
			'collection'  => '',
			'build'       => null,
			'contacts'    => null,
			'on_success'  => null,
			'phase'       => 50,
			'backfill'    => false,
			'batch'       => 0,
			'woocommerce' => false,
			'store'       => false,
		),
		$spec
	);
	if ( '' === $spec['endpoint'] || '' === $spec['collection'] || ! is_callable( $spec['build'] ) ) {
		agumail_log( 'error', sprintf( 'Builder for %s needs an endpoint, a collection and a build callable.', $type ) );
		return false;
	}
	if ( ! is_callable( $spec['contacts'] ) && ! in_array( $spec['endpoint'], array( '/api/v1/contacts', '/api/v1/products', '/api/v1/signups' ), true ) ) {
		agumail_log( 'error', sprintf( 'Builder for %s must name the contacts its records belong to.', $type ) );
		return false;
	}
	$defaults            = array(
		'contacts' => 250,
		'products' => 250,
		'orders'   => 100,
		'carts'    => 100,
	);
	$registry            = &agumail_builder_registry();
	$spec['type']        = (string) $type;
	$spec['phase']       = (int) $spec['phase'];
	$spec['backfill']    = (bool) $spec['backfill'];
	$spec['woocommerce'] = (bool) $spec['woocommerce'] || (bool) $spec['store'];
	$spec['store']       = (bool) $spec['store'];
	$spec['batch']       = (int) $spec['batch'] > 0 ? (int) $spec['batch'] : ( isset( $defaults[ $spec['collection'] ] ) ? $defaults[ $spec['collection'] ] : 100 );
	$spec['batch']       = max( 1, min( AGUMAIL_MAX_BATCH, $spec['batch'] ) );
	$spec['order']       = isset( $registry[ $type ] ) ? $registry[ $type ]['order'] : count( $registry );
	$registry[ $type ]   = $spec;
	return true;
}

/**
 * The registered builders by outbox type, by reference.
 */
function &agumail_builder_registry() {
	static $registry = array();
	return $registry;
}

/**
 * Whether a builder may send now: store data only while store sync is on, and nothing that needs WooCommerce while it is inactive.
 *
 * @param array $spec A registered builder.
 */
function agumail_builder_may_send( array $spec ) {
	if ( ! empty( $spec['store'] ) ) {
		return agumail_store_sync_on();
	}
	return empty( $spec['woocommerce'] ) || agumail_woocommerce_active();
}

/**
 * The registered builder for an outbox type, or null.
 *
 * @param string $type Outbox type.
 */
function agumail_builder( $type ) {
	$registry = &agumail_builder_registry();
	return isset( $registry[ $type ] ) ? $registry[ $type ] : null;
}

/**
 * Builders in the order they send: by phase, live before backfill, then as
 * registered. Only those that may send now: none that needs WooCommerce while
 * it is inactive, and no store data while store sync is off.
 */
function agumail_builders() {
	$builders = array_filter( agumail_builder_registry(), 'agumail_builder_may_send' );
	uasort(
		$builders,
		function ( $a, $b ) {
			if ( $a['phase'] !== $b['phase'] ) {
				return $a['phase'] < $b['phase'] ? -1 : 1;
			}
			if ( $a['backfill'] !== $b['backfill'] ) {
				return $a['backfill'] ? 1 : -1;
			}
			return $a['order'] < $b['order'] ? -1 : 1;
		}
	);
	return $builders;
}

/**
 * A row queued with its record already in it, such as a contact.
 *
 * @param string $key The row's object key.
 * @param array  $row The outbox row.
 */
function agumail_build_from_payload( $key, $row ) {
	return is_array( $row['payload'] ) && $row['payload'] ? $row['payload'] : null;
}

agumail_register_builder(
	'contact',
	array(
		'endpoint'   => '/api/v1/contacts',
		'collection' => 'contacts',
		'build'      => 'agumail_build_from_payload',
		'phase'      => 10,
	)
);

// ─────────────────────────────────────────────────────────────────────────────
// Batch sizes. A request that timed out was probably too big for AguMail to
// finish in time, and was resent whole; that type's batch is halved for a day.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Records per request for a type: its builder's size, or less after a timeout.
 *
 * @param string $type Outbox type.
 */
function agumail_drain_batch_size( $type ) {
	$spec    = agumail_builder( $type );
	$size    = $spec ? $spec['batch'] : 100;
	$smaller = get_option( 'agumail_batch_sizes', array() );
	if ( is_array( $smaller ) && isset( $smaller[ $type ]['size'], $smaller[ $type ]['until'] ) && (int) $smaller[ $type ]['until'] > agumail_time() ) {
		$size = min( $size, (int) $smaller[ $type ]['size'] );
	}
	return max( 1, $size );
}

/**
 * Halves a type's batch size for a day, after a request of $sent records timed out.
 *
 * @param string $type Outbox type.
 * @param int    $sent Records in the request that timed out.
 */
function agumail_drain_halve_batch( $type, $sent ) {
	$smaller = get_option( 'agumail_batch_sizes', array() );
	if ( ! is_array( $smaller ) ) {
		$smaller = array();
	}
	$size             = max( 1, (int) floor( min( $sent, agumail_drain_batch_size( $type ) ) / 2 ) );
	$smaller[ $type ] = array(
		'size'  => $size,
		'until' => agumail_time() + AGUMAIL_SMALLER_BATCH_FOR,
	);
	update_option( 'agumail_batch_sizes', $smaller, false );
	agumail_log( 'warning', sprintf( 'AguMail took too long to answer; %s will go %d at a time for a day.', $type, $size ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Jobs.
// ─────────────────────────────────────────────────────────────────────────────

agumail_add_background_action( 'agumail_drain', 'agumail_drain', 10, 0 );
agumail_add_background_action( 'agumail_drain_now', 'agumail_drain', 10, 0 );
agumail_add_background_action( 'agumail_housekeeping', 'agumail_schedule', 5, 0 );

/**
 * Asks for another run straight away. Only from background work; a shopper's
 * request never schedules. A follow-up that is itself running counts as
 * in-progress to Action Scheduler, so only pending ones are looked for.
 */
function agumail_schedule_drain_now() {
	if ( ! agumail_in_background() || ! function_exists( 'as_enqueue_async_action' ) ) {
		return false;
	}
	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'agumail_drain_now',
				'group'    => AGUMAIL_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		if ( $pending ) {
			return false;
		}
	}
	return (bool) as_enqueue_async_action( 'agumail_drain_now', array(), AGUMAIL_GROUP );
}

/**
 * True while sending should not even be tried: the store is not connected, the
 * app reported it disconnected, or another feature says so through the
 * agumail_drain_paused filter.
 */
function agumail_drain_paused() {
	$paused = ! agumail_has_api_key() || 'disconnected' === agumail_connection()['status'];
	return (bool) apply_filters( 'agumail_drain_paused', $paused );
}

/**
 * One run. Returns why it stopped: done, idle (nothing to do), budget (out of
 * time or memory with more to send; another run is scheduled), stopped
 * (AguMail asked us to wait), locked, paused, backoff, not_ready or refused
 * (fired from a request that may not send).
 */
function agumail_drain() {
	if ( ! agumail_http_allowed() ) {
		return 'refused';
	}
	if ( ! agumail_ready() ) {
		return 'not_ready';
	}
	if ( agumail_drain_paused() ) {
		return 'paused';
	}
	if ( agumail_backoff_until() > agumail_time() ) {
		return 'backoff';
	}
	agumail_store_sync_recheck();
	$store = agumail_store();
	if ( ! $store->has_work( agumail_mysql_time() ) ) {
		return 'idle';
	}
	$token = $store->acquire_lock( AGUMAIL_DRAIN_LOCK, AGUMAIL_DRAIN_LOCK_TTL, agumail_time() );
	if ( ! $token ) {
		return 'locked';
	}
	$deadline      = agumail_clock() + AGUMAIL_DRAIN_BUDGET;
	$sent_contacts = &agumail_drain_sent_contacts();
	$sent_contacts = array();
	try {
		try {
			if ( agumail_store_sync_on() ) {
				do_action( 'agumail_drain_move_carts' );
			}
		} catch ( Throwable $e ) {
			agumail_log( 'error', 'Moving carts into the outbox failed: ' . $e->getMessage() );
		}
		agumail_enqueue_suppressed( true );
		$outcome = agumail_drain_rounds( $deadline );
	} finally {
		agumail_enqueue_suppressed( false );
		$store->release_lock( AGUMAIL_DRAIN_LOCK, $token );
	}
	if ( 'budget' === $outcome ) {
		agumail_schedule_drain_now();
	}
	return $outcome;
}

/**
 * Out of time, or of memory: the run stops and another one carries on in a fresh request.
 *
 * @param float $deadline When the run must stop, in seconds since the epoch.
 */
function agumail_drain_should_stop( $deadline ) {
	if ( agumail_clock() >= $deadline ) {
		return true;
	}
	$limit = (int) apply_filters( 'agumail_drain_memory_limit', agumail_memory_limit() );
	return $limit > 0 && memory_get_usage( true ) > 0.8 * $limit;
}

/** PHP's memory limit in bytes, or 0 for none. */
function agumail_memory_limit() {
	$limit = trim( (string) ini_get( 'memory_limit' ) );
	if ( '' === $limit || '-1' === $limit ) {
		return 0;
	}
	if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
		return (int) wp_convert_hr_to_bytes( $limit );
	}
	$units = array(
		'k' => 1024,
		'm' => 1048576,
		'g' => 1073741824,
	);
	$unit  = strtolower( substr( $limit, -1 ) );
	return (int) ( (float) $limit * ( isset( $units[ $unit ] ) ? $units[ $unit ] : 1 ) );
}

/**
 * Round after round, each type in phase order sends one batch, until nothing
 * is due. Every type keeps a cursor, so a row changed during the run waits for
 * the next one rather than being sent twice now.
 *
 * @param float $deadline When the run must stop, in seconds since the epoch.
 */
function agumail_drain_rounds( $deadline ) {
	$builders = agumail_builders();
	$cursors  = array_fill_keys( array_keys( $builders ), 0 );
	$finished = array();
	$total    = count( $builders );
	$done     = 0;
	while ( $done < $total ) {
		foreach ( $builders as $type => $spec ) {
			if ( isset( $finished[ $type ] ) ) {
				continue;
			}
			if ( agumail_drain_should_stop( $deadline ) ) {
				return 'budget';
			}
			$result = agumail_drain_batch( $spec, $cursors[ $type ], $deadline );
			// Objects loaded to build the batch need not stay in memory for the next one.
			agumail_release_cache_memory();
			if ( 'stop' === $result ) {
				return 'stopped';
			}
			if ( 'budget' === $result ) {
				return 'budget';
			}
			if ( 'finished' === $result ) {
				$finished[ $type ] = true;
				++$done;
			}
		}
	}
	return 'done';
}

/**
 * Sends the next batch of one type. Returns more (a full batch went; there may
 * be more), finished (nothing more to send this run), stop or budget.
 *
 * @param array $spec     The type's builder.
 * @param int   $cursor   Id of the last row sent; moved on to the last row read.
 * @param float $deadline When the run must stop, in seconds since the epoch.
 */
function agumail_drain_batch( array $spec, &$cursor, $deadline ) {
	// Store sync was held earlier in this run.
	if ( ! agumail_builder_may_send( $spec ) ) {
		return 'finished';
	}
	$store = agumail_store();
	$size  = agumail_drain_batch_size( $spec['type'] );
	$rows  = $store->due( $spec['type'], $size, agumail_mysql_time(), $cursor );
	if ( ! $rows ) {
		return 'finished';
	}
	$cursor     = end( $rows )['id'];
	$full       = count( $rows ) >= $size;
	$is_contact = '/api/v1/contacts' === $spec['endpoint'];

	$items = array();
	foreach ( $rows as $row ) {
		if ( agumail_drain_should_stop( $deadline ) ) {
			return 'budget';
		}
		$key = $row['object_key'];
		try {
			$record   = call_user_func( $spec['build'], $key, $row );
			$contacts = $record && is_callable( $spec['contacts'] ) ? call_user_func( $spec['contacts'], $key, $row ) : array();
		} catch ( Throwable $e ) {
			$parks = $row['attempts'] + 1 >= AGUMAIL_MAX_ATTEMPTS;
			agumail_log(
				'warning',
				$parks
				? sprintf( 'Could not build %s %s, set aside after %d attempts: %s', $spec['type'], $key, AGUMAIL_MAX_ATTEMPTS, $e->getMessage() )
				: sprintf( 'Could not build %s %s, will try again: %s', $spec['type'], $key, $e->getMessage() )
			);
			agumail_drain_defer( array( 'row' => $row ), $e->getMessage() );
			continue;
		}
		if ( ! $record ) {
			$store->drop( $row['id'], $row['version'] );
			continue;
		}
		$time = agumail_drain_row_time( $row );
		if ( $is_contact && ! empty( $record['email'] ) ) {
			list($record, $time) = agumail_drain_reconcile_contact( $record, $time );
		}
		$json = wp_json_encode( $record );
		if ( false === $json ) {
			agumail_drain_park( $row, 'The record could not be encoded as JSON.' );
			continue;
		}
		if ( strlen( $json ) > AGUMAIL_MAX_BATCH_BYTES - 1024 ) {
			agumail_drain_park( $row, 'The record is larger than one request may carry.' );
			continue;
		}
		$items[] = array(
			'row'      => $row,
			'record'   => $record,
			'time'     => $time,
			'bytes'    => strlen( $json ),
			'contacts' => array_values(
				array_filter(
					is_array( $contacts ) ? $contacts : array(),
					function ( $contact ) {
						return is_array( $contact ) && ! empty( $contact['email'] );
					}
				)
			),
		);
	}

	if ( $items ) {
		$status = agumail_drain_contacts_first( $spec, $items, $deadline );
		if ( 'ok' === $status ) {
			$status = agumail_drain_send(
				$spec['endpoint'],
				$spec['collection'],
				$spec['backfill'],
				$items,
				$deadline,
				array(
					'size_of' => $spec['type'],
					'sent'    => function ( array $sent, $response ) use ( $spec, $is_contact ) {
						if ( $is_contact ) {
							foreach ( $sent as $item ) {
								agumail_drain_remember_contact( $item['record'], $item['time'] );
							}
						}
						agumail_drain_sent( $spec, $sent, $response );
					},
					'park'    => 'agumail_drain_park_item',
					'retry'   => 'agumail_drain_defer',
				)
			);
		}
		if ( 'deferred' === $status ) {
			return 'finished';
		}
		if ( 'ok' !== $status ) {
			return $status;
		}
	}
	return $full ? 'more' : 'finished';
}

/**
 * Sends the contacts a batch depends on, merged by email and reconciled with
 * what this run already sent, before the batch itself. Items whose contact
 * AguMail refused are parked and taken out of $items. Returns ok, deferred,
 * stop or budget; on anything but ok nothing else is sent.
 *
 * @param array $spec     The batch's builder.
 * @param array $items    The batch's items.
 * @param float $deadline When the run must stop, in seconds since the epoch.
 */
function agumail_drain_contacts_first( array $spec, array &$items, $deadline ) {
	$contacts = array();
	$times    = array();
	foreach ( $items as $item ) {
		foreach ( $item['contacts'] as $contact ) {
			$contacts[]      = $contact;
			$email           = strtolower( trim( (string) $contact['email'] ) );
			$given           = isset( $contact['consentedAt'] ) ? strtotime( (string) $contact['consentedAt'] ) : false;
			$time            = $given ? $given : $item['time'];
			$times[ $email ] = isset( $times[ $email ] ) ? max( $times[ $email ], $time ) : $time;
		}
	}
	if ( ! $contacts ) {
		return 'ok';
	}
	$entries = array();
	foreach ( agumail_merge_contacts( $contacts ) as $contact ) {
		list($contact, $time) = agumail_drain_reconcile_contact( $contact, $times[ $contact['email'] ] );
		$entries[]            = array(
			'record' => $contact,
			'time'   => $time,
			'bytes'  => strlen( (string) wp_json_encode( $contact ) ),
		);
	}
	$refused = array();
	$retry   = false;
	$status  = agumail_drain_send(
		'/api/v1/contacts',
		'contacts',
		$spec['backfill'],
		$entries,
		$deadline,
		array(
			'size_of' => 'contact',
			'sent'    => function ( array $sent ) {
				foreach ( $sent as $entry ) {
					agumail_drain_remember_contact( $entry['record'], $entry['time'] );
				}
			},
			'park'    => function ( array $entry, $message ) use ( &$refused ) {
				$refused[ strtolower( $entry['record']['email'] ) ] = $message;
			},
			'retry'   => function ( array $entry, $message ) use ( &$retry ) {
				$retry = $message;
			},
		)
	);
	if ( false !== $retry ) {
		foreach ( $items as $item ) {
			agumail_drain_defer( $item, 'Its contact could not be sent: ' . $retry );
		}
		return $status;
	}
	if ( 'ok' !== $status ) {
		return $status;
	}
	foreach ( $items as $index => $item ) {
		foreach ( $item['contacts'] as $contact ) {
			$email = strtolower( trim( (string) $contact['email'] ) );
			if ( isset( $refused[ $email ] ) ) {
				agumail_drain_park_item( $item, 'AguMail refused its contact: ' . $refused[ $email ] );
				unset( $items[ $index ] );
				break;
			}
		}
	}
	$items = array_values( $items );
	return 'ok';
}

// ─────────────────────────────────────────────────────────────────────────────
// Contacts sent during this run, by email, with the time of the answer each
// carries: when it was given if the record says (consentedAt), else when its
// row was queued. A contact about to go is merged with the one already sent,
// the newer answer winning as agumail_merge_queued_contact() decides.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Contacts sent during this run, by lowercased email, by reference.
 */
function &agumail_drain_sent_contacts() {
	static $sent = array();
	return $sent;
}

/**
 * Merges a contact about to go with the one this run already sent. Returns the record and its time.
 *
 * @param array $record Contact record.
 * @param int   $time   Time of its answer, as a timestamp.
 */
function agumail_drain_reconcile_contact( array $record, $time ) {
	$sent  = &agumail_drain_sent_contacts();
	$email = strtolower( trim( (string) $record['email'] ) );
	if ( ! isset( $sent[ $email ] ) ) {
		return array( $record, $time );
	}
	$known           = $sent[ $email ];
	$merged          = $time >= $known['time']
		? agumail_merge_queued_contact( $known['record'], $record )
		: agumail_merge_queued_contact( $record, $known['record'] );
	$merged['email'] = $record['email'];
	return array( $merged, max( $time, $known['time'] ) );
}

/**
 * Remembers a contact this run sent, with the time of its answer.
 *
 * @param array $record Contact record.
 * @param int   $time   Time of its answer, as a timestamp.
 */
function agumail_drain_remember_contact( array $record, $time ) {
	$sent = &agumail_drain_sent_contacts();
	$sent[ strtolower( trim( (string) $record['email'] ) ) ] = array(
		'record' => $record,
		'time'   => $time,
	);
}

/**
 * When a row was last queued, as a timestamp.
 *
 * @param array $row The outbox row.
 */
function agumail_drain_row_time( array $row ) {
	$time = isset( $row['updated_at'] ) ? strtotime( $row['updated_at'] . ' UTC' ) : false;
	return $time ? $time : agumail_time();
}

// ─────────────────────────────────────────────────────────────────────────────
// Sending.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Posts entries ({record, bytes, ...}) to an endpoint in batches of the size
 * $on['size_of'] names, and handles each answer. $on also has three callables:
 * sent($entries, $response) for accepted entries, park($entry, $message) for an
 * entry AguMail refused, and retry($entry, $message) for entries to try again
 * later. Returns ok, deferred (a batch waits; the rest of this type can wait
 * too), stop or budget.
 *
 * @param string $endpoint   API path.
 * @param string $collection Body key the records go under.
 * @param bool   $backfill   Whether every request says backfill: true.
 * @param array  $entries    Entries to send.
 * @param float  $deadline   When the run must stop, in seconds since the epoch.
 * @param array  $on         Batch size type and callables, as above.
 */
function agumail_drain_send( $endpoint, $collection, $backfill, array $entries, $deadline, array $on ) {
	$queue = agumail_drain_chunks( $entries, agumail_drain_batch_size( $on['size_of'] ) );
	while ( $queue ) {
		$chunk = array_shift( $queue );
		if ( ! $chunk ) {
			continue;
		}
		if ( agumail_drain_should_stop( $deadline ) ) {
			return 'budget';
		}
		$body = array(
			$collection => array_map(
				function ( $entry ) {
					return $entry['record'];
				},
				$chunk
			),
		);
		if ( $backfill ) {
			$body['backfill'] = true;
		}
		$response = agumail_api_request( 'POST', $endpoint, $body, array( 'timeout' => AGUMAIL_DRAIN_TIMEOUT ) );
		$answer   = agumail_drain_classify( $response );

		if ( 'ok' === $answer ) {
			agumail_clear_connection_error();
			if ( is_callable( $on['sent'] ) ) {
				call_user_func( $on['sent'], $chunk, $response );
			}
			do_action( 'agumail_drain_sent', $endpoint, count( $chunk ), $response );
			continue;
		}
		if ( 'too_large' === $answer ) {
			if ( count( $chunk ) > 1 ) {
				$half = (int) ceil( count( $chunk ) / 2 );
				array_unshift( $queue, array_slice( $chunk, 0, $half ), array_slice( $chunk, $half ) );
			} else {
				call_user_func( $on['park'], $chunk[0], 'The record is larger than AguMail accepts.' );
			}
			continue;
		}
		if ( 'invalid' === $answer ) {
			$message = agumail_drain_error_message( $response );
			$index   = agumail_drain_error_index( $response, $collection );
			if ( null !== $index && isset( $chunk[ $index ] ) ) {
				call_user_func( $on['park'], $chunk[ $index ], $message );
				unset( $chunk[ $index ] );
				array_unshift( $queue, array_values( $chunk ) );
				continue;
			}
			if ( count( $chunk ) > 1 && agumail_drain_error_names_collection( $response, $collection ) ) {
				$half = (int) ceil( count( $chunk ) / 2 );
				array_unshift( $queue, array_slice( $chunk, 0, $half ), array_slice( $chunk, $half ) );
				continue;
			}
			// About the request itself, not a record in it: nothing to set aside, so it waits.
			foreach ( $chunk as $entry ) {
				call_user_func( $on['retry'], $entry, $message );
			}
			return 'deferred';
		}
		if ( 'auth' === $answer && agumail_is_store_sync_refusal( $response ) ) {
			// Only store data needs the plan: it waits, untouched, and the rest goes on.
			agumail_store_sync_hold( agumail_drain_error_message( $response ) );
			return 'deferred';
		}
		if ( 'auth' === $answer ) {
			$message = agumail_drain_error_message( $response );
			agumail_record_connection_error( $response['code'], $message );
			agumail_set_backoff_until( agumail_time() + AGUMAIL_AUTH_PAUSE );
			agumail_log( 'error', sprintf( 'AguMail refused this store (%d): %s. Sending is paused.', $response['code'], $message ) );
			return 'stop';
		}
		if ( 'rate' === $answer ) {
			agumail_set_backoff_until( agumail_time() + agumail_drain_retry_after( $response ) );
			return 'stop';
		}
		if ( 'refused' === $answer ) {
			return 'stop';
		}
		if ( agumail_drain_timed_out( $response ) ) {
			agumail_drain_halve_batch( $on['size_of'], count( $chunk ) );
		}
		$message = is_wp_error( $response ) ? $response->get_error_message() : agumail_drain_error_message( $response );
		foreach ( $chunk as $entry ) {
			call_user_func( $on['retry'], $entry, $message );
		}
		return 'stop';
	}
	return 'ok';
}

/**
 * Splits entries into requests of at most $max records and 4 MB.
 *
 * @param array $entries Entries with their bytes.
 * @param int   $max     Most records per request.
 */
function agumail_drain_chunks( array $entries, $max ) {
	$chunks = array();
	$chunk  = array();
	$bytes  = 64;
	foreach ( $entries as $entry ) {
		$size = $entry['bytes'] + 1;
		if ( $chunk && ( count( $chunk ) >= $max || $bytes + $size > AGUMAIL_MAX_BATCH_BYTES ) ) {
			$chunks[] = $chunk;
			$chunk    = array();
			$bytes    = 64;
		}
		$chunk[] = $entry;
		$bytes  += $size;
	}
	if ( $chunk ) {
		$chunks[] = $chunk;
	}
	return $chunks;
}

/**
 * Returns ok, invalid, too_large, auth, rate, refused (the guard, or no key) or retry.
 *
 * @param array|WP_Error $response The API response.
 */
function agumail_drain_classify( $response ) {
	if ( is_wp_error( $response ) ) {
		return in_array( $response->get_error_code(), array( 'agumail_not_background', 'agumail_not_connected' ), true ) ? 'refused' : 'retry';
	}
	$code = (int) $response['code'];
	if ( $code >= 200 && $code < 300 ) {
		return 'ok';
	}
	if ( 400 === $code || 422 === $code ) {
		return 'invalid';
	}
	if ( 413 === $code ) {
		return 'too_large';
	}
	if ( in_array( $code, array( 401, 402, 403 ), true ) ) {
		return 'auth';
	}
	if ( 429 === $code ) {
		return 'rate';
	}
	return 'retry';
}

/**
 * Whether a request failed because it timed out.
 *
 * @param array|WP_Error $response The API response.
 */
function agumail_drain_timed_out( $response ) {
	if ( ! is_wp_error( $response ) ) {
		return false;
	}
	$message = $response->get_error_message();
	return false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
}

/**
 * The error AguMail gave, else the HTTP status, else an empty string.
 *
 * @param array|WP_Error $response The API response.
 */
function agumail_drain_error_message( $response ) {
	if ( is_array( $response ) && isset( $response['body']['error'] ) && is_string( $response['body']['error'] ) ) {
		return $response['body']['error'];
	}
	return is_array( $response ) ? 'HTTP ' . $response['code'] : '';
}

/**
 * The record a 400 names: AguMail reports the first problem as "orders.3.email: ...".
 *
 * @param array|WP_Error $response   The API response.
 * @param string         $collection Body key the records went under.
 */
function agumail_drain_error_index( $response, $collection ) {
	$error = agumail_drain_error_message( $response );
	if ( preg_match( '/^' . preg_quote( $collection, '/' ) . '\.(\d+)(?![\d])/', $error, $match ) ) {
		return (int) $match[1];
	}
	return null;
}

/**
 * A 400 about the list as a whole ("orders: Array must contain at most 500 element(s)").
 *
 * @param array|WP_Error $response   The API response.
 * @param string         $collection Body key the records went under.
 */
function agumail_drain_error_names_collection( $response, $collection ) {
	return (bool) preg_match( '/^' . preg_quote( $collection, '/' ) . '(?:[.:\s]|$)/', agumail_drain_error_message( $response ) );
}

/**
 * Seconds to wait after a 429: Retry-After (seconds or a date), else the body's retryAfter, else a minute.
 *
 * @param array $response The API response.
 */
function agumail_drain_retry_after( $response ) {
	$header = isset( $response['headers']['retry-after'] ) ? $response['headers']['retry-after'] : null;
	if ( is_array( $header ) ) {
		$header = reset( $header );
	}
	$seconds = null;
	if ( is_numeric( $header ) ) {
		$seconds = (int) $header;
	} elseif ( is_string( $header ) && '' !== $header && strtotime( $header ) ) {
		$seconds = strtotime( $header ) - agumail_time();
	} elseif ( isset( $response['body']['retryAfter'] ) && is_numeric( $response['body']['retryAfter'] ) ) {
		$seconds = (int) $response['body']['retryAfter'];
	}
	return max( 1, min( AGUMAIL_RETRY_CAP, null === $seconds ? 60 : $seconds ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// What happens to rows.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Accepted: rows that did not change meanwhile go, and each builder hears of it.
 *
 * @param array $spec     The type's builder.
 * @param array $items    Items AguMail accepted.
 * @param array $response The API response.
 */
function agumail_drain_sent( array $spec, array $items, $response ) {
	$store = agumail_store();
	foreach ( $items as $item ) {
		$row = $item['row'];
		$store->complete( $row['id'], $row['version'] );
		if ( is_callable( $spec['on_success'] ) ) {
			try {
				call_user_func( $spec['on_success'], $row['object_key'], $row, $response );
			} catch ( Throwable $e ) {
				agumail_log( 'error', sprintf( 'After sending %s %s: %s', $spec['type'], $row['object_key'], $e->getMessage() ) );
			}
		}
	}
}

/**
 * Parks a batch item's row as failed.
 *
 * @param array  $item    A batch item, with its row.
 * @param string $message Why AguMail refused it.
 */
function agumail_drain_park_item( array $item, $message ) {
	agumail_drain_park( $item['row'], $message );
}

/**
 * Parks a row as failed, unless it changed meanwhile, and logs why.
 *
 * @param array  $row     The outbox row.
 * @param string $message Why AguMail refused it.
 */
function agumail_drain_park( array $row, $message ) {
	$message = agumail_drain_trim_error( $message );
	agumail_store()->park( $row['id'], $row['version'], $message, agumail_mysql_time() );
	agumail_log( 'warning', sprintf( '%s %s was refused and set aside: %s', $row['type'], $row['object_key'], $message ) );
}

/**
 * Not delivered: waits 30 s, doubling each time to at most 6 h, and parks after 20 attempts.
 *
 * @param array  $item    A batch item, with its row.
 * @param string $message Why it was not delivered.
 */
function agumail_drain_defer( array $item, $message ) {
	$row      = $item['row'];
	$attempts = $row['attempts'] + 1;
	$failed   = $attempts >= AGUMAIL_MAX_ATTEMPTS;
	agumail_store()->defer(
		$row['id'],
		$attempts,
		agumail_mysql_time( agumail_time() + agumail_retry_delay( $attempts ) ),
		agumail_drain_trim_error( $message ),
		$failed,
		agumail_mysql_time()
	);
}

/**
 * 30 s after the first failure, doubling, at most 6 h, less up to a fifth so retries spread out.
 *
 * @param int $attempts Attempts so far.
 */
function agumail_retry_delay( $attempts ) {
	$delay  = min( AGUMAIL_RETRY_CAP, AGUMAIL_RETRY_BASE * pow( 2, max( 0, (int) $attempts - 1 ) ) );
	$jitter = (int) floor( $delay * 0.2 * wp_rand( 0, 1000 ) / 1000 );
	return max( 1, (int) $delay - $jitter );
}

/**
 * An error message trimmed to what the last_error column holds.
 *
 * @param string $message Error message.
 */
function agumail_drain_trim_error( $message ) {
	$message = trim( (string) $message );
	return strlen( $message ) > AGUMAIL_ERROR_MAX ? substr( $message, 0, AGUMAIL_ERROR_MAX ) : $message;
}

// ─────────────────────────────────────────────────────────────────────────────
// Store sync held by the plan (settings.php). Holding it keeps orders and
// products waiting in the queue and stops keeping carts: the carts queued so far
// are dropped, since a reminder that arrives weeks late is wrong, and cart
// recovery is part of store sync anyway. The drain checks the plan again once
// an hour, so a store that moves to a plan with store sync carries on within the
// hour without being reconnected.
// ─────────────────────────────────────────────────────────────────────────────

define( 'AGUMAIL_STORE_HOLD_KEPT', 90 * DAY_IN_SECONDS );

agumail_add_background_action( 'agumail_housekeeping', 'agumail_store_sync_housekeeping', 30, 0 );

/**
 * AguMail refused store data for the plan: hold it. Only the first refusal changes anything.
 *
 * @param string $message AguMail's error message, for the log.
 */
function agumail_store_sync_hold( $message = '' ) {
	if ( agumail_store_sync_held() ) {
		return;
	}
	update_option( AGUMAIL_STORE_HOLD_OPTION, agumail_time(), false );
	update_option(
		AGUMAIL_STORE_CHECK_OPTION,
		array(
			'available' => false,
			'at'        => agumail_time(),
		),
		false
	);
	update_option( 'agumail_store_notice', 'held', false );
	$dropped = agumail_store()->discard_pending( array( 'cart' ) );
	agumail_store()->carts_clear_dirty();
	agumail_store_sync_queue_waiting_subscribers();
	agumail_log(
		'warning',
		sprintf(
			'AguMail does not take store data on this plan%s. Orders and products wait, %d queued carts were dropped, and signups carry on.',
			'' !== $message ? ' (' . $message . ')' : '',
			$dropped
		)
	);
}

/**
 * Orders queued before the hold wait with it, and so would the shoppers who
 * ticked the box on them: each gets its checkout subscriber row, as an order
 * queued during the hold does (agumail_order_queue_subscriber()). Written to
 * the store directly, since the hold usually starts inside the drain, where
 * agumail_enqueue() queues nothing.
 */
function agumail_store_sync_queue_waiting_subscribers() {
	$store      = agumail_store();
	$everything = agumail_mysql_time( agumail_time() + 3650 * DAY_IN_SECONDS );
	$now        = agumail_mysql_time();
	$cursor     = 0;
	do {
		$rows = $store->due( 'order', AGUMAIL_MAX_BATCH, $everything, $cursor );
		foreach ( $rows as $row ) {
			$cursor = $row['id'];
			if ( ! is_array( $row['payload'] ) && ! $store->find( 'order_contact', $row['object_key'] ) ) {
				$store->enqueue( 'order_contact', $row['object_key'], null, $now );
			}
		}
		$full = count( $rows ) >= AGUMAIL_MAX_BATCH;
	} while ( $full );
}

/** Store sync is back: the waiting store data goes with the next run. */
function agumail_store_sync_resume() {
	$was_held = agumail_store_sync_held();
	delete_option( AGUMAIL_STORE_HOLD_OPTION );
	update_option(
		AGUMAIL_STORE_CHECK_OPTION,
		array(
			'available' => true,
			'at'        => agumail_time(),
		),
		false
	);
	if ( $was_held ) {
		update_option( 'agumail_store_notice', 'resumed', false );
		agumail_log( 'info', 'AguMail takes store data again. Orders and products that waited go now.' );
	}
}

/**
 * Asks AguMail whether the plan has store sync, with a products request that
 * holds no products: AguMail checks the plan before it reads the request, so
 * the answer is 402 (no store sync) or 400 (an empty list, so store sync is
 * there), and nothing is stored either way. Returns true, false, or null when
 * the answer says neither. Background work only, like every call.
 *
 * @param int $timeout Request timeout in seconds, or 0 for the default.
 */
function agumail_store_sync_check( $timeout = 0 ) {
	if ( ! agumail_has_api_key() ) {
		return null;
	}
	$before   = agumail_store_sync_availability();
	$response = agumail_api_request( 'POST', '/api/v1/products', array( 'products' => array() ), $timeout > 0 ? array( 'timeout' => (int) $timeout ) : array() );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	if ( agumail_is_store_sync_refusal( $response ) ) {
		$available = false;
	} elseif ( in_array( (int) $response['code'], array( 200, 400, 422 ), true ) ) {
		$available = true;
	} else {
		return null;
	}
	update_option(
		AGUMAIL_STORE_CHECK_OPTION,
		array(
			'available' => $available,
			'at'        => agumail_time(),
		),
		false
	);
	if ( $available && agumail_store_sync_held() ) {
		agumail_store_sync_resume();
	} elseif ( AGUMAIL_PLATFORM_WORDPRESS === agumail_connection_platform() && agumail_woocommerce_active() ) {
		if ( $available && ( ! $before || ! $before['available'] ) ) {
			// A store connected for forms and signups whose plan now has store sync: offer it once.
			update_option( 'agumail_store_notice', 'available', false );
		} elseif ( ! $available && ! $before ) {
			// A site connected by key that has just gained WooCommerce: say once what store sync adds.
			update_option( 'agumail_store_notice', 'offer', false );
		}
	}
	return $available;
}

/** While store sync is held, the drain asks again at most once an hour. */
function agumail_store_sync_recheck() {
	if ( ! agumail_store_sync_held() || ! agumail_store_connected() ) {
		return;
	}
	$last = agumail_store_sync_availability();
	if ( $last && agumail_time() - $last['at'] < AGUMAIL_STORE_CHECK_EVERY ) {
		return;
	}
	agumail_store_sync_check();
}

/**
 * Daily: a held store asks again, and orders and products that have waited
 * longer than 90 days go; the store data sync brings the history back once
 * store sync is.
 */
function agumail_store_sync_housekeeping() {
	if ( ! agumail_store_sync_held() || ! agumail_ready() ) {
		return;
	}
	if ( agumail_store_connected() ) {
		agumail_store_sync_check();
	}
	// Read again: the check above lifts the hold when the plan now has store sync.
	$held_since = agumail_store_sync_held_since();
	if ( $held_since > 0 && agumail_time() - $held_since > AGUMAIL_STORE_HOLD_KEPT ) {
		agumail_store()->discard_pending( array( 'order', 'product' ), agumail_mysql_time( agumail_time() - AGUMAIL_STORE_HOLD_KEPT ) );
	}
}
