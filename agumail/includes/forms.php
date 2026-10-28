<?php
// Forms: the [agumail_form] shortcode, the agumail/form block, the site-wide
// popup and the forms cache.
//
// All three print the same inert placeholder,
//   <script type="text/plain" data-agumail-form="slug" data-mode="popup" ...></script>
// which AguMail's forms loader (embedScript in app/src/forms/publicApi.ts)
// finds by script[data-agumail-form] whatever its type, and turns into the
// form. The loader itself is enqueued once per page, in the footer.
//
// Pages are built from stored options only: the config and the forms cache.
// The cache (option agumail_forms_cache) is filled from GET /api/v1/forms by
// daily housekeeping and by the Refresh button, never while a page is built.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_FORMS_HANDLE', 'agumail-forms');
define('AGUMAIL_FORM_BLOCK_HANDLE', 'agumail-form-block');

add_action('init', 'agumail_forms_init');
add_action('rest_api_init', 'agumail_forms_register_rest');
add_action('wp_footer', 'agumail_forms_render_popup', 5);
add_filter('script_loader_tag', 'agumail_forms_script_tag', 10, 3);
agumail_add_background_action('agumail_housekeeping', 'agumail_forms_housekeeping', 20, 0);

function agumail_forms_init() {
    add_shortcode('agumail_form', 'agumail_form_shortcode');
    agumail_forms_register_block();
    agumail_admin_register_section('forms', __('Forms', 'agumail'), 'agumail_forms_render_section', 60, array(
        'icon'        => 'feedback',
        'description' => __('Signup forms you publish in AguMail.', 'agumail'),
    ));
    agumail_admin_register_action('forms_refresh', 'agumail_forms_refresh_action');
    agumail_admin_register_action('forms_save_popup', 'agumail_forms_save_popup', array(
        'agumail_popup_slug'      => 'text',
        'agumail_popup_trigger'   => 'key',
        'agumail_popup_delay'     => 'text',
        'agumail_popup_scroll'    => 'text',
        'agumail_popup_frequency' => 'text',
    ));
}

// ─────────────────────────────────────────────────────────────────────────────
// The placeholder.
// ─────────────────────────────────────────────────────────────────────────────

/** [agumail_form slug="x" mode="inline|popup" delay="5" scroll="50" exit_intent="yes" frequency_days="7"] */
function agumail_form_shortcode($atts = array()) {
    $atts = shortcode_atts(array(
        'slug'           => '',
        'mode'           => 'inline',
        'delay'          => '',
        'scroll'         => '',
        'exit_intent'    => '',
        'frequency_days' => '',
    ), is_array($atts) ? $atts : array(), 'agumail_form');
    return agumail_form_render($atts);
}

/**
 * The placeholder for one form, or '' when there is no form to show or no
 * loader to show it. A popup trigger that was not asked for is left out: the
 * loader reads data-delay and data-exit-intent by their presence.
 */
function agumail_form_render(array $args) {
    $slug = agumail_forms_clean_slug(isset($args['slug']) ? $args['slug'] : '');
    $loader = agumail_forms_loader_url();
    if ($slug === '' || $loader === '') {
        return '';
    }
    $mode = isset($args['mode']) && $args['mode'] === 'popup' ? 'popup' : 'inline';
    $attributes = array('data-agumail-form' => $slug, 'data-mode' => $mode);
    if ($mode === 'popup') {
        $delay = agumail_forms_number(isset($args['delay']) ? $args['delay'] : '', 0, 3600);
        if ($delay !== null) {
            $attributes['data-delay'] = $delay;
        }
        $scroll = agumail_forms_number(isset($args['scroll']) ? $args['scroll'] : '', 0, 100);
        if ($scroll) {
            $attributes['data-scroll'] = $scroll;
        }
        if (agumail_forms_yes(isset($args['exit_intent']) ? $args['exit_intent'] : '')) {
            $attributes['data-exit-intent'] = 'yes';
        }
        // 1 to 365 days, as the loader itself clamps it (it reads 0 as a day).
        $frequency = agumail_forms_number(isset($args['frequency_days']) ? $args['frequency_days'] : '', 1, 365);
        if ($frequency !== null) {
            $attributes['data-frequency-days'] = $frequency;
        }
    }
    // Inline or as a popup, the form is in the page now: the site-wide popup does not repeat it.
    $placed = &agumail_forms_inline_slugs();
    $placed[$slug] = true;
    agumail_forms_enqueue_loader($loader);

    $html = '<script type="text/plain"';
    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
    }
    $html .= '></script>';
    // An inline form takes its place in the content; a popup takes none.
    return $mode === 'inline' ? '<div class="agumail-form">' . $html . '</div>' : $html;
}

/** The forms already placed in this page, inline or as a popup, so the site-wide popup does not repeat one. */
function &agumail_forms_inline_slugs() {
    static $slugs = array();
    return $slugs;
}

/** Form slugs are short letters and digits; anything else is not one. */
function agumail_forms_clean_slug($slug) {
    $slug = is_scalar($slug) ? trim((string) $slug) : '';
    return preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $slug) ? $slug : '';
}

/** A whole number clamped to [$min, $max], or null when none was given. */
function agumail_forms_number($value, $min, $max) {
    if (is_bool($value) || $value === null) {
        return null;
    }
    $value = is_string($value) ? trim($value) : $value;
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (int) max($min, min($max, round((float) $value)));
}

function agumail_forms_yes($value) {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), array('yes', 'true', '1', 'on'), true);
}

/**
 * The loader's address: from the config, else from the forms cache, and only
 * on AguMail's own hosts, whatever is stored. None once the store is not
 * connected.
 */
function agumail_forms_loader_url() {
    if (!agumail_has_api_key()) {
        return '';
    }
    foreach (array(agumail_config('formsLoaderUrl'), agumail_forms_cache()['loaderUrl']) as $url) {
        if (agumail_is_own_url($url)) {
            return $url;
        }
    }
    return '';
}

/** Once per page, in the footer, whatever number of forms the page has. */
function agumail_forms_enqueue_loader($url) {
    if (!wp_script_is(AGUMAIL_FORMS_HANDLE, 'enqueued')) {
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- AguMail's address, which carries its own version.
        wp_enqueue_script(AGUMAIL_FORMS_HANDLE, $url, array(), null, true);
    }
}

/** Cloudflare's Rocket Loader is told to leave the loader alone. */
function agumail_forms_script_tag($tag, $handle, $src = '') {
    if ($handle !== AGUMAIL_FORMS_HANDLE) {
        return $tag;
    }
    return agumail_script_tag_attributes($tag, $handle, array('data-cfasync' => 'false'));
}

// ─────────────────────────────────────────────────────────────────────────────
// The block.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_forms_register_block() {
    if (!function_exists('register_block_type')) {
        return;
    }
    wp_register_script(
        AGUMAIL_FORM_BLOCK_HANDLE,
        plugins_url('assets/js/block.js', AGUMAIL_FILE),
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch', 'wp-i18n'),
        AGUMAIL_VERSION,
        true
    );
    if (function_exists('wp_set_script_translations')) {
        wp_set_script_translations(AGUMAIL_FORM_BLOCK_HANDLE, 'agumail');
    }
    register_block_type('agumail/form', array(
        'api_version'     => 3,
        'editor_script'   => AGUMAIL_FORM_BLOCK_HANDLE,
        'render_callback' => 'agumail_form_block_render',
        'attributes'      => array(
            'slug'          => array('type' => 'string', 'default' => ''),
            'mode'          => array('type' => 'string', 'default' => 'inline'),
            'delay'         => array('type' => 'number'),
            'scroll'        => array('type' => 'number'),
            'exitIntent'    => array('type' => 'boolean', 'default' => false),
            'frequencyDays' => array('type' => 'number'),
        ),
    ));
}

/** The block renders exactly as the shortcode does. */
function agumail_form_block_render($attributes = array()) {
    $attributes = is_array($attributes) ? $attributes : array();
    $get = function ($key, $default = '') use ($attributes) {
        return isset($attributes[$key]) ? $attributes[$key] : $default;
    };
    return agumail_form_render(array(
        'slug'           => $get('slug'),
        'mode'           => $get('mode', 'inline'),
        'delay'          => $get('delay'),
        'scroll'         => $get('scroll'),
        'exit_intent'    => $get('exitIntent', false) ? 'yes' : '',
        'frequency_days' => $get('frequencyDays'),
    ));
}

/** The block's form picker reads this: the cached list, never AguMail itself. */
function agumail_forms_register_rest() {
    register_rest_route('agumail/v1', '/forms', array(
        'methods'             => 'GET',
        'callback'            => 'agumail_forms_rest_list',
        'permission_callback' => 'agumail_forms_rest_permission',
    ));
}

function agumail_forms_rest_permission() {
    return current_user_can('edit_posts');
}

function agumail_forms_rest_list() {
    $forms = array();
    foreach (agumail_forms_cache()['forms'] as $form) {
        $forms[] = array('slug' => $form['slug'], 'name' => $form['name'], 'doubleOptIn' => $form['doubleOptIn']);
    }
    return array('forms' => $forms, 'ready' => agumail_forms_loader_url() !== '');
}

// ─────────────────────────────────────────────────────────────────────────────
// The site-wide popup.
// ─────────────────────────────────────────────────────────────────────────────

/** The popup setting, or null when there is none. */
function agumail_forms_popup() {
    $popup = get_option('agumail_popup', array());
    if (!is_array($popup) || agumail_forms_clean_slug(isset($popup['slug']) ? $popup['slug'] : '') === '') {
        return null;
    }
    return array_merge(array('trigger' => 'delay', 'delay' => 5, 'scroll' => 50, 'exitIntent' => false, 'frequencyDays' => 7), $popup);
}

/**
 * Every storefront page but the cart, checkout and account pages, and never
 * for a signed-in customer who already subscribed. The form must still be in
 * the cached list, so one unpublished in AguMail stops showing after the next
 * refresh, and not already in the page, placed by a shortcode or block.
 */
function agumail_forms_render_popup() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (function_exists('wp_is_json_request') && wp_is_json_request())) {
        return;
    }
    if ((function_exists('is_feed') && is_feed()) || agumail_forms_popup_excluded_page()) {
        return;
    }
    $popup = agumail_forms_popup();
    if (!$popup || !agumail_forms_find($popup['slug'])) {
        return;
    }
    $inline = &agumail_forms_inline_slugs();
    if (isset($inline[$popup['slug']])) {
        return;
    }
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), '_agumail_optin', true) === 'yes') {
        return;
    }
    $args = array('slug' => $popup['slug'], 'mode' => 'popup', 'frequency_days' => $popup['frequencyDays']);
    if ($popup['trigger'] === 'scroll') {
        $args['scroll'] = $popup['scroll'];
    } elseif ($popup['trigger'] === 'exit_intent') {
        $args['exit_intent'] = 'yes';
    } else {
        $args['delay'] = $popup['delay'];
    }
    echo agumail_form_render($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped attributes.
}

function agumail_forms_popup_excluded_page() {
    return (function_exists('is_cart') && is_cart())
        || (function_exists('is_checkout') && is_checkout())
        || (function_exists('is_account_page') && is_account_page());
}

// ─────────────────────────────────────────────────────────────────────────────
// The cache.
// ─────────────────────────────────────────────────────────────────────────────

/** ['loaderUrl' => string, 'forms' => [{slug, name, doubleOptIn, publishedAt}], 'fetchedAt' => int] */
function agumail_forms_cache() {
    $cache = get_option('agumail_forms_cache', array());
    $cache = is_array($cache) ? $cache : array();
    $forms = array();
    foreach (isset($cache['forms']) && is_array($cache['forms']) ? $cache['forms'] : array() as $form) {
        $form = agumail_forms_clean_form($form);
        if ($form) {
            $forms[] = $form;
        }
    }
    return array(
        'loaderUrl' => isset($cache['loaderUrl']) && is_string($cache['loaderUrl']) ? $cache['loaderUrl'] : '',
        'forms'     => $forms,
        'fetchedAt' => isset($cache['fetchedAt']) ? (int) $cache['fetchedAt'] : 0,
    );
}

function agumail_forms_find($slug) {
    foreach (agumail_forms_cache()['forms'] as $form) {
        if ($form['slug'] === $slug) {
            return $form;
        }
    }
    return null;
}

/** A form as the cache keeps it, or null when it has no usable slug. Names are kept as text and escaped when printed. */
function agumail_forms_clean_form($form) {
    if (!is_array($form)) {
        return null;
    }
    $slug = agumail_forms_clean_slug(isset($form['slug']) ? $form['slug'] : '');
    if ($slug === '') {
        return null;
    }
    $name = isset($form['name']) && is_scalar($form['name']) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $form['name'])) : '';
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 200, 'UTF-8');
    }
    return array(
        'slug'        => $slug,
        'name'        => $name !== '' ? $name : $slug,
        'doubleOptIn' => !empty($form['doubleOptIn']),
        'publishedAt' => isset($form['publishedAt']) && is_string($form['publishedAt']) ? $form['publishedAt'] : null,
    );
}

/** Fetches the published forms and keeps them; the old list stays when the fetch fails. Returns how many, or a WP_Error. */
function agumail_forms_refresh() {
    $response = agumail_api_request('GET', '/api/v1/forms');
    if (is_wp_error($response)) {
        return agumail_connect_unreachable($response);
    }
    $body = $response['body'];
    if ($response['code'] !== 200 || !is_array($body) || empty($body['ok']) || !isset($body['forms']) || !is_array($body['forms'])) {
        return agumail_connect_refusal($response);
    }
    $forms = array();
    foreach ($body['forms'] as $form) {
        $form = agumail_forms_clean_form($form);
        if ($form) {
            $forms[] = $form;
        }
    }
    $loader = isset($body['loaderUrl']) && agumail_is_own_url($body['loaderUrl']) ? $body['loaderUrl'] : '';
    update_option('agumail_forms_cache', array('loaderUrl' => $loader, 'forms' => $forms, 'fetchedAt' => time()));
    return count($forms);
}

function agumail_forms_housekeeping() {
    if (!agumail_has_api_key()) {
        return;
    }
    $result = agumail_forms_refresh();
    if (is_wp_error($result)) {
        agumail_log('warning', 'Could not refresh the list of forms: ' . $result->get_error_message());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The Forms section.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_forms_refresh_action() {
    if (!agumail_has_api_key()) {
        return new WP_Error('agumail_not_connected', __('Connect to AguMail first.', 'agumail'));
    }
    $count = agumail_forms_refresh();
    if (is_wp_error($count)) {
        return $count;
    }
    return $count === 0
        ? __('No published forms found. Publish a form in AguMail, then refresh again.', 'agumail')
        /* translators: %d: how many published forms were found */
        : sprintf(_n('Found %d published form.', 'Found %d published forms.', $count, 'agumail'), $count);
}

function agumail_forms_save_popup(array $request) {
    $slug = agumail_forms_clean_slug(isset($request['agumail_popup_slug']) ? $request['agumail_popup_slug'] : '');
    if ($slug === '') {
        delete_option('agumail_popup');
        return __('The site-wide popup is off.', 'agumail');
    }
    if (!agumail_forms_find($slug)) {
        return new WP_Error('agumail_unknown_form', __('That form is not in the list. Press Refresh to load your latest forms.', 'agumail'));
    }
    $trigger = isset($request['agumail_popup_trigger']) && in_array($request['agumail_popup_trigger'], array('delay', 'scroll', 'exit_intent'), true)
        ? $request['agumail_popup_trigger'] : 'delay';
    $delay = agumail_forms_number(isset($request['agumail_popup_delay']) ? $request['agumail_popup_delay'] : '', 0, 3600);
    $scroll = agumail_forms_number(isset($request['agumail_popup_scroll']) ? $request['agumail_popup_scroll'] : '', 1, 100);
    $frequency = agumail_forms_number(isset($request['agumail_popup_frequency']) ? $request['agumail_popup_frequency'] : '', 1, 365);
    update_option('agumail_popup', array(
        'slug'          => $slug,
        'trigger'       => $trigger,
        'delay'         => $delay === null ? 5 : $delay,
        'scroll'        => $scroll === null ? 50 : $scroll,
        'exitIntent'    => $trigger === 'exit_intent',
        'frequencyDays' => $frequency === null ? 7 : $frequency,
    ));
    return __('Popup saved.', 'agumail');
}

function agumail_forms_render_section() {
    $cache = agumail_forms_cache();
    if (!agumail_has_api_key() && !$cache['forms']) {
        echo '<p>' . esc_html__('Connect to AguMail to add your signup forms to this site.', 'agumail') . '</p>';
        return;
    }

    echo '<p>' . esc_html__('Add a form to any page with its shortcode or the AguMail form block, or show one as a popup across the site.', 'agumail') . '</p>';
    if ($cache['forms']) {
        echo '<table class="widefat striped agumail-forms-table"><thead><tr><th>' . esc_html__('Form', 'agumail') . '</th><th>'
            . esc_html__('Shortcode', 'agumail') . '</th></tr></thead><tbody>';
        foreach ($cache['forms'] as $form) {
            echo '<tr><td>' . esc_html($form['name']) . '</td><td><code>' . esc_html('[agumail_form slug="' . $form['slug'] . '"]') . '</code></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('No published forms yet. Publish a form in AguMail, then press Refresh.', 'agumail') . ' ';
        agumail_connect_app_button(agumail_admin_app_link('/dashboard/audience/forms/new', 'forms'), __('Create a form in AguMail', 'agumail'), 'link');
        echo '</p>';
    }
    echo '<div class="agumail-actions">';
    agumail_connect_button('forms_refresh', __('Refresh', 'agumail'), 'secondary');
    if ($cache['fetchedAt'] > 0) {
        echo '<span class="description">' . esc_html(sprintf(
            /* translators: %s: how long ago, such as "3 hours" */
            __('Last refreshed %s ago.', 'agumail'),
            human_time_diff($cache['fetchedAt'], time())
        )) . '</span>';
    }
    echo '</div>';

    if (!$cache['forms']) {
        return;
    }
    $popup = agumail_forms_popup();
    $current = $popup ? $popup : array('slug' => '', 'trigger' => 'delay', 'delay' => 5, 'scroll' => 50, 'frequencyDays' => 7);
    echo '<h3>' . esc_html__('Site-wide popup', 'agumail') . '</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('forms_save_popup');
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="agumail_popup_slug">' . esc_html__('Form', 'agumail') . '</label></th><td><select name="agumail_popup_slug" id="agumail_popup_slug">';
    echo '<option value=""' . selected($current['slug'], '', false) . '>' . esc_html__('No popup', 'agumail') . '</option>';
    foreach ($cache['forms'] as $form) {
        echo '<option value="' . esc_attr($form['slug']) . '"' . selected($current['slug'], $form['slug'], false) . '>' . esc_html($form['name']) . '</option>';
    }
    echo '</select></td></tr>';

    $triggers = array(
        'delay'       => __('After a few seconds', 'agumail'),
        'scroll'      => __('After scrolling part of the page', 'agumail'),
        'exit_intent' => __('When the visitor moves to leave (desktop only)', 'agumail'),
    );
    echo '<tr><th scope="row"><label for="agumail_popup_trigger">' . esc_html__('Show it', 'agumail') . '</label></th><td><select name="agumail_popup_trigger" id="agumail_popup_trigger">';
    foreach ($triggers as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($current['trigger'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="agumail_popup_delay">' . esc_html__('Delay', 'agumail') . '</label></th><td>'
        . '<input type="number" min="0" max="3600" name="agumail_popup_delay" id="agumail_popup_delay" class="small-text" value="' . esc_attr((string) $current['delay']) . '" /> '
        . esc_html__('seconds', 'agumail') . '</td></tr>';
    echo '<tr><th scope="row"><label for="agumail_popup_scroll">' . esc_html__('Scroll depth', 'agumail') . '</label></th><td>'
        . '<input type="number" min="1" max="100" name="agumail_popup_scroll" id="agumail_popup_scroll" class="small-text" value="' . esc_attr((string) $current['scroll']) . '" /> %</td></tr>';
    echo '<tr><th scope="row"><label for="agumail_popup_frequency">' . esc_html__('Show again after', 'agumail') . '</label></th><td>'
        . '<input type="number" min="1" max="365" name="agumail_popup_frequency" id="agumail_popup_frequency" class="small-text" value="' . esc_attr((string) $current['frequencyDays']) . '" /> '
        . esc_html__('days', 'agumail') . '</td></tr>';

    echo '</tbody></table>';
    echo '<p class="description">' . esc_html__('The popup is not shown on the cart, checkout or account pages, or to signed-in customers who already subscribed.', 'agumail') . '</p>';
    submit_button(__('Save popup', 'agumail'));
    echo '</form>';
}
