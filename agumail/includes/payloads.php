<?php
/**
 * Building records for AguMail: the limits its API accepts, and the pieces
 * every builder shares (contacts, money, dates).
 *
 * A record that breaks a limit is refused whole, so every string and list is
 * clamped to what the server's schema allows before it leaves the store.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_MAX_BATCH', 500 );
define( 'AGUMAIL_MAX_BATCH_BYTES', 4 * 1024 * 1024 );
define( 'AGUMAIL_MAX_ITEMS', 100 );
define( 'AGUMAIL_MAX_NAME', 100 );
define( 'AGUMAIL_MAX_TITLE', 300 );
define( 'AGUMAIL_MAX_DESCRIPTION', 2000 );
define( 'AGUMAIL_MAX_URL', 800 );
define( 'AGUMAIL_MAX_SKU', 100 );
define( 'AGUMAIL_MAX_OPTIONS', 100 );
define( 'AGUMAIL_MAX_EXTERNAL_ID', 100 );
define( 'AGUMAIL_MAX_CART_ID', 120 );
define( 'AGUMAIL_MAX_CAMPAIGN_REF', 100 );
define( 'AGUMAIL_MAX_TAGS', 20 );
define( 'AGUMAIL_MAX_TAG', 60 );
define( 'AGUMAIL_MAX_CATEGORIES', 20 );
define( 'AGUMAIL_MAX_CATEGORY', 100 );
define( 'AGUMAIL_MAX_SOURCE', 60 );
define( 'AGUMAIL_MAX_DATE', 40 );

// ─────────────────────────────────────────────────────────────────────────────
// Clamps.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * A trimmed string AguMail's limit of $max takes. The server counts as
 * JavaScript does, in UTF-16 units, so a character outside the Basic
 * Multilingual Plane (most emoji) counts twice; the cut never splits one.
 *
 * @param mixed $value The value, read as a string.
 * @param int   $max   The most UTF-16 units kept.
 */
function agumail_clamp( $value, $max ) {
	$value = trim( wp_strip_all_tags( (string) $value ) );
	if ( strlen( $value ) <= $max ) {
		return $value;
	}
	// Text with no character outside the Basic Multilingual Plane (no emoji):
	// one UTF-16 unit per character, so the cut is a plain character count.
	if ( 0 === preg_match( '/[\x{10000}-\x{10FFFF}]/u', $value ) ) {
		return rtrim( mb_substr( $value, 0, $max, 'UTF-8' ) );
	}
	if ( ! preg_match_all( '/./su', $value, $characters ) ) {
		return rtrim( substr( $value, 0, $max ) );
	}
	$kept  = '';
	$units = 0;
	foreach ( $characters[0] as $character ) {
		$units += 4 === strlen( $character ) ? 2 : 1;
		if ( $units > $max ) {
			break;
		}
		$kept .= $character;
	}
	return rtrim( $kept );
}

/**
 * A URL AguMail will take, or null: a URL cut short would lead nowhere, so a
 * long one is dropped.
 *
 * @param string|false|null $url The URL.
 */
function agumail_clamp_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || strlen( $url ) > AGUMAIL_MAX_URL || ! preg_match( '#^https?://#i', $url ) ) {
		return null;
	}
	return $url;
}

/**
 * At most $max entries, each a clamped non-empty string, duplicates removed.
 *
 * @param array|string $values     The values; a single value is a list of one.
 * @param int          $max        The most entries kept.
 * @param int          $max_length The limit for each entry.
 */
function agumail_clamp_list( $values, $max, $max_length ) {
	$clean = array();
	foreach ( (array) $values as $value ) {
		$value = agumail_clamp( $value, $max_length );
		if ( '' !== $value && ! in_array( $value, $clean, true ) ) {
			$clean[] = $value;
		}
		if ( count( $clean ) >= $max ) {
			break;
		}
	}
	return $clean;
}

/**
 * At most AGUMAIL_MAX_ITEMS lines.
 *
 * @param array $items The lines.
 */
function agumail_clamp_items( array $items ) {
	return array_slice( array_values( $items ), 0, AGUMAIL_MAX_ITEMS );
}

/**
 * A SKU AguMail will take, or null when there is none.
 *
 * @param string $sku The SKU.
 */
function agumail_clamp_sku( $sku ) {
	$sku = agumail_clamp( $sku, AGUMAIL_MAX_SKU );
	return '' !== $sku ? $sku : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Numbers and dates.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An amount as a JSON number, to four places.
 *
 * @param float|int|string $amount The amount; anything not numeric is 0.
 */
function agumail_money( $amount ) {
	return is_numeric( $amount ) ? round( (float) $amount, 4 ) : 0.0;
}

/**
 * An amount that can never be negative (totals).
 *
 * @param float|int|string $amount The amount; anything not numeric is 0.
 */
function agumail_money_total( $amount ) {
	return max( 0.0, agumail_money( $amount ) );
}

/**
 * A quantity as a whole number, never negative; 0 when it is not numeric.
 *
 * @param float|int|string $qty The quantity.
 */
function agumail_quantity( $qty ) {
	return is_numeric( $qty ) ? max( 0, (int) round( (float) $qty ) ) : 0;
}

/**
 * ISO 8601 in UTC from a timestamp, a DateTime (WC_DateTime included) or a date
 * string; null when there is none.
 *
 * @param int|string|DateTimeInterface|null $value The date.
 */
function agumail_iso_date( $value ) {
	if ( $value instanceof DateTimeInterface ) {
		$timestamp = $value->getTimestamp();
	} elseif ( is_numeric( $value ) ) {
		$timestamp = (int) $value;
	} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
		$timestamp = strtotime( $value . ( preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $value ) ? '' : ' UTC' ) );
	} else {
		return null;
	}
	return $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null;
}

/**
 * The three-letter currency, uppercase, or null.
 *
 * @param string $code The currency code.
 */
function agumail_currency( $code ) {
	$code = strtoupper( trim( (string) $code ) );
	return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Contacts.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An email address trimmed and lowercased, or '' when it is not a valid one.
 *
 * @param string $email The address.
 */
function agumail_normalize_email( $email ) {
	$email = strtolower( trim( (string) $email ) );
	return is_email( $email ) ? $email : '';
}

/**
 * A contact record, or null when the address is not one. $args:
 *   first_name, last_name
 *   opted_in        true or false records the shopper's answer; null (the
 *                   default) sends no consent at all, as a captured email must
 *   source          checkout, wp_register, my_account, bulk_sync or capture
 *   unsubscribed    true when the shopper asked to stop
 *   customer_since  when they became a customer (timestamp, date or DateTime)
 *   consented_at    when they opted in
 *   tags            defaults to ['woocommerce']
 *
 * @param string $email The contact's email address.
 * @param array  $args  The fields above, all optional.
 */
function agumail_contact_payload( $email, array $args = array() ) {
	$email = agumail_normalize_email( $email );
	if ( '' === $email ) {
		return null;
	}
	$contact = array( 'email' => $email );
	foreach ( array(
		'first_name' => 'firstName',
		'last_name'  => 'lastName',
	) as $arg => $field ) {
		$value = isset( $args[ $arg ] ) ? agumail_clamp( $args[ $arg ], AGUMAIL_MAX_NAME ) : '';
		if ( '' !== $value ) {
			$contact[ $field ] = $value;
		}
	}
	$contact['tags'] = agumail_clamp_list( isset( $args['tags'] ) ? $args['tags'] : array( 'woocommerce' ), AGUMAIL_MAX_TAGS, AGUMAIL_MAX_TAG );
	if ( ! empty( $args['unsubscribed'] ) ) {
		$contact['unsubscribed'] = true;
	} elseif ( isset( $args['opted_in'] ) ) {
		$contact['consent'] = array( 'optedIn' => (bool) $args['opted_in'] );
		if ( ! empty( $args['source'] ) ) {
			$contact['consent']['source'] = agumail_clamp( $args['source'], AGUMAIL_MAX_SOURCE );
		}
	}
	$customer_since = isset( $args['customer_since'] ) ? agumail_iso_date( $args['customer_since'] ) : null;
	if ( $customer_since ) {
		$contact['customerSince'] = $customer_since;
	}
	$consented_at = isset( $args['consented_at'] ) ? agumail_iso_date( $args['consented_at'] ) : null;
	if ( $consented_at && isset( $contact['consent'] ) && $contact['consent']['optedIn'] ) {
		$contact['consentedAt'] = $consented_at;
	}
	return $contact;
}

/**
 * How strongly a record speaks for the contact: an unsubscribe (3) beats an
 * opt-in (2), which beats an explicit "no" (1), which beats a record with no
 * consent at all (0), such as a captured email.
 *
 * @param array $contact A contact record.
 */
function agumail_contact_rank( array $contact ) {
	if ( ! empty( $contact['unsubscribed'] ) ) {
		return 3;
	}
	if ( isset( $contact['consent']['optedIn'] ) ) {
		return $contact['consent']['optedIn'] ? 2 : 1;
	}
	return 0;
}

/**
 * Merges contact records by email, for one request. The strongest record's
 * consent stands, per agumail_contact_rank(); names fill in from whichever
 * record has them; tags are combined; customerSince keeps the earliest date.
 * Returns the merged records, in the order their emails first appeared.
 *
 * @param array $records Contact records.
 */
function agumail_merge_contacts( array $records ) {
	$merged = array();
	foreach ( $records as $record ) {
		if ( ! is_array( $record ) || empty( $record['email'] ) ) {
			continue;
		}
		$email = strtolower( trim( (string) $record['email'] ) );
		if ( ! isset( $merged[ $email ] ) ) {
			$merged[ $email ]          = $record;
			$merged[ $email ]['email'] = $email;
			continue;
		}
		$current                   = $merged[ $email ];
		$winner                    = agumail_contact_rank( $record ) > agumail_contact_rank( $current ) ? $record : $current;
		$other                     = $winner === $record ? $current : $record;
		$merged[ $email ]          = agumail_combine_contacts( $winner, $other );
		$merged[ $email ]['email'] = $email;
	}
	return array_values( $merged );
}

/**
 * A contact queued again before the drain sent the first one, or two records
 * for one contact given at different times. The newer record's answer stands
 * when it is an opt-in or an unsubscribe, or at least as strong as the older
 * one; a weaker one (an unticked box, a captured email) never replaces an
 * answer the shopper gave, and only fills the gaps.
 *
 * @param array $older The record queued first.
 * @param array $newer The record queued later.
 */
function agumail_merge_queued_contact( array $older, array $newer ) {
	$rank = agumail_contact_rank( $newer );
	if ( $rank >= 2 || $rank >= agumail_contact_rank( $older ) ) {
		return agumail_combine_contacts( $newer, $older );
	}
	return agumail_combine_contacts( $older, $newer );
}

/**
 * $winner's consent and values, with gaps filled from $other.
 *
 * @param array $winner The record whose consent stands.
 * @param array $other  The record that fills the gaps.
 */
function agumail_combine_contacts( array $winner, array $other ) {
	$combined = $winner;
	foreach ( array( 'firstName', 'lastName' ) as $field ) {
		if ( empty( $combined[ $field ] ) && ! empty( $other[ $field ] ) ) {
			$combined[ $field ] = $other[ $field ];
		}
	}
	$tags = array_merge( isset( $winner['tags'] ) ? (array) $winner['tags'] : array(), isset( $other['tags'] ) ? (array) $other['tags'] : array() );
	if ( $tags ) {
		$combined['tags'] = array_slice( array_values( array_unique( $tags ) ), 0, AGUMAIL_MAX_TAGS );
	}
	$since = array_filter(
		array(
			isset( $winner['customerSince'] ) ? $winner['customerSince'] : null,
			isset( $other['customerSince'] ) ? $other['customerSince'] : null,
		)
	);
	if ( $since ) {
		sort( $since );
		$combined['customerSince'] = $since[0];
	}
	return $combined;
}
