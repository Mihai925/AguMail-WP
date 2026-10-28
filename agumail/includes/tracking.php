<?php
// Tracking: the on-site script and signed identify for signed-in customers.
//
// The script is AguMail's own (siteRuntime.ts): it records which pages, and on
// a store which products, a recognized visitor looked at, so a flow can follow
// up. It reads its
// site key and endpoint off its own tag, and is added from the config AguMail
// handed over at connect or ping; a site set up by hand under 1.x still has
// its pasted key and script address, which are read as a fallback.
//
// A signed-in customer is named to the script with a signature:
//   sig = base64url(HMAC-SHA256(identifySecret, lowercased trimmed email))
// with identifySecret, the string from the config, used as the key exactly as
// it is. The signature only shows that this store's own pages named the
// address, so it is worth no more than the address: AguMail uses it to record
// product views for a contact it already has (one not unsubscribed, bounced or
// complained), and nothing else. It mints no visitor token, records only
// catalogue fields, and never counts as an event that starts a flow.
//
// The runtime reads the address and signature off its own tag (data-email,
// data-esig), so it knows the visitor before its first page view. An inline
// identify stays as a fallback for a runtime that does not read them. Store
// staff are not named unless the agumail_identify_staff filter says so, and
// signing out forgets the visitor in this browser. Everything here reads
// stored options only.
//
// Website tracking is off until the merchant switches it on. Once the site is
// connected and its AguMail account has site tracking, a notice on the
// Dashboard, the Plugins screen and the AguMail page says so and offers to
// switch it on. It goes once the merchant has chosen: switched it on, saved
// the setting either way, or pressed Keep it off.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_SITE_HANDLE', 'agumail-site');

add_action('init', 'agumail_tracking_register_admin');
add_action('wp_enqueue_scripts', 'agumail_tracking_enqueue');
add_filter('script_loader_tag', 'agumail_tracking_script_tag', 10, 3);
add_action('wp_logout', 'agumail_tracking_forget_visitor');
add_action('admin_notices', 'agumail_tracking_notice');

// ─────────────────────────────────────────────────────────────────────────────
// Signed identify.
// ─────────────────────────────────────────────────────────────────────────────

/** The address as AguMail signs and matches it: trimmed and lowercased. */
function agumail_identify_email($email) {
    $email = trim((string) $email);
    return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
}

/** The signature AguMail accepts next to this address, or '' when the store has no identify secret. */
function agumail_identify_signature($email) {
    $secret = agumail_config('identifySecret');
    if (!is_string($secret) || $secret === '') {
        return '';
    }
    $email = agumail_identify_email($email);
    if ($email === '') {
        return '';
    }
    return agumail_base64url(hash_hmac('sha256', $email, $secret, true));
}

// ─────────────────────────────────────────────────────────────────────────────
// The script.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Where the script comes from and what it reports to: ['src', 'key', 'endpoint'],
 * or null when there is none. Both addresses must be on AguMail's own hosts,
 * whatever is stored: otherwise there is no script.
 */
function agumail_tracking_site() {
    $key = agumail_config('siteKey');
    $src = agumail_config('siteScriptUrl');
    $endpoint = agumail_config('siteEndpoint');
    if (!is_string($key) || $key === '' || !agumail_is_own_url($src)) {
        // A 1.x site set up by hand.
        $key = trim((string) get_option('agumail_site_key', ''));
        $src = trim((string) get_option('agumail_site_src', ''));
        $endpoint = null;
        if ($key === '' || !agumail_is_own_url($src)) {
            return null;
        }
    }
    if (!agumail_is_own_url($endpoint)) {
        $endpoint = agumail_api_url() . '/api/public/site/' . rawurlencode($key) . '/events';
    }
    if (!agumail_is_own_url($endpoint)) {
        return null;
    }
    return array('src' => $src, 'key' => $key, 'endpoint' => $endpoint);
}

function agumail_tracking_enqueue() {
    if (is_admin() || !agumail_tracking_on()) {
        return;
    }
    $site = agumail_tracking_site();
    if (!$site) {
        return;
    }
    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- no version: the address is AguMail's and already carries its own.
    wp_enqueue_script(AGUMAIL_SITE_HANDLE, $site['src'], array(), null, false);
    $identify = agumail_tracking_identify_script();
    if ($identify !== '') {
        wp_add_inline_script(AGUMAIL_SITE_HANDLE, $identify, 'after');
    }
}

/**
 * The script reads its site key and endpoint, and who the visitor is, off its
 * own tag. It loads async so it never holds up the page, and Cloudflare's
 * Rocket Loader is told to leave it alone.
 */
function agumail_tracking_script_tag($tag, $handle, $src = '') {
    if ($handle !== AGUMAIL_SITE_HANDLE || !agumail_tracking_on()) {
        return $tag;
    }
    $site = agumail_tracking_site();
    if (!$site) {
        return $tag;
    }
    $attributes = array('data-site' => esc_attr($site['key']), 'data-endpoint' => esc_url($site['endpoint']));
    $identity = agumail_tracking_identity();
    if ($identity) {
        $attributes['data-email'] = esc_attr($identity['email']);
        if ($identity['sig'] !== '') {
            $attributes['data-esig'] = esc_attr($identity['sig']);
        }
    }
    $attributes['data-cfasync'] = 'false';
    return agumail_script_tag_attributes($tag, $handle, $attributes, true);
}

/**
 * Adds already-escaped attributes to the tag that loads $handle's file.
 * WordPress passes that tag together with the inline scripts around it
 * (translations, before, after), whose text may itself hold "<script src=", so
 * the tag is found by the id WordPress gives it, {handle}-js, and only it is
 * changed. $async adds async unless it is there already.
 */
function agumail_script_tag_attributes($tag, $handle, array $attributes, $async = false) {
    $tag = (string) $tag;
    $add = '';
    foreach ($attributes as $name => $value) {
        $add .= ' ' . $name . '="' . $value . '"';
    }
    $change = function ($match) use ($add, $async) {
        $existing = $match[1];
        $extra = $async && !preg_match('/\sasync(\s|=|$)/i', $existing) ? ' async' : '';
        return '<script' . $add . $existing . $extra . '>';
    };
    $count = 0;
    $id = preg_quote($handle . '-js', '/');
    $changed = preg_replace_callback('/<script\b([^>]*\sid=(["\'])' . $id . '\2[^>]*)>/i', $change, $tag, 1, $count);
    if ($count > 0) {
        return $changed;
    }
    return preg_replace_callback('/<script\b([^>]*\ssrc=[^>]*)>/i', $change, $tag, 1);
}

/**
 * The signed-in customer to name to the script: ['email', 'sig'], or null.
 * Guests get nothing: no address is ever printed for a visitor who is not
 * signed in. Store staff are left out unless agumail_identify_staff says so.
 */
function agumail_tracking_identity() {
    if (!is_user_logged_in()) {
        return null;
    }
    $user = wp_get_current_user();
    if (agumail_tracking_is_staff() && !apply_filters('agumail_identify_staff', false, $user)) {
        return null;
    }
    $email = $user ? agumail_identify_email($user->user_email) : '';
    if ($email === '' || !is_email($email)) {
        return null;
    }
    return array('email' => $email, 'sig' => agumail_identify_signature($email));
}

/** Store staff, as the foundation defines them (agumail_is_staff), else whoever manages WooCommerce. */
function agumail_tracking_is_staff() {
    return function_exists('agumail_is_staff') ? (bool) agumail_is_staff() : current_user_can(AGUMAIL_ADMIN_CAPABILITY);
}

/** The inline fallback for the signed-in customer, or '' when there is none. */
function agumail_tracking_identify_script() {
    $identity = agumail_tracking_identity();
    return $identity ? agumail_tracking_identify_js($identity['email'], $identity['sig']) : '';
}

/**
 * Identifies once the script element itself has loaded, and only when the
 * runtime did not already read the tag (it marks its element
 * data-agumail-identified when it does), so the identify is not sent twice.
 * The address is JSON with <, >, &, ' and " escaped, so it cannot end the tag.
 */
function agumail_tracking_identify_js($email, $sig) {
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $args = wp_json_encode((string) $email, $flags) . ((string) $sig !== '' ? ',' . wp_json_encode((string) $sig, $flags) : '');
    return '(function(){var s=document.getElementById("' . AGUMAIL_SITE_HANDLE . '-js");'
        . 'function go(){if(s&&s.hasAttribute("data-agumail-identified"))return;'
        . 'if(window.agumail&&window.agumail.identify){window.agumail.identify(' . $args . ');}}'
        . 'if(window.agumail){go();}else if(s){s.addEventListener("load",go);}})();';
}

// ─────────────────────────────────────────────────────────────────────────────
// Signing out.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The runtime's cookies this browser sent, each with what expires it: the
 * path and SameSite the runtime sets them with, and no domain, as it sets
 * none (siteRuntime.ts writeCookie).
 */
function agumail_tracking_logout_cookies() {
    $cookies = array();
    foreach (array('agm_e', 'agm_v') as $name) {
        if (isset($_COOKIE[$name])) {
            $cookies[$name] = array('expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax');
        }
    }
    return $cookies;
}

/** Signing out forgets who this browser was: a response header each, no call and no write. */
function agumail_tracking_forget_visitor() {
    foreach (agumail_tracking_logout_cookies() as $name => $options) {
        unset($_COOKIE[$name]);
        if (!headers_sent()) {
            setcookie($name, '', $options);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The setting.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_tracking_register_admin() {
    agumail_admin_register_section('tracking', __('Website tracking', 'agumail'), 'agumail_tracking_render_section', 50, array(
        'icon'        => 'visibility',
        'description' => agumail_woocommerce_active()
            ? __('Product views, so flows can follow up on them.', 'agumail')
            : __('Page views, so flows can follow up on them.', 'agumail'),
    ));
    agumail_admin_register_action('tracking_save', 'agumail_tracking_save', array('agumail_tracking_enabled' => 'text'));
    agumail_admin_register_action('tracking_keep_off', 'agumail_tracking_keep_off');
}

function agumail_tracking_render_section() {
    $on = agumail_tracking_on();
    $store = agumail_woocommerce_active();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('tracking_save');
    echo '<table class="form-table" role="presentation"><tbody><tr>';
    echo '<th scope="row">' . esc_html__('Website tracking', 'agumail') . '</th><td>';
    echo '<label><input type="checkbox" name="agumail_tracking_enabled" id="agumail_tracking_enabled" value="yes"' . checked($on, true, false) . ' /> '
        . esc_html($store ? __('Add the AguMail script to your store', 'agumail') : __('Add the AguMail script to your site', 'agumail')) . '</label>';
    echo '<p class="description">' . esc_html($store
        ? __('Lets flows follow up on the products people looked at. Views are recorded only for visitors AguMail recognizes, such as signed-in customers and people who arrived from your emails.', 'agumail')
        : __('Lets flows follow up on the pages people read. Views are recorded only for visitors AguMail recognizes, such as signed-in members and people who arrived from your emails.', 'agumail')) . '</p>';
    echo '</td></tr></tbody></table>';
    submit_button(__('Save', 'agumail'));
    echo '</form>';
    if (agumail_tracking_site()) {
        return;
    }
    if (!agumail_has_api_key()) {
        echo '<p class="description">' . esc_html($store
            ? __('The script is added once the store is connected.', 'agumail')
            : __('The script is added once the site is connected.', 'agumail')) . '</p>';
        return;
    }
    // Connected by key, on an account that has not switched site tracking on in AguMail yet.
    echo '<div class="agumail-problem is-warning agumail-callout"><p>' . esc_html__('Your AguMail account has no site tracking yet. Switch it on in AguMail under Settings > Site tracking, then check again here.', 'agumail') . '</p>';
    echo '<div class="agumail-actions">';
    agumail_connect_app_button(agumail_admin_app_link('/dashboard/settings#site-tracking', 'tracking'), __('Open site tracking', 'agumail'));
    agumail_connect_button('connect_check', __('Check again', 'agumail'), 'secondary');
    echo '</div></div>';
}

function agumail_tracking_save(array $request) {
    $on = isset($request['agumail_tracking_enabled']) && $request['agumail_tracking_enabled'] !== '' && $request['agumail_tracking_enabled'] !== 'no';
    update_option('agumail_tracking_enabled', $on ? 'yes' : 'no');
    return array('message' => $on ? __('Website tracking is on.', 'agumail') : __('Website tracking is off.', 'agumail'));
}

// ─────────────────────────────────────────────────────────────────────────────
// The notice. Its button is the setting's own save, so the merchant lands on
// the AguMail page with tracking on; Keep it off saves the setting as off and
// goes back to the page they were on. Both are admin actions, with the same
// nonce and capability checks as every other.
// ─────────────────────────────────────────────────────────────────────────────

/** Whether the notice belongs on the screen being drawn: a connected site that has not chosen yet, on the Dashboard, Plugins or AguMail page. */
function agumail_tracking_notice_due() {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || get_option('agumail_tracking_enabled', '') !== ''
        || !agumail_has_api_key() || !agumail_tracking_site()) {
        return false;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen && !empty($screen->id) && in_array($screen->id, array('dashboard', 'plugins', agumail_admin_hook_suffix()), true);
}

function agumail_tracking_notice() {
    if (!agumail_tracking_notice_due()) {
        return;
    }
    $text = agumail_woocommerce_active()
        ? __('Switch it on so your flows can follow up on the products people looked at. Views are recorded only for visitors AguMail recognizes, such as signed-in customers and people who arrived from your emails.', 'agumail')
        : __('Switch it on so your flows can follow up on the pages people read. Views are recorded only for visitors AguMail recognizes, such as signed-in members and people who arrived from your emails.', 'agumail');
    echo '<div class="notice notice-info agumail-tracking-notice"><p><strong>' . esc_html__('AguMail website tracking is off.', 'agumail') . '</strong> ' . esc_html($text) . '</p><p>'
        . '<a class="button button-primary" href="' . esc_url(agumail_admin_action_url('tracking_save', array('agumail_tracking_enabled' => 'yes'))) . '">' . esc_html__('Turn on website tracking', 'agumail') . '</a> '
        . '<a class="agumail-tracking-notice-dismiss" href="' . esc_url(agumail_admin_action_url('tracking_keep_off')) . '">' . esc_html__('Keep it off', 'agumail') . '</a>'
        . '</p></div>';
}

/** Admin action: tracking stays off, the notice goes, and the merchant goes back to the page they were on. */
function agumail_tracking_keep_off() {
    update_option('agumail_tracking_enabled', 'no');
    $back = wp_get_referer();
    return array('redirect' => $back ? $back : admin_url());
}
