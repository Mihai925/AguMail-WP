<?php
// Loads the fakes (core first, then every other file in fakes/, so a lane can
// add its own) and the helpers every scenario shares: assertions, and the
// simulated request.

require __DIR__ . '/fakes/core.php';
// AGM_NO_WOOCOMMERCE=1 loads a WordPress site without WooCommerce: none of its
// classes, functions or logger, and none of the fakes built on them.
$agm_skipped = getenv('AGM_NO_WOOCOMMERCE') ? array('wc.php', 'orders_products.php', 'p4.php', 'logger.php') : array();
foreach (glob(__DIR__ . '/fakes/*.php') as $agm_fake) {
    // AGM_NO_SCHEDULER=1 loads a site without Action Scheduler, as when WooCommerce is inactive and the bundled copy is not loaded.
    if (basename($agm_fake) === 'core.php' || (basename($agm_fake) === 'scheduler.php' && getenv('AGM_NO_SCHEDULER'))
        || in_array(basename($agm_fake), $agm_skipped, true)) {
        continue;
    }
    require_once $agm_fake;
}
unset($agm_fake, $agm_skipped);
if (!isset($GLOBALS['agm']['logs'])) {
    $GLOBALS['agm']['logs'] = array();
}

// ── Assertions ──

function expect_that($condition, $message) {
    if (!$condition) {
        throw new Exception($message);
    }
}

function expect_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new Exception($message . "\n        expected: " . var_export($expected, true) . "\n        actual:   " . var_export($actual, true));
    }
}

/**
 * Runs one function of a scenario file in a fresh PHP process with extra
 * environment (AGM_NO_SCHEDULER=1, say) and fails unless it finished cleanly.
 */
function agm_expect_child_passes($file, $function, array $env = array()) {
    $prefix = '';
    foreach ($env as $name => $value) {
        $prefix .= $name . '=' . escapeshellarg($value) . ' ';
    }
    $output = array();
    exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run.php') . ' --child ' . escapeshellarg($file) . ' ' . escapeshellarg($function) . ' 2>&1', $output, $code);
    $lines = array_values(array_filter($output, function ($line) { return $line !== '' && $line !== 'agm-ok'; }));
    if ($code !== 0 || end($output) !== 'agm-ok') {
        throw new Exception($function . ' failed in a fresh process: ' . implode(' | ', $lines ?: array('(exited without finishing)')));
    }
}

// ── The plugin, as a connected store ──

/**
 * Called once the plugin file is loaded: gives it a key, marks its tables as
 * created and hands it the in-memory store. Setup writes are then forgotten.
 */
function agm_plugin_loaded() {
    $GLOBALS['agm']['options']['agumail_api_url'] = 'https://api.agumail.test';
    $GLOBALS['agm']['options']['agumail_api_key'] = 'agm_' . str_repeat('a', 40);
    if (defined('AGUMAIL_DB_VERSION')) {
        $GLOBALS['agm']['options']['agumail_db_version'] = AGUMAIL_DB_VERSION;
    }
    if (function_exists('agumail_set_store') && class_exists('Agm_Fake_Store')) {
        agumail_set_store(new Agm_Fake_Store());
    }
    // WooCommerce loads during plugins_loaded, before anything the scenarios run.
    if (class_exists('WooCommerce')) {
        do_action('woocommerce_loaded');
    }
    $GLOBALS['agm']['writes'] = array();
}

// ── Simulated requests ──

/**
 * Runs $body as one request of a kind and reports what it did:
 *   shopper       a storefront page, a wc-ajax call or a gateway callback
 *   shopper_rest  a Store API call from the block cart or checkout
 *   shopper_ajax  admin-ajax.php called from the storefront (is_admin() is true there)
 *   admin         a wp-admin page
 *   cron          WP-Cron, which is where Action Scheduler runs
 * A wp_die, JSON response or redirect ends the request, as it would in WordPress.
 */
function agm_request($name, $kind, callable $body) {
    $contexts = array(
        'shopper'      => array('admin' => false, 'cron' => false, 'ajax' => false, 'rest' => false),
        'shopper_rest' => array('admin' => false, 'cron' => false, 'ajax' => false, 'rest' => true),
        'shopper_ajax' => array('admin' => true, 'cron' => false, 'ajax' => true, 'rest' => false),
        'admin'        => array('admin' => true, 'cron' => false, 'ajax' => false, 'rest' => false),
        'cron'         => array('admin' => false, 'cron' => true, 'ajax' => false, 'rest' => false),
    );
    if (!isset($contexts[$kind])) {
        throw new Exception('Unknown request kind ' . $kind);
    }
    $GLOBALS['agm']['request'] = $name;
    $GLOBALS['agm']['context'] = $contexts[$kind];
    $_GET = $_POST = $_REQUEST = array();
    $http_before = count($GLOBALS['agm']['http']);
    $logs_before = count($GLOBALS['agm']['logs']);
    $halt = null;
    try {
        $body();
    } catch (Agm_Halt $e) {
        $halt = $e->getMessage();
    }
    $result = array(
        'name'    => $name,
        'http'    => array_slice($GLOBALS['agm']['http'], $http_before),
        'writes'  => agm_writes(null, $name),
        'logs'    => array_slice($GLOBALS['agm']['logs'], $logs_before),
        'halt'    => $halt,
    );
    $GLOBALS['agm']['request'] = 'idle';
    $GLOBALS['agm']['context'] = $contexts['shopper'];
    return $result;
}

/** Sets request input the way PHP would: $_GET and $_POST, merged into $_REQUEST, every value a string. */
function agm_input(array $get = array(), array $post = array()) {
    $strings = function ($value) use (&$strings) {
        if (is_array($value)) {
            return array_map($strings, $value);
        }
        return is_bool($value) ? ($value ? '1' : '') : (is_scalar($value) ? (string) $value : $value);
    };
    $_GET = $strings($get);
    $_POST = $strings($post);
    $_REQUEST = array_merge($_GET, $_POST);
}
