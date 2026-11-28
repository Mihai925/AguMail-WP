<?php
/**
 * Connect: one-click connect, connecting with a key, and config refresh
 * through ping.
 *
 * How a site connects depends on what it is and on its AguMail plan:
 *   - A WooCommerce store connects in one click, or with a key through
 * api/v1/connect. AguMail then takes it as a store (platform `woocommerce`).
 *     Both need a plan with store sync (the Commerce plans, or the trial).
 *   - A site without WooCommerce, or a store on a plan without store sync,
 * connects with a key that ping checks (platform `wordpress`). Ping answers
 *     on every plan, and so do contacts, signups and forms, which is all such
 *     a site sends. AguMail keeps no store for it, so its account is not set up
 *     as a store's. A store connected this way can start syncing later with the
 *     same key ("Sync your store"), once its plan has store sync.
 *
 * One-click connect works like an OAuth app install with PKCE:
 *   1. "Connect to AguMail" keeps a random state and a verifier for this
 *      merchant for an hour, and sends them to AguMail's app with the store,
 *      the address to come back to, the state and sha256(verifier).
 *   2. The merchant approves there; AguMail sends them back to this settings
 *      page with a code and the same state.
 *   3. The plugin checks the state, throws the kept pair away, and trades the
 *      code and the verifier for an API key. Only this site ever held the
 *      verifier, so a code caught on the way back is worth nothing alone.
 * "Advanced" keeps the older way for anyone who needs it: an API key pasted by
 * hand, and the API address.
 *
 * After that, ping keeps the config (site key, script and loader addresses,
 * identify secret) fresh: once a day, and when the settings page is opened and
 * the last check is over an hour old. Ping never connects the store again, so a
 * store disconnected in AguMail stays disconnected: sending pauses and the page
 * offers Reconnect.
 *
 * A copy of the store (a staging site, a clone) carries the live store's key in
 * its database. The address the store connected from is recorded, and a site
 * whose own address differs from it, or from the one AguMail knows the store
 * by, sends nothing until it is reconnected from there.
 *
 * Every call here runs from an admin tool, the settings page or housekeeping,
 * never from a shopper's request.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// As long as AguMail keeps its side of a start. PKCE and the code's own
// 10-minute life are what bound it.
define( 'AGUMAIL_CONNECT_TTL', HOUR_IN_SECONDS );
define( 'AGUMAIL_PING_STALE', HOUR_IN_SECONDS );
define( 'AGUMAIL_PING_PAGE_TIMEOUT', 5 );
define( 'AGUMAIL_PING_RETRY', 10 * MINUTE_IN_SECONDS );
define( 'AGUMAIL_CONFIG_KEYS', 'siteKey,siteScriptUrl,siteEndpoint,formsLoaderUrl,identifySecret' );
// The config's addresses that go on the storefront, kept only on AguMail's own hosts.
define( 'AGUMAIL_CONFIG_URL_KEYS', 'siteScriptUrl,siteEndpoint,formsLoaderUrl' );

add_action( 'init', 'agumail_connect_register_admin', 10, 0 );
add_action( 'admin_init', 'agumail_connect_on_settings_page', 10, 0 );
add_filter( 'agumail_drain_paused', 'agumail_connect_drain_paused' );
agumail_add_background_action( 'agumail_housekeeping', 'agumail_connect_housekeeping', 10, 0 );

/**
 * Registers the Connection section and its admin actions.
 */
function agumail_connect_register_admin() {
	agumail_admin_register_section(
		'connection',
		__( 'Connection', 'agumail' ),
		'agumail_connect_render_section',
		10,
		array(
			'icon'        => 'admin-links',
			'description' => agumail_woocommerce_active()
				? __( 'Your AguMail account and this store.', 'agumail' )
				: __( 'Your AguMail account and this site.', 'agumail' ),
		)
	);
	agumail_admin_register_action( 'connect_start', 'agumail_connect_start' );
	agumail_admin_register_action(
		'connect_save_advanced',
		'agumail_connect_save_advanced',
		array(
			'agumail_api_key' => 'text',
			'agumail_api_url' => 'text',
		)
	);
	agumail_admin_register_action( 'connect_disconnect', 'agumail_connect_disconnect' );
	agumail_admin_register_action( 'connect_store', 'agumail_connect_store' );
	agumail_admin_register_action( 'connect_check', 'agumail_connect_check' );
}

if ( ! function_exists( 'agumail_base64url' ) ) {
	/**
	 * Base64url without padding (RFC 4648 section 5), as PKCE and the signed identify use it.
	 *
	 * @param string $bytes Raw bytes to encode.
	 */
	function agumail_base64url( $bytes ) {
		return rtrim( strtr( base64_encode( (string) $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes PKCE and identify signatures (RFC 4648), not code.
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// One-click connect.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The transient that keeps an administrator's pending start.
 *
 * @param int|null $user_id User ID, or null for the current user.
 */
function agumail_connect_pending_key( $user_id = null ) {
	return 'agumail_connect_' . (int) ( null === $user_id ? get_current_user_id() : $user_id );
}

/** The store as AguMail sees it: the site's home address. */
function agumail_connect_store_url() {
	return untrailingslashit( home_url() );
}

/**
 * The store's currency code, or '' without WooCommerce.
 */
function agumail_connect_store_currency() {
	return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
}

/**
 * $body with the store's currency, left out when it is not a three-letter code.
 *
 * @param array  $body Request body.
 * @param string $code The store's currency code.
 */
function agumail_connect_with_currency( array $body, $code ) {
	$currency = agumail_currency( $code );
	if ( null !== $currency ) {
		$body['currency'] = $currency;
	}
	return $body;
}

/**
 * A store address as AguMail keeps it: no scheme, no path, lowercase, the port kept (normalizeStoreUrl).
 *
 * @param string $url Address to normalize.
 */
function agumail_connect_normalize_host( $url ) {
	$host = strtolower( trim( (string) $url ) );
	$host = preg_replace( '#^https?://#', '', $host );
	return (string) preg_replace( '#/.*$#s', '', $host );
}

/** This site's own address, as AguMail would keep it. */
function agumail_connect_site_host() {
	return agumail_connect_normalize_host( home_url() );
}

/**
 * Two addresses are the same store when they differ at most by a leading www.
 *
 * @param string $a One address.
 * @param string $b The other address.
 */
function agumail_connect_same_host( $a, $b ) {
	$strip = function ( $host ) {
		return preg_replace( '/^www\./', '', $host );
	};
	return $strip( agumail_connect_normalize_host( $a ) ) === $strip( agumail_connect_normalize_host( $b ) );
}

/**
 * AguMail sends the merchant back to the admin only on the store's own host,
 * so a site whose admin lives elsewhere cannot connect in one click.
 *
 * @param string $home_url  The store's home address.
 * @param string $admin_url The admin address AguMail sends the merchant back to.
 */
function agumail_connect_host_problem( $home_url, $admin_url ) {
	$home  = agumail_connect_normalize_host( $home_url );
	$admin = agumail_connect_normalize_host( $admin_url );
	if ( '' === $home || $home === $admin ) {
		return null;
	}
	return new WP_Error(
		'agumail_split_hosts',
		sprintf(
		/* translators: 1: the store's address, 2: its admin address */
			__( 'This store is at %1$s but its admin is at %2$s, so AguMail cannot bring you back here after you approve. Connect with an API key under Advanced instead.', 'agumail' ),
			$home,
			$admin
		)
	);
}

/**
 * Set when this site's address is not the one the store connected from, or not
 * the one AguMail knows it by: ['from' => host, 'to' => host], else null.
 */
function agumail_connect_address_change() {
	if ( ! agumail_has_api_key() ) {
		return null;
	}
	$connection = agumail_connection();
	$here       = agumail_connect_site_host();
	foreach ( array( 'siteHost', 'storeUrl' ) as $field ) {
		$known = isset( $connection[ $field ] ) && is_string( $connection[ $field ] ) ? agumail_connect_normalize_host( $connection[ $field ] ) : '';
		if ( '' !== $known && ! agumail_connect_same_host( $known, $here ) ) {
			return array(
				'from' => $known,
				'to'   => $here,
			);
		}
	}
	return null;
}

/**
 * Which administrator a start belongs to, found by its state.
 *
 * @param string $state The start's state.
 */
function agumail_connect_owner_key( $state ) {
	return 'agumail_connect_owner_' . substr( hash( 'sha256', (string) $state ), 0, 32 );
}

/**
 * The settings page address AguMail sends the merchant back to.
 */
function agumail_connect_return_url() {
	return agumail_admin_page_url( array( 'agumail_connect' => 'callback' ) );
}

/**
 * The PKCE challenge for a verifier: its sha256, base64url-encoded.
 *
 * @param string $verifier The PKCE verifier.
 */
function agumail_connect_challenge( $verifier ) {
	return agumail_base64url( hash( 'sha256', $verifier, true ) );
}

/**
 * Admin action: keep a fresh state and verifier for this merchant, and send
 * them to AguMail to approve. A store's way in only: AguMail's approval page
 * connects a store, so a site without WooCommerce connects with a key.
 */
function agumail_connect_start() {
	if ( ! agumail_woocommerce_active() ) {
		return new WP_Error( 'agumail_connect_key_only', __( 'Connect this site with an API key from your AguMail account.', 'agumail' ) );
	}
	$problem = agumail_connect_host_problem( home_url(), agumail_connect_return_url() );
	if ( $problem ) {
		return $problem;
	}
	$state    = bin2hex( random_bytes( 16 ) );
	$verifier = agumail_base64url( random_bytes( 48 ) );
	set_transient(
		agumail_connect_pending_key(),
		array(
			'state'    => $state,
			'verifier' => $verifier,
		),
		AGUMAIL_CONNECT_TTL
	);
	set_transient( agumail_connect_owner_key( $state ), get_current_user_id(), AGUMAIL_CONNECT_TTL );
	// Built by hand rather than with add_query_arg(), which leaves values unencoded.
	$query = http_build_query(
		array(
			'store'     => agumail_connect_store_url(),
			'return'    => agumail_connect_return_url(),
			'state'     => $state,
			'challenge' => agumail_connect_challenge( $verifier ),
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
	return array( 'redirect' => agumail_app_url() . '/connect/woocommerce?' . $query );
}

/**
 * The settings page, before anything is printed: handles the way back from
 * AguMail, or refreshes a stale connection. Only a store manager's own
 * wp-admin page view; never admin-ajax, which storefront themes call too.
 */
function agumail_connect_on_settings_page() {
	if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	// No nonce: the callback is checked against the kept state.
	$page     = agumail_queried( 'page', 'key' );
	$callback = 'callback' === agumail_queried( 'agumail_connect', 'key' );
	$query    = array();
	foreach ( array( 'state', 'code', 'error' ) as $name ) {
		if ( $callback && agumail_queried_flag( $name ) ) {
			$query[ $name ] = agumail_queried( $name );
		}
	}
	if ( AGUMAIL_ADMIN_PAGE !== $page || ! current_user_can( AGUMAIL_ADMIN_CAPABILITY ) ) {
		return;
	}
	if ( $callback ) {
		agumail_connect_finish( $query );
		return;
	}
	agumail_connect_refresh_if_stale();
}

/**
 * Back from AguMail: check the state, exchange the code, and show the page again without the code in its address.
 *
 * @param array $query The state, code and error from the callback address.
 */
function agumail_connect_finish( array $query ) {
	$state = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';
	$code  = isset( $query['code'] ) && is_string( $query['code'] ) ? trim( $query['code'] ) : '';
	$error = isset( $query['error'] ) && is_string( $query['error'] ) ? $query['error'] : '';
	$owner = '' !== $state ? (int) get_transient( agumail_connect_owner_key( $state ) ) : 0;
	if ( $owner > 0 && get_current_user_id() !== $owner ) {
		// Another administrator's start: theirs to finish, so it is left alone.
		agumail_admin_notice( 'error', __( 'This connection was started by another administrator. Press Connect to AguMail to start again.', 'agumail' ) );
		wp_safe_redirect( agumail_admin_page_url() );
		exit;
	}
	$key     = agumail_connect_pending_key();
	$pending = get_transient( $key );
	delete_transient( $key );
	if ( is_array( $pending ) && ! empty( $pending['state'] ) ) {
		delete_transient( agumail_connect_owner_key( $pending['state'] ) );
	}

	if ( ! is_array( $pending ) || empty( $pending['state'] ) || empty( $pending['verifier'] ) ) {
		$result = new WP_Error( 'agumail_connect_expired', __( 'This connection link has expired. Press Connect to AguMail to start again.', 'agumail' ) );
	} elseif ( '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
		$result = new WP_Error( 'agumail_connect_state', __( 'This connection could not be confirmed as yours. Press Connect to AguMail to start again.', 'agumail' ) );
	} elseif ( 'access_denied' === $error ) {
		// The merchant pressed Cancel on AguMail's approval page.
		$result = array(
			'type'    => 'info',
			'message' => __( 'Connection cancelled. Press Connect to AguMail when you\'re ready.', 'agumail' ),
		);
	} elseif ( '' === $code ) {
		$result = new WP_Error( 'agumail_connect_not_approved', __( 'The connection was not approved in AguMail. Press Connect to AguMail to try again.', 'agumail' ) );
	} else {
		$result = agumail_run_in_background( 'agumail_connect_exchange', array( $code, (string) $pending['verifier'] ) );
	}

	$notice = agumail_admin_notice_from( $result );
	if ( $notice ) {
		agumail_admin_notice( $notice['type'], $notice['message'] );
	}
	wp_safe_redirect( agumail_admin_page_url() );
	exit;
}

/**
 * Trades the code and verifier for this store's API key. Neither is ever logged.
 *
 * @param string $code     The code AguMail sent back.
 * @param string $verifier The PKCE verifier kept for this start.
 */
function agumail_connect_exchange( $code, $verifier ) {
	$response = agumail_api_request(
		'POST',
		'/api/v1/connect/exchange',
		agumail_connect_with_currency(
			array(
				'code'     => $code,
				'verifier' => $verifier,
				'storeUrl' => agumail_connect_store_url(),
			),
			agumail_connect_store_currency()
		),
		array( 'auth' => false )
	);
	if ( is_wp_error( $response ) ) {
		return agumail_connect_unreachable( $response );
	}
	$body    = $response['body'];
	$api_key = is_array( $body ) && isset( $body['apiKey'] ) && is_string( $body['apiKey'] ) ? trim( $body['apiKey'] ) : '';
	if ( 200 !== $response['code'] || empty( $body['ok'] ) || ! preg_match( '/^[A-Za-z0-9_\-]{8,200}$/', $api_key ) ) {
		return agumail_connect_refusal( $response );
	}
	agumail_save_api_key( $api_key );
	agumail_connect_save_connection( $body, 'woocommerce' );
	agumail_log( 'info', 'Connected to AguMail.' );
	return array(
		'type'    => 'success',
		'message' => agumail_connect_success_message(),
	);
}

/**
 * Keeps what connect, the exchange or a key's ping answered: the config, the
 * account, when, the address the site connected from, and how it connected
 * ($platform: `woocommerce` or `wordpress`). Then fetches the account's forms.
 * Ping names the account under shop; connect and the exchange at the top.
 *
 * @param array  $body     AguMail's answer.
 * @param string $platform How the site connected.
 */
function agumail_connect_save_connection( array $body, $platform = 'woocommerce' ) {
	if ( isset( $body['config'] ) && is_array( $body['config'] ) ) {
		update_option( 'agumail_config', agumail_connect_clean_config( $body['config'] ) );
	}
	$account = isset( $body['shop'] ) && is_array( $body['shop'] ) ? $body['shop'] : $body;
	$user_id = agumail_connect_string( $account, 'userId' );
	agumail_connect_forget_other_account( $user_id );
	agumail_update_connection(
		array(
			'status'        => 'connected',
			'shopName'      => agumail_connect_string( $account, 'shopName' ),
			'userId'        => $user_id,
			'integrationId' => agumail_connect_string( $body, 'integrationId' ),
			'siteHost'      => agumail_connect_site_host(),
			'storeUrl'      => agumail_connect_site_host(),
			'checkedAt'     => time(),
			'error'         => '',
			'errorCode'     => 0,
			'platform'      => AGUMAIL_PLATFORM_WORDPRESS === $platform ? AGUMAIL_PLATFORM_WORDPRESS : 'woocommerce',
		)
	);
	update_option( 'agumail_connected_at', time(), false );
	delete_transient( 'agumail_ping_retry' );
	// A refused key paused sending for a while; the new one need not wait.
	agumail_clear_backoff();
	// A store AguMail just took has store sync; a site connected by key sends no store data.
	foreach ( array( AGUMAIL_STORE_HOLD_OPTION, AGUMAIL_STORE_CHECK_OPTION, 'agumail_store_notice' ) as $option ) {
		delete_option( $option );
	}
	if ( AGUMAIL_PLATFORM_WORDPRESS !== $platform ) {
		set_transient( 'agumail_offer_sync_' . get_current_user_id(), 1, HOUR_IN_SECONDS );
	}
	if ( function_exists( 'agumail_forms_refresh' ) ) {
		$forms = agumail_forms_refresh();
		if ( is_wp_error( $forms ) ) {
			agumail_log( 'warning', 'Connected, but the list of forms could not be fetched: ' . $forms->get_error_message() );
		}
	}
}

/** The AguMail account this store last sent to, remembered across "Disconnect from this site". */
function agumail_connect_previous_account() {
	$current = agumail_connection()['userId'];
	return '' !== $current ? $current : (string) get_option( 'agumail_last_account', '' );
}

/**
 * What is kept here for sending belongs to one AguMail account. When the store
 * connects to another, the previous account's queue (pending and failed rows),
 * the addresses held for customer accounts (_agumail_email) and its forms are
 * dropped, so none of it reaches the new account.
 *
 * @param string $user_id The AguMail account now connected.
 */
function agumail_connect_forget_other_account( $user_id ) {
	if ( '' === $user_id ) {
		return;
	}
	$previous = agumail_connect_previous_account();
	if ( '' !== $previous && $previous !== $user_id ) {
		delete_option( 'agumail_forms_cache' );
		if ( function_exists( 'agumail_outbox_clear' ) ) {
			agumail_outbox_clear();
		}
		delete_metadata( 'user', 0, '_agumail_email', '', true );
		agumail_log( 'info', 'This store now sends to another AguMail account. The previous account\'s queue and held addresses were cleared.' );
	}
	update_option( 'agumail_last_account', $user_id, false );
}

/**
 * The notice for a new connection, naming the account when AguMail gave its name.
 */
function agumail_connect_success_message() {
	$shop = agumail_connection()['shopName'];
	return '' !== $shop
		/* translators: %s: the AguMail account's shop name */
		? sprintf( __( 'Connected to AguMail as %s.', 'agumail' ), $shop )
		: __( 'Connected to AguMail.', 'agumail' );
}

/** A store that connected for forms and signups, because its plan has no store sync. */
function agumail_connect_forms_only_message() {
	return agumail_connect_success_message() . ' ' . __( 'Your forms, signups and checkout subscribers go to AguMail now. Orders, carts and products sync on AguMail\'s Commerce plans; once you move to one, press Sync your store here.', 'agumail' );
}

/**
 * Only the keys the plugin knows, each a non-empty string or null. An address
 * for the storefront off AguMail's own hosts is null, whoever answered: the
 * store's pages load only AguMail's scripts.
 *
 * @param array $config The config AguMail sent.
 */
function agumail_connect_clean_config( array $config ) {
	$clean = array();
	$urls  = explode( ',', AGUMAIL_CONFIG_URL_KEYS );
	foreach ( explode( ',', AGUMAIL_CONFIG_KEYS ) as $key ) {
		$value = isset( $config[ $key ] ) && is_string( $config[ $key ] ) ? trim( $config[ $key ] ) : '';
		if ( '' !== $value && in_array( $key, $urls, true ) && ! agumail_is_own_url( $value ) ) {
			agumail_log( 'warning', sprintf( 'The config\'s %s is not on an AguMail host, so it was not kept.', $key ) );
			$value = '';
		}
		$clean[ $key ] = '' !== $value ? $value : null;
	}
	return $clean;
}

/**
 * A value from an array as sanitized text, or '' when it is missing or not scalar.
 *
 * @param array|null $values Array to read from.
 * @param string     $key    Key to read.
 */
function agumail_connect_string( $values, $key ) {
	return is_array( $values ) && isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? sanitize_text_field( (string) $values[ $key ] ) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Errors, as the merchant reads them.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * AguMail's own error message in a response, or ''.
 *
 * @param array $response Response from agumail_api_request().
 */
function agumail_connect_response_error( $response ) {
	$body = isset( $response['body'] ) ? $response['body'] : null;
	return is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ? sanitize_text_field( $body['error'] ) : '';
}

/**
 * The error shown when AguMail could not be reached.
 *
 * @param WP_Error $error The network error.
 */
function agumail_connect_unreachable( WP_Error $error ) {
	return new WP_Error(
		'agumail_unreachable',
		sprintf(
		/* translators: %s: the network error */
			__( 'AguMail could not be reached (%s). Please try again.', 'agumail' ),
			$error->get_error_message()
		)
	);
}

/**
 * AguMail said no, or answered something the plugin cannot use. Its own words are shown when it gave any.
 *
 * @param array $response Response from agumail_api_request().
 */
function agumail_connect_refusal( array $response ) {
	$code    = (int) $response['code'];
	$message = agumail_connect_response_error( $response );
	if ( '' === $message ) {
		if ( 401 === $code || 403 === $code ) {
			/* translators: %d: the HTTP status code */
			$message = sprintf( __( 'AguMail refused this key (HTTP %d).', 'agumail' ), $code );
		} elseif ( $code >= 200 && $code < 300 ) {
			$message = __( 'AguMail sent an answer the plugin could not read. Please try again.', 'agumail' );
		} else {
			/* translators: %d: the HTTP status code */
			$message = sprintf( __( 'AguMail could not connect this store (HTTP %d). Please try again.', 'agumail' ), $code );
		}
	}
	return new WP_Error( 'agumail_refused', $message, array( 'status' => $code ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Advanced: a pasted key and the API address.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Admin action: save the address and key, and connect with them. A refusal
 * puts the previous ones back.
 *
 * The key goes wherever the API URL says, so only an administrator changes the
 * URL, and a saved key is never sent to another host: moving there takes the
 * key pasted again. The form leaves the URL field out where it shows the URL
 * read-only, and the saved URL then stands.
 *
 * @param array $request The submitted fields.
 */
function agumail_connect_save_advanced( array $request ) {
	$previous_key = get_option( 'agumail_api_key', false );
	$previous_url = get_option( 'agumail_api_url', false );

	$pasted = isset( $request['agumail_api_key'] ) ? trim( sanitize_text_field( (string) $request['agumail_api_key'] ) ) : '';
	$key    = '' !== $pasted ? $pasted : agumail_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'agumail_no_key', __( 'Enter your AguMail API key. You can create one in AguMail under Settings > API keys.', 'agumail' ) );
	}

	$url = null;
	if ( ! agumail_api_url_is_pinned() && isset( $request['agumail_api_url'] ) ) {
		$url = trim( (string) $request['agumail_api_url'] );
		if ( '' === $url ) {
			$url = AGUMAIL_DEFAULT_API_URL;
		}
		if ( ! agumail_connect_valid_url( $url ) ) {
			return new WP_Error( 'agumail_bad_url', __( 'The API URL must be a full address starting with https://. Plain http:// works only for localhost.', 'agumail' ) );
		}
		$url = untrailingslashit( esc_url_raw( $url ) );
		if ( agumail_api_url() === $url ) {
			$url = null;
		}
	}

	if ( null !== $url ) {
		if ( ! current_user_can( AGUMAIL_API_URL_CAPABILITY ) ) {
			return new WP_Error( 'agumail_api_url_forbidden', __( 'Only an administrator can change the API URL.', 'agumail' ) );
		}
		if ( '' === $pasted && agumail_connect_normalize_host( $url ) !== agumail_connect_normalize_host( agumail_api_url() ) ) {
			return new WP_Error( 'agumail_key_for_new_host', __( 'To connect at a new API URL, paste your API key again. A saved key is never sent to another address.', 'agumail' ) );
		}
		if ( AGUMAIL_DEFAULT_API_URL === $url ) {
			delete_option( 'agumail_api_url' );
		} else {
			update_option( 'agumail_api_url', $url );
		}
	}
	agumail_save_api_key( $key );

	$result = agumail_woocommerce_active() ? agumail_connect_as_store() : agumail_connect_with_key_only();
	if ( is_wp_error( $result ) ) {
		if ( false === $previous_key ) {
			delete_option( 'agumail_api_key' );
		} else {
			agumail_save_api_key( $previous_key );
		}
		if ( null !== $url ) {
			agumail_connect_restore_option( 'agumail_api_url', $previous_url );
		}
		return $result;
	}
	return array(
		'type'    => 'success',
		'message' => AGUMAIL_PLATFORM_WORDPRESS === $result && agumail_woocommerce_active()
			? agumail_connect_forms_only_message()
			: agumail_connect_success_message(),
	);
}

/**
 * Connects the saved key as a store (/api/v1/connect). On a plan without
 * store sync AguMail refuses that, and the store connects for forms and
 * signups instead. Returns the platform it connected as, or a WP_Error.
 */
function agumail_connect_as_store() {
	$response = agumail_api_request(
		'POST',
		'/api/v1/connect',
		agumail_connect_with_currency(
			array(
				'platform' => 'woocommerce',
				'storeUrl' => agumail_connect_store_url(),
			),
			agumail_connect_store_currency()
		)
	);
	if ( agumail_is_store_sync_refusal( $response ) ) {
		$platform = agumail_connect_with_key_only();
		if ( ! is_wp_error( $platform ) ) {
			update_option(
				AGUMAIL_STORE_CHECK_OPTION,
				array(
					'available' => false,
					'at'        => agumail_time(),
				),
				false
			);
		}
		return $platform;
	}
	if ( is_wp_error( $response ) ) {
		return agumail_connect_unreachable( $response );
	}
	if ( 200 !== $response['code'] || ! is_array( $response['body'] ) || empty( $response['body']['ok'] ) ) {
		return agumail_connect_refusal( $response );
	}
	agumail_connect_save_connection( $response['body'], 'woocommerce' );
	return 'woocommerce';
}

/**
 * Checks the saved key with ping, which AguMail answers on every plan, and
 * connects the site as `wordpress`: forms, signups, registrations and tracking,
 * and no store. Returns AGUMAIL_PLATFORM_WORDPRESS, or a WP_Error.
 */
function agumail_connect_with_key_only() {
	$response = agumail_api_request( 'GET', '/api/v1/ping' );
	if ( is_wp_error( $response ) ) {
		return agumail_connect_unreachable( $response );
	}
	if ( 200 !== $response['code'] || ! is_array( $response['body'] ) || empty( $response['body']['ok'] ) ) {
		return agumail_connect_refusal( $response );
	}
	agumail_connect_save_connection( $response['body'], AGUMAIL_PLATFORM_WORDPRESS );
	return AGUMAIL_PLATFORM_WORDPRESS;
}

/**
 * Admin action: a store connected for forms and signups starts syncing its
 * orders, carts and products, with the key it already has. AguMail takes it as
 * a store only on a plan with store sync; otherwise nothing changes.
 */
function agumail_connect_store() {
	if ( ! agumail_has_api_key() ) {
		return new WP_Error( 'agumail_not_connected', __( 'Connect to AguMail first.', 'agumail' ) );
	}
	if ( ! agumail_woocommerce_active() ) {
		return new WP_Error( 'agumail_no_woocommerce', __( 'Store sync needs WooCommerce. Activate it, then try again.', 'agumail' ) );
	}
	$response = agumail_api_request(
		'POST',
		'/api/v1/connect',
		agumail_connect_with_currency(
			array(
				'platform' => 'woocommerce',
				'storeUrl' => agumail_connect_store_url(),
			),
			agumail_connect_store_currency()
		)
	);
	if ( agumail_is_store_sync_refusal( $response ) ) {
		update_option(
			AGUMAIL_STORE_CHECK_OPTION,
			array(
				'available' => false,
				'at'        => agumail_time(),
			),
			false
		);
		return array(
			'type'    => 'warning',
			'message' => __( 'Your AguMail plan does not include store sync yet. Orders, carts and products sync on the Commerce plans; your forms and signups keep working meanwhile.', 'agumail' ),
		);
	}
	if ( is_wp_error( $response ) ) {
		return agumail_connect_unreachable( $response );
	}
	if ( 200 !== $response['code'] || ! is_array( $response['body'] ) || empty( $response['body']['ok'] ) ) {
		return agumail_connect_refusal( $response );
	}
	agumail_connect_save_connection( $response['body'], 'woocommerce' );
	agumail_log( 'info', 'The store now syncs its orders, carts and products with AguMail.' );
	return array(
		'type'    => 'success',
		'message' => __( 'Your store is connected. Orders, carts and products sync from now on.', 'agumail' ),
	);
}

/** Admin action: asks AguMail again for the config, and whether the plan has store sync. */
function agumail_connect_check() {
	if ( ! agumail_has_api_key() ) {
		return new WP_Error( 'agumail_not_connected', __( 'Connect to AguMail first.', 'agumail' ) );
	}
	$ping = agumail_connect_ping();
	if ( is_wp_error( $ping ) ) {
		return $ping;
	}
	agumail_connect_check_store_plan();
	return __( 'Checked with AguMail.', 'agumail' );
}

/**
 * Https, or plain http on this machine only (a development server).
 *
 * @param string $url Address to check.
 */
function agumail_connect_valid_url( $url ) {
	if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return false;
	}
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return false;
	}
	return 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) );
}

/**
 * Puts an option back as it was, deleting it when it was not set.
 *
 * @param string $name     Option name.
 * @param mixed  $previous The previous value, or false when the option was not set.
 */
function agumail_connect_restore_option( $name, $previous ) {
	if ( false === $previous ) {
		delete_option( $name );
	} else {
		update_option( $name, $previous );
	}
}

/** Admin action: forget the key and config on this site. The store stays in the AguMail account. */
function agumail_connect_disconnect() {
	$account = agumail_connection()['userId'];
	if ( '' !== $account ) {
		update_option( 'agumail_last_account', $account, false );
	}
	foreach ( array(
		'agumail_api_key',
		'agumail_config',
		'agumail_connection',
		'agumail_connected_at',
		'agumail_forms_cache',
		AGUMAIL_STORE_HOLD_OPTION,
		AGUMAIL_STORE_CHECK_OPTION,
		'agumail_store_notice',
	) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'agumail_ping_retry' );
	delete_transient( 'agumail_offer_sync_' . get_current_user_id() );
	agumail_log( 'info', 'Disconnected from AguMail on this site.' );
	return __( 'This site is no longer connected to AguMail. The store stays in your AguMail account until you remove it there.', 'agumail' );
}

// ─────────────────────────────────────────────────────────────────────────────
// Config refresh through ping.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Housekeeping: refreshes the config and checks the store's plan.
 */
function agumail_connect_housekeeping() {
	if ( agumail_has_api_key() ) {
		agumail_connect_ping();
		agumail_connect_check_store_plan();
	}
}

/**
 * A store connected for forms and signups learns when its plan gains store
 * sync, so the settings page can offer Sync your store. A held store is
 * checked by the drain instead (agumail_store_sync_recheck()).
 *
 * @param int $timeout Seconds to wait for AguMail, or 0 for the default.
 */
function agumail_connect_check_store_plan( $timeout = 0 ) {
	if ( ! agumail_has_api_key() || ! agumail_woocommerce_active() || AGUMAIL_PLATFORM_WORDPRESS !== agumail_connection_platform() ) {
		return null;
	}
	return agumail_store_sync_check( $timeout );
}

/** The settings page checks in when the last check is over an hour old, waiting a few seconds at most. */
function agumail_connect_refresh_if_stale() {
	if ( ! agumail_has_api_key() || get_transient( 'agumail_ping_retry' ) ) {
		return;
	}
	if ( time() - (int) agumail_connection()['checkedAt'] <= AGUMAIL_PING_STALE ) {
		return;
	}
	agumail_run_in_background( 'agumail_connect_ping', array( AGUMAIL_PING_PAGE_TIMEOUT ) );
	agumail_run_in_background( 'agumail_connect_check_store_plan', array( AGUMAIL_PING_PAGE_TIMEOUT ) );
}

/**
 * Asks AguMail how this store stands and for its current config. A refused key
 * or plan is recorded like the drain records one; a network failure changes
 * nothing, and the settings page waits a while before asking again.
 *
 * @param int $timeout Seconds to wait for AguMail, or 0 for the default.
 */
function agumail_connect_ping( $timeout = 0 ) {
	$options = $timeout > 0 ? array( 'timeout' => (int) $timeout ) : array();
	// A site connected by key has no store in AguMail to ask about.
	$path     = AGUMAIL_PLATFORM_WORDPRESS === agumail_connection_platform() ? '/api/v1/ping' : '/api/v1/ping?platform=woocommerce';
	$response = agumail_api_request( 'GET', $path, null, $options );
	if ( is_wp_error( $response ) ) {
		set_transient( 'agumail_ping_retry', 1, AGUMAIL_PING_RETRY );
		return $response;
	}
	$code = (int) $response['code'];
	if ( in_array( $code, array( 401, 402, 403 ), true ) ) {
		$error = agumail_connect_refusal( $response );
		agumail_record_connection_error( $code, $error->get_error_message() );
		return $error;
	}
	if ( 200 !== $code || ! is_array( $response['body'] ) || empty( $response['body']['ok'] ) ) {
		set_transient( 'agumail_ping_retry', 1, AGUMAIL_PING_RETRY );
		return agumail_connect_refusal( $response );
	}
	agumail_connect_apply_ping( $response['body'] );
	return true;
}

/**
 * An integration of null, or one AguMail marks disconnected, pauses the store;
 * any other integration means it is connected. No integration key at all says
 * nothing either way, so the status stands, except that a refusal no longer
 * does: this call went through. A site connected by key has no integration
 * in AguMail, so for it the key answering is all that counts.
 *
 * @param array $body Ping's answer.
 */
function agumail_connect_apply_ping( array $body ) {
	$before      = agumail_connection()['status'];
	$integration = null;
	if ( AGUMAIL_PLATFORM_WORDPRESS === agumail_connection_platform() ) {
		$status = 'connected';
	} elseif ( array_key_exists( 'integration', $body ) ) {
		$integration  = is_array( $body['integration'] ) ? $body['integration'] : null;
		$disconnected = null === $integration || ( isset( $integration['status'] ) && 'disconnected' === $integration['status'] );
		$status       = $disconnected ? 'disconnected' : 'connected';
	} else {
		$status = in_array( $before, array( 'error', '' ), true ) ? 'connected' : $before;
	}
	$changes = array(
		'status'    => $status,
		'checkedAt' => time(),
		'error'     => '',
		'errorCode' => 0,
	);
	$shop    = isset( $body['shop'] ) && is_array( $body['shop'] ) ? $body['shop'] : array();
	foreach ( array( 'shopName', 'userId' ) as $field ) {
		if ( isset( $shop[ $field ] ) && is_scalar( $shop[ $field ] ) ) {
			$changes[ $field ] = sanitize_text_field( (string) $shop[ $field ] );
		}
	}
	if ( isset( $changes['userId'] ) ) {
		agumail_connect_forget_other_account( $changes['userId'] );
	}
	$store = agumail_connect_string( $integration, 'storeUrl' );
	if ( '' !== $store ) {
		$changes['storeUrl'] = $store;
	}
	agumail_update_connection( $changes );
	if ( isset( $body['config'] ) && is_array( $body['config'] ) ) {
		update_option( 'agumail_config', agumail_connect_clean_config( $body['config'] ) );
	}
	delete_transient( 'agumail_ping_retry' );
	if ( $before !== $status && in_array( $status, array( 'connected', 'disconnected' ), true ) ) {
		agumail_log(
			'disconnected' === $status ? 'warning' : 'info',
			'disconnected' === $status
			? 'AguMail reports this store as disconnected. Sending is paused until it is reconnected.'
			: 'AguMail reports this store as connected.'
		);
	}
}

/**
 * A store AguMail reported disconnected, or a copy of the store on another
 * address, sends nothing until it is reconnected.
 *
 * @param bool $paused Whether sending is already paused.
 */
function agumail_connect_drain_paused( $paused ) {
	return $paused || 'disconnected' === agumail_connection()['status'] || null !== agumail_connect_address_change();
}

// ─────────────────────────────────────────────────────────────────────────────
// The Connection section.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Renders the Connection section.
 */
function agumail_connect_render_section() {
	if ( ! agumail_has_api_key() ) {
		agumail_connect_render_welcome();
	} else {
		agumail_connect_render_connected( agumail_connection() );
	}
	agumail_connect_render_advanced();
}

/**
 * A button that runs an admin action. $style is primary, secondary, or delete (a red text button, for undoing something).
 *
 * @param string $action  Admin action to run.
 * @param string $label   Button text.
 * @param string $style   Button style.
 * @param string $confirm Question to confirm first, or '' for none.
 */
function agumail_connect_button( $action, $label, $style = 'primary', $confirm = '' ) {
	$classes = array(
		'primary'   => 'button button-primary',
		'secondary' => 'button',
		'delete'    => 'button-link button-link-delete',
	);
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="agumail-inline-form"'
		. ( '' !== $confirm ? ' onsubmit="return window.confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
	agumail_admin_action_fields( $action );
	echo '<button type="submit" class="' . esc_attr( isset( $classes[ $style ] ) ? $classes[ $style ] : $classes['secondary'] ) . '">' . esc_html( $label ) . '</button>';
	echo '</form>';
}

/**
 * A link into AguMail's app, opening in a new tab. $style is primary, secondary or link.
 *
 * @param string $url   Link address.
 * @param string $label Link text.
 * @param string $style Link style.
 */
function agumail_connect_app_button( $url, $label, $style = 'secondary' ) {
	$classes = array(
		'primary'   => 'button button-primary',
		'secondary' => 'button',
		'link'      => '',
	);
	$class   = isset( $classes[ $style ] ) ? $classes[ $style ] : $classes['secondary'];
	echo '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label )
		. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'agumail' ) . '</span></a>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Not connected yet: what AguMail does here, a free account to start with, and
// the way to connect. A store connects in one click; a site without
// WooCommerce pastes a key, since AguMail's approval page connects stores.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * What the welcome panel lists, for a store or for a site without WooCommerce.
 */
function agumail_connect_welcome_points() {
	if ( agumail_woocommerce_active() ) {
		return array(
			__( 'Win back abandoned carts with reminders that link straight back to checkout.', 'agumail' ),
			__( 'Grow your list from checkout, signup forms, popups and the forms you already have.', 'agumail' ),
			__( 'See the revenue every campaign and flow brings in.', 'agumail' ),
		);
	}
	return array(
		__( 'Grow your list with signup forms, popups and the forms you already have: Elementor, Contact Form 7, WPForms and Gravity Forms.', 'agumail' ),
		__( 'Welcome new subscribers automatically, and send newsletters they look forward to.', 'agumail' ),
		__( 'Describe a flow in a sentence, or let AguMail suggest what to send next.', 'agumail' ),
	);
}

/**
 * Renders the panel for a site that is not connected yet.
 */
function agumail_connect_render_welcome() {
	echo '<div class="agumail-welcome">';
	echo '<p class="agumail-welcome-title">' . esc_html__( 'Email marketing made easy', 'agumail' ) . '</p>';
	echo '<ul class="agumail-welcome-points">';
	foreach ( agumail_connect_welcome_points() as $point ) {
		echo '<li>' . esc_html( $point ) . '</li>';
	}
	echo '</ul>';
	echo '<div class="agumail-actions">';
	agumail_connect_app_button( agumail_admin_signup_link( 'connect-welcome' ), __( 'Create your free AguMail account', 'agumail' ), 'primary' );
	echo '<span class="agumail-welcome-signin">' . esc_html__( 'Already have an account?', 'agumail' ) . ' ';
	agumail_connect_app_button( agumail_admin_app_link( '/login', 'connect-welcome' ), __( 'Sign in', 'agumail' ), 'link' );
	echo '</span></div>';
	echo '<p class="description">' . esc_html__( 'Start with a free 14-day trial of everything, no credit card needed. After it, the free plan carries on with no deadline.', 'agumail' ) . '</p>';
	echo '</div>';

	if ( agumail_woocommerce_active() ) {
		echo '<h3>' . esc_html__( 'Connect your store', 'agumail' ) . '</h3>';
		echo '<p>' . esc_html__( 'Approve the connection in AguMail and you come straight back here. Your orders, carts and products then sync in the background.', 'agumail' ) . '</p>';
		echo '<div class="agumail-actions">';
		agumail_connect_button( 'connect_start', __( 'Connect to AguMail', 'agumail' ) );
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'One-click connect syncs your store, which is on the Commerce plans and the trial. On another plan, connect with an API key under Advanced: your forms, signups and checkout subscribers still go to AguMail.', 'agumail' ) . '</p>';
		return;
	}
	agumail_connect_render_key_steps();
}

/** A site without WooCommerce: three steps, the key pasted right here. */
function agumail_connect_render_key_steps() {
	echo '<h3>' . esc_html__( 'Connect this site', 'agumail' ) . '</h3>';
	echo '<ol class="agumail-steps">';
	echo '<li><strong>' . esc_html__( 'Sign in to AguMail', 'agumail' ) . '</strong> ' . esc_html__( 'or create your free account above.', 'agumail' ) . '</li>';
	echo '<li><strong>' . esc_html__( 'Create an API key', 'agumail' ) . '</strong> ' . esc_html__( 'in AguMail under Settings > API keys.', 'agumail' ) . ' ';
	agumail_connect_app_button( agumail_admin_app_link( '/dashboard/settings#api-keys', 'connect-key' ), __( 'Open API keys', 'agumail' ), 'link' );
	echo '</li>';
	echo '<li><strong>' . esc_html__( 'Paste it here', 'agumail' ) . '</strong> ' . esc_html__( 'and connect.', 'agumail' );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="agumail-key-form">';
	agumail_admin_action_fields( 'connect_save_advanced' );
	echo '<label for="agumail_connect_key" class="screen-reader-text">' . esc_html__( 'API key', 'agumail' ) . '</label>';
	echo '<input type="password" name="agumail_api_key" id="agumail_connect_key" class="regular-text" value="" autocomplete="off" placeholder="agm_" /> ';
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Connect', 'agumail' ) . '</button>';
	echo '</form></li>';
	echo '</ol>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Connected.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Renders the connected account, its status and the actions for it.
 *
 * @param array $connection The saved connection.
 */
function agumail_connect_render_connected( array $connection ) {
	if ( get_transient( 'agumail_offer_sync_' . get_current_user_id() ) ) {
		delete_transient( 'agumail_offer_sync_' . get_current_user_id() );
		if ( agumail_store_sync_on() ) {
			agumail_connect_render_sync_offer();
		}
	}
	$status = $connection['status'];
	$site   = ! agumail_woocommerce_active() && AGUMAIL_PLATFORM_WORDPRESS === agumail_connection_platform();
	$store  = isset( $connection['storeUrl'] ) && is_string( $connection['storeUrl'] ) && '' !== $connection['storeUrl']
		? $connection['storeUrl']
		: (string) wp_parse_url( agumail_connect_store_url(), PHP_URL_HOST );
	$since  = (int) get_option( 'agumail_connected_at', 0 );

	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'Account', 'agumail' ) . '</th><td>' . esc_html( '' !== $connection['shopName'] ? $connection['shopName'] : __( 'Your AguMail account', 'agumail' ) ) . '</td></tr>';
	echo '<tr><th scope="row">' . esc_html( $site ? __( 'Site', 'agumail' ) : __( 'Store', 'agumail' ) ) . '</th><td>' . esc_html( $store ) . '</td></tr>';
	$moved = agumail_connect_address_change();
	echo '<tr><th scope="row">' . esc_html__( 'Status', 'agumail' ) . '</th><td>';
	if ( $moved ) {
		echo '<div class="agumail-problem is-warning"><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'warning' ) ) . ' </span><strong>' . esc_html(
			sprintf(
				$site
				/* translators: 1: the address the site connected from, 2: this site's address */
				? __( 'This site\'s address changed from %1$s to %2$s. Connect again if this is the live site.', 'agumail' )
				/* translators: 1: the address the store connected from, 2: this site's address */
				: __( 'This site\'s address changed from %1$s to %2$s. Reconnect if this is the live store.', 'agumail' ),
				$moved['from'],
				$moved['to']
			)
		) . '</strong> ' . esc_html__( 'Sending is paused.', 'agumail' ) . '</div>';
	} elseif ( 'disconnected' === $status ) {
		echo '<div class="agumail-problem is-warning"><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'warning' ) ) . ' </span><strong>' . esc_html__( 'Disconnected in AguMail.', 'agumail' ) . '</strong> '
			. esc_html__( 'Sending is paused until you reconnect.', 'agumail' ) . '</div>';
	} elseif ( 'error' === $status ) {
		echo '<div class="agumail-problem is-error"><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'error' ) ) . ' </span><strong>' . esc_html( $site ? __( 'AguMail refused this site.', 'agumail' ) : __( 'AguMail refused this store.', 'agumail' ) ) . '</strong> ' . esc_html( $connection['error'] );
		if ( 401 === (int) $connection['errorCode'] ) {
			echo ' ' . esc_html__( 'Reconnect to continue.', 'agumail' );
		}
		echo '</div>';
	} else {
		echo '<span class="agumail-badge is-success"><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'status' ) ) . ' </span>' . esc_html__( 'Connected', 'agumail' ) . '</span>';
	}
	echo '</td></tr>';
	if ( $since > 0 ) {
		echo '<tr><th scope="row">' . esc_html__( 'Connected since', 'agumail' ) . '</th><td>' . esc_html( agumail_date( get_option( 'date_format', 'Y-m-d' ), $since ) ) . '</td></tr>';
	}
	echo '</tbody></table>';

	agumail_connect_render_store_state();

	$needs_reconnect = $moved || in_array( $status, array( 'disconnected', 'error' ), true );
	echo '<div class="agumail-actions">';
	if ( agumail_woocommerce_active() ) {
		agumail_connect_button( 'connect_start', __( 'Reconnect', 'agumail' ), $needs_reconnect ? 'primary' : 'secondary' );
	}
	agumail_connect_button(
		'connect_disconnect',
		__( 'Disconnect from this site', 'agumail' ),
		'delete',
		__( 'Disconnect this site from AguMail? Nothing more is sent until you connect again.', 'agumail' )
	);
	echo '</div>';
	if ( AGUMAIL_PLATFORM_WORDPRESS === agumail_connection_platform() ) {
		echo '<p class="description">' . esc_html__( 'Disconnecting here removes the key from this site only. To stop the key working anywhere, delete it in AguMail under Settings > API keys.', 'agumail' ) . '</p>';
		if ( ! agumail_woocommerce_active() && $needs_reconnect ) {
			echo '<p class="description">' . esc_html__( 'To reconnect, paste a working key under Advanced.', 'agumail' ) . '</p>';
		}
	} else {
		echo '<p class="description">' . esc_html__( 'Disconnecting here removes the key from this site only. The store stays in your AguMail account until you remove it there.', 'agumail' ) . '</p>';
	}
}

/**
 * Where the store's own data stands, when it is not simply syncing: a store
 * connected for forms and signups (with Sync your store once the plan allows),
 * store sync held by the plan, or a store connection while WooCommerce is off.
 */
function agumail_connect_render_store_state() {
	$platform = agumail_connection_platform();
	if ( ! agumail_woocommerce_active() ) {
		if ( 'woocommerce' === $platform ) {
			echo '<div class="agumail-problem is-warning agumail-callout"><p><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'warning' ) ) . ' </span><strong>' . esc_html__( 'WooCommerce is not active.', 'agumail' ) . '</strong> '
				. esc_html__( 'Your forms and signups keep going to AguMail. Orders and products wait here, and go once WooCommerce is back.', 'agumail' ) . '</p></div>';
		}
		return;
	}
	if ( AGUMAIL_PLATFORM_WORDPRESS === $platform ) {
		$check = agumail_store_sync_availability();
		echo '<div class="agumail-store-offer">';
		if ( $check && $check['available'] ) {
			echo '<p><strong>' . esc_html__( 'Your AguMail plan now includes store sync.', 'agumail' ) . '</strong> '
				. esc_html__( 'Sync your store to recover abandoned carts, see the revenue your emails bring in, and put your products in emails.', 'agumail' ) . '</p>';
			echo '<div class="agumail-actions">';
			agumail_connect_button( 'connect_store', __( 'Sync your store', 'agumail' ) );
			echo '</div>';
		} else {
			echo '<p><strong>' . esc_html__( 'Connected for forms and signups.', 'agumail' ) . '</strong> '
				. esc_html__( 'Your forms, popups, form plugin signups, registrations and checkout subscribers go to AguMail. Abandoned-cart reminders, revenue from your emails and products in emails need store sync, which is on the Commerce plans.', 'agumail' ) . '</p>';
			echo '<div class="agumail-actions">';
			agumail_connect_app_button( agumail_admin_app_link( '/pricing', 'connect-store-sync' ), __( 'See plans', 'agumail' ) );
			agumail_connect_button( 'connect_store', __( 'Sync your store', 'agumail' ), 'secondary' );
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'Once your plan includes store sync, press Sync your store. This page also checks once a day.', 'agumail' ) . '</p>';
		}
		echo '</div>';
		return;
	}
	if ( agumail_store_sync_held() ) {
		echo '<div class="agumail-problem is-warning agumail-callout"><p><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'warning' ) ) . ' </span><strong>' . esc_html__( 'Store sync is paused: your AguMail plan does not include it.', 'agumail' ) . '</strong> '
			. esc_html__( 'Forms, signups and checkout subscribers keep going to AguMail. Orders and products wait here and go within the hour after you move to a Commerce plan, with nothing to reconnect. Abandoned carts are not kept meanwhile.', 'agumail' ) . '</p>';
		echo '<div class="agumail-actions">';
		agumail_connect_app_button( agumail_admin_app_link( '/pricing', 'connect-store-held' ), __( 'See plans', 'agumail' ) );
		agumail_connect_button( 'connect_check', __( 'Check again', 'agumail' ), 'secondary' );
		echo '</div></div>';
	}
}

/** The bulk sync action a store just connected is offered, if that feature registered one. */
function agumail_connect_sync_action() {
	$action   = (string) apply_filters( 'agumail_sync_now_action', 'bulk_sync_start' );
	$registry = &agumail_admin_registry();
	return '' !== $action && isset( $registry['actions'][ $action ] ) ? $action : '';
}

/**
 * Renders the offer to sync store data, shown once after a store connects.
 */
function agumail_connect_render_sync_offer() {
	$action = agumail_connect_sync_action();
	echo '<div class="notice notice-success inline agumail-sync-offer">';
	if ( '' === $action ) {
		echo '<p><strong>' . esc_html__( 'Sync store data now', 'agumail' ) . '</strong></p>';
	}
	echo '<p>' . esc_html__( 'Bring your customers, products and past orders into AguMail. Past orders arrive as history and start no emails.', 'agumail' ) . '</p>';
	if ( '' !== $action ) {
		echo '<div class="agumail-actions">';
		agumail_connect_button( $action, __( 'Sync store data now', 'agumail' ) );
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Renders the Advanced panel: the API URL and a pasted key.
 */
function agumail_connect_render_advanced() {
	$has_key = agumail_has_api_key();
	echo '<details class="agumail-advanced"><summary>' . esc_html__( 'Advanced', 'agumail' ) . '</summary>';
	echo '<p>' . esc_html(
		agumail_woocommerce_active()
		? __( 'Connect with an API key instead, or point the plugin at another AguMail address.', 'agumail' )
		: __( 'Connect with another API key, or point the plugin at another AguMail address.', 'agumail' )
	) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	agumail_admin_action_fields( 'connect_save_advanced' );
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="agumail_api_url">' . esc_html__( 'API URL', 'agumail' ) . '</label></th><td>';
	if ( agumail_api_url_is_pinned() ) {
		echo '<code>' . esc_html( agumail_api_url() ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Set in wp-config.php.', 'agumail' ) . '</p>';
	} elseif ( ! current_user_can( AGUMAIL_API_URL_CAPABILITY ) ) {
		echo '<code>' . esc_html( agumail_api_url() ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Only an administrator can change this.', 'agumail' ) . '</p>';
	} else {
		echo '<input type="url" name="agumail_api_url" id="agumail_api_url" class="regular-text" value="' . esc_attr( agumail_api_url() ) . '" />';
	}
	echo '</td></tr>';
	echo '<tr><th scope="row"><label for="agumail_api_key">' . esc_html__( 'API key', 'agumail' ) . '</label></th><td>';
	echo '<input type="password" name="agumail_api_key" id="agumail_api_key" class="regular-text" value="" autocomplete="off" placeholder="'
		. esc_attr( $has_key ? __( 'Saved. Leave empty to keep it.', 'agumail' ) : 'agm_' ) . '" />';
	echo '<p class="description">' . esc_html__( 'Create a key in AguMail under Settings > API keys.', 'agumail' ) . '</p>';
	echo '</td></tr>';
	echo '</tbody></table>';
	echo '<div class="agumail-actions"><button type="submit" class="button">' . esc_html__( 'Save and connect', 'agumail' ) . '</button></div>';
	echo '</form></details>';
}
