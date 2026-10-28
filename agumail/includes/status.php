<?php
// Status: how the store's link to AguMail is doing, in one section of the
// settings page. The connection and account, when AguMail last took
// something, what is waiting (by type) and how old the oldest item is, whether
// sending is paused and until when, the items AguMail refused (Retry or
// Discard, one or all, through the outbox's own functions), the store data
// sync, and the way to the plugin's log.
//
// Everything here reads what the plugin already keeps; nothing calls AguMail.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_STATUS_FAILED_SHOWN', 50);

add_action('init', 'agumail_status_register_admin');
add_action('agumail_drain_sent', 'agumail_status_record_sent', 10, 2);
add_action('agumail_bulk_sent', 'agumail_status_record_sent', 10, 2);

function agumail_status_register_admin() {
    agumail_admin_register_section('status', __('Status', 'agumail'), 'agumail_status_render_section', 90, array(
        'icon'        => 'info-outline',
        'description' => __('What was sent, and anything waiting or failed.', 'agumail'),
    ));
    agumail_admin_register_action('status_retry', 'agumail_status_retry', array('id' => 'int'));
    agumail_admin_register_action('status_retry_all', 'agumail_status_retry_all');
    agumail_admin_register_action('status_discard', 'agumail_status_discard', array('id' => 'int', 'version' => 'int'));
    agumail_admin_register_action('status_discard_all', 'agumail_status_discard_all');
}

/** AguMail took a batch: when, where, how many. Written from the drain and the bulk sync, never a shopper's request. */
function agumail_status_record_sent($endpoint, $count) {
    update_option('agumail_last_sent', array('at' => agumail_time(), 'endpoint' => (string) $endpoint, 'count' => (int) $count), false);
}

// ─────────────────────────────────────────────────────────────────────────────
// Actions.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_status_request_id(array $request) {
    return isset($request['id']) ? absint($request['id']) : 0;
}

function agumail_status_retry(array $request) {
    $id = agumail_status_request_id($request);
    if (!$id || !agumail_outbox_retry($id)) {
        return new WP_Error('agumail_status_missing', __('That item is no longer in the queue.', 'agumail'));
    }
    return __('The item goes again with the next send.', 'agumail');
}

function agumail_status_retry_all() {
    $count = agumail_outbox_retry_failed();
    /* translators: number of items */
    return sprintf(_n('%s item goes again with the next send.', '%s items go again with the next send.', $count, 'agumail'), number_format_i18n($count));
}

/**
 * Discards the failed item as the page showed it: a row that changed since (a
 * customer answered again, and it went back in line) is kept.
 */
function agumail_status_discard(array $request) {
    $id = agumail_status_request_id($request);
    $version = isset($request['version']) ? absint($request['version']) : 0;
    if (!$id || !$version || !agumail_outbox_discard($id, $version)) {
        return new WP_Error('agumail_status_missing', __('That item changed or is no longer in the failed list, so it was kept. Reload the page to see it now.', 'agumail'));
    }
    return __('The item was discarded. AguMail will not receive it.', 'agumail');
}

function agumail_status_discard_all() {
    $count = agumail_outbox_discard_failed();
    /* translators: number of items */
    return sprintf(_n('%s item was discarded.', '%s items were discarded.', $count, 'agumail'), number_format_i18n($count));
}

// ─────────────────────────────────────────────────────────────────────────────
// The section.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_status_type_labels() {
    return array(
        'contact'       => __('Contacts', 'agumail'),
        'user'          => __('Customer accounts', 'agumail'),
        'product'       => __('Products', 'agumail'),
        'order'         => __('Orders', 'agumail'),
        'cart'          => __('Carts', 'agumail'),
        'signup'        => __('Form signups', 'agumail'),
        'order_contact' => __('Checkout subscribers', 'agumail'),
    );
}

/** A UTC DATETIME from the tables, as a timestamp. */
function agumail_status_time($datetime) {
    $time = $datetime ? strtotime($datetime . ' UTC') : false;
    return $time ? $time : 0;
}

function agumail_status_ago($timestamp) {
    /* translators: a length of time, such as 5 mins */
    return sprintf(__('%s ago', 'agumail'), human_time_diff((int) $timestamp, agumail_time()));
}

function agumail_status_render_section() {
    $stats = agumail_outbox_stats();
    echo '<table class="form-table agumail-status" role="presentation"><tbody>';
    agumail_status_row(__('Connection', 'agumail'), agumail_status_connection_text());
    agumail_status_row(__('Last successful sync', 'agumail'), agumail_status_last_sent_text());
    echo '<tr><th scope="row">' . esc_html__('Queue', 'agumail') . '</th><td>';
    agumail_status_render_queue($stats);
    echo '</td></tr>';
    $until = agumail_backoff_until();
    if ($until > agumail_time()) {
        agumail_status_row(__('Sending', 'agumail'), sprintf(
            /* translators: the time sending carries on */
            __('Sending is paused until %s. AguMail asked the plugin to wait, or refused the last request; nothing is lost.', 'agumail'),
            wp_date(get_option('time_format', 'H:i'), $until)
        ));
    }
    if (agumail_store_sync_held()) {
        agumail_status_row(__('Store sync', 'agumail'), sprintf(
            /* translators: the date AguMail stopped taking store data */
            __('Paused since %s: your AguMail plan does not include store sync. Orders and products wait here; signups and checkout subscribers carry on.', 'agumail'),
            wp_date(get_option('date_format', 'Y-m-d'), agumail_store_sync_held_since())
        ));
    }
    if (agumail_store_sync_on()) {
        echo '<tr><th scope="row">' . esc_html__('Store data sync', 'agumail') . '</th><td>' . esc_html(agumail_bulk_summary(agumail_bulk_state()))
            . ' <a href="#agumail-section-sync">' . esc_html__('Store data', 'agumail') . '</a></td></tr>';
    }
    agumail_status_render_log_row();
    echo '</tbody></table>';
    agumail_status_render_failed((int) $stats['failed']);
}

/** WooCommerce's log, or without it the scheduled jobs and the WordPress debug log. */
function agumail_status_render_log_row() {
    if (function_exists('wc_get_logger') && agumail_woocommerce_active()) {
        $logs = add_query_arg(array('page' => 'wc-status', 'tab' => 'logs', 'source' => 'agumail'), admin_url('admin.php'));
        echo '<tr><th scope="row">' . esc_html__('Log', 'agumail') . '</th><td><a href="' . esc_url($logs) . '">'
            . esc_html__('View the AguMail log', 'agumail') . '</a><p class="description">'
            . esc_html__('WooCommerce > Status > Logs, source agumail. Shoppers\' addresses are masked there.', 'agumail') . '</p></td></tr>';
        return;
    }
    $jobs = add_query_arg(array('page' => 'action-scheduler', 's' => 'agumail'), admin_url('tools.php'));
    echo '<tr><th scope="row">' . esc_html__('Log', 'agumail') . '</th><td><a href="' . esc_url($jobs) . '">'
        . esc_html__('View AguMail\'s background jobs', 'agumail') . '</a><p class="description">'
        . esc_html__('Tools > Scheduled Actions. Warnings and errors also go to the WordPress debug log while WP_DEBUG_LOG is on, with addresses masked.', 'agumail') . '</p></td></tr>';
}

function agumail_status_row($label, $text) {
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($text) . '</td></tr>';
}

function agumail_status_connection_text() {
    if (!agumail_has_api_key()) {
        return __('Not connected.', 'agumail');
    }
    $connection = agumail_connection();
    $account = $connection['shopName'] !== '' ? $connection['shopName'] : __('your AguMail account', 'agumail');
    if ($connection['status'] === 'disconnected') {
        /* translators: the AguMail account */
        return sprintf(__('Disconnected in AguMail (%s). Sending is paused until you reconnect.', 'agumail'), $account);
    }
    if ($connection['status'] === 'error') {
        /* translators: AguMail's reason */
        return sprintf(__('AguMail refused this store: %s', 'agumail'), $connection['error']);
    }
    $store = isset($connection['storeUrl']) && is_string($connection['storeUrl']) ? $connection['storeUrl'] : '';
    if ($store !== '' && agumail_connection_platform() === 'wordpress') {
        /* translators: 1: the AguMail account, 2: the site's address */
        return sprintf(__('Connected to %1$s (site %2$s).', 'agumail'), $account, $store);
    }
    return $store !== ''
        /* translators: 1: the AguMail account, 2: the store's address */
        ? sprintf(__('Connected to %1$s (store %2$s).', 'agumail'), $account, $store)
        /* translators: the AguMail account */
        : sprintf(__('Connected to %s.', 'agumail'), $account);
}

function agumail_status_last_sent_text() {
    $last = get_option('agumail_last_sent', array());
    if (!is_array($last) || empty($last['at'])) {
        return __('Nothing has been sent yet.', 'agumail');
    }
    $kinds = array(
        '/api/v1/contacts' => __('contacts', 'agumail'),
        '/api/v1/products' => __('products', 'agumail'),
        '/api/v1/orders'   => __('orders', 'agumail'),
        '/api/v1/carts'    => __('carts', 'agumail'),
        '/api/v1/signups'  => __('form signups', 'agumail'),
    );
    $kind = isset($kinds[$last['endpoint']]) ? $kinds[$last['endpoint']] : __('records', 'agumail');
    /* translators: 1: how long ago, 2: a number, 3: what was sent, such as contacts */
    return sprintf(__('%1$s (%2$s %3$s).', 'agumail'), agumail_status_ago($last['at']), number_format_i18n((int) $last['count']), $kind);
}

function agumail_status_render_queue(array $stats) {
    if (empty($stats['by_type'])) {
        echo esc_html__('Nothing is waiting.', 'agumail');
        return;
    }
    $labels = agumail_status_type_labels();
    echo '<table class="widefat striped agumail-status-queue"><thead><tr><th scope="col">' . esc_html__('Type', 'agumail') . '</th> <th scope="col">'
        . esc_html__('Waiting', 'agumail') . '</th> <th scope="col">' . esc_html__('Failed', 'agumail') . '</th></tr></thead><tbody>';
    foreach ($labels + array_fill_keys(array_keys($stats['by_type']), '') as $type => $label) {
        if (!isset($stats['by_type'][$type])) {
            continue;
        }
        echo '<tr><td>' . esc_html($label !== '' ? $label : $type) . '</td> <td>' . esc_html(number_format_i18n($stats['by_type'][$type]['pending']))
            . '</td> <td>' . esc_html(number_format_i18n($stats['by_type'][$type]['failed'])) . '</td></tr>';
    }
    echo '</tbody></table>';
    $oldest = agumail_status_time($stats['oldest_pending_at']);
    if ($oldest) {
        /* translators: how long ago, such as 5 mins ago */
        echo '<p class="description">' . esc_html(sprintf(__('Oldest waiting item: queued %s.', 'agumail'), agumail_status_ago($oldest))) . '</p>';
    }
}

/** What a queued item is, for the merchant: the order or product number, the address, the cart, who signed up on which form. */
function agumail_status_item_label(array $row) {
    $key = (string) $row['object_key'];
    switch ($row['type']) {
        case 'order':
            /* translators: the order number */
            return sprintf(__('Order #%s', 'agumail'), $key);
        case 'product':
            /* translators: the product id */
            return sprintf(__('Product #%s', 'agumail'), $key);
        case 'user':
            $user = get_userdata((int) $key);
            /* translators: the account's email address, or its id */
            return $user ? sprintf(__('Customer account %s', 'agumail'), $user->user_email) : sprintf(__('Customer account #%s', 'agumail'), $key);
        case 'cart':
            /* translators: the cart id */
            return sprintf(__('Cart %s', 'agumail'), $key);
        case 'order_contact':
            /* translators: the order number */
            return sprintf(__('Checkout subscriber from order #%s', 'agumail'), $key);
        case 'contact':
            return $key;
        case 'signup':
            $record = is_array($row['payload']) ? $row['payload'] : array();
            if (!empty($record['email']) && !empty($record['form']['name'])) {
                /* translators: 1: the email address that signed up, 2: the name of the form they used */
                return sprintf(__('%1$s from %2$s', 'agumail'), $record['email'], $record['form']['name']);
            }
            /* translators: the queued signup's key */
            return sprintf(__('Form signup %s', 'agumail'), $key);
    }
    return $row['type'] . ' ' . $key;
}

function agumail_status_render_failed($total) {
    if ($total <= 0) {
        return;
    }
    echo '<h3>' . esc_html__('Failed items', 'agumail') . '</h3>';
    /* translators: number of failed items */
    echo '<p>' . esc_html(sprintf(_n(
        'AguMail refused %s item, or it failed too many times. Retry sends it again with the next send; Discard drops it.',
        'AguMail refused %s items, or they failed too many times. Retry sends them again with the next send; Discard drops them.',
        $total, 'agumail'), number_format_i18n($total))) . '</p>';
    echo '<div class="agumail-actions">';
    agumail_status_button('status_retry_all', __('Retry all', 'agumail'), '');
    agumail_status_button('status_discard_all', __('Discard all', 'agumail'), __('Discard every failed item? AguMail will not receive them.', 'agumail'));
    echo '</div>';
    echo '<table class="widefat striped agumail-status-failed"><thead><tr>'
        . '<th scope="col">' . esc_html__('Item', 'agumail') . '</th> <th scope="col">' . esc_html__('Error', 'agumail') . '</th> '
        . '<th scope="col">' . esc_html__('Tries', 'agumail') . '</th> <th scope="col">' . esc_html__('Last tried', 'agumail') . '</th> '
        . '<th scope="col">' . esc_html__('Actions', 'agumail') . '</th></tr></thead><tbody>';
    foreach (agumail_outbox_failed(AGUMAIL_STATUS_FAILED_SHOWN) as $row) {
        $tried = agumail_status_time($row['updated_at']);
        echo '<tr><td>' . esc_html(agumail_status_item_label($row)) . '</td> <td>' . esc_html((string) $row['last_error']) . '</td> <td>'
            . esc_html(number_format_i18n((int) $row['attempts'])) . '</td> <td>' . esc_html($tried ? agumail_status_ago($tried) : '') . '</td> <td>'
            . '<a href="' . esc_url(agumail_admin_action_url('status_retry', array('id' => $row['id']))) . '">' . esc_html__('Retry', 'agumail') . '</a> | '
            . '<a href="' . esc_url(agumail_admin_action_url('status_discard', array('id' => $row['id'], 'version' => $row['version']))) . '">' . esc_html__('Discard', 'agumail') . '</a>'
            . '</td></tr>';
    }
    echo '</tbody></table>';
    if ($total > AGUMAIL_STATUS_FAILED_SHOWN) {
        /* translators: 1: items shown, 2: all failed items */
        echo '<p class="description">' . esc_html(sprintf(__('Showing the %1$s most recent of %2$s.', 'agumail'),
            number_format_i18n(AGUMAIL_STATUS_FAILED_SHOWN), number_format_i18n($total))) . '</p>';
    }
}

function agumail_status_button($action, $label, $confirm) {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="agumail-inline-form"'
        . ($confirm !== '' ? ' onsubmit="return window.confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>';
    agumail_admin_action_fields($action);
    echo '<button type="submit" class="button" name="agumail_do" value="' . esc_attr($action) . '">' . esc_html($label) . '</button></form>';
}
