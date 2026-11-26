<?php
// Deleting the plugin removes what it stored: both tables, every agumail_*
// option and transient, and its scheduled jobs. What shoppers answered stays,
// on their accounts and orders, because that belongs to the store.

function test_uninstall_removes_the_tables_settings_and_jobs_but_keeps_what_shoppers_answered($plugin_dir) {
    global $wpdb;
    foreach (array('agumail_api_url', 'agumail_app_url', 'agumail_api_key', 'agumail_consent_label', 'agumail_config',
        'agumail_connection', 'agumail_connected_at', 'agumail_tracking_enabled', 'agumail_registration_optin',
        'agumail_account_toggle', 'agumail_popup', 'agumail_forms_cache', 'agumail_bulk_state', 'agumail_backoff_until',
        'agumail_drain_lock', 'agumail_db_version', 'agumail_site_key', 'agumail_site_src', 'agumail_checkout_optin_default',
        'agumail_connect_notice_dismissed', 'agumail_some_future_option') as $name) {
        $GLOBALS['agm']['options'][$name] = 'x';
    }
    $GLOBALS['agm']['options']['woocommerce_currency'] = 'EUR';
    $GLOBALS['agm']['options']['agumailer_other_plugin'] = 'keep';
    set_transient('agumail_notice_1', array('type' => 'success', 'message' => 'Saved.'));
    set_transient('agumail_maintained', 1);
    set_transient('wc_other', 1);
    agumail_schedule();
    as_schedule_single_action(time(), 'agumail_bulk_step', array(array('page' => 3)), 'agumail');
    as_schedule_single_action(time(), 'woocommerce_cleanup_sessions', array(), 'woocommerce');
    update_user_meta(7, '_agumail_optin', 'yes');
    $order = agm_order(501);
    $order->update_meta_data('_agumail_optin', 'yes');

    define('WP_UNINSTALL_PLUGIN', 'agumail/agumail.php');
    require $plugin_dir . '/uninstall.php';

    expect_same(array("DROP TABLE IF EXISTS wp_agumail_outbox", "DROP TABLE IF EXISTS wp_agumail_carts"),
        $wpdb->statements('/^DROP TABLE/'), 'both tables should be dropped');
    $left = array_values(array_filter(array_keys($GLOBALS['agm']['options']), function ($name) { return strpos($name, 'agumail_') === 0; }));
    expect_same(array(), $left, 'options left behind');
    expect_same('EUR', get_option('woocommerce_currency'), 'another plugin\'s option was deleted');
    expect_same('keep', get_option('agumailer_other_plugin'), 'an option that only starts like ours was deleted');
    expect_same(array('wc_other'), array_keys($GLOBALS['agm']['transients']), 'transients left behind, or another plugin\'s deleted');
    expect_same(array(), array_values(array_filter($GLOBALS['agm']['as'], function ($action) { return $action['group'] === 'agumail'; })), 'AguMail jobs are still scheduled');
    expect_same(1, count($GLOBALS['agm']['as']), 'another plugin\'s job was unscheduled');
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the customer\'s answer was deleted');
    expect_same('yes', $order->get_meta('_agumail_optin'), 'the order\'s answer was deleted');
    expect_same(array('_agumail_signup'), $GLOBALS['agm']['deleted_post_meta'] ?? array(), 'Contact Form 7 forms keep their AguMail settings');
}

function test_uninstall_does_nothing_unless_wordpress_is_uninstalling($plugin_dir) {
    // uninstall.php exits when loaded directly; run it in a child PHP so the exit does not end this test.
    $script = '<?php define("ABSPATH", "/tmp/"); $GLOBALS["dropped"] = 0; '
        . 'class W { public $prefix = "wp_"; public $options = "wp_options"; public function query($q) { echo "QUERY"; } } $wpdb = new W(); '
        . 'function delete_option($n) { echo "DELETE"; } '
        . 'require ' . var_export($plugin_dir . '/uninstall.php', true) . '; echo "RAN";';
    $file = tempnam(sys_get_temp_dir(), 'agm');
    file_put_contents($file, $script);
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    expect_same('', trim((string) $output), 'uninstall.php did something outside an uninstall: ' . $output);
}

function test_uninstall_cleans_every_site_of_a_multisite_network($plugin_dir) {
    global $wpdb;
    $GLOBALS['agm']['sites'] = array(1, 2);
    $GLOBALS['agm']['options']['agumail_api_key'] = 'x';
    agumail_schedule();
    define('WP_UNINSTALL_PLUGIN', 'agumail/agumail.php');
    require $plugin_dir . '/uninstall.php';
    expect_same(array('DROP TABLE IF EXISTS wp_agumail_outbox', 'DROP TABLE IF EXISTS wp_agumail_carts',
        'DROP TABLE IF EXISTS wp_2_agumail_outbox', 'DROP TABLE IF EXISTS wp_2_agumail_carts'),
        $wpdb->statements('/^DROP TABLE/'), 'every site\'s tables should be dropped');
    // On each site: every agumail_* option and transient, then the per-merchant and per-connect transients by name.
    expect_same(array(4, 4), array(count($wpdb->statements('/^DELETE FROM wp_options WHERE option_name LIKE/')), count($wpdb->statements('/^DELETE FROM wp_2_options WHERE option_name LIKE/'))), 'every site\'s options should be swept');
    expect_same(array(1, 2), $GLOBALS['agm']['switched'], 'uninstall did not visit every site');
    expect_same(1, get_current_blog_id(), 'uninstall left WordPress on another site');
    expect_same(2, count(array_filter($GLOBALS['agm']['as_unschedule_all'], function ($call) { return $call['group'] === 'agumail'; })), 'every site\'s jobs should be unscheduled');
}

function test_uninstall_deletes_the_address_each_account_was_linked_under_but_keeps_its_answer($plugin_dir) {
    update_user_meta(7, '_agumail_email', 'bea@example.com');
    update_user_meta(8, '_agumail_email', 'cy@example.com');
    update_user_meta(7, '_agumail_optin', 'yes');
    update_user_meta(8, 'billing_email', 'cy@example.com');
    define('WP_UNINSTALL_PLUGIN', 'agumail/agumail.php');
    require $plugin_dir . '/uninstall.php';
    expect_same('', get_user_meta(7, '_agumail_email', true), 'a linked address outlived the plugin, and would unsubscribe the wrong contact after a reconnect');
    expect_same('', get_user_meta(8, '_agumail_email', true), 'not every account\'s linked address was deleted');
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the customer\'s answer was deleted');
    expect_same('cy@example.com', get_user_meta(8, 'billing_email', true), 'another key was deleted');
}

function test_uninstall_without_woocommerce_still_removes_the_jobs($plugin_dir) {
    agm_expect_child_passes('uninstall.php', 'uninstall_without_action_scheduler', array('AGM_NO_SCHEDULER' => '1'));
}

/** Runs in a process with no Action Scheduler functions, as when WooCommerce is inactive. */
function uninstall_without_action_scheduler($plugin_dir) {
    global $wpdb;
    expect_that(!function_exists('as_unschedule_all_actions'), 'Action Scheduler is loaded in this process');
    $wpdb->tables['wp_actionscheduler_actions'] = 'CREATE TABLE wp_actionscheduler_actions';
    $wpdb->tables['wp_actionscheduler_groups'] = 'CREATE TABLE wp_actionscheduler_groups';
    define('WP_UNINSTALL_PLUGIN', 'agumail/agumail.php');
    require $plugin_dir . '/uninstall.php';
    $logs = $wpdb->statements('/^DELETE l FROM wp_actionscheduler_logs l/');
    $actions = $wpdb->statements('/^DELETE a FROM wp_actionscheduler_actions a/');
    expect_same(1, count($logs), 'the jobs\' log lines were not removed');
    expect_same(1, count($actions), 'the jobs were not removed from Action Scheduler\'s tables');
    expect_that(strpos($actions[0], "WHERE g.slug = 'agumail'") !== false && strpos($actions[0], 'status') === false, 'uninstall should remove every AguMail job: ' . $actions[0]);
}
