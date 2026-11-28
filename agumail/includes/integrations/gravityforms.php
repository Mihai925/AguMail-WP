<?php
/**
 * Gravity Forms: an AguMail tab in each form's Settings, next to
 * Confirmations and Notifications.
 *
 * The tab switches AguMail on for the form and sets its tags, double opt-in
 * and, if the merchant likes, which field is the email, the name and the
 * phone ("Detect automatically" finds them by type and label, see
 * signups.php), and which field is the consent. A Consent field is found on
 * its own when it is optional: a required one is the terms box, ticked on
 * every entry. A plain checkbox is the consent only when the merchant picks
 * it, since it may be about anything. A Name field gives the first and last
 * name from its own parts.
 *
 * The tab is a Gravity Forms add-on (GFAddOn, in
 * class-agumail-gravity-forms-addon.php), the way Gravity Forms' own add-ons
 * add theirs, so Gravity Forms renders it, checks the nonce and capability,
 * and keeps the settings in the form's own meta ($form['agumail']).
 *
 * A submission is queued on gform_after_submission, after Gravity Forms has
 * saved the entry. Gravity Forms fires that hook for spam too (it only marks
 * the entry), so a spam entry is skipped here. Confirmations, redirects and
 * the rest of the submission are left as Gravity Forms runs them. With
 * tracking on, a form sent by Gravity Forms' own AJAX also says who signed up
 * in its answer, for the AguMail script (signups.php). A form posted to its
 * page, or through the older iframe, has no answer to add to.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGUMAIL_GRAVITYFORMS_SLUG', 'agumail' );

add_action( 'gform_loaded', 'agumail_gravityforms_load', 5, 0 );
add_action( 'gform_after_submission', 'agumail_gravityforms_submit', 10, 2 );
add_filter( 'gform_ajax_submission_result', 'agumail_gravityforms_ajax_result', 10, 1 );

/**
 * Registers the AguMail add-on, once Gravity Forms has loaded its add-on framework.
 */
function agumail_gravityforms_load() {
	if ( ! class_exists( 'GFForms' ) || ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}
	GFForms::include_addon_framework();
	if ( ! class_exists( 'GFAddOn' ) ) {
		return;
	}
	require_once AGUMAIL_DIR . 'includes/integrations/class-agumail-gravity-forms-addon.php';
	GFAddOn::register( 'AguMail_Gravity_Forms_Addon' );
}

/**
 * The form's settings, with the defaults for any not saved yet: enabled and
 * double_opt_in ('1' or not), tags, and field_email, field_name, field_phone
 * and field_consent (a field id, or '' to detect).
 *
 * @param mixed $form The form, an array from Gravity Forms; anything else has the defaults.
 */
function agumail_gravityforms_settings( $form ) {
	$saved = is_array( $form ) && isset( $form[ AGUMAIL_GRAVITYFORMS_SLUG ] ) && is_array( $form[ AGUMAIL_GRAVITYFORMS_SLUG ] ) ? $form[ AGUMAIL_GRAVITYFORMS_SLUG ] : array();
	return array_merge(
		array(
			'enabled'       => '',
			'tags'          => '',
			'double_opt_in' => '',
			'field_email'   => '',
			'field_name'    => '',
			'field_phone'   => '',
			'field_consent' => '',
		),
		$saved
	);
}

/**
 * One property of a form field. Gravity Forms hands fields over as GF_Field
 * objects, and older code or another plugin's filter may leave plain arrays.
 *
 * @param mixed  $field The field.
 * @param string $key   The property.
 */
function agumail_gravityforms_prop( $field, $key ) {
	if ( is_array( $field ) ) {
		return isset( $field[ $key ] ) ? $field[ $key ] : null;
	}
	return is_object( $field ) && isset( $field->$key ) ? $field->$key : null;
}

/**
 * What a field is, as Gravity Forms decides it: its input type when it has
 * one (a Post Custom Field that is an email box is an email), else its type.
 *
 * @param mixed $field The field.
 */
function agumail_gravityforms_input_type( $field ) {
	if ( is_object( $field ) && method_exists( $field, 'get_input_type' ) ) {
		return (string) $field->get_input_type();
	}
	$input = agumail_gravityforms_prop( $field, 'inputType' );
	return is_string( $input ) && '' !== $input ? $input : (string) agumail_gravityforms_prop( $field, 'type' );
}

/**
 * The form's fields that hold an answer: every field with an id.
 *
 * @param mixed $form The form.
 */
function agumail_gravityforms_fields( $form ) {
	$fields = array();
	foreach ( is_array( $form ) && isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array() as $field ) {
		$id = agumail_gravityforms_prop( $field, 'id' );
		if ( is_scalar( $id ) && '' !== (string) $id ) {
			$fields[] = $field;
		}
	}
	return $fields;
}

/**
 * A field's label as the merchant sees it in the form editor.
 *
 * @param mixed $field The field.
 */
function agumail_gravityforms_label( $field ) {
	foreach ( array( 'label', 'adminLabel' ) as $key ) {
		$label = agumail_gravityforms_prop( $field, $key );
		if ( is_scalar( $label ) && '' !== trim( (string) $label ) ) {
			return trim( wp_strip_all_tags( (string) $label ) );
		}
	}
	/* translators: %s: the field's id in the form. */
	return sprintf( __( 'Field %s', 'agumail' ), (string) agumail_gravityforms_prop( $field, 'id' ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// The tab.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The choices of a field select: first $first, then the form's fields of
 * $types, whole (a Name field, not its First part).
 *
 * @param mixed  $form  The form.
 * @param array  $types The input types the select lists.
 * @param string $first The first choice, which saves as ''.
 */
function agumail_gravityforms_field_choices( $form, array $types, $first ) {
	$choices = array(
		array(
			'label' => $first,
			'value' => '',
		),
	);
	foreach ( agumail_gravityforms_fields( $form ) as $field ) {
		if ( in_array( agumail_gravityforms_input_type( $field ), $types, true ) ) {
			$choices[] = array(
				'label' => agumail_gravityforms_label( $field ),
				'value' => (string) agumail_gravityforms_prop( $field, 'id' ),
			);
		}
	}
	return $choices;
}

/**
 * The tab's settings, as Gravity Forms' settings API takes them: one section.
 *
 * @param mixed $form The form being edited.
 */
function agumail_gravityforms_settings_fields( $form ) {
	$detect = __( 'Detect automatically', 'agumail' );
	$fields = array();
	if ( ! agumail_has_api_key() ) {
		$fields[] = array(
			'name' => 'not_connected',
			'type' => 'html',
			'html' => '<div class="alert warning" role="alert"><p>' . esc_html__( 'Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', 'agumail' ) . ' '
				. '<a href="' . esc_url( agumail_admin_page_url() ) . '">' . esc_html__( 'Open AguMail', 'agumail' ) . '</a></p></div>',
		);
	}
	$fields[] = array(
		'name'  => 'enabled',
		'type'  => 'toggle',
		'label' => __( 'Send signups to AguMail', 'agumail' ),
	);
	$fields[] = array(
		'name'        => 'tags',
		'type'        => 'text',
		'label'       => __( 'Tags', 'agumail' ),
		'description' => __( 'Separate tags with commas. New tags are created.', 'agumail' ),
	);
	$fields[] = array(
		'name'        => 'double_opt_in',
		'type'        => 'toggle',
		'label'       => __( 'Double opt-in', 'agumail' ),
		'description' => __( 'New subscribers confirm by email first.', 'agumail' ),
	);
	$selects  = array(
		'field_email'   => array( __( 'Email', 'agumail' ), array( 'email' ), '' ),
		'field_name'    => array( __( 'Name', 'agumail' ), array( 'name', 'text' ), __( 'Separate First Name and Last Name fields are found by their labels.', 'agumail' ) ),
		'field_phone'   => array( __( 'Phone', 'agumail' ), array( 'phone', 'text' ), '' ),
		'field_consent' => array( __( 'Consent', 'agumail' ), array( 'consent', 'checkbox' ), __( 'An optional Consent field is found automatically. A checkbox counts only when you pick it here. When there is one, visitors who leave it unticked are not sent.', 'agumail' ) ),
	);
	foreach ( $selects as $name => $select ) {
		$field = array(
			'name'    => $name,
			'type'    => 'select',
			'label'   => $select[0],
			'choices' => agumail_gravityforms_field_choices( $form, $select[1], $detect ),
		);
		if ( '' !== $select[2] ) {
			$field['description'] = $select[2];
		}
		$fields[] = $field;
	}
	return array(
		array(
			'title'       => __( 'AguMail', 'agumail' ),
			'description' => __( 'Send the people who submit this form to AguMail as subscribers.', 'agumail' ),
			'fields'      => $fields,
		),
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Submissions.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Queues a signup from an entry Gravity Forms saved, when the form has AguMail on and the entry is not spam.
 *
 * @param mixed $entry The entry, an array (checked before use).
 * @param mixed $form  The form, an array (checked before use).
 */
function agumail_gravityforms_submit( $entry, $form ) {
	try {
		if ( ! is_array( $entry ) || ! is_array( $form ) || ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) ) {
			return;
		}
		$settings = agumail_gravityforms_settings( $form );
		if ( ! agumail_signup_consent_ticked( $settings['enabled'] ) ) {
			return;
		}
		$signup = agumail_gravityforms_signup( $entry, $form, $settings );
		if ( $signup && agumail_signup_enqueue( 'gravityforms', $signup['form'], $signup['data'] ) ) {
			$identity = agumail_signup_identity( $signup['data']['email'] );
			if ( $identity ) {
				agumail_signup_response_identity( $identity );
			}
		}
	} catch ( Throwable $e ) {
		agumail_log( 'error', 'A Gravity Forms signup was not queued: ' . $e->getMessage() );
	}
}

/**
 * One answer in the entry as a trimmed string, or '' when there is none.
 *
 * @param array  $entry The entry.
 * @param string $id    The field or input id, like "3" or "1.3".
 */
function agumail_gravityforms_entry_value( array $entry, $id ) {
	return isset( $entry[ $id ] ) && is_scalar( $entry[ $id ] ) ? trim( (string) $entry[ $id ] ) : '';
}

/**
 * The inputs a Name field was answered in, by part: prefix, first, middle,
 * last, suffix. Gravity Forms numbers them .2, .3, .4, .6 and .8 whatever
 * the field shows. The simple format has one input and no parts.
 *
 * @param mixed $field The Name field.
 * @param array $entry The entry.
 */
function agumail_gravityforms_name_parts( $field, array $entry ) {
	if ( ! is_array( agumail_gravityforms_prop( $field, 'inputs' ) ) ) {
		return array();
	}
	$id    = (string) agumail_gravityforms_prop( $field, 'id' );
	$parts = array();
	foreach ( array(
		'first'  => '.3',
		'middle' => '.4',
		'last'   => '.6',
	) as $part => $input ) {
		$parts[ $part ] = agumail_gravityforms_entry_value( $entry, $id . $input );
	}
	return $parts;
}

/**
 * The words of the choices ticked in a Checkbox field, from the entry's
 * inputs (.1, .2, and so on: each holds its choice's value when ticked).
 *
 * @param mixed $field The Checkbox field.
 * @param array $entry The entry.
 */
function agumail_gravityforms_ticked_choices( $field, array $entry ) {
	$inputs  = agumail_gravityforms_prop( $field, 'inputs' );
	$choices = agumail_gravityforms_prop( $field, 'choices' );
	$ticked  = array();
	foreach ( is_array( $inputs ) ? $inputs : array() as $input ) {
		$value = agumail_gravityforms_entry_value( $entry, (string) agumail_gravityforms_prop( $input, 'id' ) );
		if ( '' === $value ) {
			continue;
		}
		// A choice's value is its words unless the merchant gave it a separate one.
		$words = $value;
		foreach ( is_array( $choices ) ? $choices : array() as $choice ) {
			if ( (string) agumail_gravityforms_prop( $choice, 'value' ) === $value && is_scalar( agumail_gravityforms_prop( $choice, 'text' ) ) ) {
				$words = trim( (string) agumail_gravityforms_prop( $choice, 'text' ) );
				break;
			}
		}
		$ticked[] = $words;
	}
	return $ticked;
}

/**
 * The signup in an entry, or null when there is none: no address, or a
 * consent box left unticked. The consent is the field the merchant picked,
 * else an optional Consent field. A required one is ticked on every entry,
 * so it is the terms box and says nothing about marketing; a plain checkbox
 * is never guessed.
 *
 * @param array $entry    The entry, answers by field or input id.
 * @param array $form     The form.
 * @param array $settings The form's settings, from agumail_gravityforms_settings().
 */
function agumail_gravityforms_signup( array $entry, array $form, array $settings ) {
	$types = array(
		'email'   => 'email',
		'phone'   => 'phone',
		'consent' => 'consent',
	);
	$list  = array();
	$by_id = array();
	foreach ( agumail_gravityforms_fields( $form ) as $field ) {
		$id           = (string) agumail_gravityforms_prop( $field, 'id' );
		$type         = agumail_gravityforms_input_type( $field );
		$by_id[ $id ] = $field;
		if ( 'name' === $type && is_array( agumail_gravityforms_prop( $field, 'inputs' ) ) ) {
			$value = implode( ' ', array_filter( agumail_gravityforms_name_parts( $field, $entry ), 'strlen' ) );
		} elseif ( 'consent' === $type ) {
			$value = agumail_gravityforms_entry_value( $entry, $id . '.1' );
		} elseif ( 'email' === $type ) {
			// With "Confirm email" on, the field has a second input; the address is kept under the field's own id.
			$value = agumail_gravityforms_entry_value( $entry, $id );
		} elseif ( is_array( agumail_gravityforms_prop( $field, 'inputs' ) ) ) {
			// An answer in several inputs (an address, a checkbox) is never a name or a phone.
			$value = '';
		} else {
			$value = agumail_gravityforms_entry_value( $entry, $id );
		}
		$list[] = array(
			'id'       => $id,
			'type'     => isset( $types[ $type ] ) ? $types[ $type ] : $type,
			'label'    => agumail_gravityforms_label( $field ),
			'value'    => $value,
			'required' => ! empty( agumail_gravityforms_prop( $field, 'isRequired' ) ),
		);
	}
	$chosen = array();
	foreach ( array(
		'email'     => 'field_email',
		'phone'     => 'field_phone',
		'consent'   => 'field_consent',
		'full_name' => 'field_name',
	) as $part => $setting ) {
		if ( is_scalar( $settings[ $setting ] ) && '' !== (string) $settings[ $setting ] ) {
			$chosen[ $part ] = (string) $settings[ $setting ];
		}
	}
	// A Name field is the name, whatever its label says.
	if ( ! isset( $chosen['full_name'] ) ) {
		foreach ( $list as $item ) {
			if ( 'name' === $item['type'] && '' !== $item['value'] ) {
				$chosen['full_name'] = $item['id'];
				break;
			}
		}
	}
	$parts = agumail_signup_detect_fields( $list, $chosen );
	if ( ! isset( $parts['email'] ) ) {
		return null;
	}
	$values = array();
	foreach ( $list as $item ) {
		$values[ $item['id'] ] = $item['value'];
	}
	$value = function ( $part ) use ( $parts, $values ) {
		return isset( $parts[ $part ] ) && is_scalar( $values[ $parts[ $part ] ] ) ? (string) $values[ $parts[ $part ] ] : '';
	};

	$consent_text = '';
	if ( isset( $parts['consent'] ) ) {
		$field = $by_id[ $parts['consent'] ];
		$id    = $parts['consent'];
		$type  = agumail_gravityforms_input_type( $field );
		if ( 'consent' === $type ) {
			$ticked = agumail_signup_consent_ticked( agumail_gravityforms_entry_value( $entry, $id . '.1' ) );
			// The words next to the box, as Gravity Forms kept them with the entry.
			$consent_text = agumail_gravityforms_entry_value( $entry, $id . '.2' );
			if ( '' === $consent_text && is_scalar( agumail_gravityforms_prop( $field, 'checkboxLabel' ) ) ) {
				$consent_text = trim( (string) agumail_gravityforms_prop( $field, 'checkboxLabel' ) );
			}
		} elseif ( is_array( agumail_gravityforms_prop( $field, 'inputs' ) ) ) {
			$words        = agumail_gravityforms_ticked_choices( $field, $entry );
			$ticked       = (bool) $words;
			$consent_text = implode( ', ', $words );
		} else {
			$consent_text = agumail_gravityforms_entry_value( $entry, $id );
			$ticked       = agumail_signup_consent_ticked( $consent_text );
		}
		if ( ! $ticked ) {
			return null;
		}
	}

	$first = $value( 'first_name' );
	$last  = $value( 'last_name' );
	// A Name field in the first and last format has the two parts already.
	if ( '' === $first && '' === $last && isset( $parts['full_name'] ) && 'name' === agumail_gravityforms_input_type( $by_id[ $parts['full_name'] ] ) ) {
		$name  = agumail_gravityforms_name_parts( $by_id[ $parts['full_name'] ], $entry );
		$first = isset( $name['first'] ) ? $name['first'] : '';
		$last  = isset( $name['last'] ) ? $name['last'] : '';
	}
	$page = isset( $entry['source_url'] ) && is_string( $entry['source_url'] ) ? $entry['source_url'] : '';
	return array(
		'form' => array(
			'id'   => isset( $form['id'] ) && is_scalar( $form['id'] ) ? (string) $form['id'] : '',
			'name' => isset( $form['title'] ) && is_scalar( $form['title'] ) ? (string) $form['title'] : '',
		),
		'data' => array(
			'email'         => $value( 'email' ),
			'first_name'    => $first,
			'last_name'     => $last,
			'full_name'     => $value( 'full_name' ),
			'phone'         => $value( 'phone' ),
			'tags'          => is_scalar( $settings['tags'] ) ? (string) $settings['tags'] : '',
			'double_opt_in' => agumail_signup_consent_ticked( $settings['double_opt_in'] ),
			'consent_text'  => $consent_text,
			'page_url'      => $page,
		),
	);
}

/**
 * Who signed up, for the AguMail script, in the answer to an AJAX submission that queued one.
 *
 * @param mixed $result The answer Gravity Forms sends the browser, an array;
 *                      another plugin on the filter may have returned anything.
 */
function agumail_gravityforms_ajax_result( $result ) {
	$identity = agumail_signup_response_identity();
	if ( $identity && is_array( $result ) ) {
		$result['agumail'] = $identity;
	}
	return $result;
}
