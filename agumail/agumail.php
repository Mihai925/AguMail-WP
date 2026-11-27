<?php
/**
 * Plugin Name:          AguMail: Email Marketing Made Easy
 * Plugin URI:           https://agumail.com
 * Description:          Signup forms, popups and the forms you already have, sending to AguMail. On a WooCommerce store it also syncs customers, orders, carts and products, for abandoned-cart recovery and revenue attribution.
 * Version:              1.0.3
 * Author:               AguMail
 * License:              GPLv2 or later
 * Security Contact:     support@agumail.com
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          agumail
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_VERSION', '1.0.3' );
define( 'AGUMAIL_FILE', __FILE__ );
define( 'AGUMAIL_DIR', __DIR__ . '/' );

// ─────────────────────────────────────────────────────────────────────────────
// The plugin is a set of files, loaded in order: the foundation first (settings,
// log, store, installer, HTTP client, payload helpers, the drain, checkout
// consent, the admin shell), then the features built on it.
//
// It runs on any WordPress site. With WooCommerce active it is also a store's
// plugin (checkout, carts, orders, products); without it those files load but
// their hooks never fire, and agumail_woocommerce_active() keeps the rest of
// the plugin from offering them.
//
// One rule holds across all of them: a visitor's request never talks to
// AguMail. Front-end hooks only record what changed in the outbox; the drain,
// run by Action Scheduler (WooCommerce's, or the copy bundled in vendor/ on a
// site without it), is what sends it.
// ─────────────────────────────────────────────────────────────────────────────

foreach ( array(
	'settings',
	'db',
	'log',
	'store',
	'install',
	'http',
	'payloads',
	'drain',
	'consent',
	'admin',
	'orders',
	'products',
	'carts',
	'connect',
	'tracking',
	'forms',
	'customers',
	'signups',
	'integrations/elementor',
	'integrations/cf7',
	'integrations/wpforms',
	'bulk-sync',
	'status',
	'onboarding',
	'privacy',
) as $agumail_include ) {
	require_once AGUMAIL_DIR . 'includes/' . $agumail_include . '.php';
}
unset( $agumail_include );

// ─────────────────────────────────────────────────────────────────────────────
// WooCommerce features this plugin runs on. Orders are read and written through
// the order object, never as posts, so High-Performance Order Storage is fine;
// consent, attribution and carts are captured on the block checkout as well as
// the classic one.
// ─────────────────────────────────────────────────────────────────────────────

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, 'agumail_activate' );
register_deactivation_hook( __FILE__, 'agumail_deactivate' );
