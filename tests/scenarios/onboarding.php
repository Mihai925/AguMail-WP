<?php
// Onboarding: from activating the plugin to a connected store. The merchant
// who activates it lands on the settings page, once; the Plugins row links to
// Connect until the store is connected, then to Settings; and a notice on the
// Dashboard and Plugins screens offers the one-click connect until the store
// is connected or the merchant dismisses it.

if (!function_exists('wc_get_screen_ids')) {
    /** A few of WooCommerce's own admin screens, as wc_get_screen_ids() lists them. */
    function wc_get_screen_ids() {
        return array('toplevel_page_woocommerce', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-settings', 'edit-product', 'product');
    }
}

// WooCommerce has loaded, as on every store the settings page can open on; a
// test without it takes this back (onboarding_without_woocommerce()).
do_action('woocommerce_loaded');

// ── Helpers ──

define('ONBOARDING_SETTINGS_URL', 'https://shop.test/wp-admin/admin.php?page=agumail');

/** A store that has not connected yet: no key. The setup write is forgotten. */
function onboarding_not_connected() {
    delete_option('agumail_api_key');
    $GLOBALS['agm']['writes'] = array();
}

/** Signs in a user who manages the store (or not). */
function onboarding_sign_in($id, $manages_store = true) {
    agm_sign_in($id, 'staff' . $id . '@shop.test');
    agm_grant('manage_woocommerce', $manages_store);
}

/** Activates the plugin as WordPress does from plugins.php (or admin-ajax.php), with that request's fields. */
function onboarding_activate(array $request = array('action' => 'activate', 'plugin' => 'agumail/agumail.php'), $network_wide = false, $kind = 'admin') {
    return agm_request('activate the plugin', $kind, function () use ($request, $network_wide) {
        agm_input($request);
        foreach ($GLOBALS['agm']['activation'] as $callback) {
            call_user_func($callback, $network_wide);
        }
    });
}

/** A wp-admin page (or admin-ajax call) as the signed-in user opens it: admin_init runs first. */
function onboarding_open(array $get = array('activate' => 'true'), $kind = 'admin') {
    return agm_request('open a wp-admin page', $kind, function () use ($get) {
        agm_input($get);
        do_action('admin_init');
    });
}

/** Where a request redirected to, or null. */
function onboarding_redirect_of(array $result) {
    return is_string($result['halt']) && strpos($result['halt'], 'redirect: ') === 0 ? substr($result['halt'], strlen('redirect: ')) : null;
}

/** A site where WooCommerce is not active (WordPress before 6.5 does not hold the plugin back without it). */
function onboarding_without_woocommerce() {
    unset($GLOBALS['agm']['did']['woocommerce_loaded']);
}

function onboarding_armed_for() {
    return get_transient('agumail_activation_redirect');
}

/** The notice as a screen draws it. */
function onboarding_notice_on($screen) {
    $GLOBALS['agm']['screen'] = $screen;
    try {
        return agm_capture(function () { do_action('admin_notices'); });
    } finally {
        $GLOBALS['agm']['screen'] = null;
    }
}

/** A link's href from printed HTML, found by its text. */
function onboarding_href($html, $text) {
    expect_that((bool) preg_match('#<a [^>]*href="([^"]+)"[^>]*>' . preg_quote($text, '#') . '</a>#', $html, $match), "no \"$text\" link in: $html");
    return html_entity_decode($match[1], ENT_QUOTES);
}

/** Follows an admin-post.php link the way a click does: a GET request to the central handler. */
function onboarding_follow($href, $referer = '') {
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
    return agm_request('follow ' . $href, 'admin', function () use ($query, $referer) {
        agm_input($query);
        if ($referer !== '') {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        try {
            do_action('admin_post_' . $query['action']);
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }
    });
}

// ── The redirect after activation ──

function test_onboarding_activating_sends_that_merchant_to_the_settings_page_once() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    onboarding_activate();
    expect_same(1, onboarding_armed_for(), 'activating did not remember who to send to the settings page');

    $first = onboarding_open();
    expect_same(ONBOARDING_SETTINGS_URL, onboarding_redirect_of($first), 'the merchant was not sent to the settings page');
    expect_same(false, onboarding_armed_for(), 'the redirect is still armed after it happened');
    expect_same(array(array('kind' => 'transient', 'name' => 'agumail_activation_redirect', 'detail' => 'delete', 'request' => 'open a wp-admin page')),
        $first['writes'], 'the redirect should only remove its mark, before redirecting');
    expect_same(array(), $first['http'], 'the redirect called AguMail');

    expect_same(null, onboarding_redirect_of(onboarding_open()), 'the merchant was sent to the settings page a second time');
}

function test_onboarding_only_the_merchant_who_activated_is_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    onboarding_activate();
    onboarding_sign_in(2);
    expect_same(null, onboarding_redirect_of(onboarding_open()), 'another administrator was sent to the settings page');
    expect_same(1, onboarding_armed_for(), 'another administrator\'s page took the mark away');
    onboarding_sign_in(1);
    expect_same(ONBOARDING_SETTINGS_URL, onboarding_redirect_of(onboarding_open()), 'the merchant who activated was not redirected after another administrator\'s page');
}

function test_onboarding_a_merchant_who_cannot_manage_the_store_is_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1, false);
    onboarding_activate();
    expect_same(null, onboarding_redirect_of(onboarding_open()), 'a user without manage_woocommerce was sent to a page they cannot open');
    expect_same(false, onboarding_armed_for(), 'the mark stayed for a user who will never be redirected');
}

function test_onboarding_admin_ajax_leaves_the_redirect_for_the_next_page() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    onboarding_activate();
    expect_same(null, onboarding_redirect_of(onboarding_open(array('action' => 'heartbeat'), 'shopper_ajax')), 'an admin-ajax call was redirected');
    expect_same(null, onboarding_redirect_of(onboarding_open(array(), 'cron')), 'a cron run was redirected');
    expect_same(1, onboarding_armed_for(), 'admin-ajax or cron took the mark away');
    expect_same(ONBOARDING_SETTINGS_URL, onboarding_redirect_of(onboarding_open()), 'the next page was not redirected');
}

function test_onboarding_the_settings_page_itself_is_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    onboarding_activate();
    expect_same(null, onboarding_redirect_of(onboarding_open(array('page' => 'agumail'))), 'the settings page redirected to itself');
    expect_same(false, onboarding_armed_for(), 'the mark stayed after the merchant reached the settings page');
}

function test_onboarding_a_store_already_connected_is_not_redirected() {
    onboarding_sign_in(1);
    onboarding_activate();
    expect_same(false, onboarding_armed_for(), 'a connected store was armed for the redirect');

    onboarding_not_connected();
    onboarding_activate();
    agumail_save_api_key('agm_' . str_repeat('b', 40));
    expect_same(null, onboarding_redirect_of(onboarding_open()), 'a store connected since activation was still redirected');
}

function test_onboarding_network_and_bulk_activations_are_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    onboarding_activate(array('action' => 'activate', 'plugin' => 'agumail/agumail.php'), true);
    expect_same(false, onboarding_armed_for(), 'a network activation was armed');
    foreach (array(array('action' => 'activate-selected'), array('action' => '-1', 'action2' => 'activate-selected'), array('activate-multi' => 'true')) as $request) {
        onboarding_activate($request);
        expect_same(false, onboarding_armed_for(), 'a bulk activation was armed: ' . json_encode($request));
    }
    // The Plugins screen a bulk activation lands on never redirects, whatever is armed.
    set_transient('agumail_activation_redirect', 1, 60);
    expect_same(null, onboarding_redirect_of(onboarding_open(array('activate-multi' => 'true'))), 'the bulk activation\'s landing page was redirected');
}

function test_onboarding_wp_cli_activation_is_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    define('WP_CLI', true);
    onboarding_activate();
    expect_same(false, onboarding_armed_for(), 'a WP-CLI activation was armed');
}

function test_onboarding_rest_api_activation_is_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    define('REST_REQUEST', true);
    onboarding_activate(array());
    expect_same(false, onboarding_armed_for(), 'a REST API activation was armed');
}

function test_onboarding_an_activation_from_admin_ajax_is_not_redirected() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    // The Add Plugins screen activates over admin-ajax.php, and the merchant stays where they are.
    onboarding_activate(array('action' => 'activate-plugin', 'plugin' => 'agumail/agumail.php'), false, 'shopper_ajax');
    expect_same(false, onboarding_armed_for(), 'an admin-ajax activation was armed, so a later page would jump to AguMail');
}

function test_onboarding_without_woocommerce_an_administrator_is_onboarded_the_same_way() {
    // The page is AguMail's own menu item, so it opens on any site, for whoever runs it.
    onboarding_not_connected();
    onboarding_without_woocommerce();
    agm_sign_in(1, 'admin1@site.test');
    agm_grant('manage_options');
    onboarding_activate();
    expect_same(ONBOARDING_SETTINGS_URL, onboarding_redirect_of(onboarding_open()), 'activating did not lead the administrator to the AguMail page');
    $notice = onboarding_notice_on('plugins');
    expect_that(strpos($notice, 'Grow your email list with AguMail') !== false, 'no notice on a site without WooCommerce');
    expect_that(strpos($notice, 'href="' . ONBOARDING_SETTINGS_URL . '"') !== false, 'the notice does not lead to the AguMail page');
    expect_that(strpos($notice, 'agumail_action=connect_start') === false, 'the notice offers the one-click connect, which is a store\'s');
    expect_that(strpos($notice, 'https://agumail.com/signup?utm_source=wordpress&amp;utm_medium=plugin&amp;utm_campaign=notice') !== false,
        'the notice does not offer a free account');
    expect_same('', onboarding_notice_on('woocommerce_page_wc-settings'), 'a notice on a WooCommerce screen of a site without it');
    $theirs = array('deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>');
    $links = apply_filters('plugin_action_links_' . plugin_basename(AGUMAIL_FILE), $theirs, plugin_basename(AGUMAIL_FILE), array(), 'all');
    expect_same('<a href="' . ONBOARDING_SETTINGS_URL . '">Connect</a>', $links[0], 'the Plugins row does not lead with Connect');

    // A shop manager left over from WooCommerce does not run the site.
    agm_grant('manage_options', false);
    agm_grant('manage_woocommerce');
    expect_same('', onboarding_notice_on('plugins'), 'a shop manager saw the notice without WooCommerce');
}

function test_onboarding_activation_without_a_signed_in_user_is_not_redirected() {
    onboarding_not_connected();
    agm_sign_out();
    onboarding_activate();
    expect_same(false, onboarding_armed_for(), 'an activation by nobody was armed');
}

// ── The Plugins row ──

function test_onboarding_the_plugins_row_links_to_connect_then_to_settings() {
    onboarding_sign_in(1);
    $hook = 'plugin_action_links_' . plugin_basename(AGUMAIL_FILE);
    $theirs = array('deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>');

    onboarding_not_connected();
    $links = apply_filters($hook, $theirs, plugin_basename(AGUMAIL_FILE), array(), 'all');
    expect_same(array('<a href="' . ONBOARDING_SETTINGS_URL . '">Connect</a>', 'deactivate' => $theirs['deactivate']), $links, 'the row does not lead with Connect');

    agumail_save_api_key('agm_' . str_repeat('b', 40));
    $links = apply_filters($hook, $theirs, plugin_basename(AGUMAIL_FILE), array(), 'all');
    expect_same(array('<a href="' . ONBOARDING_SETTINGS_URL . '">Settings</a>', 'deactivate' => $theirs['deactivate']), $links, 'a connected store\'s row does not lead with Settings');

    agm_grant('manage_woocommerce', false);
    expect_same($theirs, apply_filters($hook, $theirs, plugin_basename(AGUMAIL_FILE), array(), 'all'), 'a user who cannot open the settings page got a link to it');
}

// ── The connect notice ──

function test_onboarding_the_notice_shows_on_the_dashboard_and_plugins_screens_only() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    foreach (array('dashboard', 'plugins') as $screen) {
        expect_that(strpos(onboarding_notice_on($screen), 'Connect AguMail to start syncing your customers, orders and carts.') !== false, "no connect notice on $screen");
    }
    foreach (array('woocommerce_page_wc-settings', 'woocommerce_page_wc-orders', 'edit-product', 'toplevel_page_agumail', 'edit-post', 'options-general', null) as $screen) {
        expect_same('', onboarding_notice_on($screen), 'a connect notice on ' . var_export($screen, true));
    }
    expect_same(array(), agm_writes(), 'showing the notice wrote something');
}

function test_onboarding_the_notice_is_only_for_a_store_manager_of_a_store_not_connected() {
    onboarding_not_connected();
    onboarding_sign_in(1, false);
    expect_same('', onboarding_notice_on('dashboard'), 'a user who cannot manage the store saw the connect notice');
    onboarding_sign_in(1);
    agumail_save_api_key('agm_' . str_repeat('b', 40));
    expect_same('', onboarding_notice_on('dashboard'), 'a connected store saw the connect notice');
}

function test_onboarding_the_notice_button_starts_the_one_click_connect() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    do_action('init');
    $html = onboarding_notice_on('plugins');
    $connect = onboarding_href($html, 'Connect to AguMail');
    expect_same(agumail_admin_action_url('connect_start'), $connect, 'the button does not run the connect action');
    expect_that(strpos($html, 'class="button button-primary"') !== false, 'Connect to AguMail is not the primary button');

    $result = onboarding_follow($connect, 'https://shop.test/wp-admin/plugins.php');
    $to = onboarding_redirect_of($result);
    expect_that($to !== null && strpos($to, agumail_app_url() . '/connect/woocommerce?') === 0, 'the button did not go to AguMail to approve: ' . var_export($to, true));
    expect_same(array(), $result['http'], 'starting the connect called AguMail from WordPress');
}

function test_onboarding_dismissing_the_notice_keeps_it_away_and_goes_back() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    do_action('init');
    $dismiss = onboarding_href(onboarding_notice_on('plugins'), 'Dismiss');
    expect_same(agumail_admin_action_url('dismiss_connect_notice'), $dismiss, 'Dismiss does not go through an admin action');

    $result = onboarding_follow($dismiss, 'https://shop.test/wp-admin/plugins.php');
    expect_same('https://shop.test/wp-admin/plugins.php', onboarding_redirect_of($result), 'the merchant was not sent back to the page they dismissed it on');
    expect_same('yes', get_option('agumail_connect_notice_dismissed'), 'dismissing was not remembered');
    expect_same(false, get_transient('agumail_notice_1'), 'dismissing left a message for the settings page');
    foreach (array('dashboard', 'plugins', 'woocommerce_page_wc-settings') as $screen) {
        expect_same('', onboarding_notice_on($screen), "the dismissed notice came back on $screen");
    }
}

function test_onboarding_dismissing_needs_the_nonce_and_the_capability() {
    onboarding_not_connected();
    onboarding_sign_in(1);
    do_action('init');
    $dismiss = onboarding_href(onboarding_notice_on('dashboard'), 'Dismiss');

    $forged = onboarding_follow(str_replace('_wpnonce=' . agumail_admin_nonce('dismiss_connect_notice'), '_wpnonce=forged', $dismiss));
    expect_that(strpos((string) $forged['halt'], 'wp_die') === 0, 'a forged dismiss was not refused');

    agm_grant('manage_woocommerce', false);
    $unprivileged = onboarding_follow($dismiss);
    expect_that(strpos((string) $unprivileged['halt'], 'wp_die') === 0, 'a dismiss by a user who cannot manage the store was not refused');
    expect_same(false, get_option('agumail_connect_notice_dismissed'), 'a refused dismiss was remembered');
}

// ── Store sync and the plan ──

function test_onboarding_a_store_hears_once_when_its_plan_holds_or_offers_store_sync() {
    onboarding_sign_in(1);
    update_option(AGUMAIL_STORE_HOLD_OPTION, time());
    update_option('agumail_store_notice', 'held');
    $notice = onboarding_notice_on('dashboard');
    expect_that(strpos($notice, 'AguMail paused store sync: your plan does not include it.') !== false, 'a held store is not told: ' . $notice);
    expect_that(strpos($notice, 'https://agumail.com/pricing?') !== false, 'the notice does not lead to the plans');
    expect_same('', onboarding_notice_on('toplevel_page_agumail'), 'the notice repeats what the AguMail page says');

    // The store sync came back meanwhile: a held notice has nothing to say.
    delete_option(AGUMAIL_STORE_HOLD_OPTION);
    expect_same('', onboarding_notice_on('dashboard'), 'a held notice outlived the hold');

    update_option('agumail_connection', array('status' => 'connected', 'platform' => 'wordpress'));
    update_option('agumail_store_notice', 'offer');
    $notice = onboarding_notice_on('plugins');
    expect_that(strpos($notice, 'AguMail can sync your store too.') !== false, 'a site that gained WooCommerce is not told: ' . $notice);
    expect_that(strpos($notice, 'https://agumail.com/pricing?') !== false, 'the offer does not lead to the plans');

    update_option('agumail_store_notice', 'available');
    $notice = onboarding_notice_on('plugins');
    expect_that(strpos($notice, 'agumail_action=connect_store') !== false, 'Sync your store is not offered: ' . $notice);

    agm_request('dismiss', 'admin', function () {
        agm_input(array('action' => 'agumail', 'agumail_action' => 'dismiss_store_notice', '_wpnonce' => agumail_admin_nonce('dismiss_store_notice')));
        do_action('init');
        do_action('admin_post_agumail');
    });
    expect_same('', onboarding_notice_on('plugins'), 'the notice came back after Dismiss');
}
