<?php
// Customer accounts: what AguMail hears when an account is made or changed,
// the marketing checkbox on the registration forms, the switch under My
// Account > Account details, and the daily reverse sync that keeps each
// account's answer in step with AguMail.
//
// An answer counts only once its form is submitted: a ticked registration box
// is an opt-in (source wp_register), an unticked one says nothing at all, and
// AguMail keeps that customer as Not subscribed. The My Account switch counts
// only when the customer moves it: on is an opt-in (my_account), off an
// unsubscribe. Somebody who unsubscribed and ticks again is simply sent as an
// opt-in; AguMail decides what that takes (a confirmation email). None of it is
// sent with consentedAt, which would make it history.
//
// Like every storefront hook, these record the change with one outbox write
// (type user, keyed by the user id) and nothing else; the drain builds the
// contact from the account when it sends it. The account's own answer, the
// user meta _agumail_optin (yes, no or unsubscribed), and for an opt-in its
// moment, _agumail_optin_at, are written only once AguMail has it, by the
// drain; until then the queued answer is the one the switch shows. An account made at checkout is left to its order, which
// carries the contact and the checkout's answer.
//
// A new email address moves the account in AguMail: the new address takes the
// account's answer (a subscription moves dated at its original consent, so it
// is not welcomed again), and the old one is left alone, since WooCommerce
// keeps it as the billing address of the account's orders. Switching off
// unsubscribes both. The address AguMail holds for the account is kept in the
// user meta _agumail_email.
//
// The reverse sync asks AguMail once a day where each customer account stands, 500
// addresses at a time, and writes yes, unsubscribed or no, so the checkout and
// the popup stop asking customers who already subscribed. It never writes an
// opt-in moment: it only learns the state, not when it began.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_REVERSE_SYNC_BATCH', 500);
define('AGUMAIL_REVERSE_SYNC_BUDGET', 20);
define('AGUMAIL_REVERSE_SYNC_RETRY', 15 * MINUTE_IN_SECONDS);

add_action('init', 'agumail_customers_register_admin');
add_action('woocommerce_register_form', 'agumail_customers_render_registration_optin');
add_action('register_form', 'agumail_customers_render_registration_optin');
add_action('user_register', 'agumail_customers_user_registered', 10, 1);
add_filter('insert_user_meta', 'agumail_customers_watch_names', 10, 3);
add_action('profile_update', 'agumail_customers_profile_updated', 10, 2);
add_action('woocommerce_edit_account_form', 'agumail_customers_render_account_switch');
add_action('woocommerce_save_account_details', 'agumail_customers_account_saved', 10, 1);
add_filter('agumail_show_checkout_optin', 'agumail_customers_show_checkout_optin');
add_filter('agumail_checkout_optin_starts_ticked', 'agumail_customers_checkout_optin_starts_ticked');
agumail_add_background_action('agumail_reverse_sync', 'agumail_customers_reverse_sync', 10, 0);

// ─────────────────────────────────────────────────────────────────────────────
// Who counts as a customer, and where their answer stands.
// ─────────────────────────────────────────────────────────────────────────────

/** The roles whose accounts are store customers. Staff accounts are never contacts. */
function agumail_customer_roles() {
    return array_values(array_filter((array) apply_filters('agumail_customer_roles', array('customer')), 'is_string'));
}

/**
 * A MySQL pattern that finds a customer role in a capabilities value, which
 * WordPress stores serialized with the role in quotes: "(customer|...)". Role
 * names are keys (a-z, 0-9, _ and -), so nothing in them means anything to the
 * pattern.
 */
function agumail_customer_roles_regexp() {
    $roles = array_values(array_filter(array_map('sanitize_key', agumail_customer_roles())));
    return '"(' . implode('|', $roles ? $roles : array('customer')) . ')"';
}

/**
 * Store staff: the reverse sync never asks about them, even when they shop.
 * The same test as carts and tracking use (agumail_is_staff(), or the
 * capability to manage the store).
 */
function agumail_customers_is_staff($user_id) {
    $user_id = (int) $user_id;
    if (function_exists('agumail_is_staff')) {
        return $user_id > 0 && agumail_is_staff($user_id);
    }
    return $user_id > 0 && user_can($user_id, AGUMAIL_ADMIN_CAPABILITY);
}

/** When the account opted in, as the plugin recorded it (_agumail_optin_at), or null when unknown, unreadable or in the future. */
function agumail_customers_optin_moment($user_id) {
    $raw = trim((string) get_user_meta((int) $user_id, '_agumail_optin_at', true));
    $time = $raw !== '' ? strtotime($raw) : false;
    return $time && $time > 0 && $time <= agumail_time() ? $time : null;
}

/** A customer account: a customer role, a paying customer, or an account that already gave AguMail an answer. */
function agumail_customers_is_customer($user) {
    if (!is_object($user) || empty($user->ID)) {
        return false;
    }
    if (array_intersect((array) $user->roles, agumail_customer_roles())) {
        return true;
    }
    return (string) get_user_meta($user->ID, 'paying_customer', true) === '1'
        || agumail_customers_saved_answer($user->ID) !== '';
}

/** yes, no or unsubscribed as AguMail last confirmed it, or '' when it never has. */
function agumail_customers_saved_answer($user_id) {
    $answer = (string) get_user_meta((int) $user_id, '_agumail_optin', true);
    return in_array($answer, array('yes', 'no', 'unsubscribed'), true) ? $answer : '';
}

/** The account's queued row, waiting or refused (failed): either way its answer is not in AguMail yet. */
function agumail_customers_unsent_row($user_id) {
    if (!agumail_ready()) {
        return null;
    }
    $row = agumail_store()->find('user', (string) (int) $user_id);
    return $row && in_array($row['status'], array('pending', 'failed'), true) ? $row : null;
}

/** The answer queued for the account and not yet in AguMail: yes, unsubscribed, or null. */
function agumail_customers_queued_answer($user_id) {
    $row = agumail_customers_unsent_row($user_id);
    if (!$row || !is_array($row['payload'])) {
        return null;
    }
    $answer = isset($row['payload']['answer']) ? $row['payload']['answer'] : null;
    return in_array($answer, array('yes', 'unsubscribed'), true) ? $answer : null;
}

/** Where the account stands as the customer last said: the queued answer first, then the saved one. */
function agumail_customers_answer($user_id) {
    $queued = agumail_customers_queued_answer($user_id);
    return $queued !== null ? $queued : agumail_customers_saved_answer($user_id);
}

/**
 * Inside a checkout, which makes the account and places the order in one go:
 * the classic checkout (WOOCOMMERCE_CHECKOUT, woocommerce_checkout_process),
 * the Store API's (the order was filled from the request before the customer
 * is made), or the order received page's account form right after it. The
 * order carries the contact and the checkout's answer, and its delivery
 * records the answer on the account.
 */
function agumail_customers_in_checkout() {
    return (defined('WOOCOMMERCE_CHECKOUT') && WOOCOMMERCE_CHECKOUT)
        || did_action('woocommerce_before_checkout_process')
        || did_action('woocommerce_checkout_process')
        || did_action('woocommerce_store_api_checkout_update_order_from_request')
        // The order received page, where WooCommerce 9 offers to make an account for the order just placed.
        || (function_exists('is_order_received_page') && is_order_received_page());
}

// ─────────────────────────────────────────────────────────────────────────────
// Queueing. A change to an account already waiting in line adds nothing unless
// it brings something new (an answer, a moved address): the drain builds the
// contact from the account as it is then. So WooCommerce saving an account
// twice in one request still costs one write. What a row already carries,
// waiting or refused, is kept when a later change brings nothing new, so a
// rename never drops an opt-in, even one AguMail set aside.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Queues the account. $answer is yes or unsubscribed, or null for a change
 * that says nothing about consent; $source is wp_register or my_account;
 * $previous is the address the account had before this request changed it.
 */
function agumail_customers_queue($user_id, $answer = null, $source = '', $previous = '') {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !agumail_ready() || !agumail_has_api_key()) {
        return false;
    }
    $row = agumail_customers_unsent_row($user_id);
    $payload = $row && is_array($row['payload']) ? $row['payload'] : array();
    if ($row && $row['status'] === 'pending'
        && ($answer === null || (isset($payload['answer']) && $payload['answer'] === $answer))
        && ($previous === '' || !empty($payload['previous']))) {
        return false;
    }
    if ($answer !== null) {
        $payload['answer'] = $answer;
        $payload['source'] = $source;
    }
    // The oldest address still waiting to be moved is the one AguMail holds.
    if ($previous !== '' && empty($payload['previous'])) {
        $payload['previous'] = $previous;
    }
    return agumail_enqueue('user', $user_id, $payload ? $payload : null);
}

// ─────────────────────────────────────────────────────────────────────────────
// Registration.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The registration checkbox, with the checkout's words and their links. Always
 * unticked, whatever the checkout's box starts as. Same markup on the
 * WooCommerce and WordPress forms. Not on the checkout page, where a theme may
 * show the registration form next to the checkout's own checkbox.
 */
function agumail_customers_render_registration_optin() {
    if (!agumail_switch_on('agumail_registration_optin') || !agumail_has_api_key() || (function_exists('is_checkout') && is_checkout())) {
        return;
    }
    echo '<p class="form-row agumail-optin"><label for="agumail_optin" class="woocommerce-form__label woocommerce-form__label-for-checkbox">'
        . '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="agumail_optin" id="agumail_optin" value="1" /> '
        . '<span>' . wp_kses(agumail_consent_label(), agumail_consent_label_tags()) . '</span></label></p>';
}

/**
 * A new account. The ticked box on a submitted registration form is an opt-in;
 * anything else carries no answer. Sent for a customer account, or anyone who
 * registered themselves; an account made at checkout is left to its order.
 */
function agumail_customers_user_registered($user_id) {
    if (agumail_customers_in_checkout()) {
        return;
    }
    $user = get_userdata($user_id);
    $self_registered = did_action('register_post') || did_action('woocommerce_register_post');
    if (!$user || (!$self_registered && !agumail_customers_is_customer($user))) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form's own check has passed; only the new account's own answer is read.
    $ticked = $self_registered && agumail_switch_on('agumail_registration_optin') && !empty($_POST['agumail_optin']);
    // A store keeps every customer, for its cart and browse reminders. A site
    // without one has nothing to remind anybody of, so only a yes is sent.
    if (!$ticked && !agumail_woocommerce_active()) {
        return;
    }
    agumail_customers_queue($user_id, $ticked ? 'yes' : null, $ticked ? 'wp_register' : '');
}

// ─────────────────────────────────────────────────────────────────────────────
// Account changes. The email is compared with the account as it was when the
// request first updated it (profile_update, which also runs while the Account
// details form saves); a name is compared while WordPress is about to store
// the new one (insert_user_meta), the only moment both are at hand.
// ─────────────────────────────────────────────────────────────────────────────

/** The address each account had when this request first updated it. */
function &agumail_customers_old_emails() {
    static $old = array();
    return $old;
}

/** The address this request moved the account away from, or ''. */
function agumail_customers_moved_from($user_id) {
    $old = &agumail_customers_old_emails();
    $user = get_userdata($user_id);
    if (!$user || !isset($old[(int) $user_id])) {
        return '';
    }
    $current = strtolower(trim((string) $user->user_email));
    return $old[(int) $user_id] !== '' && $old[(int) $user_id] !== $current ? $old[(int) $user_id] : '';
}

/** A moved account keeps its subscription: the new address goes with a yes, as the customer's own. */
function agumail_customers_move_answer($user_id) {
    return agumail_customers_answer($user_id) === 'yes' ? 'yes' : null;
}

/** Accounts whose name changed in this request. */
function &agumail_customers_renamed() {
    static $renamed = array();
    return $renamed;
}

function agumail_customers_watch_names($meta, $user = null, $update = false) {
    if ($update && is_object($user) && !empty($user->ID) && is_array($meta)) {
        foreach (array('first_name', 'last_name') as $key) {
            if (array_key_exists($key, $meta) && (string) $meta[$key] !== (string) $user->$key) {
                $renamed = &agumail_customers_renamed();
                $renamed[(int) $user->ID] = true;
            }
        }
    }
    return $meta;
}

/** Whether the account's email or name changed in this request. */
function agumail_customers_changed($user_id) {
    $renamed = &agumail_customers_renamed();
    return !empty($renamed[(int) $user_id]) || agumail_customers_moved_from($user_id) !== '';
}

/** The Account details form is being saved: its own handler queues the account once, with the switch's answer. */
function agumail_customers_saving_account() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks this form's nonce before it saves anything.
    return isset($_POST['action']) && is_string($_POST['action']) && sanitize_key(wp_unslash($_POST['action'])) === 'save_account_details';
}

function agumail_customers_profile_updated($user_id, $old_user = null) {
    $old = &agumail_customers_old_emails();
    if (is_object($old_user) && isset($old_user->user_email) && !isset($old[(int) $user_id])) {
        $old[(int) $user_id] = strtolower(trim((string) $old_user->user_email));
    }
    if (agumail_customers_in_checkout() || agumail_customers_saving_account()) {
        return;
    }
    if (agumail_customers_changed($user_id) && agumail_customers_is_customer(get_userdata($user_id))) {
        $moved_from = agumail_customers_moved_from($user_id);
        $answer = $moved_from !== '' ? agumail_customers_move_answer($user_id) : null;
        agumail_customers_queue($user_id, $answer, $answer ? 'my_account' : '', $moved_from);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// My Account > Account details. A hidden marker says the switch was on the
// form, so a form without it (another plugin's, a theme's) is never read as
// switching off. Only a move counts: saving the form with the switch left as
// it was says nothing, so a customer who never subscribed is not unsubscribed
// (and stops getting cart reminders) for changing their name.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_customers_render_account_switch() {
    if (!agumail_switch_on('agumail_account_toggle') || !is_user_logged_in() || !agumail_has_api_key()) {
        return;
    }
    $on = agumail_customers_answer(get_current_user_id()) === 'yes';
    echo '<fieldset class="agumail-account-optin"><p class="woocommerce-form-row form-row">'
        . '<label for="agumail_optin" class="woocommerce-form__label woocommerce-form__label-for-checkbox">'
        . '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="agumail_optin" id="agumail_optin" value="1"'
        . ($on ? ' checked="checked"' : '') . ' /> <span>' . wp_kses(agumail_consent_label(), agumail_consent_label_tags()) . '</span></label>'
        . '<input type="hidden" name="agumail_account_optin" value="1" /></p></fieldset>';
}

/** The switch as the customer left it, compared with where it stood: yes, unsubscribed, or null when it did not move. */
function agumail_customers_switch_answer($user_id) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the Account details nonce before firing its hook; only whether the box is there is read.
    if (!agumail_switch_on('agumail_account_toggle') || empty($_POST['agumail_account_optin'])) {
        return null;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above; only whether the box is ticked is read.
    $on = !empty($_POST['agumail_optin']);
    $was_on = agumail_customers_answer($user_id) === 'yes';
    if ($on === $was_on) {
        return null;
    }
    return $on ? 'yes' : 'unsubscribed';
}

function agumail_customers_account_saved($user_id) {
    if (agumail_customers_in_checkout()) {
        return;
    }
    $answer = agumail_customers_switch_answer($user_id);
    $moved_from = agumail_customers_moved_from($user_id);
    if ($answer === null && $moved_from !== '') {
        $answer = agumail_customers_move_answer($user_id);
    }
    if ($answer !== null) {
        agumail_customers_queue($user_id, $answer, 'my_account', $moved_from);
    } elseif (agumail_customers_changed($user_id) && agumail_customers_is_customer(get_userdata($user_id))) {
        agumail_customers_queue($user_id, null, '', $moved_from);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The checkout checkbox is not shown to a customer AguMail holds as
// subscribed, and never starts ticked for one who said no. The block checkout
// asks the first at woocommerce_init, when the signed-in user is already known.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_customers_show_checkout_optin($show) {
    if (!$show || !is_user_logged_in()) {
        return $show;
    }
    return agumail_customers_saved_answer(get_current_user_id()) !== 'yes';
}

/**
 * A box the merchant set to start ticked starts unticked for a signed-in
 * customer who said no or unsubscribed, including an unsubscribe still on its
 * way to AguMail: their own answer wins over the store's default.
 */
function agumail_customers_checkout_optin_starts_ticked($ticked) {
    if (!$ticked || !is_user_logged_in()) {
        return $ticked;
    }
    return !in_array(agumail_customers_answer(get_current_user_id()), array('no', 'unsubscribed'), true);
}

// ─────────────────────────────────────────────────────────────────────────────
// The record, built when the drain sends it.
// ─────────────────────────────────────────────────────────────────────────────

agumail_register_builder('user', array(
    'endpoint'   => '/api/v1/contacts',
    'collection' => 'contacts',
    'phase'      => 10,
    'build'      => 'agumail_customers_build_user',
    'contacts'   => 'agumail_customers_user_moved_from',
    'on_success' => 'agumail_customers_user_sent',
));

/**
 * The address AguMail holds for the account when it is no longer the
 * account's: the one it last took (_agumail_email), else the one the change
 * moved away from. '' when the account has not moved.
 */
function agumail_customers_held_address($user, $row) {
    $current = strtolower(trim((string) $user->user_email));
    $held = strtolower(trim((string) get_user_meta($user->ID, '_agumail_email', true)));
    if ($held === '' && is_array($row['payload']) && !empty($row['payload']['previous'])) {
        $held = strtolower(trim((string) $row['payload']['previous']));
    }
    return $held !== '' && $held !== $current ? $held : '';
}

/**
 * Sent before the account itself when the customer switched off: the address
 * AguMail held for a moved account, unsubscribed too, so switching off stops
 * both. A move alone leaves the old address alone: WooCommerce keeps it as the
 * billing address of the account's orders. No tags or names: it is an
 * instruction about an address, not a new customer.
 */
function agumail_customers_user_moved_from($key, $row) {
    $answer = is_array($row['payload']) && isset($row['payload']['answer']) ? $row['payload']['answer'] : null;
    $user = $answer === 'unsubscribed' ? get_userdata((int) $key) : null;
    $held = $user ? agumail_customers_held_address($user, $row) : '';
    $contact = $held !== '' ? agumail_contact_payload($held, array('unsubscribed' => true, 'tags' => array())) : null;
    return $contact ? array($contact) : array();
}

/** The account as a contact, with the queued answer, or null when the account is gone or has no valid email. */
function agumail_customers_build_user($key, $row) {
    $user = get_userdata((int) $key);
    if (!$user) {
        return null;
    }
    $payload = is_array($row['payload']) ? $row['payload'] : array();
    $answer = isset($payload['answer']) ? $payload['answer'] : null;
    $source = isset($payload['source']) ? $payload['source'] : '';
    $moved = agumail_customers_held_address($user, $row) !== '';
    $subscribed = agumail_customers_saved_answer($user->ID) === 'yes';
    // A subscribed account whose address moved takes its subscription along,
    // dated at the original consent: AguMail files it as history and does not
    // welcome them again. Without a recorded moment it goes as news.
    if ($answer === null && $moved && $subscribed) {
        $answer = 'yes';
        $source = 'my_account';
    }
    $consented_at = $moved && $subscribed && $answer === 'yes' ? agumail_customers_optin_moment($user->ID) : null;
    return agumail_contact_payload($user->user_email, array(
        'first_name'     => agumail_customers_first_name($user),
        'last_name'      => agumail_customers_last_name($user),
        'opted_in'       => $answer === 'yes' ? true : null,
        'source'         => $source,
        'unsubscribed'   => $answer === 'unsubscribed',
        'customer_since' => $user->user_registered,
        'consented_at'   => $consented_at,
    ));
}

function agumail_customers_first_name($user) {
    $name = trim((string) $user->first_name);
    return $name !== '' ? $name : (string) get_user_meta($user->ID, 'billing_first_name', true);
}

function agumail_customers_last_name($user) {
    $name = trim((string) $user->last_name);
    return $name !== '' ? $name : (string) get_user_meta($user->ID, 'billing_last_name', true);
}

/**
 * AguMail holds the account: it records the address AguMail now has
 * (_agumail_email) and the answer (the drain queues nothing for these writes).
 * An account whose answer becomes yes also records the moment,
 * _agumail_optin_at (ISO 8601, UTC): when the customer submitted the form,
 * which is when the row was queued, not when the drain got to it. A later
 * change to an account already subscribed (a rename, a moved address) keeps
 * the moment it has. The bulk sync dates the account's consent by it.
 */
function agumail_customers_user_sent($key, $row) {
    $user = get_userdata((int) $key);
    if (!$user) {
        return;
    }
    $email = strtolower(trim((string) $user->user_email));
    if ($email !== '' && (string) get_user_meta($user->ID, '_agumail_email', true) !== $email) {
        update_user_meta($user->ID, '_agumail_email', $email);
    }
    $answer = is_array($row['payload']) && isset($row['payload']['answer']) ? $row['payload']['answer'] : null;
    if (!in_array($answer, array('yes', 'unsubscribed'), true)) {
        return;
    }
    $saved = agumail_customers_saved_answer($user->ID);
    if ($saved === $answer) {
        return;
    }
    update_user_meta($user->ID, '_agumail_optin', $answer);
    if ($answer === 'yes') {
        update_user_meta($user->ID, '_agumail_optin_at', agumail_iso_date(agumail_drain_row_time($row)));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Reverse sync: once a day, 500 accounts per request, in id order, for about
// 20 seconds a run; a run that stops early leaves its place in an option and a
// follow-up to carry on. An account with an answer still queued is left alone:
// that answer is newer than anything AguMail can say.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Customer accounts of this site after $after, by id: [ID, user_email] rows.
 * A customer has a customer role or has paid for an order; staff are left out
 * afterwards, by the same test carts and tracking use.
 */
function agumail_customers_accounts_after($after, $limit) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's customer accounts for the daily check, read once; WP_User_Query cannot page by id.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, user_email FROM {$wpdb->users} WHERE ID > %d AND user_email <> ''"
        . " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} cm WHERE cm.user_id = {$wpdb->users}.ID AND cm.meta_key = %s"
        . " AND (cm.meta_value REGEXP %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} pm WHERE pm.user_id = cm.user_id AND pm.meta_key = 'paying_customer' AND pm.meta_value = '1')))"
        . ' ORDER BY ID ASC LIMIT %d',
        (int) $after, $wpdb->prefix . 'capabilities', agumail_customer_roles_regexp(), (int) $limit
    ), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/**
 * yes when AguMail may mail them, unsubscribed when they asked to stop (or
 * complained, or sit on the suppression list), no for anything else AguMail
 * holds. An address AguMail does not hold changes nothing: not knowing an
 * address is no news about the account (it may be on its way, or moved).
 */
function agumail_customers_answer_from_status(array $contact, $current) {
    if (!empty($contact['subscribed'])) {
        return 'yes';
    }
    $status = isset($contact['status']) && is_string($contact['status']) ? $contact['status'] : null;
    if ($status === null) {
        return $current;
    }
    return in_array($status, array('unsubscribed', 'complained', 'active'), true) ? 'unsubscribed' : 'no';
}

/** The daily job, and its follow-ups. */
function agumail_customers_reverse_sync() {
    if (!agumail_http_allowed() || !agumail_ready() || agumail_drain_paused()) {
        return 'skipped';
    }
    if (agumail_backoff_until() > agumail_time()) {
        agumail_customers_reverse_sync_later(agumail_backoff_until() - agumail_time());
        return 'backoff';
    }
    $after = (int) get_option('agumail_reverse_sync_cursor', 0);
    $deadline = agumail_clock() + AGUMAIL_REVERSE_SYNC_BUDGET;
    do {
        $accounts = agumail_customers_accounts_after($after, AGUMAIL_REVERSE_SYNC_BATCH);
        if (!$accounts) {
            break;
        }
        $customers = array_values(array_filter($accounts, function ($account) {
            return !agumail_customers_is_staff((int) $account['ID']);
        }));
        $outcome = $customers ? agumail_customers_reverse_sync_batch($customers) : true;
        if ($outcome !== true) {
            update_option('agumail_reverse_sync_cursor', $after, false);
            agumail_customers_reverse_sync_later($outcome);
            return 'wait';
        }
        $last = end($accounts);
        $after = (int) $last['ID'];
        if (count($accounts) < AGUMAIL_REVERSE_SYNC_BATCH) {
            break;
        }
        if (agumail_clock() >= $deadline) {
            update_option('agumail_reverse_sync_cursor', $after, false);
            agumail_customers_reverse_sync_later(0);
            return 'more';
        }
    } while (true);
    delete_option('agumail_reverse_sync_cursor');
    return 'done';
}

/** A follow-up in $delay seconds, marked so it is never mistaken for the daily job. */
function agumail_customers_reverse_sync_later($delay) {
    if (!function_exists('as_schedule_single_action')) {
        return;
    }
    as_unschedule_all_actions('agumail_reverse_sync', array('continue'), AGUMAIL_GROUP);
    if ($delay <= 0 && function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('agumail_reverse_sync', array('continue'), AGUMAIL_GROUP);
    } else {
        as_schedule_single_action(agumail_time() + max(1, (int) $delay), 'agumail_reverse_sync', array('continue'), AGUMAIL_GROUP);
    }
}

/** One request for up to 500 accounts. Returns true, or the seconds to wait before trying again. */
function agumail_customers_reverse_sync_batch(array $accounts) {
    $emails = array();
    foreach ($accounts as $account) {
        $emails[] = strtolower(trim((string) $account['user_email']));
    }
    $response = agumail_api_request('POST', '/api/v1/contacts/status', array('emails' => $emails), array('timeout' => AGUMAIL_DRAIN_TIMEOUT));
    $answer = agumail_drain_classify($response);
    if ($answer === 'rate') {
        $wait = agumail_drain_retry_after($response);
        agumail_set_backoff_until(max(agumail_backoff_until(), agumail_time() + $wait));
        return $wait;
    }
    if ($answer !== 'ok' || !isset($response['body']['contacts']) || !is_array($response['body']['contacts'])) {
        if ($answer === 'auth') {
            agumail_record_connection_error($response['code'], agumail_drain_error_message($response));
        }
        agumail_log('warning', 'The daily subscription check could not reach AguMail; it will try again later.');
        return AGUMAIL_REVERSE_SYNC_RETRY;
    }
    $by_email = array();
    foreach ($response['body']['contacts'] as $contact) {
        if (is_array($contact) && isset($contact['email'])) {
            $by_email[strtolower(trim((string) $contact['email']))] = $contact;
        }
    }
    foreach ($accounts as $i => $account) {
        if (!isset($by_email[$emails[$i]])) {
            continue;
        }
        $user_id = (int) $account['ID'];
        $current = agumail_customers_saved_answer($user_id);
        $next = agumail_customers_answer_from_status($by_email[$emails[$i]], $current);
        if ($next === $current || $next === '') {
            continue;
        }
        // Checked only for an account that would change: one lookup, not 500.
        // A row waiting or refused holds an answer AguMail has not taken yet.
        if (agumail_customers_unsent_row($user_id)) {
            continue;
        }
        update_user_meta($user_id, '_agumail_optin', $next);
    }
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Settings: the two places customers are asked outside checkout.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_customers_register_admin() {
    agumail_admin_register_action('customers_save', 'agumail_customers_save_settings', array(
        'agumail_registration_form'  => 'text',
        'agumail_registration_optin' => 'text',
        'agumail_account_toggle'     => 'text',
        'agumail_consent_label'      => 'label',
    ));
    if (!agumail_woocommerce_active()) {
        // No checkout and no My Account: the WordPress registration form, and the checkbox's words, which the Checkout section keeps on a store.
        agumail_admin_register_section('accounts', __('Registration', 'agumail'), 'agumail_customers_render_registration_section', 45, array(
            'icon'        => 'admin-users',
            'description' => __('Asking for consent when people register on this site.', 'agumail'),
        ));
        return;
    }
    agumail_admin_register_section('accounts', __('Customer accounts', 'agumail'), 'agumail_customers_render_section', 45, array(
        'icon'        => 'admin-users',
        'description' => __('Asking for consent at registration and in My Account.', 'agumail'),
    ));
}

function agumail_customers_render_section() {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('customers_save');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('Registration', 'agumail') . '</th><td><label>'
        . '<input type="checkbox" name="agumail_registration_optin" value="yes"' . checked(agumail_switch_on('agumail_registration_optin'), true, false) . ' /> '
        . esc_html__('Show the marketing checkbox on the registration forms', 'agumail') . '</label>'
        . '<p class="description">' . esc_html__('Uses the same words as the checkout checkbox. It starts unticked, and only a ticked box counts as a yes.', 'agumail') . '</p></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('My Account', 'agumail') . '</th><td><label>'
        . '<input type="checkbox" name="agumail_account_toggle" value="yes"' . checked(agumail_switch_on('agumail_account_toggle'), true, false) . ' /> '
        . esc_html__('Let customers switch marketing emails on or off under Account details', 'agumail') . '</label></td></tr>';
    echo '</tbody></table>';
    submit_button(__('Save', 'agumail'));
    echo '</form>';
}

/** A site without WooCommerce: the checkbox on WordPress's registration form, and its words. */
function agumail_customers_render_registration_section() {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('customers_save');
    echo '<input type="hidden" name="agumail_registration_form" value="1" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('Registration', 'agumail') . '</th><td><label>'
        . '<input type="checkbox" name="agumail_registration_optin" value="yes"' . checked(agumail_switch_on('agumail_registration_optin'), true, false) . ' /> '
        . esc_html__('Show a marketing checkbox on the registration form', 'agumail') . '</label>'
        . '<p class="description">' . esc_html__('It starts unticked. People who tick it join your AguMail list as subscribers; nobody else is sent.', 'agumail') . '</p>';
    if (!get_option('users_can_register')) {
        echo '<p class="description">' . esc_html__('This site does not let people register (Settings > General > Membership), so the checkbox shows nowhere yet.', 'agumail') . '</p>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="agumail_consent_label">' . esc_html__('Checkbox label', 'agumail') . '</label></th>';
    echo '<td><input name="agumail_consent_label" id="agumail_consent_label" type="text" class="large-text" value="' . esc_attr(agumail_consent_label()) . '" />';
    echo '<p class="description">' . esc_html__('Links are kept; any other markup is removed.', 'agumail') . '</p></td></tr>';
    echo '</tbody></table>';
    submit_button(__('Save', 'agumail'));
    echo '</form>';
}

function agumail_customers_save_settings(array $request) {
    if (!empty($request['agumail_registration_form'])) {
        update_option('agumail_registration_optin', !empty($request['agumail_registration_optin']) ? 'yes' : 'no');
        agumail_admin_save_consent_label(isset($request['agumail_consent_label']) ? $request['agumail_consent_label'] : '');
        return array('message' => __('Registration settings saved.', 'agumail'));
    }
    foreach (array('agumail_registration_optin', 'agumail_account_toggle') as $option) {
        update_option($option, !empty($request[$option]) ? 'yes' : 'no');
    }
    return array('message' => __('Customer account settings saved.', 'agumail'));
}
