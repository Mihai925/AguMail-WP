<?php
// Connecting the store. The merchant presses "Connect to AguMail", approves in
// the app, and comes back with a code the plugin exchanges for a key (PKCE: the
// verifier never leaves this site until the exchange). "Advanced" keeps the
// pasted key and API address as a fallback. Afterwards ping keeps the config
// fresh, daily and when the settings page is opened, and never connects again.

// ── Helpers ──

/** The config as AguMail hands it out: the site script on its pages domain, the rest on its API. */
function connect_config() {
    return array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://agupage.com/_/v1/site.js?v=3',
        'siteEndpoint'   => 'https://api.agumail.com/api/public/site/site_abc123/events',
        'formsLoaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js',
        'identifySecret' => '0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg',
    );
}

function connect_exchange_ok() {
    return array(
        'ok'            => true,
        'apiKey'        => 'agm_' . str_repeat('b', 40),
        'userId'        => 'usr_1',
        'shopName'      => 'Hollow Pine',
        'integrationId' => 'int_1',
        'config'        => connect_config(),
    );
}

function connect_ping_body($integration = array('status' => 'connected', 'storeUrl' => 'shop.test'), $config = null) {
    return array(
        'ok'          => true,
        'shop'        => array('userId' => 'usr_1', 'shopName' => 'Hollow Pine'),
        'integration' => $integration,
        'config'      => $config === null ? connect_config() : $config,
    );
}

function connect_forms_body() {
    return array('ok' => true, 'loaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js', 'forms' => array(
        array('slug' => 'fspring', 'name' => 'Spring', 'doubleOptIn' => true, 'publishedAt' => '2026-09-01T10:00:00Z'),
    ));
}

/** A store manager, signed in, on a site that has not connected yet. */
function connect_merchant($connected = false) {
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    if (!$connected) {
        unset($GLOBALS['agm']['options']['agumail_api_key']);
    }
    do_action('init');
}

function connect_admin_action($action, array $fields = array()) {
    return agm_request('admin: ' . $action, 'admin', function () use ($action, $fields) {
        agm_input(array(), array_merge(array(
            'action'         => 'agumail',
            'agumail_action' => $action,
            '_wpnonce'       => agumail_admin_nonce($action),
        ), $fields));
        do_action('admin_post_agumail');
    });
}

/** Presses the Connect button; returns where the merchant was sent and its query. */
function connect_start() {
    $result = connect_admin_action('connect_start');
    $redirect = end($GLOBALS['agm']['redirects']);
    expect_that($redirect, 'pressing Connect sent the merchant nowhere');
    $query = array();
    parse_str((string) parse_url($redirect['location'], PHP_URL_QUERY), $query);
    return array('result' => $result, 'location' => $redirect['location'], 'query' => $query);
}

/** AguMail sends the merchant back to the settings page with a code and the state. */
function connect_callback(array $get, $kind = 'admin') {
    return agm_request('connect callback', $kind, function () use ($get) {
        agm_input(array_merge(array('page' => 'agumail', 'agumail_connect' => 'callback'), $get));
        do_action('admin_init');
    });
}

function connect_open_settings_page($kind = 'admin') {
    return agm_request('settings page', $kind, function () {
        agm_input(array('page' => 'agumail'));
        do_action('admin_init');
    });
}

function connect_section_html() {
    $sections = agumail_admin_sections();
    expect_that(isset($sections['connection']), 'there is no Connection section');
    return agm_capture(function () use ($sections) {
        call_user_func($sections['connection']['render']);
    });
}

function connect_notice() {
    return get_transient('agumail_notice_' . get_current_user_id());
}

function connect_b64url($bytes) {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function connect_paths(array $requests) {
    return array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $requests);
}

// ── Not connected ──

function test_connect_the_page_offers_one_click_connect_and_a_collapsed_advanced_section() {
    connect_merchant();
    $html = connect_section_html();
    expect_that(preg_match('/<button[^>]*button-primary[^>]*>Connect to AguMail<\/button>/', $html) === 1, 'no primary "Connect to AguMail" button: ' . $html);
    expect_that(strpos($html, 'value="connect_start"') !== false, 'the Connect button does not post to the start action');
    expect_that(preg_match('/<details[^>]*>\s*<summary[^>]*>Advanced<\/summary>/', $html) === 1, 'no collapsed "Advanced" section');
    expect_that(preg_match('/<details[^>]*\bopen\b/', $html) === 0, 'Advanced should start collapsed');
    expect_that(strpos($html, 'Disconnect') === false, 'a store that never connected is offered Disconnect');
    expect_that(strpos($html, '—') === false, 'UI copy carries an em-dash');
    // Somebody who found the plugin first starts a free account, set up for a store.
    expect_that(strpos($html, 'Create your free AguMail account') !== false, 'no free account offered');
    expect_that(strpos($html, 'https://agumail.com/signup?for=store&amp;utm_source=wordpress&amp;utm_medium=plugin&amp;utm_campaign=connect-welcome') !== false,
        'the free account is not a store\'s: ' . $html);
}

// ── One-click connect: the start ──

function test_connect_start_sends_the_merchant_to_the_app_with_state_and_a_pkce_challenge() {
    connect_merchant();
    $start = connect_start();
    $parts = parse_url($start['location']);
    expect_same('https', $parts['scheme'], 'the app is not reached over https');
    expect_same('agumail.com', $parts['host'], 'the merchant was not sent to the AguMail app');
    expect_same('/connect/woocommerce', $parts['path'], 'wrong app page');
    expect_same(array('store', 'return', 'state', 'challenge'), array_keys($start['query']), 'wrong query parameters');
    expect_same('https://shop.test', $start['query']['store'], 'the store address is wrong');
    expect_same('https://shop.test/wp-admin/admin.php?page=agumail&agumail_connect=callback', $start['query']['return'], 'the return address is wrong');

    $pending = get_transient('agumail_connect_1');
    expect_that(is_array($pending), 'the state and verifier were not kept for this merchant');
    expect_same(32, strlen($start['query']['state']), 'the state is not 32 characters');
    expect_that(hash_equals($pending['state'], $start['query']['state']), 'the state sent is not the state kept');
    $verifier = $pending['verifier'];
    expect_that(strlen($verifier) >= 43 && strlen($verifier) <= 128, 'the verifier is not 43 to 128 characters: ' . strlen($verifier));
    expect_that(preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1, 'the verifier uses characters PKCE does not allow');
    expect_same(connect_b64url(hash('sha256', $verifier, true)), $start['query']['challenge'], 'the challenge is not base64url(sha256(verifier))');
    expect_that(strpos($start['location'], $verifier) === false, 'the verifier itself left the site');

    $expires = $GLOBALS['agm']['transients']['agumail_connect_1'][1];
    expect_that(abs($expires - (time() + HOUR_IN_SECONDS)) <= 2, 'the start should be kept for 60 minutes, as long as AguMail keeps its side');
    // The plugin encodes the query itself: WordPress's add_query_arg() leaves values raw.
    expect_that(strpos($start['location'], 'return=https%3A%2F%2Fshop.test%2Fwp-admin%2Fadmin.php%3Fpage%3Dagumail%26agumail_connect%3Dcallback&') !== false, 'the return address was not encoded into the query: ' . $start['location']);
    expect_that(strpos($start['location'], 'store=https%3A%2F%2Fshop.test&') !== false, 'the store address was not encoded into the query: ' . $start['location']);
    expect_same(array(), $start['result']['http'], 'starting a connection called AguMail');
}

function test_connect_every_start_is_fresh() {
    connect_merchant();
    $first = connect_start();
    $second = connect_start();
    expect_that($first['query']['state'] !== $second['query']['state'], 'the state was reused');
    expect_that($first['query']['challenge'] !== $second['query']['challenge'], 'the verifier was reused');
    expect_same($second['query']['state'], get_transient('agumail_connect_1')['state'], 'the latest start is not the one kept');
}

function test_connect_start_needs_a_store_manager() {
    connect_merchant();
    agm_grant('manage_woocommerce', false);
    $result = connect_admin_action('connect_start');
    expect_same(false, get_transient('agumail_connect_1'), 'a start was kept for somebody who may not manage the store');
    expect_that(strpos((string) $result['halt'], 'wp_die') === 0, 'the request was not refused');
}

// ── One-click connect: the callback ──

function test_connect_callback_exchanges_the_code_and_stores_the_connection() {
    connect_merchant();
    $start = connect_start();
    $verifier = get_transient('agumail_connect_1')['verifier'];
    agm_http_respond(200, connect_exchange_ok());
    agm_http_respond(200, connect_forms_body());

    $result = connect_callback(array('code' => 'c1.secret-payload.sig', 'state' => $start['query']['state']));

    expect_same(array('POST /api/v1/connect/exchange', 'GET /api/v1/forms'), connect_paths($result['http']), 'the code was not exchanged once, then the forms fetched');
    expect_same('Bearer agm_' . str_repeat('b', 40), $result['http'][1]['headers']['Authorization'], 'the forms were not fetched with the new key');
    expect_same('fspring', get_option('agumail_forms_cache')['forms'][0]['slug'], 'the forms were not cached after connecting');
    $sent = $result['http'][0];
    expect_that(!isset($sent['headers']['Authorization']), 'the exchange sent an API key');
    expect_same(true, $sent['context']['admin'] && !$sent['context']['ajax'], 'the exchange did not run on the merchant\'s own admin page');
    expect_same(array('code' => 'c1.secret-payload.sig', 'verifier' => $verifier, 'storeUrl' => 'https://shop.test', 'currency' => 'EUR'), $sent['body'], 'wrong exchange body');

    expect_same('agm_' . str_repeat('b', 40), get_option('agumail_api_key'), 'the new key was not stored');
    expect_same(connect_config(), get_option('agumail_config'), 'the config was not stored');
    $connection = agumail_connection();
    expect_same('connected', $connection['status'], 'the connection is not marked connected');
    expect_same('Hollow Pine', $connection['shopName'], 'the shop name was not stored');
    expect_same('usr_1', $connection['userId'], 'the account was not stored');
    expect_same('int_1', $connection['integrationId'], 'the integration was not stored');
    expect_same('shop.test', $connection['siteHost'], 'the address the store connected from was not recorded');
    expect_that(abs((int) get_option('agumail_connected_at') - time()) <= 2, 'agumail_connected_at was not set');
    expect_same(false, get_transient('agumail_connect_1'), 'the start was not thrown away after use');

    expect_same('https://shop.test/wp-admin/admin.php?page=agumail', end($GLOBALS['agm']['redirects'])['location'], 'the code was left in the address bar');
    $notice = connect_notice();
    expect_same('success', $notice['type'], 'no success notice');
    expect_that(strpos($notice['message'], 'Hollow Pine') !== false, 'the notice does not name the account: ' . $notice['message']);
    foreach ($GLOBALS['agm']['logs'] as $entry) {
        expect_that(strpos(json_encode($entry), 'secret-payload') === false, 'the code reached the log: ' . $entry['message']);
        expect_that(strpos(json_encode($entry), $verifier) === false, 'the verifier reached the log: ' . $entry['message']);
    }

    $html = connect_section_html();
    expect_that(strpos($html, 'Sync store data now') !== false, 'the merchant is not offered a sync after connecting');
    expect_that(strpos($html, 'Hollow Pine') !== false, 'the connected page does not name the account');
}

function test_connect_the_sync_offer_links_to_the_bulk_sync_action_when_it_exists() {
    connect_merchant();
    agumail_admin_register_action('bulk_sync_start', function () { return 'Started.'; });
    $start = connect_start();
    agm_http_respond(200, connect_exchange_ok());
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    $html = connect_section_html();
    expect_that(strpos($html, 'value="bulk_sync_start"') !== false, 'the sync offer does not post to the bulk sync action');
    expect_that(preg_match('/<button[^>]*>Sync store data now<\/button>/', $html) === 1, 'no "Sync store data now" button');
    expect_that(strpos(connect_section_html(), 'Sync store data now') === false, 'the offer should be shown once');
}

function test_connect_callback_refuses_a_state_that_does_not_match() {
    connect_merchant();
    connect_start();
    $result = connect_callback(array('code' => 'c1.p.s', 'state' => str_repeat('x', 32)));
    expect_same(array(), $result['http'], 'a callback with the wrong state was exchanged');
    expect_same('', agumail_api_key(), 'a key was stored');
    expect_same(false, get_transient('agumail_connect_1'), 'the start outlived a failed callback');
    expect_same('error', connect_notice()['type'], 'the mismatch was not reported');
}

function test_connect_callback_refuses_an_expired_or_missing_start() {
    connect_merchant();
    $start = connect_start();
    $GLOBALS['agm']['transients']['agumail_connect_1'][1] = time() - 1;
    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'an expired start was exchanged');
    $notice = connect_notice();
    expect_same('error', $notice['type'], 'the expiry was not reported');
    expect_that(stripos($notice['message'], 'start again') !== false || stripos($notice['message'], 'try again') !== false, 'the merchant is not told to start again: ' . $notice['message']);

    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'a callback with no start at all was exchanged');
}

function test_connect_callback_without_a_code_is_reported() {
    connect_merchant();
    $start = connect_start();
    $result = connect_callback(array('state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'a callback without a code called AguMail');
    expect_same('error', connect_notice()['type'], 'a callback without a code was not reported');
    expect_that(strpos(connect_notice()['message'], 'not approved') !== false, 'wrong message for a missing code: ' . connect_notice()['message']);
    expect_same(false, get_transient('agumail_connect_1'), 'the start outlived a callback without a code');
}

function test_connect_cancelling_in_the_app_says_so_and_spends_the_start() {
    connect_merchant();
    $start = connect_start();
    $result = connect_callback(array('error' => 'access_denied', 'state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'a cancelled connection called AguMail');
    expect_same(array('type' => 'info', 'message' => 'Connection cancelled. Press Connect to AguMail when you\'re ready.'), connect_notice(), 'the cancellation was not reported plainly');
    expect_same(false, get_transient('agumail_connect_1'), 'the start outlived the cancellation');
    expect_same('https://shop.test/wp-admin/admin.php?page=agumail', end($GLOBALS['agm']['redirects'])['location'], 'the cancel parameters were left in the address bar');

    // A cancel without a matching state is no proof of anything: it is refused like any other.
    connect_start();
    connect_callback(array('error' => 'access_denied', 'state' => str_repeat('x', 32)));
    expect_same('error', connect_notice()['type'], 'a cancel with the wrong state was taken at its word');
}

function test_connect_callback_shows_exchange_errors_plainly() {
    connect_merchant();
    $cases = array(
        array(array(400, array('ok' => false, 'error' => 'This connection link has expired. Start again from WordPress.')), 'This connection link has expired. Start again from WordPress.'),
        array(array(402, array('ok' => false, 'error' => 'Your plan connects 1 store. Disconnect the other one in AguMail, or move to Commerce Pro to run two.', 'code' => 'PLAN_LIMIT')), 'Your plan connects 1 store.'),
        array(array(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out')), 'cURL error 28: Operation timed out'),
        array(array(500, array('ok' => false)), 'try again'),
        array(array(200, array('ok' => true)), 'try again'),
    );
    foreach ($cases as $case) {
        $start = connect_start();
        call_user_func_array('agm_http_respond', $case[0]);
        $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
        expect_same(1, count($result['http']), 'the exchange was not tried');
        $notice = connect_notice();
        expect_same('error', $notice['type'], 'an exchange error was not shown as an error');
        expect_that(stripos($notice['message'], $case[1]) !== false, 'the notice does not say "' . $case[1] . '": ' . $notice['message']);
        expect_same('', agumail_api_key(), 'a key was stored after a failed exchange');
        expect_same(false, get_transient('agumail_connect_1'), 'the start outlived a failed exchange');
    }
}

function test_connect_callback_is_only_handled_for_a_store_manager_on_the_settings_page() {
    connect_merchant();
    $start = connect_start();
    agm_grant('manage_woocommerce', false);
    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'the callback ran for somebody who may not manage the store');
    agm_grant('manage_woocommerce');

    $result = agm_request('another admin page', 'admin', function () use ($start) {
        agm_input(array('page' => 'wc-settings', 'agumail_connect' => 'callback', 'code' => 'c1.p.s', 'state' => $start['query']['state']));
        do_action('admin_init');
    });
    expect_same(array(), $result['http'], 'the callback ran on another admin page');

    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']), 'shopper_ajax');
    expect_same(array(), $result['http'], 'the callback ran on admin-ajax');
    expect_that(is_array(get_transient('agumail_connect_1')), 'the start was spent by a request that was not the callback');
}

// ── Advanced ──

function test_connect_advanced_shows_the_api_url_and_a_key_field() {
    connect_merchant();
    agm_grant('manage_options');
    $html = connect_section_html();
    expect_that(preg_match('/<input[^>]*name="agumail_api_url"[^>]*value="https:\/\/api\.agumail\.test"/', $html) === 1, 'the API URL is not pre-filled');
    expect_that(preg_match('/<input[^>]*type="password"[^>]*name="agumail_api_key"/', $html) === 1, 'no API key field');
    expect_that(strpos($html, 'value="connect_save_advanced"') !== false, 'Advanced does not post to its save action');

    unset($GLOBALS['agm']['options']['agumail_api_url']);
    expect_that(strpos(connect_section_html(), 'value="https://api.agumail.com"') !== false, 'the default API URL is not pre-filled');
}

function test_connect_advanced_save_connects_with_the_pasted_key() {
    connect_merchant();
    agm_grant('manage_options');
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_2', 'userId' => 'usr_2', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array(
        'agumail_api_url' => ' https://api.example.test/ ',
        'agumail_api_key' => '  agm_' . str_repeat('c', 40) . ' ',
    ));
    expect_same(array('POST /api/v1/connect', 'GET /api/v1/forms'), connect_paths($result['http']), 'saving did not connect once, then fetch the forms');
    $sent = $result['http'][0];
    expect_same('https://api.example.test/api/v1/connect', $sent['url'], 'the saved API URL was not used');
    expect_same('Bearer agm_' . str_repeat('c', 40), $sent['headers']['Authorization'], 'the pasted key was not used');
    expect_same(array('platform' => 'woocommerce', 'storeUrl' => 'https://shop.test', 'currency' => 'EUR'), $sent['body'], 'wrong connect body');
    expect_same('https://api.example.test', get_option('agumail_api_url'), 'the API URL was not saved');
    expect_same('agm_' . str_repeat('c', 40), get_option('agumail_api_key'), 'the key was not saved');
    expect_same(connect_config(), get_option('agumail_config'), 'the config was not stored');
    expect_same('connected', agumail_connection()['status'], 'the connection is not marked connected');
    expect_same('int_2', agumail_connection()['integrationId'], 'the integration was not stored');
    expect_same('shop.test', agumail_connection()['siteHost'], 'the address the store connected from was not recorded');
    expect_that((int) get_option('agumail_connected_at') > 0, 'agumail_connected_at was not set');
    expect_same('success', connect_notice()['type'], 'no success notice');
}

function test_connect_advanced_save_shows_refusals_and_keeps_the_working_key() {
    connect_merchant(true);
    agm_grant('manage_options');
    $working = agumail_api_key();
    $cases = array(
        array(401, array('ok' => false, 'error' => 'Invalid API key.'), 'Invalid API key.'),
        array(402, array('ok' => false, 'error' => 'Your plan does not include store sync.', 'code' => 'PLAN_FEATURE'), 'Your plan does not include store sync.'),
        array(403, array('ok' => false), 'refused'),
    );
    foreach ($cases as $case) {
        agm_http_respond($case[0], $case[1]);
        $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.other.test', 'agumail_api_key' => 'agm_' . str_repeat('d', 40)));
        expect_same(1, count($result['http']), 'the key was not tried');
        $notice = connect_notice();
        expect_same('error', $notice['type'], 'a refusal was not shown as an error');
        expect_that(stripos($notice['message'], $case[2]) !== false, 'the notice does not say "' . $case[2] . '": ' . $notice['message']);
        expect_same($working, agumail_api_key(), 'a refused key replaced the working one');
        expect_same('https://api.agumail.test', agumail_api_url(), 'a refused save changed the API URL');
    }
}

function test_connect_a_key_on_a_plan_without_store_sync_connects_the_store_for_forms_and_signups() {
    connect_merchant();
    agm_http_respond(402, array('ok' => false, 'error' => 'Connecting a store, and sending its orders, carts and products, is on the Commerce plans. Contacts, signups and events work on every plan.',
        'code' => 'PLAN_FEATURE', 'feature' => 'storeSync'));
    agm_http_respond(200, connect_ping_body(null));
    agm_http_respond(200, connect_forms_body());
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same(array('POST /api/v1/connect', 'GET /api/v1/ping', 'GET /api/v1/forms'), connect_paths($result['http']), 'the key was not checked with ping after the store was refused');
    expect_same(null, $result['http'][1]['query'], 'ping asked about a store AguMail did not take');
    expect_same('agm_' . str_repeat('c', 40), agumail_api_key(), 'the key was not kept');
    expect_same('wordpress', agumail_connection_platform(), 'the store did not connect for forms and signups');
    expect_same('connected', agumail_connection()['status'], 'not connected');
    expect_that(!agumail_store_sync_on(), 'store sync is on without the plan for it');
    expect_same(false, agumail_store_sync_availability()['available'], 'the plan AguMail just named was not remembered');
    $notice = connect_notice();
    expect_same('success', $notice['type'], 'the forms-only connect was not a success');
    expect_that(strpos($notice['message'], 'Orders, carts and products sync on AguMail\'s Commerce plans') !== false, 'the plan was not explained: ' . $notice['message']);

    // Its later pings ask about no store, and leave it connected.
    agm_http_respond(200, connect_ping_body(null));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same('connected', agumail_connection()['status'], 'a store connected by key read as disconnected');
}

function test_connect_a_store_limit_still_refuses_the_key() {
    connect_merchant();
    agm_http_respond(402, array('ok' => false, 'error' => 'Your plan connects 1 store. Disconnect the other one in AguMail, or move to Commerce Pro to run two.',
        'code' => 'PLAN_LIMIT', 'feature' => 'maxIntegrations', 'limit' => 1));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same(array('POST /api/v1/connect'), connect_paths($result['http']), 'a store over its limit was connected another way');
    expect_that(!agumail_has_api_key(), 'the refused key was kept');
    expect_same('error', connect_notice()['type'], 'the limit was not shown as an error');
}

function test_connect_a_store_connection_from_before_platforms_pings_about_its_store() {
    connect_merchant(true);
    update_option('agumail_connection', array('status' => 'connected', 'shopName' => 'Hollow Pine', 'userId' => 'usr_1'));
    expect_same('woocommerce', agumail_connection_platform(), 'a connection from before platforms is not a store\'s');
    agm_http_respond(200, connect_ping_body());
    $result = agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same('platform=woocommerce', $result['http'][0]['query'], 'the store\'s ping changed');
}

function test_connect_advanced_save_keeps_the_saved_key_when_the_field_is_left_empty() {
    connect_merchant(true);
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => ''));
    expect_same('Bearer agm_' . str_repeat('a', 40), $result['http'][0]['headers']['Authorization'], 'the saved key was not used');
    expect_same('agm_' . str_repeat('a', 40), agumail_api_key(), 'the saved key was lost');

    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => ''));
    expect_same(array(), $result['http'], 'AguMail was called without a key');
    expect_same('error', connect_notice()['type'], 'a missing key was not reported');

    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'not a url', 'agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same(array(), $result['http'], 'AguMail was called at an address that is not one');
    expect_same('error', connect_notice()['type'], 'a bad API URL was not reported');
}

function test_connect_advanced_shows_a_pinned_api_url_read_only_and_ignores_the_field() {
    define('AGUMAIL_API_URL', 'http://localhost:3011');
    connect_merchant();
    $html = connect_section_html();
    expect_that(strpos($html, 'http://localhost:3011') !== false, 'the pinned API URL is not shown');
    expect_that(preg_match('/<input[^>]*name="agumail_api_url"/', $html) === 0, 'the pinned API URL can be edited');
    expect_that(strpos($html, 'wp-config.php') !== false, 'the merchant is not told where the URL is set');

    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.other.test', 'agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same('http://localhost:3011/api/v1/connect', $result['http'][0]['url'], 'the pinned URL was not used');
    expect_same('https://api.agumail.test', get_option('agumail_api_url'), 'the field overwrote the saved URL while pinned');
}

// ── Connected ──

function test_connect_the_connected_page_shows_the_account_and_offers_reconnect_and_disconnect() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'shopName' => 'Hollow <b>Pine</b>', 'storeUrl' => 'shop.test', 'checkedAt' => time()));
    $html = connect_section_html();
    expect_that(strpos($html, 'Hollow &lt;b&gt;Pine&lt;/b&gt;') !== false, 'the shop name is not shown escaped');
    expect_that(strpos($html, 'shop.test') !== false, 'the store address is not shown');
    expect_that(strpos($html, 'Connected') !== false, 'the status is not shown');
    expect_that(preg_match('/<button[^>]*>Reconnect<\/button>/', $html) === 1, 'no Reconnect button');
    expect_that(strpos($html, 'value="connect_start"') !== false, 'Reconnect does not start a new connection');
    expect_that(preg_match('/<button[^>]*>Disconnect from this site<\/button>/', $html) === 1, 'no "Disconnect from this site" button');
    expect_that(strpos($html, 'Connect to AguMail</button>') === false, 'a connected store is offered the first-time Connect button');
    expect_that(preg_match('/<summary[^>]*>Advanced<\/summary>/', $html) === 1, 'Advanced is missing once connected');
}

function test_connect_disconnect_from_this_site_clears_the_key_and_config_locally() {
    connect_merchant(true);
    update_option('agumail_config', connect_config());
    agumail_update_connection(array('status' => 'connected', 'shopName' => 'Hollow Pine'));
    update_option('agumail_connected_at', time());
    update_option(AGUMAIL_STORE_HOLD_OPTION, time());
    update_option('agumail_store_notice', 'held');
    $result = connect_admin_action('connect_disconnect');
    expect_same(array(), $result['http'], 'disconnecting from this site called AguMail');
    expect_that(!agumail_store_sync_held() && get_option('agumail_store_notice') === false, 'the plan\'s store sync state outlived the connection');
    expect_same('', agumail_api_key(), 'the key was kept');
    expect_same(array(), agumail_config(), 'the config was kept');
    expect_same('', agumail_connection()['status'], 'the connection was kept');
    expect_same(false, get_option('agumail_connected_at'), 'agumail_connected_at was kept');
    expect_same('success', connect_notice()['type'], 'no notice');
    expect_that(strpos(connect_section_html(), 'Connect to AguMail</button>') !== false, 'the page does not offer to connect again');
}

// ── Config refresh through ping ──

/** Answers ping and forms by path; anything else is recorded as unexpected. */
function connect_serve(array $ping_body, $ping_code = 200) {
    agm_http_default(function ($url) use ($ping_body, $ping_code) {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === '/api/v1/ping') {
            return array('code' => $ping_code, 'body' => $ping_body, 'headers' => array());
        }
        if ($path === '/api/v1/forms') {
            return array('code' => 200, 'body' => array('ok' => true, 'loaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js', 'forms' => array()), 'headers' => array());
        }
        return array('code' => 500, 'body' => array('ok' => false, 'error' => 'unexpected'), 'headers' => array());
    });
}

function test_connect_housekeeping_refreshes_the_config_through_ping_daily_and_never_reconnects() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'checkedAt' => time() - 5 * MINUTE_IN_SECONDS));
    update_option('agumail_config', array('siteKey' => 'old'));
    $fresh = connect_config();
    $fresh['siteKey'] = 'site_new';
    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'shop.test'), $fresh));

    agm_request('schedule', 'cron', function () { agumail_schedule(); });
    $housekeeping = agm_scheduled('agumail_housekeeping');
    expect_same(1, count($housekeeping), 'housekeeping is not scheduled');
    expect_same(DAY_IN_SECONDS, $housekeeping[0]['interval'], 'housekeeping is not daily');
    $result = agm_request('housekeeping', 'cron', function () { agm_run_actions('agumail_housekeeping'); });

    $pings = array_values(array_filter($result['http'], function ($r) { return $r['path'] === '/api/v1/ping'; }));
    expect_same(1, count($pings), 'housekeeping did not ping, once');
    expect_same('GET', $pings[0]['method'], 'ping is a GET');
    expect_same('platform=woocommerce', $pings[0]['query'], 'ping does not name the platform');
    foreach ($result['http'] as $request) {
        expect_that(strpos($request['path'], '/api/v1/connect') !== 0, 'housekeeping connected again: ' . $request['method'] . ' ' . $request['path']);
    }
    expect_same($fresh, get_option('agumail_config'), 'the config was not refreshed');
    expect_same('Hollow Pine', agumail_connection()['shopName'], 'the shop name was not refreshed');
    expect_same('shop.test', agumail_connection()['storeUrl'], 'the store address was not refreshed');
    expect_that(abs(agumail_connection()['checkedAt'] - time()) <= 2, 'the check time was not recorded');
}

function test_connect_opening_the_settings_page_pings_when_the_last_check_is_over_an_hour_old() {
    connect_merchant(true);
    connect_serve(connect_ping_body());

    agumail_update_connection(array('status' => 'connected', 'checkedAt' => time() - 10 * MINUTE_IN_SECONDS));
    expect_same(array(), connect_open_settings_page()['http'], 'a fresh connection was pinged again');

    agumail_update_connection(array('checkedAt' => time() - HOUR_IN_SECONDS - 60));
    $result = connect_open_settings_page();
    expect_same(array('GET /api/v1/ping'), connect_paths($result['http']), 'a stale connection was not pinged on the settings page');
    expect_that($result['http'][0]['timeout'] <= 10, 'the page waits too long for ping');
    expect_same(connect_config(), get_option('agumail_config'), 'the config was not refreshed');

    agumail_update_connection(array('checkedAt' => time() - 2 * HOUR_IN_SECONDS));
    $result = agm_request('another admin page', 'admin', function () {
        agm_input(array('page' => 'wc-settings'));
        do_action('admin_init');
    });
    expect_same(array(), $result['http'], 'another admin page pinged AguMail');
}

function test_connect_a_failed_page_ping_is_not_retried_on_every_page_view() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'checkedAt' => time() - 2 * HOUR_IN_SECONDS));
    agm_http_default(function () { return new WP_Error('http_request_failed', 'timed out'); });
    expect_same(1, count(connect_open_settings_page()['http']), 'the stale connection was not pinged');
    expect_same(array(), connect_open_settings_page()['http'], 'a failed ping was retried on the very next page view');
    expect_same('connected', agumail_connection()['status'], 'a network failure changed the connection status');
}

function test_connect_a_store_disconnected_in_the_app_pauses_the_drain_and_offers_reconnect() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'shopName' => 'Hollow Pine', 'checkedAt' => 0));
    expect_same(false, apply_filters('agumail_drain_paused', false), 'a connected store is paused');

    connect_serve(connect_ping_body(array('status' => 'disconnected', 'storeUrl' => 'shop.test')));
    agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    expect_same('disconnected', agumail_connection()['status'], 'the disconnect was not recorded');
    expect_same(true, apply_filters('agumail_drain_paused', false), 'the drain was not paused');
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com'));
    $drain = agm_request('drain', 'cron', function () use (&$outcome) {
        $outcome = agumail_run_in_background('agumail_drain');
    });
    expect_same('paused', $outcome, 'the drain ran for a store AguMail disconnected');
    expect_same(array(), $drain['http'], 'the paused drain sent something');

    $html = connect_section_html();
    expect_that(preg_match('/<button[^>]*button-primary[^>]*>Reconnect<\/button>/', $html) === 1, 'Reconnect is not the main action once disconnected');
    expect_that(stripos($html, 'paused') !== false, 'the page does not say sending is paused');

    connect_serve(connect_ping_body(null));
    agumail_update_connection(array('status' => 'connected'));
    agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    expect_same('disconnected', agumail_connection()['status'], 'a ping without an integration did not count as disconnected');

    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'shop.test')));
    agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    expect_same('connected', agumail_connection()['status'], 'reconnecting in the app did not resume the store');
    expect_same(false, apply_filters('agumail_drain_paused', false), 'the drain stayed paused');
}

function test_connect_a_refused_key_on_ping_is_recorded_and_offers_reconnect() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'checkedAt' => 0));
    connect_serve(array('ok' => false, 'error' => 'Invalid API key.'), 401);
    agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    $connection = agumail_connection();
    expect_same('error', $connection['status'], 'the refusal was not recorded');
    expect_same(401, $connection['errorCode'], 'the refusal code was not recorded');
    expect_same('Invalid API key.', $connection['error'], 'the refusal message was not recorded');
    $html = connect_section_html();
    expect_that(strpos($html, 'Invalid API key.') !== false, 'the refusal is not shown');
    expect_that(preg_match('/<button[^>]*button-primary[^>]*>Reconnect<\/button>/', $html) === 1, 'Reconnect is not offered after a refusal');
}

// ── Shoppers ──

function test_connect_no_shopper_request_ever_calls_agumail() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'checkedAt' => 0));
    set_transient('agumail_connect_0', array('state' => str_repeat('s', 32), 'verifier' => str_repeat('v', 64)), 900);
    agm_sign_out();
    $get = array('page' => 'agumail', 'agumail_connect' => 'callback', 'code' => 'c1.p.s', 'state' => str_repeat('s', 32));
    foreach (array('shopper', 'shopper_ajax', 'shopper_rest') as $kind) {
        $result = agm_request('storefront ' . $kind, $kind, function () use ($get) {
            agm_input($get);
            do_action('init');
            do_action('admin_init');
            do_action('wp_loaded');
            do_action('template_redirect');
            do_action('wp_enqueue_scripts');
            do_action('wp_footer');
        });
        expect_same(array(), $result['http'], "a $kind request called AguMail");
        expect_same(array(), agm_writes(array('option', 'transient'), 'storefront ' . $kind), "a $kind request wrote settings");
    }
}

// ── Review fixes: edge cases of the one-click flow ──

function test_connect_a_callback_loaded_by_another_administrator_says_so_and_keeps_the_start() {
    connect_merchant();
    $start = connect_start();
    $pending = get_transient('agumail_connect_1');

    agm_sign_in(2, 'second@shop.test');
    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same(array(), $result['http'], 'another administrator exchanged the code');
    $notice = get_transient('agumail_notice_2');
    expect_same('error', $notice['type'], 'the second administrator was not told why');
    expect_same('This connection was started by another administrator. Press Connect to AguMail to start again.', $notice['message'], 'wrong message for another administrator');
    expect_same($pending, get_transient('agumail_connect_1'), 'another administrator spent the first one\'s start');

    // The administrator who started it can still finish.
    agm_sign_in(1);
    agm_http_respond(200, connect_exchange_ok());
    $result = connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same('POST /api/v1/connect/exchange', connect_paths($result['http'])[0], 'the first administrator could not finish');
}

function test_connect_start_explains_an_admin_on_another_host() {
    connect_merchant();
    expect_same(null, agumail_connect_host_problem('https://shop.test', 'https://shop.test/wp-admin/admin.php?page=agumail'), 'the same host was refused');
    expect_same(null, agumail_connect_host_problem('https://shop.test/store', 'https://SHOP.test/wp-admin/'), 'a subfolder install on the same host was refused');
    foreach (array('https://admin.shop.test/wp-admin/', 'https://shop.test:8443/wp-admin/') as $admin) {
        $problem = agumail_connect_host_problem('https://shop.test', $admin);
        expect_that(is_wp_error($problem), "an admin at $admin was not refused");
        expect_that(strpos($problem->get_error_message(), 'shop.test') !== false && strpos($problem->get_error_message(), 'Advanced') !== false, 'the explanation is not plain: ' . $problem->get_error_message());
        expect_that(strpos($problem->get_error_message(), '—') === false, 'UI copy carries an em-dash');
    }
    // Here home and admin share a host, so the start goes ahead.
    expect_that(strpos(connect_start()['location'], 'https://agumail.com/connect/woocommerce?') === 0, 'a store on one host could not start');
}

function test_connect_the_currency_is_sent_only_when_it_is_one() {
    expect_same(array('storeUrl' => 'x', 'currency' => 'EUR'), agumail_connect_with_currency(array('storeUrl' => 'x'), ' eur '), 'the currency was not cleaned');
    foreach (array('', 'EURO', null, 'E1R') as $code) {
        expect_same(array('storeUrl' => 'x'), agumail_connect_with_currency(array('storeUrl' => 'x'), $code), 'a currency that is not one was sent: ' . var_export($code, true));
    }
}

function test_connect_advanced_needs_https_except_on_this_machine() {
    connect_merchant(true);
    agm_grant('manage_options');
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'http://api.example.test', 'agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same(array(), $result['http'], 'a plain http address was used');
    expect_same('error', connect_notice()['type'], 'a plain http address was not refused');
    expect_that(strpos(connect_notice()['message'], 'https://') !== false, 'the refusal does not say https is needed');
    foreach (array('http://localhost:3011', 'http://127.0.0.1:3011') as $url) {
        agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
        $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => $url, 'agumail_api_key' => 'agm_' . str_repeat('c', 40)));
        expect_same($url . '/api/v1/connect', $result['http'][0]['url'], "$url was refused");
    }
}

// ── Review fixes: the forms cache follows the account ──

function test_connect_disconnecting_forgets_the_forms_and_stops_them_rendering() {
    connect_merchant(true);
    update_option('agumail_config', connect_config());
    update_option('agumail_forms_cache', array('loaderUrl' => 'https://api.agumail.test/embed/agumail-forms.js', 'forms' => array(array('slug' => 'fspring', 'name' => 'Spring'))));
    expect_that(agm_shortcode('agumail_form', array('slug' => 'fspring')) !== '', 'the form did not render while connected');
    connect_admin_action('connect_disconnect');
    expect_same(false, get_option('agumail_forms_cache'), 'the forms were kept after disconnecting');

    // Even a cache left behind is not used without a connection.
    update_option('agumail_forms_cache', array('loaderUrl' => 'https://api.agumail.test/embed/agumail-forms.js', 'forms' => array(array('slug' => 'fspring', 'name' => 'Spring'))));
    expect_same('', agm_shortcode('agumail_form', array('slug' => 'fspring')), 'a form rendered after disconnecting');
}

function test_connect_another_account_forgets_the_previous_accounts_forms() {
    connect_merchant();
    agumail_update_connection(array('userId' => 'usr_old'));
    update_option('agumail_forms_cache', array('loaderUrl' => 'x', 'forms' => array(array('slug' => 'fold001', 'name' => 'Old'))));
    $start = connect_start();
    agm_http_respond(200, connect_exchange_ok());
    agm_http_respond(500, array('ok' => false));
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same(false, get_option('agumail_forms_cache'), 'the previous account\'s forms were kept');

    update_option('agumail_forms_cache', array('loaderUrl' => 'x', 'forms' => array(array('slug' => 'fold001', 'name' => 'Old'))));
    connect_serve(connect_ping_body());
    agm_request('housekeeping ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_that(get_option('agumail_forms_cache') !== false, 'the same account\'s forms were dropped');
    agumail_update_connection(array('userId' => 'usr_other'));
    agm_request('housekeeping ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same(false, get_option('agumail_forms_cache'), 'ping reporting another account kept the old forms');
}

// ── Review fixes: ping ──

function test_connect_ping_without_an_integration_key_keeps_the_status_but_null_pauses() {
    connect_merchant(true);
    $body = connect_ping_body();
    unset($body['integration']);
    connect_serve($body);
    foreach (array('connected', 'disconnected') as $status) {
        agumail_update_connection(array('status' => $status));
        agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
        expect_same($status, agumail_connection()['status'], "a ping without an integration changed '$status'");
    }
    agumail_update_connection(array('status' => 'error', 'error' => 'Invalid API key.', 'errorCode' => 401));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same('connected', agumail_connection()['status'], 'a ping that went through left an earlier refusal standing');

    connect_serve(connect_ping_body(null));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same('disconnected', agumail_connection()['status'], 'an explicit null integration did not pause the store');
}

// ── Review fixes: a site whose address changed (staging, clones) ──

function test_connect_a_site_whose_address_changed_pauses_until_reconnected() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'siteHost' => 'live.shop', 'storeUrl' => 'live.shop', 'shopName' => 'Hollow Pine'));
    expect_same(true, apply_filters('agumail_drain_paused', false), 'a copy of the store on another address was not paused');
    $html = connect_section_html();
    expect_that(strpos($html, 'This site&#039;s address changed from live.shop to shop.test. Reconnect if this is the live store.') !== false, 'the address change is not explained: ' . $html);
    expect_that(preg_match('/<button[^>]*button-primary[^>]*>Reconnect<\/button>/', $html) === 1, 'Reconnect is not the main action');

    // Ping alone can tell: AguMail knows the store by another address.
    agumail_update_connection(array('siteHost' => ''));
    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'live.shop')));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same(true, apply_filters('agumail_drain_paused', false), 'ping reporting another address did not pause');

    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'www.shop.test')));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same(false, apply_filters('agumail_drain_paused', false), 'the www form of the same address paused the store');

    // Reconnecting from here records this address.
    agumail_update_connection(array('siteHost' => 'live.shop', 'storeUrl' => 'live.shop'));
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => ''));
    expect_same('shop.test', agumail_connection()['siteHost'], 'reconnecting did not record this address');
    expect_same(false, apply_filters('agumail_drain_paused', false), 'reconnecting did not lift the pause');
    expect_that(strpos(connect_section_html(), 'address changed') === false, 'the address change is still shown after reconnecting');
}

// ── Cross-cutting review: another account never gets the previous one's data ──

/** Queues a contact and an order, parks one, and holds two account addresses: what the previous account left. */
function connect_leave_old_account_data() {
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com'));
    agumail_enqueue('order', '501');
    $row = agumail_store()->find('order', '501');
    agumail_store()->park($row['id'], $row['version'], 'refused', agumail_mysql_time());
    agm_add_user(7, 'bea@example.com');
    agm_add_user(8, 'cy@example.com');
    update_user_meta(7, '_agumail_email', 'bea@example.com');
    update_user_meta(8, '_agumail_email', 'cy@example.com');
    update_user_meta(7, '_agumail_optin', 'yes');
    $stats = agumail_outbox_stats();
    expect_same(array(1, 1), array($stats['pending'], $stats['failed']), 'the old account\'s rows were not set up');
}

function connect_expect_old_account_data($kept, $when) {
    $stats = agumail_outbox_stats();
    $rows = $stats['pending'] + $stats['failed'];
    $held = array_filter(array(get_user_meta(7, '_agumail_email', true), get_user_meta(8, '_agumail_email', true)));
    if ($kept) {
        expect_same(2, $rows, "$when: the queue was cleared for the same account");
        expect_same(2, count($held), "$when: the held addresses were dropped for the same account");
    } else {
        expect_same(0, $rows, "$when: the previous account's queue would reach the new one");
        expect_same(0, count($held), "$when: the previous account's held addresses were kept");
    }
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), "$when: a shopper's own answer was dropped");
}

function test_connect_connecting_another_account_clears_the_queue_and_the_held_addresses() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'userId' => 'usr_1'));
    connect_leave_old_account_data();

    // The same account again keeps everything.
    $start = connect_start();
    agm_http_respond(200, connect_exchange_ok());
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    connect_expect_old_account_data(true, 'reconnecting the same account');

    // Another account: nothing of the first one's reaches it.
    $start = connect_start();
    $other = connect_exchange_ok();
    $other['userId'] = 'usr_2';
    agm_http_respond(200, $other);
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    connect_expect_old_account_data(false, 'connecting another account');
}

function test_connect_another_account_after_disconnecting_still_clears_the_queue() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'userId' => 'usr_1'));
    connect_leave_old_account_data();
    connect_admin_action('connect_disconnect');
    connect_expect_old_account_data(true, 'disconnecting');

    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_9', 'userId' => 'usr_9', 'shopName' => 'Other', 'config' => connect_config()));
    connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => 'agm_' . str_repeat('e', 40)));
    connect_expect_old_account_data(false, 'connecting another account after disconnecting');
}

function test_connect_ping_reporting_another_account_clears_the_queue() {
    connect_merchant(true);
    agumail_update_connection(array('status' => 'connected', 'userId' => 'usr_old', 'checkedAt' => 0));
    connect_leave_old_account_data();
    connect_serve(connect_ping_body());
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    connect_expect_old_account_data(false, 'ping reporting another account');
}

function test_connect_every_placeholder_string_has_a_translators_comment($plugin_dir) {
    $missing = array();
    foreach (array('includes/connect.php', 'includes/tracking.php', 'includes/forms.php') as $file) {
        $lines = file($plugin_dir . '/' . $file);
        foreach ($lines as $i => $line) {
            if (!preg_match('/\b(__|_n|_x|esc_html__|esc_attr__)\(\s*\'[^\']*%(\d\$)?[sd]/', $line)) {
                continue;
            }
            $context = implode('', array_slice($lines, max(0, $i - 2), min(3, $i + 1)));
            if (strpos($context, 'translators:') === false) {
                $missing[] = $file . ':' . ($i + 1);
            }
        }
    }
    expect_same(array(), $missing, 'strings with placeholders but no translators comment');
}

// ── The key is autoloaded: every storefront request reads it ──

function test_connect_the_key_is_saved_autoloaded_by_the_exchange_and_the_advanced_save() {
    connect_merchant();
    $start = connect_start();
    agm_http_respond(200, connect_exchange_ok());
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    expect_same('agm_' . str_repeat('b', 40), get_option('agumail_api_key'), 'the exchange did not save the key');
    expect_same(true, $GLOBALS['agm']['autoload']['agumail_api_key'], 'the exchange saved the key without autoload');

    $GLOBALS['agm']['autoload']['agumail_api_key'] = false;
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same('agm_' . str_repeat('c', 40), get_option('agumail_api_key'), 'the Advanced save did not save the key');
    expect_same(true, $GLOBALS['agm']['autoload']['agumail_api_key'], 'the Advanced save saved the key without autoload');

    // A refused key puts the working one back, still autoloaded.
    agm_http_respond(401, array('ok' => false, 'error' => 'Invalid API key.'));
    connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test', 'agumail_api_key' => 'agm_' . str_repeat('d', 40)));
    expect_same('agm_' . str_repeat('c', 40), get_option('agumail_api_key'), 'the working key was not put back');
    expect_same(true, $GLOBALS['agm']['autoload']['agumail_api_key'], 'the working key was put back without autoload');
}

// ── Where the key goes, and what runs on the storefront ──

/** A config as a host that is not AguMail would answer it: every address points back at that host. */
function connect_foreign_config() {
    return array_merge(connect_config(), array(
        'siteScriptUrl'  => 'https://evil.test/site.js',
        'siteEndpoint'   => 'https://evil.test/events',
        'formsLoaderUrl' => 'https://evil.test/forms.js',
    ));
}

/** A storefront page with a form on it, website tracking switched on: the address of each AguMail script it enqueued. */
function connect_storefront_scripts() {
    update_option('agumail_tracking_enabled', 'yes');
    unset($GLOBALS['agm']['scripts']['agumail-site'], $GLOBALS['agm']['scripts']['agumail-forms']);
    agm_request('storefront page', 'shopper', function () {
        do_action('init');
        do_action('wp_enqueue_scripts');
        agm_shortcode('agumail_form', array('slug' => 'fspring'));
        do_action('wp_footer');
    });
    $scripts = array();
    foreach (array('agumail-site', 'agumail-forms') as $handle) {
        $script = agm_script($handle);
        if ($script && !empty($script['enqueued'])) {
            $scripts[$handle] = $script['src'];
        }
    }
    return $scripts;
}

function connect_url_fields(array $config) {
    return array($config['siteScriptUrl'], $config['siteEndpoint'], $config['formsLoaderUrl']);
}

function test_connect_only_an_administrator_changes_the_api_url() {
    connect_merchant(true);
    $key = agumail_api_key();
    $cases = array(
        array('https://evil.test', ''),
        array('https://evil.test', 'agm_' . str_repeat('c', 40)),
        array('', ''),
    );
    foreach ($cases as $case) {
        $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => $case[0], 'agumail_api_key' => $case[1]));
        expect_same(array(), $result['http'], 'a store manager connected at an API URL of their choosing: "' . $case[0] . '"');
        expect_same(array('type' => 'error', 'message' => 'Only an administrator can change the API URL.'), connect_notice(), 'a store manager changing the API URL was not refused plainly');
        expect_same('https://api.agumail.test', agumail_api_url(), 'a store manager changed the API URL');
        expect_same($key, agumail_api_key(), 'a refused save changed the key');
    }

    // The rest of Advanced is theirs as before; the API URL is shown, not offered.
    $html = connect_section_html();
    expect_that(preg_match('/<input[^>]*name="agumail_api_url"/', $html) === 0, 'a store manager is offered the API URL to edit');
    expect_that(strpos($html, '<code>https://api.agumail.test</code>') !== false, 'the API URL is not shown to a store manager');
    expect_that(strpos($html, 'Only an administrator can change this.') !== false, 'a store manager is not told who can change it');
    expect_that(preg_match('/<input[^>]*type="password"[^>]*name="agumail_api_key"/', $html) === 1, 'a store manager lost the key field');

    // Their form, without the URL field, connects at the saved address.
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    expect_same('https://api.agumail.test/api/v1/connect', $result['http'][0]['url'], 'a store manager could not reconnect at the saved API URL');
    expect_same('Bearer agm_' . str_repeat('c', 40), $result['http'][0]['headers']['Authorization'], 'the pasted key was not used');
    expect_same('https://api.agumail.test', get_option('agumail_api_url'), 'saving without the URL field reset the API URL');

    // An administrator can.
    agm_grant('manage_options');
    expect_that(preg_match('/<input[^>]*name="agumail_api_url"[^>]*value="https:\/\/api\.agumail\.test"/', connect_section_html()) === 1, 'an administrator is not offered the API URL');
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.other.test', 'agumail_api_key' => 'agm_' . str_repeat('d', 40)));
    expect_same('https://api.other.test/api/v1/connect', $result['http'][0]['url'], 'an administrator could not change the API URL');
    expect_same('https://api.other.test', agumail_api_url(), 'the administrator\'s API URL was not saved');
}

function test_connect_a_new_api_host_needs_the_key_pasted_again() {
    connect_merchant(true);
    agm_grant('manage_options');
    $saved = agumail_api_key();
    foreach (array('https://evil.test', 'https://api.agumail.test.evil.test', 'https://api.agumail.test:8443', 'http://localhost:3011', '') as $url) {
        $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => $url, 'agumail_api_key' => ''));
        expect_same(array(), $result['http'], 'the saved key was sent to "' . $url . '"');
        $notice = connect_notice();
        expect_same('error', $notice['type'], 'a new host without the key was not refused: "' . $url . '"');
        expect_that(stripos($notice['message'], 'paste your API key') !== false, 'the merchant is not told to paste the key: ' . $notice['message']);
        expect_that(strpos($notice['message'], "\u{2014}") === false, 'UI copy carries an em-dash');
        expect_same('https://api.agumail.test', agumail_api_url(), 'a refused save moved the API URL to "' . $url . '"');
        expect_same($saved, agumail_api_key(), 'a refused save changed the key');
    }

    // Everything after still goes to the address the key was saved for.
    connect_serve(connect_ping_body());
    $result = agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same('https://api.agumail.test/api/v1/ping?platform=woocommerce', $result['http'][0]['url'], 'ping left the saved address');

    // The same host keeps the saved key.
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.agumail.test/', 'agumail_api_key' => ''));
    expect_same('Bearer ' . $saved, $result['http'][0]['headers']['Authorization'], 'the same host did not keep the saved key');

    // Pasted again, only the pasted key goes to the new host.
    $pasted = 'agm_' . str_repeat('e', 40);
    agm_http_respond(200, array('ok' => true, 'integrationId' => 'int_1', 'userId' => 'usr_1', 'shopName' => 'Hollow Pine', 'config' => connect_config()));
    $result = connect_admin_action('connect_save_advanced', array('agumail_api_url' => 'https://api.other.test', 'agumail_api_key' => $pasted));
    expect_same('https://api.other.test/api/v1/connect', $result['http'][0]['url'], 'the new host was not used');
    foreach (agm_http_requests() as $request) {
        if (strpos($request['url'], 'https://api.agumail.test/') !== 0) {
            expect_same('Bearer ' . $pasted, $request['headers']['Authorization'], 'the saved key reached ' . $request['url']);
        }
    }
}

function test_connect_addresses_off_agumails_hosts_are_not_kept_or_loaded() {
    connect_merchant();
    $start = connect_start();
    $answer = connect_exchange_ok();
    $answer['config'] = connect_foreign_config();
    agm_http_respond(200, $answer);
    agm_http_respond(200, array_merge(connect_forms_body(), array('loaderUrl' => 'https://evil.test/forms.js')));
    connect_callback(array('code' => 'c1.p.s', 'state' => $start['query']['state']));
    $config = get_option('agumail_config');
    expect_same(array(null, null, null), connect_url_fields($config), 'addresses off AguMail\'s hosts were kept from the exchange');
    expect_same('site_abc123', $config['siteKey'], 'the site key was dropped with them');
    expect_same(connect_config()['identifySecret'], $config['identifySecret'], 'the identify secret was dropped with them');
    $cache = get_option('agumail_forms_cache');
    expect_same('', $cache['loaderUrl'], 'a forms loader off AguMail\'s hosts was cached');
    expect_same('fspring', $cache['forms'][0]['slug'], 'the forms were dropped with it');
    agm_sign_out();
    expect_same(array(), connect_storefront_scripts(), 'a script off AguMail\'s hosts was put on the storefront');

    // Ping is held to the same hosts.
    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'shop.test'), connect_config()));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same(connect_config(), get_option('agumail_config'), 'AguMail\'s own addresses were not kept from ping');
    expect_same(array('agumail-site' => 'https://agupage.com/_/v1/site.js?v=3', 'agumail-forms' => 'https://api.agumail.com/embed/agumail-forms.js'),
        connect_storefront_scripts(), 'AguMail\'s own scripts were not put on the storefront');
    connect_serve(connect_ping_body(array('status' => 'connected', 'storeUrl' => 'shop.test'), connect_foreign_config()));
    agm_request('ping', 'cron', function () { agumail_run_in_background('agumail_connect_ping'); });
    expect_same(array(null, null, null), connect_url_fields(get_option('agumail_config')), 'addresses off AguMail\'s hosts were kept from ping');
    expect_same(array(), connect_storefront_scripts(), 'a script from ping\'s foreign addresses was put on the storefront');
}

function test_connect_only_https_on_agumails_own_hosts_counts_as_agumails() {
    $own = array(
        'https://agupage.com/_/v1/site.js?v=3',
        'https://api.agumail.com/embed/agumail-forms.js',
        'https://API.AguMail.com/api/public/site/site_abc123/events',
    );
    foreach ($own as $url) {
        $config = agumail_connect_clean_config(array('siteScriptUrl' => $url, 'siteEndpoint' => $url, 'formsLoaderUrl' => $url));
        expect_same(array($url, $url, $url), connect_url_fields($config), "AguMail's own address $url was dropped");
    }
    $others = array(
        'http://agupage.com/_/v1/site.js',
        '//agupage.com/_/v1/site.js',
        '/_/v1/site.js',
        'https://www.agupage.com/_/v1/site.js',
        'https://agumail.com/site.js',
        'https://agupage.com.evil.test/site.js',
        'https://evil.test/agupage.com/site.js',
        'https://agupage.com@evil.test/site.js',
        'https://evil.test\\@agupage.com/site.js',
        'https://evil.test#@api.agumail.com/',
        'https://localhost:3011/p/_/v1/site.js',
        'javascript:alert(1)//agupage.com',
        'data:text/javascript,alert(1)',
    );
    foreach ($others as $url) {
        $config = agumail_connect_clean_config(array('siteScriptUrl' => $url, 'siteEndpoint' => $url, 'formsLoaderUrl' => $url));
        expect_same(array(null, null, null), connect_url_fields($config), "$url was kept as AguMail's");
    }
}

function test_connect_a_host_pinned_in_wp_config_counts_as_agumails() {
    // As the end-to-end site pins them: the API by its service name, the app as the browser reaches it.
    define('AGUMAIL_API_URL', 'http://fake-agumail:8090');
    define('AGUMAIL_APP_URL', 'http://localhost:8090');
    $pinned = array(
        'siteScriptUrl'  => 'http://localhost:8090/p/_/v1/site.js?v=e2e',
        'siteEndpoint'   => 'http://fake-agumail:8090/api/public/site/site_e2e/events',
        'formsLoaderUrl' => 'http://localhost:8090/embed/agumail-forms.js',
    );
    expect_same(array_values($pinned), connect_url_fields(agumail_connect_clean_config($pinned)), 'an address on a pinned host was dropped');
    expect_same(array(null, null, null), connect_url_fields(agumail_connect_clean_config(array(
        'siteScriptUrl'  => 'http://evil.test:8090/site.js',
        'siteEndpoint'   => 'https://evil.test/events',
        'formsLoaderUrl' => 'http://localhost.evil.test:8090/forms.js',
    ))), 'an address off the pinned hosts was kept');

    connect_merchant(true);
    agm_sign_out();
    update_option('agumail_config', array_merge(connect_config(), $pinned));
    expect_same(array('agumail-site' => $pinned['siteScriptUrl'], 'agumail-forms' => $pinned['formsLoaderUrl']), connect_storefront_scripts(), 'the pinned host\'s scripts were not put on the storefront');
}

function test_connect_an_api_url_saved_under_advanced_does_not_make_its_host_agumails() {
    connect_merchant(true);
    update_option('agumail_api_url', 'http://localhost:3011');
    $local = array(
        'siteScriptUrl'  => 'http://localhost:3011/p/_/v1/site.js',
        'siteEndpoint'   => 'http://localhost:3011/api/public/site/site_abc123/events',
        'formsLoaderUrl' => 'http://localhost:3011/embed/agumail-forms.js',
    );
    expect_same(array(null, null, null), connect_url_fields(agumail_connect_clean_config($local)), 'a saved API URL counted as a pin');
}

function test_connect_a_foreign_address_saved_earlier_never_loads_on_the_storefront() {
    connect_merchant(true);
    delete_option('agumail_api_url');
    agm_sign_out();
    // Kept by an earlier version, which saved whatever the config said.
    update_option('agumail_config', connect_foreign_config());
    update_option('agumail_forms_cache', array_merge(connect_forms_body(), array('loaderUrl' => 'https://evil.test/forms.js')));
    expect_same(array(), connect_storefront_scripts(), 'a foreign address saved earlier was put on the storefront');

    // A 1.x site's pasted key and script address are still read, from AguMail's hosts only.
    update_option('agumail_site_key', 'legacy_key');
    update_option('agumail_site_src', 'https://evil.test/legacy.js');
    expect_same(array(), connect_storefront_scripts(), 'a foreign 1.x script address was put on the storefront');
    update_option('agumail_site_src', 'https://agupage.com/_/v1/site.js?v=1');
    expect_same(array('agumail-site' => 'https://agupage.com/_/v1/site.js?v=1'), connect_storefront_scripts(), 'a 1.x site set up by hand lost its script');
    expect_same('https://api.agumail.com/api/public/site/legacy_key/events', agumail_tracking_site()['endpoint'], 'the 1.x site reports somewhere else');

    // AguMail's script never reports to a foreign address: a saved one gives way to the API's, and a foreign API stops it.
    update_option('agumail_config', array_merge(connect_config(), array('siteEndpoint' => 'https://evil.test/events')));
    expect_same('https://api.agumail.com/api/public/site/site_abc123/events', agumail_tracking_site()['endpoint'], 'the script reports to a foreign address saved earlier');
    update_option('agumail_api_url', 'https://evil.test');
    expect_same(null, agumail_tracking_site(), 'the script reports to a foreign API URL saved earlier');
    expect_that(!isset(connect_storefront_scripts()['agumail-site']), 'the site script loaded while it would report to a foreign address');

    // The forms loader saved earlier gives way to the cache's, and loads only from AguMail's hosts.
    update_option('agumail_config', array_merge(connect_config(), array('formsLoaderUrl' => 'https://evil.test/forms.js')));
    update_option('agumail_forms_cache', connect_forms_body());
    expect_same('https://api.agumail.com/embed/agumail-forms.js', agumail_forms_loader_url(), 'the cached loader did not take over from a foreign one');
}
