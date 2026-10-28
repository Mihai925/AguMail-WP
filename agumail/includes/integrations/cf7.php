<?php
// Contact Form 7: an AguMail tab in the form editor, next to Mail and
// Messages.
//
// The tab switches AguMail on for the form and sets its tags, double opt-in
// and, if the merchant likes, which form tag is the email, first name, last
// name, full name, phone and consent box; "Detect automatically" finds them
// by type and name (signups.php). The settings are a property of the contact
// form, which CF7 keeps with the form itself (post meta _agumail_signup).
//
// A submission is queued when CF7 sent its mail or tried to (mail_sent or
// mail_failed), never in demo mode: the same rule as CF7's own Brevo module.
// An acceptance box with the option consent_for:agumail is the consent box,
// as consent_for:sendinblue is for Brevo; an unticked one means no signup.
// Without one, the first optional acceptance box is: a required one (CF7's
// default) is the terms box, which every sent form has ticked.
// With tracking on, the answer CF7 sends the browser also says who signed up,
// for the AguMail script (signups.php).

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_CF7_PROPERTY', 'agumail_signup');

add_filter('wpcf7_pre_construct_contact_form_properties', 'agumail_cf7_register_property', 10, 2);
add_filter('wpcf7_editor_panels', 'agumail_cf7_editor_panels');
add_action('wpcf7_save_contact_form', 'agumail_cf7_save', 10, 3);
add_action('wpcf7_submit', 'agumail_cf7_submit', 10, 2);
add_filter('wpcf7_feedback_response', 'agumail_cf7_feedback_response', 10, 2);

/** The parts the tab can point at a form tag, with their labels. */
function agumail_cf7_parts() {
    return array(
        'email'      => __('Email', 'agumail'),
        'first_name' => __('First name', 'agumail'),
        'last_name'  => __('Last name', 'agumail'),
        'full_name'  => __('Full name', 'agumail'),
        'phone'      => __('Phone', 'agumail'),
        'consent'    => __('Consent', 'agumail'),
    );
}

function agumail_cf7_register_property($properties, $contact_form = null) {
    return (array) $properties + array(AGUMAIL_CF7_PROPERTY => array());
}

/** The form's settings: enabled ('1' or ''), tags, double_opt_in ('1' or '') and fields, part => tag name. */
function agumail_cf7_settings($contact_form) {
    $saved = is_object($contact_form) && method_exists($contact_form, 'prop') ? $contact_form->prop(AGUMAIL_CF7_PROPERTY) : array();
    $saved = is_array($saved) ? $saved : array();
    $settings = array_merge(array('enabled' => '', 'tags' => '', 'double_opt_in' => '', 'fields' => array()), $saved);
    $settings['fields'] = array_merge(array_fill_keys(array_keys(agumail_cf7_parts()), ''), is_array($settings['fields']) ? $settings['fields'] : array());
    return $settings;
}

// ─────────────────────────────────────────────────────────────────────────────
// The tab.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_cf7_editor_panels($panels) {
    $panels = is_array($panels) ? $panels : array();
    $panels['agumail-panel'] = array('title' => __('AguMail', 'agumail'), 'callback' => 'agumail_cf7_render_panel');
    return $panels;
}

/** The form tags a part can point at: every named field but the submit button. */
function agumail_cf7_named_tags($contact_form) {
    $tags = array();
    foreach (is_object($contact_form) && method_exists($contact_form, 'scan_form_tags') ? (array) $contact_form->scan_form_tags() : array() as $tag) {
        if (is_object($tag) && !empty($tag->name) && $tag->basetype !== 'submit') {
            $tags[] = $tag;
        }
    }
    return $tags;
}

function agumail_cf7_render_panel($contact_form) {
    $settings = agumail_cf7_settings($contact_form);
    $tags = agumail_cf7_named_tags($contact_form);
    echo '<h2>' . esc_html__('AguMail', 'agumail') . '</h2>';
    echo '<fieldset><legend>' . esc_html__('Send the people who submit this form to AguMail as subscribers.', 'agumail') . '</legend>';
    if (!agumail_has_api_key()) {
        echo '<p><strong>' . esc_html__('Connect this site to AguMail first, on the AguMail page in your admin menu. Until then, signups from this form are not sent.', 'agumail') . '</strong> '
            . '<a href="' . esc_url(agumail_admin_page_url()) . '">' . esc_html__('Open AguMail', 'agumail') . '</a></p>';
    }
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('AguMail', 'agumail') . '</th><td><label><input type="checkbox" name="agumail-signup[enabled]" value="1"'
        . checked($settings['enabled'], '1', false) . ' /> ' . esc_html__('Send signups to AguMail', 'agumail') . '</label></td></tr>';
    echo '<tr><th scope="row"><label for="agumail-signup-tags">' . esc_html__('Tags', 'agumail') . '</label></th><td>'
        . '<input type="text" id="agumail-signup-tags" name="agumail-signup[tags]" class="large-text" value="' . esc_attr((string) $settings['tags']) . '" />'
        . '<p class="description">' . esc_html__('Separate tags with commas. New tags are created.', 'agumail') . '</p></td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Double opt-in', 'agumail') . '</th><td><label><input type="checkbox" name="agumail-signup[double_opt_in]" value="1"'
        . checked($settings['double_opt_in'], '1', false) . ' /> ' . esc_html__('New subscribers confirm by email first', 'agumail') . '</label></td></tr>';
    foreach (agumail_cf7_parts() as $part => $label) {
        $id = 'agumail-signup-' . str_replace('_', '-', $part);
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>'
            . '<select id="' . esc_attr($id) . '" name="agumail-signup[fields][' . esc_attr($part) . ']">'
            . '<option value="">' . esc_html__('Detect automatically', 'agumail') . '</option>';
        foreach ($tags as $tag) {
            echo '<option value="' . esc_attr($tag->name) . '"' . selected($settings['fields'][$part], $tag->name, false) . '>' . esc_html($tag->name) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">' . esc_html__('Save the form to see new fields here.', 'agumail') . '</p>';
    echo '</fieldset>';
}

/**
 * Keeps the tab's settings on the form. Only when the tab was posted: a form
 * saved another way (the REST API, a copy) keeps what it had. CF7 has checked
 * the nonce and the merchant's capability before it saves.
 */
function agumail_cf7_save($contact_form, $args = array(), $context = 'save') {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by Contact Form 7 before it saves the form.
    if (!isset($_POST['agumail-signup']) || !is_array($_POST['agumail-signup']) || !is_object($contact_form) || !method_exists($contact_form, 'set_properties')) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
    $posted = map_deep(wp_unslash($_POST['agumail-signup']), 'sanitize_text_field');
    $fields = array();
    foreach (array_keys(agumail_cf7_parts()) as $part) {
        $name = isset($posted['fields'][$part]) && is_string($posted['fields'][$part]) ? trim($posted['fields'][$part]) : '';
        // A form tag name, as CF7 allows them.
        $fields[$part] = preg_match('/^[A-Za-z][-A-Za-z0-9_:.]*$/', $name) ? $name : '';
    }
    $contact_form->set_properties(array(AGUMAIL_CF7_PROPERTY => array(
        'enabled'       => !empty($posted['enabled']) ? '1' : '',
        'tags'          => isset($posted['tags']) && is_string($posted['tags']) ? sanitize_text_field($posted['tags']) : '',
        'double_opt_in' => !empty($posted['double_opt_in']) ? '1' : '',
        'fields'        => $fields,
    )));
}

// ─────────────────────────────────────────────────────────────────────────────
// Submissions.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_cf7_submit($contact_form, $result) {
    try {
        if (!is_object($contact_form) || !is_array($result) || !empty($result['demo_mode'])
            || (method_exists($contact_form, 'in_demo_mode') && $contact_form->in_demo_mode())) {
            return;
        }
        if (empty($result['status']) || !in_array($result['status'], array('mail_sent', 'mail_failed'), true)) {
            return;
        }
        $settings = agumail_cf7_settings($contact_form);
        if ($settings['enabled'] !== '1' || !class_exists('WPCF7_Submission')) {
            return;
        }
        $submission = WPCF7_Submission::get_instance();
        $signup = $submission ? agumail_cf7_signup($contact_form, $submission, $settings) : null;
        if ($signup && agumail_signup_enqueue('cf7', $signup['form'], $signup['data'])) {
            $identity = agumail_signup_identity($signup['data']['email']);
            if ($identity) {
                agumail_signup_response_identity($identity);
            }
        }
    } catch (Throwable $e) {
        agumail_log('error', 'A Contact Form 7 signup was not queued: ' . $e->getMessage());
    }
}

/** The signup in a submission, or null when there is none: no address, or a consent box left unticked. */
function agumail_cf7_signup($contact_form, $submission, array $settings) {
    $types = array('email' => 'email', 'tel' => 'phone', 'acceptance' => 'consent');
    $list = array();
    $by_name = array();
    foreach (agumail_cf7_named_tags($contact_form) as $tag) {
        $by_name[$tag->name] = $tag;
        $list[] = array(
            'id'       => $tag->name,
            'type'     => isset($types[$tag->basetype]) ? $types[$tag->basetype] : $tag->basetype,
            'label'    => '',
            'value'    => agumail_cf7_posted($submission, $tag->name),
            // CF7 requires an acceptance box to be ticked unless it is marked optional.
            'required' => $tag->basetype === 'acceptance' && !(method_exists($tag, 'has_option') && $tag->has_option('optional')),
        );
    }
    $chosen = array_filter($settings['fields']);
    if (!isset($chosen['consent'])) {
        foreach ($by_name as $name => $tag) {
            if ($tag->basetype === 'acceptance' && method_exists($tag, 'has_option') && $tag->has_option('consent_for:agumail')) {
                $chosen['consent'] = $name;
                break;
            }
        }
    }
    $parts = agumail_signup_detect_fields($list, $chosen);
    if (!isset($parts['email'])) {
        return null;
    }
    $consent_text = '';
    if (isset($parts['consent'])) {
        $tag = $by_name[$parts['consent']];
        $ticked = agumail_signup_consent_ticked(agumail_cf7_posted($submission, $tag->name));
        // An inverted box is ticked to say no.
        if (method_exists($tag, 'has_option') && $tag->has_option('invert')) {
            $ticked = !$ticked;
        }
        if (!$ticked) {
            return null;
        }
        // The words next to the box, as CF7 records them for its own consent log.
        $consent_text = trim((string) (!empty($tag->content) ? $tag->content : reset($tag->values)));
    }
    $value = function ($part) use ($parts, $submission) {
        return isset($parts[$part]) ? agumail_cf7_posted($submission, $parts[$part]) : '';
    };
    $page = method_exists($submission, 'get_meta') ? $submission->get_meta('url') : '';
    return array(
        'form' => array('id' => (string) $contact_form->id(), 'name' => (string) $contact_form->title()),
        'data' => array(
            'email'         => $value('email'),
            'first_name'    => $value('first_name'),
            'last_name'     => $value('last_name'),
            'full_name'     => $value('full_name'),
            'phone'         => $value('phone'),
            'tags'          => $settings['tags'],
            'double_opt_in' => $settings['double_opt_in'] === '1',
            'consent_text'  => $consent_text,
            'page_url'      => is_string($page) ? $page : '',
        ),
    );
}

/** What the visitor entered in a field, as one trimmed string (the first value of a list). */
function agumail_cf7_posted($submission, $name) {
    $value = $submission->get_posted_data($name);
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? trim((string) $value) : '';
}

/** Who signed up, for the AguMail script, in the answer to a submission that queued one. */
function agumail_cf7_feedback_response($response, $result = array()) {
    $identity = agumail_signup_response_identity();
    if ($identity && is_array($response)) {
        $response['agumail'] = $identity;
    }
    return $response;
}
