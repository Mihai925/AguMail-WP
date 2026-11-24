<?php
/**
 * WPForms (Lite and Pro): an AguMail section under the form builder's
 * Settings.
 *
 * The section switches AguMail on for the form and sets its tags, double
 * opt-in and, if the merchant likes, which field is the email, the name and
 * the phone ("Detect automatically" finds them by type and label, see
 * signups.php), and which checkbox is the consent. The consent is never
 * guessed: a GDPR box is always required, and a plain checkbox may be about
 * anything. WPForms keeps the settings in the form itself
 * (form_data['settings']['agumail_*']). The field selects are WPForms' own
 * field-map selects, so the builder keeps their lists up to date as fields
 * are added. A Name field gives the first and last name from its own parts.
 *
 * A submission is queued on wpforms_process_complete, which WPForms fires only
 * for a submission it accepted and did not mark as spam. With tracking on, the
 * answer to an AJAX form also says who signed up, for the AguMail script
 * (signups.php).
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wpforms_builder_settings_sections', 'agumail_wpforms_settings_section', 20, 1 );
add_action( 'wpforms_form_settings_panel_content', 'agumail_wpforms_settings_content', 20, 1 );
add_action( 'wpforms_process_complete', 'agumail_wpforms_submit', 10, 3 );
add_filter( 'wpforms_ajax_submit_success_response', 'agumail_wpforms_ajax_response', 10, 1 );

/**
 * Adds the AguMail section to the form builder's Settings.
 *
 * @param mixed $sections The builder's settings sections by slug, an array from
 *                        WPForms; another plugin on the filter may have
 *                        returned anything.
 */
function agumail_wpforms_settings_section( $sections ) {
	$sections            = is_array( $sections ) ? $sections : array();
	$sections['agumail'] = __( 'AguMail', 'agumail' );
	return $sections;
}

/**
 * Whether the form asks for double opt-in. A toggle switched off is simply missing from the saved form.
 *
 * @param array $settings The form's settings.
 */
function agumail_wpforms_double_opt_in( array $settings ) {
	return ! empty( $settings['agumail_double_opt_in'] );
}

/**
 * Prints the AguMail section of the form builder's Settings.
 *
 * @param mixed $panel The WPForms_Builder_Panel_Settings panel, as the action
 *                     hands it over (checked before use).
 */
function agumail_wpforms_settings_content( $panel ) {
	if ( ! function_exists( 'wpforms_panel_field' ) ) {
		return;
	}
	$form_data = is_object( $panel ) && isset( $panel->form_data ) && is_array( $panel->form_data ) ? $panel->form_data : array();
	$settings  = isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ? $form_data['settings'] : array();
	echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-agumail">';
	echo '<div class="wpforms-panel-content-section-title">' . esc_html__( 'AguMail', 'agumail' ) . '</div>';
	if ( ! agumail_has_api_key() ) {
		echo '<div class="wpforms-alert wpforms-alert-warning"><p>' . esc_html__( 'Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', 'agumail' ) . ' '
			. '<a href="' . esc_url( agumail_admin_page_url() ) . '">' . esc_html__( 'Open AguMail', 'agumail' ) . '</a></p></div>';
	}
	wpforms_panel_field( 'toggle', 'settings', 'agumail_enable', $form_data, esc_html__( 'Send signups to AguMail', 'agumail' ) );
	wpforms_panel_field(
		'text',
		'settings',
		'agumail_tags',
		$form_data,
		esc_html__( 'Tags', 'agumail' ),
		array(
			'after' => '<p class="note">' . esc_html__( 'Separate tags with commas. New tags are created.', 'agumail' ) . '</p>',
		)
	);
	wpforms_panel_field(
		'toggle',
		'settings',
		'agumail_double_opt_in',
		$form_data,
		esc_html__( 'Double opt-in', 'agumail' ),
		array(
			'value'   => agumail_wpforms_double_opt_in( $settings ) ? 1 : 0,
			'tooltip' => __( 'New subscribers confirm by email first.', 'agumail' ),
		)
	);
	$detect  = __( 'Detect automatically', 'agumail' );
	$selects = array(
		'agumail_field_email'   => array( __( 'Email', 'agumail' ), array( 'email' ), $detect ),
		'agumail_field_name'    => array( __( 'Name', 'agumail' ), array( 'name', 'text' ), $detect ),
		'agumail_field_phone'   => array( __( 'Phone', 'agumail' ), array( 'phone', 'text' ), $detect ),
		'agumail_field_consent' => array( __( 'Consent', 'agumail' ), array( 'gdpr-checkbox', 'checkbox' ), __( 'No consent box', 'agumail' ) ),
	);
	foreach ( $selects as $field => $select ) {
		wpforms_panel_field(
			'select',
			'settings',
			$field,
			$form_data,
			esc_html( $select[0] ),
			array(
				'field_map'   => $select[1],
				'placeholder' => $select[2],
			)
		);
	}
	echo '</div>';
}

/**
 * Queues a signup from a submission WPForms accepted, when the form has AguMail on.
 *
 * @param mixed $fields    The submitted fields as WPForms formats them, an array
 *                         (checked before use).
 * @param array $entry     The raw submission.
 * @param mixed $form_data The form, an array (checked before use).
 */
function agumail_wpforms_submit( $fields, $entry = array(), $form_data = array() ) {
	try {
		$settings = is_array( $form_data ) && isset( $form_data['settings'] ) && is_array( $form_data['settings'] ) ? $form_data['settings'] : array();
		if ( empty( $settings['agumail_enable'] ) || ! empty( $form_data['spam_reason'] ) ) {
			return;
		}
		$signup = agumail_wpforms_signup( is_array( $fields ) ? $fields : array(), $form_data );
		if ( $signup && agumail_signup_enqueue( 'wpforms', $signup['form'], $signup['data'] ) ) {
			$identity = agumail_signup_identity( $signup['data']['email'] );
			if ( $identity ) {
				agumail_signup_response_identity( $identity );
			}
		}
	} catch ( Throwable $e ) {
		agumail_log( 'error', 'A WPForms signup was not queued: ' . $e->getMessage() );
	}
}

/**
 * The signup in a completed submission, or null when there is none: no
 * address, or a consent checkbox left unticked. $fields are the submitted
 * fields by id, as WPForms formats them ({name, value, id, type}, and a Name
 * field's first, middle and last). The consent is only ever the field the
 * merchant mapped: a GDPR box is required (ticked whenever the form went
 * through; its value is the words next to it), and a plain checkbox may be
 * about anything. A checkbox's value is the words of the choices ticked.
 *
 * @param array $fields    The submitted fields.
 * @param array $form_data The form.
 */
function agumail_wpforms_signup( array $fields, array $form_data ) {
	$settings = $form_data['settings'];
	// A GDPR box is always required, so it is the consent only when the merchant maps it.
	$types = array(
		'email' => 'email',
		'phone' => 'phone',
	);
	$list  = array();
	foreach ( $fields as $id => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$type   = isset( $field['type'] ) ? (string) $field['type'] : '';
		$list[] = array(
			'id'    => (string) $id,
			// A Name field is found as a name, not by its label.
			'type'  => isset( $types[ $type ] ) ? $types[ $type ] : ( 'name' === $type ? 'name' : $type ),
			'label' => isset( $field['name'] ) ? (string) $field['name'] : '',
			'value' => isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '',
		);
	}
	$chosen = array();
	foreach ( array(
		'email'     => 'agumail_field_email',
		'phone'     => 'agumail_field_phone',
		'consent'   => 'agumail_field_consent',
		'full_name' => 'agumail_field_name',
	) as $part => $setting ) {
		if ( ! empty( $settings[ $setting ] ) && is_scalar( $settings[ $setting ] ) ) {
			$chosen[ $part ] = (string) $settings[ $setting ];
		}
	}
	if ( ! isset( $chosen['full_name'] ) ) {
		foreach ( $fields as $id => $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'name' === $field['type'] && isset( $field['value'] ) && '' !== trim( (string) $field['value'] ) ) {
				$chosen['full_name'] = (string) $id;
				break;
			}
		}
	}
	$parts = agumail_signup_detect_fields( $list, $chosen );
	$value = function ( $part, $key = 'value' ) use ( $parts, $fields ) {
		return isset( $parts[ $part ], $fields[ $parts[ $part ] ][ $key ] ) && is_scalar( $fields[ $parts[ $part ] ][ $key ] ) ? trim( (string) $fields[ $parts[ $part ] ][ $key ] ) : '';
	};
	if ( ! isset( $parts['email'] ) ) {
		return null;
	}
	$consent_text = '';
	if ( isset( $parts['consent'] ) ) {
		$consent_text = $value( 'consent' );
		if ( ! agumail_signup_consent_ticked( $consent_text ) ) {
			return null;
		}
	}
	$first = $value( 'first_name' );
	$last  = $value( 'last_name' );
	// A Name field in the first and last format has the two parts already.
	if ( '' === $first && '' === $last && isset( $parts['full_name'] ) && 'name' === $fields[ $parts['full_name'] ]['type'] ) {
		$first = $value( 'full_name', 'first' );
		$last  = $value( 'full_name', 'last' );
	}
	// WPForms has checked the submission; only the page address it posts is read.
	$page = agumail_posted( 'page_url', 'url' );
	return array(
		'form' => array(
			'id'   => isset( $form_data['id'] ) ? (string) $form_data['id'] : '',
			'name' => isset( $settings['form_title'] ) && is_scalar( $settings['form_title'] ) ? (string) $settings['form_title'] : '',
		),
		'data' => array(
			'email'         => $value( 'email' ),
			'first_name'    => $first,
			'last_name'     => $last,
			'full_name'     => $value( 'full_name' ),
			'phone'         => $value( 'phone' ),
			'tags'          => isset( $settings['agumail_tags'] ) ? $settings['agumail_tags'] : '',
			'double_opt_in' => agumail_wpforms_double_opt_in( $settings ),
			'consent_text'  => $consent_text,
			'page_url'      => $page,
		),
	);
}

/**
 * Who signed up, for the AguMail script, in the answer to an AJAX submission that queued one.
 *
 * @param mixed $response The answer WPForms sends the browser, an array; another
 *                        plugin on the filter may have returned anything.
 */
function agumail_wpforms_ajax_response( $response ) {
	$identity = agumail_signup_response_identity();
	if ( $identity && is_array( $response ) ) {
		$response['agumail'] = $identity;
	}
	return $response;
}
