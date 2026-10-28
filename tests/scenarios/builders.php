<?php
// The form plugin adapters: Elementor Pro's "AguMail" action after submit,
// Contact Form 7's AguMail tab, and WPForms' Settings > AguMail section. Each
// turns a successful submission into one queued signup (signups.php), never
// fails the visitor's own submission, and, with website tracking on, hands the
// address and its identify signature back in the form plugin's own answer.

// The site script loads only from AguMail's own hosts or one wp-config.php
// pins. This store pins the test API, as a development site would, so the
// addresses below count as AguMail's.
define('AGUMAIL_API_URL', 'https://api.agumail.test');

require_once dirname(__DIR__) . '/fakes/builders/elementor.php';
require_once dirname(__DIR__) . '/fakes/builders/cf7.php';
require_once dirname(__DIR__) . '/fakes/builders/wpforms.php';

function builders_setup($now = 1790000000) {
    $GLOBALS['agm']['clock'] = (float) $now;
    agumail_set_clock(function () { return $GLOBALS['agm']['clock']; });
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
}

/** Tracking on, with the site script and identify secret AguMail handed over. */
function builders_tracking() {
    update_option('agumail_tracking_enabled', 'yes');
    update_option('agumail_config', array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://api.agumail.test/p/_/v1/site.js',
        'siteEndpoint'   => 'https://api.agumail.test/api/public/site/site_abc123/events',
        'identifySecret' => 'secret-for-identify',
    ));
}

function builders_signups() {
    return array_map(function ($row) { return $row['payload']; }, agm_outbox('signup'));
}

function builders_only_signup() {
    $signups = builders_signups();
    expect_same(1, count($signups), 'expected one queued signup, got ' . count($signups));
    return $signups[0];
}

function builders_expect_nothing_queued($why) {
    expect_same(array(), agm_outbox('signup'), $why);
}

/** Runs $body as one storefront request of $kind and fails on any call or on more than one write. */
function builders_request($name, $kind, callable $body) {
    // The harness counts writes by request name, so each request gets its own.
    static $count = 0;
    $name .= ' #' . (++$count);
    $result = agm_request($name, $kind, $body);
    expect_same(array(), $result['http'], "\"$name\" called out");
    expect_that(count($result['writes']) <= 1, "\"$name\" made more than one write: " . json_encode($result['writes']));
    return $result;
}

// ── Elementor Pro ──

/** An Elementor form: name, email, phone and an acceptance box. */
function builders_elementor_fields($consent = 'on') {
    return array(
        array('name', 'text', 'Name', 'Ada King Lovelace'),
        array('email', 'email', 'Email', 'Ada@Example.com'),
        array('field_ph1', 'tel', 'Your phone', '+44 20 7946 0000'),
        array('field_ok', 'acceptance', 'Consent', $consent, array('acceptance_text' => 'Email me about <a href="/offers">offers</a>')),
    );
}

function builders_elementor_run($record) {
    $action = agm_elementor_action();
    $handler = agm_elementor_handler();
    builders_request('elementor submit', 'shopper_ajax', function () use ($action, $record, $handler) {
        agm_input(array(), array('action' => 'elementor_pro_forms_send_form', 'referrer' => 'https://shop.test/sale/?utm_source=ig', 'post_id' => '12', 'form_id' => 'abc123'));
        $action->run($record, $handler);
    });
    expect_same(array(), $handler->errors, 'the action added a field error: the visitor\'s submission fails');
    expect_same(array(), $handler->messages['error'], 'the action added an error message: the visitor\'s submission fails');
    expect_same(array(), $handler->messages['admin_error'], 'the action added an admin error: the visitor\'s submission fails');
    expect_same(true, $handler->is_success, 'the submission was marked failed');
    return $handler;
}

function test_builders_elementor_registers_the_agumail_action_when_elementor_pro_asks() {
    $action = agm_elementor_action();
    expect_that($action instanceof AguMail_Elementor_Action, 'the AguMail action was not registered');
    expect_that($action instanceof \ElementorPro\Modules\Forms\Classes\Integration_Base, 'the action is not an Elementor Pro integration');
    expect_same('agumail', $action->get_name(), 'wrong name');
    expect_same('AguMail', $action->get_label(), 'wrong label');
    $element = array('id' => 'abc123', 'settings' => array('agumail_tags' => 'vip', 'agumail_fields_map' => array()));
    expect_same($element, $action->on_export($element), 'exporting the form changed it');
}

function test_builders_elementor_the_action_class_loads_only_when_elementor_pro_registers_actions($plugin_dir) {
    expect_that(!class_exists('AguMail_Elementor_Action', false), 'the action class was loaded before Elementor Pro asked for it');
    $always = file_get_contents($plugin_dir . '/includes/integrations/elementor.php');
    expect_that(strpos($always, 'class AguMail_Elementor_Action') === false, 'the class is defined in the file every request loads');
}

function test_builders_elementor_the_agumail_section_has_tags_double_opt_in_and_the_field_map() {
    $widget = new Agm_Elementor_Widget();
    agm_elementor_action()->register_settings_section($widget);
    expect_same(array('label' => 'AguMail', 'condition' => array('submit_actions' => 'agumail')), $widget->sections['section_agumail'], 'wrong section');
    expect_that(!isset($widget->controls['agumail_not_connected']), 'a connected store shows the not-connected alert');
    expect_same('text', $widget->controls['agumail_tags']['type'], 'the tags control is not text');
    expect_same('Separate tags with commas. New tags are created.', $widget->controls['agumail_tags']['description'], 'wrong tags help');
    expect_same('switcher', $widget->controls['agumail_double_opt_in']['type'], 'double opt-in is not a switch');
    expect_same('', $widget->controls['agumail_double_opt_in']['default'], 'double opt-in should start off');
    expect_same('fields_map', $widget->controls['agumail_fields_map']['type'], 'the field map is missing');
    foreach ($widget->controls as $id => $control) {
        expect_same('section_agumail', $control['section'], "$id is outside the AguMail section");
    }
}

function test_builders_elementor_an_unconnected_store_shows_an_alert() {
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $widget = new Agm_Elementor_Widget();
    agm_elementor_action()->register_settings_section($widget);
    $alert = $widget->controls['agumail_not_connected'];
    expect_same('alert', $alert['type'], 'the not-connected message is not an alert');
    expect_same('Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', $alert['content'], 'wrong alert');
}

function test_builders_elementor_older_than_3_19_gets_the_not_connected_notice_without_the_alert_control() {
    agm_expect_child_passes('builders.php', 'builders_child_elementor_without_the_alert_control', array('AGM_ELEMENTOR_NO_ALERT' => '1'));
}

/** Run in a fresh process with Controls_Manager as Elementor 3.18 and older have it: no ALERT. */
function builders_child_elementor_without_the_alert_control() {
    expect_that(!defined('Elementor\Controls_Manager::ALERT'), 'the fake still has the ALERT control');
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $widget = new Agm_Elementor_Widget();
    agm_elementor_action()->register_settings_section($widget);
    $notice = $widget->controls['agumail_not_connected'];
    expect_same('raw_html', $notice['type'], 'the notice is not raw HTML');
    expect_same('Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', $notice['raw'], 'wrong notice');
    expect_same('elementor-panel-alert elementor-panel-alert-warning', $notice['content_classes'], 'the notice is not styled as a warning');
}

function test_builders_elementor_the_editor_script_gets_the_agumail_fields() {
    do_action('elementor/editor/after_enqueue_scripts');
    $script = agm_script('agumail-elementor-editor');
    expect_that($script && $script['enqueued'], 'the editor script was not enqueued');
    expect_same('https://shop.test/wp-content/plugins/agumail/assets/js/elementor-editor.js', $script['src'], 'wrong script');
    $inline = implode("\n", $script['inline']);
    foreach (array('"remote_id":"email"', '"remote_id":"first_name"', '"remote_id":"last_name"', '"remote_id":"phone"', '"remote_id":"consent"', '"remote_type":"acceptance"') as $needle) {
        expect_that(strpos($inline, $needle) !== false, "the editor data lacks $needle: $inline");
    }
}

function test_builders_elementor_auto_detects_every_field_nobody_mapped() {
    builders_setup();
    builders_elementor_run(agm_elementor_record(builders_elementor_fields(), array('agumail_tags' => 'popup, VIP')));
    $signup = builders_only_signup();
    expect_same('ada@example.com', $signup['email'], 'wrong email');
    expect_same(array('Ada', 'King Lovelace'), array($signup['firstName'], $signup['lastName']), 'the single name field was not split');
    expect_same('+44 20 7946 0000', $signup['phone'], 'the tel field was not the phone');
    expect_same(array('popup', 'VIP'), $signup['tags'], 'wrong tags');
    expect_same(false, $signup['doubleOptIn'], 'double opt-in should be off until the merchant switches it on');
    expect_same(array('builder' => 'elementor', 'id' => '12:abc123', 'name' => 'Welcome popup'), $signup['form'], 'wrong form');
    expect_same('https://shop.test/sale/?utm_source=ig', $signup['pageUrl'], 'the page is not the posted referrer');
    expect_same('Email me about offers', $signup['consentText'], 'the consent words are not the acceptance text');
    expect_same('203.0.113.9', $signup['ip'], 'wrong address');
}

function test_builders_elementor_first_and_last_name_fields_are_found_by_id_or_label() {
    builders_setup();
    builders_elementor_run(agm_elementor_record(array(
        array('field_1', 'text', 'First name', 'Ada'),
        array('lname', 'text', '', 'Lovelace'),
        array('field_3', 'text', 'Mobile number', '0700'),
        array('field_4', 'email', 'Work email', ''),
        array('field_5', 'email', 'Email', 'ada@example.com'),
    ), array('agumail_double_opt_in' => '')));
    $signup = builders_only_signup();
    expect_same(array('ada@example.com', 'Ada', 'Lovelace', '0700', false), array($signup['email'], $signup['firstName'], $signup['lastName'], $signup['phone'], $signup['doubleOptIn']), 'wrong detection');
    expect_that(!isset($signup['consentText']), 'a form without an acceptance box sent consent words');
}

function test_builders_elementor_the_merchants_map_wins_over_detection() {
    builders_setup();
    builders_elementor_run(agm_elementor_record(array(
        array('email', 'email', 'Email', 'wrong@example.com'),
        array('field_b', 'email', 'Your best email', 'ada@example.com'),
        array('field_n', 'text', 'Nickname', 'Countess'),
        array('field_p', 'text', 'Notes', '555 0100'),
    ), array('agumail_fields_map' => agm_elementor_map(array('email' => 'field_b', 'first_name' => 'field_n', 'last_name' => '', 'phone' => 'field_p')))));
    $signup = builders_only_signup();
    expect_same(array('ada@example.com', 'Countess', '555 0100'), array($signup['email'], $signup['firstName'], $signup['phone']), 'the map was not followed');
}

function test_builders_elementor_an_unticked_acceptance_box_queues_nothing() {
    builders_setup();
    builders_elementor_run(agm_elementor_record(builders_elementor_fields('')));
    builders_expect_nothing_queued('an unticked acceptance box queued a signup');
}

function test_builders_elementor_a_mapped_consent_box_unticked_queues_nothing() {
    builders_setup();
    $fields = builders_elementor_fields('on');
    $fields[] = array('field_news', 'acceptance', 'News', '', array('acceptance_text' => 'Send me news'));
    builders_elementor_run(agm_elementor_record($fields, array('agumail_fields_map' => agm_elementor_map(array('consent' => 'field_news')))));
    builders_expect_nothing_queued('the mapped consent box was unticked, yet a signup was queued');
}

function test_builders_elementor_a_required_acceptance_box_is_never_taken_for_the_consent() {
    builders_setup();
    $terms = array('terms', 'acceptance', 'Terms', 'on', array('acceptance_text' => 'I accept the terms', 'required' => 'true'));
    $news = array('news', 'acceptance', 'News', '', array('acceptance_text' => 'Send me news'));
    $email = array('email', 'email', 'Email', 'ada@example.com');
    builders_elementor_run(agm_elementor_record(array($email, $terms, $news)));
    builders_expect_nothing_queued('the optional newsletter box was left unticked, yet a signup was queued');

    $news[3] = 'on';
    builders_elementor_run(agm_elementor_record(array($email, $terms, $news)));
    expect_same('Send me news', builders_only_signup()['consentText'], 'the consent words are not the optional box');

    agm_store()->outbox = array();
    builders_elementor_run(agm_elementor_record(array($email, $terms)));
    expect_that(!isset(builders_only_signup()['consentText']), 'a required terms box was sent as consent to marketing');
}

function test_builders_elementor_a_form_without_an_email_queues_nothing_and_still_succeeds() {
    builders_setup();
    builders_elementor_run(agm_elementor_record(array(array('name', 'text', 'Name', 'Ada'), array('email', 'email', 'Email', ''))));
    builders_expect_nothing_queued('a form without an address queued a signup');
}

function test_builders_elementor_never_fails_the_visitors_submission() {
    builders_setup();
    // Not connected: nothing is sent, and the visitor never hears of it.
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    builders_elementor_run(agm_elementor_record(builders_elementor_fields()));
    builders_expect_nothing_queued('an unconnected store queued a signup');
    // A record that breaks as it is read.
    $GLOBALS['agm']['options']['agumail_api_key'] = 'agm_' . str_repeat('a', 40);
    $broken = new class(array(), array()) extends \ElementorPro\Modules\Forms\Classes\Form_Record {
        public function get($property) {
            throw new RuntimeException('record broke');
        }
    };
    builders_elementor_run($broken);
    expect_same(1, count(agm_logs('record broke')), 'the failure was not logged');
}

// ── Contact Form 7 ──

/** A contact form: name, email, phone, a required terms box and an optional newsletter box. */
function builders_cf7_form(array $agumail = null, array $extra_tags = array()) {
    $tags = array_merge(array(
        agm_cf7_tag('text*', 'your-name'),
        agm_cf7_tag('email*', 'your-email'),
        agm_cf7_tag('tel', 'your-phone'),
        agm_cf7_tag('textarea', 'your-message'),
        agm_cf7_tag('acceptance', 'terms', array(), 'I accept the terms'),
        agm_cf7_tag('submit', ''),
    ), $extra_tags);
    return agm_cf7_form(7, 'Contact form 1', $tags, $agumail === null ? array('enabled' => '1', 'tags' => 'contact', 'double_opt_in' => '1', 'fields' => array()) : $agumail);
}

function builders_cf7_posted(array $extra = array()) {
    return array_merge(array('your-name' => 'Ada King Lovelace', 'your-email' => 'ada@example.com', 'your-phone' => '0700', 'your-message' => 'Hello', 'terms' => '1'), $extra);
}

function builders_cf7_submit(WPCF7_ContactForm $form, array $posted, $status = 'mail_sent') {
    $response = null;
    builders_request('cf7 submit', 'shopper_rest', function () use ($form, $posted, $status, &$response) {
        $response = agm_cf7_submit($form, $posted, $status);
    });
    return $response;
}

function test_builders_cf7_a_sent_form_queues_the_signup() {
    builders_setup();
    builders_cf7_submit(builders_cf7_form(), builders_cf7_posted());
    $signup = builders_only_signup();
    expect_same(array(
        'email'       => 'ada@example.com',
        'firstName'   => 'Ada',
        'lastName'    => 'King Lovelace',
        'phone'       => '0700',
        'tags'        => array('contact'),
        'doubleOptIn' => true,
        'form'        => array('builder' => 'cf7', 'id' => '7', 'name' => 'Contact form 1'),
        'pageUrl'     => 'https://shop.test/contact/',
        'ip'          => '203.0.113.9',
        'occurredAt'  => gmdate('Y-m-d\TH:i:s\Z', 1790000000),
    ), $signup, 'wrong signup (a required terms box is not consent to marketing)');
}

function test_builders_cf7_sends_on_mail_sent_or_mail_failed_only_and_never_in_demo_mode() {
    builders_setup();
    builders_cf7_submit(builders_cf7_form(), builders_cf7_posted(array('your-email' => 'failed@example.com')), 'mail_failed');
    expect_same(array('failed@example.com'), array_column(builders_signups(), 'email'), 'a form whose mail failed was not queued');
    foreach (array('validation_failed', 'acceptance_missing', 'spam', 'aborted') as $status) {
        builders_cf7_submit(builders_cf7_form(), builders_cf7_posted(array('your-email' => $status . '@example.com')), $status);
    }
    expect_same(1, count(builders_signups()), 'a submission that did not go through was queued');
    $demo = builders_cf7_form();
    $demo->demo = true;
    builders_cf7_submit($demo, builders_cf7_posted(array('your-email' => 'demo@example.com')));
    expect_same(1, count(builders_signups()), 'a form in demo mode was queued');
}

function test_builders_cf7_a_form_without_agumail_switched_on_queues_nothing() {
    builders_setup();
    builders_cf7_submit(agm_cf7_form(7, 'Contact form 1', builders_cf7_form()->tags), builders_cf7_posted());
    builders_expect_nothing_queued('a form never set up for AguMail queued a signup');
    builders_cf7_submit(builders_cf7_form(array('enabled' => '', 'tags' => '', 'double_opt_in' => '1', 'fields' => array())), builders_cf7_posted());
    builders_expect_nothing_queued('a form with AguMail off queued a signup');
}

function test_builders_cf7_a_consent_for_agumail_box_is_the_consent() {
    builders_setup();
    $news = agm_cf7_tag('acceptance', 'newsletter', array('optional', 'consent_for:agumail'), 'Send me offers');
    builders_cf7_submit(builders_cf7_form(null, array($news)), builders_cf7_posted(array('newsletter' => '')));
    builders_expect_nothing_queued('an unticked consent_for:agumail box queued a signup');
    builders_cf7_submit(builders_cf7_form(null, array($news)), builders_cf7_posted(array('newsletter' => '1')));
    expect_same('Send me offers', builders_only_signup()['consentText'], 'the consent words are not the consent_for:agumail box');
}

function test_builders_cf7_an_optional_acceptance_box_is_the_consent_and_a_required_one_never_is() {
    builders_setup();
    $news = agm_cf7_tag('acceptance', 'news', array('optional'), 'Send me news');
    builders_cf7_submit(builders_cf7_form(null, array($news)), builders_cf7_posted(array('news' => '')));
    builders_expect_nothing_queued('the optional news box was left unticked, yet a signup was queued');
    builders_cf7_submit(builders_cf7_form(null, array($news)), builders_cf7_posted(array('news' => '1')));
    expect_same('Send me news', builders_only_signup()['consentText'], 'the consent words are not the optional box');
}

function test_builders_cf7_an_inverted_box_counts_when_left_unticked() {
    builders_setup();
    $optout = agm_cf7_tag('acceptance', 'no-news', array('optional', 'invert', 'consent_for:agumail'), 'Do not send me offers');
    builders_cf7_submit(builders_cf7_form(null, array($optout)), builders_cf7_posted(array('no-news' => '1')));
    builders_expect_nothing_queued('a ticked opt-out box queued a signup');
    builders_cf7_submit(builders_cf7_form(null, array($optout)), builders_cf7_posted(array('no-news' => '')));
    expect_same(1, count(builders_signups()), 'an unticked opt-out box queued nothing');
}

function test_builders_cf7_the_panel_mapping_wins_over_detection() {
    builders_setup();
    $form = builders_cf7_form(array('enabled' => '1', 'tags' => '', 'double_opt_in' => '', 'fields' => array(
        'email' => 'alt-email', 'first_name' => 'nick', 'last_name' => '', 'full_name' => '', 'phone' => '', 'consent' => '',
    )), array(agm_cf7_tag('email', 'alt-email'), agm_cf7_tag('text', 'nick')));
    builders_cf7_submit($form, builders_cf7_posted(array('alt-email' => 'bea@example.com', 'nick' => 'Bee')));
    $signup = builders_only_signup();
    expect_same(array('bea@example.com', 'Bee', false), array($signup['email'], $signup['firstName'], $signup['doubleOptIn']), 'the mapping was not followed');
    expect_that(!isset($signup['lastName']), 'a mapped first name was joined by a split full name');
}

function test_builders_cf7_the_agumail_tab_lists_the_forms_fields() {
    $panels = apply_filters('wpcf7_editor_panels', array('form-panel' => array('title' => 'Form', 'callback' => 'strlen')));
    expect_same('AguMail', $panels['agumail-panel']['title'], 'the AguMail tab is missing');
    $form = builders_cf7_form(array('enabled' => '1', 'tags' => 'a, b', 'double_opt_in' => '', 'fields' => array('email' => 'your-email')));
    $html = agm_capture(function () use ($panels, $form) {
        call_user_func($panels['agumail-panel']['callback'], $form);
    });
    expect_that(preg_match('/name="agumail-signup\[enabled\]"[^>]*checked/', $html) === 1, 'the switch does not show it is on');
    expect_that(strpos($html, 'value="a, b"') !== false, 'the tags are not shown');
    expect_that(preg_match('/name="agumail-signup\[double_opt_in\]"[^>]*checked/', $html) === 0, 'double opt-in shows on while saved off');
    expect_that(strpos($html, 'Detect automatically') !== false, 'the selects do not offer detection');
    expect_that(preg_match('/<option value="your-email" selected/', $html) === 1, 'the saved email field is not selected');
    foreach (array('your-name', 'your-phone', 'terms') as $name) {
        expect_that(strpos($html, 'value="' . $name . '"') !== false, "the tag $name is not offered");
    }
    expect_that(strpos($html, 'Save the form to see new fields here.') !== false, 'the note is missing');
    expect_that(strpos($html, 'Connect this store') === false, 'a connected store is told to connect');
}

function test_builders_cf7_a_new_form_starts_with_double_opt_in_off_and_says_when_not_connected() {
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $panels = apply_filters('wpcf7_editor_panels', array());
    $form = agm_cf7_form(9, 'New form', array(agm_cf7_tag('email*', 'your-email')));
    $html = agm_capture(function () use ($panels, $form) {
        call_user_func($panels['agumail-panel']['callback'], $form);
    });
    expect_that(preg_match('/name="agumail-signup\[double_opt_in\]"[^>]*checked/', $html) === 0, 'double opt-in should start off');
    expect_that(preg_match('/name="agumail-signup\[enabled\]"[^>]*checked/', $html) === 0, 'a new form should start off');
    expect_that(strpos($html, 'Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.') !== false, 'an unconnected site is not told');
}

function test_builders_cf7_the_tab_saves_as_a_form_property_only_when_it_was_posted() {
    $form = agm_cf7_form(7, 'Contact form 1', array());
    expect_same(array(), $form->prop('agumail_signup'), 'the property is not registered');
    agm_input(array(), array('agumail-signup' => array(
        'enabled' => '1', 'tags' => ' vip, <b>news</b> ', 'fields' => array('email' => 'your-email', 'phone' => 'bad name!', 'consent' => 'terms'),
    )));
    do_action('wpcf7_save_contact_form', $form, array(), 'save');
    expect_same(array(
        'enabled'       => '1',
        'tags'          => 'vip, news',
        'double_opt_in' => '',
        'fields'        => array('email' => 'your-email', 'first_name' => '', 'last_name' => '', 'full_name' => '', 'phone' => '', 'consent' => 'terms'),
    ), $form->prop('agumail_signup'), 'wrong saved settings');

    agm_input(array(), array());
    do_action('wpcf7_save_contact_form', $form, array(), 'save');
    expect_same('1', $form->prop('agumail_signup')['enabled'], 'a save without the tab (the REST API, say) wiped the settings');
}

// ── WPForms ──

function builders_wpforms_fields(array $extra = array()) {
    return array_merge(array(
        agm_wpforms_field(1, 'name', 'Name', 'Ada Lovelace', array('first' => 'Ada', 'middle' => '', 'last' => 'Lovelace')),
        agm_wpforms_field(2, 'email', 'Email', 'Ada@Example.com'),
        agm_wpforms_field(3, 'textarea', 'Comment', 'Hi'),
        agm_wpforms_field(4, 'gdpr-checkbox', 'GDPR Agreement', 'I consent to receiving offers'),
    ), $extra);
}

function builders_wpforms_form(array $settings = array()) {
    return agm_wpforms_form(42, 'Newsletter', array(), array_merge(array('agumail_enable' => '1', 'agumail_tags' => 'news', 'agumail_double_opt_in' => '1'), $settings));
}

function builders_wpforms_submit(array $form_data, array $fields, array $post = array(), $kind = 'shopper_ajax') {
    $response = null;
    builders_request('wpforms submit', $kind, function () use ($form_data, $fields, $post, &$response) {
        $response = agm_wpforms_submit($form_data, $fields, $post);
    });
    return $response;
}

function test_builders_wpforms_a_completed_submission_queues_the_signup() {
    builders_setup();
    builders_wpforms_submit(builders_wpforms_form(), builders_wpforms_fields());
    $signup = builders_only_signup();
    expect_same(array(
        'email'       => 'ada@example.com',
        'firstName'   => 'Ada',
        'lastName'    => 'Lovelace',
        'tags'        => array('news'),
        'doubleOptIn' => true,
        'form'        => array('builder' => 'wpforms', 'id' => '42', 'name' => 'Newsletter'),
        'pageUrl'     => 'https://shop.test/newsletter/',
        'ip'          => '203.0.113.9',
        'occurredAt'  => gmdate('Y-m-d\TH:i:s\Z', 1790000000),
    ), $signup, 'wrong signup (a GDPR box is always required, so it is not consent to marketing unless mapped)');
}

function test_builders_wpforms_a_simple_name_field_is_split() {
    builders_setup();
    builders_wpforms_submit(builders_wpforms_form(), array(
        agm_wpforms_field(1, 'name', 'Name', 'Ada King Lovelace', array('first' => '', 'middle' => '', 'last' => '')),
        agm_wpforms_field(2, 'email', 'Email', 'ada@example.com'),
        agm_wpforms_field(5, 'phone', 'Phone', '(555) 010-0100'),
    ));
    $signup = builders_only_signup();
    expect_same(array('Ada', 'King Lovelace', '(555) 010-0100'), array($signup['firstName'], $signup['lastName'], $signup['phone']), 'wrong name or phone');
    expect_that(!isset($signup['consentText']), 'a form without a consent box sent consent words');
}

function test_builders_wpforms_nothing_is_queued_when_off_or_marked_as_spam() {
    builders_setup();
    builders_wpforms_submit(agm_wpforms_form(42, 'Newsletter', array()), builders_wpforms_fields());
    builders_expect_nothing_queued('a form never set up for AguMail queued a signup');
    $spam = builders_wpforms_form();
    $spam['spam_reason'] = 'Akismet';
    builders_wpforms_submit($spam, builders_wpforms_fields());
    builders_expect_nothing_queued('a submission marked as spam queued a signup');
}

function test_builders_wpforms_a_mapped_gdpr_box_gives_the_consent_words() {
    builders_setup();
    builders_wpforms_submit(builders_wpforms_form(array('agumail_field_consent' => '4')), builders_wpforms_fields());
    expect_same('I consent to receiving offers', builders_only_signup()['consentText'], 'the mapped GDPR box is not the consent');
}

function test_builders_wpforms_a_mapped_consent_checkbox_left_unticked_queues_nothing() {
    builders_setup();
    $form = builders_wpforms_form(array('agumail_field_consent' => '6'));
    $fields = builders_wpforms_fields(array(agm_wpforms_field(6, 'checkbox', 'Newsletter', '')));
    builders_wpforms_submit($form, $fields);
    builders_expect_nothing_queued('an unticked mapped checkbox queued a signup');
    $fields[4]['value'] = 'Yes, send me offers';
    builders_wpforms_submit($form, $fields);
    expect_same('Yes, send me offers', builders_only_signup()['consentText'], 'the ticked checkbox is not the consent');
}

function test_builders_wpforms_the_mapping_wins_and_double_opt_in_can_be_off() {
    builders_setup();
    $form = builders_wpforms_form(array('agumail_field_email' => '7', 'agumail_field_name' => '8', 'agumail_double_opt_in' => null));
    unset($form['settings']['agumail_double_opt_in']);
    builders_wpforms_submit($form, builders_wpforms_fields(array(
        agm_wpforms_field(7, 'email', 'Other email', 'bea@example.com'),
        agm_wpforms_field(8, 'text', 'Call me', 'Bea Stone'),
    )));
    $signup = builders_only_signup();
    expect_same(array('bea@example.com', 'Bea', 'Stone', false), array($signup['email'], $signup['firstName'], $signup['lastName'], $signup['doubleOptIn']), 'the mapping or the saved switch was not followed');
}

function test_builders_wpforms_the_agumail_section_is_in_the_builder_settings() {
    $sections = apply_filters('wpforms_builder_settings_sections', array('general' => 'General'), array());
    expect_same(array('general' => 'General', 'agumail' => 'AguMail'), $sections, 'the AguMail section is missing');

    $panel = new Agm_WPForms_Settings_Panel(agm_wpforms_form(42, 'Newsletter', array()));
    $html = agm_capture(function () use ($panel) {
        do_action('wpforms_form_settings_panel_content', $panel);
    });
    expect_that(strpos($html, 'wpforms-panel-content-section-agumail') !== false, 'the section content is missing');
    $fields = $GLOBALS['agm']['wpforms_fields'];
    expect_same('toggle', $fields['agumail_enable']['option'], 'the switch is not a toggle');
    expect_same('text', $fields['agumail_tags']['option'], 'tags are not text');
    expect_same(0, $fields['agumail_double_opt_in']['value'], 'double opt-in should start off');
    $maps = array('agumail_field_email' => array('email'), 'agumail_field_name' => array('name', 'text'),
        'agumail_field_phone' => array('phone', 'text'), 'agumail_field_consent' => array('gdpr-checkbox', 'checkbox'));
    foreach ($maps as $field => $types) {
        expect_same('select', $fields[$field]['option'], "$field is not a select");
        expect_same($types, $fields[$field]['args']['field_map'], "$field lists the wrong field types");
        // The consent is never guessed on WPForms: a GDPR box is always required, a plain checkbox may be about anything.
        expect_same($field === 'agumail_field_consent' ? 'No consent box' : 'Detect automatically', $fields[$field]['args']['placeholder'], "$field has the wrong first choice");
    }
    expect_that(strpos($html, 'Separate tags with commas. New tags are created.') !== false, 'the tags help is missing');
}

function test_builders_wpforms_a_switched_on_double_opt_in_shows_on() {
    $panel = new Agm_WPForms_Settings_Panel(agm_wpforms_form(42, 'Newsletter', array(), array('agumail_enable' => '1', 'agumail_double_opt_in' => '1')));
    agm_capture(function () use ($panel) {
        do_action('wpforms_form_settings_panel_content', $panel);
    });
    expect_same(1, $GLOBALS['agm']['wpforms_fields']['agumail_double_opt_in']['value'], 'a saved-on double opt-in shows off');
}

// ── Recognising the visitor after a signup (tracking on) ──

function builders_sig($email) {
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $email, 'secret-for-identify', true)), '+/', '-_'), '=');
}

function test_builders_elementor_answers_with_who_signed_up_when_tracking_is_on() {
    builders_setup();
    builders_tracking();
    $handler = builders_elementor_run(agm_elementor_record(builders_elementor_fields()));
    expect_same(array('agumail' => array('email' => 'ada@example.com', 'sig' => builders_sig('ada@example.com'))), $handler->data, 'wrong identity in the answer');

    $handler = builders_elementor_run(agm_elementor_record(builders_elementor_fields('')));
    expect_same(array(), $handler->data, 'a visitor who did not sign up was named');

    update_option('agumail_tracking_enabled', 'no');
    $handler = builders_elementor_run(agm_elementor_record(builders_elementor_fields()));
    expect_same(array(), $handler->data, 'the visitor was named with tracking off');
}

function test_builders_store_staff_trying_a_form_are_not_named() {
    builders_setup();
    builders_tracking();
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    $handler = builders_elementor_run(agm_elementor_record(builders_elementor_fields()));
    expect_same(1, count(builders_signups()), 'a signup by staff should still be queued');
    expect_same(array(), $handler->data, 'staff were named to the AguMail script');
}

function test_builders_a_session_past_the_capture_limit_is_not_named() {
    builders_setup();
    builders_tracking();
    WC()->session->set(AGUMAIL_SESSION_CAPTURES, AGUMAIL_CAPTURE_LIMIT);
    $handler = builders_elementor_run(agm_elementor_record(builders_elementor_fields()));
    expect_same(1, count(builders_signups()), 'the signup should still be queued');
    expect_same(array(), $handler->data, 'a session past the capture limit got a signature');
    expect_same(AGUMAIL_CAPTURE_LIMIT, WC()->session->get(AGUMAIL_SESSION_CAPTURES), 'the check changed the session');
}

function test_builders_an_address_past_the_capture_limit_is_not_named() {
    builders_setup();
    builders_tracking();
    // An address that started as many carts in the last hour as capture allows.
    for ($i = 0; $i < AGUMAIL_CAPTURE_ADDRESS_CARTS; $i++) {
        agumail_cart_save('wc-busy-' . $i, array('email' => 'x' . $i . '@example.com', 'ip_hash' => agumail_capture_address_hash()));
    }
    $response = builders_cf7_submit(builders_cf7_form(), builders_cf7_posted());
    expect_same(1, count(builders_signups()), 'the signup should still be queued');
    expect_that(!isset($response['agumail']), 'an address past the capture limit got a signature');
}

function test_builders_an_address_past_the_hourly_limit_of_a_persistent_cache_is_not_named() {
    builders_setup();
    builders_tracking();
    $GLOBALS['agm']['ext_object_cache'] = true;
    wp_cache_set('capture_' . md5('203.0.113.9') . '_' . (int) floor(agumail_time() / HOUR_IN_SECONDS), AGUMAIL_CAPTURE_ADDRESS_LIMIT, 'agumail');
    $response = builders_wpforms_submit(builders_wpforms_form(), builders_wpforms_fields());
    expect_same(1, count(builders_signups()), 'the signup should still be queued');
    expect_same(array('confirmation' => '<p>Thanks!</p>'), $response, 'an address past the hourly limit got a signature');
}

function test_builders_cf7_and_wpforms_answer_with_who_signed_up_when_tracking_is_on() {
    builders_setup();
    builders_tracking();
    $response = builders_cf7_submit(builders_cf7_form(), builders_cf7_posted());
    expect_same(array('email' => 'ada@example.com', 'sig' => builders_sig('ada@example.com')), $response['agumail'], 'wrong identity in the CF7 answer');
    expect_same('mail_sent', $response['status'], 'the CF7 answer lost its own fields');

    $response = builders_wpforms_submit(builders_wpforms_form(), builders_wpforms_fields(array(agm_wpforms_field(9, 'text', 'Other', 'x'))));
    expect_same(array('email' => 'ada@example.com', 'sig' => builders_sig('ada@example.com')), $response['agumail'], 'wrong identity in the WPForms answer');
    expect_same('<p>Thanks!</p>', $response['confirmation'], 'the WPForms answer lost its confirmation');
}

function test_builders_cf7_and_wpforms_answers_stay_as_they_were_without_a_signup() {
    builders_setup();
    builders_tracking();
    $news = agm_cf7_tag('acceptance', 'newsletter', array('optional', 'consent_for:agumail'), 'Send me offers');
    $response = builders_cf7_submit(builders_cf7_form(null, array($news)), builders_cf7_posted(array('newsletter' => '')));
    expect_that(!isset($response['agumail']), 'CF7 named a visitor who did not sign up');
    $response = builders_wpforms_submit(agm_wpforms_form(42, 'Newsletter', array()), builders_wpforms_fields());
    expect_same(array('confirmation' => '<p>Thanks!</p>'), $response, 'WPForms named a visitor on a form without AguMail');
}

function test_builders_the_page_passes_a_signup_to_the_agumail_script() {
    define('WPCF7_VERSION', '6.1.7');
    builders_tracking();
    agm_request('storefront page', 'shopper', function () {
        do_action('init');
        do_action('wp_enqueue_scripts');
    });
    $inline = implode("\n", agm_script('agumail-site')['inline']);
    foreach (array('"submit_success"', '"wpcf7mailsent"', '"wpcf7mailfailed"', '"wpformsAjaxSubmitSuccess"', 'window.agumail.identify(d.email,d.sig)', 'agumail-site-js') as $needle) {
        expect_that(strpos($inline, $needle) !== false, "the listener lacks $needle: $inline");
    }
}

function test_builders_no_listener_without_a_form_plugin_or_with_tracking_off() {
    builders_tracking();
    agm_request('storefront page', 'shopper', function () {
        do_action('wp_enqueue_scripts');
    });
    expect_that(strpos(implode("\n", agm_script('agumail-site')['inline']), 'wpcf7mailsent') === false, 'the listener was added with no form plugin active');

    define('WPFORMS_VERSION', '2.0.2.1');
    update_option('agumail_tracking_enabled', 'no');
    $GLOBALS['agm']['scripts'] = array();
    agm_request('storefront page', 'shopper', function () {
        do_action('wp_enqueue_scripts');
    });
    expect_same(null, agm_script('agumail-site'), 'the AguMail script was added with tracking off');
}
