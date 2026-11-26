<?php
// The slice of WordPress the plugin touches, faked in memory.
//
// Every fake keeps its state in $GLOBALS['agm'] so a scenario can read back
// what the plugin did: hooks, options, transients, user meta, redirects, and a
// log of every write. Fakes for WooCommerce, HTTP, Action Scheduler, the
// logger, $wpdb and the plugin's store live in the other files of this folder.
//
// Names here are agm_* (helpers) or WordPress's own; never agumail_*, which
// belongs to the plugin.

define('ABSPATH', dirname(__DIR__) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

ini_set('error_log', '/dev/null');

$GLOBALS['agm'] = array(
    'hooks'       => array(),
    'did'         => array(),
    'current'     => array(),
    'options'     => array(),
    'transients'  => array(),
    'cache'       => array(),
    'writes'      => array(),
    'context'     => array('admin' => false, 'cron' => false, 'ajax' => false, 'rest' => false),
    'user_id'     => 0,
    'users'       => array(),
    'user_meta'   => array(),
    'caps'        => array(),
    'nonces_ok'   => true,
    'redirects'   => array(),
    'json'        => array(),
    'dies'        => array(),
    'menus'       => array(),
    'scripts'     => array(),
    'activation'  => array(),
    'deactivation' => array(),
    'request'     => 'boot',
);

/** A wp_die, wp_send_json or redirect-and-exit: stops the simulated request, as WordPress would. */
class Agm_Halt extends Exception {}

class WP_Error {
    public $errors = array();
    public $error_data = array();
    public function __construct($code = '', $message = '', $data = '') {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }
    public function get_error_code() { $codes = array_keys($this->errors); return $codes ? $codes[0] : ''; }
    public function get_error_codes() { return array_keys($this->errors); }
    public function get_error_message($code = '') {
        $code = $code === '' ? $this->get_error_code() : $code;
        return isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
    }
    public function get_error_data($code = '') {
        $code = $code === '' ? $this->get_error_code() : $code;
        return isset($this->error_data[$code]) ? $this->error_data[$code] : null;
    }
    public function add($code, $message, $data = '') { $this->errors[$code][] = $message; if ($data !== '') { $this->error_data[$code] = $data; } }
    public function has_errors() { return !empty($this->errors); }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

// ── Writes: every store-ish write is logged with the request it happened in ──

function agm_record_write($kind, $name, $detail = null) {
    $GLOBALS['agm']['writes'][] = array(
        'kind'    => $kind,
        'name'    => $name,
        'detail'  => $detail,
        'request' => $GLOBALS['agm']['request'],
    );
}

// ── Hooks ──
// As in WordPress: callbacks run by priority, then in the order they were
// added, and a callback receives only as many arguments as it asked for.

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['agm']['hooks'][$hook][$priority][] = array($callback, $accepted_args);
    ksort($GLOBALS['agm']['hooks'][$hook]);
    return true;
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    return add_filter($hook, $callback, $priority, $accepted_args);
}
function agm_callbacks($hook) {
    $list = array();
    foreach (isset($GLOBALS['agm']['hooks'][$hook]) ? $GLOBALS['agm']['hooks'][$hook] : array() as $callbacks) {
        foreach ($callbacks as $callback) {
            $list[] = $callback;
        }
    }
    return $list;
}
function apply_filters($hook, $value, ...$args) {
    $GLOBALS['agm']['current'][] = $hook;
    try {
        foreach (agm_callbacks($hook) as $entry) {
            $value = call_user_func_array($entry[0], array_slice(array_merge(array($value), $args), 0, $entry[1]));
        }
    } finally {
        array_pop($GLOBALS['agm']['current']);
    }
    return $value;
}
function apply_filters_ref_array($hook, $args) {
    return apply_filters($hook, ...$args);
}
function do_action($hook, ...$args) {
    $GLOBALS['agm']['did'][$hook] = (isset($GLOBALS['agm']['did'][$hook]) ? $GLOBALS['agm']['did'][$hook] : 0) + 1;
    $GLOBALS['agm']['current'][] = $hook;
    try {
        foreach (agm_callbacks($hook) as $entry) {
            call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
        }
    } finally {
        array_pop($GLOBALS['agm']['current']);
    }
}
function do_action_ref_array($hook, $args) { do_action($hook, ...$args); }
function remove_filter($hook, $callback, $priority = 10) {
    if (empty($GLOBALS['agm']['hooks'][$hook][$priority])) {
        return false;
    }
    foreach ($GLOBALS['agm']['hooks'][$hook][$priority] as $i => $entry) {
        if ($entry[0] === $callback) {
            unset($GLOBALS['agm']['hooks'][$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}
function remove_action($hook, $callback, $priority = 10) { return remove_filter($hook, $callback, $priority); }
function remove_all_actions($hook) { unset($GLOBALS['agm']['hooks'][$hook]); return true; }
function has_filter($hook, $callback = false) {
    if ($callback === false) {
        return count(agm_callbacks($hook)) > 0;
    }
    foreach (isset($GLOBALS['agm']['hooks'][$hook]) ? $GLOBALS['agm']['hooks'][$hook] : array() as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            if ($entry[0] === $callback) {
                return $priority;
            }
        }
    }
    return false;
}
function has_action($hook, $callback = false) { return has_filter($hook, $callback); }
function did_action($hook) { return isset($GLOBALS['agm']['did'][$hook]) ? $GLOBALS['agm']['did'][$hook] : 0; }
function doing_action($hook = null) {
    return $hook === null ? !empty($GLOBALS['agm']['current']) : in_array($hook, $GLOBALS['agm']['current'], true);
}
function doing_filter($hook = null) { return doing_action($hook); }
function current_filter() { $c = $GLOBALS['agm']['current']; return $c ? end($c) : false; }
function current_action() { return current_filter(); }
function register_activation_hook($file, $callback) { $GLOBALS['agm']['activation'][] = $callback; }
function register_deactivation_hook($file, $callback) { $GLOBALS['agm']['deactivation'][] = $callback; }
function register_uninstall_hook($file, $callback) {}
function __return_true() { return true; }
function __return_false() { return false; }
function __return_null() { return null; }
function __return_zero() { return 0; }
function __return_empty_array() { return array(); }
function __return_empty_string() { return ''; }

// ── Options, transients, object cache ──

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['agm']['options']) ? $GLOBALS['agm']['options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    if ($autoload !== null) {
        $GLOBALS['agm']['autoload'][$name] = $autoload;
    }
    if (array_key_exists($name, $GLOBALS['agm']['options']) && $GLOBALS['agm']['options'][$name] === $value) {
        return false;
    }
    $GLOBALS['agm']['options'][$name] = $value;
    agm_record_write('option', $name);
    return true;
}
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($name, $GLOBALS['agm']['options'])) {
        return false;
    }
    $GLOBALS['agm']['autoload'][$name] = $autoload;
    $GLOBALS['agm']['options'][$name] = $value;
    agm_record_write('option', $name);
    return true;
}
function delete_option($name) {
    if (!array_key_exists($name, $GLOBALS['agm']['options'])) {
        return false;
    }
    unset($GLOBALS['agm']['options'][$name]);
    agm_record_write('option', $name, 'delete');
    return true;
}
/** WordPress 6.4+: the autoload flag of an existing option, as $GLOBALS['agm']['autoload'] records it. */
function wp_set_option_autoload($option, $autoload) {
    if (!array_key_exists($option, $GLOBALS['agm']['options'])) {
        return false;
    }
    $GLOBALS['agm']['autoload'][$option] = $autoload;
    agm_record_write('option', $option, 'autoload');
    return true;
}
function get_transient($name) {
    if (!isset($GLOBALS['agm']['transients'][$name])) {
        return false;
    }
    list($value, $expires) = $GLOBALS['agm']['transients'][$name];
    if ($expires && $expires < time()) {
        unset($GLOBALS['agm']['transients'][$name]);
        return false;
    }
    return $value;
}
function set_transient($name, $value, $ttl = 0) {
    $GLOBALS['agm']['transients'][$name] = array($value, $ttl ? time() + $ttl : 0);
    agm_record_write('transient', $name);
    return true;
}
function delete_transient($name) {
    if (!isset($GLOBALS['agm']['transients'][$name])) {
        return false;
    }
    unset($GLOBALS['agm']['transients'][$name]);
    agm_record_write('transient', $name, 'delete');
    return true;
}
function get_site_transient($name) { return get_transient('site_' . $name); }
function set_site_transient($name, $value, $ttl = 0) { return set_transient('site_' . $name, $value, $ttl); }
function delete_site_transient($name) { return delete_transient('site_' . $name); }
function wp_cache_get($key, $group = '') { return isset($GLOBALS['agm']['cache'][$group][$key]) ? $GLOBALS['agm']['cache'][$group][$key] : false; }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { $GLOBALS['agm']['cache'][$group][$key] = $value; return true; }
function wp_cache_add($key, $value, $group = '', $ttl = 0) { if (wp_cache_get($key, $group) !== false) { return false; } return wp_cache_set($key, $value, $group); }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['agm']['cache'][$group][$key]); return true; }
function wp_cache_flush() { $GLOBALS['agm']['cache'] = array(); return true; }
/** As WordPress: these groups live for one request only (agm_request() empties them as a request starts). */
function wp_cache_add_non_persistent_groups($groups) {
    foreach ((array) $groups as $group) {
        $GLOBALS['agm']['cache_non_persistent'][$group] = true;
    }
}
/** Features the object cache supports; list unsupported ones in $GLOBALS['agm']['cache_unsupported']. */
function wp_cache_supports($feature) {
    return !in_array($feature, isset($GLOBALS['agm']['cache_unsupported']) ? $GLOBALS['agm']['cache_unsupported'] : array(), true);
}
function wp_cache_flush_runtime() { $GLOBALS['wp_object_cache']->flush_runtime(); return true; }
/** A persistent object cache that can drop its in-memory copies, as Redis Object Cache can. */
class Agm_Object_Cache {
    public function flush_runtime() {
        $GLOBALS['agm']['runtime_flushes'] = (isset($GLOBALS['agm']['runtime_flushes']) ? $GLOBALS['agm']['runtime_flushes'] : 0) + 1;
        return true;
    }
}
$GLOBALS['wp_object_cache'] = new Agm_Object_Cache();
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache($using = null) {
        return !empty($GLOBALS['agm']['ext_object_cache']);
    }
}

// Legacy WP-Cron, in case anything still asks.
function wp_next_scheduled($hook, $args = array()) { return false; }
function wp_schedule_event($time, $recurrence, $hook, $args = array()) { agm_record_write('cron', $hook); return true; }
function wp_schedule_single_event($time, $hook, $args = array()) { agm_record_write('cron', $hook); return true; }
function wp_clear_scheduled_hook($hook, $args = array()) { return 0; }

// ── Request context ──

function is_admin() { return !empty($GLOBALS['agm']['context']['admin']); }
function wp_doing_cron() { return !empty($GLOBALS['agm']['context']['cron']); }
function wp_doing_ajax() { return !empty($GLOBALS['agm']['context']['ajax']); }
function wp_is_json_request() { return !empty($GLOBALS['agm']['context']['rest']); }
function is_multisite() { return !empty($GLOBALS['agm']['sites']); }
/** Multisite: $GLOBALS['agm']['sites'] lists the blog ids; switching changes $wpdb->prefix. */
function get_sites($args = array()) { return isset($GLOBALS['agm']['sites']) ? $GLOBALS['agm']['sites'] : array(); }
function get_current_blog_id() { return isset($GLOBALS['agm']['blog_id']) ? $GLOBALS['agm']['blog_id'] : 1; }
function switch_to_blog($id) {
    $GLOBALS['agm']['blog_stack'][] = get_current_blog_id();
    $GLOBALS['agm']['blog_id'] = (int) $id;
    $GLOBALS['agm']['switched'][] = (int) $id;
    if (isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb']->set_blog((int) $id);
    }
    return true;
}
function restore_current_blog() {
    $id = array_pop($GLOBALS['agm']['blog_stack']);
    $GLOBALS['agm']['blog_id'] = $id ?: 1;
    if (isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb']->set_blog($GLOBALS['agm']['blog_id']);
    }
    return true;
}
function is_ssl() { return true; }

// ── Users ──

class WP_User {
    public $ID = 0;
    public $user_email = '';
    public $user_login = '';
    public $first_name = '';
    public $last_name = '';
    public $display_name = '';
    public $user_registered = '2026-01-01 00:00:00';
    public $roles = array('customer');
    public function __construct($id = 0, $email = '', $first = '', $last = '') {
        $this->ID = (int) $id;
        $this->user_email = $email;
        $this->user_login = $email;
        $this->first_name = $first;
        $this->last_name = $last;
    }
    public function exists() { return $this->ID > 0; }
    public function has_cap($cap) { return current_user_can($cap); }
    public function get($key) { return isset($this->$key) ? $this->$key : get_user_meta($this->ID, $key, true); }
}
function agm_add_user($id, $email, $first = '', $last = '') {
    $user = new WP_User($id, $email, $first, $last);
    $GLOBALS['agm']['users'][$id] = $user;
    return $user;
}
function agm_sign_in($id, $email = null) {
    if (!isset($GLOBALS['agm']['users'][$id])) {
        agm_add_user($id, $email ?: 'user' . $id . '@example.com');
    }
    $GLOBALS['agm']['user_id'] = (int) $id;
    return $GLOBALS['agm']['users'][$id];
}
function agm_sign_out() { $GLOBALS['agm']['user_id'] = 0; }
function is_user_logged_in() { return $GLOBALS['agm']['user_id'] > 0; }
function get_current_user_id() { return (int) $GLOBALS['agm']['user_id']; }
function wp_get_current_user() {
    $id = $GLOBALS['agm']['user_id'];
    return isset($GLOBALS['agm']['users'][$id]) ? $GLOBALS['agm']['users'][$id] : new WP_User(0, '');
}
function get_userdata($id) { return isset($GLOBALS['agm']['users'][$id]) ? $GLOBALS['agm']['users'][$id] : false; }
function get_user_by($field, $value) {
    foreach ($GLOBALS['agm']['users'] as $user) {
        if (($field === 'id' || $field === 'ID') && $user->ID === (int) $value) {
            return $user;
        }
        if ($field === 'email' && strtolower($user->user_email) === strtolower((string) $value)) {
            return $user;
        }
        if ($field === 'login' && $user->user_login === $value) {
            return $user;
        }
    }
    return false;
}
function get_users($args = array()) { return array_values($GLOBALS['agm']['users']); }
function get_user_meta($id, $key = '', $single = false) {
    if ($key === '') {
        return isset($GLOBALS['agm']['user_meta'][$id]) ? $GLOBALS['agm']['user_meta'][$id] : array();
    }
    $value = isset($GLOBALS['agm']['user_meta'][$id][$key]) ? $GLOBALS['agm']['user_meta'][$id][$key] : null;
    if ($value === null) {
        return $single ? '' : array();
    }
    return $single ? $value : array($value);
}
function update_user_meta($id, $key, $value, $prev = '') {
    $GLOBALS['agm']['user_meta'][$id][$key] = $value;
    agm_record_write('user_meta', $key);
    return true;
}
function add_user_meta($id, $key, $value, $unique = false) { return update_user_meta($id, $key, $value); }
function delete_user_meta($id, $key, $value = '') {
    unset($GLOBALS['agm']['user_meta'][$id][$key]);
    agm_record_write('user_meta', $key, 'delete');
    return true;
}
/**
 * Any user's capability: $GLOBALS['agm']['user_caps'][id][cap], else, for the
 * signed-in user, current_user_can(). As in WordPress, map_meta_cap turns a
 * capability into the ones the user must hold.
 */
function user_can($user, $cap, ...$args) {
    $id = is_object($user) ? (int) $user->ID : (int) $user;
    foreach (agm_map_meta_cap($cap, $id, $args) as $required) {
        if (isset($GLOBALS['agm']['user_caps'][$id][$required])) {
            if (!$GLOBALS['agm']['user_caps'][$id][$required]) {
                return false;
            }
            continue;
        }
        if (!($id > 0 && $id === get_current_user_id() && agm_holds_cap($required))) {
            return false;
        }
    }
    return true;
}
/** The primitive capabilities $cap takes, through the map_meta_cap filter. */
function agm_map_meta_cap($cap, $user_id, array $args = array()) {
    $caps = apply_filters('map_meta_cap', array($cap), $cap, $user_id, $args);
    return is_array($caps) && $caps ? $caps : array($cap);
}
/** Records the post meta key removed from every post. */
function delete_post_meta_by_key($key) {
    $GLOBALS['agm']['deleted_post_meta'][] = $key;
    return true;
}

/** delete_metadata('user', 0, $key, '', true) removes a key from every user, as WordPress does. */
function delete_metadata($type, $object_id, $key, $value = '', $delete_all = false) {
    if ($type !== 'user') {
        return false;
    }
    $deleted = false;
    foreach ($GLOBALS['agm']['user_meta'] as $id => $meta) {
        if (($delete_all || (int) $id === (int) $object_id) && array_key_exists($key, $meta)) {
            unset($GLOBALS['agm']['user_meta'][$id][$key]);
            $deleted = true;
        }
    }
    if ($deleted) {
        agm_record_write('user_meta', $key, 'delete');
    }
    return $deleted;
}
function current_user_can($cap, ...$args) {
    foreach (agm_map_meta_cap($cap, get_current_user_id(), $args) as $required) {
        if (!agm_holds_cap($required)) {
            return false;
        }
    }
    return true;
}
/** Whether the signed-in user was granted a primitive capability (agm_grant()). */
function agm_holds_cap($cap) {
    return isset($GLOBALS['agm']['caps'][$cap]) ? (bool) $GLOBALS['agm']['caps'][$cap] : false;
}
function agm_grant($cap, $granted = true) { $GLOBALS['agm']['caps'][$cap] = $granted; }

// ── Nonces ──

function wp_create_nonce($action = -1) { return 'nonce-' . substr(md5((string) $action), 0, 10); }
function wp_verify_nonce($nonce, $action = -1) {
    return $GLOBALS['agm']['nonces_ok'] && $nonce === wp_create_nonce($action) ? 1 : false;
}
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true) {
    $html = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '" />';
    if ($display) {
        echo $html;
    }
    return $html;
}
function wp_nonce_url($url, $action = -1, $name = '_wpnonce') { return add_query_arg($name, wp_create_nonce($action), $url); }
function check_admin_referer($action = -1, $name = '_wpnonce') {
    $nonce = isset($_REQUEST[$name]) ? $_REQUEST[$name] : '';
    if (!wp_verify_nonce($nonce, $action)) {
        wp_die('The link you followed has expired.', 403);
    }
    return 1;
}
function check_ajax_referer($action = -1, $name = false, $die = true) {
    $key = $name ? $name : '_ajax_nonce';
    $nonce = isset($_REQUEST[$key]) ? $_REQUEST[$key] : (isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '');
    $ok = wp_verify_nonce($nonce, $action);
    if (!$ok && $die) {
        wp_die(-1, 403);
    }
    return $ok;
}

// ── Responses that end a request ──

function wp_die($message = '', $title = '', $args = array()) {
    $GLOBALS['agm']['dies'][] = array('message' => is_scalar($message) ? (string) $message : '', 'title' => $title);
    throw new Agm_Halt('wp_die: ' . (is_scalar($message) ? $message : ''));
}
function wp_send_json($response, $status = null) {
    $GLOBALS['agm']['json'][] = array('body' => $response, 'status' => $status);
    throw new Agm_Halt('json');
}
function wp_send_json_success($data = null, $status = null) { wp_send_json(array('success' => true, 'data' => $data), $status); }
function wp_send_json_error($data = null, $status = null) { wp_send_json(array('success' => false, 'data' => $data), $status); }
function wp_redirect($location, $status = 302) {
    $GLOBALS['agm']['redirects'][] = array('location' => $location, 'status' => $status);
    // Plugins follow a redirect with exit; the harness stops the request here instead.
    throw new Agm_Halt('redirect: ' . $location);
}
/** As WordPress: a location on another host than the site's, or one allowed_redirect_hosts adds, becomes the fallback. */
function wp_validate_redirect($location, $fallback = '') {
    $host = parse_url((string) $location, PHP_URL_HOST);
    if (!$host) {
        return $location;
    }
    $allowed = apply_filters('allowed_redirect_hosts', array(parse_url(home_url(), PHP_URL_HOST)), $host);
    return in_array(strtolower($host), array_map('strtolower', (array) $allowed), true) ? $location : $fallback;
}
function wp_safe_redirect($location, $status = 302) {
    return wp_redirect(wp_validate_redirect($location, apply_filters('wp_safe_redirect_fallback', admin_url(), $status)), $status);
}
function status_header($code) {}
function nocache_headers() {}

// ── Formatting ──

function __($text, $domain = null) { return $text; }
function _e($text, $domain = null) { echo $text; }
function _x($text, $context, $domain = null) { return $text; }
function _n($single, $plural, $number, $domain = null) { return $number == 1 ? $single : $plural; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_js($text) { return addslashes((string) $text); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
function esc_url_raw($url) { return (string) $url; }
function sanitize_url($url) { return (string) $url; }
function esc_html__($text, $domain = null) { return esc_html($text); }
function esc_attr__($text, $domain = null) { return esc_attr($text); }
function esc_html_e($text, $domain = null) { echo esc_html($text); }
function esc_attr_e($text, $domain = null) { echo esc_attr($text); }
function wp_kses_post($text) { return (string) $text; }
/**
 * wp_kses() for the markup a scenario uses: every tag not in $allowed goes (its
 * words stay), and so does every attribute not allowed for its tag, and an href
 * that is not http(s), mailto, a path or an anchor. No entity normalising.
 */
function wp_kses($text, $allowed = array(), $protocols = array()) {
    $allowed = is_array($allowed) ? array_change_key_case($allowed, CASE_LOWER) : array();
    return preg_replace_callback('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>|<[^>]*>?/', function ($m) use ($allowed) {
        $tag = isset($m[2]) ? strtolower($m[2]) : '';
        if ($tag === '' || !isset($allowed[$tag])) {
            return '';
        }
        if ($m[1] === '/') {
            return '</' . $tag . '>';
        }
        $attributes = '';
        preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $m[3], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $name = strtolower($pair[1]);
            if (empty($allowed[$tag][$name])) {
                continue;
            }
            $value = isset($pair[5]) ? $pair[5] : (isset($pair[4]) && $pair[4] !== '' ? $pair[4] : $pair[3]);
            if ($name === 'href' && !preg_match('#^(https?://|mailto:|/|\#)#i', trim(html_entity_decode($value, ENT_QUOTES)))) {
                continue;
            }
            $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false) . '"';
        }
        return '<' . $tag . $attributes . '>';
    }, (string) $text);
}
function sanitize_text_field($text) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $text))); }
function sanitize_textarea_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_title($title) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-'); }
function sanitize_email($email) { return trim((string) $email); }
function is_email($email) { return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false; }
function wp_unslash($value) { return $value; }
function wp_slash($value) { return $value; }
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function absint($value) { return abs((int) $value); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function trailingslashit($value) { return rtrim($value, '/\\') . '/'; }
function untrailingslashit($value) { return rtrim($value, '/\\'); }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals); }
function human_time_diff($from, $to = 0) { return abs(($to ?: time()) - $from) . ' seconds'; }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp === null ? time() : $timestamp); }
function date_i18n($format, $timestamp = false) { return gmdate($format, $timestamp ?: time()); }
/** Counts its calls; values queued in $GLOBALS['agm']['wp_rand'] come out first, for results a test can predict. */
function wp_rand($min = 0, $max = 0) {
    $GLOBALS['agm']['wp_rand_calls'] = (isset($GLOBALS['agm']['wp_rand_calls']) ? $GLOBALS['agm']['wp_rand_calls'] : 0) + 1;
    if (!empty($GLOBALS['agm']['wp_rand'])) {
        return (int) array_shift($GLOBALS['agm']['wp_rand']);
    }
    return mt_rand($min, $max ?: mt_getrandmax());
}
function wp_generate_password($length = 12, $special = true) { return substr(str_repeat(md5((string) mt_rand()), 4), 0, $length); }
function wp_generate_uuid4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}
function wp_hash($data, $scheme = 'auth') { return hash_hmac('md5', (string) $data, 'agm-salt'); }
function wp_salt($scheme = 'auth') { return 'agm-salt'; }
function home_url($path = '') { return 'https://shop.test' . ($path ? '/' . ltrim($path, '/') : ''); }
function site_url($path = '') { return home_url($path); }
function admin_url($path = '') { return 'https://shop.test/wp-admin/' . ltrim($path, '/'); }
function get_bloginfo($show = '') { return $show === 'version' ? '6.8' : 'Test Shop'; }
function get_locale() { return 'en_US'; }
function wp_timezone_string() { return 'UTC'; }
/**
 * WordPress's own add_query_arg(), line for line: values added are NOT
 * encoded, values already in the URL are re-encoded, false drops a key, null
 * values are left out, and a key with an empty value keeps no "=".
 */
function add_query_arg(...$args) {
    if (is_array($args[0])) {
        $uri = count($args) < 2 || false === $args[1] ? (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') : $args[1];
    } else {
        $uri = count($args) < 3 || false === $args[2] ? (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') : $args[2];
    }
    $uri = (string) $uri;
    $frag = strstr($uri, '#');
    if ($frag) {
        $uri = substr($uri, 0, -strlen($frag));
    } else {
        $frag = '';
    }
    if (0 === stripos($uri, 'http://')) {
        $protocol = 'http://';
        $uri = substr($uri, 7);
    } elseif (0 === stripos($uri, 'https://')) {
        $protocol = 'https://';
        $uri = substr($uri, 8);
    } else {
        $protocol = '';
    }
    if (strpos($uri, '?') !== false) {
        list($base, $query) = explode('?', $uri, 2);
        $base .= '?';
    } elseif ($protocol || strpos($uri, '=') === false) {
        $base = $uri . '?';
        $query = '';
    } else {
        $base = '';
        $query = $uri;
    }
    wp_parse_str($query, $qs);
    $qs = urlencode_deep($qs);
    if (is_array($args[0])) {
        foreach ($args[0] as $k => $v) {
            $qs[$k] = $v;
        }
    } else {
        $qs[$args[0]] = $args[1];
    }
    foreach ($qs as $k => $v) {
        if (false === $v) {
            unset($qs[$k]);
        }
    }
    $ret = build_query($qs);
    $ret = trim($ret, '?');
    $ret = preg_replace('#=(&|$)#', '$1', $ret);
    $ret = $protocol . $base . $ret . $frag;
    $ret = rtrim($ret, '?');
    return str_replace('?#', '#', $ret);
}
function remove_query_arg($key, $query = false) {
    if (is_array($key)) {
        foreach ($key as $k) {
            $query = add_query_arg($k, false, $query);
        }
        return $query;
    }
    return add_query_arg($key, false, $query);
}
function wp_parse_str($input_string, &$result) {
    parse_str((string) $input_string, $result);
    $result = apply_filters('wp_parse_str', $result);
}
function map_deep($value, $callback) {
    if (is_array($value)) {
        foreach ($value as $index => $item) {
            $value[$index] = map_deep($item, $callback);
        }
    } elseif (is_object($value)) {
        foreach (get_object_vars($value) as $name => $property) {
            $value->$name = map_deep($property, $callback);
        }
    } else {
        $value = call_user_func($callback, $value);
    }
    return $value;
}
function urlencode_deep($value) { return map_deep($value, 'urlencode'); }
function rawurlencode_deep($value) { return map_deep($value, 'rawurlencode'); }
function build_query($data) { return _http_build_query($data, null, '&', '', false); }
function _http_build_query($data, $prefix = null, $sep = null, $key = '', $urlencode = true) {
    $ret = array();
    foreach ((array) $data as $k => $v) {
        if ($urlencode) {
            $k = urlencode($k);
        }
        if (is_int($k) && null !== $prefix) {
            $k = $prefix . $k;
        }
        if (!empty($key)) {
            $k = $key . '%5B' . $k . '%5D';
        }
        if (null === $v) {
            continue;
        } elseif (false === $v) {
            $v = '0';
        }
        if (is_array($v) || is_object($v)) {
            array_push($ret, _http_build_query($v, '', $sep, $k, $urlencode));
        } elseif ($urlencode) {
            array_push($ret, $k . '=' . urlencode($v));
        } else {
            array_push($ret, $k . '=' . $v);
        }
    }
    if (null === $sep) {
        $sep = ini_get('arg_separator.output');
    }
    return implode($sep, $ret);
}

// ── Admin screens ──

function add_menu_page(...$args) { $GLOBALS['agm']['menus'][] = $args; return 'toplevel_page_' . $args[3]; }
function add_submenu_page(...$args) { $GLOBALS['agm']['menus'][] = $args; return 'woocommerce_page_' . $args[4]; }
function register_setting($group, $name, $args = array()) {}
function settings_fields($group) {}
function submit_button($text = 'Save Changes', $type = 'primary', $name = 'submit', $wrap = true, $other = '') {
    echo '<button type="submit" class="button button-' . esc_attr($type) . '">' . esc_html($text) . '</button>';
}
/** The admin screen being drawn, from $GLOBALS['agm']['screen'] (its id), or null outside one. */
function get_current_screen() {
    return empty($GLOBALS['agm']['screen']) ? null : (object) array('id' => (string) $GLOBALS['agm']['screen'], 'base' => (string) $GLOBALS['agm']['screen']);
}
/** As WordPress: the posted _wp_http_referer, else the Referer header, when it is on an allowed host; false otherwise. */
function wp_get_referer() {
    $referer = isset($_REQUEST['_wp_http_referer']) ? (string) $_REQUEST['_wp_http_referer'] : (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
    return $referer !== '' ? wp_validate_redirect($referer, false) : false;
}
function selected($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($echo) { echo $r; } return $r; }
function checked($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ($echo) { echo $r; } return $r; }
function disabled($a, $b = true, $echo = true) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ($echo) { echo $r; } return $r; }

// ── Assets ──

function plugin_dir_path($file) { return trailingslashit(dirname($file)); }
function plugin_dir_url($file) { return 'https://shop.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
function plugins_url($path = '', $plugin = '') { return 'https://shop.test/wp-content/plugins/agumail/' . ltrim($path, '/'); }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
/**
 * As WordPress 6.3 and later: $args is in_footer as a bool, or an array with
 * in_footer and strategy (defer or async). A handle registered twice keeps its
 * first registration. agm_script() reads a handle back.
 */
function wp_register_script($handle, $src = '', $deps = array(), $ver = false, $args = array()) {
    if (!empty($GLOBALS['agm']['scripts'][$handle]['registered'])) {
        return false;
    }
    $existing = isset($GLOBALS['agm']['scripts'][$handle]) ? $GLOBALS['agm']['scripts'][$handle] : array();
    $GLOBALS['agm']['scripts'][$handle] = array_merge(array('enqueued' => false, 'inline' => array(), 'data' => array()), $existing, array(
        'registered' => true,
        'src'        => $src,
        'deps'       => (array) $deps,
        'ver'        => $ver,
        'in_footer'  => is_array($args) ? !empty($args['in_footer']) : (bool) $args,
        'strategy'   => is_array($args) && isset($args['strategy']) ? (string) $args['strategy'] : null,
        'args'       => $args,
    ));
    return true;
}
function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $args = array()) {
    if (empty($GLOBALS['agm']['scripts'][$handle]['registered'])) {
        wp_register_script($handle, $src, $deps, $ver, $args);
    }
    $GLOBALS['agm']['scripts'][$handle]['enqueued'] = true;
}
/** What was registered for a script handle: src, deps, ver, in_footer, strategy, args, enqueued, inline, data. */
function agm_script($handle) {
    return isset($GLOBALS['agm']['scripts'][$handle]) ? $GLOBALS['agm']['scripts'][$handle] : null;
}
function wp_script_is($handle, $list = 'enqueued') {
    if (!isset($GLOBALS['agm']['scripts'][$handle])) {
        return false;
    }
    $script = $GLOBALS['agm']['scripts'][$handle];
    return $list === 'enqueued' ? !empty($script['enqueued']) : ($list === 'registered' ? !empty($script['registered']) : true);
}
function wp_add_inline_script($handle, $data, $position = 'after') { $GLOBALS['agm']['scripts'][$handle]['inline'][] = $data; return true; }
function wp_localize_script($handle, $name, $data) { $GLOBALS['agm']['scripts'][$handle]['data'][$name] = $data; return true; }
function wp_script_add_data($handle, $key, $value) { $GLOBALS['agm']['scripts'][$handle]['data'][$key] = $value; return true; }
function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {}
function wp_register_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {}
function wp_add_inline_style($handle, $data) {}
function wp_is_post_revision($post) { return false; }

// ── Reading back ──

/** Writes of a kind (or all), optionally only those made during one simulated request. */
function agm_writes($kind = null, $request = null) {
    return array_values(array_filter($GLOBALS['agm']['writes'], function ($write) use ($kind, $request) {
        return ($kind === null || in_array($write['kind'], (array) $kind, true))
            && ($request === null || $write['request'] === $request);
    }));
}
