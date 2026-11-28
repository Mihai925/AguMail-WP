<?php
/**
 * The AguMail tab in a Gravity Forms form's Settings. Loaded by
 * gravityforms.php once Gravity Forms has loaded its add-on framework, where
 * GFAddOn exists. The tab's fields and the submission handling live in
 * gravityforms.php; this class only puts the tab where Gravity Forms expects
 * an add-on's.
 *
 * @package AguMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only where Gravity Forms' base class exists, so this file can never fatal on its own.
if ( class_exists( 'GFAddOn' ) ) {
	/**
	 * The AguMail add-on: a form settings tab, saved in the form's own meta under "agumail".
	 */
	class AguMail_Gravity_Forms_Addon extends GFAddOn {

		/**
		 * The one instance Gravity Forms works with.
		 *
		 * @var AguMail_Gravity_Forms_Addon|null
		 */
		private static $instance = null;

		/**
		 * The instance Gravity Forms asks for when it starts its add-ons.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Describes the add-on to Gravity Forms, in the properties GFAddOn reads
		 * (their names are Gravity Forms', underscores included): the slug, which
		 * is also the key of the settings in each form; the oldest Gravity Forms
		 * with the settings API the tab is built on; who sees the tab (whoever may
		 * edit the form); and this plugin's own file, whatever its folder is
		 * called.
		 */
		public function __construct() {
			$this->_slug                       = AGUMAIL_GRAVITYFORMS_SLUG;
			$this->_title                      = 'AguMail';
			$this->_short_title                = 'AguMail';
			$this->_version                    = AGUMAIL_VERSION;
			$this->_min_gravityforms_version   = '2.5';
			$this->_capabilities_form_settings = 'gravityforms_edit_forms';
			$this->_full_path                  = AGUMAIL_FILE;
			$this->_path                       = plugin_basename( AGUMAIL_FILE );
			parent::__construct();
		}

		/**
		 * The tab's settings.
		 *
		 * @param array $form The form being edited.
		 */
		public function form_settings_fields( $form ) {
			return agumail_gravityforms_settings_fields( $form );
		}

		/**
		 * The tab's icon.
		 */
		public function get_menu_icon() {
			return 'dashicons-email-alt';
		}

		/**
		 * Never. Gravity Forms' add-on uninstall (Forms > Settings > Uninstall)
		 * ends by deactivating the add-on's plugin, which here is all of
		 * AguMail, store sync included. Refusing it also keeps AguMail off
		 * that page. Switching a form off is done in its own tab.
		 */
		public function current_user_can_uninstall() {
			return false;
		}
	}
}
