<?php
// Form signups: the record a form plugin's submission becomes, the one outbox
// write that queues it, and how the drain sends it to /api/v1/signups. The
// builder adapters themselves are in builders.php.

function signups_setup($now = 1790000000) {
    $GLOBALS['agm']['clock'] = (float) $now;
    agumail_set_clock(function () { return $GLOBALS['agm']['clock']; });
}

function signups_form($id = '12:abc123', $name = 'Welcome popup') {
    return array('id' => $id, 'name' => $name);
}

/** One signup queued from a storefront request, as a form plugin's AJAX submit would. */
function signups_submit($builder, array $form, array $data, $kind = 'shopper_ajax') {
    $queued = null;
    $result = agm_request($builder . ' form submitted', $kind, function () use ($builder, $form, $data, &$queued) {
        $queued = agumail_signup_enqueue($builder, $form, $data);
    });
    $result['queued'] = $queued;
    return $result;
}

function signups_rows() {
    return agm_outbox('signup');
}

// ── The record ──

function test_signups_a_record_carries_what_the_contract_names() {
    signups_setup();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $record = agumail_signup_record('elementor', signups_form(), array(
        'email'         => ' Ada@Example.COM ',
        'first_name'    => 'Ada',
        'last_name'     => 'Lovelace',
        'phone'         => '+44 20 7946 0000',
        'tags'          => 'Popup, VIP',
        'double_opt_in' => true,
        'consent_text'  => 'Email me about <a href="/offers">offers</a>',
        'page_url'      => 'https://shop.test/sale/?utm_source=ig',
    ));
    expect_same(array(
        'email'       => 'ada@example.com',
        'firstName'   => 'Ada',
        'lastName'    => 'Lovelace',
        'phone'       => '+44 20 7946 0000',
        'tags'        => array('Popup', 'VIP'),
        'doubleOptIn' => true,
        'form'        => array('builder' => 'elementor', 'id' => '12:abc123', 'name' => 'Welcome popup'),
        'pageUrl'     => 'https://shop.test/sale/?utm_source=ig',
        'ip'          => '203.0.113.9',
        'consentText' => 'Email me about offers',
        'occurredAt'  => gmdate('Y-m-d\TH:i:s\Z', 1790000000),
    ), $record, 'wrong record');
}

function test_signups_a_record_is_clamped_to_the_limits_agumail_takes() {
    signups_setup();
    $_SERVER['REMOTE_ADDR'] = 'not an address';
    $tags = array('', ' a ', 'a', str_repeat('t', 70));
    for ($i = 0; $i < 30; $i++) {
        $tags[] = 'tag' . $i;
    }
    $record = agumail_signup_record('cf7', array('id' => str_repeat('9', 150), 'name' => str_repeat('n', 250)), array(
        'email'         => 'ada@example.com',
        'first_name'    => str_repeat('f', 150),
        'last_name'     => str_repeat('l', 150),
        'phone'         => str_repeat('1', 60),
        'tags'          => $tags,
        'double_opt_in' => 'yes',
        'consent_text'  => str_repeat('c', 1200),
        'page_url'      => 'https://shop.test/?' . str_repeat('q', 900),
    ));
    expect_same(100, strlen($record['firstName']), 'the first name was not cut at 100');
    expect_same(100, strlen($record['lastName']), 'the last name was not cut at 100');
    expect_same(40, strlen($record['phone']), 'the phone was not cut at 40');
    expect_same(20, count($record['tags']), 'more than 20 tags were kept');
    expect_same(array('a', str_repeat('t', 60), 'tag0'), array_slice($record['tags'], 0, 3), 'tags were not trimmed, deduplicated and cut at 60');
    expect_same(100, strlen($record['form']['id']), 'the form id was not cut at 100');
    expect_same(200, strlen($record['form']['name']), 'the form name was not cut at 200');
    expect_same(1000, strlen($record['consentText']), 'the consent words were not cut at 1000');
    expect_that(!isset($record['pageUrl']), 'a page address too long to keep whole was sent cut');
    expect_that(!isset($record['ip']), 'something that is not an IP address was sent as one');
    expect_same(true, $record['doubleOptIn'], 'double opt-in "yes" is not true');
}

function test_signups_empty_values_are_left_out_and_a_nameless_form_is_named_by_its_id() {
    signups_setup();
    unset($_SERVER['REMOTE_ADDR']);
    $record = agumail_signup_record('wpforms', array('id' => '42', 'name' => '  '), array(
        'email'    => 'bea@example.com',
        'page_url' => 'javascript:alert(1)',
        'tags'     => ' , ',
    ));
    expect_same(array(
        'email'       => 'bea@example.com',
        'tags'        => array(),
        'doubleOptIn' => false,
        'form'        => array('builder' => 'wpforms', 'id' => '42', 'name' => '42'),
        'occurredAt'  => gmdate('Y-m-d\TH:i:s\Z', 1790000000),
    ), $record, 'empty values should be left out');
}

function test_signups_what_is_not_a_signup_makes_no_record() {
    signups_setup();
    expect_same(null, agumail_signup_record('elementor', signups_form(), array('email' => 'not-an-email')), 'an invalid address made a record');
    expect_same(null, agumail_signup_record('gravity', signups_form(), array('email' => 'ada@example.com')), 'an unknown builder made a record');
    expect_same(null, agumail_signup_record('cf7', array('id' => ' ', 'name' => 'x'), array('email' => 'ada@example.com')), 'a form without an id made a record');
}

function test_signups_a_full_name_is_split_into_first_word_and_the_rest() {
    expect_same(array('Ada', 'King Lovelace'), agumail_signup_split_name('  Ada  King Lovelace '), 'wrong split');
    expect_same(array('Cher', ''), agumail_signup_split_name('Cher'), 'a single name should be the first name');
    expect_same(array('', ''), agumail_signup_split_name('   '), 'an empty name should split into nothing');

    signups_setup();
    $record = agumail_signup_record('cf7', signups_form(), array('email' => 'ada@example.com', 'full_name' => 'Ada King Lovelace'));
    expect_same(array('Ada', 'King Lovelace'), array($record['firstName'], $record['lastName']), 'the full name was not split');
    $record = agumail_signup_record('cf7', signups_form(), array('email' => 'ada@example.com', 'first_name' => 'Augusta', 'full_name' => 'Ada King'));
    expect_same('Augusta', $record['firstName'], 'a first name given was replaced by the full name');
    expect_that(!isset($record['lastName']), 'the full name filled the last name next to a first name given');
}

function test_signups_tags_come_from_a_comma_separated_list() {
    expect_same(array('a', 'b', 'c'), agumail_signup_parse_tags(' a, b,,a , c '), 'wrong tags from a list');
    expect_same(array('x', 'y'), agumail_signup_parse_tags(array('x', ' y ', '', 'x')), 'wrong tags from an array');
    expect_same(array(), agumail_signup_parse_tags(null), 'no tags should be none');
}

function test_signups_a_consent_tick_is_read_like_a_checkbox() {
    foreach (array('1', 'on', 'yes', 'I agree', 1, true, array('Yes')) as $ticked) {
        expect_same(true, agumail_signup_consent_ticked($ticked), 'not read as ticked: ' . var_export($ticked, true));
    }
    foreach (array('', '0', 'no', 'off', 'false', 0, false, null, array(), array('')) as $unticked) {
        expect_same(false, agumail_signup_consent_ticked($unticked), 'read as ticked: ' . var_export($unticked, true));
    }
}

// ── Queueing ──

function test_signups_queueing_is_one_write_and_no_call() {
    signups_setup();
    $result = signups_submit('elementor', signups_form(), array('email' => 'Ada@Example.com', 'tags' => 'popup', 'double_opt_in' => true));
    expect_same(true, $result['queued'], 'the signup was not queued');
    expect_same(array(), $result['http'], 'queueing a signup called out');
    expect_same(1, count($result['writes']), 'queueing a signup should be exactly one write');
    expect_same('outbox', $result['writes'][0]['kind'], 'the write was not the outbox');
    $rows = signups_rows();
    expect_same(1, count($rows), 'expected one queued signup');
    expect_same('elementor:12:abc123:ada@example.com', $rows[0]['object_key'], 'wrong key');
    expect_same('ada@example.com', $rows[0]['payload']['email'], 'the payload is not the record');
    expect_same(array('popup'), $rows[0]['payload']['tags'], 'the payload lost its tags');
}

function test_signups_a_double_submit_collapses_into_one_row() {
    signups_setup();
    signups_submit('cf7', array('id' => '7', 'name' => 'Contact'), array('email' => 'ada@example.com', 'first_name' => 'Ada'));
    signups_submit('cf7', array('id' => '7', 'name' => 'Contact'), array('email' => 'ADA@example.com', 'first_name' => 'Ada L'));
    $rows = signups_rows();
    expect_same(1, count($rows), 'a double submit made two rows');
    expect_same(2, $rows[0]['version'], 'the second submit did not bump the row');
    expect_same('Ada L', $rows[0]['payload']['firstName'], 'the newer submit should win');
    signups_submit('cf7', array('id' => '8', 'name' => 'Other'), array('email' => 'ada@example.com'));
    expect_same(2, count(signups_rows()), 'another form should be its own row');
}

function test_signups_a_long_key_is_shortened_so_it_stays_unique() {
    signups_setup();
    $email = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.example.com';
    signups_submit('elementor', array('id' => str_repeat('1', 100), 'name' => 'Long'), array('email' => $email));
    $rows = signups_rows();
    expect_same(1, count($rows), 'the long signup was not queued');
    expect_that(strlen($rows[0]['object_key']) <= 191, 'the key does not fit the column');
    expect_same($email, $rows[0]['payload']['email'], 'the record lost its address');
}

function test_signups_nothing_is_queued_while_the_store_is_not_connected() {
    signups_setup();
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $result = signups_submit('wpforms', array('id' => '42', 'name' => 'Newsletter'), array('email' => 'ada@example.com'));
    expect_same(false, $result['queued'], 'a signup was queued with no store connected');
    expect_same(array(), $result['writes'], 'an unconnected store wrote something');
}

function test_signups_queueing_never_throws() {
    signups_setup();
    agumail_set_store(new class extends Agm_Fake_Store {
        public function enqueue($type, $key, $payload, $now) {
            throw new RuntimeException('database gone');
        }
    });
    $result = signups_submit('elementor', signups_form(), array('email' => 'ada@example.com'));
    expect_same(false, $result['queued'], 'a failed write was reported as queued');
    expect_same(null, $result['halt'], 'the failure reached the visitor');
    expect_same(1, count(agm_logs('database gone')), 'the failure was not logged');
    expect_same(false, agumail_signup_enqueue('elementor', signups_form(), array('email' => new stdClass())), 'odd input did not come back false');
}

// ── Sending ──

function test_signups_are_sent_to_the_signups_endpoint_without_a_contact_first() {
    signups_setup();
    $builder = agumail_builder('signup');
    expect_same('/api/v1/signups', $builder['endpoint'], 'wrong endpoint');
    expect_same('signups', $builder['collection'], 'wrong collection');
    expect_same(15, $builder['phase'], 'signups should go after contacts and before products');
    expect_same(100, $builder['batch'], 'wrong batch size');
    expect_same(null, $builder['contacts'], 'a signup must never pass through the contacts sync');

    signups_submit('elementor', signups_form(), array('email' => 'ada@example.com', 'tags' => 'popup'));
    agumail_enqueue('contact', 'bea@example.com', agumail_contact_payload('bea@example.com'));
    agumail_enqueue('product', 11);
    agm_drain();
    $paths = array_map(function ($request) { return $request['path']; }, agm_http_requests());
    expect_same('/api/v1/contacts', $paths[0], 'contacts should go first: ' . implode(', ', $paths));
    expect_same('/api/v1/signups', $paths[1], 'signups should go second: ' . implode(', ', $paths));
    $sent = agm_http_requests('/api/v1/signups');
    expect_same(1, count($sent), 'expected one signups request');
    expect_same(array('ada@example.com'), array_column($sent[0]['body']['signups'], 'email'), 'wrong signups sent');
    expect_that(!isset($sent[0]['body']['backfill']), 'live signups were sent as history');
    $contacts = agm_sent_records('/api/v1/contacts', 'contacts');
    expect_same(array('bea@example.com'), array_column($contacts, 'email'), 'the signup went through the contacts sync too');
    expect_same(array(), signups_rows(), 'the sent signup stayed queued');
}

function test_signups_a_400_naming_one_signup_parks_just_that_one() {
    signups_setup();
    foreach (array('a', 'b', 'c') as $who) {
        signups_submit('wpforms', array('id' => '42', 'name' => 'Newsletter'), array('email' => $who . '@example.com'));
    }
    agm_http_respond(400, array('ok' => false, 'error' => 'signups.1.email: Invalid email'));
    agm_drain();
    $sent = agm_http_requests('/api/v1/signups');
    expect_same(2, count($sent), 'the rest of the batch was not sent again');
    expect_same(array('a@example.com', 'c@example.com'), array_column($sent[1]['body']['signups'], 'email'), 'the resend should leave out the refused signup');
    $rows = signups_rows();
    expect_same(1, count($rows), 'only the refused signup should stay');
    expect_same(array('wpforms:42:b@example.com', 'failed', 'signups.1.email: Invalid email'), array($rows[0]['object_key'], $rows[0]['status'], $rows[0]['last_error']), 'the refused signup was not parked');
}

function test_signups_capacity_and_the_visitor_limit_are_logged() {
    signups_setup();
    signups_submit('cf7', array('id' => '7', 'name' => 'Contact'), array('email' => 'ada@example.com'));
    agm_http_respond(200, array('ok' => true, 'received' => 1, 'overCapacity' => 1, 'limited' => 0));
    agm_drain();
    expect_same(1, count(agm_logs('contact limit')), 'a signup over the plan was not logged');
    expect_same('warning', agm_logs('contact limit')[0]['level'], 'wrong level');

    signups_submit('cf7', array('id' => '7', 'name' => 'Contact'), array('email' => 'bea@example.com'));
    agm_http_respond(200, array('ok' => true, 'received' => 1, 'overCapacity' => 0, 'limited' => 1));
    agm_drain();
    expect_same(1, count(agm_logs('too many signups')), 'a signup over the visitor limit was not logged');

    signups_submit('cf7', array('id' => '7', 'name' => 'Contact'), array('email' => 'cy@example.com'));
    $before = count($GLOBALS['agm']['logs']);
    agm_drain();
    expect_same(0, count(array_filter(array_slice($GLOBALS['agm']['logs'], $before), function ($entry) { return $entry['level'] === 'warning'; })), 'a clean answer logged a warning');
}

// ── Settings ──

function test_signups_the_form_plugins_section_shows_only_when_a_builder_is_active() {
    agm_p4_merchant();
    expect_that(!isset(agumail_admin_sections()['signups']), 'the section shows with no form plugin active');
}

function test_signups_the_form_plugins_section_names_each_active_builder() {
    define('WPCF7_VERSION', '6.1.7');
    define('WPFORMS_VERSION', '2.0.2.1');
    agm_p4_merchant();
    $sections = agumail_admin_sections();
    expect_that(isset($sections['signups']), 'the section is missing');
    expect_same('Form plugins', $sections['signups']['title'], 'wrong title');
    expect_same(65, $sections['signups']['priority'], 'wrong place on the page');
    $html = agm_p4_section('signups');
    expect_that(strpos($html, 'Contact Form 7') !== false, 'Contact Form 7 is not named');
    expect_that(strpos($html, 'WPForms') !== false, 'WPForms is not named');
    expect_that(strpos($html, 'Elementor') === false, 'an inactive builder is named');
    expect_that(strpos($html, '<form') === false && strpos($html, '<input') === false, 'the section should be read-only');
}

function test_signups_the_form_plugins_section_names_gravity_forms_when_it_is_active() {
    require_once dirname(__DIR__) . '/fakes/builders/gravityforms.php';
    agm_gf_activate();
    agm_p4_merchant();
    $html = agm_p4_section('signups');
    expect_that(strpos($html, 'Gravity Forms: edit the form, open Settings &gt; AguMail and switch on Send signups to AguMail.') !== false, 'Gravity Forms is not named: ' . $html);
    expect_that(strpos($html, 'WPForms') === false, 'an inactive builder is named');
}

function test_signups_the_form_plugins_section_says_when_the_store_is_not_connected() {
    define('ELEMENTOR_PRO_VERSION', '4.0.3');
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    agm_p4_merchant();
    $html = agm_p4_section('signups');
    expect_that(strpos($html, 'Elementor Pro') !== false, 'Elementor Pro is not named');
    expect_that(strpos($html, 'Connect to AguMail') !== false, 'an unconnected store is not told to connect: ' . $html);
}

// ── House rules ──

function test_signups_the_new_files_have_no_em_dash($plugin_dir) {
    $files = array_merge(
        array($plugin_dir . '/includes/signups.php'),
        glob($plugin_dir . '/includes/integrations/*.php'),
        glob($plugin_dir . '/assets/js/elementor-editor.js'),
        glob(dirname(__DIR__) . '/scenarios/{signups,builders}.php', GLOB_BRACE),
        glob(dirname(__DIR__) . '/fakes/builders/*.php'),
        glob(dirname(dirname(__DIR__)) . '/e2e/specs/builder-signups.spec.ts'),
        glob(dirname(dirname(__DIR__)) . '/e2e/wp/builders.php')
    );
    $dash = "\u{2014}";
    $found = array();
    foreach ($files as $file) {
        if (is_file($file) && strpos(file_get_contents($file), $dash) !== false) {
            $found[] = $file;
        }
    }
    expect_same(array(), $found, 'em-dash found');
    expect_that(count($files) >= 5, 'the scan found too few files: ' . implode(', ', $files));
}
