<?php
/**
 * Orders: what AguMail hears about the store's orders, and when.
 *
 * An order reaches AguMail once it is paid, and again whenever what it is worth
 * changes: a refund, a cancellation, the trash, a restore, a deletion. An order
 * nobody paid for (a declined card, a bank transfer that never came) never gets
 * there: an order AguMail holds counts as revenue and starts post-purchase
 * flows. "Paid" (agumail_order_counts_as_paid()) is WooCommerce's paid date,
 * kept through a later refund or cancellation, or a paid status: cash on
 * delivery is processing with no paid date until the parcel is delivered.
 *
 * Nothing here talks to AguMail. A hook only records that an order changed, in
 * one outbox write, and the drain builds the record from the order as it is
 * when it sends, so the record always carries the latest refunds. Within one
 * request an order is queued again only when something AguMail sees changed,
 * so a checkout that takes a payment (several saves and status hooks) costs
 * the shopper a single write.
 *
 * Only the shopper's own request knows the campaign that brought them (the
 * agm_cid cookie) and the cart they checked out (their session), so both are
 * written onto the order while WooCommerce creates it, riding on WooCommerce's
 * own save of the order. That meta, and the checkbox's answer, are trusted only
 * on an order a checkout created: WooCommerce Subscriptions copies an order's
 * meta onto its subscription and every renewal, which no shopper submitted.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The parameter AguMail's links carry (app/src/tracking/attribution.ts), and
// the cookie that keeps it.
if ( ! defined( 'AGUMAIL_CID_COOKIE' ) ) {
	define( 'AGUMAIL_CID_COOKIE', 'agm_cid' );
}
define( 'AGUMAIL_CID_DAYS', 30 );

// ─────────────────────────────────────────────────────────────────────────────
// The campaign that brought the shopper: ?agm_cid=<campaign or flow id>, kept
// for 30 days. The latest link wins.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'agumail_order_capture_campaign', 10, 0 );
add_action( 'wp_enqueue_scripts', 'agumail_order_campaign_consent_script', 10, 0 );

/**
 * Keeps the campaign from an ?agm_cid= link in the shopper's cookie.
 */
function agumail_order_capture_campaign() {
	// No nonce: a landing parameter, kept only in the shopper's own cookie.
	$raw = agumail_queried( AGUMAIL_CID_COOKIE );
	if ( '' === $raw ) {
		return;
	}
	$cid = agumail_order_clean_campaign( $raw );
	if ( null === $cid || agumail_order_campaign() === $cid ) {
		return;
	}
	// The campaign cookie is a marketing cookie. With a consent plugin and no
	// consent yet, the page keeps the campaign and sets the cookie in the
	// browser once the visitor consents (agumail_order_campaign_consent_script()).
	if ( ! agumail_has_consent( 'marketing' ) ) {
		$GLOBALS['agumail_pending_campaign'] = $cid;
		return;
	}
	$expires = time() + AGUMAIL_CID_DAYS * DAY_IN_SECONDS;
	if ( function_exists( 'wc_setcookie' ) ) {
		wc_setcookie( AGUMAIL_CID_COOKIE, $cid, $expires, is_ssl(), true );
	} else {
		agumail_setcookie(
			AGUMAIL_CID_COOKIE,
			$cid,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
			),
			'marketing'
		);
	}
	$_COOKIE[ AGUMAIL_CID_COOKIE ] = $cid;
}

/**
 * The landing page's campaign, kept until the visitor consents to marketing
 * cookies, then set as the same cookie in the browser.
 */
function agumail_order_campaign_consent_script() {
	$cid = isset( $GLOBALS['agumail_pending_campaign'] ) ? (string) $GLOBALS['agumail_pending_campaign'] : '';
	if ( '' === $cid ) {
		return;
	}
	$cookie = wp_json_encode( AGUMAIL_CID_COOKIE . '=' . rawurlencode( $cid ) . '; max-age=' . ( AGUMAIL_CID_DAYS * DAY_IN_SECONDS ) . '; path=/; SameSite=Lax' );
	$handle = 'agumail-campaign-consent';
	wp_register_script( $handle, false, array(), AGUMAIL_VERSION, true );
	wp_enqueue_script( $handle );
	wp_add_inline_script( $handle, agumail_consent_js( 'marketing', 'document.cookie=' . $cookie . '+(location.protocol==="https:"?"; secure":"");' ) );
}

/**
 * A campaign reference as AguMail takes it (at most 100 characters), or null.
 *
 * @param mixed $value Campaign reference to check, expected a string. Anything but a scalar is none.
 */
function agumail_order_clean_campaign( $value ) {
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	$value = sanitize_text_field( (string) $value );
	return '' !== $value && strlen( $value ) <= AGUMAIL_MAX_CAMPAIGN_REF ? $value : null;
}

/** The campaign in the shopper's cookie, or null. */
function agumail_order_campaign() {
	return isset( $_COOKIE[ AGUMAIL_CID_COOKIE ] ) && is_string( $_COOKIE[ AGUMAIL_CID_COOKIE ] )
		? agumail_order_clean_campaign( sanitize_text_field( wp_unslash( $_COOKIE[ AGUMAIL_CID_COOKIE ] ) ) )
		: null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Checkout. Both checkouts save the order right after these hooks, so the meta
// costs no write of its own. The Store API hook also runs for the block
// checkout's draft saves, which is harmless: the values are the same.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_create_order', 'agumail_order_remember_checkout', 10, 1 );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'agumail_order_remember_checkout', 10, 1 );

/**
 * Writes the shopper's campaign and cart onto the order while the checkout creates it.
 *
 * @param mixed $order The order being created, a WC_Order. Anything else is ignored.
 */
function agumail_order_remember_checkout( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}
	$cid = agumail_order_campaign();
	if ( null !== $cid ) {
		$order->update_meta_data( '_agumail_cid', $cid );
	}
	$cart_id = agumail_order_session_cart_id();
	if ( '' !== $cart_id ) {
		$order->update_meta_data( '_agumail_cart_id', $cart_id );
	}
}

/**
 * The id of the shopper's cart row, or ''. The carts feature answers when it
 * can (it also finds the open row for this session when the email capture has
 * not yet put the id in the session); otherwise the id it keeps in the session.
 */
function agumail_order_session_cart_id() {
	if ( function_exists( 'agumail_cart_id_for_current_session' ) ) {
		$cart_id = agumail_cart_id_for_current_session();
		if ( agumail_order_valid_cart_id( $cart_id ) ) {
			return $cart_id;
		}
	}
	$session = agumail_wc_session();
	if ( ! $session ) {
		return '';
	}
	$cart_id = $session->get( 'agumail_cart_id' );
	return agumail_order_valid_cart_id( $cart_id ) ? $cart_id : '';
}

/**
 * Whether a value is a cart row id: 1 to 64 letters, digits, underscores or hyphens.
 *
 * @param mixed $cart_id Value to check.
 */
function agumail_order_valid_cart_id( $cart_id ) {
	return is_string( $cart_id ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $cart_id );
}

// ─────────────────────────────────────────────────────────────────────────────
// Subscriptions. WooCommerce Subscriptions copies the first order's meta onto
// the subscription, and the subscription's onto each renewal; the plugin's own
// keys are left behind. The copy is filtered in both shapes Subscriptions has
// used (meta_key => value, or database rows); without it the filters never run.
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'wc_subscriptions_subscription_data', 'agumail_order_skip_copied_meta' );
add_filter( 'wc_subscriptions_renewal_order_data', 'agumail_order_skip_copied_meta' );
add_filter( 'wc_subscriptions_resubscribe_order_data', 'agumail_order_skip_copied_meta' );

/**
 * Leaves the plugin's own meta keys out of what Subscriptions copies.
 *
 * @param mixed $data The meta Subscriptions is about to copy, an array. Anything else is returned as it came.
 */
function agumail_order_skip_copied_meta( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( $data as $key => $value ) {
		$meta_key = $key;
		if ( is_int( $key ) && is_array( $value ) && isset( $value['meta_key'] ) ) {
			$meta_key = $value['meta_key'];
		} elseif ( is_int( $key ) && is_object( $value ) && isset( $value->meta_key ) ) {
			$meta_key = $value->meta_key;
		}
		if ( is_string( $meta_key ) && 0 === strpos( $meta_key, '_agumail_' ) ) {
			unset( $data[ $key ] );
		}
	}
	return $data;
}

/**
 * Whether a shopper's submitted checkout created the order (not a renewal, the admin, or the REST API).
 *
 * @param WC_Order $order The order.
 */
function agumail_order_from_checkout( $order ) {
	return in_array( (string) $order->get_created_via(), array( 'checkout', 'store-api' ), true );
}

/**
 * The checkbox's answer ('yes', 'no' or null), from a submitted checkout only.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_answer( $order ) {
	return agumail_order_from_checkout( $order ) ? agumail_order_optin_answer( $order ) : null;
}

/**
 * Order meta the checkout wrote (_agumail_cid, _agumail_cart_id), or '' on any other order.
 *
 * @param WC_Order $order The order.
 * @param string   $key   Meta key.
 */
function agumail_order_checkout_meta( $order, $key ) {
	return agumail_order_from_checkout( $order ) ? (string) $order->get_meta( $key ) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Queueing. Every moment an order can change what AguMail holds: payment and
// other status changes, a refund (partial ones included, which change no
// status), a save from the order screen or a plugin, and the trash, a restore
// or a deletion, both as WooCommerce's data store fires them and as WordPress
// does for orders still stored as posts (the legacy orders screen).
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_new_order', 'agumail_order_changed', 10, 2 );
add_action( 'woocommerce_update_order', 'agumail_order_changed', 10, 2 );
add_action( 'woocommerce_order_status_changed', 'agumail_order_status_changed', 10, 4 );
add_action( 'woocommerce_order_refunded', 'agumail_order_changed', 10, 1 );
add_action( 'woocommerce_trash_order', 'agumail_order_changed', 10, 1 );
add_action( 'woocommerce_untrash_order', 'agumail_order_changed', 10, 1 );
add_action( 'woocommerce_before_delete_order', 'agumail_order_deleted', 10, 2 );
add_action( 'trashed_post', 'agumail_order_post_changed', 10, 1 );
add_action( 'untrashed_post', 'agumail_order_post_changed', 10, 1 );
add_action( 'before_delete_post', 'agumail_order_post_deleted', 10, 2 );

/**
 * Queues an order that was created, saved, refunded, trashed or restored.
 *
 * @param int           $order_id Order ID.
 * @param WC_Order|null $order    The order, when the hook passes it.
 */
function agumail_order_changed( $order_id, $order = null ) {
	agumail_order_queue( is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order_id ) );
}

/**
 * Queues an order whose status changed.
 *
 * @param int           $order_id Order ID.
 * @param string        $from     The previous status.
 * @param string        $to       The new status.
 * @param WC_Order|null $order    The order.
 */
function agumail_order_status_changed( $order_id, $from = '', $to = '', $order = null ) {
	agumail_order_changed( $order_id, $order );
}

/**
 * WordPress fires these for every post, WooCommerce loaded or not.
 *
 * @param int $post_id Post ID.
 */
function agumail_order_post_changed( $post_id ) {
	if ( function_exists( 'wc_get_order' ) && 'shop_order' === get_post_type( $post_id ) ) {
		agumail_order_changed( $post_id );
	}
}

/**
 * An order stored as a post is about to be deleted.
 *
 * @param int         $post_id Post ID.
 * @param object|null $post    The post, a WP_Post when WordPress passes one.
 */
function agumail_order_post_deleted( $post_id, $post = null ) {
	$type = is_object( $post ) && isset( $post->post_type ) ? $post->post_type : get_post_type( $post_id );
	if ( function_exists( 'wc_get_order' ) && 'shop_order' === $type ) {
		agumail_order_deleted( $post_id );
	}
}

/**
 * A store order (not a refund, nor a subscription or other order type).
 *
 * @param WC_Abstract_Order|false $order The order, or false.
 */
function agumail_order_is_shop_order( $order ) {
	return is_a( $order, 'WC_Order' ) && 'shop_order' === $order->get_type();
}

/**
 * Whether the connected AguMail account holds the order. The flag names the
 * account (its userId), so an order sent to an account the store has since
 * left is new to this one; a flag of 'yes' predates that and still counts, as
 * does any flag while this account's id is not yet known.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_synced( $order ) {
	$flag = (string) $order->get_meta( '_agumail_synced' );
	if ( '' === $flag ) {
		return false;
	}
	$account = agumail_order_account_id();
	return 'yes' === $flag || '' === $account || $flag === $account;
}

/** The connected AguMail account's userId, or '' before it is known. */
function agumail_order_account_id() {
	$connection = agumail_connection();
	return isset( $connection['userId'] ) ? (string) $connection['userId'] : '';
}

/**
 * Whether an order counts as a purchase: it has a paid date, or its status is
 * one WooCommerce counts as paid (processing, completed, and any a store adds
 * through woocommerce_order_is_paid_statuses). Cash on delivery counts from
 * processing, though WooCommerce gives it a paid date only on delivery; a bank
 * transfer or cheque on hold does not count until it is processing.
 *
 * @param WC_Order|false $order The order, or false.
 */
function agumail_order_counts_as_paid( $order ) {
	return is_object( $order ) && ( (bool) $order->get_date_paid() || $order->has_status( wc_get_is_paid_statuses() ) );
}

/**
 * Counts as paid, or already sent: an order AguMail holds hears what happens to it next.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_sendable( $order ) {
	return agumail_order_counts_as_paid( $order ) || agumail_order_synced( $order );
}

/** The orders queued in this request, with what AguMail would have seen of each. */
function &agumail_order_queued() {
	static $queued = array();
	return $queued;
}

/**
 * What the record carries of an order: two moments with the same signature
 * need one send. The status is not in it (the record has none), so an order
 * paid and then completed in one request is one write.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_signature( $order ) {
	$paid = $order->get_date_paid();
	return implode(
		'|',
		array(
			agumail_order_value( $order ),
			$paid ? $paid->getTimestamp() : 0,
			strtolower( (string) $order->get_billing_email() ),
			(string) $order->get_currency(),
			count( $order->get_items( 'line_item' ) ),
		)
	);
}

/**
 * Queues an order AguMail should hear about. Returns whether it was queued.
 *
 * @param WC_Abstract_Order|false $order The order, or false.
 */
function agumail_order_queue( $order ) {
	if ( ! $order instanceof WC_Order || ! agumail_order_is_shop_order( $order ) || ! agumail_order_sendable( $order ) ) {
		return false;
	}
	$id = (int) $order->get_id();
	if ( $id <= 0 ) {
		return false;
	}
	agumail_order_queue_subscriber( $order );
	$queued    = &agumail_order_queued();
	$signature = agumail_order_signature( $order );
	if ( isset( $queued[ $id ] ) && $queued[ $id ] === $signature ) {
		return false;
	}
	if ( ! agumail_enqueue( 'order', $id ) ) {
		return false;
	}
	$queued[ $id ] = $signature;
	return true;
}

/**
 * A store whose orders do not go to AguMail now (connected for forms and
 * signups, or store sync held by its plan) still sends the shopper who ticked
 * the box at checkout, as a subscriber, once the order counts as paid. The
 * answer is the shopper's own, and contacts work on every plan. Only a tick is
 * sent, so a free plan's contact limit is spent on subscribers alone; the
 * contact carries the moment of the order, so a late send never re-subscribes
 * someone who has unsubscribed since. One write per order and request.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_queue_subscriber( $order ) {
	static $queued = array();
	if ( agumail_store_sync_on() || ! agumail_order_counts_as_paid( $order ) || 'yes' !== agumail_order_answer( $order ) || agumail_order_synced( $order ) ) {
		return false;
	}
	$id = (int) $order->get_id();
	if ( $id <= 0 || isset( $queued[ $id ] ) ) {
		return false;
	}
	$queued[ $id ] = true;
	return agumail_enqueue( 'order_contact', $id );
}

/**
 * An order about to be deleted. Once it is gone there is nothing to build its
 * record from, so the record goes into the queue now, worth nothing. An order
 * AguMail never had needs nothing: a send still waiting finds it gone.
 *
 * @param int           $order_id Order ID.
 * @param WC_Order|null $order    The order, when the hook passes it.
 */
function agumail_order_deleted( $order_id, $order = null ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! agumail_order_is_shop_order( $order ) || ! agumail_order_synced( $order ) ) {
		return;
	}
	$id     = (int) $order->get_id();
	$queued = &agumail_order_queued();
	if ( isset( $queued[ $id ] ) && 'deleted' === $queued[ $id ] ) {
		return;
	}
	$record = agumail_order_record( $order, true );
	if ( $record && agumail_enqueue( 'order', $id, array( 'record' => $record ) ) ) {
		$queued[ $id ] = 'deleted';
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// The record, built when the drain sends it.
// ─────────────────────────────────────────────────────────────────────────────

agumail_register_builder(
	'order',
	array(
		'endpoint'   => '/api/v1/orders',
		'collection' => 'orders',
		'phase'      => 30,
		'build'      => 'agumail_build_order',
		'contacts'   => 'agumail_order_contacts',
		'on_success' => 'agumail_order_sent',
		'store'      => true,
	)
);

// The checkout subscriber of an order that is not sent itself (agumail_order_queue_subscriber()).
agumail_register_builder(
	'order_contact',
	array(
		'endpoint'    => '/api/v1/contacts',
		'collection'  => 'contacts',
		'phase'       => 10,
		'build'       => 'agumail_build_order_subscriber',
		'on_success'  => 'agumail_order_subscriber_sent',
		'woocommerce' => true,
	)
);

/**
 * The contact of a paid checkout order whose box was ticked, or null (the row goes unsent).
 *
 * @param string $key Outbox row key: the order ID.
 * @param array  $row Outbox row.
 */
function agumail_build_order_subscriber( $key, $row ) {
	$order = wc_get_order( (int) $key );
	if ( ! agumail_order_is_shop_order( $order ) || ! agumail_order_counts_as_paid( $order ) || 'yes' !== agumail_order_answer( $order ) ) {
		return null;
	}
	$contacts = agumail_order_contacts( $key, $row );
	return $contacts ? $contacts[0] : null;
}

/**
 * AguMail has the subscriber: a customer who ticked for their own address has said yes on their account too.
 *
 * @param string $key      Outbox row key: the order ID.
 */
function agumail_order_subscriber_sent( $key ) {
	$order = wc_get_order( (int) $key );
	if ( agumail_order_is_shop_order( $order ) ) {
		agumail_order_remember_on_account( $order );
	}
}

/**
 * The record stored when the order was deleted, if it was.
 *
 * @param array $row Outbox row.
 */
function agumail_order_stored_record( $row ) {
	return is_array( $row['payload'] ) && isset( $row['payload']['record'] ) && is_array( $row['payload']['record'] )
		? $row['payload']['record']
		: null;
}

/**
 * The order's record: the one stored when it was deleted, else built from the order, or null when it cannot go.
 *
 * @param string $key Outbox row key: the order ID.
 * @param array  $row Outbox row.
 */
function agumail_build_order( $key, $row ) {
	$stored = agumail_order_stored_record( $row );
	if ( $stored ) {
		return $stored;
	}
	$order = wc_get_order( (int) $key );
	if ( ! agumail_order_is_shop_order( $order ) || ! agumail_order_sendable( $order ) ) {
		return null;
	}
	$record = agumail_order_record( $order, false, $row );
	if ( ! $record ) {
		agumail_log( 'notice', sprintf( 'Order %d has no valid billing email, so it cannot go to AguMail.', $order->get_id() ) );
	}
	return $record;
}

/**
 * The order as AguMail takes it, or null without a valid email. $deleted makes
 * it worth nothing and leaves the lines out, so AguMail keeps the ones it
 * holds. $row is the outbox row being sent, when there is one.
 *
 * @param WC_Order   $order   The order.
 * @param bool       $deleted Whether the order is being deleted.
 * @param array|null $row     Outbox row, or null.
 */
function agumail_order_record( $order, $deleted = false, $row = null ) {
	$email = agumail_normalize_email( $order->get_billing_email() );
	if ( '' === $email ) {
		return null;
	}
	$record   = array(
		'externalId' => (string) $order->get_id(),
		'email'      => $email,
		'total'      => $deleted ? 0.0 : agumail_order_value( $order ),
	);
	$currency = agumail_currency( $order->get_currency() );
	if ( $currency ) {
		$record['currency'] = $currency;
	}
	if ( ! $deleted ) {
		$record['items'] = agumail_order_items( $order );
	}
	// Sent every time, not just the first: AguMail re-totals the campaign an
	// order names, and an update without it would leave that campaign quoting
	// money the store has since given back.
	$campaign = agumail_order_clean_campaign( agumail_order_checkout_meta( $order, '_agumail_cid' ) );
	if ( null !== $campaign ) {
		$record['campaignRef'] = $campaign;
	}
	$record['occurredAt'] = agumail_order_occurred_at( $order, $row );
	$record['source']     = 'woocommerce';
	return $record;
}

/**
 * A name as text: entities WordPress stored (&amp;, &#8217;) decoded, then clamped.
 *
 * @param string $value The name.
 * @param int    $max   Most characters to keep.
 */
function agumail_catalogue_text( $value, $max ) {
	return agumail_clamp( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $max );
}

/**
 * When the order happened, as AguMail dates it: fixed at its first delivery
 * (_agumail_occurred_at) and sent unchanged ever after, because AguMail credits
 * an order to the email that led to it by that moment. Before then: when it was
 * paid (a bank transfer paid five days after it was placed is a purchase made
 * today); with no paid date yet, as for cash on delivery in processing, when it
 * was first queued, the moment it began to count. An order already sent before
 * the moment was kept dates from when it was placed.
 *
 * @param WC_Order   $order The order.
 * @param array|null $row   Outbox row, or null.
 */
function agumail_order_occurred_at( $order, $row = null ) {
	$kept = agumail_iso_date( (string) $order->get_meta( '_agumail_occurred_at' ) );
	if ( $kept ) {
		return $kept;
	}
	$paid = $order->get_date_paid();
	if ( $paid ) {
		return agumail_iso_date( $paid );
	}
	if ( ! agumail_order_synced( $order ) && is_array( $row ) && ! empty( $row['created_at'] ) ) {
		$queued = agumail_iso_date( $row['created_at'] );
		if ( $queued ) {
			return $queued;
		}
	}
	$created = $order->get_date_created();
	return agumail_iso_date( $created ? $created : agumail_time() );
}

/**
 * What the order is still worth: its total less refunds. Cancelled, refunded, failed or in the trash, nothing.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_value( $order ) {
	if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
		return 0.0;
	}
	return agumail_money_total( (float) $order->get_total() - (float) $order->get_total_refunded() );
}

/**
 * At most 100 lines. externalId is the catalogue product (a variation's
 * parent, which is what AguMail's catalogue holds); sku is the line's own, a
 * variation's included, so a segment can ask "bought SKU X". A line whose
 * product was deleted has neither. The unit price includes tax when the store
 * shows its cart prices with tax, as the shopper saw it.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_items( $order ) {
	$with_tax = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	$items    = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( count( $items ) >= AGUMAIL_MAX_ITEMS ) {
			break;
		}
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$line       = array();
		$product_id = (int) $item->get_product_id();
		if ( $product_id > 0 ) {
			$line['externalId'] = (string) $product_id;
		}
		$product = $item->get_product();
		$sku     = $product ? agumail_clamp_sku( $product->get_sku() ) : null;
		if ( null !== $sku ) {
			$line['sku'] = $sku;
		}
		$line['title'] = agumail_catalogue_text( $item->get_name(), AGUMAIL_MAX_TITLE );
		$line['qty']   = agumail_quantity( $item->get_quantity() );
		$line['price'] = agumail_money( $order->get_item_total( $item, $with_tax, true ) );
		$items[]       = $line;
	}
	return $items;
}

/**
 * The buyer, sent before the first delivery of their order, with the answer
 * the submitted checkout gave (none when the box was not shown, or on an
 * order no checkout created). An opt-in dates from the moment the order does
 * (agumail_order_occurred_at()), so it is news or history together with its
 * order: a timely first delivery is news, one that goes late (AguMail was
 * unreachable for days) is history. The customer dates from the order. Later deliveries (a refund, a cancellation) carry
 * no contact: the answer they gave then is not news, and may be older than
 * one they have given since.
 *
 * @param string $key Outbox row key: the order ID.
 * @param array  $row Outbox row.
 */
function agumail_order_contacts( $key, $row ) {
	if ( agumail_order_stored_record( $row ) ) {
		return array();
	}
	$order = wc_get_order( (int) $key );
	if ( ! agumail_order_is_shop_order( $order ) || agumail_order_synced( $order ) ) {
		return array();
	}
	$answer  = agumail_order_answer( $order );
	$contact = agumail_contact_payload(
		$order->get_billing_email(),
		array(
			'first_name'     => $order->get_billing_first_name(),
			'last_name'      => $order->get_billing_last_name(),
			'opted_in'       => null === $answer ? null : 'yes' === $answer,
			'source'         => 'checkout',
			'customer_since' => $order->get_date_created(),
			'consented_at'   => agumail_order_occurred_at( $order, $row ),
		)
	);
	return $contact ? array( $contact ) : array();
}

/**
 * AguMail holds the order. The first time: it is flagged with the account that
 * holds it, the moment it was sent with is kept (so later sends never move it),
 * the cart it was bought from is closed, and a customer who ticked the box for
 * their own address has said yes on their account too, dated from the first
 * yes (the on-site script and the bulk sync read both). A tick on an order
 * billed to another address speaks for that address, not the account. An
 * account that unsubscribed stays so: AguMail asks the shopper to confirm a
 * tick after an unsubscribe, and only their click undoes it.
 *
 * The flags are saved as meta alone. High-Performance Order Storage would
 * otherwise follow a meta change with a full save of the order, which runs
 * every order hook again and sends order.updated webhooks for nothing.
 *
 * @param string $key      Outbox row key: the order ID.
 * @param array  $row      Outbox row.
 */
function agumail_order_sent( $key, $row ) {
	if ( agumail_order_stored_record( $row ) ) {
		return;
	}
	$order = wc_get_order( (int) $key );
	if ( ! agumail_order_is_shop_order( $order ) ) {
		return;
	}
	$first   = ! agumail_order_synced( $order );
	$changed = false;
	if ( '' === (string) $order->get_meta( '_agumail_occurred_at' ) ) {
		$order->update_meta_data( '_agumail_occurred_at', agumail_order_occurred_at( $order, $row ) );
		$changed = true;
	}
	if ( $first ) {
		$account = agumail_order_account_id();
		$order->update_meta_data( '_agumail_synced', '' !== $account ? $account : 'yes' );
		$changed = true;
	}
	if ( $changed ) {
		add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
		try {
			$order->save_meta_data();
		} finally {
			remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
		}
	}
	if ( ! $first ) {
		return;
	}
	agumail_order_remember_on_account( $order );
	agumail_order_close_cart( $order );
}

/**
 * A ticked box on the customer's own address, remembered on their account.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_remember_on_account( $order ) {
	$customer_id = (int) $order->get_customer_id();
	$user        = $customer_id > 0 ? get_userdata( $customer_id ) : false;
	if ( ! $user || 'yes' !== agumail_order_answer( $order ) ) {
		return;
	}
	if ( strtolower( trim( (string) $user->user_email ) ) !== strtolower( trim( (string) $order->get_billing_email() ) ) ) {
		return;
	}
	if ( 'unsubscribed' === get_user_meta( $customer_id, '_agumail_optin', true ) ) {
		return;
	}
	update_user_meta( $customer_id, '_agumail_optin', 'yes' );
	// When the account first opted in, which the bulk sync sends as the consent date.
	if ( '' === (string) get_user_meta( $customer_id, '_agumail_optin_at', true ) ) {
		$paid = $order->get_date_paid();
		update_user_meta( $customer_id, '_agumail_optin_at', agumail_iso_date( $paid ? $paid : $order->get_date_created() ) );
	}
}

/**
 * The cart row the order was bought from is ordered, and goes again so AguMail closes its copy.
 *
 * @param WC_Order $order The order.
 */
function agumail_order_close_cart( $order ) {
	$cart_id = agumail_order_checkout_meta( $order, '_agumail_cart_id' );
	if ( '' === $cart_id || ! function_exists( 'agumail_cart_get' ) || ! function_exists( 'agumail_cart_update' ) ) {
		return;
	}
	$cart = agumail_cart_get( $cart_id );
	if ( ! $cart || 'ordered' === $cart['status'] ) {
		return;
	}
	agumail_cart_update(
		$cart_id,
		array(
			'status'   => 'ordered',
			'order_id' => (int) $order->get_id(),
		)
	);
}
