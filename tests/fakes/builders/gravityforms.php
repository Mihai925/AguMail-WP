<?php
// Gravity Forms, as far as the AguMail adapter touches it: GFForms and the
// add-on framework (GFAddOn) it loads, form fields as GF_Field objects, a
// form and an entry as arrays, and a submission as GFFormDisplay runs it
// (gform_after_submission, then, for the AJAX route, the
// gform_ajax_submission_result filter). Shapes follow Gravity Forms 3.2.3.1
// (gravityforms.php, includes/addon/class-gf-addon.php, form_display.php,
// includes/ajax/class-gf-ajax-handler.php, includes/fields). Loaded only by
// the scenarios that need it.
//
// AguMail takes Gravity Forms to be active when GFForms exists, so the
// plugin's classes are only declared once a scenario calls agm_gf_activate().

/** A form field: the properties GF_Field has, and its input type as Gravity Forms decides it. */
class GF_Field {
    public function __construct(array $properties = array()) {
        foreach ($properties as $key => $value) {
            $this->$key = $value;
        }
    }
    public function get_input_type() {
        return empty($this->inputType) ? $this->type : $this->inputType;
    }
}

/**
 * Switches Gravity Forms on: declares GFForms and GFAddOn as Gravity Forms
 * loads them, then fires gform_loaded, as GFForms::loaded() does during
 * plugins_loaded. Returns the add-on classes registered.
 */
function agm_gf_activate() {
    if (!class_exists('GFForms', false)) {
        class GFForms {
            public static $version = '3.2.3.1';
            public static function include_addon_framework() {
                $GLOBALS['agm']['gf_framework'] = true;
            }
        }
        class GFAddOn {
            protected $_version;
            protected $_min_gravityforms_version;
            protected $_slug;
            protected $_path;
            protected $_full_path;
            protected $_title;
            protected $_short_title;
            protected $_capabilities_form_settings = array();
            protected $_capabilities_uninstall = array();
            public static $registered = array();
            public function __construct() {
                // Gravity Forms corrects _path when the plugin's folder was renamed.
                if ($this->_path && $this->_full_path && dirname($this->_path) !== basename(dirname($this->_full_path))) {
                    $this->_path = basename(dirname($this->_full_path)) . '/' . basename($this->_path);
                }
            }
            public static function register($class, $overrides = null) {
                self::$registered[] = $class;
            }
            public function get_slug() { return $this->_slug; }
            public function get_path() { return $this->_path; }
            public function get_version() { return $this->_version; }
            public function get_short_title() { return $this->_short_title; }
            public function get_form_settings_capabilities() { return $this->_capabilities_form_settings; }
            public function get_form_settings($form) { return isset($form[$this->_slug]) ? $form[$this->_slug] : null; }
            public function form_settings_fields($form) { return array(); }
            public function get_menu_icon() { return 'gform-icon--cog'; }
            public function current_user_can_uninstall() { return current_user_can('gravityforms_uninstall'); }
        }
    }
    do_action('gform_loaded');
    return GFAddOn::$registered;
}

/** The AguMail add-on, as Gravity Forms starts it (GFAddOn::init_addons() calls get_instance()). */
function agm_gf_addon() {
    $registered = agm_gf_activate();
    expect_that(in_array('AguMail_Gravity_Forms_Addon', $registered, true), 'the AguMail add-on was not registered');
    return call_user_func(array('AguMail_Gravity_Forms_Addon', 'get_instance'));
}

/** A single-input field. */
function agm_gf_field($id, $type, $label, array $extra = array()) {
    return new GF_Field(array_merge(array('id' => $id, 'type' => $type, 'label' => $label, 'adminLabel' => '', 'isRequired' => false, 'inputs' => null), $extra));
}

/** A Name field in the default (advanced) format: inputs .2 prefix, .3 first, .4 middle, .6 last, .8 suffix. */
function agm_gf_name_field($id, $label = 'Name', array $extra = array()) {
    $inputs = array();
    foreach (array('2' => 'Prefix', '3' => 'First', '4' => 'Middle', '6' => 'Last', '8' => 'Suffix') as $n => $part) {
        $inputs[] = array('id' => "$id.$n", 'label' => $part, 'name' => '');
    }
    return agm_gf_field($id, 'name', $label, array_merge(array('nameFormat' => 'advanced', 'inputs' => $inputs), $extra));
}

/** A Consent field: .1 the box (value 1), .2 the words next to it, .3 the description's revision. */
function agm_gf_consent_field($id, $label, $words, $required = false) {
    return agm_gf_field($id, 'consent', $label, array(
        'isRequired'    => $required,
        'checkboxLabel' => $words,
        'inputs'        => array(
            array('id' => "$id.1", 'label' => 'Consent'),
            array('id' => "$id.2", 'label' => 'Text', 'isHidden' => true),
            array('id' => "$id.3", 'label' => 'Description', 'isHidden' => true),
        ),
    ));
}

/** A Checkbox field, one input per choice: .1, .2, and so on. */
function agm_gf_checkbox_field($id, $label, array $choices) {
    $inputs = array();
    $list = array();
    $n = 0;
    foreach ($choices as $value => $text) {
        $n++;
        $inputs[] = array('id' => "$id.$n", 'label' => $text);
        $list[] = array('text' => $text, 'value' => is_string($value) ? $value : $text, 'isSelected' => false);
    }
    return agm_gf_field($id, 'checkbox', $label, array('inputs' => $inputs, 'choices' => $list));
}

/** A form as Gravity Forms hands it to its hooks: id, title, fields, and the AguMail settings when it has them. */
function agm_gf_form($id, $title, array $fields, array $agumail = null) {
    $form = array('id' => $id, 'title' => $title, 'fields' => $fields, 'confirmations' => array());
    if ($agumail !== null) {
        $form['agumail'] = $agumail;
    }
    return $form;
}

/** A saved entry: the answers by field or input id, and the entry's own properties. */
function agm_gf_entry($form_id, array $answers, array $properties = array()) {
    // array_replace, not array_merge: answers are keyed by field id, which PHP keeps as integers.
    return array_replace(array(
        'id'         => '101',
        'form_id'    => (string) $form_id,
        'status'     => 'active',
        'source_url' => 'https://shop.test/guide/',
        'ip'         => '203.0.113.9',
    ), $properties, $answers);
}

/**
 * A submission Gravity Forms has validated and saved: gform_after_submission
 * (general, then for the form), as GFFormDisplay::process_form() fires it,
 * then the answer GF_Ajax_Handler::submit_form() sends an AJAX form, through
 * gform_ajax_submission_result. Returns that answer.
 */
function agm_gf_submit(array $form, array $entry) {
    do_action('gform_after_submission', $entry, $form);
    do_action('gform_after_submission_' . $form['id'], $entry, $form);
    $result = array(
        'is_valid'              => true,
        'page_number'           => 0,
        'source_page_number'    => 1,
        'confirmation_message'  => '<p>Thanks!</p>',
        'confirmation_type'     => 'message',
        'confirmation_redirect' => '',
    );
    $result = apply_filters('gform_ajax_submission_result', $result);
    return apply_filters('gform_ajax_submission_result_' . $form['id'], $result);
}
