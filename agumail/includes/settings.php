<?php
// Settings: where AguMail lives, the key that speaks for this store, and the
// store-wide choices the merchant makes. Every option is read through one of
// these functions, so each default lives in one place.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_DEFAULT_API_URL', 'https://api.agumail.com');
define('AGUMAIL_DEFAULT_APP_URL', 'https://agumail.com');

// Who manages AguMail from wp-admin: the settings page, its tools, admin-ajax.
// A capability of the plugin's own, which agumail_map_admin_capability() turns
// into whoever runs the site: store managers (manage_woocommerce) while
// WooCommerce is active, administrators (manage_options) without it.
define('AGUMAIL_ADMIN_CAPABILITY', 'manage_agumail');

// Who may change the API URL, which is where the key is sent: an administrator,
// not every store manager.
define('AGUMAIL_API_URL_CAPABILITY', 'manage_options');

// AguMail's own hosts: its pages domain serves the site script, its API the
// forms loader and the address the script reports to.
define('AGUMAIL_OWN_HOSTS', 'agupage.com,api.agumail.com');

add_filter('map_meta_cap', 'agumail_map_admin_capability', 10, 2);

/** manage_agumail is manage_woocommerce on a store, manage_options on any other site. */
function agumail_map_admin_capability($caps, $cap) {
    if ($cap !== AGUMAIL_ADMIN_CAPABILITY) {
        return $caps;
    }
    return array(agumail_woocommerce_active() ? 'manage_woocommerce' : 'manage_options');
}

// ─────────────────────────────────────────────────────────────────────────────
// Two modes, one plugin. With WooCommerce active the plugin is a store's: the
// checkout checkbox, carts, orders, products and the store data sync. Without
// it, a WordPress site's: forms, popups, form plugin signups, registrations and
// tracking. The mode is read when it matters, never stored, so a site that
// switches WooCommerce on or off keeps its connection, queue and settings.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Whether WooCommerce is active and loaded. WordPress loads plugins in
 * alphabetical order, so this is known from plugins_loaded on, not while the
 * plugin's own files load.
 */
function agumail_woocommerce_active() {
    return class_exists('WooCommerce') && did_action('woocommerce_loaded') > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Where AguMail lives. wp-config.php can pin either address with AGUMAIL_API_URL
// or AGUMAIL_APP_URL, which is how a development or end-to-end site points the
// plugin at a local server; a pinned address wins over anything saved.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_api_url() {
    return agumail_resolve_url('AGUMAIL_API_URL', 'agumail_api_url', AGUMAIL_DEFAULT_API_URL);
}

function agumail_app_url() {
    return agumail_resolve_url('AGUMAIL_APP_URL', 'agumail_app_url', AGUMAIL_DEFAULT_APP_URL);
}

/** True when wp-config.php pins the API address, so the settings page shows it read-only. */
function agumail_api_url_is_pinned() {
    return defined('AGUMAIL_API_URL') && (string) AGUMAIL_API_URL !== '';
}

function agumail_resolve_url($constant, $option, $default) {
    if (defined($constant) && (string) constant($constant) !== '') {
        return untrailingslashit((string) constant($constant));
    }
    $saved = trim((string) get_option($option, ''));
    return untrailingslashit($saved !== '' ? $saved : $default);
}

/**
 * Whether an address may go on the storefront: the site script, the forms
 * loader, or where the script reports. It must be https on one of AguMail's own
 * hosts, or on a host wp-config.php pins with AGUMAIL_API_URL or AGUMAIL_APP_URL
 * (http allowed there, as a local server runs it). Neither AguMail's answers
 * nor anything saved in the settings adds a host, so the store's pages never
 * load a script from anywhere else.
 */
function agumail_is_own_url($url) {
    if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
    $host = strtolower($parts['host']);
    if ($scheme === 'https' && in_array($host, explode(',', AGUMAIL_OWN_HOSTS), true)) {
        return true;
    }
    if ($scheme !== 'https' && $scheme !== 'http') {
        return false;
    }
    foreach (array('AGUMAIL_API_URL', 'AGUMAIL_APP_URL') as $constant) {
        $pinned = defined($constant) ? strtolower((string) wp_parse_url((string) constant($constant), PHP_URL_HOST)) : '';
        if ($pinned !== '' && $host === $pinned) {
            return true;
        }
    }
    return false;
}

function agumail_api_key() {
    return trim((string) get_option('agumail_api_key', ''));
}

function agumail_has_api_key() {
    return agumail_api_key() !== '';
}

/** Saves the key, autoloaded: every storefront request reads it, so it must not cost a query of its own. */
function agumail_save_api_key($key) {
    return update_option('agumail_api_key', trim((string) $key), true);
}

/**
 * Whether a user (the signed-in one by default) is staff rather than a
 * shopper or reader: anyone who manages AguMail here (store managers with
 * WooCommerce, administrators without), unless the agumail_is_staff filter
 * says otherwise. Staff are never tracked or treated as customers.
 */
function agumail_is_staff($user_id = null) {
    $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;
    $staff = $user_id > 0 && user_can($user_id, AGUMAIL_ADMIN_CAPABILITY);
    return (bool) apply_filters('agumail_is_staff', $staff, $user_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// Store-wide choices.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_default_consent_label() {
    return __('Email me about new products and offers', 'agumail');
}

/**
 * The words next to the marketing checkbox, on both checkouts and the
 * customer account forms. They may carry links (<a href target rel>, nothing
 * else): print them with wp_kses() and agumail_consent_label_tags(), or as
 * agumail_consent_label_text() where only words fit.
 */
function agumail_consent_label() {
    $label = trim((string) get_option('agumail_consent_label', ''));
    return $label !== '' ? $label : agumail_default_consent_label();
}

/** The only markup the label keeps: links. */
function agumail_consent_label_tags() {
    return array('a' => array('href' => true, 'target' => true, 'rel' => true));
}

/** The label as plain words, for the block checkout, which shows it as text. */
function agumail_consent_label_text() {
    return trim(html_entity_decode(wp_strip_all_tags(agumail_consent_label()), ENT_QUOTES, 'UTF-8'));
}

/** Whether the checkout's marketing checkbox starts ticked. Off unless the merchant turns it on. */
function agumail_checkout_optin_ticked_by_default() {
    return get_option('agumail_checkout_optin_default', 'no') === 'yes';
}

/** A yes/no switch: agumail_registration_optin, agumail_account_toggle. Both default to yes. */
function agumail_switch_on($option) {
    return get_option($option, 'yes') !== 'no';
}

/** Whether website tracking is on. Off until the merchant switches it on. */
function agumail_tracking_on() {
    return get_option('agumail_tracking_enabled', 'no') === 'yes';
}

/** The config AguMail last handed over through ping or connect, or one key of it. */
function agumail_config($key = null) {
    $config = get_option('agumail_config', array());
    if (!is_array($config)) {
        $config = array();
    }
    if ($key === null) {
        return $config;
    }
    return isset($config[$key]) ? $config[$key] : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Connection state, as last seen by ping, connect or the drain:
// {status, shopName, userId, integrationId, checkedAt, error, errorCode,
// platform}. status is '' until the site has connected, then connected,
// disconnected or error. errorCode is the HTTP status behind an error, so the
// settings page can tell a revoked key (401) from a lapsed plan (402).
//
// platform is how the site connected: woocommerce when AguMail took it as a
// store (one-click, or a key through /api/v1/connect), wordpress when only the
// key was checked (ping), for a site without WooCommerce or a store whose plan
// has no store sync. A connection from before platform was kept is a store's.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_connection() {
    $saved = get_option('agumail_connection', array());
    return array_merge(array(
        'status'        => '',
        'shopName'      => '',
        'userId'        => '',
        'integrationId' => '',
        'checkedAt'     => 0,
        'error'         => '',
        'errorCode'     => 0,
    ), is_array($saved) ? $saved : array());
}

function agumail_update_connection(array $changes) {
    return update_option('agumail_connection', array_merge(agumail_connection(), $changes), false);
}

/** AguMail refused the key or the plan (401, 402 or 403): the drain stops and the settings page says why. */
function agumail_record_connection_error($code, $message) {
    agumail_update_connection(array(
        'status'    => 'error',
        'error'     => (string) $message,
        'errorCode' => (int) $code,
        'checkedAt' => time(),
    ));
}

/** A call went through again, so an earlier refusal no longer stands. */
function agumail_clear_connection_error() {
    $connection = agumail_connection();
    if ($connection['status'] !== 'error') {
        return;
    }
    agumail_update_connection(array('status' => 'connected', 'error' => '', 'errorCode' => 0, 'checkedAt' => time()));
}

/** woocommerce or wordpress: how the site connected. */
function agumail_connection_platform() {
    $saved = get_option('agumail_connection', array());
    $platform = is_array($saved) && isset($saved['platform']) ? $saved['platform'] : '';
    return $platform === 'wordpress' ? 'wordpress' : 'woocommerce';
}

// ─────────────────────────────────────────────────────────────────────────────
// Store sync: orders, carts and products, and the store data sync. AguMail
// takes them from a store it connected as one, on a plan that has store sync
// (the Commerce plans and the trial). Contacts, signups and forms work on every
// plan, so they never wait on any of this.
//
// When AguMail refuses a store request for the plan (402, code PLAN_FEATURE,
// feature storeSync), sending store data is held: orders and products wait in
// the queue, carts are not kept, and everything else carries on. The drain
// checks the plan again every hour, with a request that carries no data
// (agumail_store_sync_check()), and lets the store data go once it is back.
// ─────────────────────────────────────────────────────────────────────────────

define('AGUMAIL_STORE_HOLD_OPTION', 'agumail_store_sync_refused');
define('AGUMAIL_STORE_CHECK_OPTION', 'agumail_store_sync_available');
define('AGUMAIL_STORE_CHECK_EVERY', HOUR_IN_SECONDS);

/** WooCommerce is active and the site is connected to AguMail as a store. */
function agumail_store_connected() {
    return agumail_has_api_key() && agumail_woocommerce_active() && agumail_connection_platform() === 'woocommerce';
}

/** When AguMail last refused store data for the plan, or 0 while it is not held. */
function agumail_store_sync_held_since() {
    return (int) get_option(AGUMAIL_STORE_HOLD_OPTION, 0);
}

function agumail_store_sync_held() {
    return agumail_store_sync_held_since() > 0;
}

/** Store data goes to AguMail: a connected store, not held by the plan. */
function agumail_store_sync_on() {
    return agumail_store_connected() && !agumail_store_sync_held();
}

/**
 * What the last plan check found: ['available' => true|false, 'at' => time],
 * or null when nothing has been checked yet.
 */
function agumail_store_sync_availability() {
    $saved = get_option(AGUMAIL_STORE_CHECK_OPTION, array());
    if (!is_array($saved) || !isset($saved['available'], $saved['at'])) {
        return null;
    }
    return array('available' => (bool) $saved['available'], 'at' => (int) $saved['at']);
}

/** Whether a plan refusal is about store sync: AguMail's 402 names the feature. */
function agumail_is_store_sync_refusal($response) {
    return is_array($response) && isset($response['code']) && (int) $response['code'] === 402
        && is_array($response['body'])
        && isset($response['body']['code'], $response['body']['feature'])
        && $response['body']['code'] === 'PLAN_FEATURE' && $response['body']['feature'] === 'storeSync';
}

// ─────────────────────────────────────────────────────────────────────────────
// Backoff: a moment before which the drain sends nothing at all, set by a 429's
// Retry-After or by a refused key.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_backoff_until() {
    return (int) get_option('agumail_backoff_until', 0);
}

function agumail_set_backoff_until($timestamp) {
    update_option('agumail_backoff_until', (int) $timestamp, false);
}

function agumail_clear_backoff() {
    delete_option('agumail_backoff_until');
}
