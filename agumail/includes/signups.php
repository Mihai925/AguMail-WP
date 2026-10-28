<?php
// Form signups: people who sign up through the store's own form plugins
// (Elementor Pro, Contact Form 7 and WPForms). Each plugin's adapter, in
// includes/integrations, reads a submitted form and hands the person to
// agumail_signup_enqueue(), which records them with one outbox write and
// nothing else. The drain sends them to /api/v1/signups later.
//
// AguMail treats these as it treats its own signup forms, not as a customer
// sync: someone already subscribed gets nothing new, a double opt-in form
// sends a new address a confirmation email, and the form's tags start flows
// once the signup completes. So a signup never goes through /api/v1/contacts,
// which trusts the store and would tag an existing subscriber again.
//
// A form submitted twice queues one row: the key is the builder, the form and
// the address, and the newer submission replaces the older one.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_SIGNUP_MAX_EMAIL', 254);
define('AGUMAIL_SIGNUP_MAX_PHONE', 40);
define('AGUMAIL_SIGNUP_MAX_FORM_ID', 100);
define('AGUMAIL_SIGNUP_MAX_FORM_NAME', 200);
define('AGUMAIL_SIGNUP_MAX_CONSENT', 1000);
define('AGUMAIL_SIGNUP_MAX_IP', 64);

add_action('init', 'agumail_signups_register_admin');
add_action('agumail_drain_sent', 'agumail_signups_sent', 10, 3);

/** The form plugins AguMail takes signups from, by the name the API knows them by. */
function agumail_signup_builders() {
    return array('elementor', 'cf7', 'wpforms');
}

// ─────────────────────────────────────────────────────────────────────────────
// The record.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The record AguMail takes for one signup, or null when it is not one (no
 * valid address, an unknown builder, a form without an id). $form is
 * {id, name}; $data has any of email, first_name, last_name, full_name, phone,
 * tags (a comma-separated list or an array), double_opt_in, consent_text and
 * page_url. Every value is clamped to what the API allows, and one it would
 * refuse whole (a page address too long to keep, something that is not an IP
 * address) is left out. Writes nothing.
 */
function agumail_signup_record($builder, array $form, array $data) {
    if (!is_string($builder) || !in_array($builder, agumail_signup_builders(), true)) {
        return null;
    }
    $email = agumail_normalize_email(agumail_signup_value($data, 'email'));
    if ($email === '' || strlen($email) > AGUMAIL_SIGNUP_MAX_EMAIL) {
        return null;
    }
    $form_id = agumail_clamp(agumail_signup_value($form, 'id'), AGUMAIL_SIGNUP_MAX_FORM_ID);
    if ($form_id === '') {
        return null;
    }
    $form_name = agumail_clamp(agumail_signup_value($form, 'name'), AGUMAIL_SIGNUP_MAX_FORM_NAME);

    $first = agumail_signup_value($data, 'first_name');
    $last = agumail_signup_value($data, 'last_name');
    if (trim($first) === '' && trim($last) === '') {
        list($first, $last) = agumail_signup_split_name(agumail_signup_value($data, 'full_name'));
    }

    $record = array('email' => $email);
    $optional = array(
        'firstName' => agumail_clamp($first, AGUMAIL_MAX_NAME),
        'lastName'  => agumail_clamp($last, AGUMAIL_MAX_NAME),
        'phone'     => agumail_clamp(agumail_signup_value($data, 'phone'), AGUMAIL_SIGNUP_MAX_PHONE),
    );
    foreach ($optional as $field => $value) {
        if ($value !== '') {
            $record[$field] = $value;
        }
    }
    $record['tags'] = agumail_signup_parse_tags(isset($data['tags']) ? $data['tags'] : null);
    // Read like a ticked box, so a form plugin's "yes" or "1" counts.
    $record['doubleOptIn'] = isset($data['double_opt_in']) && agumail_signup_consent_ticked($data['double_opt_in']);
    $record['form'] = array('builder' => $builder, 'id' => $form_id, 'name' => $form_name !== '' ? $form_name : $form_id);

    $page = agumail_clamp_url(agumail_signup_value($data, 'page_url'));
    if ($page) {
        $record['pageUrl'] = $page;
    }
    $ip = trim((string) agumail_capture_address());
    if ($ip !== '' && strlen($ip) <= AGUMAIL_SIGNUP_MAX_IP && filter_var($ip, FILTER_VALIDATE_IP)) {
        $record['ip'] = $ip;
    }
    $consent = agumail_clamp(agumail_signup_value($data, 'consent_text'), AGUMAIL_SIGNUP_MAX_CONSENT);
    if ($consent !== '') {
        $record['consentText'] = $consent;
    }
    $record['occurredAt'] = agumail_iso_date(agumail_time());
    return $record;
}

/** A value of $data as a string, or '' when it is missing or not a plain value. */
function agumail_signup_value(array $data, $key) {
    return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
}

/** A full name as [first word, the rest]. */
function agumail_signup_split_name($name) {
    $parts = preg_split('/\s+/u', trim((string) $name), 2);
    return array(isset($parts[0]) ? $parts[0] : '', isset($parts[1]) ? $parts[1] : '');
}

/** Tags from a comma-separated list or an array: trimmed, empty ones dropped, no repeats, at most 20 of at most 60 characters. */
function agumail_signup_parse_tags($tags) {
    if (is_string($tags)) {
        $tags = explode(',', $tags);
    }
    if (!is_array($tags)) {
        return array();
    }
    return agumail_clamp_list(array_filter($tags, 'is_scalar'), AGUMAIL_MAX_TAGS, AGUMAIL_MAX_TAG);
}

/**
 * Whether a checkbox's submitted value means ticked. Form plugins post the
 * box's value (on, 1, yes, or its label) when ticked and nothing when not; a
 * list is ticked when any of its values is.
 */
function agumail_signup_consent_ticked($value) {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (agumail_signup_consent_ticked($item)) {
                return true;
            }
        }
        return false;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return $value != 0;
    }
    if (!is_string($value)) {
        return false;
    }
    return !in_array(strtolower(trim($value)), array('', '0', 'no', 'off', 'false'), true);
}

/**
 * What a form field is for, guessed from its id and label: first_name,
 * last_name, full_name, phone, or '' when neither says. For forms whose
 * merchant mapped nothing.
 */
function agumail_signup_field_role($id, $label = '') {
    foreach (array($label, $id) as $text) {
        $text = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $text)));
        if ($text === '') {
            continue;
        }
        if (preg_match('/\b(first|given)\s?name\b|^(fname|first)$/', $text)) {
            return 'first_name';
        }
        if (preg_match('/\b(last|family|sur)\s?name\b|^(lname|last)$/', $text)) {
            return 'last_name';
        }
        if (preg_match('/^(your )?(full )?name$|^fullname$/', $text)) {
            return 'full_name';
        }
        if (preg_match('/\b(phone|telephone|tel|mobile|cell)\b/', $text)) {
            return 'phone';
        }
    }
    return '';
}

/**
 * Which field of a submitted form plays each part: email, first_name,
 * last_name, full_name, phone and consent, as part => field id. $fields is a
 * list of {id, type, label, value, required}, with type the form plugin's own
 * field type put in AguMail's words (email, phone, consent for a consent box,
 * anything else as it is). $chosen holds the parts the merchant picked, part
 * => field id; every other part is found here:
 *   email       the first email field with a value
 *   names       by id or label ("First name", "lname", "Your name")
 *   phone       the first phone field, else one called a phone
 *   consent     the first optional consent box. A required one is the terms
 *               or privacy box: the form cannot be sent without it, so it says
 *               nothing about marketing, and its words are not the consent.
 */
function agumail_signup_detect_fields(array $fields, array $chosen) {
    $by_id = array();
    foreach ($fields as $field) {
        $by_id[(string) $field['id']] = $field;
    }
    $parts = array();
    foreach ($chosen as $part => $id) {
        if (is_scalar($id) && (string) $id !== '' && isset($by_id[(string) $id])) {
            $parts[$part] = (string) $id;
        }
    }
    $filled = function ($field) {
        return is_scalar($field['value']) ? trim((string) $field['value']) !== '' : !empty($field['value']);
    };
    $typed = array('email', 'phone', 'consent');
    // By type first: a phone field wins over a text field called a phone. A
    // consent box counts empty too, since an unticked box is its answer.
    foreach ($fields as $field) {
        $type = (string) $field['type'];
        if (!in_array($type, $typed, true) || isset($parts[$type])) {
            continue;
        }
        if ($type === 'consent' ? empty($field['required']) : $filled($field)) {
            $parts[$type] = (string) $field['id'];
        }
    }
    foreach ($fields as $field) {
        $id = (string) $field['id'];
        if (in_array((string) $field['type'], $typed, true) || in_array($id, $parts, true) || !$filled($field)) {
            continue;
        }
        $role = agumail_signup_field_role($id, isset($field['label']) ? $field['label'] : '');
        if ($role !== '' && !isset($parts[$role])) {
            $parts[$role] = $id;
        }
    }
    return $parts;
}

/** Where a signup is kept in the outbox: builder, form and address. */
function agumail_signup_key(array $record) {
    return $record['form']['builder'] . ':' . $record['form']['id'] . ':' . $record['email'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Queueing.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Queues one signup from a form plugin's submission: one outbox write, no
 * call. Returns whether it was queued. Never throws, so the visitor's own
 * submission always goes through; nothing is queued while the store is not
 * connected. $form and $data are as agumail_signup_record() takes them.
 */
function agumail_signup_enqueue($builder, array $form, array $data) {
    try {
        if (!agumail_has_api_key()) {
            return false;
        }
        $record = agumail_signup_record($builder, $form, $data);
        return $record ? (bool) agumail_enqueue('signup', agumail_signup_key($record), $record) : false;
    } catch (Throwable $e) {
        agumail_log('error', 'A form signup could not be queued: ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Sending. Signups go after contacts and before products, built from the
// record queued with them.
// ─────────────────────────────────────────────────────────────────────────────

agumail_register_builder('signup', array(
    'endpoint'   => '/api/v1/signups',
    'collection' => 'signups',
    'build'      => 'agumail_build_from_payload',
    'phase'      => 15,
    'batch'      => 100,
));

/** AguMail answers {received, overCapacity, limited}: the signups it did not add are worth a line in the log. */
function agumail_signups_sent($endpoint, $count, $response) {
    if ($endpoint !== '/api/v1/signups' || !is_array($response) || !isset($response['body']) || !is_array($response['body'])) {
        return;
    }
    $body = $response['body'];
    $over = isset($body['overCapacity']) ? (int) $body['overCapacity'] : 0;
    $limited = isset($body['limited']) ? (int) $body['limited'] : 0;
    if ($over > 0) {
        agumail_log('warning', sprintf('AguMail did not add %d form signup(s): the account has reached its contact limit.', $over));
    }
    if ($limited > 0) {
        agumail_log('warning', sprintf('AguMail did not add %d form signup(s): too many signups came from one visitor in a minute.', $limited));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The Form plugins section: where to switch AguMail on in each form plugin
// that is active. It only reads.
// ─────────────────────────────────────────────────────────────────────────────

/** The active form plugins AguMail works with, as builder names. */
function agumail_signups_active_builders() {
    $active = array(
        'elementor' => defined('ELEMENTOR_PRO_VERSION'),
        'cf7'       => defined('WPCF7_VERSION'),
        'wpforms'   => defined('WPFORMS_VERSION'),
    );
    return array_keys(array_filter($active));
}

function agumail_signups_register_admin() {
    if (agumail_signups_active_builders()) {
        agumail_admin_register_section('signups', __('Form plugins', 'agumail'), 'agumail_signups_render_section', 65, array(
            'icon'        => 'admin-plugins',
            'description' => __('Signups from the form plugins on this site.', 'agumail'),
        ));
    }
}

function agumail_signups_render_section() {
    $where = array(
        'elementor' => __('Elementor Pro: edit the form, add AguMail under Actions After Submit, then set its tags and fields in the AguMail section.', 'agumail'),
        'cf7'       => __('Contact Form 7: edit the form, open the AguMail tab and tick Send signups to AguMail.', 'agumail'),
        'wpforms'   => __('WPForms: edit the form, open Settings > AguMail and switch on Send signups to AguMail.', 'agumail'),
    );
    echo '<p>' . esc_html__('Signups from your form plugins can go to AguMail. Switch it on in each form:', 'agumail') . '</p><ul class="ul-disc">';
    foreach (agumail_signups_active_builders() as $builder) {
        echo '<li>' . esc_html($where[$builder]) . '</li>';
    }
    echo '</ul>';
    if (!agumail_has_api_key()) {
        echo '<p class="description">' . esc_html__('Connect to AguMail first. Until then, signups from these forms are not sent.', 'agumail') . '</p>';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Recognising the visitor after a signup. With website tracking on, the
// adapter adds the address and its identify signature (tracking.php) to the
// form plugin's own AJAX answer, and a few lines next to the AguMail script
// hand them to it, so the visitor's product views count from then on, as
// after a checkout email capture. It costs no write and no call. Store staff
// trying a form are not recognised, and neither is a visitor past the capture
// endpoint's own limits (carts.php).
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', 'agumail_signups_enqueue_identify', 20);

/** {email, sig} for the page to pass to the AguMail script, or null when tracking is off, the visitor may not be signed for, or there is no signature. */
function agumail_signup_identity($email) {
    if (!agumail_tracking_on() || !agumail_tracking_site() || agumail_is_staff() || !agumail_signup_may_sign()) {
        return null;
    }
    $email = agumail_identify_email(agumail_normalize_email($email));
    $sig = $email !== '' ? agumail_identify_signature($email) : '';
    return $sig !== '' ? array('email' => $email, 'sig' => $sig) : null;
}

/**
 * Whether the page may be handed a signature, by the limits the capture
 * endpoint signs within: a WooCommerce session that has already changed its
 * email AGUMAIL_CAPTURE_LIMIT times gets none, and neither does an address
 * agumail_capture_address_allows() turns away. Both only read (the address
 * check counts in the object cache when there is a persistent one); a signup
 * never adds to the session's own count, which would save the session.
 */
function agumail_signup_may_sign() {
    if (function_exists('WC') && WC() && !empty(WC()->session)
        && (int) WC()->session->get(AGUMAIL_SESSION_CAPTURES, 0) >= AGUMAIL_CAPTURE_LIMIT) {
        return false;
    }
    return agumail_capture_address_allows();
}

/**
 * The identity of the signup this request queued, for a form plugin whose
 * answer is built after its submit hook ran (Contact Form 7, WPForms). A call
 * with an identity keeps it; one without reads it back.
 */
function agumail_signup_response_identity($identity = null) {
    static $kept = null;
    if ($identity !== null) {
        $kept = $identity;
    }
    return $kept;
}

/** The listener goes next to the AguMail script, on pages where it runs and a form plugin is active. */
function agumail_signups_enqueue_identify() {
    if (is_admin() || !agumail_signups_active_builders() || !wp_script_is(AGUMAIL_SITE_HANDLE, 'enqueued')) {
        return;
    }
    wp_add_inline_script(AGUMAIL_SITE_HANDLE, agumail_signups_identify_js(), 'after');
}

/**
 * Listens for each form plugin's own success event and passes {email, sig}
 * from its answer to window.agumail.identify, once the script has loaded:
 *   Elementor Pro    jQuery "submit_success" (event, response.data), data.agumail
 *   Contact Form 7   "wpcf7mailsent" / "wpcf7mailfailed", detail.apiResponse.agumail
 *   WPForms          jQuery "wpformsAjaxSubmitSuccess" (event, response), data.agumail
 * The jQuery events are bound once the page has loaded, since a theme may
 * load jQuery in the footer.
 */
function agumail_signups_identify_js() {
    return '(function(){var s=document.getElementById("' . AGUMAIL_SITE_HANDLE . '-js");'
        . 'function identify(d){if(!d||typeof d.email!=="string"||typeof d.sig!=="string")return;'
        . 'function go(){if(window.agumail&&window.agumail.identify){window.agumail.identify(d.email,d.sig);}}'
        . 'if(window.agumail&&window.agumail.identify){go();}else if(s){s.addEventListener("load",go);}}'
        . 'function cf7(e){identify(e.detail&&e.detail.apiResponse&&e.detail.apiResponse.agumail);}'
        . 'document.addEventListener("wpcf7mailsent",cf7);document.addEventListener("wpcf7mailfailed",cf7);'
        . 'function jq(){var $=window.jQuery;if(!$)return;'
        . '$(document).on("submit_success",function(e,r){identify(r&&r.data&&r.data.agumail);});'
        . '$(document).on("wpformsAjaxSubmitSuccess",function(e,r){identify(r&&r.data&&r.data.agumail);});}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",jq);}else{jq();}})();';
}
