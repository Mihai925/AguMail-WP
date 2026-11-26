<?php
// Shortcodes, blocks and the REST API, for lane P3 (connect, tracking, forms).
// Each registration is recorded so a scenario can call what the plugin
// registered. Guarded, so another lane's fake of the same function wins
// without a clash.

$GLOBALS['agm']['shortcodes']  = array();
$GLOBALS['agm']['blocks']      = array();
$GLOBALS['agm']['rest_routes'] = array();

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        $GLOBALS['agm']['shortcodes'][$tag] = $callback;
    }
}

if (!function_exists('shortcode_exists')) {
    function shortcode_exists($tag) {
        return isset($GLOBALS['agm']['shortcodes'][$tag]);
    }
}

if (!function_exists('shortcode_atts')) {
    /** As WordPress: only the known attributes, each defaulted, then the shortcode_atts_{$shortcode} filter. */
    function shortcode_atts($pairs, $atts, $shortcode = '') {
        $atts = (array) $atts;
        $out = array();
        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }
        if ($shortcode) {
            $out = apply_filters('shortcode_atts_' . $shortcode, $out, $pairs, $atts, $shortcode);
        }
        return $out;
    }
}

if (!function_exists('register_block_type')) {
    function register_block_type($name, $args = array()) {
        // A folder holding block.json, as WordPress reads it: the metadata, then $args on top.
        if (is_file(rtrim($name, '/') . '/block.json')) {
            $meta = json_decode(file_get_contents(rtrim($name, '/') . '/block.json'), true);
            $name = $meta['name'];
            $map  = array('apiVersion' => 'api_version', 'editorScript' => 'editor_script', 'attributes' => 'attributes', 'title' => 'title', 'category' => 'category');
            $from = array();
            foreach ($map as $json => $key) {
                if (isset($meta[$json])) {
                    $from[$key] = $meta[$json];
                }
            }
            $args = array_merge($from, $args);
        }
        $GLOBALS['agm']['blocks'][$name] = $args;
        return (object) array_merge(array('name' => $name), $args);
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = array(), $override = false) {
        $GLOBALS['agm']['rest_routes'][trim($namespace, '/') . '/' . ltrim($route, '/')] = $args;
        return true;
    }
}

if (!function_exists('delete_metadata')) {
    /** As WordPress: with $delete_all, the key goes from every object of the type, not just $object_id. */
    function delete_metadata($meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false) {
        if ($meta_type !== 'user') {
            return false;
        }
        $found = false;
        foreach ($GLOBALS['agm']['user_meta'] as $id => $meta) {
            if (($delete_all || (int) $id === (int) $object_id) && array_key_exists($meta_key, $meta)
                && ($meta_value === '' || $meta[$meta_key] === $meta_value)) {
                unset($GLOBALS['agm']['user_meta'][$id][$meta_key]);
                $found = true;
            }
        }
        if ($found) {
            agm_record_write('user_meta', $meta_key, $delete_all ? 'delete_all' : 'delete');
        }
        return $found;
    }
}

/** Runs the shortcode the plugin registered, as WordPress would for [$tag ...$atts]. */
function agm_shortcode($tag, $atts = array(), $content = null) {
    if (!isset($GLOBALS['agm']['shortcodes'][$tag])) {
        throw new Exception("The shortcode [$tag] is not registered");
    }
    return call_user_func($GLOBALS['agm']['shortcodes'][$tag], $atts, $content, $tag);
}

/** What a callback printed. */
function agm_capture(callable $callback) {
    ob_start();
    try {
        $callback();
    } finally {
        $output = ob_get_clean();
    }
    return $output;
}
