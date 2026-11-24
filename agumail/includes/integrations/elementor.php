<?php
/**
 * Elementor Pro forms: an "AguMail" action under Actions After Submit.
 *
 * The merchant adds AguMail to a form's actions, then sets tags, double
 * opt-in and, if they like, which form field is the email, first name, last
 * name, phone and consent box. A field left unmapped is found by its type or
 * its name (signups.php). On each successful submission the action queues the
 * signup with one outbox write; it never reports an error to Elementor, so the
 * visitor's submission always goes through, whatever happens on our side.
 *
 * Only the hooks live here. The action class extends Elementor Pro's own
 * Integration_Base, so its file is loaded inside Elementor Pro's registration
 * hook, when that class is sure to exist. The editor script fills the field
 * map with AguMail's fields, as Elementor Pro's own integrations do.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'elementor_pro/forms/actions/register', 'agumail_elementor_register_action' );
add_action( 'elementor/editor/after_enqueue_scripts', 'agumail_elementor_editor_script', 10, 0 );

/**
 * Registers the AguMail action with Elementor Pro's forms.
 *
 * @param mixed $registrar Elementor Pro's form actions, an
 *                         ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar
 *                         (checked before use).
 */
function agumail_elementor_register_action( $registrar ) {
	if ( ! is_object( $registrar ) || ! method_exists( $registrar, 'register' ) || ! class_exists( '\ElementorPro\Modules\Forms\Classes\Integration_Base' ) ) {
		return;
	}
	require_once AGUMAIL_DIR . 'includes/integrations/class-agumail-elementor-action.php';
	$registrar->register( new AguMail_Elementor_Action() );
}

/**
 * Adds the editor script that fills the field map, with AguMail's fields.
 */
function agumail_elementor_editor_script() {
	wp_enqueue_script( 'agumail-elementor-editor', plugins_url( 'assets/js/elementor-editor.js', AGUMAIL_FILE ), array( 'jquery' ), AGUMAIL_VERSION, true );
	wp_add_inline_script( 'agumail-elementor-editor', 'window.agumailElementor = ' . wp_json_encode( array( 'fields' => agumail_elementor_remote_fields() ) ) . ';', 'before' );
}

/**
 * The rows of the AguMail field map, in the shape Elementor Pro's Fields_Map
 * control takes. The map lists the form's fields of the same type next to
 * each row, and every field next to a text row.
 */
function agumail_elementor_remote_fields() {
	return array(
		array(
			'remote_id'       => 'email',
			'remote_label'    => __( 'Email', 'agumail' ),
			'remote_type'     => 'email',
			'remote_required' => true,
		),
		array(
			'remote_id'       => 'first_name',
			'remote_label'    => __( 'First name', 'agumail' ),
			'remote_type'     => 'text',
			'remote_required' => false,
		),
		array(
			'remote_id'       => 'last_name',
			'remote_label'    => __( 'Last name', 'agumail' ),
			'remote_type'     => 'text',
			'remote_required' => false,
		),
		array(
			'remote_id'       => 'phone',
			'remote_label'    => __( 'Phone', 'agumail' ),
			'remote_type'     => 'text',
			'remote_required' => false,
		),
		array(
			'remote_id'       => 'consent',
			'remote_label'    => __( 'Consent', 'agumail' ),
			'remote_type'     => 'acceptance',
			'remote_required' => false,
		),
	);
}

/**
 * The signup in a submitted Elementor form, or null when there is none: no
 * address, or a consent box left unticked. $fields are the record's fields by
 * custom id ({id, type, title, value, required}); $settings the form widget's
 * settings, as Elementor Pro hands them to actions. Returns ['form' => {id,
 * name}, 'data' => ...] for agumail_signup_enqueue().
 *
 * @param array $fields   The record's fields.
 * @param array $settings The form widget's settings.
 */
function agumail_elementor_signup( array $fields, array $settings ) {
	$types = array(
		'email'      => 'email',
		'tel'        => 'phone',
		'acceptance' => 'consent',
	);
	$list  = array();
	foreach ( $fields as $id => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$type   = isset( $field['type'] ) ? (string) $field['type'] : '';
		$list[] = array(
			'id'       => (string) $id,
			'type'     => isset( $types[ $type ] ) ? $types[ $type ] : $type,
			'label'    => isset( $field['title'] ) ? (string) $field['title'] : '',
			'value'    => isset( $field['value'] ) && is_scalar( $field['value'] ) ? (string) $field['value'] : '',
			'required' => ! empty( $field['required'] ),
		);
	}
	$chosen = array();
	foreach ( isset( $settings['agumail_fields_map'] ) && is_array( $settings['agumail_fields_map'] ) ? $settings['agumail_fields_map'] : array() as $item ) {
		if ( is_array( $item ) && ! empty( $item['remote_id'] ) && ! empty( $item['local_id'] ) ) {
			$chosen[ (string) $item['remote_id'] ] = (string) $item['local_id'];
		}
	}
	$parts = agumail_signup_detect_fields( $list, $chosen );
	$value = function ( $part ) use ( $parts, $fields ) {
		return isset( $parts[ $part ], $fields[ $parts[ $part ] ]['value'] ) && is_scalar( $fields[ $parts[ $part ] ]['value'] ) ? trim( (string) $fields[ $parts[ $part ] ]['value'] ) : '';
	};
	if ( ! isset( $parts['email'] ) ) {
		return null;
	}
	$consent_text = '';
	if ( isset( $parts['consent'] ) ) {
		if ( ! agumail_signup_consent_ticked( $value( 'consent' ) ) ) {
			return null;
		}
		$consent_text = agumail_elementor_acceptance_text( $settings, $parts['consent'] );
	}
	$form_id = isset( $settings['id'] ) && is_scalar( $settings['id'] ) ? (string) $settings['id'] : '';
	$post_id = isset( $settings['form_post_id'] ) && is_scalar( $settings['form_post_id'] ) ? (string) $settings['form_post_id'] : '';
	// Elementor Pro's forms post without a nonce; only the page address is read.
	$page = agumail_posted( 'referrer', 'url' );
	return array(
		'form' => array(
			'id'   => $post_id . ':' . $form_id,
			'name' => isset( $settings['form_name'] ) && is_scalar( $settings['form_name'] ) ? (string) $settings['form_name'] : '',
		),
		'data' => array(
			'email'         => $value( 'email' ),
			'first_name'    => $value( 'first_name' ),
			'last_name'     => $value( 'last_name' ),
			'full_name'     => $value( 'full_name' ),
			'phone'         => $value( 'phone' ),
			'tags'          => isset( $settings['agumail_tags'] ) ? $settings['agumail_tags'] : '',
			'double_opt_in' => isset( $settings['agumail_double_opt_in'] ) && 'yes' === $settings['agumail_double_opt_in'],
			'consent_text'  => $consent_text,
			'page_url'      => $page,
		),
	);
}

/**
 * The words next to an acceptance box, from the form's field settings.
 *
 * @param array  $settings The form widget's settings.
 * @param string $field_id The box's custom id.
 */
function agumail_elementor_acceptance_text( array $settings, $field_id ) {
	foreach ( isset( $settings['form_fields'] ) && is_array( $settings['form_fields'] ) ? $settings['form_fields'] : array() as $item ) {
		if ( is_array( $item ) && isset( $item['custom_id'] ) && (string) $item['custom_id'] === (string) $field_id ) {
			return isset( $item['acceptance_text'] ) && is_scalar( $item['acceptance_text'] ) ? (string) $item['acceptance_text'] : '';
		}
	}
	return '';
}
