<?php
// The settings page, AguMail in the admin menu, built from sections and actions
// that each feature registers.
//
// A section is a titled block of the page. An action is something the merchant
// does from it (save, connect, retry, sync); it is reached through
// admin-post.php for forms and links, or admin-ajax.php for scripts, and every
// action passes the same checks here: the capability to manage AguMail
// (AGUMAIL_ADMIN_CAPABILITY) and a nonce of its own. An action receives only
// the fields it names, each already cleaned. Actions run as background work,
// so they may call AguMail.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_ADMIN_PAGE', 'agumail');

// ─────────────────────────────────────────────────────────────────────────────
// Registries.
// ─────────────────────────────────────────────────────────────────────────────

function &agumail_admin_registry() {
    static $registry = array('sections' => array(), 'actions' => array(), 'fields' => array());
    return $registry;
}

/**
 * A block of the settings page; lower priorities come first. Registering an id
 * again replaces it. $args may give an icon (a dashicon name without its
 * dashicons- prefix) and a one-line description shown under the title.
 */
function agumail_admin_register_section($id, $title, $render_callback, $priority = 50, array $args = array()) {
    $registry = &agumail_admin_registry();
    $registry['sections'][$id] = array(
        'id'          => (string) $id,
        'title'       => (string) $title,
        'render'      => $render_callback,
        'priority'    => (int) $priority,
        'icon'        => isset($args['icon']) ? (string) $args['icon'] : 'admin-generic',
        'description' => isset($args['description']) ? (string) $args['description'] : '',
        'order'       => isset($registry['sections'][$id]) ? $registry['sections'][$id]['order'] : count($registry['sections']),
    );
}

/**
 * Something the merchant can do. $fields names the request fields it reads,
 * each with how it is cleaned:
 *   text   sanitize_text_field()
 *   key    sanitize_key()
 *   int    absint()
 *   label  wp_kses() with the consent label's tags (links only)
 * $callback receives those fields that were sent, cleaned, and returns one of:
 *   a message string, or ['message' => ..., 'type' => success|error|warning|info]
 *   a WP_Error, shown as an error
 *   ['redirect' => url] to send the merchant somewhere else: this site, or AguMail's app
 *   null, to say nothing
 * Called from a script, the value comes back as JSON instead.
 */
function agumail_admin_register_action($action, $callback, array $fields = array()) {
    $registry = &agumail_admin_registry();
    $registry['actions'][(string) $action] = $callback;
    $registry['fields'][(string) $action] = $fields;
}

/** Sections in page order. Until a feature supplies its own, a plain Connection section stands in. */
function agumail_admin_sections() {
    $registry = &agumail_admin_registry();
    $sections = $registry['sections'];
    if (!isset($sections['connection'])) {
        $sections['connection'] = array('id' => 'connection', 'title' => __('Connection', 'agumail'),
            'render' => 'agumail_admin_render_connection_placeholder', 'priority' => 10, 'icon' => 'admin-links', 'description' => '', 'order' => -1);
    }
    uasort($sections, function ($a, $b) {
        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] < $b['priority'] ? -1 : 1;
        }
        return $a['order'] < $b['order'] ? -1 : 1;
    });
    return $sections;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reaching an action.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_admin_nonce($action) {
    return wp_create_nonce('agumail_action_' . $action);
}

/** The hidden fields a form needs to post to an action. */
function agumail_admin_action_fields($action) {
    echo '<input type="hidden" name="action" value="agumail" />';
    echo '<input type="hidden" name="agumail_action" value="' . esc_attr($action) . '" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(agumail_admin_nonce($action)) . '" />';
}

/** A link that runs an action (for a Retry button, say). */
function agumail_admin_action_url($action, array $args = array()) {
    return add_query_arg(array_merge($args, array(
        'action'         => 'agumail',
        'agumail_action' => $action,
        '_wpnonce'       => agumail_admin_nonce($action),
    )), admin_url('admin-post.php'));
}

function agumail_admin_page_url(array $args = array()) {
    return add_query_arg(array_merge(array('page' => AGUMAIL_ADMIN_PAGE), $args), admin_url('admin.php'));
}

add_action('admin_post_agumail', 'agumail_admin_handle_post');
add_action('wp_ajax_agumail', 'agumail_admin_handle_ajax');

/** A form or link: runs the action, keeps its message for the next page view, and goes back to the settings page. */
function agumail_admin_handle_post() {
    $result = agumail_admin_dispatch(agumail_admin_requested_action());
    if (is_wp_error($result) && in_array($result->get_error_code(), array('agumail_forbidden', 'agumail_bad_nonce'), true)) {
        wp_die(esc_html($result->get_error_message()), '', array('response' => 403));
    }
    $redirect = agumail_admin_page_url();
    if (is_array($result) && !empty($result['redirect'])) {
        $redirect = $result['redirect'];
    }
    $notice = agumail_admin_notice_from($result);
    if ($notice) {
        agumail_admin_notice($notice['type'], $notice['message']);
    }
    // An action may send the merchant to AguMail itself (to approve a
    // connection, say), and only there: its host is allowed for this redirect alone.
    $allow_agumail = 'agumail_allow_app_redirect';
    add_filter('allowed_redirect_hosts', $allow_agumail);
    try {
        wp_safe_redirect($redirect);
    } finally {
        remove_filter('allowed_redirect_hosts', $allow_agumail);
    }
    exit;
}

function agumail_allow_app_redirect($hosts) {
    $host = wp_parse_url(agumail_app_url(), PHP_URL_HOST);
    if ($host) {
        $hosts[] = $host;
    }
    return $hosts;
}

/** A script: runs the action and answers with JSON. */
function agumail_admin_handle_ajax() {
    $result = agumail_admin_dispatch(agumail_admin_requested_action());
    if (is_wp_error($result)) {
        $status = in_array($result->get_error_code(), array('agumail_forbidden', 'agumail_bad_nonce'), true) ? 403 : 400;
        wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()), $status);
    }
    wp_send_json_success($result);
}

/** Which action the request asks for, or ''. */
function agumail_admin_requested_action() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the action's own nonce is checked in agumail_admin_dispatch().
    return isset($_REQUEST['agumail_action']) && is_string($_REQUEST['agumail_action']) ? sanitize_key(wp_unslash($_REQUEST['agumail_action'])) : '';
}

/** The nonce the request carries: _wpnonce from a form or link, _ajax_nonce from a script. */
function agumail_admin_request_nonce() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- this is the nonce, read to be verified.
    foreach (array('_wpnonce', '_ajax_nonce') as $name) {
        if (isset($_REQUEST[$name]) && is_string($_REQUEST[$name])) {
            return sanitize_text_field(wp_unslash($_REQUEST[$name]));
        }
    }
    // phpcs:enable
    return '';
}

/**
 * The fields $action names, those the request sent, each cleaned as the
 * action asked. Called only once the nonce has passed.
 */
function agumail_admin_request_fields($action) {
    $registry = &agumail_admin_registry();
    $wanted = isset($registry['fields'][$action]) ? $registry['fields'][$action] : array();
    $fields = array();
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- agumail_admin_dispatch() verified the nonce first.
    foreach ($wanted as $name => $type) {
        if (!isset($_REQUEST[$name]) || !is_string($_REQUEST[$name])) {
            continue;
        }
        if ($type === 'int') {
            $fields[$name] = absint(wp_unslash($_REQUEST[$name]));
        } elseif ($type === 'key') {
            $fields[$name] = sanitize_key(wp_unslash($_REQUEST[$name]));
        } elseif ($type === 'label') {
            $fields[$name] = wp_kses(wp_unslash($_REQUEST[$name]), agumail_consent_label_tags());
        } else {
            $fields[$name] = sanitize_text_field(wp_unslash($_REQUEST[$name]));
        }
    }
    // phpcs:enable
    return $fields;
}

/** The central checks, then the action itself with its fields, as background work. */
function agumail_admin_dispatch($action) {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return new WP_Error('agumail_forbidden', __('You are not allowed to manage AguMail.', 'agumail'));
    }
    if ($action === '' || !wp_verify_nonce(agumail_admin_request_nonce(), 'agumail_action_' . $action)) {
        return new WP_Error('agumail_bad_nonce', __('This link has expired. Go back to the AguMail settings and try again.', 'agumail'));
    }
    $registry = &agumail_admin_registry();
    if (!isset($registry['actions'][$action]) || !is_callable($registry['actions'][$action])) {
        return new WP_Error('agumail_unknown_action', __('Unknown AguMail action.', 'agumail'));
    }
    return agumail_run_in_background($registry['actions'][$action], array(agumail_admin_request_fields($action)));
}

// ─────────────────────────────────────────────────────────────────────────────
// Notices, kept per merchant until the page shows them.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_admin_notice($type, $message) {
    set_transient('agumail_notice_' . get_current_user_id(), array('type' => (string) $type, 'message' => (string) $message), 5 * MINUTE_IN_SECONDS);
}

function agumail_admin_take_notice() {
    $key = 'agumail_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if ($notice) {
        delete_transient($key);
    }
    return is_array($notice) ? $notice : null;
}

function agumail_admin_notice_from($result) {
    if (is_wp_error($result)) {
        return array('type' => 'error', 'message' => $result->get_error_message());
    }
    if (is_string($result) && $result !== '') {
        return array('type' => 'success', 'message' => $result);
    }
    if (is_array($result) && !empty($result['message'])) {
        return array('type' => isset($result['type']) ? $result['type'] : 'success', 'message' => $result['message']);
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// The page.
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', 'agumail_admin_menu');
add_action('admin_enqueue_scripts', 'agumail_admin_enqueue');

/** Its own item in the admin menu, with or without WooCommerce, just above Appearance. */
function agumail_admin_menu() {
    $hook = add_menu_page(__('AguMail', 'agumail'), __('AguMail', 'agumail'), AGUMAIL_ADMIN_CAPABILITY, AGUMAIL_ADMIN_PAGE,
        'agumail_admin_render_page', 'dashicons-email-alt', '58.9');
    agumail_admin_hook_suffix(is_string($hook) ? $hook : '');
}

/** The settings page's hook suffix, as add_menu_page() gave it; set once, read by whatever enqueues for the page. */
function agumail_admin_hook_suffix($set = null) {
    static $suffix = 'toplevel_page_' . AGUMAIL_ADMIN_PAGE;
    if ($set !== null && $set !== '') {
        $suffix = (string) $set;
    }
    return $suffix;
}

/** The page's stylesheet, and the script that marks the section in view in its side nav. */
function agumail_admin_enqueue($hook_suffix = '') {
    if ($hook_suffix !== agumail_admin_hook_suffix() || !current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return;
    }
    wp_enqueue_style('agumail-admin-page', plugins_url('assets/css/admin.css', AGUMAIL_FILE), array(), AGUMAIL_VERSION);
    wp_enqueue_script('agumail-admin-nav', plugins_url('assets/js/admin-nav.js', AGUMAIL_FILE), array(), AGUMAIL_VERSION, true);
}

function agumail_admin_render_page() {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return;
    }
    $notice = agumail_admin_take_notice();
    $sections = agumail_admin_sections();
    echo '<div class="wrap agumail-settings">';
    agumail_admin_render_header();
    // WordPress moves other plugins' notices to just after this line, so they
    // sit under the header and not inside it.
    echo '<hr class="wp-header-end" />';
    if ($notice) {
        $type = in_array($notice['type'], array('success', 'error', 'warning', 'info'), true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }
    echo '<div class="agumail-layout">';
    echo '<nav class="agumail-nav" aria-label="' . esc_attr__('AguMail settings', 'agumail') . '"><ul>';
    foreach ($sections as $section) {
        echo '<li><a href="#agumail-section-' . esc_attr($section['id']) . '"><span class="dashicons dashicons-' . esc_attr($section['icon']) . '" aria-hidden="true"></span>'
            . esc_html($section['title']) . '</a></li>';
    }
    echo '</ul></nav><div class="agumail-main">';
    foreach ($sections as $section) {
        echo '<div class="agumail-section agumail-card" id="agumail-section-' . esc_attr($section['id']) . '">';
        echo '<div class="agumail-card-header"><span class="agumail-card-icon dashicons dashicons-' . esc_attr($section['icon']) . '" aria-hidden="true"></span><div>';
        echo '<h2>' . esc_html($section['title']) . '</h2>';
        if ($section['description'] !== '') {
            echo '<p class="agumail-card-description">' . esc_html($section['description']) . '</p>';
        }
        echo '</div></div><div class="agumail-card-body">';
        if (is_callable($section['render'])) {
            call_user_func($section['render']);
        }
        echo '</div></div>';
    }
    echo '</div></div></div>';
}

/** The title, whether the store is connected, and a way into AguMail itself. */
function agumail_admin_render_header() {
    $connected = agumail_has_api_key();
    $connection = agumail_connection();
    if (!$connected) {
        $state = 'neutral';
        $label = __('Not connected', 'agumail');
    } elseif ($connection['status'] === 'error' || agumail_drain_paused()) {
        $state = 'warning';
        $label = __('Needs attention', 'agumail');
    } else {
        $state = 'success';
        $label = __('Connected', 'agumail');
    }
    echo '<div class="agumail-header">';
    echo '<span class="agumail-logo dashicons dashicons-email-alt" aria-hidden="true"></span>';
    echo '<h1>' . esc_html__('AguMail', 'agumail') . '</h1>';
    echo '<span class="agumail-badge is-' . esc_attr($state) . '">' . esc_html($label) . '</span>';
    if ($connected && $connection['shopName'] !== '') {
        echo '<span class="agumail-header-account">' . esc_html($connection['shopName']) . '</span>';
    }
    if ($connected) {
        echo '<a class="button agumail-open-app" href="' . esc_url(agumail_app_url()) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Open AguMail', 'agumail') . '<span class="dashicons dashicons-external" aria-hidden="true"></span>'
            . '<span class="screen-reader-text">' . esc_html__('(opens in a new tab)', 'agumail') . '</span></a>';
    }
    echo '</div>';
}

/** Where AguMail is and whether a key is saved, until the connect feature supplies its own section. */
function agumail_admin_render_connection_placeholder() {
    $connection = agumail_connection();
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('AguMail server', 'agumail') . '</th><td><code>' . esc_html(agumail_api_url()) . '</code>';
    if (agumail_api_url_is_pinned()) {
        echo '<p class="description">' . esc_html__('Set in wp-config.php.', 'agumail') . '</p>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Status', 'agumail') . '</th><td>';
    if (!agumail_has_api_key()) {
        esc_html_e('Not connected.', 'agumail');
    } elseif ($connection['status'] === 'error' && $connection['error'] !== '') {
        /* translators: %s: the reason AguMail gave for refusing the store. */
        echo esc_html(sprintf(__('AguMail refused this store: %s', 'agumail'), $connection['error']));
    } else {
        esc_html_e('Connected.', 'agumail');
    }
    echo '</td></tr></tbody></table>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Links into AguMail's app: signing up, signing in, the API keys, site
// tracking, the plans. Each says where in the plugin it was followed from, so
// AguMail can tell which of them bring people in.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * An address in AguMail's app. $path may carry a #fragment; $place names the
 * spot in the plugin the link sits in (utm_campaign).
 */
function agumail_admin_app_link($path, $place, array $args = array()) {
    $fragment = '';
    $hash = strpos($path, '#');
    if ($hash !== false) {
        $fragment = substr($path, $hash);
        $path = substr($path, 0, $hash);
    }
    $args = array_merge($args, array(
        'utm_source'   => 'wordpress',
        'utm_medium'   => 'plugin',
        'utm_campaign' => (string) $place,
    ));
    return agumail_app_url() . $path . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986) . $fragment;
}

/** Where a new account starts: a store's signup asks AguMail to set the account up for a store. */
function agumail_admin_signup_link($place) {
    return agumail_admin_app_link('/signup', $place, agumail_woocommerce_active() ? array('for' => 'store') : array());
}

// ─────────────────────────────────────────────────────────────────────────────
// Checkout: the words next to the marketing checkbox, and whether it starts
// ticked. A store's section; a site without WooCommerce keeps the words in its
// Registration section (customers.php).
// ─────────────────────────────────────────────────────────────────────────────

// Registered on init, like every section with a translated title: WordPress
// loads translations no earlier.
add_action('init', 'agumail_admin_register_checkout');

function agumail_admin_register_checkout() {
    agumail_admin_register_action('save_checkout', 'agumail_admin_save_checkout', array(
        'agumail_consent_label'          => 'label',
        'agumail_checkout_optin_default' => 'text',
    ));
    if (!agumail_woocommerce_active()) {
        return;
    }
    agumail_admin_register_section('checkout', __('Checkout', 'agumail'), 'agumail_admin_render_checkout', 40, array(
        'icon'        => 'cart',
        'description' => __('The marketing checkbox shoppers see when they check out.', 'agumail'),
    ));
}

function agumail_admin_render_checkout() {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('save_checkout');
    echo '<table class="form-table" role="presentation"><tbody><tr>';
    echo '<th scope="row"><label for="agumail_consent_label">' . esc_html__('Checkbox label', 'agumail') . '</label></th>';
    echo '<td><input name="agumail_consent_label" id="agumail_consent_label" type="text" class="large-text" value="' . esc_attr(agumail_consent_label()) . '" />';
    echo '<p class="description">' . esc_html__('Shown next to the marketing checkbox on the classic and block checkouts, once the store is connected.', 'agumail') . '</p>';
    echo '<p class="description">' . esc_html__('Links show on the classic checkout and registration forms. The block checkout shows the words only.', 'agumail') . '</p></td>';
    echo '</tr><tr>';
    echo '<th scope="row">' . esc_html__('Checkbox', 'agumail') . '</th>';
    echo '<td><label for="agumail_checkout_optin_default"><input type="checkbox" name="agumail_checkout_optin_default" id="agumail_checkout_optin_default" value="yes"'
        . checked(agumail_checkout_optin_ticked_by_default(), true, false) . ' /> ' . esc_html__('Tick the box by default', 'agumail') . '</label></td>';
    echo '</tr></tbody></table>';
    submit_button(__('Save', 'agumail'));
    echo '</form>';
}

function agumail_admin_save_checkout(array $request) {
    agumail_admin_save_consent_label(isset($request['agumail_consent_label']) ? $request['agumail_consent_label'] : '');
    update_option('agumail_checkout_optin_default', !empty($request['agumail_checkout_optin_default']) ? 'yes' : 'no');
    return array('message' => __('Checkout settings saved.', 'agumail'));
}

/** Saves the checkbox's words; empty, or the default words, means the default. */
function agumail_admin_save_consent_label($raw) {
    $label = agumail_admin_clean_label($raw);
    if ($label === '' || $label === agumail_default_consent_label()) {
        delete_option('agumail_consent_label');
    } else {
        update_option('agumail_consent_label', $label);
    }
}

/** One line of words, with links and no other markup. */
function agumail_admin_clean_label($label) {
    $label = wp_kses(is_string($label) ? $label : '', agumail_consent_label_tags());
    return trim(preg_replace('/[\r\n\t ]+/', ' ', $label));
}
