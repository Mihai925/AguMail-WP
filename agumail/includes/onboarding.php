<?php
// Onboarding: from activating the plugin to a connected site.
//
// Activating the plugin from the Plugins screen takes the merchant who did it
// to the AguMail page, once. Until the site is connected, its row on the
// Plugins screen links to Connect and to a free AguMail account, and a notice
// on the Dashboard and the Plugins screen offers the way in (the one-click
// connect on a store, the AguMail page elsewhere); the merchant can dismiss it
// for good. Once connected, the row links to
// Settings and the notice is gone.
//
// A store also hears, once each, when its plan stops or starts carrying store
// sync (drain.php sets agumail_store_notice): held, resumed, or, for a store
// connected for forms and signups, offer (WooCommerce just arrived) and
// available (its plan now carries store sync).

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_ACTIVATION_REDIRECT', 'agumail_activation_redirect');

add_action('init', 'agumail_onboarding_register_admin');
add_action('admin_init', 'agumail_onboarding_redirect', 5);
add_filter('plugin_action_links_' . plugin_basename(AGUMAIL_FILE), 'agumail_onboarding_action_links');
add_filter('plugin_row_meta', 'agumail_onboarding_row_meta', 10, 2);
add_action('admin_notices', 'agumail_onboarding_notice');
add_action('admin_notices', 'agumail_onboarding_store_notice');

function agumail_onboarding_register_admin() {
    agumail_admin_register_action('dismiss_connect_notice', 'agumail_onboarding_dismiss_notice');
    agumail_admin_register_action('dismiss_store_notice', 'agumail_onboarding_dismiss_store_notice');
}

// ─────────────────────────────────────────────────────────────────────────────
// The redirect after activation.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Called by agumail_activate(): remembers who activated the plugin, so their
 * next wp-admin page takes them to the settings page. Not when activating
 * across a network, from WP-CLI, the REST API or admin-ajax (the Add Plugins
 * screen, which keeps the merchant where they are), or several plugins at once
 * (the merchant is busy with the others), nor for a store already connected.
 */
function agumail_onboarding_arm_redirect($network_wide = false) {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- plugins.php checked its own nonce; only which of its actions is running is read.
    $bulk = isset($_REQUEST['activate-multi']);
    foreach (array('action', 'action2') as $name) {
        if (isset($_REQUEST[$name]) && is_string($_REQUEST[$name]) && sanitize_key(wp_unslash($_REQUEST[$name])) === 'activate-selected') {
            $bulk = true;
        }
    }
    // phpcs:enable
    if ($network_wide || $bulk || agumail_is_cli() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax()
        || agumail_has_api_key() || get_current_user_id() <= 0) {
        return;
    }
    set_transient(AGUMAIL_ACTIVATION_REDIRECT, get_current_user_id(), 5 * MINUTE_IN_SECONDS);
}

/**
 * The first wp-admin page the activating merchant opens: the settings page
 * instead, once. The mark is removed before anything else, so it can never
 * send anyone round in circles. Another administrator's page leaves it for
 * the one who activated.
 */
function agumail_onboarding_redirect() {
    if (wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    $user_id = (int) get_transient(AGUMAIL_ACTIVATION_REDIRECT);
    if ($user_id <= 0 || $user_id !== get_current_user_id()) {
        return;
    }
    delete_transient(AGUMAIL_ACTIVATION_REDIRECT);
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only where the merchant is, to leave a bulk activation and our own page alone.
    $bulk = isset($_GET['activate-multi']);
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    // phpcs:enable
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || agumail_has_api_key() || $bulk || $page === AGUMAIL_ADMIN_PAGE) {
        return;
    }
    wp_safe_redirect(agumail_admin_page_url());
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// The Plugins screen row.
// ─────────────────────────────────────────────────────────────────────────────

/** Connect (Settings, once connected) first among the row's links. */
function agumail_onboarding_action_links($links) {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return $links;
    }
    $label = agumail_has_api_key() ? __('Settings', 'agumail') : __('Connect', 'agumail');
    array_unshift($links, '<a href="' . esc_url(agumail_admin_page_url()) . '">' . esc_html($label) . '</a>');
    return $links;
}

/** Under the description, until the site is connected: where a free AguMail account starts. */
function agumail_onboarding_row_meta($meta, $file = '') {
    if ($file !== plugin_basename(AGUMAIL_FILE) || agumail_has_api_key() || !current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return $meta;
    }
    $meta[] = '<a href="' . esc_url(agumail_admin_signup_link('plugins-row')) . '" target="_blank" rel="noopener noreferrer">'
        . esc_html__('Create a free AguMail account', 'agumail') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'agumail') . '</span></a>';
    return $meta;
}

// ─────────────────────────────────────────────────────────────────────────────
// The connect notice. Its button starts the one-click connect straight away;
// Dismiss is an admin action, so it passes the same nonce and capability
// checks as every other.
// ─────────────────────────────────────────────────────────────────────────────

/** Whether the notice belongs on the screen being drawn. */
function agumail_onboarding_notice_due() {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || agumail_has_api_key()
        || get_option('agumail_connect_notice_dismissed') === 'yes') {
        return false;
    }
    return agumail_onboarding_screen_due();
}

/** The Dashboard and the Plugins screen only. The AguMail page says it all already. */
function agumail_onboarding_screen_due() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen && !empty($screen->id) && in_array($screen->id, array('dashboard', 'plugins'), true);
}

/**
 * A store connects in one click from the notice; any other site goes to the
 * AguMail page, where it connects with a key. Both offer a free account.
 */
function agumail_onboarding_notice() {
    if (!agumail_onboarding_notice_due()) {
        return;
    }
    $signup = '<a class="button" href="' . esc_url(agumail_admin_signup_link('notice')) . '" target="_blank" rel="noopener noreferrer">'
        . esc_html__('Create a free account', 'agumail') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'agumail') . '</span></a> ';
    if (agumail_woocommerce_active()) {
        $text = esc_html__('Connect AguMail to start syncing your customers, orders and carts.', 'agumail');
        $start = '<a class="button button-primary" href="' . esc_url(agumail_admin_action_url('connect_start')) . '">' . esc_html__('Connect to AguMail', 'agumail') . '</a> ';
    } else {
        $text = esc_html__('Grow your email list with AguMail: signup forms, popups and the forms you already have, with welcome emails that send themselves.', 'agumail');
        $start = '<a class="button button-primary" href="' . esc_url(agumail_admin_page_url()) . '">' . esc_html__('Get started', 'agumail') . '</a> ';
    }
    echo '<div class="notice notice-info agumail-connect-notice"><p>' . $text . '</p><p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        . $start . $signup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        . '<a class="agumail-connect-notice-dismiss" href="' . esc_url(agumail_admin_action_url('dismiss_connect_notice')) . '">' . esc_html__('Dismiss', 'agumail') . '</a>'
        . '</p></div>';
}

/** Admin action: the notice stays away for good, and the merchant goes back to the page they were on. */
function agumail_onboarding_dismiss_notice() {
    update_option('agumail_connect_notice_dismissed', 'yes', false);
    $back = wp_get_referer();
    return array('redirect' => $back ? $back : admin_url());
}

// ─────────────────────────────────────────────────────────────────────────────
// Store sync and the plan, said once each: held (the plan stopped carrying it),
// resumed, offer (a site connected by key that has just gained WooCommerce) or
// available (a store connected for forms and signups whose plan now carries
// it). The AguMail page says it in its Connection section anyway.
// ─────────────────────────────────────────────────────────────────────────────

/** held, resumed, offer or available, when it belongs on the screen being drawn; '' otherwise. */
function agumail_onboarding_store_notice_due() {
    $notice = (string) get_option('agumail_store_notice', '');
    if (!in_array($notice, array('held', 'resumed', 'offer', 'available'), true) || !current_user_can(AGUMAIL_ADMIN_CAPABILITY)
        || !agumail_has_api_key() || !agumail_woocommerce_active() || !agumail_onboarding_screen_due()) {
        return '';
    }
    if ($notice === 'held' && !agumail_store_sync_held()) {
        return '';
    }
    if (in_array($notice, array('offer', 'available'), true) && agumail_connection_platform() !== 'wordpress') {
        return '';
    }
    return $notice;
}

function agumail_onboarding_store_notice() {
    $notice = agumail_onboarding_store_notice_due();
    if ($notice === '') {
        return;
    }
    $dismiss = '<a class="agumail-store-notice-dismiss" href="' . esc_url(agumail_admin_action_url('dismiss_store_notice')) . '">' . esc_html__('Dismiss', 'agumail') . '</a>';
    if ($notice === 'held') {
        echo '<div class="notice notice-warning agumail-store-notice"><p><strong>' . esc_html__('AguMail paused store sync: your plan does not include it.', 'agumail') . '</strong> '
            . esc_html__('Signups, forms and checkout subscribers keep going to AguMail. Orders and products wait, and go once your plan includes store sync.', 'agumail') . '</p><p>'
            . '<a class="button" href="' . esc_url(agumail_admin_app_link('/pricing', 'notice-store-held')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('See plans', 'agumail')
            . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'agumail') . '</span></a> '
            . $dismiss . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    } elseif ($notice === 'offer') {
        echo '<div class="notice notice-info agumail-store-notice"><p><strong>' . esc_html__('AguMail can sync your store too.', 'agumail') . '</strong> '
            . esc_html__('Recover abandoned carts, see the revenue your emails bring in and put your products in emails. Store sync is on AguMail\'s Commerce plans; your forms and signups keep working either way.', 'agumail') . '</p><p>'
            . '<a class="button" href="' . esc_url(agumail_admin_app_link('/pricing', 'notice-store-offer')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('See plans', 'agumail')
            . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'agumail') . '</span></a> '
            . $dismiss . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    } elseif ($notice === 'resumed') {
        echo '<div class="notice notice-success agumail-store-notice"><p>' . esc_html__('AguMail store sync is back on. Orders and products that waited are on their way.', 'agumail') . ' '
            . $dismiss . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    } else {
        echo '<div class="notice notice-info agumail-store-notice"><p><strong>' . esc_html__('Your AguMail plan now includes store sync.', 'agumail') . '</strong> '
            . esc_html__('Sync your store to recover abandoned carts and see the revenue your emails bring in.', 'agumail') . '</p><p>'
            . '<a class="button button-primary" href="' . esc_url(agumail_admin_action_url('connect_store')) . '">' . esc_html__('Sync your store', 'agumail') . '</a> '
            . $dismiss . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    }
}

/** Admin action: the store notice goes, and the merchant goes back to the page they were on. */
function agumail_onboarding_dismiss_store_notice() {
    delete_option('agumail_store_notice');
    $back = wp_get_referer();
    return array('redirect' => $back ? $back : admin_url());
}
