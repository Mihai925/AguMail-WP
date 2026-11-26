<?php
// Signup forms on the store: the [agumail_form] shortcode, the agumail/form
// block, and the site-wide popup. All three print an inert placeholder the
// AguMail loader turns into the form, and read only what is stored here: the
// list of forms is cached by housekeeping or the Refresh button, never fetched
// while a page is built.

// The loader loads only from AguMail's own hosts or one wp-config.php pins.
// This store pins the test API, as a development site would, so the
// addresses below count as AguMail's.
define('AGUMAIL_API_URL', 'https://api.agumail.test');
define('FORMS_LOADER', 'https://api.agumail.test/embed/agumail-forms.js');

function forms_cache($forms = null, $loader = FORMS_LOADER) {
    return array(
        'loaderUrl' => $loader,
        'forms'     => $forms === null ? array(
            array('slug' => 'fnews01', 'name' => 'Newsletter', 'doubleOptIn' => true, 'publishedAt' => '2026-09-01T10:00:00Z'),
            array('slug' => 'fvip002', 'name' => 'VIP <list>', 'doubleOptIn' => false, 'publishedAt' => '2026-09-02T10:00:00Z'),
        ) : $forms,
        'fetchedAt' => time() - HOUR_IN_SECONDS,
    );
}

function forms_connected() {
    update_option('agumail_config', array('siteKey' => 'site_abc123', 'formsLoaderUrl' => FORMS_LOADER));
    update_option('agumail_forms_cache', forms_cache());
    do_action('init');
}

function forms_merchant() {
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    do_action('init');
}

function forms_admin_action($action, array $fields = array()) {
    return agm_request('admin: ' . $action, 'admin', function () use ($action, $fields) {
        agm_input(array(), array_merge(array('action' => 'agumail', 'agumail_action' => $action, '_wpnonce' => agumail_admin_nonce($action)), $fields));
        do_action('admin_post_agumail');
    });
}

/** A storefront page of a kind (home, cart, checkout, account, product): returns what it did and printed. */
function forms_page($page = 'home', $content = null) {
    $GLOBALS['agm']['page'] = $page;
    // A new request: no form is in the page yet.
    $inline = &agumail_forms_inline_slugs();
    $inline = array();
    $html = '';
    $result = agm_request('storefront ' . $page, 'shopper', function () use (&$html, $content) {
        $html = agm_capture(function () use ($content) {
            do_action('init');
            do_action('wp_enqueue_scripts');
            if ($content) {
                echo $content();
            }
            do_action('wp_footer');
        });
    });
    $result['html'] = $html;
    return $result;
}

function forms_loader() {
    return isset($GLOBALS['agm']['scripts']['agumail-forms']) ? $GLOBALS['agm']['scripts']['agumail-forms'] : null;
}

// ── The shortcode ──

function test_forms_the_shortcode_prints_an_inert_placeholder_the_loader_boots_on() {
    forms_connected();
    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'delay' => '5', 'scroll' => '50', 'exit_intent' => 'yes', 'frequency_days' => '7'));
    expect_that(preg_match('/<script type="text\/plain" data-agumail-form="fnews01" data-mode="popup" data-delay="5" data-scroll="50" data-exit-intent="yes" data-frequency-days="7"><\/script>/', $html) === 1, 'wrong popup placeholder: ' . $html);
    expect_that(strpos($html, ' src=') === false, 'the placeholder loads a script itself');

    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01'));
    expect_that(preg_match('/<script type="text\/plain" data-agumail-form="fnews01" data-mode="inline"><\/script>/', $html) === 1, 'wrong inline placeholder: ' . $html);
    expect_that(strpos($html, 'data-delay') === false && strpos($html, 'data-exit-intent') === false, 'an inline form carries popup triggers: ' . $html);
}

function test_forms_the_shortcode_leaves_out_triggers_it_was_not_given() {
    forms_connected();
    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup'));
    expect_that(preg_match('/data-mode="popup"/', $html) === 1, 'not a popup: ' . $html);
    // The loader reads data-delay and data-exit-intent by presence, so an absent value must stay absent.
    foreach (array('data-delay', 'data-scroll', 'data-exit-intent', 'data-frequency-days') as $attribute) {
        expect_that(strpos($html, $attribute) === false, "$attribute was printed without being asked for: $html");
    }
    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'exit_intent' => 'no', 'scroll' => '0'));
    expect_that(strpos($html, 'data-exit-intent') === false && strpos($html, 'data-scroll') === false, 'a "no" turned a trigger on: ' . $html);
}

function test_forms_the_shortcode_escapes_and_cleans_what_it_is_given() {
    forms_connected();
    expect_same('', agm_shortcode('agumail_form', array('slug' => 'x"><script>alert(1)</script>')), 'a slug that is not one was printed');
    expect_same('', agm_shortcode('agumail_form', array()), 'a form with no slug was printed');
    expect_same('', agm_shortcode('agumail_form', ''), 'a bare [agumail_form] was printed');

    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'banner"', 'delay' => '-3', 'scroll' => '250', 'frequency_days' => 'soon"'));
    expect_that(strpos($html, 'data-mode="inline"') !== false, 'an unknown mode was not treated as inline: ' . $html);
    expect_that(strpos($html, '"banner') === false && strpos($html, 'soon') === false, 'raw input was printed: ' . $html);

    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'delay' => '-3', 'scroll' => '250', 'frequency_days' => '9999'));
    expect_that(strpos($html, 'data-delay="0"') !== false, 'a negative delay was not clamped: ' . $html);
    expect_that(strpos($html, 'data-scroll="100"') !== false, 'a scroll past 100% was not clamped: ' . $html);
    expect_that(strpos($html, 'data-frequency-days="365"') !== false, 'the frequency was not clamped: ' . $html);

    // The least is a day, as the loader itself clamps it to 1..365.
    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'frequency_days' => '0'));
    expect_that(strpos($html, 'data-frequency-days="1"') !== false, 'a frequency of 0 was not raised to 1: ' . $html);
}

function test_forms_the_loader_is_enqueued_once_in_the_footer() {
    forms_connected();
    $page = forms_page('home', function () {
        return agm_shortcode('agumail_form', array('slug' => 'fnews01')) . agm_shortcode('agumail_form', array('slug' => 'fvip002'));
    });
    $loader = forms_loader();
    expect_that($loader && $loader['enqueued'], 'the loader was not enqueued');
    expect_same(FORMS_LOADER, $loader['src'], 'wrong loader address');
    expect_same(2, substr_count($page['html'], 'data-agumail-form='), 'both forms should be on the page');
    expect_same(0, substr_count($page['html'], FORMS_LOADER), 'the loader was printed inline instead of enqueued');
    expect_same(1, count(array_filter(array_keys($GLOBALS['agm']['scripts']), function ($handle) { return strpos($handle, 'agumail-forms') === 0; })), 'the loader was registered more than once');
    expect_same(array(), $page['http'], 'the page called AguMail');
}

function test_forms_the_loader_falls_back_to_the_cached_address() {
    update_option('agumail_forms_cache', forms_cache(null, 'https://api.agumail.test/embed/cached-loader.js'));
    do_action('init');
    $html = agm_shortcode('agumail_form', array('slug' => 'fnews01'));
    expect_that(strpos($html, 'data-agumail-form="fnews01"') !== false, 'the form did not render from the cache alone');
    expect_same('https://api.agumail.test/embed/cached-loader.js', forms_loader()['src'], 'the cached loader was not used');
}

function test_forms_nothing_is_printed_without_a_loader() {
    do_action('init');
    expect_same('', agm_shortcode('agumail_form', array('slug' => 'fnews01')), 'a placeholder was printed that nothing will load');
    expect_same(null, forms_loader(), 'a loader with no address was enqueued');
}

function test_forms_nothing_is_enqueued_on_a_page_without_a_form() {
    forms_connected();
    forms_page('home');
    expect_same(null, forms_loader(), 'the loader was added to a page without a form');
}

// ── The block ──

function test_forms_the_block_is_registered_with_the_shortcode_render() {
    forms_connected();
    expect_that(isset($GLOBALS['agm']['blocks']['agumail/form']), 'the agumail/form block is not registered');
    $block = $GLOBALS['agm']['blocks']['agumail/form'];
    expect_that(is_callable($block['render_callback']), 'the block has no server render');
    expect_same('agumail-form-block', $block['editor_script'], 'the block has no editor script');
    expect_same(3, $block['api_version'], 'the block is not on block API version 3');
    expect_that(isset($GLOBALS['agm']['scripts']['agumail-form-block']), 'the editor script is not registered');
    expect_same('https://shop.test/wp-content/plugins/agumail/assets/js/block.js', $GLOBALS['agm']['scripts']['agumail-form-block']['src'], 'wrong editor script');
    expect_same(false, $GLOBALS['agm']['scripts']['agumail-form-block']['enqueued'], 'the editor script was enqueued outside the editor');
    foreach (array('slug', 'mode', 'delay', 'scroll', 'exitIntent', 'frequencyDays') as $attribute) {
        expect_that(isset($block['attributes'][$attribute]), "the block has no $attribute attribute");
    }

    $from_block = call_user_func($block['render_callback'], array('slug' => 'fnews01', 'mode' => 'popup', 'delay' => 5, 'scroll' => 50, 'exitIntent' => true, 'frequencyDays' => 7));
    $from_shortcode = agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'delay' => '5', 'scroll' => '50', 'exit_intent' => 'yes', 'frequency_days' => '7'));
    expect_same($from_shortcode, $from_block, 'the block and the shortcode render differently');

    $from_block = call_user_func($block['render_callback'], array('slug' => 'fnews01', 'mode' => 'popup', 'exitIntent' => false));
    expect_same(agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup')), $from_block, 'a block without triggers renders differently');
    expect_same('', call_user_func($block['render_callback'], array()), 'a block with no form picked printed something');
}

function test_forms_the_block_editor_script_uses_the_wordpress_globals($plugin_dir) {
    $source = file_get_contents($plugin_dir . '/assets/js/block.js');
    expect_that($source !== false, 'assets/js/block.js is missing');
    foreach (array("registerBlockType('agumail/form'", 'wp.blocks', 'wp.element', 'InspectorControls', 'SelectControl', 'TextControl', 'ToggleControl', 'wp.apiFetch', "'/agumail/v1/forms'") as $needle) {
        expect_that(strpos($source, $needle) !== false, "block.js does not use $needle");
    }
    expect_that(strpos($source, 'import ') === false && strpos($source, 'require(') === false, 'block.js needs a build step');
    expect_that(strpos($source, 'apiVersion: 3') !== false, 'block.js is not on block API version 3');
    $controls = preg_match_all('/el\((?:components\.)?(SelectControl|TextControl|ToggleControl),\s*\{/', $source);
    expect_same($controls, substr_count($source, '__nextHasNoMarginBottom: true'), 'not every control opts into the new bottom margin');
    expect_same($controls, substr_count($source, '__next40pxDefaultSize: true'), 'not every control opts into the 40px size');
    expect_that($controls >= 6, 'fewer controls than expected: ' . $controls);
}

// ── The REST route the block's picker reads ──

function test_forms_the_rest_route_lists_the_cached_forms_and_never_calls_agumail() {
    forms_connected();
    do_action('rest_api_init');
    expect_that(isset($GLOBALS['agm']['rest_routes']['agumail/v1/forms']), 'the agumail/v1/forms route is not registered');
    $route = $GLOBALS['agm']['rest_routes']['agumail/v1/forms'];
    expect_same('GET', $route['methods'], 'the route should be GET');

    expect_same(false, call_user_func($route['permission_callback']), 'somebody who cannot edit posts may list the forms');
    agm_grant('edit_posts');
    expect_same(true, call_user_func($route['permission_callback']), 'an editor may not list the forms');

    $result = agm_request('rest: list forms', 'shopper_rest', function () use ($route, &$answer) {
        $answer = call_user_func($route['callback'], new WP_REST_Request('GET', '/agumail/v1/forms'));
    });
    expect_same(array(), $result['http'], 'listing forms called AguMail');
    expect_same(array(), $result['writes'], 'listing forms wrote something');
    expect_same(array(
        array('slug' => 'fnews01', 'name' => 'Newsletter', 'doubleOptIn' => true),
        array('slug' => 'fvip002', 'name' => 'VIP <list>', 'doubleOptIn' => false),
    ), $answer['forms'], 'the cached forms were not listed');
    expect_same(true, $answer['ready'], 'a store with a loader is not ready');

    delete_option('agumail_forms_cache');
    delete_option('agumail_config');
    $answer = call_user_func($route['callback'], new WP_REST_Request('GET', '/agumail/v1/forms'));
    expect_same(array(), $answer['forms'], 'an empty cache listed forms');
    expect_same(false, $answer['ready'], 'a store without a loader is ready');
}

// ── The site-wide popup ──

function forms_set_popup(array $changes = array()) {
    update_option('agumail_popup', array_merge(array('slug' => 'fnews01', 'trigger' => 'delay', 'delay' => 8, 'scroll' => 40, 'exitIntent' => false, 'frequencyDays' => 14), $changes));
}

function test_forms_the_popup_is_on_every_storefront_page_but_cart_checkout_and_account() {
    forms_connected();
    forms_set_popup();
    foreach (array('home', 'product', 'shop') as $page) {
        unset($GLOBALS['agm']['scripts']['agumail-forms']);
        $result = forms_page($page);
        expect_that(preg_match('/<script type="text\/plain" data-agumail-form="fnews01" data-mode="popup" data-delay="8" data-frequency-days="14"><\/script>/', $result['html']) === 1, "no popup on the $page page: " . $result['html']);
        expect_that(forms_loader() && forms_loader()['enqueued'], "the loader was not enqueued on the $page page");
        expect_same(array(), $result['http'], "the $page page called AguMail");
        expect_same(array(), $result['writes'], "the $page page wrote something");
    }
    foreach (array('cart', 'checkout', 'account') as $page) {
        unset($GLOBALS['agm']['scripts']['agumail-forms']);
        $result = forms_page($page);
        expect_that(strpos($result['html'], 'data-agumail-form') === false, "the popup showed on the $page page");
        expect_same(null, forms_loader(), "the loader was enqueued on the $page page");
    }
}

function test_forms_the_popup_trigger_follows_the_setting() {
    forms_connected();
    forms_set_popup(array('trigger' => 'scroll'));
    $html = forms_page()['html'];
    expect_that(strpos($html, 'data-scroll="40"') !== false && strpos($html, 'data-delay') === false, 'the scroll trigger was not used: ' . $html);
    forms_set_popup(array('trigger' => 'exit_intent', 'exitIntent' => true));
    $html = forms_page()['html'];
    expect_that(strpos($html, 'data-exit-intent="yes"') !== false && strpos($html, 'data-delay') === false && strpos($html, 'data-scroll') === false, 'the exit-intent trigger was not used: ' . $html);
}

function test_forms_the_popup_is_not_shown_to_subscribed_customers() {
    forms_connected();
    forms_set_popup();
    agm_sign_in(7, 'bea@example.com');
    update_user_meta(7, '_agumail_optin', 'yes');
    expect_that(strpos(forms_page()['html'], 'data-agumail-form') === false, 'a subscribed customer was shown the popup');
    foreach (array('no', 'unsubscribed', '') as $answer) {
        update_user_meta(7, '_agumail_optin', $answer);
        expect_that(strpos(forms_page()['html'], 'data-agumail-form="fnews01"') !== false, "a customer whose answer is '$answer' was not shown the popup");
    }
}

function test_forms_the_popup_needs_a_form_that_is_still_published() {
    forms_connected();
    forms_set_popup(array('slug' => 'fgone99'));
    expect_that(strpos(forms_page()['html'], 'data-agumail-form') === false, 'a form no longer in the list was shown');
    delete_option('agumail_popup');
    expect_that(strpos(forms_page()['html'], 'data-agumail-form') === false, 'a popup showed with none set');
}

function test_forms_the_popup_setting_is_saved_from_the_forms_section() {
    forms_connected();
    forms_merchant();
    $sections = agumail_admin_sections();
    expect_that(isset($sections['forms']), 'there is no Forms section');
    expect_same('Forms', $sections['forms']['title'], 'wrong section title');
    $html = agm_capture(function () use ($sections) { call_user_func($sections['forms']['render']); });
    expect_that(strpos($html, 'VIP &lt;list&gt;') !== false, 'the cached forms are not listed escaped: ' . $html);
    expect_that(strpos($html, '[agumail_form slug=&quot;fnews01&quot;]') !== false || strpos($html, '[agumail_form slug="fnews01"]') !== false, 'the shortcode to copy is not shown');
    expect_that(preg_match('/<button[^>]*>Refresh<\/button>/', $html) === 1, 'no Refresh button');
    expect_that(strpos($html, 'name="agumail_popup_slug"') !== false, 'no popup form picker');
    expect_that(strpos($html, '—') === false, 'UI copy carries an em-dash');

    $result = forms_admin_action('forms_save_popup', array('agumail_popup_slug' => 'fvip002', 'agumail_popup_trigger' => 'scroll', 'agumail_popup_delay' => '12', 'agumail_popup_scroll' => '60', 'agumail_popup_frequency' => '30'));
    expect_same(array(), $result['http'], 'saving the popup called AguMail');
    expect_same(array('slug' => 'fvip002', 'trigger' => 'scroll', 'delay' => 12, 'scroll' => 60, 'exitIntent' => false, 'frequencyDays' => 30), get_option('agumail_popup'), 'the popup was not saved');

    forms_admin_action('forms_save_popup', array('agumail_popup_slug' => 'fvip002', 'agumail_popup_trigger' => 'delay', 'agumail_popup_frequency' => '0'));
    expect_same(1, get_option('agumail_popup')['frequencyDays'], 'a popup frequency of 0 was not raised to 1');
    $html = agm_capture(function () use ($sections) { call_user_func($sections['forms']['render']); });
    expect_that(preg_match('/<input type="number" min="1" max="365" name="agumail_popup_frequency"/', $html) === 1, 'the frequency field allows less than a day');

    forms_admin_action('forms_save_popup', array('agumail_popup_slug' => 'fnope00', 'agumail_popup_trigger' => 'delay'));
    expect_same('error', get_transient('agumail_notice_1')['type'], 'a form that is not in the list was accepted');
    expect_same('fvip002', get_option('agumail_popup')['slug'], 'an unknown form replaced the popup');
    expect_same(1, get_option('agumail_popup')['frequencyDays'], 'an unknown form changed the popup');

    forms_admin_action('forms_save_popup', array('agumail_popup_slug' => ''));
    expect_same(false, get_option('agumail_popup'), 'choosing no form did not turn the popup off');
}

// ── The cache ──

function forms_list_response() {
    return array('ok' => true, 'loaderUrl' => 'https://api.agumail.test/embed/agumail-forms.js', 'forms' => array(
        array('slug' => 'fnew003', 'name' => 'Spring launch', 'doubleOptIn' => false, 'publishedAt' => '2026-09-20T10:00:00Z'),
        array('slug' => 'bad slug"', 'name' => 'Broken', 'doubleOptIn' => false),
    ));
}

function test_forms_refresh_fetches_the_forms_from_the_settings_page() {
    forms_merchant();
    agm_http_respond(200, forms_list_response());
    $result = forms_admin_action('forms_refresh');
    expect_same(1, count($result['http']), 'Refresh did not call AguMail once');
    expect_same('GET', $result['http'][0]['method'], 'the forms list is a GET');
    expect_same('/api/v1/forms', $result['http'][0]['path'], 'wrong path');
    expect_that(isset($result['http'][0]['headers']['Authorization']), 'the key was not sent');
    expect_same(array('admin' => true, 'cron' => false, 'ajax' => false, 'rest' => false), $result['http'][0]['context'], 'the forms were not fetched from the admin');
    $cache = get_option('agumail_forms_cache');
    expect_same('https://api.agumail.test/embed/agumail-forms.js', $cache['loaderUrl'], 'the loader address was not cached');
    expect_same(array(array('slug' => 'fnew003', 'name' => 'Spring launch', 'doubleOptIn' => false, 'publishedAt' => '2026-09-20T10:00:00Z')), $cache['forms'], 'the forms were not cached cleanly');
    expect_that(abs($cache['fetchedAt'] - time()) <= 2, 'the fetch time was not recorded');
    expect_same('success', get_transient('agumail_notice_1')['type'], 'no notice after Refresh');
}

function test_forms_housekeeping_refreshes_the_cache() {
    agm_http_default(function ($url) {
        return parse_url($url, PHP_URL_PATH) === '/api/v1/forms'
            ? array('code' => 200, 'body' => forms_list_response(), 'headers' => array())
            : array('code' => 200, 'body' => array('ok' => true), 'headers' => array());
    });
    $result = agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    expect_same(1, count(array_filter($result['http'], function ($r) { return $r['path'] === '/api/v1/forms'; })), 'housekeeping did not fetch the forms');
    expect_same('fnew003', get_option('agumail_forms_cache')['forms'][0]['slug'], 'housekeeping did not cache the forms');
}

function test_forms_a_failed_refresh_keeps_the_cache_and_says_why() {
    forms_merchant();
    update_option('agumail_forms_cache', forms_cache());
    foreach (array(array(402, array('ok' => false, 'error' => 'Your plan does not include forms.')), array(new WP_Error('http_request_failed', 'timed out'))) as $answer) {
        call_user_func_array('agm_http_respond', $answer);
        forms_admin_action('forms_refresh');
        expect_same(forms_cache(), get_option('agumail_forms_cache'), 'a failed refresh changed the cache');
        expect_same('error', get_transient('agumail_notice_1')['type'], 'a failed refresh was not reported');
    }
    expect_that(strpos(get_transient('agumail_notice_1')['message'], 'timed out') !== false, 'the error is not shown plainly');
}

function test_forms_a_store_that_is_not_connected_does_not_fetch_forms() {
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $result = agm_request('housekeeping', 'cron', function () { do_action('agumail_housekeeping'); });
    expect_same(array(), $result['http'], 'a store with no key fetched forms');
}

// ── Shoppers ──

function test_forms_the_popup_is_skipped_when_the_same_form_is_already_in_the_page() {
    forms_connected();
    forms_set_popup();
    $html = forms_page('home', function () {
        return agm_shortcode('agumail_form', array('slug' => 'fnews01'));
    })['html'];
    expect_same(1, substr_count($html, 'data-agumail-form="fnews01"'), 'the same form showed inline and as the popup: ' . $html);
    expect_that(strpos($html, 'data-mode="popup"') === false, 'the popup of a form already in the page was printed');

    $html = forms_page('home', function () {
        return agm_shortcode('agumail_form', array('slug' => 'fvip002'));
    })['html'];
    expect_that(strpos($html, 'data-agumail-form="fnews01" data-mode="popup"') !== false, 'another form in the page stopped the popup');

    // Placed as a popup by the page's own shortcode (or block): the page's settings stand, and the
    // site-wide popup does not print the form a second time.
    $html = forms_page('home', function () {
        return agm_shortcode('agumail_form', array('slug' => 'fnews01', 'mode' => 'popup', 'delay' => '3'));
    })['html'];
    expect_same(1, substr_count($html, 'data-agumail-form="fnews01"'), 'the same popup was printed twice: ' . $html);
    expect_that(strpos($html, 'data-delay="3"') !== false, 'the page\'s own popup lost its settings: ' . $html);
}

function test_forms_storefront_pages_with_forms_are_quiet() {
    forms_connected();
    forms_set_popup();
    agm_sign_in(7, 'bea@example.com');
    $result = forms_page('home', function () {
        return agm_shortcode('agumail_form', array('slug' => 'fnews01'));
    });
    expect_same(array(), $result['http'], 'a page with forms called AguMail');
    expect_same(array(), $result['writes'], 'a page with forms wrote something');
    $refused = agm_logs('refused');
    expect_same(array(), $refused, 'a page with forms tried to call AguMail');
}
