<?php
/**
 * Puts the AguMail plugin in the "connected" state, as if the merchant had connected the store, and points
 * it at an API URL. Run with:
 *   wp eval-file /e2e/wp/connect.php [apiUrl=http://fake-agumail:8090]
 *
 * v1 (1.2.0) reads the agumail_api_url / agumail_api_key options. v2 reads AGUMAIL_API_URL (wp-config, from
 * .state/config.json, which the caller writes), the key option, and the connection and config options from
 * the contracts. The config mirrors what the fake AguMail's ping answers. Website tracking is switched on,
 * as a merchant who wants it does from the notice or the settings page, since the specs read the site script.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

$api_url    = isset($args[0]) && $args[0] !== '' ? $args[0] : 'http://fake-agumail:8090';
$public_url = 'http://localhost:8090';
$site_key   = 'site_e2e0000000000000000';
$secret     = 'agumail-e2e-tracking-secret'; // fake-agumail TRACKING_SECRET default
$identify   = rtrim(strtr(base64_encode(hash_hmac('sha256', 'site-identify:' . $site_key, $secret, true)), '+/', '-_'), '=');

update_option('agumail_api_url', $api_url);
update_option('agumail_api_key', 'agm_e2e0000000000000000000000000000000000');
update_option('agumail_connection', array(
    'status'        => 'connected',
    'shopName'      => 'AguMail E2E Shop',
    'userId'        => 'user_e2e',
    'integrationId' => 'int_e2e_woocommerce',
    'checkedAt'     => time(),
    'error'         => null,
));
update_option('agumail_config', array(
    'siteKey'        => $site_key,
    'siteScriptUrl'  => $public_url . '/p/_/v1/site.js?v=e2e',
    'siteEndpoint'   => $public_url . '/api/public/site/' . $site_key . '/events',
    'formsLoaderUrl' => $public_url . '/embed/agumail-forms.js',
    'identifySecret' => $identify,
));
update_option('agumail_connected_at', time());
update_option('agumail_tracking_enabled', 'yes');
delete_option('agumail_backoff_until');

WP_CLI::success('AguMail connected, API URL ' . $api_url);
