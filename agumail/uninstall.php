<?php
/**
 * Removes what the plugin stored when it is deleted from wp-admin: its two
 * tables, every agumail_* option and transient, the AguMail settings of each
 * Contact Form 7 form, and its scheduled actions, on every site of a network,
 * and the AguMail address each account was linked under. What shoppers
 * answered (the _agumail_optin user and order meta) stays; it belongs to the
 * store.
 *
 * @package AguMail
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$agumail_uninstall_site = function () {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'agumail_outbox' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'agumail_carts' ) );

	foreach ( array(
		'agumail_api_url',
		'agumail_app_url',
		'agumail_api_key',
		'agumail_consent_label',
		'agumail_config',
		'agumail_connection',
		'agumail_connected_at',
		'agumail_tracking_enabled',
		'agumail_registration_optin',
		'agumail_account_toggle',
		'agumail_popup',
		'agumail_forms_cache',
		'agumail_bulk_state',
		'agumail_backoff_until',
		'agumail_drain_lock',
		'agumail_db_version',
		'agumail_catalogue_version',
		'agumail_batch_sizes',
		'agumail_site_key',
		'agumail_site_src',
		'agumail_checkout_optin_default',
		'agumail_connect_notice_dismissed',
		'agumail_last_account',
		'agumail_last_sent',
		'agumail_reverse_sync_cursor',
		'agumail_store_notice',
	) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'agumail_activation_redirect', 'agumail_install_failed', 'agumail_maintained', 'agumail_ping_retry' ) as $transient ) {
		delete_transient( $transient );
	}

	// Anything else the plugin named agumail_*, and its transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by name pattern, which the options API cannot do; the cache is cleared below.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'agumail_' ) . '%',
			$wpdb->esc_like( '_transient_agumail_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_agumail_' ) . '%',
			$wpdb->esc_like( '_site_transient_agumail_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_agumail_' ) . '%'
		)
	);
	// The transients named per merchant or per connect attempt, by name: each
	// merchant's next notice, the store sync offer, the connect callback's owner.
	foreach ( array( 'agumail_notice_', 'agumail_offer_sync_', 'agumail_connect_owner_' ) as $agumail_transient_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients by name prefix, which the transients API cannot do.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_' . $agumail_transient_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $agumail_transient_prefix ) . '%'
			)
		);
	}
	wp_cache_delete( 'alloptions', 'options' );

	// Contact Form 7 keeps each form's AguMail settings as post meta on the form.
	delete_post_meta_by_key( '_agumail_signup' );

	if ( function_exists( 'as_unschedule_all_actions' ) && ( ! class_exists( 'ActionScheduler' ) || ActionScheduler::is_initialized() ) ) {
		as_unschedule_all_actions( '', array(), 'agumail' );
		return;
	}
	// Action Scheduler is not loaded (WooCommerce is inactive, and this plugin's
	// own copy does not load while it is being deleted): AguMail's jobs, and
	// their log lines, go straight from its tables.
	foreach ( array( 'actionscheduler_actions', 'actionscheduler_groups' ) as $table ) {
		// Whether Action Scheduler's table exists: the number of tables by that name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once, while the plugin is deleted; only the database knows.
		if ( ! $wpdb->query( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ) ) {
			return;
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler's own tables, while its API is not loaded.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE l FROM {$wpdb->prefix}actionscheduler_logs l INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s",
			'agumail'
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler's own tables, while its API is not loaded.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE a FROM {$wpdb->prefix}actionscheduler_actions a INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s",
			'agumail'
		)
	);
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $agumail_site_id ) {
		switch_to_blog( (int) $agumail_site_id );
		$agumail_uninstall_site();
		restore_current_blog();
	}
} else {
	$agumail_uninstall_site();
}

// Users are shared by every site. The address an account was linked under in
// AguMail would unsubscribe the wrong contact after connecting another account.
delete_metadata( 'user', 0, '_agumail_email', '', true );
