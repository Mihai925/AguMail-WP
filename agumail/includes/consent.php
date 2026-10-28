<?php
// The checkout's marketing checkbox.
//
// A store runs one of two checkouts. The classic one renders the box from a
// template hook and posts it with the form; the block checkout shows only
// fields registered with WooCommerce, and keeps the answer on the order.
//
// The answer counts only once the order is submitted. A box ticked on a page
// that was never submitted, or saved into a block checkout's draft order along
// the way, is not an answer. Either way the answer lands in the order meta
// _agumail_optin (yes or no), written while WooCommerce saves the order, so it
// costs no write of its own; agumail_order_optin_answer() reads it. An order
// that never showed the box has no answer at all.
//
// The box starts unticked unless the merchant sets it to start ticked
// (agumail_checkout_optin_default). Either way only what the submitted order
// carries counts.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_CHECKOUT_FIELD', 'agumail/optin');

/**
 * Whether this shopper sees the box. Only on a connected store, like the
 * registration box and the My Account switch: activating the plugin alone
 * changes nothing on the checkout. The agumail_show_checkout_optin filter
 * hides it (for a customer already known to be subscribed, say); an order
 * placed without the box shown records no answer.
 *
 * On the block checkout this is decided at woocommerce_init, both for the
 * page and for the Store API request that submits the order.
 */
function agumail_checkout_optin_visible() {
    return agumail_has_api_key() && (bool) apply_filters('agumail_show_checkout_optin', true);
}

/**
 * Whether the box starts ticked for this shopper: as the merchant set it,
 * unless the agumail_checkout_optin_starts_ticked filter says otherwise (for a
 * customer who already said no, say). Where it starts is never an answer.
 */
function agumail_checkout_optin_starts_ticked() {
    return (bool) apply_filters('agumail_checkout_optin_starts_ticked', agumail_checkout_optin_ticked_by_default());
}

// ─────────────────────────────────────────────────────────────────────────────
// Classic checkout. A hidden marker goes with the box, so a submitted order
// tells "shown and left unticked" (no) from "never shown" (no answer).
// ─────────────────────────────────────────────────────────────────────────────

add_action('woocommerce_review_order_before_submit', 'agumail_render_checkout_optin');

function agumail_render_checkout_optin() {
    if (!function_exists('woocommerce_form_field') || !agumail_checkout_optin_visible()) {
        return;
    }
    woocommerce_form_field('agumail_optin', array(
        'type'     => 'checkbox',
        'class'    => array('form-row', 'agumail-optin'),
        'label'    => wp_kses(agumail_consent_label(), agumail_consent_label_tags()),
        'required' => false,
    ), agumail_checkout_optin_ticked_on_screen());
    echo '<input type="hidden" name="agumail_optin_shown" value="1" />';
}

/**
 * Whether the box is ticked on the page being drawn. The classic checkout
 * redraws it each time it refreshes the order review, posting the form as one
 * serialized post_data field; once the box has been on the page (its marker
 * came back), it keeps the shopper's tick. Until then it starts as the
 * merchant set it, for this shopper. This keeps what the shopper sees; it is
 * not an answer.
 */
function agumail_checkout_optin_ticked_on_screen() {
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- WooCommerce checks the order review nonce; two fields are read, each only as whether it is non-empty, and nothing is saved or printed.
    $shown = !empty($_POST['agumail_optin_shown']);
    $ticked = !empty($_POST['agumail_optin']);
    if (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
        $form = array();
        parse_str(wp_unslash($_POST['post_data']), $form);
        $shown = !empty($form['agumail_optin_shown']);
        $ticked = !empty($form['agumail_optin']);
    }
    // phpcs:enable
    return $shown ? $ticked : agumail_checkout_optin_starts_ticked();
}

add_action('woocommerce_checkout_create_order', 'agumail_record_classic_optin', 10, 1);

/** The classic checkout's submit: WooCommerce saves the order right after this hook. */
function agumail_record_classic_optin($order) {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before creating the order.
    if (!is_object($order) || empty($_POST['agumail_optin_shown'])) {
        return;
    }
    $order->update_meta_data('_agumail_optin', empty($_POST['agumail_optin']) ? 'no' : 'yes');
    // phpcs:enable
}

// ─────────────────────────────────────────────────────────────────────────────
// Block checkout (WooCommerce 8.9 and later; on older stores only the classic
// checkout asks). Up to WooCommerce 10.8 the same Store API hook runs when the
// draft order is saved (PUT) and when the order is placed (POST); from 10.9 it
// runs only for the POST, or for a PUT on the order of a failed payment. Only
// the POST is a submission.
// ─────────────────────────────────────────────────────────────────────────────

add_action('woocommerce_init', 'agumail_register_block_optin');

function agumail_register_block_optin() {
    if (!function_exists('woocommerce_register_additional_checkout_field') || !agumail_checkout_optin_visible()) {
        return;
    }
    woocommerce_register_additional_checkout_field(array(
        'id'                         => AGUMAIL_CHECKOUT_FIELD,
        // WooCommerce shows the label as plain text, so links go and only their words stay.
        'label'                      => agumail_consent_label_text(),
        'location'                   => 'order',
        'type'                       => 'checkbox',
        // Otherwise the thank-you page and the order emails list the box
        // under "Additional information", Yes or No, on every order
        // (WooCommerce 10.1 and later; older versions ignore the key).
        'show_in_order_confirmation' => false,
    ));
    agumail_block_optin_registered(true);
}

add_filter('woocommerce_get_default_value_for_' . AGUMAIL_CHECKOUT_FIELD, 'agumail_block_optin_default', 10, 3);

/**
 * The block checkout draws the box from what it holds for the shopper. When
 * the box starts ticked for this shopper and that holds no value for it yet,
 * it starts ticked; once the shopper touches the box, their choice is kept and
 * this is not asked.
 *
 * Up to WooCommerce 10.8 the checkout drafts an order as the shopper goes and
 * draws the box from it. Orders only there: WooCommerce copies the shopper's
 * customer record into the draft each time it updates the draft, and a default
 * there would tick the box again after the shopper unticked it. From 10.9 no
 * order exists until the shopper places it, and the box is drawn from the
 * customer record, which keeps the shopper's choice, so the customer record
 * gets the default too. An order still does, for a payment retried on the
 * order of one that failed.
 */
function agumail_block_optin_default($value, $group = '', $object = null) {
    if ($value !== null || !is_object($object) || !agumail_checkout_optin_starts_ticked()) {
        return $value;
    }
    if (!is_a($object, 'WC_Order') && !(is_a($object, 'WC_Customer') && agumail_block_checkout_defers_order())) {
        return $value;
    }
    // Where WooCommerce keeps an order's or a customer's own additional fields.
    return $object->meta_exists('_wc_other/' . AGUMAIL_CHECKOUT_FIELD) ? $value : true;
}

/** WooCommerce 10.9 and later: the block checkout creates the order only when the shopper places it. */
function agumail_block_checkout_defers_order() {
    return method_exists('Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema', 'get_draft_response');
}

function agumail_block_optin_registered($set = null) {
    static $registered = false;
    if ($set !== null) {
        $registered = (bool) $set;
    }
    return $registered;
}

add_action('woocommerce_store_api_checkout_update_order_from_request', 'agumail_record_block_optin', 10, 2);

/** The Store API saves the order right after this hook. */
function agumail_record_block_optin($order, $request = null) {
    if (!is_object($order) || !is_object($request) || !method_exists($request, 'get_method')) {
        return;
    }
    if (strtoupper($request->get_method()) !== 'POST' || !agumail_block_optin_registered()) {
        return;
    }
    // What the submission itself carried; a tick left on the draft by an earlier PUT does not count.
    $fields = $request->get_param('additional_fields');
    $value = is_array($fields) && array_key_exists(AGUMAIL_CHECKOUT_FIELD, $fields) ? $fields[AGUMAIL_CHECKOUT_FIELD] : false;
    $order->update_meta_data('_agumail_optin', agumail_truthy($value) ? 'yes' : 'no');
}

function agumail_truthy($value) {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'on'), true);
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the answer.
// ─────────────────────────────────────────────────────────────────────────────

/** 'yes', 'no', or null when the order was never submitted with the box shown. */
function agumail_order_optin_answer($order) {
    if (!is_object($order)) {
        return null;
    }
    $answer = $order->get_meta('_agumail_optin');
    return $answer === 'yes' || $answer === 'no' ? $answer : null;
}

function agumail_order_opted_in($order) {
    return agumail_order_optin_answer($order) === 'yes';
}
