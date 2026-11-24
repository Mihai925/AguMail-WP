<?php
/**
 * Personal data: what the plugin keeps on a person, for Tools > Export
 * Personal Data and Tools > Erase Personal Data.
 *
 * On the site the plugin keeps, for an email address:
 * - its carts (the email, the lines, the total, and a hash of the IP address
 *   the cart was started from), for abandoned-cart reminders;
 * - on a customer account, the address AguMail holds for it (_agumail_email),
 *   and the marketing answer with when it was given (_agumail_optin,
 *   _agumail_optin_at).
 *
 * Erasing removes the carts and the held address. The marketing answer stays
 * as the record of the consent the store was given, as an order's own copy
 * does, and the eraser says so. The contact itself lives in the store's AguMail
 * account, where it is removed separately; the eraser says that too.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_PRIVACY_PAGE', 100 );

add_filter( 'wp_privacy_personal_data_exporters', 'agumail_privacy_register_exporter', 10, 1 );
add_filter( 'wp_privacy_personal_data_erasers', 'agumail_privacy_register_eraser', 10, 1 );
add_action( 'admin_init', 'agumail_privacy_policy_content', 10, 0 );

/**
 * Adds the AguMail exporter to Tools > Export Personal Data.
 *
 * @param mixed $exporters The exporters registered so far, by id.
 * @return array
 */
function agumail_privacy_register_exporter( $exporters ) {
	$exporters            = is_array( $exporters ) ? $exporters : array();
	$exporters['agumail'] = array(
		'exporter_friendly_name' => __( 'AguMail', 'agumail' ),
		'callback'               => 'agumail_privacy_export',
	);
	return $exporters;
}

/**
 * Adds the AguMail eraser to Tools > Erase Personal Data.
 *
 * @param mixed $erasers The erasers registered so far, by id.
 * @return array
 */
function agumail_privacy_register_eraser( $erasers ) {
	$erasers            = is_array( $erasers ) ? $erasers : array();
	$erasers['agumail'] = array(
		'eraser_friendly_name' => __( 'AguMail', 'agumail' ),
		'callback'             => 'agumail_privacy_erase',
	);
	return $erasers;
}

/**
 * Suggests the words for the site's privacy policy page.
 */
function agumail_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$text = '<p>' . esc_html__( 'This site sends the email address, name and marketing consent of people who sign up, register or buy to AguMail, its email marketing service, so it can email them. On a store it also sends orders, and the carts of shoppers who gave their email address, so it can remind them of a cart they left.', 'agumail' ) . '</p>'
		. '<p>' . esc_html__( 'A cookie keeps the campaign an AguMail email link came from for 30 days, so an order can be credited to it. With website tracking on, a cookie identifies the visitor to AguMail.', 'agumail' ) . '</p>';
	wp_add_privacy_policy_content( 'AguMail', wp_kses_post( $text ) );
}

/**
 * The carts kept for an email address, one page of them, newest first.
 *
 * @param string $email The address, as the request gave it.
 * @param int    $page  The page, from 1.
 * @return array[]
 */
function agumail_privacy_carts( $email, $page = 1 ) {
	$email = agumail_normalize_email( $email );
	$page  = max( 1, (int) $page );
	if ( '' === $email || ! agumail_ready() ) {
		return array();
	}
	$carts = agumail_store()->carts_by_email( $email, array(), $page * AGUMAIL_PRIVACY_PAGE );
	return array_slice( $carts, ( $page - 1 ) * AGUMAIL_PRIVACY_PAGE, AGUMAIL_PRIVACY_PAGE );
}

/**
 * Exports what the plugin keeps on an email address.
 *
 * @param string $email The address the request is for.
 * @param int    $page  The page WordPress asks for, from 1.
 * @return array
 */
function agumail_privacy_export( $email, $page = 1 ) {
	$items = array();
	$user  = get_user_by( 'email', (string) $email );
	if ( 1 === (int) $page && $user ) {
		$data = array();
		foreach ( array(
			'_agumail_email'    => __( 'Address held in AguMail', 'agumail' ),
			'_agumail_optin'    => __( 'Marketing answer', 'agumail' ),
			'_agumail_optin_at' => __( 'Marketing answer given at', 'agumail' ),
		) as $key => $name ) {
			$value = (string) get_user_meta( $user->ID, $key, true );
			if ( '' !== $value ) {
				$data[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}
		if ( $data ) {
			$items[] = array(
				'group_id'    => 'agumail',
				'group_label' => __( 'AguMail', 'agumail' ),
				'item_id'     => 'agumail-user-' . $user->ID,
				'data'        => $data,
			);
		}
	}
	$carts = agumail_privacy_carts( $email, $page );
	foreach ( $carts as $cart ) {
		$items[] = array(
			'group_id'    => 'agumail-carts',
			'group_label' => __( 'AguMail carts', 'agumail' ),
			'item_id'     => 'agumail-cart-' . $cart['id'],
			'data'        => array(
				array(
					'name'  => __( 'Status', 'agumail' ),
					'value' => (string) $cart['status'],
				),
				array(
					'name'  => __( 'Items', 'agumail' ),
					'value' => (string) count( $cart['lines'] ),
				),
				array(
					'name'  => __( 'Total', 'agumail' ),
					'value' => trim( $cart['total'] . ' ' . $cart['currency'] ),
				),
				array(
					'name'  => __( 'Last changed (UTC)', 'agumail' ),
					'value' => (string) $cart['updated_at'],
				),
			),
		);
	}
	return array(
		'data' => $items,
		'done' => count( $carts ) < AGUMAIL_PRIVACY_PAGE,
	);
}

/**
 * Erases what the plugin keeps on an email address: its carts, and the
 * address held for its account. The marketing answer is kept as the record of
 * consent, and the eraser says so.
 *
 * WordPress asks for page after page until one says it is done; each page
 * erases the next batch of carts, so no page number is needed.
 *
 * @param string $email The address the request is for.
 * @return array
 */
function agumail_privacy_erase( $email ) {
	$removed  = false;
	$retained = false;
	$messages = array();
	$carts    = agumail_privacy_carts( $email );
	$deleted  = 0;
	foreach ( $carts as $cart ) {
		if ( agumail_store()->cart_delete( $cart['id'] ) ) {
			++$deleted;
		}
	}
	$removed = $deleted > 0;
	// Done when this page held the last carts, or none could be deleted (a
	// database error): asking again would only find the same carts.
	$done = count( $carts ) < AGUMAIL_PRIVACY_PAGE || 0 === $deleted;
	if ( $deleted < count( $carts ) ) {
		$retained   = true;
		$messages[] = __( 'Some of the shopper\'s carts could not be deleted. Try again later.', 'agumail' );
	}
	$user = get_user_by( 'email', (string) $email );
	if ( $done && $user ) {
		if ( '' !== (string) get_user_meta( $user->ID, '_agumail_email', true ) ) {
			delete_user_meta( $user->ID, '_agumail_email' );
			$removed = true;
		}
		if ( '' !== (string) get_user_meta( $user->ID, '_agumail_optin', true ) ) {
			$retained   = true;
			$messages[] = __( 'AguMail kept the account\'s marketing answer, as the record of the consent the store was given.', 'agumail' );
		}
	}
	if ( $done && agumail_has_api_key() ) {
		$messages[] = __( 'The contact is kept in your AguMail account too. Remove it there to erase it from AguMail.', 'agumail' );
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => $retained,
		'messages'       => $messages,
		'done'           => $done,
	);
}
