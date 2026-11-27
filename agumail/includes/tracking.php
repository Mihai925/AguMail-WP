<?php
/**
 * Tracking: the on-site script and signed identify for signed-in customers.
 *
 * The script is AguMail's own (siteRuntime.ts): it records which pages, and on
 * a store which products, a recognized visitor looked at, so a flow can follow
 * up. It reads its
 * site key and endpoint off its own tag, and is added from the config AguMail
 * handed over at connect or ping; a site set up by hand under 1.x still has
 * its pasted key and script address, which are read as a fallback.
 *
 * A signed-in customer is named to the script with a signature:
 *   sig = base64url(HMAC-SHA256(identifySecret, lowercased trimmed email))
 * with identifySecret, the string from the config, used as the key exactly as
 * it is. The signature only shows that this store's own pages named the
 * address, so it is worth no more than the address: AguMail uses it to record
 * product views for a contact it already has (one not unsubscribed, bounced or
 * complained), and nothing else. It mints no visitor token, records only
 * catalogue fields, and never counts as an event that starts a flow.
 *
 * The runtime reads the address and signature off its own tag (data-email,
 * data-esig), so it knows the visitor before its first page view. An inline
 * identify stays as a fallback for a runtime that does not read them. Store
 * staff are not named unless the agumail_identify_staff filter says so, and
 * signing out forgets the visitor in this browser. Everything here reads
 * stored options only.
 *
 * On a product page the footer names the product to the script, from
 * WooCommerce itself, so the view is recorded on any theme and whatever an SEO
 * plugin says about the page. A product with options (a size, a colour) also
 * carries each option's price, picture and link, so the script can record the
 * one the shopper picks.
 *
 * Website tracking is off until the merchant switches it on. Once the site is
 * connected and its AguMail account has site tracking, a notice on the
 * Dashboard, the Plugins screen and the AguMail page says so and offers to
 * switch it on. It goes once the merchant has chosen: switched it on, saved
 * the setting either way, or pressed Keep it off.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_SITE_HANDLE', 'agumail-site' );

add_action( 'init', 'agumail_tracking_register_admin', 10, 0 );
add_action( 'wp_enqueue_scripts', 'agumail_tracking_enqueue', 10, 0 );
add_filter( 'script_loader_tag', 'agumail_tracking_script_tag', 10, 2 );
add_action( 'wp_logout', 'agumail_tracking_forget_visitor', 10, 0 );
add_action( 'admin_notices', 'agumail_tracking_notice', 10, 0 );
add_action( 'woocommerce_after_single_product', 'agumail_tracking_saw_product', 10, 0 );
add_action( 'wp_footer', 'agumail_tracking_print_product', 5, 0 );

// ─────────────────────────────────────────────────────────────────────────────
// Signed identify.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The address as AguMail signs and matches it: trimmed and lowercased.
 *
 * @param string $email The address.
 */
function agumail_identify_email( $email ) {
	$email = trim( (string) $email );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $email, 'UTF-8' ) : strtolower( $email );
}

/**
 * The signature AguMail accepts next to this address, or '' when the store has no identify secret.
 *
 * @param string $email The address.
 */
function agumail_identify_signature( $email ) {
	$secret = agumail_config( 'identifySecret' );
	if ( ! is_string( $secret ) || '' === $secret ) {
		return '';
	}
	$email = agumail_identify_email( $email );
	if ( '' === $email ) {
		return '';
	}
	return agumail_base64url( hash_hmac( 'sha256', $email, $secret, true ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// The script.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Where the script comes from and what it reports to: ['src', 'key', 'endpoint'],
 * or null when there is none. Both addresses must be on AguMail's own hosts,
 * whatever is stored: otherwise there is no script.
 */
function agumail_tracking_site() {
	$key      = agumail_config( 'siteKey' );
	$src      = agumail_config( 'siteScriptUrl' );
	$endpoint = agumail_config( 'siteEndpoint' );
	if ( ! is_string( $key ) || '' === $key || ! agumail_is_own_url( $src ) ) {
		// A 1.x site set up by hand.
		$key      = trim( (string) get_option( 'agumail_site_key', '' ) );
		$src      = trim( (string) get_option( 'agumail_site_src', '' ) );
		$endpoint = null;
		if ( '' === $key || ! agumail_is_own_url( $src ) ) {
			return null;
		}
	}
	if ( ! agumail_is_own_url( $endpoint ) ) {
		$endpoint = agumail_api_url() . '/api/public/site/' . rawurlencode( $key ) . '/events';
	}
	if ( ! agumail_is_own_url( $endpoint ) ) {
		return null;
	}
	return array(
		'src'      => $src,
		'key'      => $key,
		'endpoint' => $endpoint,
	);
}

/**
 * Adds the script to the front end, with the inline identify fallback, while tracking is on.
 */
function agumail_tracking_enqueue() {
	if ( is_admin() || ! agumail_tracking_on() ) {
		return;
	}
	$site = agumail_tracking_site();
	if ( ! $site ) {
		return;
	}
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- no version: the address is AguMail's and already carries its own.
	wp_enqueue_script( AGUMAIL_SITE_HANDLE, $site['src'], array(), null, false );
	$identify = agumail_tracking_identify_script();
	if ( '' !== $identify ) {
		wp_add_inline_script( AGUMAIL_SITE_HANDLE, $identify, 'after' );
	}
	// The script identifies the visitor with its own cookie, for marketing. With
	// a consent plugin its tag is printed inert (agumail_tracking_script_tag())
	// and this brings it to life once the visitor consents.
	if ( agumail_consent_api_active() ) {
		wp_register_script( AGUMAIL_SITE_HANDLE . '-consent', false, array(), AGUMAIL_VERSION, true );
		wp_enqueue_script( AGUMAIL_SITE_HANDLE . '-consent' );
		wp_add_inline_script( AGUMAIL_SITE_HANDLE . '-consent', agumail_tracking_consent_loader() );
	}
}

/**
 * The script that swaps the inert site script tag for a live one, with the
 * same attributes, once the visitor consents to marketing cookies.
 *
 * @return string
 */
function agumail_tracking_consent_loader() {
	$id = wp_json_encode( AGUMAIL_SITE_HANDLE . '-js' );
	return agumail_consent_js(
		'marketing',
		'var old=document.getElementById(' . $id . ');if(!old||!old.parentNode)return;'
		. 'var s=document.createElement("script");'
		. 'for(var i=0;i<old.attributes.length;i++){var a=old.attributes[i];if(a.name!=="type"&&a.name!=="data-agumail-consent"){s.setAttribute(a.name,a.value);}}'
		. 's.async=true;old.parentNode.replaceChild(s,old);'
	);
}

/**
 * The script reads its site key and endpoint, and who the visitor is, off its
 * own tag. It loads async so it never holds up the page, and Cloudflare's
 * Rocket Loader is told to leave it alone.
 *
 * @param string $tag    The script tag.
 * @param string $handle The script's handle.
 */
function agumail_tracking_script_tag( $tag, $handle ) {
	if ( AGUMAIL_SITE_HANDLE !== $handle || ! agumail_tracking_on() ) {
		return $tag;
	}
	$site = agumail_tracking_site();
	if ( ! $site ) {
		return $tag;
	}
	$attributes = array(
		'data-site'     => esc_attr( $site['key'] ),
		'data-endpoint' => esc_url( $site['endpoint'] ),
	);
	$identity   = agumail_tracking_identity();
	if ( $identity ) {
		$attributes['data-email'] = esc_attr( $identity['email'] );
		if ( '' !== $identity['sig'] ) {
			$attributes['data-esig'] = esc_attr( $identity['sig'] );
		}
	}
	$attributes['data-cfasync'] = 'false';
	if ( agumail_consent_api_active() ) {
		// Inert until agumail_tracking_consent_loader() finds consent.
		$tag                                = preg_replace( '/(<script\b[^>]*?)\stype=(["\'])[^"\']*\2/i', '$1', (string) $tag, 1 );
		$attributes['type']                 = 'text/plain';
		$attributes['data-agumail-consent'] = 'marketing';
	}
	return agumail_script_tag_attributes( $tag, $handle, $attributes, true );
}

/**
 * Adds already-escaped attributes to the tag that loads $handle's file.
 * WordPress passes that tag together with the inline scripts around it
 * (translations, before, after), whose text may itself hold "<script src=", so
 * the tag is found by the id WordPress gives it, {handle}-js, and only it is
 * changed. $async adds async unless it is there already.
 *
 * @param string $tag        The script tag, with any inline scripts around it.
 * @param string $handle     The script's handle.
 * @param array  $attributes Attribute names mapped to already-escaped values.
 * @param bool   $async      Whether to add async.
 */
function agumail_script_tag_attributes( $tag, $handle, array $attributes, $async = false ) {
	$tag = (string) $tag;
	$add = '';
	foreach ( $attributes as $name => $value ) {
		$add .= ' ' . $name . '="' . $value . '"';
	}
	$change  = function ( $matches ) use ( $add, $async ) {
		$existing = $matches[1];
		$extra    = $async && ! preg_match( '/\sasync(\s|=|$)/i', $existing ) ? ' async' : '';
		return '<script' . $add . $existing . $extra . '>';
	};
	$count   = 0;
	$id      = preg_quote( $handle . '-js', '/' );
	$changed = preg_replace_callback( '/<script\b([^>]*\sid=(["\'])' . $id . '\2[^>]*)>/i', $change, $tag, 1, $count );
	if ( $count > 0 ) {
		return $changed;
	}
	return preg_replace_callback( '/<script\b([^>]*\ssrc=[^>]*)>/i', $change, $tag, 1 );
}

/**
 * The signed-in customer to name to the script: ['email', 'sig'], or null.
 * Guests get nothing: no address is ever printed for a visitor who is not
 * signed in. Store staff are left out unless agumail_identify_staff says so.
 */
function agumail_tracking_identity() {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	$user = wp_get_current_user();
	if ( agumail_tracking_is_staff() && ! apply_filters( 'agumail_identify_staff', false, $user ) ) {
		return null;
	}
	$email = agumail_identify_email( $user->user_email );
	if ( '' === $email || ! is_email( $email ) ) {
		return null;
	}
	return array(
		'email' => $email,
		'sig'   => agumail_identify_signature( $email ),
	);
}

/** Store staff, as the foundation defines them (agumail_is_staff), else whoever manages WooCommerce. */
function agumail_tracking_is_staff() {
	return function_exists( 'agumail_is_staff' ) ? (bool) agumail_is_staff() : current_user_can( AGUMAIL_ADMIN_CAPABILITY );
}

/** The inline fallback for the signed-in customer, or '' when there is none. */
function agumail_tracking_identify_script() {
	$identity = agumail_tracking_identity();
	return $identity ? agumail_tracking_identify_js( $identity['email'], $identity['sig'] ) : '';
}

/**
 * Identifies once the script element itself has loaded, and only when the
 * runtime did not already read the tag (it marks its element
 * data-agumail-identified when it does), so the identify is not sent twice.
 * The address is JSON with <, >, &, ' and " escaped, so it cannot end the tag.
 *
 * @param string $email The address.
 * @param string $sig   Its signature, or ''.
 */
function agumail_tracking_identify_js( $email, $sig ) {
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	$args  = wp_json_encode( (string) $email, $flags ) . ( '' !== (string) $sig ? ',' . wp_json_encode( (string) $sig, $flags ) : '' );
	return '(function(){var s=document.getElementById("' . AGUMAIL_SITE_HANDLE . '-js");'
		. 'function go(){if(s&&s.hasAttribute("data-agumail-identified"))return;'
		. 'if(window.agumail&&window.agumail.identify){window.agumail.identify(' . $args . ');}}'
		. 'if(window.agumail){go();}else if(s){s.addEventListener("load",go);}})();';
}

// ─────────────────────────────────────────────────────────────────────────────
// The product on the page.
// ─────────────────────────────────────────────────────────────────────────────

/** The id of the product this page shows, once something has said so; 0 until then. */
function &agumail_tracking_viewed() {
	static $id = 0;
	return $id;
}

/**
 * The single-product template has run for the global product. On a product's
 * own page that is the product; it also runs for [product_page id=X] and the
 * Single Product block, which show a product on a page where is_product() is
 * false. The first product shown is the one the page is about.
 */
function agumail_tracking_saw_product() {
	global $product;
	$viewed = &agumail_tracking_viewed();
	if ( ! $viewed && $product instanceof WC_Product ) {
		$viewed = (int) $product->get_id();
	}
}

/**
 * Prints the product this page shows for the script, while tracking is on. The
 * script reads it at DOMContentLoaded, so the footer is soon enough, and by
 * then every template that could show a product has run.
 */
function agumail_tracking_print_product() {
	if ( is_admin() || ! agumail_tracking_on() || ! agumail_tracking_site() || ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	$id = agumail_tracking_viewed();
	if ( ! $id && function_exists( 'is_product' ) && is_product() ) {
		$id = (int) get_queried_object_id();
	}
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	echo agumail_tracking_product_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped in agumail_tracking_product_html().
}

/**
 * The hidden element the script reads the product from (siteRuntime.ts,
 * product()): its id, SKU, name, price and currency, and for a product with
 * options each option by id.
 *
 * @param WC_Product $product The product.
 */
function agumail_tracking_product_html( WC_Product $product ) {
	$sku        = agumail_clamp_sku( $product->get_sku() );
	$attributes = array(
		'data-agm-product'  => (string) $product->get_id(),
		'data-agm-sku'      => null !== $sku ? $sku : '',
		'data-agm-title'    => agumail_catalogue_text( $product->get_name(), 200 ),
		'data-agm-price'    => agumail_tracking_price( $product ),
		'data-agm-currency' => (string) agumail_currency( get_woocommerce_currency() ),
	);
	if ( $product->is_type( 'variable' ) ) {
		$attributes['data-agm-options'] = (string) wp_json_encode( (object) agumail_tracking_options( $product ) );
	}
	$html = '<span hidden';
	foreach ( $attributes as $name => $value ) {
		$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
	}
	return $html . "></span>\n";
}

/**
 * The price the catalogue holds for a product (agumail_product_record()), as
 * text: a product with options is at the lowest of theirs. '' when it has none.
 *
 * @param WC_Product $product The product.
 */
function agumail_tracking_price( WC_Product $product ) {
	$price = $product->get_price( 'edit' );
	return is_numeric( $price ) ? (string) agumail_money( $price ) : '';
}

/**
 * Each option of a product, by id, as the catalogue holds it
 * (agumail_product_options()): what the script sends when the shopper picks it.
 *
 * Past WooCommerce's own limit for printing options with the page
 * (woocommerce_ajax_variation_threshold) there are none: a product with
 * hundreds would weigh down every view of it, and the script reads an option
 * off WooCommerce's found_variation instead.
 *
 * @param WC_Product $product A product with options.
 */
function agumail_tracking_options( WC_Product $product ) {
	$count = count( (array) $product->get_children() );
	$limit = (int) apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own limit, read so ours matches it.
	if ( $limit > 0 && $count > $limit ) {
		return array();
	}
	$options = array();
	foreach ( agumail_product_options( $product, $count ) as $record ) {
		$options[ $record['externalId'] ] = array(
			'sku'      => isset( $record['sku'] ) ? $record['sku'] : '',
			'price'    => isset( $record['price'] ) ? (string) $record['price'] : '',
			'option'   => $record['title'],
			'imageUrl' => isset( $record['imageUrl'] ) ? $record['imageUrl'] : '',
			'url'      => isset( $record['productUrl'] ) ? $record['productUrl'] : '',
		);
	}
	return $options;
}

// ─────────────────────────────────────────────────────────────────────────────
// Signing out.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The runtime's cookies this browser sent, each with what expires it: the
 * path and SameSite the runtime sets them with, and no domain, as it sets
 * none (siteRuntime.ts writeCookie).
 */
function agumail_tracking_logout_cookies() {
	$cookies = array();
	foreach ( array( 'agm_e', 'agm_v' ) as $name ) {
		if ( isset( $_COOKIE[ $name ] ) ) {
			$cookies[ $name ] = array(
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'samesite' => 'Lax',
			);
		}
	}
	return $cookies;
}

/** Signing out forgets who this browser was: a response header each, no call and no write. */
function agumail_tracking_forget_visitor() {
	foreach ( agumail_tracking_logout_cookies() as $name => $options ) {
		unset( $_COOKIE[ $name ] );
		// Expires the cookie. Removing a cookie is functional, which the
		// WP Consent API always allows, so it goes whatever the visitor chose.
		agumail_setcookie( $name, '', $options, 'functional' );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// The setting.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Registers the Website tracking section and its save and keep-off actions.
 */
function agumail_tracking_register_admin() {
	agumail_admin_register_section(
		'tracking',
		__( 'Website tracking', 'agumail' ),
		'agumail_tracking_render_section',
		50,
		array(
			'icon'        => 'visibility',
			'description' => agumail_woocommerce_active()
				? __( 'Product views, so flows can follow up on them.', 'agumail' )
				: __( 'Page views, so flows can follow up on them.', 'agumail' ),
		)
	);
	agumail_admin_register_action( 'tracking_save', 'agumail_tracking_save', array( 'agumail_tracking_enabled' => 'text' ) );
	agumail_admin_register_action( 'tracking_keep_off', 'agumail_tracking_keep_off' );
}

/**
 * Prints the Website tracking section, in a .wrap with no margin of its own.
 */
function agumail_tracking_render_section() {
	echo '<div class="wrap agumail-section-wrap">';
	agumail_tracking_render_section_body();
	echo '</div>';
}

/**
 * The Website tracking section's content: the switch, and why no script is added yet when there is none.
 */
function agumail_tracking_render_section_body() {
	$on    = agumail_tracking_on();
	$store = agumail_woocommerce_active();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	agumail_admin_action_fields( 'tracking_save' );
	echo '<table class="form-table" role="presentation"><tbody><tr>';
	echo '<th scope="row">' . esc_html__( 'Website tracking', 'agumail' ) . '</th><td>';
	echo '<label><input type="checkbox" name="agumail_tracking_enabled" id="agumail_tracking_enabled" value="yes"' . checked( $on, true, false ) . ' /> '
		. esc_html( $store ? __( 'Add the AguMail script to your store', 'agumail' ) : __( 'Add the AguMail script to your site', 'agumail' ) ) . '</label>';
	echo '<p class="description">' . esc_html(
		$store
		? __( 'Lets flows follow up on the products people looked at. Views are recorded only for visitors AguMail recognizes, such as signed-in customers and people who arrived from your emails.', 'agumail' )
		: __( 'Lets flows follow up on the pages people read. Views are recorded only for visitors AguMail recognizes, such as signed-in members and people who arrived from your emails.', 'agumail' )
	) . '</p>';
	echo '</td></tr></tbody></table>';
	submit_button( __( 'Save', 'agumail' ) );
	echo '</form>';
	if ( agumail_tracking_site() ) {
		return;
	}
	if ( ! agumail_has_api_key() ) {
		echo '<p class="description">' . esc_html(
			$store
			? __( 'The script is added once the store is connected.', 'agumail' )
			: __( 'The script is added once the site is connected.', 'agumail' )
		) . '</p>';
		return;
	}
	// Connected by key, on an account that has not switched site tracking on in AguMail yet.
	echo '<div class="agumail-problem is-warning agumail-callout"><p><span class="screen-reader-text">' . esc_html( agumail_admin_state_word( 'warning' ) ) . ' </span>' . esc_html__( 'Your AguMail account has no site tracking yet. Switch it on in AguMail under Settings > Site tracking, then check again here.', 'agumail' ) . '</p>';
	echo '<div class="agumail-actions">';
	agumail_connect_app_button( agumail_admin_app_link( '/dashboard/settings#site-tracking', 'tracking' ), __( 'Open site tracking', 'agumail' ) );
	agumail_connect_button( 'connect_check', __( 'Check again', 'agumail' ), 'secondary' );
	echo '</div></div>';
}

/**
 * Admin action: saves whether website tracking is on.
 *
 * @param array $request The cleaned fields the action names.
 */
function agumail_tracking_save( array $request ) {
	$on = isset( $request['agumail_tracking_enabled'] ) && '' !== $request['agumail_tracking_enabled'] && 'no' !== $request['agumail_tracking_enabled'];
	update_option( 'agumail_tracking_enabled', $on ? 'yes' : 'no' );
	return array( 'message' => $on ? __( 'Website tracking is on.', 'agumail' ) : __( 'Website tracking is off.', 'agumail' ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// The notice. Its button is the setting's own save, so the merchant lands on
// the AguMail page with tracking on; Keep it off saves the setting as off and
// goes back to the page they were on. Both are admin actions, with the same
// nonce and capability checks as every other.
// ─────────────────────────────────────────────────────────────────────────────

/** Whether the notice belongs on the screen being drawn: a connected site that has not chosen yet, on the Dashboard, Plugins or AguMail page. */
function agumail_tracking_notice_due() {
	if ( ! current_user_can( AGUMAIL_ADMIN_CAPABILITY ) || '' !== get_option( 'agumail_tracking_enabled', '' )
		|| ! agumail_has_api_key() || ! agumail_tracking_site() ) {
		return false;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && ! empty( $screen->id ) && in_array( $screen->id, array( 'dashboard', 'plugins', agumail_admin_hook_suffix() ), true );
}

/**
 * Prints the notice offering to switch website tracking on, when it is due.
 */
function agumail_tracking_notice() {
	if ( ! agumail_tracking_notice_due() ) {
		return;
	}
	$text = agumail_woocommerce_active()
		? __( 'Switch it on so your flows can follow up on the products people looked at. Views are recorded only for visitors AguMail recognizes, such as signed-in customers and people who arrived from your emails.', 'agumail' )
		: __( 'Switch it on so your flows can follow up on the pages people read. Views are recorded only for visitors AguMail recognizes, such as signed-in members and people who arrived from your emails.', 'agumail' );
	echo '<div class="notice notice-info agumail-tracking-notice"><p><strong>' . esc_html__( 'AguMail website tracking is off.', 'agumail' ) . '</strong> ' . esc_html( $text ) . '</p><p>'
		. '<a class="button button-primary" href="' . esc_url( agumail_admin_action_url( 'tracking_save', array( 'agumail_tracking_enabled' => 'yes' ) ) ) . '">' . esc_html__( 'Turn on website tracking', 'agumail' ) . '</a> '
		. '<a class="agumail-tracking-notice-dismiss" href="' . esc_url( agumail_admin_action_url( 'tracking_keep_off' ) ) . '">' . esc_html__( 'Keep it off', 'agumail' ) . '</a>'
		. '</p></div>';
}

/** Admin action: tracking stays off, the notice goes, and the merchant goes back to the page they were on. */
function agumail_tracking_keep_off() {
	update_option( 'agumail_tracking_enabled', 'no' );
	$back = wp_get_referer();
	return array( 'redirect' => $back ? $back : admin_url() );
}
