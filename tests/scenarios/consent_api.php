<?php
// Cookie consent through the WP Consent API. A consent plugin that implements
// it decides whether the campaign cookie is set and the website tracking
// script loads; both are marketing. Without one, nothing changes.

// The consent plugin: what the visitor allowed, by category.
function wp_has_consent($category) {
    return !empty($GLOBALS['agm_consent'][$category]);
}

define('AGUMAIL_API_URL', 'https://api.agumail.test');
update_option('agumail_tracking_enabled', 'yes');
update_option('agumail_config', array(
    'siteKey'        => 'site_abc123',
    'siteScriptUrl'  => 'https://api.agumail.test/t/site.js',
    'siteEndpoint'   => 'https://api.agumail.test/api/public/site/site_abc123/events',
    'formsLoaderUrl' => 'https://api.agumail.test/embed/agumail-forms.js',
    'identifySecret' => 'secret',
));

function consent_api_land($cid) {
    unset($_COOKIE[AGUMAIL_CID_COOKIE]);
    agm_request('landing from an email', 'shopper', function () use ($cid) {
        agm_input(array('agm_cid' => $cid));
        do_action('init');
    });
    return isset($_COOKIE[AGUMAIL_CID_COOKIE]) ? $_COOKIE[AGUMAIL_CID_COOKIE] : null;
}

function consent_api_site_script() {
    unset($GLOBALS['agm']['scripts']['agumail-site']);
    agm_request('storefront page', 'shopper', function () {
        do_action('init');
        do_action('wp_enqueue_scripts');
    });
    return isset($GLOBALS['agm']['scripts']['agumail-site']) ? $GLOBALS['agm']['scripts']['agumail-site'] : null;
}

function test_consent_api_the_plugin_says_it_follows_the_api() {
    expect_that(apply_filters('wp_consent_api_registered_' . plugin_basename(AGUMAIL_FILE), false), 'the plugin did not register with the WP Consent API');
}

function test_consent_api_the_campaign_cookie_waits_for_marketing_consent() {
    $GLOBALS['agm_consent'] = array('statistics' => true);
    expect_same(null, consent_api_land('cmp_1'), 'the campaign cookie was set without marketing consent');
    $GLOBALS['agm_consent'] = array('marketing' => true);
    expect_same('cmp_2', consent_api_land('cmp_2'), 'the campaign cookie was not set with marketing consent');
}

function test_consent_api_a_campaign_landing_without_consent_is_kept_for_the_browser_to_set() {
    $GLOBALS['agm_consent'] = array();
    unset($GLOBALS['agm']['scripts']['agumail-campaign-consent']);
    agm_request('landing from an email', 'shopper', function () {
        agm_input(array('agm_cid' => 'cmp_9'));
        do_action('init');
        do_action('wp_enqueue_scripts');
    });
    $script = agm_script('agumail-campaign-consent');
    expect_that($script && $script['enqueued'], 'the page did not keep the campaign for later consent');
    $js = implode("\n", $script['inline']);
    expect_that(strpos($js, 'agm_cid=cmp_9') !== false, 'the kept campaign is not the landing one: ' . $js);
    expect_that(strpos($js, 'wp_has_consent("marketing")') !== false, 'the cookie is not set only with marketing consent: ' . $js);
    expect_that(strpos($js, 'wp_listen_for_consent_change') !== false, 'a later consent does not set the cookie: ' . $js);
}

function test_consent_api_the_site_script_is_inert_until_the_browser_finds_consent() {
    // Decided in the browser, so the page is the same whatever this visitor allowed.
    foreach (array(array(), array('marketing' => true)) as $consent) {
        $GLOBALS['agm_consent'] = $consent;
        $script = consent_api_site_script();
        expect_that($script && $script['enqueued'], 'the site script tag was not printed');
        $tag = apply_filters('script_loader_tag', '<script type="text/javascript" src="https://api.agumail.test/t/site.js" id="agumail-site-js"></script>' . "\n", 'agumail-site', 'https://api.agumail.test/t/site.js');
        expect_that(strpos($tag, 'type="text/plain"') !== false, 'the site script tag is live before consent: ' . $tag);
        expect_same(1, substr_count($tag, 'type='), 'the tag has two types: ' . $tag);
        expect_that(strpos($tag, 'data-agumail-consent="marketing"') !== false, 'the tag does not say which consent it waits for: ' . $tag);
        $loader = agm_script('agumail-site-consent');
        expect_that($loader && $loader['enqueued'], 'nothing brings the site script to life on consent');
        expect_that(strpos(implode("\n", $loader['inline']), 'wp_has_consent("marketing")') !== false, 'the loader does not check marketing consent');
    }
}
