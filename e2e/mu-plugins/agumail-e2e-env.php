<?php
/**
 * Plugin Name: AguMail e2e environment
 * Description: Test-stack only. Makes background work deterministic (the specs run Action Scheduler with wp-cli) unless config.json turns realCron on, and captures outgoing mail to a log instead of sending it.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Action Scheduler starts its own queue runner with a loopback request on admin page loads. Off by
// default so a spec decides when the queue runs; on in the opt-in real-cron mode.
add_filter('action_scheduler_allow_async_request_runner', function ($allow) {
    return defined('AGUMAIL_E2E_REAL_CRON') && AGUMAIL_E2E_REAL_CRON ? $allow : false;
}, PHP_INT_MAX);

// WP-Cron is off here, so WooCommerce's own scheduled actions pile up past due, and Action Scheduler then
// adds a "past-due actions found" warning to every wp-admin page, among the plugin's own notices.
add_filter('action_scheduler_pastdue_actions_check_pre', function ($check) {
    return defined('AGUMAIL_E2E_REAL_CRON') && AGUMAIL_E2E_REAL_CRON ? $check : false;
});

// There is no mail server in the stack. Log instead, so a spec can read what would have been sent.
add_filter('pre_wp_mail', function ($short_circuit, $atts) {
    if (function_exists('agumail_e2e_audit_write')) {
        agumail_e2e_audit_write('mail.jsonl', array(
            'time'    => microtime(true),
            'to'      => isset($atts['to']) ? $atts['to'] : null,
            'subject' => isset($atts['subject']) ? $atts['subject'] : null,
        ));
    }
    return true;
}, PHP_INT_MAX, 2);

// Keep WooCommerce from phoning home during tests.
add_filter('woocommerce_allow_marketplace_suggestions', '__return_false');
add_filter('pre_option_woocommerce_allow_tracking', function () {
    return 'no';
});

// CheckoutWC checks the email domain's MX record; the stack has no DNS for the .test shopper domains.
add_filter('cfw_email_domain_valid', function ($valid, $domain) {
    return $valid || substr((string) $domain, -5) === '.test';
}, 10, 2);

// A shopper carrying the cookie agumail_e2e_plugins_off=1 gets the store with no AguMail plugin loaded,
// exactly as if it were inactive, while every other request still has it. The timing spec uses it to
// interleave "no plugin" and "plugin" checkouts in the same minutes, so drift in this Docker host
// cancels out instead of landing on one side of the comparison.
if (isset($_COOKIE['agumail_e2e_plugins_off']) && $_COOKIE['agumail_e2e_plugins_off'] === '1') {
    add_filter('option_active_plugins', function ($plugins) {
        return array_values(array_filter((array) $plugins, function ($plugin) {
            return strpos((string) $plugin, 'agumail') !== 0;
        }));
    }, PHP_INT_MAX);
}

// Gravity Forms sends a form by its own AJAX (admin-ajax.php, a JSON answer) only when the page asks for
// it; a shortcode's ajax="true" still means the older iframe. wp/builders.php names the form that should.
add_filter('gform_form_args', function ($args) {
    $ajax_form = (int) get_option('agumail_e2e_gf_ajax_form');
    if ($ajax_form && isset($args['form_id']) && (int) $args['form_id'] === $ajax_form) {
        $args['submission_method'] = 'ajax';
    }
    return $args;
});
