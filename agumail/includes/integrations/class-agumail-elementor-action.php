<?php
/**
 * The AguMail action for Elementor Pro forms. Loaded by elementor.php inside
 * Elementor Pro's action registration, where Integration_Base exists.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only where Elementor Pro's base class exists, so this file can never fatal on its own.
if ( class_exists( '\ElementorPro\Modules\Forms\Classes\Integration_Base' ) ) {
	/**
	 * The AguMail action under Actions After Submit.
	 */
	class AguMail_Elementor_Action extends \ElementorPro\Modules\Forms\Classes\Integration_Base {

		/**
		 * The action's name.
		 */
		public function get_name() {
			return 'agumail';
		}

		/**
		 * The action's label in the editor.
		 */
		public function get_label() {
			return __( 'AguMail', 'agumail' );
		}

		/**
		 * Adds the AguMail section to the form widget: tags, double opt-in and the field map.
		 *
		 * @param object $widget The form widget, an ElementorPro\Modules\Forms\Widgets\Form.
		 */
		public function register_settings_section( $widget ) {
			$widget->start_controls_section(
				'section_agumail',
				array(
					'label'     => __( 'AguMail', 'agumail' ),
					'condition' => array( 'submit_actions' => $this->get_name() ),
				)
			);
			if ( ! agumail_has_api_key() ) {
				$message = esc_html__( 'Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', 'agumail' );
				// Elementor Pro registers this section on every form it renders or submits, so a
				// control Elementor does not have would break those pages: ALERT came in Elementor
				// 3.19, and older versions get the same warning as raw HTML.
				$widget->add_control(
					'agumail_not_connected',
					defined( 'Elementor\Controls_Manager::ALERT' )
					? array(
						'type'       => constant( '\Elementor\Controls_Manager::ALERT' ),
						'alert_type' => 'warning',
						'content'    => $message,
					)
					: array(
						'type'            => constant( '\Elementor\Controls_Manager::RAW_HTML' ),
						'raw'             => $message,
						'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
					)
				);
			}
			$widget->add_control(
				'agumail_tags',
				array(
					'label'       => __( 'Tags', 'agumail' ),
					'type'        => constant( '\Elementor\Controls_Manager::TEXT' ),
					'description' => __( 'Separate tags with commas. New tags are created.', 'agumail' ),
					'render_type' => 'none',
					'ai'          => array( 'active' => false ),
				)
			);
			$widget->add_control(
				'agumail_double_opt_in',
				array(
					'label'       => __( 'Double opt-in', 'agumail' ),
					'type'        => constant( '\Elementor\Controls_Manager::SWITCHER' ),
					'default'     => '',
					'description' => __( 'New subscribers confirm by email before they get anything else.', 'agumail' ),
					'render_type' => 'none',
				)
			);
			// Integration_Base's own fields map control.
			call_user_func( array( $this, 'register_fields_map_control' ), $widget );
			$widget->end_controls_section();
		}

		/**
		 * Queues the signup, and never adds an error: Elementor Pro would show it
		 * to the visitor and fail their submission. A problem is logged instead.
		 * The only thing added to the answer is who signed up, for the AguMail
		 * script, when tracking is on.
		 *
		 * @param object $record       The submission, an
		 *                             ElementorPro\Modules\Forms\Classes\Form_Record.
		 * @param mixed  $ajax_handler The answer to the browser, an
		 *                             ElementorPro\Modules\Forms\Classes\Ajax_Handler
		 *                             (checked before use).
		 */
		public function run( $record, $ajax_handler ) {
			try {
				$fields   = $record->get( 'fields' );
				$settings = $record->get( 'form_settings' );
				$signup   = agumail_elementor_signup( is_array( $fields ) ? $fields : array(), is_array( $settings ) ? $settings : array() );
				if ( $signup && agumail_signup_enqueue( 'elementor', $signup['form'], $signup['data'] ) ) {
					// With tracking on, the page tells the AguMail script who signed up (signups.php).
					$identity = agumail_signup_identity( $signup['data']['email'] );
					if ( $identity && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_response_data' ) ) {
						$ajax_handler->add_response_data( 'agumail', $identity );
					}
				}
			} catch ( Throwable $e ) {
				agumail_log( 'error', 'An Elementor form signup was not queued: ' . $e->getMessage() );
			}
		}

		/**
		 * Nothing in the AguMail settings is tied to this store, so an exported form keeps them all.
		 *
		 * @param array $element The form element being exported.
		 */
		public function on_export( $element ) {
			return $element;
		}
	}
}
