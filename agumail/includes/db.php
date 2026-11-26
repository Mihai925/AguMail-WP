<?php
/**
 * Database reads, with the object cache in front.
 *
 * Every direct read the plugin makes goes through agumail_db_get_var(),
 * agumail_db_get_col(), agumail_db_get_row() and agumail_db_get_results(). A
 * result is kept in the object cache for the rest of the request, so a read
 * the same request makes again (a status shown twice on a page, a count read
 * by two sections) costs nothing.
 *
 * The cache group is non-persistent: results never outlive the request, so a
 * persistent object cache never serves another request a stale queue row.
 * Within the request, every write the plugin makes to its tables calls
 * agumail_db_changed(), which starts a new generation of keys, so nothing read
 * before a write is served after it. A read that failed (last_error set) is
 * never kept.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_DB_CACHE_GROUP', 'agumail_db' );

if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	wp_cache_add_non_persistent_groups( array( AGUMAIL_DB_CACHE_GROUP ) );
}

add_filter( 'query', 'agumail_db_watch_writes', 10, 1 );

/**
 * Starts a new generation of cached reads whenever the request writes to the
 * database, through this plugin or anything else, so a read is never served
 * from before a write. Every query passes here; only its first word is read.
 *
 * @param mixed $query The SQL $wpdb is about to run.
 * @return mixed The same SQL.
 */
function agumail_db_watch_writes( $query ) {
	if ( is_string( $query ) && preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', $query ) ) {
		agumail_db_changed();
	}
	return $query;
}

/**
 * Starts a new generation of cached reads: call after any write.
 */
function agumail_db_changed() {
	$generation = (int) wp_cache_get( 'generation', AGUMAIL_DB_CACHE_GROUP );
	wp_cache_set( 'generation', $generation + 1, AGUMAIL_DB_CACHE_GROUP );
}

/**
 * Runs a $wpdb read once per request and generation, keeping its result in
 * the object cache.
 *
 * @param string $method One of get_var, get_col, get_row or get_results.
 * @param string $query  The query, already built with $wpdb->prepare().
 * @param string $output For get_row and get_results: OBJECT, ARRAY_A or ARRAY_N.
 * @param bool   $fresh  True to read the database even when this request has
 *                       the answer, as a lock must: another request may have
 *                       changed it since. The fresh answer is kept.
 * @return mixed What $wpdb's method returns.
 */
function agumail_db_read( $method, $query, $output = OBJECT, $fresh = false ) {
	global $wpdb;
	$key    = (int) wp_cache_get( 'generation', AGUMAIL_DB_CACHE_GROUP ) . ':' . $method . ':' . $output . ':' . md5( (string) $query );
	$cached = $fresh ? false : wp_cache_get( $key, AGUMAIL_DB_CACHE_GROUP );
	if ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
		// A query clears last_error as it starts; a cached answer does the same.
		$wpdb->last_error = '';
		return $cached['value'];
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- callers pass $wpdb->prepare() output, or a query with no variable part; the result is cached above.
	switch ( $method ) {
		case 'get_var':
			$value = $wpdb->get_var( $query );
			break;
		case 'get_col':
			$value = $wpdb->get_col( $query );
			break;
		case 'get_row':
			$value = $wpdb->get_row( $query, $output );
			break;
		default:
			$value = $wpdb->get_results( $query, $output );
	}
	// phpcs:enable
	if ( '' === (string) $wpdb->last_error ) {
		wp_cache_set( $key, array( 'value' => $value ), AGUMAIL_DB_CACHE_GROUP );
	}
	return $value;
}

/**
 * Like $wpdb->get_var(), through the request's read cache.
 *
 * @param string $query The prepared query.
 * @param bool   $fresh True to skip the cache (see agumail_db_read()).
 * @return string|null
 */
function agumail_db_get_var( $query, $fresh = false ) {
	return agumail_db_read( 'get_var', $query, OBJECT, $fresh );
}

/**
 * Like $wpdb->get_col(), through the request's read cache.
 *
 * @param string $query The prepared query.
 * @return array
 */
function agumail_db_get_col( $query ) {
	return agumail_db_read( 'get_col', $query );
}

/**
 * Like $wpdb->get_row(), through the request's read cache.
 *
 * @param string $query  The prepared query.
 * @param string $output OBJECT, ARRAY_A or ARRAY_N.
 * @return array|object|null
 */
function agumail_db_get_row( $query, $output = OBJECT ) {
	return agumail_db_read( 'get_row', $query, $output );
}

/**
 * Like $wpdb->get_results(), through the request's read cache.
 *
 * @param string $query  The prepared query.
 * @param string $output OBJECT, ARRAY_A or ARRAY_N.
 * @return array|null
 */
function agumail_db_get_results( $query, $output = OBJECT ) {
	return agumail_db_read( 'get_results', $query, $output );
}

/**
 * Frees the memory the object cache holds for this request, between batches
 * of a long background job, when the object cache supports it.
 *
 * A persistent object cache (Redis, Memcached) that can drop its in-memory
 * copies does that, and keeps everything it has stored. The built-in cache is
 * in memory only, so emptying it is the same thing, as WordPress's own runtime
 * flush does for it. A persistent cache that cannot drop its copies alone is
 * left as it is: emptying it would throw away the whole site's cache.
 */
function agumail_release_cache_memory() {
	global $wp_object_cache;
	if ( ! wp_cache_supports( 'flush_runtime' ) ) {
		return;
	}
	if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'flush_runtime' ) ) {
		$wp_object_cache->flush_runtime();
	} elseif ( ! wp_using_ext_object_cache() ) {
		wp_cache_flush();
	}
}
