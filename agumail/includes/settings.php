<?php
/**
 * Settings: where AguMail lives, the key that speaks for this store, and the
 * store-wide choices the merchant makes. Every option is read through one of
 * these functions, so each default lives in one place.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_DEFAULT_API_URL', 'https://api.agumail.com' );
define( 'AGUMAIL_DEFAULT_APP_URL', 'https://agumail.com' );

// Who manages AguMail from wp-admin: the settings page, its tools, admin-ajax.
// A capability of the plugin's own, which agumail_map_admin_capability() turns
// into whoever runs the site: store managers (manage_woocommerce) while
// WooCommerce is active, administrators (manage_options) without it.
define( 'AGUMAIL_ADMIN_CAPABILITY', 'manage_agumail' );

// Who may change the API URL, which is where the key is sent: an administrator,
// not every store manager.
define( 'AGUMAIL_API_URL_CAPABILITY', 'manage_options' );

// AguMail's own hosts: its pages domain serves the site script, its API the
// forms loader and the address the script reports to.
define( 'AGUMAIL_OWN_HOSTS', 'agupage.com,api.agumail.com' );

// The platform a site connected by key alone (ping) is saved and sent under.
define( 'AGUMAIL_PLATFORM_WORDPRESS', 'wordpress' );

add_filter( 'map_meta_cap', 'agumail_map_admin_capability', 10, 2 );

/**
 * `manage_agumail` is `manage_woocommerce` on a store, `manage_options` on any
 * other site.
 *
 * @param string[] $caps The capabilities the user needs.
 * @param string   $cap  The capability checked.
 */
function agumail_map_admin_capability( $caps, $cap ) {
	if ( AGUMAIL_ADMIN_CAPABILITY !== $cap ) {
		return $caps;
	}
	return array( agumail_woocommerce_active() ? 'manage_woocommerce' : 'manage_options' );
}

// ─────────────────────────────────────────────────────────────────────────────
// Two modes, one plugin. With WooCommerce active the plugin is a store's: the
// checkout checkbox, carts, orders, products and the store data sync. Without
// it, a WordPress site's: forms, popups, form plugin signups, registrations and
// tracking. The mode is read when it matters, never stored, so a site that
// switches WooCommerce on or off keeps its connection, queue and settings.
// ─────────────────────────────────────────────────────────────────────────────

// The plugin follows the WP Consent API: a consent plugin that implements it
// (Complianz, CookieYes and others) decides which cookies may be set.
add_filter( 'wp_consent_api_registered_' . plugin_basename( AGUMAIL_FILE ), '__return_true' );

/**
 * A time as the site shows dates: in its timezone and language. wp_date()
 * (WordPress 5.3 and later, which the plugin requires), with date_i18n() as
 * the fallback.
 *
 * @param string $format    A PHP date format.
 * @param int    $timestamp A Unix timestamp.
 * @return string
 */
function agumail_date( $format, $timestamp ) {
	if ( function_exists( 'wp_date' ) ) {
		return (string) wp_date( $format, (int) $timestamp );
	}
	return (string) date_i18n( $format, (int) $timestamp + (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
}

/**
 * Whether a consent plugin implementing the WP Consent API is active. Without
 * one there is nobody to ask, so the site's own settings decide, as before.
 *
 * @return bool
 */
function agumail_consent_api_active() {
	return function_exists( 'wp_has_consent' );
}

/**
 * Whether this request carries the visitor's consent to a WP Consent API
 * category (marketing, statistics...). True when no consent plugin is active.
 * Only for what the server does in this response, such as setting a cookie:
 * a page a cache may keep must decide in the browser (agumail_consent_js()).
 *
 * @param string $category The consent category.
 * @return bool
 */
function agumail_has_consent( $category ) {
	return ! function_exists( 'wp_has_consent' ) || (bool) wp_has_consent( $category );
}

/**
 * Sets a cookie of a WP Consent API category, or removes one when $options
 * expires it. With a consent plugin, a cookie the visitor has not allowed is
 * not set. Functional cookies are always allowed by the API, which is how a
 * cookie is always removed.
 *
 * @param string $name     The cookie's name.
 * @param string $value    Its value.
 * @param array  $options  As setcookie() takes them: expires, path, domain, secure, httponly, samesite.
 * @param string $category The consent category: marketing, statistics, functional...
 * @return bool Whether the cookie header was sent.
 */
function agumail_setcookie( $name, $value, array $options, $category ) {
	if ( function_exists( 'wp_has_consent' ) && ! wp_has_consent( $category ) ) {
		return false;
	}
	if ( headers_sent() ) {
		return false;
	}
	return setcookie( $name, $value, $options );
}

/**
 * JavaScript that runs $body once the visitor consents to $category: at once
 * when they already have, else when the consent plugin says they changed
 * their choice. Until the API's own wp_has_consent() is there, nothing runs,
 * so a page never acts on consent it could not check. Decided in the browser,
 * the page is the same for every visitor, which a page cache needs.
 *
 * @param string $category The consent category.
 * @param string $body     JavaScript statements to run once, with consent.
 * @return string
 */
function agumail_consent_js( $category, $body ) {
	$category = wp_json_encode( (string) $category );
	return '(function(){var done=false;'
		. 'function go(){if(done||typeof window.wp_has_consent!=="function"||!window.wp_has_consent(' . $category . '))return;done=true;' . $body . '}'
		. 'go();document.addEventListener("wp_listen_for_consent_change",go);'
		. 'if(document.readyState!=="complete"){window.addEventListener("load",go);}})();';
}

/**
 * WooCommerce's session for this request, or null without WooCommerce or
 * before WooCommerce starts it (in wp-admin, cron and REST requests, say).
 *
 * @return WC_Session|null
 */
function agumail_wc_session() {
	return function_exists( 'WC' ) ? WC()->session : null;
}

/**
 * WooCommerce's cart for this request, or null without WooCommerce or before
 * WooCommerce loads it.
 *
 * @return WC_Cart|null
 */
function agumail_wc_cart() {
	return function_exists( 'WC' ) ? WC()->cart : null;
}

/**
 * WooCommerce's customer for this request, or null without WooCommerce or
 * before WooCommerce loads it.
 *
 * @return WC_Customer|null
 */
function agumail_wc_customer() {
	return function_exists( 'WC' ) ? WC()->customer : null;
}

/**
 * Whether WooCommerce is active and loaded. WordPress loads plugins in
 * alphabetical order, so this is known from plugins_loaded on, not while the
 * plugin's own files load.
 */
function agumail_woocommerce_active() {
	return class_exists( 'WooCommerce' ) && did_action( 'woocommerce_loaded' ) > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Request input. Every read of $_POST and $_GET goes through these four, so
// each value is unslashed and cleaned in one place. They check no nonce: each
// caller says why its request needs none, or which check already ran.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Cleans a request value for its type, as the original read did: text with
 * sanitize_text_field(), a key with sanitize_key(), an email with
 * sanitize_email(), a URL with esc_url_raw().
 *
 * @param string $value The unslashed value.
 * @param string $type  One of text, key, email or url.
 * @return string
 */
function agumail_clean_input( $value, $type ) {
	switch ( $type ) {
		case 'key':
			return sanitize_key( $value );
		case 'email':
			return sanitize_email( $value );
		case 'url':
			return esc_url_raw( $value );
		default:
			return sanitize_text_field( $value );
	}
}

/**
 * A posted field ($_POST), unslashed, or null when it was not posted. Raw:
 * callers clean it for its type.
 *
 * @param string $name The field's name.
 * @return mixed
 */
function agumail_posted_raw( $name ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; each caller cleans the value for its type.
	return wp_unslash( $_POST[ $name ] ?? null );
}

/**
 * A query parameter ($_GET), unslashed, or null when it is not in the URL.
 * Raw: callers clean it for its type.
 *
 * @param string $name The parameter's name.
 * @return mixed
 */
function agumail_queried_raw( $name ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; each caller cleans the value for its type.
	return wp_unslash( $_GET[ $name ] ?? null );
}

/**
 * A posted field ($_POST), cleaned, or '' when it is missing or not text.
 *
 * @param string $name The field's name.
 * @param string $type One of text, key, email or url.
 * @return string
 */
function agumail_posted( $name, $type = 'text' ) {
	$value = agumail_posted_raw( $name );
	return is_string( $value ) ? agumail_clean_input( $value, $type ) : '';
}

/**
 * Whether a posted field ($_POST) is there and not empty, as a ticked box is.
 *
 * @param string $name The field's name.
 * @return bool
 */
function agumail_posted_flag( $name ) {
	$value = agumail_posted_raw( $name );
	return ! empty( $value );
}

/**
 * A query parameter ($_GET), cleaned, or '' when it is missing or not text.
 *
 * @param string $name The parameter's name.
 * @param string $type One of text, key, email or url.
 * @return string
 */
function agumail_queried( $name, $type = 'text' ) {
	$value = agumail_queried_raw( $name );
	return is_string( $value ) ? agumail_clean_input( $value, $type ) : '';
}

/**
 * Whether a query parameter ($_GET) is there at all, whatever its value.
 *
 * @param string $name The parameter's name.
 * @return bool
 */
function agumail_queried_flag( $name ) {
	return null !== agumail_queried_raw( $name );
}

// ─────────────────────────────────────────────────────────────────────────────
// Where AguMail lives. wp-config.php can pin either address with AGUMAIL_API_URL
// or AGUMAIL_APP_URL, which is how a development or end-to-end site points the
// plugin at a local server; a pinned address wins over anything saved.
// ─────────────────────────────────────────────────────────────────────────────

/** AguMail's API address, without a trailing slash. */
function agumail_api_url() {
	return agumail_resolve_url( 'AGUMAIL_API_URL', 'agumail_api_url', AGUMAIL_DEFAULT_API_URL );
}

/** AguMail's app address, without a trailing slash. */
function agumail_app_url() {
	return agumail_resolve_url( 'AGUMAIL_APP_URL', 'agumail_app_url', AGUMAIL_DEFAULT_APP_URL );
}

/** True when wp-config.php pins the API address, so the settings page shows it read-only. */
function agumail_api_url_is_pinned() {
	return defined( 'AGUMAIL_API_URL' ) && '' !== (string) AGUMAIL_API_URL;
}

/**
 * The address a constant pins, else the saved option, else the default, without
 * a trailing slash.
 *
 * @param string $constant The wp-config.php constant that pins it.
 * @param string $option   The option it is saved in.
 * @param string $fallback The address used when neither is set.
 */
function agumail_resolve_url( $constant, $option, $fallback ) {
	if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
		return untrailingslashit( (string) constant( $constant ) );
	}
	$saved = trim( (string) get_option( $option, '' ) );
	return untrailingslashit( '' !== $saved ? $saved : $fallback );
}

/**
 * Whether an address may go on the storefront: the site script, the forms
 * loader, or where the script reports. It must be https on one of AguMail's own
 * hosts, or on a host wp-config.php pins with AGUMAIL_API_URL or AGUMAIL_APP_URL
 * (http allowed there, as a local server runs it). Neither AguMail's answers
 * nor anything saved in the settings adds a host, so the store's pages never
 * load a script from anywhere else.
 *
 * @param mixed $url The address.
 */
function agumail_is_own_url( $url ) {
	if ( ! is_string( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}
	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	$host   = strtolower( $parts['host'] );
	if ( 'https' === $scheme && in_array( $host, explode( ',', AGUMAIL_OWN_HOSTS ), true ) ) {
		return true;
	}
	if ( 'https' !== $scheme && 'http' !== $scheme ) {
		return false;
	}
	foreach ( array( 'AGUMAIL_API_URL', 'AGUMAIL_APP_URL' ) as $constant ) {
		$pinned = defined( $constant ) ? strtolower( (string) wp_parse_url( (string) constant( $constant ), PHP_URL_HOST ) ) : '';
		if ( '' !== $pinned && $host === $pinned ) {
			return true;
		}
	}
	return false;
}

/** The saved API key, or '' when there is none. */
function agumail_api_key() {
	return trim( (string) get_option( 'agumail_api_key', '' ) );
}

/** Whether an API key is saved. */
function agumail_has_api_key() {
	return '' !== agumail_api_key();
}

/**
 * Saves the key, autoloaded: every storefront request reads it, so it must not
 * cost a query of its own.
 *
 * @param string $key The API key.
 */
function agumail_save_api_key( $key ) {
	return update_option( 'agumail_api_key', trim( (string) $key ), true );
}

/**
 * Whether a user (the signed-in one by default) is staff rather than a
 * shopper or reader: anyone who manages AguMail here (store managers with
 * WooCommerce, administrators without), unless the agumail_is_staff filter
 * says otherwise. Staff are never tracked or treated as customers.
 *
 * @param int|null $user_id The user's id, or null for the signed-in user.
 */
function agumail_is_staff( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	$staff   = $user_id > 0 && user_can( $user_id, AGUMAIL_ADMIN_CAPABILITY );
	return (bool) apply_filters( 'agumail_is_staff', $staff, $user_id );
}

// ─────────────────────────────────────────────────────────────────────────────
// Store-wide choices.
// ─────────────────────────────────────────────────────────────────────────────

/** The marketing checkbox's words when the merchant has not written their own. */
function agumail_default_consent_label() {
	return __( 'Email me about new products and offers', 'agumail' );
}

/**
 * The words next to the marketing checkbox, on both checkouts and the
 * customer account forms. They may carry links (<a href target rel>, nothing
 * else): print them with wp_kses() and agumail_consent_label_tags(), or as
 * agumail_consent_label_text() where only words fit.
 */
function agumail_consent_label() {
	$label = trim( (string) get_option( 'agumail_consent_label', '' ) );
	return '' !== $label ? $label : agumail_default_consent_label();
}

/** The only markup the label keeps: links. */
function agumail_consent_label_tags() {
	return array(
		'a' => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);
}

/** The label as plain words, for the block checkout, which shows it as text. */
function agumail_consent_label_text() {
	return trim( html_entity_decode( wp_strip_all_tags( agumail_consent_label() ), ENT_QUOTES, 'UTF-8' ) );
}

/** Whether the checkout's marketing checkbox starts ticked. Off unless the merchant turns it on. */
function agumail_checkout_optin_ticked_by_default() {
	return 'yes' === get_option( 'agumail_checkout_optin_default', 'no' );
}

/**
 * A yes/no switch: agumail_registration_optin, agumail_account_toggle. Both
 * default to yes.
 *
 * @param string $option The option's name.
 */
function agumail_switch_on( $option ) {
	return 'no' !== get_option( $option, 'yes' );
}

/** Whether website tracking is on. Off until the merchant switches it on. */
function agumail_tracking_on() {
	return 'yes' === get_option( 'agumail_tracking_enabled', 'no' );
}

/**
 * The config AguMail last handed over through ping or connect, or one key of
 * it.
 *
 * @param string|null $key One key, or null for the whole config.
 */
function agumail_config( $key = null ) {
	$config = get_option( 'agumail_config', array() );
	if ( ! is_array( $config ) ) {
		$config = array();
	}
	if ( null === $key ) {
		return $config;
	}
	return isset( $config[ $key ] ) ? $config[ $key ] : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Connection state, as last seen by ping, connect or the drain:
// {status, shopName, userId, integrationId, checkedAt, error, errorCode,
// platform}. status is '' until the site has connected, then connected,
// disconnected or error. errorCode is the HTTP status behind an error, so the
// settings page can tell a revoked key (401) from a lapsed plan (402).
//
// platform is how the site connected: woocommerce when AguMail took it as a
// store (one-click, or a key through /api/v1/connect), `wordpress` when only the
// key was checked (ping), for a site without WooCommerce or a store whose plan
// has no store sync. A connection from before platform was kept is a store's.
// ─────────────────────────────────────────────────────────────────────────────

/** The saved connection state, with defaults for any field it lacks. */
function agumail_connection() {
	$saved = get_option( 'agumail_connection', array() );
	return array_merge(
		array(
			'status'        => '',
			'shopName'      => '',
			'userId'        => '',
			'integrationId' => '',
			'checkedAt'     => 0,
			'error'         => '',
			'errorCode'     => 0,
		),
		is_array( $saved ) ? $saved : array()
	);
}

/**
 * Saves the connection state with these fields changed.
 *
 * @param array $changes The fields to change.
 */
function agumail_update_connection( array $changes ) {
	return update_option( 'agumail_connection', array_merge( agumail_connection(), $changes ), false );
}

/**
 * AguMail refused the key or the plan (401, 402 or 403): the drain stops and
 * the settings page says why.
 *
 * @param int    $code    The HTTP status.
 * @param string $message Why AguMail refused.
 */
function agumail_record_connection_error( $code, $message ) {
	agumail_update_connection(
		array(
			'status'    => 'error',
			'error'     => (string) $message,
			'errorCode' => (int) $code,
			'checkedAt' => time(),
		)
	);
}

/** A call went through again, so an earlier refusal no longer stands. */
function agumail_clear_connection_error() {
	$connection = agumail_connection();
	if ( 'error' !== $connection['status'] ) {
		return;
	}
	agumail_update_connection(
		array(
			'status'    => 'connected',
			'error'     => '',
			'errorCode' => 0,
			'checkedAt' => time(),
		)
	);
}

/** `woocommerce` or `wordpress`: how the site connected. */
function agumail_connection_platform() {
	$saved    = get_option( 'agumail_connection', array() );
	$platform = is_array( $saved ) && isset( $saved['platform'] ) ? $saved['platform'] : '';
	return AGUMAIL_PLATFORM_WORDPRESS === $platform ? AGUMAIL_PLATFORM_WORDPRESS : 'woocommerce';
}

// ─────────────────────────────────────────────────────────────────────────────
// Store sync: orders, carts and products, and the store data sync. AguMail
// takes them from a store it connected as one, on a plan that has store sync
// (the Commerce plans and the trial). Contacts, signups and forms work on every
// plan, so they never wait on any of this.
//
// When AguMail refuses a store request for the plan (402, code PLAN_FEATURE,
// feature storeSync), sending store data is held: orders and products wait in
// the queue, carts are not kept, and everything else carries on. The drain
// checks the plan again every hour, with a request that carries no data
// (agumail_store_sync_check()), and lets the store data go once it is back.
// ─────────────────────────────────────────────────────────────────────────────

define( 'AGUMAIL_STORE_HOLD_OPTION', 'agumail_store_sync_refused' );
define( 'AGUMAIL_STORE_CHECK_OPTION', 'agumail_store_sync_available' );
define( 'AGUMAIL_STORE_CHECK_EVERY', HOUR_IN_SECONDS );

/** WooCommerce is active and the site is connected to AguMail as a store. */
function agumail_store_connected() {
	return agumail_has_api_key() && agumail_woocommerce_active() && 'woocommerce' === agumail_connection_platform();
}

/** When AguMail last refused store data for the plan, or 0 while it is not held. */
function agumail_store_sync_held_since() {
	return (int) get_option( AGUMAIL_STORE_HOLD_OPTION, 0 );
}

/** Whether sending store data is held for the plan. */
function agumail_store_sync_held() {
	return agumail_store_sync_held_since() > 0;
}

/** Store data goes to AguMail: a connected store, not held by the plan. */
function agumail_store_sync_on() {
	return agumail_store_connected() && ! agumail_store_sync_held();
}

/**
 * What the last plan check found: ['available' => true|false, 'at' => time],
 * or null when nothing has been checked yet.
 */
function agumail_store_sync_availability() {
	$saved = get_option( AGUMAIL_STORE_CHECK_OPTION, array() );
	if ( ! is_array( $saved ) || ! isset( $saved['available'], $saved['at'] ) ) {
		return null;
	}
	return array(
		'available' => (bool) $saved['available'],
		'at'        => (int) $saved['at'],
	);
}

/**
 * Whether a plan refusal is about store sync: AguMail's 402 names the feature.
 *
 * @param array|WP_Error $response The answer from agumail_api_request().
 */
function agumail_is_store_sync_refusal( $response ) {
	return is_array( $response ) && isset( $response['code'] ) && 402 === (int) $response['code']
		&& is_array( $response['body'] )
		&& isset( $response['body']['code'], $response['body']['feature'] )
		&& 'PLAN_FEATURE' === $response['body']['code'] && 'storeSync' === $response['body']['feature'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Backoff: a moment before which the drain sends nothing at all, set by a 429's
// Retry-After or by a refused key.
// ─────────────────────────────────────────────────────────────────────────────

/** The time before which the drain sends nothing, or 0. */
function agumail_backoff_until() {
	return (int) get_option( 'agumail_backoff_until', 0 );
}

/**
 * Sets the time before which the drain sends nothing.
 *
 * @param int $timestamp A Unix timestamp.
 */
function agumail_set_backoff_until( $timestamp ) {
	update_option( 'agumail_backoff_until', (int) $timestamp, false );
}

/** Lets the drain send again. */
function agumail_clear_backoff() {
	delete_option( 'agumail_backoff_until' );
}
