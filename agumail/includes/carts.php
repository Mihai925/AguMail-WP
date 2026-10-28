<?php
// Carts: keeping the shopper's cart row current, checkout email capture, the
// restore link and cart housekeeping.
//
// A cart is worth recording once somebody can be reminded of it: a signed-in
// customer, or a guest whose email the checkout script captured when they left
// the email field. The row lives in the plugin's carts table; the drain sends
// it to AguMail later, so a shopper's request never calls out.
//
//   Capture     The checkout, cart and My Account pages load assets/js/capture.js,
//               which posts an email to ?wc-ajax=agumail_capture when the shopper
//               leaves an email field. The email goes into the WooCommerce
//               session and, when the cart has items, into the cart row: one
//               write. Only the email: the marketing checkbox counts on a
//               submitted order alone, so a captured contact claims no consent.
//   Cart hooks  Adding, removing, changing quantities, coupons and the saved cart
//               merged at login rewrite the row, once per request, at shutdown. A
//               cart the shopper empties is marked emptied and its id retired. A
//               cart WooCommerce empties because it was ordered is closed by its
//               order: a paid order is sent and closes it; an order not paid yet
//               (bank transfer, cheque) is not sent, so the row is closed here.
//   Restore     A recovery email links to the checkout page with
//               ?agumail_cart=<id>&agumail_token=<t>, which rebuilds that cart in
//               any browser and goes on to checkout.
//   Drain       Changed rows move into the outbox and go to /api/v1/carts before
//               orders, so a purchase sent in the same run closes them.
//
// The session keys are named by the constants below. The orders feature stamps
// an order with agumail_cart_id_for_current_session().

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_SESSION_CART_ID', 'agumail_cart_id');
define('AGUMAIL_SESSION_EMAIL', 'agumail_email');
define('AGUMAIL_SESSION_CAPTURES', 'agumail_captures');
define('AGUMAIL_SESSION_EMAIL_BY', 'agumail_email_captured_by');
define('AGUMAIL_SESSION_RETIRED', 'agumail_cart_retired');
define('AGUMAIL_CAPTURE_LIMIT', 20);
define('AGUMAIL_CAPTURE_ADDRESS_LIMIT', 60);
define('AGUMAIL_CAPTURE_ADDRESS_CARTS', 30);
define('AGUMAIL_CAPTURE_ACTION', 'agumail_capture');
define('AGUMAIL_CART_ITEM_DATA_MAX', 2000);
define('AGUMAIL_CARTS_FINISHED_KEPT', 14 * DAY_IN_SECONDS);
define('AGUMAIL_CARTS_KEPT', 90 * DAY_IN_SECONDS);

// ─────────────────────────────────────────────────────────────────────────────
// Whose cart, and whether to record it at all.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Whether carts are recorded in this request: the tables exist, the store
 * sends its store data (agumail_store_sync_on(): connected as a store, on a
 * plan with store sync), WooCommerce has a cart and a session, and the shopper
 * is not a store manager trying out their own checkout.
 */
function agumail_carts_active() {
    if (!agumail_ready() || !agumail_store_sync_on() || !function_exists('WC')) {
        return false;
    }
    $wc = WC();
    if (!$wc || empty($wc->cart) || empty($wc->session)) {
        return false;
    }
    return !agumail_cart_staff();
}

/** Somebody who runs the store, whose own test carts are not recorded. */
function agumail_cart_staff() {
    return function_exists('agumail_is_staff') ? (bool) agumail_is_staff() : current_user_can(AGUMAIL_ADMIN_CAPABILITY);
}

/**
 * Whether this browser has a WooCommerce session that will be kept: a cookie,
 * or a signed-in customer. A session handler a plugin swapped in without the
 * check is taken to have one.
 */
function agumail_cart_session_started() {
    $session = WC()->session;
    return !method_exists($session, 'has_session') || $session->has_session();
}

/**
 * Who the cart is recovered by, or '' when nobody. A guest: the address
 * captured in their session. A signed-in customer: their account email, as it
 * is now, unless they typed another address at checkout while signed in.
 */
function agumail_cart_email() {
    $session = WC()->session;
    $captured = agumail_normalize_email($session->get(AGUMAIL_SESSION_EMAIL, ''));
    $user_id = get_current_user_id();
    if (!$user_id) {
        return $captured;
    }
    if ($captured !== '' && (int) $session->get(AGUMAIL_SESSION_EMAIL_BY, 0) === $user_id) {
        return $captured;
    }
    return agumail_normalize_email(wp_get_current_user()->user_email);
}

// An address typed as a guest is not the account's: once the shopper signs in
// to an account with another address, it is forgotten. WooCommerce moves a
// guest's session to the customer on their first page signed in; the login
// form's own request is caught too.
add_action('woocommerce_guest_session_to_user_id', 'agumail_cart_guest_session_became_customer', 10, 2);
add_action('wp_login', 'agumail_cart_signed_in', 10, 2);

function agumail_cart_guest_session_became_customer($guest_session_id, $user_id) {
    agumail_cart_forget_guest_email(get_userdata((int) $user_id));
}

function agumail_cart_signed_in($login, $user = null) {
    agumail_cart_forget_guest_email($user);
}

function agumail_cart_forget_guest_email($user) {
    if (!is_object($user) || empty($user->ID) || !function_exists('WC') || !WC() || empty(WC()->session)) {
        return;
    }
    $session = WC()->session;
    $captured = agumail_normalize_email($session->get(AGUMAIL_SESSION_EMAIL, ''));
    if ($captured === '' || (int) $session->get(AGUMAIL_SESSION_EMAIL_BY, 0) === (int) $user->ID) {
        return;
    }
    if ($captured !== agumail_normalize_email($user->user_email)) {
        $session->set(AGUMAIL_SESSION_EMAIL, null);
        $session->set(AGUMAIL_SESSION_EMAIL_BY, null);
    }
}

/**
 * Whose rows these are, as the carts table's session_key: a signed-in
 * customer's user id, so every device they use shares one cart (WooCommerce
 * keys a signed-in customer's session by user id too), else the guest's
 * session.
 */
function agumail_cart_owner() {
    $user_id = get_current_user_id();
    return $user_id ? (string) $user_id : (string) WC()->session->get_customer_id();
}

/**
 * The newest open row of this session's owner, or null. A signed-in
 * customer's must also carry their user id. The cart this session just
 * checked out is never it: it stays open until its order is sent.
 */
function agumail_cart_owner_open_row() {
    $owner = agumail_cart_owner();
    if ($owner === '' || !agumail_ready()) {
        return null;
    }
    $cart = agumail_cart_find_by_session($owner, array('open'));
    if (!$cart || $cart['id'] === (string) WC()->session->get(AGUMAIL_SESSION_RETIRED, '')) {
        return null;
    }
    $user_id = get_current_user_id();
    return !$user_id || $cart['user_id'] === $user_id ? $cart : null;
}

/**
 * The cart row an order placed in this session belongs to: the id the session
 * keeps, else the open row of its owner. The second covers a checkout that
 * loaded the session before a capture running beside it saved the id. ''
 * when none.
 */
function agumail_cart_id_for_current_session() {
    if (!function_exists('WC') || !WC() || empty(WC()->session)) {
        return '';
    }
    $id = WC()->session->get(AGUMAIL_SESSION_CART_ID, '');
    if (is_string($id) && $id !== '') {
        return $id;
    }
    $cart = agumail_cart_owner_open_row();
    return $cart ? (string) $cart['id'] : '';
}

/**
 * State for this request only: whether the cart changed, whether the plugin is
 * rebuilding a cart itself, the order placed (if any), whether the checkout
 * emptied the cart, and whether WooCommerce is merging a saved cart into the
 * session. Cleared at the end of the request.
 */
function &agumail_cart_request_state() {
    static $state = null;
    if ($state === null) {
        $state = agumail_cart_fresh_request_state();
    }
    return $state;
}

function agumail_cart_fresh_request_state() {
    return array('changed' => false, 'restoring' => false, 'ordered' => false, 'order_id' => 0, 'checkout_emptied' => false, 'merging' => false);
}

function agumail_cart_reset_request_state() {
    $state = &agumail_cart_request_state();
    $state = agumail_cart_fresh_request_state();
}

// ─────────────────────────────────────────────────────────────────────────────
// Writing the row.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Writes the shopper's cart row from WooCommerce's cart, in one upsert: the
 * row the session keeps (unless already ordered); else the owner's open row,
 * so one shopper never has two carts to be reminded of (a signed-in
 * customer's from another device, or a guest's written by a request that ran
 * beside this one); else a new row under a new id, which records a hash of the
 * address it came from. Does nothing for an empty cart or when nobody can be
 * reminded of it. Returns whether it wrote.
 */
function agumail_cart_save_current() {
    $cart = WC()->cart;
    if ($cart->is_empty()) {
        return false;
    }
    $email = agumail_cart_email();
    if ($email === '') {
        return false;
    }
    $session = WC()->session;
    $id = (string) $session->get(AGUMAIL_SESSION_CART_ID, '');
    if ($id !== '') {
        $row = agumail_cart_get($id);
        if ($row && $row['status'] === 'ordered') {
            $id = '';
        }
    }
    if ($id === '') {
        $shared = agumail_cart_owner_open_row();
        if ($shared) {
            $id = $shared['id'];
            $session->set(AGUMAIL_SESSION_CART_ID, $id);
        }
    }
    $fields = array();
    if ($id === '') {
        $id = 'wc-' . wp_generate_uuid4();
        $session->set(AGUMAIL_SESSION_CART_ID, $id);
        $fields['ip_hash'] = agumail_capture_address_hash();
    }
    $lines = array();
    $value = 0.0;
    foreach ($cart->get_cart() as $item) {
        $line = agumail_cart_line($item);
        $value += $line['line_total'];
        $lines[] = $line;
    }
    // WooCommerce's total once it has worked it out (it does on every cart
    // change); the lines' own value until then.
    $total = agumail_money_total($cart->get_total('edit'));
    $user_id = get_current_user_id();
    return agumail_cart_save($id, $fields + array(
        'session_key' => agumail_cart_owner(),
        'user_id'     => $user_id ? $user_id : null,
        'email'       => $email,
        'lines'       => $lines,
        'total'       => $total > 0 ? $total : $value,
        'currency'    => get_woocommerce_currency(),
        'status'      => 'open',
    ));
}

/**
 * One line of the row: product_id, variation_id, variation, qty and line_total
 * (in the cart's currency), plus the data a plugin added to the item
 * (item_data) and, for an item that belongs to another line such as a bundled
 * product, child => true.
 */
function agumail_cart_line(array $item) {
    $qty = agumail_quantity(isset($item['quantity']) ? $item['quantity'] : 0);
    $line_total = isset($item['line_total']) ? agumail_money($item['line_total']) : 0.0;
    if ($line_total <= 0 && isset($item['data']) && is_object($item['data'])) {
        $line_total = agumail_money((float) $item['data']->get_price() * $qty);
    }
    $line = array(
        'product_id'   => isset($item['product_id']) ? (int) $item['product_id'] : 0,
        'variation_id' => isset($item['variation_id']) ? (int) $item['variation_id'] : 0,
        'variation'    => isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : array(),
        'qty'          => $qty,
        'line_total'   => $line_total,
    );
    if (agumail_cart_item_is_child($item)) {
        $line['child'] = true;
        return $line;
    }
    $data = agumail_cart_item_data($item);
    if ($data) {
        $line['item_data'] = $data;
    }
    return $line;
}

/** An item another line put in the cart: a bundled item (Product Bundles), a component (Composite Products), a Mix and Match child. */
function agumail_cart_item_is_child(array $item) {
    foreach (array('bundled_by', 'composite_parent', 'mnm_container') as $key) {
        if (!empty($item[$key])) {
            return true;
        }
    }
    return false;
}

/**
 * The data a plugin added to a cart item (options, add-ons, a bundle's
 * configuration), so a restore can add the item as it was. Only plain values
 * that survive JSON are kept; WooCommerce's own keys and the keys tying lines
 * together are left out, because a parent adds its children again itself.
 */
function agumail_cart_item_data(array $item) {
    $skipped = array_flip(array(
        'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data',
        'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax',
        'bundled_by', 'bundled_items', 'composite_parent', 'composite_children', 'mnm_container', 'mnm_contents',
    ));
    $data = array();
    foreach ($item as $key => $value) {
        if (!is_string($key) || isset($skipped[$key]) || !agumail_cart_plain_value($value)) {
            continue;
        }
        $data[$key] = $value;
    }
    if (!$data) {
        return array();
    }
    $json = wp_json_encode($data);
    return $json !== false && strlen($json) <= AGUMAIL_CART_ITEM_DATA_MAX ? $data : array();
}

/** A string, number, boolean or null, or a list or map of them. */
function agumail_cart_plain_value($value) {
    if (is_array($value)) {
        foreach ($value as $inner) {
            if (!agumail_cart_plain_value($inner)) {
                return false;
            }
        }
        return true;
    }
    if (is_float($value)) {
        return is_finite($value);
    }
    return $value === null || is_scalar($value);
}

/** The shopper emptied their cart: its row is marked emptied (one write) and its id retired, so the next cart is a new one. */
function agumail_cart_retire_emptied() {
    $session = WC()->session;
    $id = (string) $session->get(AGUMAIL_SESSION_CART_ID, '');
    if ($id === '') {
        return false;
    }
    $session->set(AGUMAIL_SESSION_CART_ID, null);
    return agumail_cart_mark_emptied($id);
}

function agumail_cart_mark_emptied($id) {
    $row = agumail_cart_get($id);
    if (!$row || $row['status'] !== 'open') {
        return false;
    }
    // The lines stay, so a recovery email already sent for this cart can still restore it.
    return agumail_cart_update($id, array('status' => 'emptied'));
}

/** The cart was bought: its row is ordered (one write), and goes to AguMail as completed. */
function agumail_cart_mark_ordered($id, $order_id) {
    $row = agumail_cart_get($id);
    if (!$row || $row['status'] === 'ordered') {
        return false;
    }
    $fields = array('status' => 'ordered');
    if ($order_id > 0) {
        $fields['order_id'] = (int) $order_id;
    }
    return agumail_cart_update($id, $fields);
}

// ─────────────────────────────────────────────────────────────────────────────
// Cart hooks. Each only notes that the cart changed; the row is written once,
// at the end of the request, from the cart as it then stands. WooCommerce has
// worked out its totals by then, and a request that adds five items writes
// once, not five times.
// ─────────────────────────────────────────────────────────────────────────────

foreach (array(
    'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored',
    'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_item_set_quantity',
    'woocommerce_applied_coupon', 'woocommerce_removed_coupon',
) as $agumail_hook) {
    add_action($agumail_hook, 'agumail_cart_changed', 20, 0);
}
unset($agumail_hook);

function agumail_cart_changed() {
    $state = &agumail_cart_request_state();
    if (!$state['restoring']) {
        $state['changed'] = true;
    }
}

// A signed-in customer's saved cart joins the session on the first page after
// they log in, or in a session that had no cart yet. WooCommerce reads the
// flag, merges, then clears the flag, so it is read before the merge.
add_action('woocommerce_load_cart_from_session', 'agumail_cart_before_session_load', 10, 0);
add_action('woocommerce_cart_loaded_from_session', 'agumail_cart_after_session_load', 10, 0);

function agumail_cart_before_session_load() {
    if (!is_user_logged_in() || !function_exists('WC') || empty(WC()->session)) {
        return;
    }
    $state = &agumail_cart_request_state();
    $state['merging'] = WC()->session->get('cart', null) === null
        || (bool) get_user_meta(get_current_user_id(), '_woocommerce_load_saved_cart_after_login', true);
}

function agumail_cart_after_session_load() {
    $state = &agumail_cart_request_state();
    if ($state['merging']) {
        $state['merging'] = false;
        agumail_cart_changed();
    }
}

add_action('woocommerce_checkout_order_processed', 'agumail_cart_order_placed', 1, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'agumail_cart_order_placed', 1, 1);

/** The classic checkout passes the order id, the Store API the order. */
function agumail_cart_order_placed($order) {
    $state = &agumail_cart_request_state();
    $state['ordered'] = true;
    $state['order_id'] = is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : absint($order);
}

// Before WooCommerce's own handler (priority 10) clears order_awaiting_payment from the session.
add_action('woocommerce_cart_emptied', 'agumail_cart_emptied', 5, 0);

/**
 * The cart was emptied. When the shopper did it, the row is marked emptied at
 * the end of the request. When WooCommerce did it because the cart was
 * ordered, the id is retired so the shopper's next cart is a new one, and the
 * row is left to its order: an order counted as paid (see
 * agumail_cart_order_counts_as_paid) is sent, carries the cart's id and closes
 * it. An order not paid yet is not sent until it is, so the row is closed now,
 * in the request's one write, and AguMail hears it was bought.
 */
function agumail_cart_emptied() {
    $state = &agumail_cart_request_state();
    if ($state['restoring'] || !agumail_carts_active()) {
        return;
    }
    $order = agumail_cart_emptying_order();
    if ($order === false) {
        $state['changed'] = true;
        return;
    }
    $state['checkout_emptied'] = true;
    $state['changed'] = false;
    $session = WC()->session;
    $id = (string) $session->get(AGUMAIL_SESSION_CART_ID, '');
    $session->set(AGUMAIL_SESSION_CART_ID, null);
    if ($id !== '') {
        $session->set(AGUMAIL_SESSION_RETIRED, $id);
    }
    if ($id !== '' && (!is_object($order) || !agumail_cart_order_counts_as_paid($order))) {
        agumail_cart_mark_ordered($id, is_object($order) ? $order->get_id() : 0);
    }
}

/**
 * Whether the orders feature sends this order, and so closes its cart itself:
 * its own rule when it has one (a paid date, or a paid status such as cash on
 * delivery in processing), else a paid date.
 */
function agumail_cart_order_counts_as_paid($order) {
    if (function_exists('agumail_order_counts_as_paid')) {
        return (bool) agumail_order_counts_as_paid($order);
    }
    return (bool) $order->get_date_paid();
}

/**
 * Why WooCommerce is emptying the cart: false when it is not for an order;
 * else that order, or true when the order cannot be found. It is for an order
 * when the classic or block checkout placed one in this request (a gateway
 * such as bank transfer or cash on delivery empties the cart while taking the
 * order), on the thank-you page (a gateway that sent the shopper away empties
 * it on their return), and when the order the session awaits payment for went
 * through, which is WooCommerce's own test before it empties the cart.
 */
function agumail_cart_emptying_order() {
    $state = agumail_cart_request_state();
    if ($state['ordered']) {
        return agumail_cart_order_or_true($state['order_id']);
    }
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        global $wp;
        $id = is_object($wp) && isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
        return agumail_cart_order_or_true($id);
    }
    $awaiting = (int) WC()->session->get('order_awaiting_payment', 0);
    if ($awaiting > 0 && function_exists('wc_get_order')) {
        $order = wc_get_order($awaiting);
        if ($order && !$order->has_status(array('failed', 'pending', 'cancelled'))) {
            return $order;
        }
    }
    return false;
}

function agumail_cart_order_or_true($order_id) {
    $order = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
    return $order ? $order : true;
}

// WordPress fires shutdown even after exit, so this runs after a redirect or a
// JSON answer too; it comes before WooCommerce saves the session (priority 20),
// so a new cart id is kept.
add_action('shutdown', 'agumail_cart_flush', 5, 0);

/** Writes the request's cart changes, once. */
function agumail_cart_flush() {
    $state = agumail_cart_request_state();
    agumail_cart_reset_request_state();
    if (!$state['changed'] || $state['checkout_emptied'] || !agumail_carts_active() || !agumail_cart_session_started()) {
        return;
    }
    if (WC()->cart->is_empty()) {
        agumail_cart_retire_emptied();
        return;
    }
    agumail_cart_save_current();
}

// ─────────────────────────────────────────────────────────────────────────────
// Capture: POST ?wc-ajax=agumail_capture {email, context}. No nonce, because the
// checkout page may be served from a page cache. It answers {ok: true} whatever
// happened, and adds the identify signature and the address it signed
// ({sig, email}) when it kept the email and website tracking is on.
// ─────────────────────────────────────────────────────────────────────────────

add_action('wc_ajax_' . AGUMAIL_CAPTURE_ACTION, 'agumail_capture_request');

function agumail_capture_request() {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- see above; the call only records the shopper's own email.
    $email = isset($_POST['email']) && is_string($_POST['email']) ? agumail_normalize_email(sanitize_email(wp_unslash($_POST['email']))) : '';
    // phpcs:enable
    $answer = array('ok' => true);
    if ($email !== '' && agumail_capture_email($email)) {
        // The email changed, so the cart row now names the new one. This is the call's one write.
        if (!WC()->cart->is_empty()) {
            agumail_cart_save_current();
        }
        $state = &agumail_cart_request_state();
        $state['changed'] = false;
    }
    // The signature is only for the site script, so none is handed out while website tracking is off.
    if ($email !== '' && agumail_tracking_on() && agumail_capture_accepted($email)) {
        $sig = function_exists('agumail_identify_signature') ? (string) agumail_identify_signature($email) : '';
        if ($sig !== '') {
            // The address exactly as signed, which sanitising may have changed from what was typed.
            $answer['sig'] = $sig;
            $answer['email'] = $email;
        }
    }
    wp_send_json($answer);
}

/**
 * Keeps $email as the session's shopper. Returns true when it changed (and the
 * cart row should follow), false when it is the same email, or it was refused:
 * carts are off, the browser has no session yet (none is started: that would
 * stop page caching for this visitor), the session already changed its email
 * AGUMAIL_CAPTURE_LIMIT times, or its address has sent too many new ones.
 */
function agumail_capture_email($email) {
    if (!agumail_carts_active() || !agumail_cart_session_started()) {
        return false;
    }
    $session = WC()->session;
    if ($email === agumail_normalize_email($session->get(AGUMAIL_SESSION_EMAIL, ''))) {
        return false;
    }
    $count = (int) $session->get(AGUMAIL_SESSION_CAPTURES, 0);
    if ($count >= AGUMAIL_CAPTURE_LIMIT || !agumail_capture_address_allows()) {
        return false;
    }
    $session->set(AGUMAIL_SESSION_CAPTURES, $count + 1);
    $session->set(AGUMAIL_SESSION_EMAIL, $email);
    // Typed while signed in, and not the account's own address: the customer's
    // choice for this checkout. Anything else yields to the account email.
    $user_id = get_current_user_id();
    $own_choice = $user_id && $email !== agumail_normalize_email(wp_get_current_user()->user_email);
    $session->set(AGUMAIL_SESSION_EMAIL_BY, $own_choice ? $user_id : 0);
    return true;
}

/**
 * Whether the shopper's address may send another new email, for a script
 * opening fresh sessions to get around the per-session cap. Two limits, both
 * reads: with a persistent object cache, at most AGUMAIL_CAPTURE_ADDRESS_LIMIT
 * new emails an hour, counted there; and on every store, no new email from an
 * address that started AGUMAIL_CAPTURE_ADDRESS_CARTS carts in the last hour,
 * one indexed count on the carts table.
 */
function agumail_capture_address_allows() {
    $address = agumail_capture_address();
    if ($address === '') {
        return true;
    }
    if (wp_using_ext_object_cache()) {
        $key = 'capture_' . md5($address) . '_' . (int) floor(agumail_time() / HOUR_IN_SECONDS);
        wp_cache_add($key, 0, 'agumail', HOUR_IN_SECONDS);
        $count = wp_cache_incr($key, 1, 'agumail');
        if ($count !== false && $count > AGUMAIL_CAPTURE_ADDRESS_LIMIT) {
            return false;
        }
    }
    return agumail_carts_count_by_ip(agumail_capture_address_hash(), agumail_time() - HOUR_IN_SECONDS) < AGUMAIL_CAPTURE_ADDRESS_CARTS;
}

/** The shopper's IP address, as WooCommerce reads it, or ''. */
function agumail_capture_address() {
    if (class_exists('WC_Geolocation') && method_exists('WC_Geolocation', 'get_ip_address')) {
        return (string) WC_Geolocation::get_ip_address();
    }
    return isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

/** A keyed hash of the shopper's address, so the carts table never holds the address itself. '' without one. */
function agumail_capture_address_hash() {
    $address = agumail_capture_address();
    return $address === '' ? '' : hash_hmac('sha256', $address, wp_salt());
}

/** Whether $email is the session's shopper now, which is when the page may identify them. */
function agumail_capture_accepted($email) {
    return agumail_carts_active()
        && agumail_cart_session_started()
        && $email === agumail_normalize_email(WC()->session->get(AGUMAIL_SESSION_EMAIL, ''));
}

// The checkout's own updates carry the email too, for a browser where the
// script never ran: the classic checkout posts its whole form each time it
// refreshes the order review, and the block checkout saves the customer
// through the Store API. Only the email is read.
add_action('woocommerce_checkout_update_order_review', 'agumail_capture_from_order_review', 10, 1);
add_action('woocommerce_store_api_cart_update_customer_from_request', 'agumail_capture_from_customer', 10, 1);

function agumail_capture_from_order_review($posted) {
    parse_str(is_string($posted) ? $posted : '', $fields);
    $email = isset($fields['billing_email']) && is_string($fields['billing_email']) ? agumail_normalize_email(sanitize_email($fields['billing_email'])) : '';
    agumail_capture_later($email);
}

function agumail_capture_from_customer($customer) {
    $email = is_object($customer) && method_exists($customer, 'get_billing_email') ? agumail_normalize_email($customer->get_billing_email()) : '';
    agumail_capture_later($email);
}

/** A changed email rewrites the cart row with the request's other changes, at shutdown. */
function agumail_capture_later($email) {
    if ($email !== '' && agumail_capture_email($email)) {
        agumail_cart_changed();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The capture script: deferred, on the pages where a shopper types their email.
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', 'agumail_enqueue_capture_script');

function agumail_enqueue_capture_script() {
    if (!agumail_carts_active() || !agumail_capture_page()) {
        return;
    }
    wp_enqueue_script('agumail-capture', plugins_url('assets/js/capture.js', AGUMAIL_FILE), array(), AGUMAIL_VERSION, true);
    wp_script_add_data('agumail-capture', 'strategy', 'defer');
    wp_add_inline_script('agumail-capture', 'window.agumailCapture = ' . wp_json_encode(array('endpoint' => agumail_capture_url())) . ';', 'before');
}

/** Checkout and cart, and My Account while signed out, where the login and register forms are. Not the thank-you page. */
function agumail_capture_page() {
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        return false;
    }
    if ((function_exists('is_checkout') && is_checkout()) || (function_exists('is_cart') && is_cart())) {
        return true;
    }
    return function_exists('is_account_page') && is_account_page() && !is_user_logged_in();
}

function agumail_capture_url() {
    if (class_exists('WC_AJAX') && method_exists('WC_AJAX', 'get_endpoint')) {
        return WC_AJAX::get_endpoint(AGUMAIL_CAPTURE_ACTION);
    }
    return add_query_arg('wc-ajax', AGUMAIL_CAPTURE_ACTION, home_url('/'));
}

// ─────────────────────────────────────────────────────────────────────────────
// The restore link: https://store/checkout/?agumail_cart=<id>&agumail_token=<t>.
// It points at the checkout page, which no page cache serves.
// ─────────────────────────────────────────────────────────────────────────────

/** The link a recovery email's button goes to. */
function agumail_cart_restore_url(array $cart) {
    $checkout = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
    return $checkout . (strpos($checkout, '?') === false ? '?' : '&')
        . 'agumail_cart=' . rawurlencode($cart['id']) . '&agumail_token=' . rawurlencode((string) $cart['token']);
}

// After WooCommerce has loaded the cart from the session (wp_loaded, priority 10).
add_action('wp_loaded', 'agumail_cart_maybe_restore', 30);

/**
 * Opens a restore link. With the right token the cart is rebuilt in this
 * browser (a fresh one gets a session first), the checkout knows the email,
 * and the shopper lands on checkout with the link's other params (agm_cid,
 * agm_uid, utm_*). An ordered cart, or one with nothing left to buy, shows the
 * cart page instead, and the shopper's own cart stays as it was. A wrong or
 * missing token changes nothing: the same page loads without the two params.
 * No call, and at most one write: the cart this browser held before, now
 * replaced, is marked emptied.
 */
function agumail_cart_maybe_restore() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the check.
    if (!isset($_GET['agumail_cart']) || is_admin() || wp_doing_ajax() || wp_doing_cron() || agumail_cart_is_rest_request()) {
        return;
    }
    $id = is_string($_GET['agumail_cart']) ? sanitize_text_field(wp_unslash($_GET['agumail_cart'])) : '';
    $token = isset($_GET['agumail_token']) && is_string($_GET['agumail_token']) ? sanitize_text_field(wp_unslash($_GET['agumail_token'])) : '';
    // phpcs:enable
    $cart = $id !== '' && agumail_ready() ? agumail_cart_get($id) : null;
    if (!agumail_cart_token_valid($cart, $token) || !function_exists('WC') || !WC()->cart || !WC()->session) {
        agumail_cart_restore_redirect(agumail_cart_restore_same_page());
    }
    if ($cart['status'] === 'ordered') {
        agumail_cart_restore_redirect(wc_get_cart_url());
    }
    $restorable = agumail_cart_restorable_lines($cart['lines']);
    if (!$restorable) {
        agumail_cart_restore_redirect(wc_get_cart_url());
    }

    $session = WC()->session;
    if (!agumail_cart_session_started() && method_exists($session, 'set_customer_session_cookie')) {
        $session->set_customer_session_cookie(true);
    }
    $previous = (string) $session->get(AGUMAIL_SESSION_CART_ID, '');
    if (!agumail_cart_rebuild($cart['id'], $restorable)) {
        agumail_cart_restore_redirect(wc_get_cart_url());
    }

    $session->set(AGUMAIL_SESSION_CART_ID, $cart['id']);
    if (!empty($cart['email'])) {
        // A guest continues under the address the link was sent to; a signed-in customer keeps their account email.
        $session->set(AGUMAIL_SESSION_EMAIL, $cart['email']);
        $session->set(AGUMAIL_SESSION_EMAIL_BY, 0);
        if (WC()->customer && (!is_user_logged_in() || WC()->customer->get_billing_email() === '')) {
            // WooCommerce saves the customer at shutdown.
            WC()->customer->set_billing_email($cart['email']);
        }
    }
    if ($previous !== '' && $previous !== $cart['id'] && agumail_carts_active()) {
        agumail_cart_mark_emptied($previous);
    }
    agumail_cart_restore_redirect(wc_get_checkout_url());
}

/**
 * A REST request, whose URL a restore link never is: WordPress defines
 * REST_REQUEST only later, while parsing the request, so the URL is read.
 */
function agumail_cart_is_rest_request() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (wp_is_json_request() || isset($_GET['rest_route'])) {
        return true;
    }
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $prefix = trim(function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json', '/');
    return $uri !== '' && $prefix !== '' && strpos($uri, '/' . $prefix . '/') !== false;
}

/**
 * Replaces the cart with $lines, with this plugin's own cart hooks quiet.
 * Returns how many lines went in. When none could, the shopper keeps the cart
 * and coupons they had.
 */
function agumail_cart_rebuild($cart_id, array $lines) {
    $cart = WC()->cart;
    $kept = $cart->get_cart_contents();
    $coupons = $cart->get_applied_coupons();
    $state = &agumail_cart_request_state();
    $state['restoring'] = true;
    $added = 0;
    try {
        $cart->empty_cart();
        foreach ($lines as $line) {
            try {
                if ($cart->add_to_cart($line['product_id'], $line['qty'], $line['variation_id'], $line['variation'], $line['item_data'])) {
                    $added++;
                }
            } catch (Throwable $e) {
                agumail_log('notice', sprintf('Restoring cart %s skipped product %d: %s', $cart_id, $line['product_id'], $e->getMessage()));
            }
        }
        if (!$added) {
            $cart->set_cart_contents($kept);
            $cart->set_applied_coupons($coupons);
            $cart->calculate_totals();
        }
    } finally {
        $state['restoring'] = false;
        $state['changed'] = false;
    }
    return $added;
}

/**
 * The lines that can still be bought, each for no more than can be: the
 * product exists, is published, purchasable and in stock; the quantity is held
 * to the product's purchase limit and to the stock left. An item another line
 * put in the cart (a bundled item) is left for that line to add again.
 */
function agumail_cart_restorable_lines(array $lines) {
    $restorable = array();
    foreach ($lines as $line) {
        if (!is_array($line) || !empty($line['child'])) {
            continue;
        }
        $product_id = isset($line['product_id']) ? (int) $line['product_id'] : 0;
        $variation_id = isset($line['variation_id']) ? (int) $line['variation_id'] : 0;
        $product = function_exists('wc_get_product') ? wc_get_product($variation_id ? $variation_id : $product_id) : null;
        if (!$product || $product->get_status() !== 'publish' || !$product->is_purchasable() || !$product->is_in_stock()) {
            continue;
        }
        $qty = max(1, agumail_quantity(isset($line['qty']) ? $line['qty'] : 1));
        $max = (int) $product->get_max_purchase_quantity();
        if ($max > 0) {
            $qty = min($qty, $max);
        }
        if (!$product->has_enough_stock($qty)) {
            $stock = (int) $product->get_stock_quantity();
            if ($stock < 1 || !$product->has_enough_stock($stock)) {
                continue;
            }
            $qty = min($qty, $stock);
        }
        $restorable[] = array(
            'product_id'   => $product_id,
            'variation_id' => $variation_id,
            'variation'    => isset($line['variation']) && is_array($line['variation']) ? $line['variation'] : array(),
            'qty'          => $qty,
            'item_data'    => isset($line['item_data']) && is_array($line['item_data']) ? $line['item_data'] : array(),
        );
    }
    return $restorable;
}

/** The page the link was opened on, as a path. */
function agumail_cart_restore_same_page() {
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $parts = explode('?', $uri, 2);
    $path = $parts[0];
    return $path !== '' && $path[0] === '/' && strpos($path, '//') !== 0 ? $path : home_url('/');
}

/**
 * A 302 to $url with the link's other params and without its own two, then
 * the end of the request. A URL on another host becomes the cart page, not
 * wp-admin, which is where wp_safe_redirect() would send a shopper.
 */
function agumail_cart_restore_redirect($url) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link's own params, passed on to the page it lands on.
    $kept = is_array($_GET) ? map_deep(wp_unslash($_GET), 'sanitize_text_field') : array();
    unset($kept['agumail_cart'], $kept['agumail_token']);
    if ($kept) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($kept, '', '&', PHP_QUERY_RFC3986);
    }
    nocache_headers();
    $cart_page = function () {
        return wc_get_cart_url();
    };
    add_filter('wp_safe_redirect_fallback', $cart_page);
    try {
        wp_safe_redirect($url, 302);
    } finally {
        remove_filter('wp_safe_redirect_fallback', $cart_page);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Sending. The drain moves changed rows into the outbox before each run. Carts
// go before orders (phase 25, orders are 30), so an order sent in the same run
// closes any cart AguMail holds for its buyer, including one that missed its
// order's cart id.
// ─────────────────────────────────────────────────────────────────────────────

add_action('agumail_drain_move_carts', 'agumail_carts_move_dirty', 10, 0);

function agumail_carts_move_dirty() {
    return agumail_carts_move_dirty_to_outbox(AGUMAIL_MAX_BATCH);
}

agumail_register_builder('cart', array(
    'endpoint'   => '/api/v1/carts',
    'collection' => 'carts',
    'build'      => 'agumail_build_cart',
    'contacts'   => 'agumail_cart_contacts',
    'phase'      => 25,
    'batch'      => 100,
    'store'      => true,
));

/**
 * The cart record: an open cart with its lines, an emptied one with none (so
 * AguMail drops a cart still open), an ordered one as completed. null drops a
 * row whose cart is gone or names nobody.
 */
function agumail_build_cart($key, $row) {
    $cart = agumail_cart_get($key);
    if (!$cart || agumail_normalize_email($cart['email']) === '') {
        return null;
    }
    $emptied = $cart['status'] === 'emptied';
    $record = array(
        'externalId' => agumail_clamp($cart['id'], AGUMAIL_MAX_CART_ID),
        'email'      => agumail_normalize_email($cart['email']),
        'total'      => $emptied ? 0.0 : agumail_money_total($cart['total']),
        'items'      => $emptied ? array() : agumail_cart_items($cart['lines']),
        'status'     => $cart['status'] === 'ordered' ? 'completed' : 'open',
        'source'     => 'woocommerce',
    );
    $currency = agumail_currency($cart['currency']);
    if ($currency) {
        $record['currency'] = $currency;
    }
    $link = agumail_clamp_url(agumail_cart_restore_url($cart));
    if ($link) {
        $record['checkoutUrl'] = $link;
    }
    $last_activity = agumail_iso_date($cart['updated_at']);
    if ($last_activity) {
        $record['lastActivityAt'] = $last_activity;
    }
    return $record;
}

/** An open cart's shopper, sent first with no consent at all: a captured email says nothing about marketing. */
function agumail_cart_contacts($key, $row) {
    $cart = agumail_cart_get($key);
    if (!$cart || $cart['status'] !== 'open') {
        return array();
    }
    $contact = agumail_contact_payload($cart['email']);
    return $contact ? array($contact) : array();
}

/**
 * The recorded lines as AguMail shows them. Title, link, image and sku are read
 * from the product now (a product since deleted is left out); the price is
 * what the line cost in the cart, in the cart's currency, which a currency
 * switcher may have made different from the catalogue's.
 */
function agumail_cart_items(array $lines) {
    $items = array();
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $product_id = isset($line['product_id']) ? (int) $line['product_id'] : 0;
        $variation_id = isset($line['variation_id']) ? (int) $line['variation_id'] : 0;
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            continue;
        }
        $qty = agumail_quantity(isset($line['qty']) ? $line['qty'] : 1);
        $parent_id = (int) $product->get_parent_id();
        $item = array(
            'title'             => agumail_catalogue_text($product->get_name(), AGUMAIL_MAX_TITLE),
            'qty'               => $qty,
            'price'             => isset($line['line_total']) && is_numeric($line['line_total']) && $qty > 0
                ? agumail_money((float) $line['line_total'] / $qty)
                : agumail_money($product->get_price()),
            'productExternalId' => (string) ($parent_id ? $parent_id : $product->get_id()),
        );
        $url = agumail_clamp_url($product->get_permalink());
        if ($url) {
            $item['url'] = $url;
        }
        $image_id = $product->get_image_id();
        $image = $image_id ? agumail_clamp_url(wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail')) : null;
        if ($image) {
            $item['imageUrl'] = $image;
        }
        // The line's own product: a variation's sku, not its parent's.
        $sku = agumail_clamp_sku($product->get_sku());
        if ($sku !== null) {
            $item['sku'] = $sku;
        }
        $items[] = $item;
    }
    return agumail_clamp_items($items);
}

// ─────────────────────────────────────────────────────────────────────────────
// Housekeeping, daily: emptied and ordered carts go after 14 days, every cart
// after 90. A restore link to a purged cart then just drops its params.
// ─────────────────────────────────────────────────────────────────────────────

add_action('agumail_housekeeping', 'agumail_carts_housekeeping', 10, 0);

function agumail_carts_housekeeping() {
    if (!agumail_ready() || !agumail_is_background_request()) {
        return;
    }
    $now = agumail_time();
    agumail_carts_purge(array('emptied', 'ordered'), $now - AGUMAIL_CARTS_FINISHED_KEPT);
    agumail_carts_purge(array(), $now - AGUMAIL_CARTS_KEPT);
}
