<?php
// The HTTP guard. AguMail is called only from inside the plugin's own
// background work (Action Scheduler callbacks and admin tools), and only when
// the request itself is cron, wp-admin or WP-CLI. Either half alone is refused.

function http_guard_call() {
    return agumail_api_request('POST', '/api/v1/contacts', array('contacts' => array(array('email' => 'ada@example.com'))));
}

// ── Refused ──

function test_http_guard_refuses_a_shopper_request_even_inside_background_work() {
    $result = agm_request('storefront page', 'shopper', function () use (&$response) {
        $response = agumail_run_in_background('http_guard_call');
    });
    expect_that(is_wp_error($response) && $response->get_error_code() === 'agumail_not_background', 'a storefront request was allowed to call AguMail');
    expect_same(array(), $result['http'], 'the refused call still went out');
}

function test_http_guard_refuses_cron_admin_and_admin_ajax_outside_background_work() {
    foreach (array('cron', 'admin', 'shopper_ajax', 'shopper_rest') as $kind) {
        $result = agm_request($kind . ' request', $kind, function () use (&$response) {
            $response = http_guard_call();
        });
        expect_that(is_wp_error($response), "a $kind request outside background work was allowed to call AguMail");
        expect_same(array(), $result['http'], "the refused call from a $kind request still went out");
    }
}

function test_http_guard_refuses_storefront_admin_ajax_even_inside_background_work() {
    // admin-ajax.php is is_admin() even when a shopper's theme calls it, so is_admin() alone proves nothing.
    $result = agm_request('storefront admin-ajax', 'shopper_ajax', function () use (&$response) {
        do_action('woocommerce_add_to_cart', 'key', 12, 1, 0, array(), array());
        $response = agumail_run_in_background('http_guard_call');
    });
    expect_that(is_wp_error($response) && $response->get_error_code() === 'agumail_not_background', 'background work on a shopper\'s admin-ajax request was allowed to call AguMail');
    expect_same(array(), $result['http'], 'the refused call still went out');
}

function test_http_guard_allows_admin_ajax_for_the_action_scheduler_runner_and_store_managers() {
    agumail_add_background_action('agm_test_job', function () {
        $GLOBALS['agm']['job_response'] = http_guard_call();
    });
    add_action('wp_ajax_nopriv_as_async_request_queue_runner', function () {
        do_action('agm_test_job');
    });
    $result = agm_request('action scheduler async runner', 'shopper_ajax', function () {
        do_action('wp_ajax_nopriv_as_async_request_queue_runner');
    });
    expect_same(1, count($result['http']), 'Action Scheduler\'s async runner could not call AguMail');

    agm_grant('manage_woocommerce');
    agumail_admin_register_action('agm_test_tool', function () {
        return http_guard_call();
    });
    $result = agm_request('store manager admin-ajax', 'shopper_ajax', function () {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'agm_test_tool', '_ajax_nonce' => agumail_admin_nonce('agm_test_tool')));
        do_action('wp_ajax_agumail');
    });
    expect_same(1, count($result['http']), 'a store manager\'s admin tool on admin-ajax could not call AguMail');
}

function test_http_guard_logs_a_refusal_without_the_address() {
    agm_request('storefront page', 'shopper', function () {
        agumail_run_in_background(function () {
            return agumail_api_request('GET', '/api/v1/contacts/status?email=ada@example.com');
        });
    });
    $refusals = agm_logs('refused');
    expect_same(1, count($refusals), 'a refused call should be logged once');
    expect_that(strpos($refusals[0]['message'], 'ada@example.com') === false, 'the log line carries the email address: ' . $refusals[0]['message']);
    expect_same('agumail', isset($refusals[0]['context']['source']) ? $refusals[0]['context']['source'] : null, 'plugin log lines belong to the agumail source');
}

// ── Allowed ──

function test_http_guard_allows_an_action_scheduler_callback_in_cron() {
    agumail_add_background_action('agm_test_job', function () {
        $GLOBALS['agm']['job_response'] = http_guard_call();
    });
    $result = agm_request('cron', 'cron', function () {
        do_action('agm_test_job');
    });
    expect_same(1, count($result['http']), 'the background job could not call AguMail from cron');
    $sent = $result['http'][0];
    expect_same('POST', $sent['method'], 'wrong method');
    expect_same('https://api.agumail.test/api/v1/contacts', $sent['url'], 'wrong URL');
    expect_same('Bearer agm_' . str_repeat('a', 40), $sent['headers']['Authorization'], 'the API key was not sent as a bearer token');
    expect_same('application/json', $sent['headers']['Content-Type'], 'the body is not declared as JSON');
    expect_same(15, $sent['timeout'], 'calls should time out after 15 seconds');
    expect_same(0, $sent['args']['redirection'], 'calls should never follow a redirect');
    expect_same(array('contacts' => array(array('email' => 'ada@example.com'))), $sent['body'], 'the body did not arrive as JSON');
    expect_same(array('code' => 200, 'headers' => array(), 'body' => array('ok' => true)), $GLOBALS['agm']['job_response'], 'wrong response shape');
    expect_that(!agumail_in_background(), 'background context outlived the job');
}

function test_http_guard_allows_an_admin_tool() {
    agm_grant('manage_woocommerce');
    agumail_admin_register_action('agm_test_tool', function () {
        $GLOBALS['agm']['tool_response'] = http_guard_call();
        return array('message' => 'Done.');
    });
    $result = agm_request('admin tool', 'admin', function () {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'agm_test_tool', '_wpnonce' => agumail_admin_nonce('agm_test_tool')));
        do_action('admin_post_agumail');
    });
    expect_same(1, count($result['http']), 'the admin tool could not call AguMail');
    expect_that(is_array($GLOBALS['agm']['tool_response']) && $GLOBALS['agm']['tool_response']['code'] === 200, 'the admin tool did not get the response');
}

function test_http_guard_allows_wp_cli_background_work() {
    define('WP_CLI', true);
    $result = agm_request('wp-cli', 'shopper', function () use (&$response) {
        $response = agumail_run_in_background('http_guard_call');
    });
    expect_same(1, count($result['http']), 'WP-CLI background work could not call AguMail');
}

function test_http_guard_background_context_ends_when_the_job_throws() {
    try {
        agumail_run_in_background(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {
        // expected
    }
    expect_that(!agumail_in_background(), 'a job that threw left the request in background context');
}

// ── Responses ──

function test_http_guard_returns_error_statuses_as_responses_and_network_failures_as_errors() {
    agm_http_respond(429, array('ok' => false, 'error' => 'Slow down', 'retryAfter' => 7), array('Retry-After' => '7'));
    agm_http_respond(500, 'not json');
    agm_http_respond(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));
    agm_request('cron', 'cron', function () use (&$responses) {
        $responses = array(
            agumail_run_in_background('http_guard_call'),
            agumail_run_in_background('http_guard_call'),
            agumail_run_in_background('http_guard_call'),
        );
    });
    expect_same(array('code' => 429, 'headers' => array('retry-after' => '7'), 'body' => array('ok' => false, 'error' => 'Slow down', 'retryAfter' => 7)), $responses[0], 'a 429 should come back as a response with its headers');
    expect_same(array('code' => 500, 'headers' => array(), 'body' => null), $responses[1], 'a body that is not JSON should come back as null');
    expect_that(is_wp_error($responses[2]) && $responses[2]->get_error_code() === 'http_request_failed', 'a network failure should come back as the WP_Error');
}

function test_http_guard_never_logs_an_email_address() {
    agm_http_respond(400, array('ok' => false, 'error' => 'contacts.0.email: ada@example.com is not deliverable'));
    agm_request('cron', 'cron', function () {
        agumail_run_in_background('http_guard_call');
    });
    foreach ($GLOBALS['agm']['logs'] as $entry) {
        expect_that(strpos($entry['message'] . json_encode($entry['context']), 'ada@example.com') === false, 'an email address was logged: ' . $entry['message']);
    }
    expect_that(count(agm_logs('a***@example.com')) === 1, 'the failed call was not logged with the address masked');
}

function test_http_guard_without_a_key_nothing_is_sent_unless_the_call_needs_none() {
    delete_option('agumail_api_key');
    $result = agm_request('cron', 'cron', function () use (&$keyed, &$open) {
        $keyed = agumail_run_in_background('http_guard_call');
        $open = agumail_run_in_background(function () {
            return agumail_api_request('POST', '/api/v1/connect/exchange', array('code' => 'c1.x.y'), array('auth' => false));
        });
    });
    expect_that(is_wp_error($keyed) && $keyed->get_error_code() === 'agumail_not_connected', 'a keyed call went ahead without a key');
    expect_same(1, count($result['http']), 'the call that needs no key was not sent');
    expect_that(!isset($result['http'][0]['headers']['Authorization']), 'a call that needs no key sent an Authorization header');
}

// ── Where AguMail lives ──

function test_http_guard_api_and_app_urls_default_and_can_be_overridden() {
    delete_option('agumail_api_url');
    expect_same('https://api.agumail.com', agumail_api_url(), 'wrong default API URL');
    expect_same('https://agumail.com', agumail_app_url(), 'wrong default app URL');
    update_option('agumail_api_url', 'https://eu.agumail.test/');
    expect_same('https://eu.agumail.test', agumail_api_url(), 'the saved API URL was not used');
    define('AGUMAIL_API_URL', 'http://localhost:3011');
    define('AGUMAIL_APP_URL', 'http://localhost:3010/');
    expect_same('http://localhost:3011', agumail_api_url(), 'the wp-config constant did not override the API URL');
    expect_same('http://localhost:3010', agumail_app_url(), 'the wp-config constant did not override the app URL');
}

// ── Admin tools: the other way into background work ──

function http_guard_post_tool($action, array $fields = array(), $nonce = null) {
    return agm_request('admin tool ' . $action, 'admin', function () use ($action, $fields, $nonce) {
        agm_input(array(), array_merge(array('action' => 'agumail', 'agumail_action' => $action, '_wpnonce' => $nonce === null ? agumail_admin_nonce($action) : $nonce), $fields));
        do_action('admin_post_agumail');
    });
}

function test_http_guard_admin_tools_need_the_capability_and_their_own_nonce() {
    $GLOBALS['agm']['ran'] = 0;
    agumail_admin_register_action('agm_test_tool', function () {
        $GLOBALS['agm']['ran']++;
        return 'Done.';
    });

    $result = http_guard_post_tool('agm_test_tool');
    expect_same('wp_die: You are not allowed to manage AguMail.', $result['halt'], 'a user without manage_woocommerce was not stopped');

    agm_grant('manage_woocommerce');
    $result = http_guard_post_tool('agm_test_tool', array(), agumail_admin_nonce('some_other_tool'));
    expect_that(strpos((string) $result['halt'], 'wp_die: This link has expired') === 0, 'another action\'s nonce was accepted');
    $result = http_guard_post_tool('agm_test_tool', array(), '');
    expect_that(strpos((string) $result['halt'], 'wp_die') === 0, 'a missing nonce was accepted');
    expect_same(0, $GLOBALS['agm']['ran'], 'the tool ran without passing the checks');

    $result = http_guard_post_tool('agm_test_tool');
    expect_same(1, $GLOBALS['agm']['ran'], 'the tool did not run');
    expect_same('redirect: https://shop.test/wp-admin/admin.php?page=agumail', $result['halt'], 'the merchant was not sent back to the settings page');

    $result = http_guard_post_tool('agm_no_such_tool');
    expect_same(array('type' => 'error', 'message' => 'Unknown AguMail action.'), get_transient('agumail_notice_0'), 'an unknown action was not reported');
}

function test_http_guard_an_admin_tool_gets_only_the_fields_it_names_each_cleaned() {
    agm_grant('manage_woocommerce');
    $GLOBALS['agm']['fields'] = null;
    agumail_admin_register_action('agm_fields', function (array $request) {
        $GLOBALS['agm']['fields'] = $request;
        return 'Done.';
    }, array('name' => 'text', 'count' => 'int', 'trigger' => 'key', 'label' => 'label', 'missing' => 'text', 'list' => 'text'));
    http_guard_post_tool('agm_fields', array(
        'name'    => "  Ada <b>Lovelace</b>\n",
        'count'   => '-12',
        'trigger' => 'Exit_Intent!',
        'label'   => 'Yes, <a href="https://shop.test/privacy">email me</a><script>x()</script>',
        'list'    => array('a', 'b'),
        'other'   => 'not asked for',
    ));
    expect_same(array(
        'name'    => 'Ada Lovelace',
        'count'   => 12,
        'trigger' => 'exit_intent',
        'label'   => 'Yes, <a href="https://shop.test/privacy">email me</a>x()',
    ), $GLOBALS['agm']['fields'], 'the action did not get exactly its own fields, cleaned');

    $GLOBALS['agm']['fields'] = null;
    $result = http_guard_post_tool('agm_fields', array(), array(agumail_admin_nonce('agm_fields')));
    expect_that(strpos((string) $result['halt'], 'wp_die: This link has expired') === 0, 'a nonce sent as a list was accepted');
    expect_same(null, $GLOBALS['agm']['fields'], 'the action ran without a usable nonce');
}

function test_http_guard_an_admin_tool_may_send_the_merchant_to_agumail_but_nowhere_else() {
    agm_grant('manage_woocommerce');
    define('AGUMAIL_APP_URL', 'https://app.agumail.test');
    agumail_admin_register_action('agm_connect', function () {
        return array('redirect' => 'https://app.agumail.test/connect/woocommerce?store=https%3A%2F%2Fshop.test');
    });
    agumail_admin_register_action('agm_elsewhere', function () {
        return array('redirect' => 'https://evil.test/');
    });
    $result = http_guard_post_tool('agm_connect');
    expect_same('redirect: https://app.agumail.test/connect/woocommerce?store=https%3A%2F%2Fshop.test', $result['halt'], 'the merchant was not sent to AguMail to approve the connection');
    $result = http_guard_post_tool('agm_elsewhere');
    expect_that(strpos((string) $result['halt'], 'evil.test') === false, 'an admin tool redirected to a host that is not AguMail');
    expect_that(!has_filter('allowed_redirect_hosts'), 'AguMail stayed an allowed redirect host after its own redirect');
}

function test_http_guard_admin_tools_answer_scripts_with_json() {
    agm_grant('manage_woocommerce');
    agumail_admin_register_action('agm_progress', function (array $request) {
        return array('done' => 40, 'total' => 100, 'asked' => $request['step']);
    }, array('step' => 'text'));
    agumail_admin_register_action('agm_failing', function () {
        return new WP_Error('agumail_sync_busy', 'A sync is already running.');
    });
    agm_request('poll', 'admin', function () {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'agm_progress', '_ajax_nonce' => agumail_admin_nonce('agm_progress'), 'step' => '2'));
        do_action('wp_ajax_agumail');
    });
    agm_request('poll failing', 'admin', function () {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'agm_failing', '_ajax_nonce' => agumail_admin_nonce('agm_failing')));
        do_action('wp_ajax_agumail');
    });
    agm_request('poll without a nonce', 'admin', function () {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'agm_progress'));
        do_action('wp_ajax_agumail');
    });
    $json = $GLOBALS['agm']['json'];
    expect_same(array('success' => true, 'data' => array('done' => 40, 'total' => 100, 'asked' => '2')), $json[0]['body'], 'wrong JSON for a working action');
    expect_same(array(array('success' => false, 'data' => array('message' => 'A sync is already running.', 'code' => 'agumail_sync_busy')), 400), array($json[1]['body'], $json[1]['status']), 'wrong JSON for a failing action');
    expect_same(403, $json[2]['status'], 'a script without a nonce was not refused');
}

function test_http_guard_the_settings_page_lists_sections_in_order_with_a_connection_stand_in() {
    agm_grant('manage_woocommerce');
    do_action('init');
    // Other features add sections of their own; only these four are checked, and
    // a feature's own Connection section is taken out so the stand-in shows.
    $registry = &agumail_admin_registry();
    unset($registry['sections']['connection']);
    agumail_admin_register_section('agm_last', 'Last', function () { echo '<p>last</p>'; }, 1000);
    agumail_admin_register_section('agm_first', 'First', function () { echo '<p>first</p>'; }, -1000);
    ob_start();
    agumail_admin_render_page();
    $html = ob_get_clean();
    preg_match_all('/<h2>([^<]+)<\/h2>/', $html, $titles);
    $ours = array_values(array_intersect($titles[1], array('First', 'Connection', 'Checkout', 'Last')));
    expect_same(array('First', 'Connection', 'Checkout', 'Last'), $ours, 'wrong sections or order');
    expect_that(strpos($html, 'https://api.agumail.test') !== false, 'the stand-in does not say where AguMail is');

    agumail_admin_register_section('connection', 'Connect to AguMail', function () { echo '<p>real</p>'; }, 10);
    ob_start();
    agumail_admin_render_page();
    $html = ob_get_clean();
    expect_that(strpos($html, 'Connect to AguMail') !== false && strpos($html, 'https://api.agumail.test') === false, 'a feature\'s own connection section did not replace the stand-in');
}

function test_http_guard_the_settings_page_is_a_top_level_menu_for_store_managers() {
    do_action('admin_menu');
    $menu = $GLOBALS['agm']['menus'][0];
    expect_same(array('AguMail', 'AguMail', 'manage_agumail', 'agumail'), array_slice($menu, 0, 4), 'wrong menu entry');
    expect_same('toplevel_page_agumail', agumail_admin_hook_suffix(), 'the page\'s hook suffix was not kept');
    // On a store, managing AguMail is managing WooCommerce.
    agm_grant('manage_woocommerce');
    expect_that(current_user_can(AGUMAIL_ADMIN_CAPABILITY), 'a store manager cannot open the settings page');
    agm_grant('manage_woocommerce', false);
    agm_grant('manage_options');
    expect_that(!current_user_can(AGUMAIL_ADMIN_CAPABILITY), 'an administrator who does not manage the store can open the settings page');
}

// ── Store staff and the key ──

function test_http_guard_staff_are_store_managers_unless_a_filter_says_otherwise() {
    agm_add_user(7, 'bea@example.com');
    agm_add_user(8, 'manager@example.com');
    $GLOBALS['agm']['user_caps'][8]['manage_woocommerce'] = true;
    expect_that(!agumail_is_staff(7), 'a customer counted as staff');
    expect_that(agumail_is_staff(8), 'a store manager did not count as staff');
    agm_sign_in(8);
    expect_that(agumail_is_staff(), 'the signed-in store manager did not count as staff');
    agm_sign_out();
    expect_that(!agumail_is_staff(), 'a visitor counted as staff');
    add_filter('agumail_is_staff', function ($staff, $user_id) { return $user_id === 7 ? true : $staff; }, 10, 2);
    expect_that(agumail_is_staff(7), 'the agumail_is_staff filter was ignored');
}

function test_http_guard_the_key_is_autoloaded_because_every_storefront_request_reads_it() {
    agumail_save_api_key('  agm_' . str_repeat('c', 40) . ' ');
    expect_same('agm_' . str_repeat('c', 40), get_option('agumail_api_key'), 'the key was not saved clean');
    expect_same(true, $GLOBALS['agm']['autoload']['agumail_api_key'], 'the key was saved without autoload');

    update_option('agumail_api_key', 'agm_' . str_repeat('d', 40), false);
    agm_grant('manage_woocommerce');
    agm_request('merchant opens wp-admin', 'admin', function () {
        do_action('admin_init');
    });
    expect_same(true, $GLOBALS['agm']['autoload']['agumail_api_key'], 'maintenance did not switch autoload back on for a key saved without it');
}
