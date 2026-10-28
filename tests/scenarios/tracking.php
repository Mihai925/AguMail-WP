<?php
// Website tracking. The site script is added to every storefront page from the
// config AguMail handed over, and a signed-in customer is identified with a
// signature AguMail can check, so nobody holding the public site key can plant
// product views for somebody else. Tracking is off until the merchant switches
// it on, and a notice says so once the site is connected.

// The script loads only from AguMail's own hosts or one wp-config.php pins.
// This store pins the test API, as a development site would, so the
// addresses below count as AguMail's.
define('AGUMAIL_API_URL', 'https://api.agumail.test');

// Test vector, computed the way the server does (Node crypto):
//   identifySecret = base64url(HMAC-SHA256('tracking-secret', 'site-identify:site_abc123'))
//                  = 0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg
//   sig            = base64url(HMAC-SHA256(identifySecret, 'ada.lovelace@example.com'))
//                  = Vs89cUzFLJ7S9RDBAl_d6Imi4MfawNK_Ox_ZEx5PsvU
define('TRACKING_SECRET_VECTOR', '0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg');
define('TRACKING_SIG_VECTOR', 'Vs89cUzFLJ7S9RDBAl_d6Imi4MfawNK_Ox_ZEx5PsvU');

// The merchant has switched website tracking on. The tests of the setting
// and its notice start from a site that has not chosen yet.
update_option('agumail_tracking_enabled', 'yes');

function tracking_config(array $changes = array()) {
    return array_merge(array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://api.agumail.test/t/site.js',
        'siteEndpoint'   => 'https://api.agumail.test/api/public/site/site_abc123/events',
        'formsLoaderUrl' => 'https://api.agumail.test/embed/agumail-forms.js',
        'identifySecret' => TRACKING_SECRET_VECTOR,
    ), $changes);
}

/** A storefront page: returns what it did, plus the site script as enqueued (or null). */
function tracking_page() {
    $result = agm_request('storefront page', 'shopper', function () {
        do_action('init');
        do_action('wp_enqueue_scripts');
        do_action('wp_footer');
    });
    $result['script'] = isset($GLOBALS['agm']['scripts']['agumail-site']) ? $GLOBALS['agm']['scripts']['agumail-site'] : null;
    return $result;
}

function tracking_tag($src = 'https://api.agumail.test/t/site.js', $handle = 'agumail-site') {
    $tag = "<script src=\"$src\" id=\"$handle-js\"></script>\n";
    return apply_filters('script_loader_tag', $tag, $handle, $src);
}

// ── The script ──

function test_tracking_the_site_script_is_enqueued_with_its_key_and_endpoint() {
    update_option('agumail_config', tracking_config());
    $page = tracking_page();
    expect_that($page['script'] && $page['script']['enqueued'], 'the site script was not enqueued');
    expect_same('https://api.agumail.test/t/site.js', $page['script']['src'], 'wrong script address');
    $tag = tracking_tag();
    expect_that(strpos($tag, 'data-site="site_abc123"') !== false, 'the tag has no data-site: ' . $tag);
    expect_that(strpos($tag, 'data-endpoint="https://api.agumail.test/api/public/site/site_abc123/events"') !== false, 'the tag has no data-endpoint: ' . $tag);
    expect_that(preg_match('/<script[^>]*\sasync[\s>=]/', $tag) === 1, 'the site script does not load async: ' . $tag);
    expect_same(1, preg_match_all('/\sasync(\s|=|>)/', $tag), 'async was added twice: ' . $tag);
    expect_that(strpos($tag, 'data-cfasync="false"') !== false, 'Rocket Loader may defer the site script: ' . $tag);

    $other = "<script src=\"https://shop.test/theme.js\" id=\"theme-js\"></script>\n";
    expect_same($other, apply_filters('script_loader_tag', $other, 'theme', 'https://shop.test/theme.js'), 'another script tag was changed');
    expect_same(array(), $page['http'], 'the page called AguMail');
}

function test_tracking_attributes_are_escaped() {
    update_option('agumail_config', tracking_config(array('siteKey' => 'k"><img src=x onerror=alert(1)>', 'siteEndpoint' => 'https://api.agumail.test/e?a=1&b="2"')));
    tracking_page();
    $tag = tracking_tag();
    expect_that(strpos($tag, '"><img') === false, 'the site key broke out of its attribute: ' . $tag);
    expect_that(strpos($tag, 'data-site="k&quot;&gt;&lt;img') !== false, 'the site key was not escaped: ' . $tag);
}

function test_tracking_falls_back_to_the_legacy_site_key() {
    update_option('agumail_site_key', 'legacy_key');
    update_option('agumail_site_src', 'https://api.agumail.test/t/legacy.js');
    $page = tracking_page();
    expect_that($page['script'] && $page['script']['enqueued'], 'the legacy site script was not enqueued');
    expect_same('https://api.agumail.test/t/legacy.js', $page['script']['src'], 'wrong legacy script address');
    $tag = tracking_tag('https://api.agumail.test/t/legacy.js');
    expect_that(strpos($tag, 'data-site="legacy_key"') !== false, 'the legacy key is not on the tag: ' . $tag);
    expect_that(strpos($tag, 'data-endpoint="https://api.agumail.test/api/public/site/legacy_key/events"') !== false, 'the legacy endpoint is wrong: ' . $tag);

    // The config, once there, wins.
    update_option('agumail_config', tracking_config());
    unset($GLOBALS['agm']['scripts']['agumail-site']);
    expect_same('https://api.agumail.test/t/site.js', tracking_page()['script']['src'], 'the legacy options beat the config');
}

function test_tracking_the_endpoint_is_built_from_the_api_url_when_the_config_has_none() {
    update_option('agumail_config', tracking_config(array('siteEndpoint' => null)));
    tracking_page();
    $tag = tracking_tag();
    expect_that(strpos($tag, 'data-endpoint="https://api.agumail.test/api/public/site/site_abc123/events"') !== false, 'no endpoint: ' . $tag);
}

function test_tracking_nothing_is_added_without_a_site_key_or_when_turned_off() {
    $page = tracking_page();
    expect_same(null, $page['script'], 'a script was added with no site key');

    update_option('agumail_config', tracking_config());
    update_option('agumail_tracking_enabled', 'no');
    $page = tracking_page();
    expect_same(null, $page['script'], 'the script was added with tracking turned off');
    expect_same("<script src=\"https://api.agumail.test/t/site.js\" id=\"agumail-site-js\"></script>\n", tracking_tag(), 'the tag was changed with tracking off');

    update_option('agumail_config', tracking_config(array('siteScriptUrl' => null)));
    update_option('agumail_tracking_enabled', 'yes');
    expect_same(null, tracking_page()['script'], 'a script was added with no script address');
}

function test_tracking_is_not_added_to_admin_pages() {
    update_option('agumail_config', tracking_config());
    agm_request('admin page', 'admin', function () {
        do_action('admin_enqueue_scripts', 'index.php');
    });
    expect_same(false, isset($GLOBALS['agm']['scripts']['agumail-site']), 'the site script was added to wp-admin');
}

// ── Identify ──

function test_tracking_signature_matches_known_hmac_vectors() {
    update_option('agumail_config', tracking_config());
    expect_same(TRACKING_SIG_VECTOR, agumail_identify_signature('  Ada.Lovelace@Example.COM '), 'the signature does not match the server\'s');
    expect_same(TRACKING_SIG_VECTOR, agumail_identify_signature('ada.lovelace@example.com'), 'case or spaces changed the signature');

    // RFC 4231 test case 2: the secret is the key exactly as given, never decoded.
    update_option('agumail_config', tracking_config(array('identifySecret' => 'Jefe')));
    expect_same('W9zBRr9gdU5qBCQmCJV1x1oAPwidJzmDnexYuWTsOEM', agumail_identify_signature('what do ya want for nothing?'), 'not HMAC-SHA256 in base64url');

    update_option('agumail_config', tracking_config(array('identifySecret' => null)));
    expect_same('', agumail_identify_signature('ada.lovelace@example.com'), 'a signature was made with no secret');
    delete_option('agumail_config');
    expect_same('', agumail_identify_signature('ada.lovelace@example.com'), 'a signature was made with no config');
    update_option('agumail_config', tracking_config());
    expect_same('', agumail_identify_signature('   '), 'an empty address was signed');
}

function test_tracking_a_signed_in_customer_is_identified_with_a_signature() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(7, '  Ada.Lovelace@Example.COM ');
    $page = tracking_page();
    $inline = implode("\n", $page['script']['inline']);
    expect_same(1, count($page['script']['inline']), 'identify should be added once');
    expect_that(strpos($inline, 'agumail.identify(') !== false, 'the customer is not identified: ' . $inline);
    expect_that(strpos($inline, '"ada.lovelace@example.com","' . TRACKING_SIG_VECTOR . '"') !== false, 'the address and signature are not passed: ' . $inline);

    // The runtime reads who it is from its own tag, before its first view.
    $tag = tracking_tag();
    expect_that(strpos($tag, 'data-email="ada.lovelace@example.com"') !== false, 'the tag does not name the customer: ' . $tag);
    expect_that(strpos($tag, 'data-esig="' . TRACKING_SIG_VECTOR . '"') !== false, 'the tag does not carry the signature: ' . $tag);

    // The inline call is only a fallback: on the script element's own load, and not when the runtime read the tag.
    expect_that(strpos($inline, 'document.getElementById("agumail-site-js")') !== false, 'the fallback does not find the script element: ' . $inline);
    expect_that(strpos($inline, 's.addEventListener("load"') !== false, 'the fallback does not wait for the script\'s own load: ' . $inline);
    expect_that(strpos($inline, 'window.addEventListener') === false, 'the fallback still waits for the whole page');
    expect_that(strpos($inline, 'data-agumail-identified') !== false, 'the fallback identifies again after the runtime read the tag');
    expect_same(array(), $page['http'], 'identifying called AguMail');
    expect_same(array(), $page['writes'], 'identifying wrote something');
}

function test_tracking_every_signed_in_customer_is_identified_opted_in_or_not() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(8, 'bea@example.com');
    update_user_meta(8, '_agumail_optin', 'no');
    $inline = implode("\n", tracking_page()['script']['inline']);
    expect_that(strpos($inline, '"bea@example.com"') !== false, 'a customer who did not opt in is not identified');
}

function test_tracking_the_identify_script_is_safe_for_any_address() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(9, 'x</script><script>alert(1)</script>@example.com');
    $inline = implode("\n", tracking_page()['script']['inline']);
    expect_that(stripos($inline, '</script>') === false, 'the address can close the script tag: ' . $inline);
}

function test_tracking_the_identify_script_encodes_any_address_safely() {
    $address = "x</script><script>alert(\"1\")</script>'&@example.com";
    $js = agumail_tracking_identify_js($address, 'sig"</script>');
    expect_that(stripos($js, '</script') === false, 'the address can close the script tag: ' . $js);
    expect_that(strpos($js, '\'') === false && strpos($js, '"1"') === false, 'quotes in the address were printed raw: ' . $js);
    preg_match('/identify\(("(?:[^"\\\\]|\\\\.)*"),("(?:[^"\\\\]|\\\\.)*")\)/', $js, $args);
    expect_same($address, json_decode($args[1]), 'the address does not come back intact: ' . $js);
    expect_same('sig"</script>', json_decode($args[2]), 'the signature does not come back intact');
}

function test_tracking_the_tag_attributes_escape_an_address_with_quotes() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(7, "o'brien&co@example.com");
    tracking_page();
    $tag = tracking_tag();
    expect_that(strpos($tag, 'data-email="o&#039;brien&amp;co@example.com"') !== false, 'the address is not escaped in the tag: ' . $tag);
}

function test_tracking_store_staff_are_not_identified_unless_the_filter_says_so() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    $page = tracking_page();
    expect_same(array(), $page['script']['inline'], 'a store manager was identified');
    expect_that(strpos(tracking_tag(), 'data-email') === false, 'a store manager was named on the tag');

    add_filter('agumail_identify_staff', '__return_true');
    unset($GLOBALS['agm']['scripts']['agumail-site']);
    $page = tracking_page();
    expect_same(1, count($page['script']['inline']), 'the filter did not let staff be identified');
    expect_that(strpos(tracking_tag(), 'data-email="owner@shop.test"') !== false, 'the filter did not name staff on the tag');
}

function test_tracking_staff_are_whoever_agumail_is_staff_says() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(12, 'manager@shop.test');
    if (!function_exists('agumail_is_staff')) {
        // Until the foundation's own helper exists, a stand-in proves the delegation.
        function agumail_is_staff() {
            return get_current_user_id() === 12;
        }
    } else {
        agm_grant('manage_woocommerce');
        $GLOBALS['agm']['user_caps'][12]['manage_woocommerce'] = true;
    }
    $page = tracking_page();
    expect_same(array(), $page['script']['inline'], 'someone agumail_is_staff() calls staff was identified');
    expect_that(strpos(tracking_tag(), 'data-email') === false, 'staff were named on the tag');
}

function test_tracking_the_tag_filter_handles_the_shapes_wordpress_prints() {
    update_option('agumail_config', tracking_config());
    agm_sign_in(7, 'ada.lovelace@example.com');
    tracking_page();
    $src = 'https://api.agumail.test/t/site.js';
    $translations = "<script id=\"agumail-site-js-translations\">\n( function( domain, translations ) { var s = '<script src=\"x\">'; } )( \"agumail\", {} );\n</script>\n";
    $before = "<script id=\"agumail-site-js-before\">\nvar agmBefore = 1;\n</script>\n";
    $after = "<script id=\"agumail-site-js-after\">\n(function(){})();\n</script>\n";
    $shapes = array(
        'modern' => $translations . $before . "<script src=\"$src\" id=\"agumail-site-js\"></script>\n" . $after,
        'single quotes' => "<script type='text/javascript' src='$src?ver=2.0.0' id='agumail-site-js'></script>\n" . "<script type='text/javascript' id='agumail-site-js-after'>\n(function(){})();\n</script>\n",
        'already async' => "<script src=\"$src\" id=\"agumail-site-js\" async data-wp-strategy=\"async\"></script>\n" . $after,
    );
    foreach ($shapes as $shape => $tag) {
        $out = apply_filters('script_loader_tag', $tag, 'agumail-site', $src);
        preg_match_all('/<script\b[^>]*>/', $out, $opening);
        $changed = array_values(array_filter($opening[0], function ($open) { return strpos($open, 'data-site=') !== false; }));
        expect_same(1, count($changed), "$shape: not exactly the loading tag was changed: $out");
        expect_that(preg_match('/id=(["\'])agumail-site-js\1/', $changed[0]) === 1, "$shape: the wrong tag was changed: " . $changed[0]);
        foreach (array('data-site="site_abc123"', 'data-email="ada.lovelace@example.com"', 'data-esig="', 'data-cfasync="false"') as $attribute) {
            expect_that(strpos($changed[0], $attribute) !== false, "$shape: $attribute missing: " . $changed[0]);
        }
        expect_same(1, preg_match_all('/\sasync(\s|=|>)/', $changed[0]), "$shape: async is missing or doubled: " . $changed[0]);
        preg_match('/<script\b[^>]*\sid=(["\'])agumail-site-js\1[^>]*>/', $tag, $original);
        expect_same($tag, str_replace($changed[0], $original[0], $out), "$shape: something besides the loading tag was changed");
    }
}

function test_tracking_signing_out_forgets_the_visitor_cookies() {
    $_COOKIE['agm_e'] = 'ada@example.com';
    $_COOKIE['agm_v'] = 'token';
    $_COOKIE['other'] = 'keep';
    $cookies = agumail_tracking_logout_cookies();
    expect_same(array('agm_e', 'agm_v'), array_keys($cookies), 'the wrong cookies are expired');
    foreach ($cookies as $name => $options) {
        expect_same('/', $options['path'], "$name is not expired on the path the runtime sets it on");
        expect_that(!isset($options['domain']), "$name is expired on a domain the runtime never sets");
        expect_that($options['expires'] < time(), "$name is not expired");
        expect_same('Lax', $options['samesite'], "$name has another SameSite than the runtime's");
    }
    expect_that(has_action('wp_logout', 'agumail_tracking_forget_visitor') !== false, 'signing out does not forget the visitor');
    $result = agm_request('sign out', 'shopper', function () { do_action('wp_logout', 7); });
    expect_same(array(), $result['http'], 'signing out called AguMail');
    expect_same(array(), $result['writes'], 'signing out wrote something');
    expect_that(!isset($_COOKIE['agm_e']) && !isset($_COOKIE['agm_v']) && isset($_COOKIE['other']), 'the cookies were not forgotten for the rest of the request');
    unset($_COOKIE['agm_e'], $_COOKIE['agm_v']);
    expect_same(array(), agumail_tracking_logout_cookies(), 'cookies the browser did not send are expired anyway');
}

function test_tracking_the_forms_loader_is_kept_away_from_rocket_loader() {
    $src = 'https://api.agumail.test/embed/agumail-forms.js';
    $out = apply_filters('script_loader_tag', "<script src=\"$src\" id=\"agumail-forms-js\"></script>\n", 'agumail-forms', $src);
    expect_that(strpos($out, 'data-cfasync="false"') !== false, 'the forms loader can be deferred by Rocket Loader: ' . $out);
}

function test_tracking_guests_are_never_identified() {
    update_option('agumail_config', tracking_config());
    agm_add_user(7, 'ada@example.com');
    $page = tracking_page();
    expect_that($page['script'] && $page['script']['enqueued'], 'guests should still get the site script');
    expect_same(array(), $page['script']['inline'], 'a guest was identified');
    expect_that(strpos(tracking_tag(), 'data-email') === false, 'a guest was named on the tag');
    $html = agm_capture(function () { do_action('wp_footer'); do_action('wp_head'); });
    expect_that(strpos($html, 'ada@example.com') === false, 'an address was printed for a guest');
}

function test_tracking_a_signed_in_user_without_an_address_is_not_identified() {
    update_option('agumail_config', tracking_config());
    agm_add_user(10, '');
    agm_sign_in(10);
    $page = tracking_page();
    expect_same(array(), $page['script']['inline'], 'a user without an address was identified');
}

function test_tracking_without_a_secret_identifies_unsigned() {
    update_option('agumail_site_key', 'legacy_key');
    update_option('agumail_site_src', 'https://api.agumail.test/t/legacy.js');
    agm_sign_in(7, 'ada@example.com');
    $inline = implode("\n", tracking_page()['script']['inline']);
    expect_that(strpos($inline, 'identify("ada@example.com")') !== false, 'the unsigned identify is wrong: ' . $inline);
}

// ── The setting ──

function test_tracking_the_setting_is_off_by_default_and_saved_from_the_settings_page() {
    delete_option('agumail_tracking_enabled');
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    do_action('init');
    $sections = agumail_admin_sections();
    expect_that(isset($sections['tracking']), 'there is no Website tracking section');
    expect_same('Website tracking', $sections['tracking']['title'], 'wrong section title');
    $html = agm_capture(function () use ($sections) { call_user_func($sections['tracking']['render']); });
    expect_that(preg_match('/<input[^>]*name="agumail_tracking_enabled"[^>]*>/', $html, $box) === 1, 'there is no tracking checkbox: ' . $html);
    expect_that(strpos($box[0], 'checked') === false, 'tracking is on by default: ' . $box[0]);
    expect_that(strpos($html, '—') === false, 'UI copy carries an em-dash');

    $save = function ($fields) {
        return agm_request('save tracking', 'admin', function () use ($fields) {
            agm_input(array(), array_merge(array('action' => 'agumail', 'agumail_action' => 'tracking_save', '_wpnonce' => agumail_admin_nonce('tracking_save')), $fields));
            do_action('admin_post_agumail');
        });
    };
    $result = $save(array());
    expect_same('no', get_option('agumail_tracking_enabled'), 'an unticked box did not turn tracking off');
    expect_same(array(), $result['http'], 'saving called AguMail');
    $save(array('agumail_tracking_enabled' => 'yes'));
    expect_same('yes', get_option('agumail_tracking_enabled'), 'a ticked box did not turn tracking on');
}

function test_tracking_nothing_loads_until_the_merchant_switches_it_on() {
    delete_option('agumail_tracking_enabled');
    update_option('agumail_config', tracking_config());
    agm_add_user(7, 'ada.lovelace@example.com');
    agm_sign_in(7);
    $page = tracking_page();
    expect_same(null, $page['script'], 'the site script loaded before the merchant switched tracking on');
    expect_same("<script src=\"https://api.agumail.test/t/site.js\" id=\"agumail-site-js\"></script>\n", tracking_tag(), 'the tag was changed before tracking was on');
}

// ── The notice ──

/** A connected store whose AguMail account has site tracking, and a store manager signed in, before anyone chose. */
function tracking_notice_store() {
    delete_option('agumail_tracking_enabled');
    update_option('agumail_config', tracking_config());
    agumail_save_api_key('agm_' . str_repeat('b', 40));
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    do_action('init');
}

/** The admin notices a screen draws. */
function tracking_notice_on($screen) {
    $GLOBALS['agm']['screen'] = $screen;
    try {
        return agm_capture(function () { do_action('admin_notices'); });
    } finally {
        $GLOBALS['agm']['screen'] = null;
    }
}

/** A link's href from printed HTML, found by its text. */
function tracking_href($html, $text) {
    expect_that((bool) preg_match('#<a [^>]*href="([^"]+)"[^>]*>' . preg_quote($text, '#') . '</a>#', $html, $match), "no \"$text\" link in: $html");
    return html_entity_decode($match[1], ENT_QUOTES);
}

/** Follows an admin-post.php link the way a click does. */
function tracking_follow($href, $referer = '') {
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

function test_tracking_the_notice_shows_on_the_dashboard_plugins_and_agumail_page_once_connected() {
    tracking_notice_store();
    $writes = count(agm_writes());
    foreach (array('dashboard', 'plugins', 'toplevel_page_agumail') as $screen) {
        $html = tracking_notice_on($screen);
        expect_that(strpos($html, 'AguMail website tracking is off.') !== false, "no tracking notice on $screen");
        expect_that(strpos($html, 'the products people looked at') !== false, "the notice on $screen does not say what tracking is for: $html");
    }
    foreach (array('woocommerce_page_wc-settings', 'edit-post', 'options-general', null) as $screen) {
        expect_that(strpos(tracking_notice_on($screen), 'agumail-tracking-notice') === false, 'a tracking notice on ' . var_export($screen, true));
    }
    expect_that(strpos(tracking_notice_on('dashboard'), '—') === false, 'the notice carries an em-dash');
    expect_same($writes, count(agm_writes()), 'showing the notice wrote something');
}

function test_tracking_the_notice_waits_for_a_connected_site_with_site_tracking_and_a_store_manager() {
    tracking_notice_store();
    agm_grant('manage_woocommerce', false);
    expect_same('', tracking_notice_on('dashboard'), 'a user who cannot manage AguMail saw the notice');
    agm_grant('manage_woocommerce');

    update_option('agumail_config', tracking_config(array('siteKey' => null)));
    expect_same('', tracking_notice_on('dashboard'), 'the notice showed for an account without site tracking');
    update_option('agumail_config', tracking_config());

    delete_option('agumail_api_key');
    expect_that(strpos(tracking_notice_on('dashboard'), 'agumail-tracking-notice') === false, 'the notice showed before the site was connected');
}

function test_tracking_the_notice_goes_once_the_merchant_has_chosen() {
    tracking_notice_store();
    foreach (array('yes', 'no') as $choice) {
        update_option('agumail_tracking_enabled', $choice);
        expect_same('', tracking_notice_on('dashboard'), "the notice showed after the merchant chose $choice");
    }
}

function test_tracking_the_notice_button_turns_tracking_on() {
    tracking_notice_store();
    $html = tracking_notice_on('dashboard');
    $on = tracking_href($html, 'Turn on website tracking');
    expect_same(agumail_admin_action_url('tracking_save', array('agumail_tracking_enabled' => 'yes')), $on, 'the button does not run the setting\'s own save');
    expect_that(strpos($html, 'class="button button-primary"') !== false, 'Turn on website tracking is not the primary button');

    $result = tracking_follow($on, 'https://shop.test/wp-admin/index.php');
    expect_same('yes', get_option('agumail_tracking_enabled'), 'the button did not turn tracking on');
    expect_same('redirect: ' . agumail_admin_page_url(), $result['halt'], 'the merchant did not land on the AguMail page');
    expect_same(array('type' => 'success', 'message' => 'Website tracking is on.'), get_transient('agumail_notice_1'), 'the AguMail page does not confirm it');
    expect_same(array(), $result['http'], 'turning tracking on called AguMail');
    expect_same('', tracking_notice_on('dashboard'), 'the notice stayed after tracking was turned on');
    agm_sign_out();
    expect_that(tracking_page()['script'] !== null, 'the site script did not load once tracking was on');
}

function test_tracking_keep_it_off_keeps_it_off_and_goes_back() {
    tracking_notice_store();
    $keep = tracking_href(tracking_notice_on('plugins'), 'Keep it off');
    expect_same(agumail_admin_action_url('tracking_keep_off'), $keep, 'Keep it off does not go through an admin action');

    $result = tracking_follow($keep, 'https://shop.test/wp-admin/plugins.php');
    expect_same('redirect: https://shop.test/wp-admin/plugins.php', $result['halt'], 'the merchant was not sent back to the page they were on');
    expect_same('no', get_option('agumail_tracking_enabled'), 'keeping it off was not saved');
    expect_same(false, get_transient('agumail_notice_1'), 'keeping it off left a message for the AguMail page');
    foreach (array('dashboard', 'plugins', 'toplevel_page_agumail') as $screen) {
        expect_same('', tracking_notice_on($screen), "the notice came back on $screen");
    }
    agm_sign_out();
    expect_same(null, tracking_page()['script'], 'the site script loaded after Keep it off');
}

function test_tracking_the_notice_links_need_the_nonce_and_the_capability() {
    tracking_notice_store();
    $html = tracking_notice_on('dashboard');
    foreach (array('Turn on website tracking', 'Keep it off') as $text) {
        $href = tracking_href($html, $text);
        $result = tracking_follow(add_query_arg('_wpnonce', 'nonce-stale', $href));
        expect_that(strpos((string) $result['halt'], 'wp_die') === 0, "$text ran without a valid nonce");
        agm_grant('manage_woocommerce', false);
        $result = tracking_follow($href);
        expect_that(strpos((string) $result['halt'], 'wp_die') === 0, "$text ran for a user who cannot manage AguMail");
        agm_grant('manage_woocommerce');
    }
    expect_same(false, get_option('agumail_tracking_enabled', false), 'a refused link changed the setting');
}
