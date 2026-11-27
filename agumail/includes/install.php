<?php
/**
 * Installing: the two tables and the Action Scheduler jobs.
 *
 * Tables are created on activation, and brought up to date (or back, if
 * missing) by maintenance from wp-admin, cron or WP-CLI. Jobs are scheduled
 * only from activation or that same maintenance, never from a shopper's
 * request, and every storefront hook stays a no-op until agumail_ready() says
 * the tables exist.
 *
 * Action Scheduler comes with WooCommerce. A site without WooCommerce runs the
 * copy in vendor/woocommerce/action-scheduler, loaded only when WooCommerce is
 * not active, so a store always runs WooCommerce's own. Both keep their jobs
 * in the same tables, so a site that switches WooCommerce on or off keeps its
 * jobs.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The schema version is the plugin's: after an update, maintenance runs dbDelta
// once, which changes nothing when the tables are already current.
define( 'AGUMAIL_DB_VERSION', AGUMAIL_VERSION );
define( 'AGUMAIL_GROUP', 'agumail' );
define( 'AGUMAIL_DRAIN_INTERVAL', 60 );

add_action( 'plugins_loaded', 'agumail_load_action_scheduler', -10, 0 );

/**
 * The bundled Action Scheduler, for a site without WooCommerce. It registers
 * itself for plugins_loaded, where the newest copy on the site wins, as with
 * any plugin that bundles it. Nothing loads in the request that activates the
 * plugin (plugins_loaded has run by then); agumail_maintain() schedules the
 * jobs on the next wp-admin page.
 */
function agumail_load_action_scheduler() {
	if ( class_exists( 'WooCommerce' ) || function_exists( 'as_enqueue_async_action' ) ) {
		return;
	}
	$file = AGUMAIL_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

/**
 * True once the tables exist, at any schema version: after an update the
 * plugin keeps working on the old tables until maintenance upgrades them. One
 * autoloaded option read.
 */
function agumail_ready() {
	return (int) get_option( 'agumail_db_version', 0 ) >= 1;
}

/**
 * True when the tables are at this version's schema or a newer one, so dbDelta
 * runs only when the schema changed (or the tables were never made).
 */
function agumail_schema_current() {
	return version_compare( (string) get_option( 'agumail_db_version', '0' ), AGUMAIL_DB_VERSION, '>=' );
}

/**
 * The SQL that creates the outbox and carts tables, in the format dbDelta reads.
 */
function agumail_schema_sql() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$outbox  = $wpdb->prefix . 'agumail_outbox';
	$carts   = $wpdb->prefix . 'agumail_carts';
	// dbDelta's format: one column per line, two spaces after PRIMARY KEY, KEY not INDEX.
	return array(
		"CREATE TABLE {$outbox} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,
  object_key VARCHAR(191) NOT NULL,
  payload LONGTEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  available_at DATETIME NOT NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_object_key (type,object_key),
  KEY status_available_at (status,available_at)
) {$charset};",
		"CREATE TABLE {$carts} (
  id VARCHAR(64) NOT NULL,
  token CHAR(32) NOT NULL,
  session_key VARCHAR(191) NOT NULL DEFAULT '',
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(254) NULL,
  `lines` LONGTEXT NOT NULL,
  total DECIMAL(18,4) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  queued_version INT UNSIGNED NOT NULL DEFAULT 0,
  dirty TINYINT(1) NOT NULL DEFAULT 1,
  order_id BIGINT UNSIGNED NULL,
  ip_hash CHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY status_updated_at (status,updated_at),
  KEY dirty_updated_at (dirty,updated_at),
  KEY email (email(191)),
  KEY session_key (session_key),
  KEY ip_hash_created_at (ip_hash,created_at)
) {$charset};",
	);
}

/** Creates or updates the tables; records the schema version only once both exist. */
function agumail_install() {
	global $wpdb;
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	dbDelta( agumail_schema_sql() );
	agumail_db_changed();
	foreach ( array( 'agumail_outbox', 'agumail_carts' ) as $table ) {
		$name  = $wpdb->prefix . $table;
		$found = agumail_db_get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		if ( $found !== $name ) {
			agumail_log( 'error', sprintf( 'Could not create the table %s: %s', $name, $wpdb->last_error ) );
			return false;
		}
	}
	update_option( 'agumail_db_version', AGUMAIL_DB_VERSION );
	return true;
}

/**
 * Activation. $network_wide is true when a multisite network activates the plugin on every site.
 *
 * @param bool $network_wide Whether the plugin is network-activated.
 */
function agumail_activate( $network_wide = false ) {
	agumail_install();
	agumail_schedule();
	// Without WooCommerce, Action Scheduler is not loaded yet in this request,
	// so the next maintenance (the very next wp-admin page) schedules the jobs.
	delete_transient( 'agumail_maintained' );
	// The merchant's next wp-admin page is the settings page (onboarding.php).
	if ( function_exists( 'agumail_onboarding_arm_redirect' ) ) {
		agumail_onboarding_arm_redirect( (bool) $network_wide );
	}
}

/** Deactivating stops every AguMail job; activating again schedules them afresh. */
function agumail_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) && ( ! class_exists( 'ActionScheduler' ) || ActionScheduler::is_initialized() ) ) {
		as_unschedule_all_actions( '', array(), AGUMAIL_GROUP );
	} else {
		agumail_delete_pending_jobs();
	}
	delete_option( 'agumail_drain_lock' );
	delete_transient( 'agumail_maintained' );
}

/**
 * When Action Scheduler is not loaded (WooCommerce inactive, and the bundled
 * copy not loaded in this request), its tables and AguMail's pending jobs are
 * still there, and would fail the day Action Scheduler runs again without this
 * plugin. They go straight from the tables.
 */
function agumail_delete_pending_jobs() {
	global $wpdb;
	foreach ( array( 'actionscheduler_actions', 'actionscheduler_groups' ) as $table ) {
		$name  = $wpdb->prefix . $table;
		$found = agumail_db_get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		if ( $found !== $name ) {
			return;
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler's own tables, while its API is not loaded.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE a FROM {$wpdb->prefix}actionscheduler_actions a INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s AND a.status = 'pending'",
			AGUMAIL_GROUP
		)
	);
}

/**
 * The recurring jobs: the drain every minute, housekeeping and the reverse
 * sync once a day. Each is scheduled once; calling this again changes nothing.
 */
function agumail_schedule() {
	if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return false;
	}
	$now  = time();
	$jobs = array(
		'agumail_drain'        => array( AGUMAIL_DRAIN_INTERVAL, $now + AGUMAIL_DRAIN_INTERVAL ),
		'agumail_housekeeping' => array( DAY_IN_SECONDS, $now + HOUR_IN_SECONDS ),
		'agumail_reverse_sync' => array( DAY_IN_SECONDS, $now + 2 * HOUR_IN_SECONDS ),
	);
	foreach ( $jobs as $hook => $job ) {
		if ( ! as_has_scheduled_action( $hook, array(), AGUMAIL_GROUP ) ) {
			as_schedule_recurring_action( $job[1], $job[0], $hook, array(), AGUMAIL_GROUP, true );
		}
	}
	return true;
}

/**
 * Keeps the tables at this version's schema and the jobs scheduled. Runs from
 * a merchant's wp-admin page (never admin-ajax, which a storefront theme may
 * call), from cron and from WP-CLI. An outdated schema is installed at once
 * (a failed attempt waits an hour); the jobs are checked at most once an hour.
 */
function agumail_maintain() {
	$merchant_page = is_admin() && ! wp_doing_ajax() && current_user_can( AGUMAIL_ADMIN_CAPABILITY );
	if ( ! $merchant_page && ! wp_doing_cron() && ! agumail_is_cli() ) {
		return;
	}
	if ( ! agumail_schema_current() && ! get_transient( 'agumail_install_failed' ) && ! agumail_install() ) {
		set_transient( 'agumail_install_failed', 1, HOUR_IN_SECONDS );
	}
	if ( ! agumail_ready() || get_transient( 'agumail_maintained' ) ) {
		return;
	}
	set_transient( 'agumail_maintained', 1, HOUR_IN_SECONDS );
	agumail_schedule();
	agumail_product_catch_up();
	// Every storefront request reads the key, so it must be autoloaded, whatever saved it.
	if ( function_exists( 'wp_set_option_autoload' ) && agumail_has_api_key() ) {
		wp_set_option_autoload( 'agumail_api_key', true );
	}
}

add_action( 'admin_init', 'agumail_maintain', 10, 0 );
add_action( 'init', 'agumail_maintain', 20, 0 );
