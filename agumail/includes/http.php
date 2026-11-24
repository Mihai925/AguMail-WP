<?php
/**
 * The HTTP client, and the only file that calls out.
 *
 * A shopper's request never talks to AguMail: not at checkout, not when a
 * payment gateway calls back, not when a theme posts to admin-ajax. The guard
 * enforces it. A call goes out only from inside the plugin's own background
 * work, meaning an Action Scheduler callback registered with
 * agumail_add_background_action() or an admin tool registered with
 * agumail_admin_register_action(), and only when the request itself is one a
 * shopper cannot make: cron, WP-CLI, a wp-admin page, or admin-ajax.php when
 * it is Action Scheduler's own queue runner or a store manager's call.
 * Anything else is refused and logged.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_HTTP_TIMEOUT', 15 );

// ─────────────────────────────────────────────────────────────────────────────
// Background context.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * How deep in background work this request is, after applying $change.
 *
 * @param int $change 1 on entering background work, -1 on leaving it, 0 to read.
 */
function agumail_background_depth( $change = 0 ) {
	static $depth = 0;
	$depth        = max( 0, $depth + (int) $change );
	return $depth;
}

/**
 * Whether the code running now is the plugin's own background work.
 */
function agumail_in_background() {
	return agumail_background_depth() > 0;
}

/**
 * Runs $callback as background work: an Action Scheduler job or an admin tool.
 *
 * @param callable $callback The work.
 * @param array    $args     Arguments passed to it.
 */
function agumail_run_in_background( $callback, array $args = array() ) {
	agumail_background_depth( 1 );
	try {
		return call_user_func_array( $callback, $args );
	} finally {
		agumail_background_depth( -1 );
	}
}

/**
 * Like add_action(), for an Action Scheduler hook whose callback may call AguMail.
 *
 * @param string   $hook          The hook's name.
 * @param callable $callback      Runs as background work.
 * @param int      $priority      As for add_action().
 * @param int      $accepted_args As for add_action().
 */
function agumail_add_background_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action(
		$hook,
		function () use ( $callback ) {
			return agumail_run_in_background( $callback, func_get_args() );
		},
		$priority,
		$accepted_args
	);
}

/**
 * Whether this request is WP-CLI.
 */
function agumail_is_cli() {
	return defined( 'WP_CLI' ) && WP_CLI;
}

/**
 * A request no shopper makes. is_admin() alone is not enough: it is true on
 * admin-ajax.php, which storefront themes post to, so an admin-ajax request
 * counts only as Action Scheduler's async queue runner or from a user who
 * manages the store.
 */
function agumail_is_background_request() {
	if ( wp_doing_cron() || agumail_is_cli() ) {
		return true;
	}
	if ( ! wp_doing_ajax() ) {
		return is_admin();
	}
	return doing_action( 'wp_ajax_as_async_request_queue_runner' )
		|| doing_action( 'wp_ajax_nopriv_as_async_request_queue_runner' )
		|| current_user_can( AGUMAIL_ADMIN_CAPABILITY );
}

/**
 * Whether a call to AguMail may go out now: background work on a request no shopper makes.
 */
function agumail_http_allowed() {
	return agumail_in_background() && agumail_is_background_request();
}

// ─────────────────────────────────────────────────────────────────────────────
// Calls.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Calls AguMail's API. $path may carry a query string; $body, when given, is
 * sent as JSON. $options: auth (default true; false sends no key, for
 * connect/exchange) and timeout (seconds, default 15; the drain waits 60).
 * Redirects are never followed.
 *
 * Returns ['code' => int, 'headers' => [lowercase name => value], 'body' =>
 * decoded JSON array or null] for any HTTP status, so callers branch on the
 * code. Returns a WP_Error when the guard refuses (agumail_not_background), no
 * key is set (agumail_not_connected), or the request never got an answer.
 *
 * @param string     $method  The HTTP method.
 * @param string     $path    The path under the API's address.
 * @param array|null $body    Data sent as JSON, or null.
 * @param array      $options Optional auth and timeout.
 */
function agumail_api_request( $method, $path, $body = null, array $options = array() ) {
	$method     = strtoupper( (string) $method );
	$shown_path = strtok( (string) $path, '?' );
	if ( ! agumail_http_allowed() ) {
		agumail_log( 'warning', sprintf( 'Refused %s %s: AguMail is only called from background tasks and admin tools.', $method, $shown_path ) );
		return new WP_Error( 'agumail_not_background', __( 'AguMail is only called from background tasks and admin tools.', 'agumail' ) );
	}
	$auth = ! isset( $options['auth'] ) || $options['auth'];
	$key  = agumail_api_key();
	if ( $auth && '' === $key ) {
		return new WP_Error( 'agumail_not_connected', __( 'This store is not connected to AguMail.', 'agumail' ) );
	}

	$args = array(
		'method'      => $method,
		'timeout'     => isset( $options['timeout'] ) ? (int) $options['timeout'] : AGUMAIL_HTTP_TIMEOUT,
		'redirection' => 0,
		'headers'     => array(
			'Accept'     => 'application/json',
			'User-Agent' => 'AguMail-WooCommerce/' . AGUMAIL_VERSION,
		),
	);
	if ( $auth ) {
		$args['headers']['Authorization'] = 'Bearer ' . $key;
	}
	if ( null !== $body ) {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new WP_Error( 'agumail_bad_body', __( 'The data could not be encoded as JSON.', 'agumail' ) );
		}
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = $json;
	}

	$started  = microtime( true );
	$response = wp_remote_request( agumail_api_url() . $path, $args );
	$took     = (int) round( ( microtime( true ) - $started ) * 1000 );

	if ( is_wp_error( $response ) ) {
		agumail_log( 'warning', sprintf( '%s %s failed after %d ms: %s', $method, $shown_path, $took, $response->get_error_message() ) );
		return $response;
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$result  = array(
		'code'    => $code,
		'headers' => agumail_http_headers( $response ),
		'body'    => is_array( $decoded ) ? $decoded : null,
	);
	if ( $code >= 200 && $code < 300 ) {
		agumail_log( 'debug', sprintf( '%s %s: %d in %d ms', $method, $shown_path, $code, $took ) );
	} else {
		$error = is_array( $decoded ) && isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : '';
		agumail_log( 'warning', sprintf( '%s %s: %d in %d ms%s', $method, $shown_path, $code, $took, '' !== $error ? ': ' . $error : '' ) );
	}
	return $result;
}

/**
 * Response headers as a plain array with lowercase names.
 *
 * The headers are read from the response as wp_remote_retrieve_headers()
 * reads them, and that function hands them on as they are. They are usually
 * a CaseInsensitiveDictionary from the Requests library, but a
 * pre_http_request or http_response filter may have left a plain array or
 * any other value there, so each shape is checked.
 *
 * @param array $response Response from wp_remote_request(), never a WP_Error.
 */
function agumail_http_headers( $response ) {
	$headers = isset( $response['headers'] ) ? $response['headers'] : array();
	if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
		$headers = $headers->getAll();
	} elseif ( $headers instanceof Traversable ) {
		$headers = iterator_to_array( $headers );
	}
	$plain = array();
	foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
		$plain[ strtolower( (string) $name ) ] = $value;
	}
	return $plain;
}
