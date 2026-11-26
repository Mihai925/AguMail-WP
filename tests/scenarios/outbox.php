<?php
// The outbox and the carts table: what one queued change writes, how changes
// to the same thing coalesce, and the helpers the status panel uses. Also the
// real store's SQL, and the installer that creates the tables and jobs.

function outbox_rows($type = null) {
    return agm_outbox($type);
}

function outbox_frozen_clock($timestamp) {
    agumail_set_clock(function () use ($timestamp) { return $timestamp; });
}

// ── Queueing ──

function test_outbox_a_change_is_queued_as_one_pending_row() {
    outbox_frozen_clock(1790000000);
    expect_that(agumail_enqueue('order', 501), 'the change was not queued');
    $rows = outbox_rows();
    expect_same(1, count($rows), 'expected one row');
    expect_same(array('order', '501', null, 1, 0, 'pending', '2026-09-21 14:13:20'),
        array($rows[0]['type'], $rows[0]['object_key'], $rows[0]['payload'], $rows[0]['version'], $rows[0]['attempts'], $rows[0]['status'], $rows[0]['available_at']),
        'wrong row');
    expect_same(1, count(agm_writes('outbox')), 'queueing a change should be exactly one write');
}

function test_outbox_queueing_again_coalesces_into_the_same_row() {
    outbox_frozen_clock(1790000000);
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com', 'firstName' => 'Ada'));
    $id = outbox_rows()[0]['id'];
    agm_store()->defer($id, 3, '2026-09-21 20:00:00', 'HTTP 503', false, '2026-09-21 14:13:20');

    outbox_frozen_clock(1790000100);
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com', 'firstName' => 'Ada B.'));
    $rows = outbox_rows();
    expect_same(1, count($rows), 'a second change made a second row');
    expect_same(2, $rows[0]['version'], 'the version did not move on');
    expect_same(3, $rows[0]['attempts'], 'attempts should be kept, so a row that keeps failing still parks');
    expect_same('2026-09-21 14:15:00', $rows[0]['available_at'], 'the changed row should be due now');
    expect_same('Ada B.', $rows[0]['payload']['firstName'], 'the newer payload did not replace the old one');
}

function test_outbox_a_parked_row_that_changes_goes_back_in_line() {
    agumail_enqueue('product', 11);
    $row = outbox_rows()[0];
    agm_store()->park($row['id'], $row['version'], 'products.0.title: Required', agumail_mysql_time());
    expect_same('failed', outbox_rows()[0]['status'], 'the row was not parked');
    agumail_enqueue('product', 11);
    expect_same('pending', outbox_rows()[0]['status'], 'a parked row that changed again should be sent again');
}

function test_outbox_types_keep_their_own_rows_and_contact_keys_are_lowercased() {
    agumail_enqueue('order', 7);
    agumail_enqueue('product', 7);
    agumail_enqueue('contact', '  Ada@Example.COM ', array('email' => 'Ada@Example.COM'));
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com'));
    $keys = array_map(function ($row) { return $row['type'] . ':' . $row['object_key']; }, outbox_rows());
    expect_same(array('order:7', 'product:7', 'contact:ada@example.com'), $keys, 'wrong rows');
}

function test_outbox_long_keys_are_shortened_and_stay_unique() {
    $long_a = str_repeat('a', 180) . '@example.com';
    $long_b = str_repeat('a', 181) . '@example.com';
    agumail_enqueue('contact', $long_a, array('email' => $long_a));
    agumail_enqueue('contact', $long_b, array('email' => $long_b));
    $rows = outbox_rows();
    expect_same(2, count($rows), 'two long keys collided');
    foreach ($rows as $row) {
        expect_that(strlen($row['object_key']) <= 191, 'a key is longer than the column: ' . strlen($row['object_key']));
    }
}

function test_outbox_nothing_is_queued_before_the_tables_exist_without_a_key_or_inside_the_drain() {
    delete_option('agumail_db_version');
    expect_that(!agumail_enqueue('order', 1), 'queued before the tables exist');
    expect_that(!agumail_cart_save('wc-1', array('token' => str_repeat('a', 32))), 'a cart was saved before the tables exist');
    update_option('agumail_db_version', AGUMAIL_DB_VERSION);

    delete_option('agumail_api_key');
    expect_that(!agumail_enqueue('order', 2), 'queued for a store that is not connected');
    update_option('agumail_api_key', 'agm_' . str_repeat('b', 40));

    agumail_enqueue_suppressed(true);
    expect_that(!agumail_enqueue('order', 3), 'queued from inside the drain');
    agumail_enqueue_suppressed(false);

    expect_that(agumail_enqueue('order', 4), 'could not queue once ready');
    expect_same(array('4'), array_column(outbox_rows(), 'object_key'), 'wrong rows');
}

function test_outbox_a_captured_contact_never_replaces_a_queued_answer() {
    agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', array('first_name' => 'Ada', 'opted_in' => true, 'source' => 'my_account')));
    agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', array('last_name' => 'Lovelace')));
    $payload = outbox_rows()[0]['payload'];
    expect_same(array('optedIn' => true, 'source' => 'my_account'), isset($payload['consent']) ? $payload['consent'] : null, 'a captured email wiped the opt-in queued before it');
    expect_same(array('Ada', 'Lovelace'), array($payload['firstName'], $payload['lastName']), 'the names were not combined');

    agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', array('unsubscribed' => true)));
    $payload = outbox_rows()[0]['payload'];
    expect_that(!empty($payload['unsubscribed']) && !isset($payload['consent']), 'a later explicit answer should replace the earlier one');
}

function test_outbox_a_weaker_answer_never_replaces_a_stronger_queued_one() {
    $queue = function ($args) {
        agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', $args));
        return outbox_rows()[0]['payload'];
    };
    $queue(array('unsubscribed' => true));
    $payload = $queue(array('opted_in' => false, 'source' => 'checkout', 'first_name' => 'Ada'));
    expect_that(!empty($payload['unsubscribed']) && !isset($payload['consent']), 'an unticked box replaced a queued unsubscribe');
    expect_same('Ada', $payload['firstName'], 'the newer record did not fill the gaps');

    agm_store()->outbox = array();
    $queue(array('opted_in' => true, 'source' => 'my_account'));
    $payload = $queue(array('opted_in' => false, 'source' => 'checkout'));
    expect_same(array('optedIn' => true, 'source' => 'my_account'), $payload['consent'], 'an unticked box replaced a queued opt-in');

    $payload = $queue(array('unsubscribed' => true));
    expect_that(!empty($payload['unsubscribed']), 'a newer unsubscribe did not replace a queued opt-in');
    $payload = $queue(array('opted_in' => true, 'source' => 'my_account'));
    expect_same(array('optedIn' => true, 'source' => 'my_account'), isset($payload['consent']) ? $payload['consent'] : null, 'a newer opt-in did not replace a queued unsubscribe');
    expect_that(empty($payload['unsubscribed']), 'the old unsubscribe survived a newer opt-in');
}

function test_outbox_a_row_changed_while_it_was_being_sent_is_kept() {
    agumail_enqueue('order', 501);
    $sent = outbox_rows()[0];
    agumail_enqueue('order', 501);
    expect_that(!agm_store()->complete($sent['id'], $sent['version']), 'the changed row was deleted');
    expect_same(2, outbox_rows()[0]['version'], 'the newer version is gone');
    expect_that(agm_store()->complete($sent['id'], 2), 'the current version was not deleted');
    expect_same(array(), outbox_rows(), 'the row is still there');
}

function test_outbox_retry_discard_and_stats_serve_the_status_panel() {
    outbox_frozen_clock(1790000000);
    foreach (array(1, 2, 3) as $id) {
        agumail_enqueue('order', $id);
    }
    outbox_frozen_clock(1790000600);
    agumail_enqueue('product', 9);
    $rows = outbox_rows();
    agm_store()->park($rows[0]['id'], 1, 'orders.0.email: Invalid email', agumail_mysql_time());
    agm_store()->park($rows[1]['id'], 1, 'orders.0.total: Expected number', agumail_mysql_time());
    agm_store()->defer($rows[2]['id'], 2, '2026-09-21 18:00:00', 'HTTP 503', false, agumail_mysql_time());

    $stats = agumail_outbox_stats();
    expect_same(2, $stats['pending'], 'wrong pending count');
    expect_same(2, $stats['failed'], 'wrong failed count');
    expect_same(1, $stats['due'], 'wrong due count');
    expect_same('2026-09-21 14:13:20', $stats['oldest_pending_at'], 'wrong oldest pending row');
    expect_same(array('pending' => 1, 'failed' => 2), $stats['by_type']['order'], 'wrong counts for orders');
    expect_same(2, count(agumail_outbox_failed()), 'the failed list is wrong');

    expect_that(agumail_outbox_retry($rows[0]['id']), 'retry did nothing');
    $retried = agumail_outbox_row($rows[0]['id']);
    expect_same(array('pending', 0, null), array($retried['status'], $retried['attempts'], $retried['last_error']), 'a retried row should be pending with a clean slate');
    expect_same(1, agumail_outbox_retry_failed(), 'retry all should retry the one left');
    agm_store()->park($rows[0]['id'], 1, 'again', agumail_mysql_time());
    expect_that(agumail_outbox_discard($rows[0]['id']), 'discard did nothing');
    agm_store()->park($rows[1]['id'], 1, 'again', agumail_mysql_time());
    expect_same(1, agumail_outbox_discard_failed(), 'discard all should remove the one failed row');
    expect_same(array('3', '9'), array_column(outbox_rows(), 'object_key'), 'wrong rows left');
}

// ── Carts ──

function test_outbox_a_new_cart_always_gets_a_token_and_an_empty_one_never_counts() {
    agumail_cart_save('wc-1', array('session_key' => 't_1'));
    $token = agumail_cart_get('wc-1')['token'];
    expect_that((bool) preg_match('/^[0-9a-f]{32}$/', $token), 'a cart saved without a token got "' . $token . '"');
    agumail_cart_save('wc-1', array('total' => 5, 'token' => ''));
    expect_same($token, agumail_cart_get('wc-1')['token'], 'an update replaced or blanked the token');
    agumail_cart_save('wc-2', array('token' => ''));
    expect_that(strlen(agumail_cart_get('wc-2')['token']) === 32, 'an empty token was stored');

    $cart = agumail_cart_get('wc-1');
    expect_that(agumail_cart_token_valid($cart, $token), 'the right token was refused');
    expect_that(!agumail_cart_token_valid($cart, strtoupper($token) . 'x'), 'a wrong token was accepted');
    $cart['token'] = '';
    expect_that(!agumail_cart_token_valid($cart, ''), 'an empty token restored a cart');
    expect_that(!agumail_cart_token_valid(null, $token), 'a missing cart was accepted');
}

function test_outbox_a_cart_changed_while_being_moved_stays_changed() {
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32)));
    $seen = agumail_carts_dirty(10);
    agumail_cart_save('wc-1', array('total' => 9));
    agumail_cart_mark_queued('wc-1', $seen[0]['version']);
    expect_same(array('wc-1'), array_column(agumail_carts_dirty(10), 'id'), 'a cart that changed after it was read lost its change');
    agumail_cart_mark_queued('wc-1', 2);
    expect_same(array(), agumail_carts_dirty(10), 'a cart queued at its latest version is still listed');
}

function test_outbox_a_cart_change_is_one_write_and_reaches_the_outbox_once() {
    $result = agm_request('add to cart', 'shopper', function () {
        agumail_cart_save('wc-1', array('token' => str_repeat('a', 32), 'session_key' => 't_1', 'lines' => array(array('product_id' => 11, 'qty' => 1)), 'total' => 30, 'currency' => 'EUR'));
    });
    expect_same(1, count(agm_writes(array('outbox', 'carts'), 'add to cart')), 'a cart change should be one write');
    $cart = agumail_cart_get('wc-1');
    expect_same(array(1, 0, 'open', 30.0, array(array('product_id' => 11, 'qty' => 1))), array($cart['version'], $cart['queued_version'], $cart['status'], $cart['total'], $cart['lines']), 'wrong cart row');
    expect_same('wc-1', agumail_cart_find_by_session('t_1')['id'], 'the cart was not found by its session');

    expect_same(1, agumail_carts_move_dirty_to_outbox(), 'the changed cart was not moved');
    expect_same(0, agumail_carts_move_dirty_to_outbox(), 'an unchanged cart was moved again');
    expect_same(array('cart:wc-1'), array_map(function ($row) { return $row['type'] . ':' . $row['object_key']; }, outbox_rows()), 'wrong outbox');

    agumail_cart_save('wc-1', array('email' => 'Ada@Example.com'));
    $cart = agumail_cart_get('wc-1');
    expect_same(array(2, 'ada@example.com', str_repeat('a', 32)), array($cart['version'], $cart['email'], $cart['token']), 'an update should keep the other columns and move the version on');
    agumail_cart_update('wc-1', array('status' => 'ordered', 'order_id' => 501), false);
    expect_same(2, agumail_cart_get('wc-1')['version'], 'a bookkeeping update moved the version on');
    expect_same(1, agumail_carts_move_dirty_to_outbox(), 'the changed cart was not moved again');
    expect_same(2, outbox_rows('cart')[0]['version'], 'the second move did not bump the queued row');
}

function test_outbox_old_carts_are_purged_by_status_and_age() {
    outbox_frozen_clock(1790000000);
    agumail_cart_save('wc-old-ordered', array('token' => 'a', 'status' => 'ordered'));
    agumail_cart_save('wc-old-open', array('token' => 'b'));
    outbox_frozen_clock(1790000000 + 20 * DAY_IN_SECONDS);
    agumail_cart_save('wc-new-ordered', array('token' => 'c', 'status' => 'ordered'));
    expect_same(1, agumail_carts_purge(array('ordered', 'emptied'), agumail_time() - 14 * DAY_IN_SECONDS), 'wrong purge count');
    expect_same(array('wc-old-open', 'wc-new-ordered'), array_keys(agm_store()->carts), 'wrong carts left');
}

// ── The real store's SQL ──

function test_outbox_the_real_store_queues_with_one_prepared_upsert() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    agumail_enqueue('order', "5'01");
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com'));
    $writes = $wpdb->statements('/^(INSERT|UPDATE|DELETE)/');
    expect_same(2, count($writes), 'each queued change should be one statement');
    expect_that(strpos($writes[0], "INSERT INTO wp_agumail_outbox") === 0, 'wrong table: ' . $writes[0]);
    expect_that(strpos($writes[0], 'ON DUPLICATE KEY UPDATE `payload` = NULL, `version` = `version` + 1') !== false, 'the upsert does not bump the version: ' . $writes[0]);
    expect_that(strpos($writes[0], "'5\\'01'") !== false, 'the key was not escaped: ' . $writes[0]);
    expect_that(strpos($writes[0], '`attempts` =') === false, 'the upsert must keep attempts: ' . $writes[0]);
    expect_that(strpos($writes[1], '`payload` = \'{') !== false, 'the payload was not written on update: ' . $writes[1]);
}

function test_outbox_the_real_store_saves_a_cart_in_one_statement() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32), 'email' => null, 'lines' => array(), 'total' => 12.5));
    $writes = $wpdb->statements('/^(INSERT|UPDATE|DELETE)/');
    expect_same(1, count($writes), 'a cart save should be one statement');
    expect_that(strpos($writes[0], '`lines`') !== false, 'lines is a reserved word and must be quoted: ' . $writes[0]);
    expect_that(strpos($writes[0], "'12.5000'") !== false, 'the total is not a plain decimal: ' . $writes[0]);
    expect_that(strpos($writes[0], 'ON DUPLICATE KEY UPDATE') !== false && strpos($writes[0], '`version` = `version` + 1') !== false, 'the save does not move the version on: ' . $writes[0]);
}

function test_outbox_the_real_store_finds_changed_carts_by_an_indexed_flag() {
    global $wpdb;
    agumail_install();
    $schema = implode("\n", $GLOBALS['agm']['dbdelta']);
    expect_that(strpos($schema, 'dirty TINYINT(1) NOT NULL DEFAULT 1') !== false, 'the carts table has no changed flag');
    expect_that(strpos($schema, 'KEY dirty_updated_at (dirty,updated_at)') !== false, 'the changed flag is not indexed');
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    agumail_carts_dirty(50);
    agumail_cart_mark_queued('wc-1', 3);
    agumail_cart_update('wc-1', array('status' => 'emptied'));
    $sql = $wpdb->statements();
    expect_that(strpos($sql[0], 'WHERE `dirty` = 1') !== false, 'changed carts are not found by the flag: ' . $sql[0]);
    expect_that(strpos($sql[1], '`dirty` = IF(`version` > 3, 1, 0)') !== false, 'queueing does not clear the flag safely: ' . $sql[1]);
    expect_that(strpos($sql[2], '`dirty` = 1') !== false, 'a change does not set the flag: ' . $sql[2]);
}

function test_outbox_the_real_store_retries_a_failed_upsert_once_and_logs_a_second_failure() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $GLOBALS['agm']['failures'] = 1;
    $wpdb->answers = array('/^INSERT INTO wp_agumail_outbox/' => function () {
        if ($GLOBALS['agm']['failures'] > 0) {
            $GLOBALS['agm']['failures']--;
            $GLOBALS['wpdb']->last_error = 'Deadlock found when trying to get lock; try restarting transaction';
            return false;
        }
        return 1;
    });
    expect_that(agumail_enqueue('order', 501), 'one deadlock lost the change');
    expect_same(2, count($wpdb->statements('/^INSERT INTO wp_agumail_outbox/')), 'the upsert was not tried again');

    $GLOBALS['agm']['failures'] = 2;
    expect_that(!agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com')), 'a change that could not be written was reported as queued');
    $logged = agm_logs('Could not queue contact');
    expect_same(1, count($logged), 'the failure was not logged');
    expect_that(strpos($logged[0]['message'], 'a***@example.com') !== false && strpos($logged[0]['message'], 'Deadlock found') !== false, 'the log line lacks the masked key or the database error: ' . $logged[0]['message']);
    expect_that(strpos($logged[0]['message'], 'ada@example.com') === false, 'the log line carries the address');
}

function test_outbox_the_real_lock_is_an_insert_then_a_compare_and_swap() {
    global $wpdb;
    $store = new Agumail_Store();
    $wpdb->answers = array('/^SELECT option_value/' => '');
    expect_that($store->acquire_lock('agumail_drain_lock', 120, 1790000000) !== false, 'a free lock was not taken');
    expect_same(1, count($wpdb->statements('/^INSERT IGNORE INTO wp_options/')), 'a free lock should be taken with INSERT IGNORE');
    $wpdb->answers = array('/^SELECT option_value/' => 'someone|1790000100');
    expect_same(false, $store->acquire_lock('agumail_drain_lock', 120, 1790000000), 'a held lock was taken');
    $wpdb->answers = array('/^SELECT option_value/' => 'someone|1789999999');
    expect_that($store->acquire_lock('agumail_drain_lock', 120, 1790000000) !== false, 'an expired lock was not taken over');
    expect_same(1, count($wpdb->statements("/^UPDATE wp_options SET option_value = .* AND option_value = 'someone\\|1789999999'/")), 'an expired lock should be taken with a compare-and-swap');
}

// ── Installing ──

function test_outbox_activation_creates_both_tables_and_schedules_the_jobs() {
    delete_option('agumail_db_version');
    agm_request('activate', 'admin', function () {
        foreach ($GLOBALS['agm']['activation'] as $callback) {
            call_user_func($callback);
        }
    });
    expect_same(AGUMAIL_DB_VERSION, get_option('agumail_db_version'), 'the schema version was not recorded');
    $sql = implode("\n", $GLOBALS['agm']['dbdelta']);
    foreach (array('wp_agumail_outbox', 'wp_agumail_carts', 'UNIQUE KEY type_object_key (type,object_key)', 'KEY status_available_at (status,available_at)',
        'object_key VARCHAR(191) NOT NULL', 'payload LONGTEXT NULL', 'available_at DATETIME NOT NULL', 'last_error TEXT NULL',
        'id VARCHAR(64) NOT NULL', 'token CHAR(32) NOT NULL', '`lines` LONGTEXT NOT NULL', 'total DECIMAL(18,4)', 'queued_version INT',
        'KEY status_updated_at (status,updated_at)', 'KEY email (email(191))', 'KEY session_key (session_key)') as $part) {
        expect_that(strpos($sql, $part) !== false, 'the schema is missing ' . $part);
    }
    $drain = agm_scheduled('agumail_drain');
    expect_same(1, count($drain), 'the drain was not scheduled once');
    expect_same(array('recurring', 60), array($drain[0]['type'], $drain[0]['interval']), 'the drain should recur every 60 seconds');
    foreach (array('agumail_housekeeping', 'agumail_reverse_sync') as $hook) {
        $job = agm_scheduled($hook);
        expect_same(1, count($job), "$hook was not scheduled once");
        expect_same(DAY_IN_SECONDS, $job[0]['interval'], "$hook should run daily");
    }
    foreach ($GLOBALS['agm']['activation'] as $callback) {
        call_user_func($callback);
    }
    expect_same(1, count(agm_scheduled('agumail_drain')), 'activating again scheduled the drain twice');
}

function test_outbox_ready_means_the_tables_exist_at_any_schema_version() {
    update_option('agumail_db_version', '2');
    expect_that(agumail_ready(), 'tables from a newer schema were treated as missing');
    expect_that(agumail_enqueue('order', 1), 'changes stopped while waiting for a schema upgrade');
    update_option('agumail_db_version', '0');
    expect_that(!agumail_ready(), 'no tables were treated as ready');
}

function test_outbox_a_schema_that_is_not_current_is_installed_from_cron_and_wp_cli() {
    delete_option('agumail_db_version');
    set_transient('agumail_maintained', 1, HOUR_IN_SECONDS);
    agm_request('wp-cron', 'cron', function () {
        do_action('init');
    });
    expect_same(AGUMAIL_DB_VERSION, get_option('agumail_db_version'), 'cron did not install the tables');

    delete_option('agumail_db_version');
    define('WP_CLI', true);
    agm_request('wp-cli', 'shopper', function () {
        do_action('init');
    });
    expect_same(AGUMAIL_DB_VERSION, get_option('agumail_db_version'), 'WP-CLI did not install the tables');
}

function test_outbox_a_table_that_could_not_be_created_leaves_the_plugin_not_ready() {
    delete_option('agumail_db_version');
    $GLOBALS['agm']['dbdelta_skip'] = array('wp_agumail_carts');
    expect_that(!agumail_install(), 'install reported success without the carts table');
    expect_that(!agumail_ready(), 'the plugin is ready without its tables');
    expect_same(1, count(agm_logs('Could not create the table wp_agumail_carts')), 'the failure was not logged');
    expect_that(!agumail_enqueue('order', 1), 'a change was queued with no tables');
}

function test_outbox_jobs_are_repaired_from_wp_admin_and_cron_but_never_from_a_shopper_request() {
    agm_grant('manage_woocommerce');
    foreach (array('shopper' => 'init', 'shopper_ajax' => 'admin_init', 'shopper_rest' => 'init') as $kind => $hook) {
        $result = agm_request("$kind $hook", $kind, function () use ($hook) {
            do_action($hook);
        });
        expect_same(array(), agm_writes(array('as', 'transient', 'option'), $result['name']), "a $kind request scheduled or wrote something");
    }
    expect_same(0, count(agm_scheduled('agumail_drain')), 'a shopper request scheduled the drain');

    agm_request('merchant opens wp-admin', 'admin', function () {
        do_action('admin_init');
    });
    expect_same(1, count(agm_scheduled('agumail_drain')), 'wp-admin did not repair the missing drain');

    as_unschedule_all_actions('', array(), 'agumail');
    delete_transient('agumail_maintained');
    agm_request('wp-cron', 'cron', function () {
        do_action('init');
    });
    expect_same(1, count(agm_scheduled('agumail_drain')), 'cron did not repair the missing drain');
}

function test_outbox_deactivation_unschedules_every_agumail_job() {
    agumail_schedule();
    as_schedule_single_action(time(), 'someone_elses_job', array(), 'other');
    foreach ($GLOBALS['agm']['deactivation'] as $callback) {
        call_user_func($callback);
    }
    expect_same(array(), array_values(array_filter($GLOBALS['agm']['as'], function ($action) { return $action['group'] === 'agumail'; })), 'AguMail jobs are still scheduled');
    expect_same(1, count($GLOBALS['agm']['as']), 'another plugin\'s job was unscheduled');
}

function test_outbox_deactivation_without_woocommerce_still_removes_the_jobs() {
    agm_expect_child_passes('outbox.php', 'outbox_deactivate_without_action_scheduler', array('AGM_NO_SCHEDULER' => '1'));
}

/** Runs in a process with no Action Scheduler functions, as when WooCommerce is inactive. */
function outbox_deactivate_without_action_scheduler() {
    global $wpdb;
    expect_that(!function_exists('as_unschedule_all_actions'), 'Action Scheduler is loaded in this process');
    $wpdb->tables['wp_actionscheduler_actions'] = 'CREATE TABLE wp_actionscheduler_actions';
    $wpdb->tables['wp_actionscheduler_groups'] = 'CREATE TABLE wp_actionscheduler_groups';
    foreach ($GLOBALS['agm']['deactivation'] as $callback) {
        call_user_func($callback);
    }
    $deleted = $wpdb->statements('/^DELETE a FROM wp_actionscheduler_actions a/');
    expect_same(1, count($deleted), 'the pending AguMail jobs were not removed from Action Scheduler\'s tables');
    expect_that(strpos($deleted[0], "INNER JOIN wp_actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = 'agumail' AND a.status = 'pending'") !== false,
        'deactivating should remove only this plugin\'s pending jobs: ' . $deleted[0]);
    expect_same(array(), $wpdb->statements('/actionscheduler_logs/'), 'deactivating should keep the history of jobs that ran');
}

// ── Batches ──

function test_outbox_queueing_many_writes_each_row_as_one_change_without_a_payload() {
    outbox_frozen_clock(1790000000);
    agumail_enqueue('contact', 'ada@example.com', array('email' => 'ada@example.com'));
    $id = outbox_rows()[0]['id'];
    agm_store()->defer($id, 3, '2026-09-21 20:00:00', 'HTTP 503', true, '2026-09-21 14:13:20');

    outbox_frozen_clock(1790000100);
    expect_same(array('Ada@Example.com', 'bea@example.com'), agumail_enqueue_many('contact', array('Ada@Example.com', 'bea@example.com', '  ')),
        'the keys written should be returned as given, and an empty key skipped');
    $rows = outbox_rows();
    expect_same(2, count($rows), 'queueing an address already queued made a second row');
    expect_same(array('ada@example.com', null, 2, 3, 'pending', '2026-09-21 14:15:00'),
        array($rows[0]['object_key'], $rows[0]['payload'], $rows[0]['version'], $rows[0]['attempts'], $rows[0]['status'], $rows[0]['available_at']),
        'the existing row was not changed as agumail_enqueue() changes it');
    expect_same(array('bea@example.com', null, 1, 0, 'pending'),
        array($rows[1]['object_key'], $rows[1]['payload'], $rows[1]['version'], $rows[1]['attempts'], $rows[1]['status']), 'wrong new row');

    agumail_enqueue_suppressed(true);
    expect_same(array(), agumail_enqueue_many('order', array(501, 502)), 'a batch was queued inside the drain');
    agumail_enqueue_suppressed(false);
    expect_same(2, count(outbox_rows()), 'a batch was queued inside the drain');
}

function test_outbox_marking_carts_in_a_batch_keeps_the_flag_of_a_cart_that_changed_since() {
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32)));
    agumail_cart_save('wc-2', array('token' => str_repeat('b', 32)));
    $seen = agumail_carts_dirty(10);
    agumail_cart_save('wc-1', array('total' => 9));
    expect_same(2, agm_store()->carts_mark_queued(array('wc-1' => $seen[0]['version'], 'wc-2' => $seen[1]['version'])), 'wrong count of carts marked');
    expect_same(array('wc-1'), array_column(agumail_carts_dirty(10), 'id'), 'a cart that changed after it was read lost its change');
    expect_same(array(1, 1), array(agumail_cart_get('wc-1')['queued_version'], agumail_cart_get('wc-2')['queued_version']), 'the queued versions were not recorded');
    expect_same(0, agm_store()->carts_mark_queued(array('wc-2' => 1)), 'a cart already queued at that version was marked again');
}

function test_outbox_carts_are_not_moved_or_marked_inside_the_drain() {
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32)));
    agumail_enqueue_suppressed(true);
    expect_same(0, agumail_carts_move_dirty_to_outbox(), 'a cart was moved while queueing was off');
    agumail_enqueue_suppressed(false);
    expect_same(array(), outbox_rows(), 'a cart reached the outbox while queueing was off');
    expect_same(array('wc-1'), array_column(agumail_carts_dirty(10), 'id'), 'the cart lost its flag without reaching the outbox');
}

function outbox_raw_carts($count) {
    $carts = array();
    for ($i = 1; $i <= $count; $i++) {
        $carts[] = array('id' => 'wc-' . $i, 'token' => str_repeat('a', 32), 'session_key' => '', 'user_id' => null, 'email' => null, 'lines' => '[]',
            'total' => '0.0000', 'currency' => 'EUR', 'status' => 'open', 'version' => (string) ($i + 1), 'queued_version' => '1', 'dirty' => '1',
            'order_id' => null, 'ip_hash' => '', 'created_at' => '2026-09-21 14:13:20', 'updated_at' => '2026-09-21 14:13:20');
    }
    return $carts;
}

function test_outbox_the_real_store_moves_carts_in_two_statements_per_batch_of_200() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->answers = array('/^SELECT \* FROM wp_agumail_carts WHERE `dirty` = 1/' => outbox_raw_carts(450));
    $wpdb->queries = array();
    expect_same(450, agumail_carts_move_dirty_to_outbox(500), 'not every cart was moved');
    $sql = $wpdb->statements();
    expect_same(7, count($sql), '450 carts should take one read and two statements per batch of 200');
    expect_same(array('SELECT', 'INSERT', 'UPDATE', 'INSERT', 'UPDATE', 'INSERT', 'UPDATE'), array_map(function ($s) { return strtok($s, ' '); }, $sql), 'wrong statements');
    expect_same(array(200, 200, 50), array(substr_count($sql[1], "('cart', '"), substr_count($sql[3], "('cart', '"), substr_count($sql[5], "('cart', '")), 'wrong batch sizes');
    expect_that(strpos($sql[1], "ON DUPLICATE KEY UPDATE `payload` = NULL, `version` = `version` + 1, `status` = 'pending', `available_at` = '") !== false, 'the batch upsert does not change a row as agumail_enqueue() does: ' . substr($sql[1], -200));
    expect_that(strpos($sql[2], "UPDATE wp_agumail_carts SET `dirty` = IF(`version` > CASE `id` WHEN 'wc-1' THEN 2 WHEN 'wc-2' THEN 3 ") === 0, 'the flag is not lowered by the version read: ' . substr($sql[2], 0, 200));
    expect_that(strpos($sql[2], "`queued_version` = CASE `id` WHEN 'wc-1' THEN 2 ") !== false, 'the queued version is not recorded: ' . substr($sql[2], 0, 200));
    expect_that(strpos($sql[2], "WHERE `id` IN ('wc-1', 'wc-2', ") !== false && strpos($sql[2], "AND `queued_version` < CASE `id` WHEN 'wc-1' THEN 2 ") !== false, 'the update is not limited to the carts read, or to versions not yet queued: ' . substr($sql[2], -200));
    expect_that(strpos($sql[6], "WHEN 'wc-450' THEN 451 END") !== false, 'the last cart was not marked');
}

function test_outbox_the_real_store_leaves_carts_flagged_when_their_upsert_fails() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->answers = array(
        '/^SELECT \* FROM wp_agumail_carts WHERE `dirty` = 1/' => outbox_raw_carts(3),
        '/^INSERT INTO wp_agumail_outbox/' => false,
    );
    $wpdb->queries = array();
    expect_same(0, agumail_carts_move_dirty_to_outbox(), 'carts were reported moved');
    expect_same(array(), $wpdb->statements('/^UPDATE wp_agumail_carts/'), 'carts were marked though their rows were not written');
}

function test_outbox_the_real_store_matches_statuses_and_types_with_fixed_sql() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    agumail_cart_find_by_session('t_1', array('open'));
    agumail_cart_find_by_session('t_1', array());
    agumail_store()->carts_by_email('ada@example.com', array('open', 'ordered'), 20, 40);
    agumail_carts_purge(array('emptied', 'ordered'), 1790000000);
    agumail_carts_purge(array(), 1790000000);
    agumail_store()->discard_pending(array('order', 'product'), '2026-09-01 00:00:00');
    agumail_store()->discard_pending(array('cart'));
    agumail_store()->discard_pending(array());
    expect_same(array(
        "SELECT * FROM wp_agumail_carts WHERE `session_key` = 't_1' AND FIND_IN_SET(`status`, 'open') ORDER BY `updated_at` DESC LIMIT 1",
        "SELECT * FROM wp_agumail_carts WHERE `session_key` = 't_1' ORDER BY `updated_at` DESC LIMIT 1",
        "SELECT * FROM wp_agumail_carts WHERE `email` = 'ada@example.com' AND FIND_IN_SET(`status`, 'open,ordered') ORDER BY `updated_at` DESC LIMIT 20 OFFSET 40",
        "DELETE FROM wp_agumail_carts WHERE `status` = 'emptied' AND `updated_at` < '2026-09-21 14:13:20'",
        "DELETE FROM wp_agumail_carts WHERE `status` = 'ordered' AND `updated_at` < '2026-09-21 14:13:20'",
        "DELETE FROM wp_agumail_carts WHERE `updated_at` < '2026-09-21 14:13:20'",
        "DELETE FROM wp_agumail_outbox WHERE `status` = 'pending' AND `type` = 'order' AND `updated_at` < '2026-09-01 00:00:00'",
        "DELETE FROM wp_agumail_outbox WHERE `status` = 'pending' AND `type` = 'product' AND `updated_at` < '2026-09-01 00:00:00'",
        "DELETE FROM wp_agumail_outbox WHERE `status` = 'pending' AND `type` = 'cart'",
    ), $wpdb->statements(), 'wrong statements');
}
