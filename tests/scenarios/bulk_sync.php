<?php
// Bulk sync: loading the store's past into AguMail. It runs as Action
// Scheduler steps that page through subscribed accounts, guests who ticked the
// box at checkout, the other customers, the catalogue and paid orders, in
// that order, and every request says
// backfill: true so nothing it sends starts a flow. Counts come from what
// AguMail answers; a 429 or a failure waits and carries on; a cancel stops it;
// a PHP fatal leaves a step behind that picks up where the last one stopped.

// ── The store ──

function bulk_sync_store() {
    // Subscribed on their account (phase one).
    agm_p4_user(10, 'ivy@example.com', array('first_name' => 'Ivy', 'user_registered' => '2024-01-10 09:00:00'), array('_agumail_optin' => 'yes'));
    agm_p4_user(11, 'leo@example.com', array('first_name' => 'Leo', 'user_registered' => '2023-05-01 12:00:00'), array('marketing_opt_in' => 'yes'));
    // The other customers (phase two).
    agm_p4_user(12, 'max@example.com', array('first_name' => 'Max', 'last_name' => 'Hale', 'user_registered' => '2022-03-03 08:00:00'));
    agm_p4_user(13, 'nia@example.com', array('user_registered' => '2022-04-04 08:00:00'), array('_agumail_optin' => 'unsubscribed', 'marketing_opt_in' => 'yes'));
    agm_p4_user(15, 'oli@example.com', array('user_registered' => '2021-01-01 00:00:00', 'roles' => array('subscriber')), array('paying_customer' => '1'));
    agm_p4_user(16, 'pam@example.com', array('user_registered' => '2020-02-02 00:00:00'));
    // Not customers.
    agm_p4_user(14, 'staff@example.com', array('roles' => array('administrator')));
    agm_p4_user(17, 'reader@example.com', array('roles' => array('subscriber')));
    // The catalogue.
    agm_product(30, 'Mug', 30);
    agm_product(31, 'Draft', 5, array('status' => 'draft'));
    agm_product(32, 'Mug - Blue', 30, array('type' => 'variation', 'parent_id' => 30));
    // Orders.
    agm_p4_order(100, 'ann@example.com', '2025-01-05 10:00:00', 'processing', array('billing_first_name' => 'Ann'));
    // Paid two days after it was placed (a bank transfer): the opt-in is dated when it was paid, as the live path dates it.
    agm_p4_order(101, 'Ann@Example.com', '2025-06-01 10:00:00', 'completed', array('date_paid' => agm_date(strtotime('2025-06-03 08:00:00 UTC'))), array('_agumail_optin' => 'yes'));
    agm_p4_order(102, 'max@example.com', '2025-02-02 10:00:00', 'processing', array('customer_id' => 12));
    agm_p4_order(103, 'pat@example.com', '2025-03-03 10:00:00', 'pending');
    agm_p4_order(104, 'pat@example.com', '2025-03-04 10:00:00', 'cancelled');
    agm_p4_order(105, 'pat@example.com', '2025-03-05 10:00:00', 'refunded');
    agm_p4_order(106, 'pat@example.com', '2025-03-06 10:00:00', 'failed');
    agm_p4_order(107, 'pam@example.com', '2024-07-07 10:00:00', 'completed', array('customer_id' => 16, 'date_paid' => agm_date(strtotime('2024-07-09 12:00:00 UTC'))), array('_agumail_optin' => 'yes'));
    agm_p4_order(108, 'bob@example.com', '2025-08-08 10:00:00', 'processing', array(), array('_agumail_optin' => 'no'));
    agm_p4_order(109, 'ivy@example.com', '2024-03-03 10:00:00', 'processing', array('customer_id' => 10), array('_agumail_optin' => 'yes'));
}

/** Presses "Sync store data" as the store manager, with the settings page's fields. */
function bulk_sync_start(array $fields = array()) {
    agm_p4_merchant();
    return agm_p4_admin_action('bulk_sync_start', $fields);
}

/** The whole sync, from the button to the last step. */
function bulk_sync_run(array $fields = array()) {
    bulk_sync_start($fields);
    agm_p4_run_bulk();
    return agumail_bulk_state();
}

/** Every bulk request, as [path, emails or ids]. */
function bulk_sync_requests() {
    $sent = array();
    foreach (agm_http_requests() as $request) {
        if (strpos($request['request'], 'bulk step') !== 0) {
            continue;
        }
        $body = $request['body'];
        foreach (array('contacts' => 'email', 'products' => 'externalId', 'orders' => 'externalId') as $collection => $field) {
            if (isset($body[$collection])) {
                $sent[] = array($request['path'], array_column($body[$collection], $field));
            }
        }
    }
    return $sent;
}

function bulk_sync_contact($email) {
    foreach (agm_sent_records('/api/v1/contacts', 'contacts') as $record) {
        if ($record['email'] === $email) {
            return $record;
        }
    }
    throw new Exception("$email was never sent");
}

/** Forgets that a run delivered the store's orders, so another run may send them again. */
function bulk_sync_forget_synced() {
    foreach ($GLOBALS['agm']['orders'] as $order) {
        unset($order->meta['_agumail_synced']);
    }
}

/** A clock the scenario moves: agumail_time() and the steps' budget read it. */
function bulk_sync_clock($start = null) {
    $GLOBALS['bulk_sync_now'] = $start === null ? (float) time() : (float) $start;
    agumail_set_clock(function () { return $GLOBALS['bulk_sync_now']; });
}

function bulk_sync_text($html) {
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

// ── Order and content ──

function test_bulk_sync_sends_subscribers_then_customers_then_products_then_orders() {
    bulk_sync_store();
    $state = bulk_sync_run();
    expect_same('done', $state['status'], 'the sync did not finish');
    expect_same(array(
        array('/api/v1/contacts', array('ivy@example.com', 'leo@example.com')),
        array('/api/v1/contacts', array('ann@example.com', 'pam@example.com')),
        array('/api/v1/contacts', array('max@example.com', 'nia@example.com', 'oli@example.com')),
        array('/api/v1/products', array('30')),
        array('/api/v1/contacts', array('bob@example.com')),
        array('/api/v1/orders', array('100', '101', '102', '107', '108', '109')),
    ), bulk_sync_requests(), 'the sync did not go subscribed accounts, checkout subscribers, customers, products, then guests and orders');
}

function test_bulk_sync_every_request_says_backfill_and_no_live_request_does() {
    bulk_sync_store();
    bulk_sync_run();
    $bulk = array_filter(agm_http_requests(), function ($request) { return strpos($request['request'], 'bulk step') === 0; });
    // One is the read that asks whether an unsubscribed account's address is known; it writes nothing.
    $writes = array_filter($bulk, function ($request) { return $request['path'] !== '/api/v1/contacts/status'; });
    expect_that(count($writes) === 6, 'the sync made ' . count($writes) . ' requests');
    foreach ($writes as $request) {
        expect_same(true, isset($request['body']['backfill']) ? $request['body']['backfill'] : null, $request['path'] . ' was sent without backfill: true');
    }

    agumail_enqueue('contact', 'live@example.com', agumail_contact_payload('live@example.com'));
    agm_order_pay(agm_p4_order(300, 'live@example.com', gmdate('Y-m-d H:i:s'), 'pending'));
    agm_drain();
    $live = agm_http_requests();
    $live = array_slice($live, count($bulk));
    expect_that(count($live) >= 1, 'the live changes were not sent');
    foreach ($live as $request) {
        expect_that(!array_key_exists('backfill', $request['body']), 'a live ' . $request['path'] . ' request said backfill');
    }
}

function test_bulk_sync_sends_paid_orders_only() {
    bulk_sync_store();
    bulk_sync_run();
    expect_same(array('100', '101', '102', '107', '108', '109'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'),
        'an order nobody paid for (pending, failed, cancelled, refunded) was sent');
    expect_that(!in_array('pat@example.com', array_column(agm_sent_records('/api/v1/contacts', 'contacts'), 'email'), true),
        'a shopper whose only orders were never paid became a contact');
}

function test_bulk_sync_dates_each_contact_and_claims_consent_only_for_those_who_gave_it() {
    bulk_sync_store();
    bulk_sync_run();

    $ivy = bulk_sync_contact('ivy@example.com');
    expect_same(array('optedIn' => true, 'source' => 'bulk_sync'), $ivy['consent'], 'a subscribed account was not sent as opted in');
    expect_same('2024-03-03T10:00:00Z', $ivy['consentedAt'], 'consentedAt is not the order where the account opted in');
    expect_same('2024-01-10T09:00:00Z', $ivy['customerSince'], 'customerSince is not the registration date');

    $leo = bulk_sync_contact('leo@example.com');
    expect_same(true, $leo['consent']['optedIn'], 'the older marketing_opt_in answer was lost');
    expect_same('2023-05-01T12:00:00Z', $leo['consentedAt'], 'an older opt-in is not dated at registration');

    $pam = bulk_sync_contact('pam@example.com');
    expect_same(true, $pam['consent']['optedIn'], 'a customer who ticked the box on an order was not sent as opted in');
    expect_same('2024-07-09T12:00:00Z', $pam['consentedAt'], 'consentedAt is not when the order where the customer opted in was paid');

    $ann = bulk_sync_contact('ann@example.com');
    expect_same('2025-01-05T10:00:00Z', $ann['customerSince'], 'a guest is not dated at their first order');
    expect_same(true, $ann['consent']['optedIn'], 'a guest who ticked the box was not sent as opted in');
    expect_same('2025-06-03T08:00:00Z', $ann['consentedAt'], 'a guest opt-in is not dated when its order was paid');

    foreach (array('max@example.com' => '2022-03-03T08:00:00Z', 'nia@example.com' => '2022-04-04T08:00:00Z', 'oli@example.com' => '2021-01-01T00:00:00Z', 'bob@example.com' => '2025-08-08T10:00:00Z') as $email => $since) {
        $contact = bulk_sync_contact($email);
        expect_that(!isset($contact['consent']) && !isset($contact['unsubscribed']), "$email was sent with a consent claim: " . json_encode($contact));
        expect_that(!isset($contact['consentedAt']), "$email carried consentedAt, which AguMail reads as an opt-in");
        expect_same($since, $contact['customerSince'], "$email is not dated as a customer");
    }

    foreach (agm_sent_records('/api/v1/contacts', 'contacts') as $contact) {
        if (isset($contact['consentedAt'])) {
            expect_same(true, $contact['consent']['optedIn'], $contact['email'] . ' has consentedAt without having opted in');
        }
    }
}

function test_bulk_sync_trusts_the_box_only_on_orders_a_checkout_created() {
    foreach (array(false, true) as $hpos) {
        $GLOBALS['agm']['hpos'] = $hpos;
        $GLOBALS['agm']['http'] = array();
        $GLOBALS['agm']['orders'] = array();
        $GLOBALS['agm']['users'] = array();
        $GLOBALS['agm']['user_meta'] = array();
        delete_option('agumail_bulk_state');
        // A renewal and an admin copy carry the meta of the order they were copied from.
        agm_p4_order(140, 'ren@example.com', '2025-02-01 10:00:00', 'processing', array('created_via' => 'subscription'), array('_agumail_optin' => 'yes'));
        agm_p4_user(18, 'ada@example.com', array('user_registered' => '2024-01-01 00:00:00'));
        agm_p4_order(141, 'ada@example.com', '2025-02-02 10:00:00', 'processing', array('customer_id' => 18, 'created_via' => 'admin'), array('_agumail_optin' => 'yes'));
        agm_p4_order(142, 'sue@example.com', '2025-02-03 10:00:00', 'processing', array('created_via' => 'store-api'), array('_agumail_optin' => 'yes'));
        bulk_sync_run();
        $label = $hpos ? ' (HPOS)' : ' (posts)';
        foreach (array('ren@example.com', 'ada@example.com') as $email) {
            $contact = bulk_sync_contact($email);
            expect_that(!isset($contact['consent']) && !isset($contact['consentedAt']), "$email was sent as opted in from an order no checkout created$label");
        }
        expect_same(true, bulk_sync_contact('sue@example.com')['consent']['optedIn'], "a block checkout's tick was not trusted$label");
    }
}

function test_bulk_sync_sends_checkout_subscribers_right_after_subscribed_accounts_once() {
    bulk_sync_store();
    // A second ticked order, a later page's worth: the buyer still goes once.
    agm_p4_order(150, 'ann@example.com', '2025-07-01 10:00:00', 'processing', array(), array('_agumail_optin' => 'yes'));
    // An account AguMail holds as not subscribed: its checkout tick still speaks for it.
    agm_p4_user(21, 'nod@example.com', array('user_registered' => '2023-01-01 00:00:00', 'roles' => array('subscriber')), array('_agumail_optin' => 'no'));
    // An account that unsubscribed since: its old tick is not an opt-in any more.
    agm_p4_user(23, 'uns@example.com', array('user_registered' => '2023-01-01 00:00:00', 'roles' => array('subscriber')), array('_agumail_optin' => 'unsubscribed'));
    agm_p4_order(153, 'uns@example.com', gmdate('Y-m-d H:i:s', strtotime('-9 days')), 'processing', array('customer_id' => 23), array('_agumail_optin' => 'yes'));
    agm_p4_order(151, 'nod@example.com', gmdate('Y-m-d H:i:s', strtotime('-10 days')), 'processing', array('customer_id' => 21), array('_agumail_optin' => 'yes'));
    // A ticked order still waiting in the live queue is news: its buyer is left to it.
    agm_p4_order(152, 'new@example.com', '2025-07-03 10:00:00', 'processing', array(), array('_agumail_optin' => 'yes'));
    agumail_enqueue('order', 152);
    $state = bulk_sync_run(array('agumail_bulk_months' => '3'));
    expect_same('done', $state['status'], 'the sync did not finish');

    $requests = bulk_sync_requests();
    expect_same(array('/api/v1/contacts', array('ann@example.com', 'pam@example.com', 'nod@example.com')), $requests[1],
        'the checkout subscribers did not go right after the subscribed accounts, whatever the order range');
    $sent = array_count_values(array_column(agm_sent_records('/api/v1/contacts', 'contacts'), 'email'));
    foreach (array('ann@example.com', 'pam@example.com') as $email) {
        expect_same(1, isset($sent[$email]) ? $sent[$email] : 0, "$email was sent more than once (or not at all)");
    }
    expect_that(!isset($sent['new@example.com']), 'the buyer of an order waiting in the live queue went as history');

    $ann = bulk_sync_contact('ann@example.com');
    expect_same(array('optedIn' => true, 'source' => 'bulk_sync'), $ann['consent'], 'a checkout subscriber was not sent as opted in');
    expect_same('2025-06-03T08:00:00Z', $ann['consentedAt'], 'a checkout subscriber is not dated when their first ticked order was paid');
    expect_same('2025-01-05T10:00:00Z', $ann['customerSince'], 'a checkout subscriber is not dated at their first order');
    $pam = bulk_sync_contact('pam@example.com');
    expect_same('2020-02-02T00:00:00Z', $pam['customerSince'], 'an account sent as a checkout subscriber is not dated at registration');
    expect_same(true, bulk_sync_contact('nod@example.com')['consent']['optedIn'], 'a Not subscribed account that ticked the box at checkout was not sent as opted in');
    $uns = bulk_sync_contact('uns@example.com');
    expect_that(!isset($uns['consent']) && !isset($uns['consentedAt']), 'an account that unsubscribed since was sent as opted in from an old tick');
}

function test_bulk_sync_uses_the_recorded_opt_in_moment_first() {
    agm_p4_user(19, 'at@example.com', array('user_registered' => '2022-01-01 00:00:00'), array('_agumail_optin' => 'yes', '_agumail_optin_at' => '2025-05-05T05:05:05Z'));
    agm_p4_order(160, 'at@example.com', '2024-02-02 10:00:00', 'processing', array('customer_id' => 19), array('_agumail_optin' => 'yes'));
    agm_p4_user(20, 'odd@example.com', array('user_registered' => '2022-02-02 00:00:00'), array('_agumail_optin' => 'yes', '_agumail_optin_at' => 'soon'));
    agm_p4_user(22, 'late@example.com', array('user_registered' => '2022-03-03 00:00:00'), array('_agumail_optin' => 'yes', '_agumail_optin_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * DAY_IN_SECONDS)));
    bulk_sync_run();
    expect_same('2025-05-05T05:05:05Z', bulk_sync_contact('at@example.com')['consentedAt'], 'the recorded opt-in moment did not come before the order');
    expect_same('2022-02-02T00:00:00Z', bulk_sync_contact('odd@example.com')['consentedAt'], 'an unreadable opt-in moment did not fall back to registration');
    expect_same('2022-03-03T00:00:00Z', bulk_sync_contact('late@example.com')['consentedAt'], 'an opt-in moment in the future was trusted');
}

function test_bulk_sync_sends_guests_before_their_orders_and_customers_only_once() {
    bulk_sync_store();
    bulk_sync_run();
    $requests = bulk_sync_requests();
    $orders_at = array_search(array('/api/v1/orders', array('100', '101', '102', '107', '108', '109')), $requests, true);
    expect_that($orders_at !== false && $orders_at > 0, 'the orders were not sent');
    expect_same(array('/api/v1/contacts', array('bob@example.com')), $requests[$orders_at - 1],
        'the guests were not sent right before their orders (or someone sent earlier was sent again)');
}

/** An order that notes whether HPOS was told not to re-save the whole order when its sync flag is saved. */
class Bulk_Sync_Watched_Order extends WC_Order {
    public $guarded = null;
    public function save_meta_data() {
        $this->guarded = has_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false') !== false;
        parent::save_meta_data();
    }
}

function test_bulk_sync_marks_what_it_sent_as_synced() {
    bulk_sync_store();
    $watched = agm_wc_persist(new Bulk_Sync_Watched_Order(110, array('status' => 'processing', 'billing_email' => 'wes@example.com',
        'date_created' => agm_date(strtotime('2025-09-09 10:00:00 UTC')), 'date_paid' => agm_date(strtotime('2025-09-09 10:00:00 UTC')), 'total' => '12')));
    bulk_sync_run();
    expect_same(true, $watched->guarded, 'the sync flag was saved without stopping HPOS from saving (and re-queueing) the whole order');
    expect_that(!has_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false'), 'the HPOS guard was left on');
    foreach (array(100, 101, 102, 107, 108, 109, 110) as $id) {
        expect_same('yes', wc_get_order($id)->get_meta('_agumail_synced'), "order $id was not marked as held by AguMail");
    }
    expect_same('', wc_get_order(103)->get_meta('_agumail_synced'), 'an order that was not sent was marked');
    expect_same(array(), agm_outbox(), 'marking orders queued them again');
}

function test_bulk_sync_leaves_an_order_already_queued_to_the_live_path() {
    bulk_sync_store();
    agumail_enqueue('order', 108);
    bulk_sync_run();
    expect_that(!in_array('108', array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), true),
        'an order waiting to be sent live went as history, which would swallow its post-purchase flow');
    expect_that(!in_array('bob@example.com', array_column(agm_sent_records('/api/v1/contacts', 'contacts'), 'email'), true),
        'the live order\'s buyer went as history');
}

// ── Counts ──

function test_bulk_sync_counts_exactly_what_agumail_reports() {
    bulk_sync_store();
    agm_http_respond(200, array('ok' => true, 'created' => 1, 'updated' => 1, 'suppressedSkipped' => 0, 'overCapacity' => 0));
    agm_http_respond(200, array('ok' => true, 'created' => 1, 'updated' => 1, 'suppressedSkipped' => 0, 'overCapacity' => 0));
    agm_http_respond(200, array('ok' => true, 'contacts' => array(array('email' => 'nia@example.com', 'status' => 'unsubscribed', 'subscribed' => false))));
    agm_http_respond(200, array('ok' => true, 'created' => 1, 'updated' => 0, 'suppressedSkipped' => 1, 'overCapacity' => 2));
    agm_http_respond(200, array('ok' => true, 'upserted' => 1));
    agm_http_respond(200, array('ok' => true, 'created' => 0, 'updated' => 0, 'suppressedSkipped' => 0, 'overCapacity' => 1));
    agm_http_respond(200, array('ok' => true, 'created' => 5, 'updated' => 1, 'duplicates' => 1));
    $state = bulk_sync_run();

    expect_same(array('created' => 3, 'updated' => 2, 'suppressedSkipped' => 1, 'overCapacity' => 3), $state['contacts'], 'contact counts are not what AguMail reported');
    expect_same(array('upserted' => 1), $state['products'], 'product counts are not what AguMail reported');
    expect_same(array('created' => 5, 'updated' => 1, 'duplicates' => 1), $state['orders'], 'order counts are not what AguMail reported');

    $text = bulk_sync_text(agm_p4_section('sync'));
    expect_that(strpos($text, "3 contacts not added: your plan's contact limit") !== false, 'the page does not say how many contacts the plan kept out: ' . $text);
    expect_that(strpos($text, '3 added') !== false && strpos($text, '5 added') !== false, 'the page does not show the counts: ' . $text);
}

function test_bulk_sync_counts_a_refused_record_and_sends_the_rest() {
    bulk_sync_store();
    agm_http_respond(400, array('ok' => false, 'error' => 'contacts.1.email: Invalid email'));
    agm_http_respond(200, array('ok' => true, 'created' => 1, 'updated' => 0, 'suppressedSkipped' => 0, 'overCapacity' => 0));
    $state = bulk_sync_run();
    $requests = bulk_sync_requests();
    expect_same(array('/api/v1/contacts', array('ivy@example.com')), $requests[1], 'the rest of the batch was not sent again without the refused record');
    expect_same(1, $state['refused']['contacts'], 'the refused contact was not counted');
    expect_same(1, $state['contacts']['created'], 'the refused contact was counted as sent');
    expect_same('done', $state['status'], 'one refused record stopped the sync');
}

// ── Waiting, failing, cancelling, resuming ──

function test_bulk_sync_waits_for_retry_after_on_429() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_respond(429, array('ok' => false, 'error' => 'Rate limit', 'retryAfter' => 30), array('Retry-After' => '30'));
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });

    $state = agumail_bulk_state();
    expect_same('running', $state['status'], 'a 429 ended the sync');
    expect_same('subscribers', $state['phase'], 'a 429 moved the sync on');
    expect_same(0, $state['cursor'], 'a 429 moved the cursor past a page AguMail never took');
    $step = agm_scheduled('agumail_bulk_step');
    expect_same(1, count($step), 'the step was not scheduled again');
    expect_same((int) $GLOBALS['bulk_sync_now'] + 30, $step[0]['timestamp'], 'the step does not wait for Retry-After');
    expect_that(agumail_backoff_until() >= (int) $GLOBALS['bulk_sync_now'] + 30, 'the live queue was not told to wait too');

    $GLOBALS['bulk_sync_now'] += 31;
    agm_p4_run_bulk();
    expect_same('done', agumail_bulk_state()['status'], 'the sync did not carry on after the wait');
    $requests = bulk_sync_requests();
    expect_same($requests[0], $requests[1], 'the page AguMail refused was not sent again');
    expect_same(7, count($requests), 'after the wait the sync sent more or less than the rest');
}

function test_bulk_sync_backs_off_on_5xx_and_network_errors() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_respond(503, array('ok' => false, 'error' => 'Unavailable'));
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    $state = agumail_bulk_state();
    expect_same(1, $state['attempts'], 'the failure was not counted');
    $step = agm_scheduled('agumail_bulk_step');
    expect_that($step[0]['timestamp'] >= (int) $GLOBALS['bulk_sync_now'] + 20, 'a failure is retried at once');
    expect_same(0, $state['cursor'], 'a failed page was skipped');

    $GLOBALS['bulk_sync_now'] += 60;
    agm_http_respond(new WP_Error('http_request_failed', 'Connection refused'));
    agm_request('bulk step 2', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    expect_same(2, agumail_bulk_state()['attempts'], 'a network error was not counted');
    expect_that(strpos(bulk_sync_text(agm_p4_section('sync')), 'Connection refused') !== false, 'the page does not say why the sync is waiting');

    $GLOBALS['bulk_sync_now'] += 120;
    agm_p4_run_bulk();
    $state = agumail_bulk_state();
    expect_same('done', $state['status'], 'the sync did not recover');
    expect_same(0, $state['attempts'], 'a success did not reset the failures');
}

function test_bulk_sync_gives_up_after_twenty_failures_in_a_row_and_can_resume() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_default(function () { return array('code' => 500, 'body' => array('ok' => false, 'error' => 'Boom')); });
    for ($i = 0; $i < 25 && agm_scheduled('agumail_bulk_step'); $i++) {
        $GLOBALS['bulk_sync_now'] += 7 * HOUR_IN_SECONDS;
        agm_request('bulk step ' . ($i + 1), 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    }
    $state = agumail_bulk_state();
    expect_same('failed', $state['status'], 'the sync never gave up');
    expect_same(20, count(bulk_sync_requests()), 'the sync gave up after the wrong number of tries');
    expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a given-up sync left a step scheduled');

    agm_http_default(null);
    agm_p4_admin_action('bulk_sync_start', array('agumail_bulk_resume' => '1'));
    agm_p4_run_bulk();
    $state = agumail_bulk_state();
    expect_same('done', $state['status'], 'Try again did not finish the sync');
    expect_same(26, count(bulk_sync_requests()), 'Try again started over instead of carrying on');
}

function test_bulk_sync_cancel_stops_it() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    // Each answer takes half a minute, so a step sends one page and hands over.
    agm_http_default(function () {
        $GLOBALS['bulk_sync_now'] += 30;
        return array('code' => 200, 'body' => array('ok' => true));
    });
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    expect_same(1, count(bulk_sync_requests()), 'a step went on past its time');

    $result = agm_p4_admin_action('bulk_sync_cancel');
    expect_same('cancelled', agumail_bulk_state()['status'], 'the sync was not cancelled');
    expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a cancelled sync left a step scheduled');
    agm_p4_run_bulk();
    expect_same(1, count(bulk_sync_requests()), 'a cancelled sync kept sending');
    expect_that(strpos(bulk_sync_text(agm_p4_section('sync')), 'cancelled') !== false, 'the page does not say the sync was cancelled');
    unset($result);
}

function test_bulk_sync_a_cancel_during_a_step_is_not_undone_by_it() {
    bulk_sync_store();
    bulk_sync_start();
    $id = agumail_bulk_state()['id'];
    agm_http_default(function () use ($id) {
        // The merchant presses Cancel while AguMail answers the first page: the cancel's own option is all that is written yet.
        $GLOBALS['agm']['options']['agumail_bulk_cancel'] = $id;
        return array('code' => 200, 'body' => array('ok' => true, 'created' => 2));
    });
    agm_p4_run_bulk();
    expect_same(1, count(bulk_sync_requests()), 'the step kept sending after the cancel');
    expect_same('cancelled', agumail_bulk_state()['status'], 'the step wrote over the cancel');
    expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a cancelled sync left a step scheduled');
    $step_writes = array_filter(agm_writes('option'), function ($write) {
        return $write['name'] === 'agumail_bulk_cancel' && strpos($write['request'], 'bulk step') === 0;
    });
    expect_same(array(), array_values($step_writes), 'a step wrote the cancel option');
}

function test_bulk_sync_deactivating_the_plugin_cancels_it() {
    bulk_sync_store();
    bulk_sync_start();
    foreach ($GLOBALS['agm']['deactivation'] as $callback) {
        call_user_func($callback);
    }
    expect_same('cancelled', agumail_bulk_state()['status'], 'deactivating left the sync running');
}

function test_bulk_sync_picks_up_where_it_stopped_after_a_fatal_error() {
    bulk_sync_store();
    bulk_sync_start();
    $calls = 0;
    agm_http_default(function ($url, $args) use (&$calls) {
        $calls++;
        if ($calls === 2) {
            throw new Error('PHP died here (out of memory, a timeout)');
        }
        $body = json_decode($args['body'], true);
        return array('code' => 200, 'body' => isset($body['contacts']) ? array('ok' => true, 'created' => count($body['contacts'])) : array('ok' => true));
    });
    try {
        agm_p4_run_bulk();
        $died = false;
    } catch (Error $e) {
        $died = true;
    }
    expect_that($died, 'the simulated fatal did not happen');
    $state = agumail_bulk_state();
    expect_same('checkout_subscribers', $state['phase'], 'the first page was not saved before the fatal');
    expect_same(1, count(agm_scheduled('agumail_bulk_step')), 'nothing was left scheduled to pick the sync up again');

    agm_p4_run_bulk();
    $state = agumail_bulk_state();
    expect_same('done', $state['status'], 'the sync did not finish after the fatal');
    $requests = bulk_sync_requests();
    expect_same(array('ivy@example.com', 'leo@example.com'), $requests[0][1], 'the first page is missing');
    expect_same(1, count(array_filter($requests, function ($r) { return $r[1] === array('ivy@example.com', 'leo@example.com'); })), 'a page AguMail took was sent again');
    expect_same(2 + 2 + 3 + 1, $state['contacts']['created'], 'the counts include the request that died');
}

function test_bulk_sync_a_refused_plan_waits_and_says_why() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_respond(402, array('ok' => false, 'error' => 'Your plan does not include store sync.'));
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    $state = agumail_bulk_state();
    expect_same('running', $state['status'], 'a refused plan ended the sync instead of waiting');
    $step = agm_scheduled('agumail_bulk_step');
    expect_same((int) $GLOBALS['bulk_sync_now'] + 15 * MINUTE_IN_SECONDS, $step[0]['timestamp'], 'a refused plan is not retried in 15 minutes');
    expect_same(402, agumail_connection()['errorCode'], 'the refusal was not recorded for the settings page');
    expect_that(strpos(bulk_sync_text(agm_p4_section('sync')), 'Your plan does not include store sync.') !== false, 'the page does not say why');

    agm_http_default(function () { return array('code' => 402, 'body' => array('ok' => false, 'error' => 'Your plan does not include store sync.')); });
    for ($i = 2; $i <= 4 && agm_scheduled('agumail_bulk_step'); $i++) {
        $GLOBALS['bulk_sync_now'] += 16 * MINUTE_IN_SECONDS;
        agm_request('bulk step ' . $i, 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    }
    $state = agumail_bulk_state();
    expect_same('failed', $state['status'], 'a plan without store sync kept the sync waiting forever');
    expect_same(3, count(bulk_sync_requests()), 'the sync gave up after the wrong number of refusals');
    expect_that(strpos(bulk_sync_text(agm_p4_section('sync')), 'Your plan does not include store sync.') !== false, 'the stopped sync does not say why');
}

function test_bulk_sync_a_plan_without_store_sync_stops_it_and_holds_store_data_only() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    // AguMail takes the contacts on every plan, and refuses products and orders without store sync.
    agm_http_default(function ($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (in_array($path, array('/api/v1/products', '/api/v1/orders'), true)) {
            return array('code' => 402, 'body' => array('ok' => false, 'error' => 'Connecting a store, and sending its orders, carts and products, is on the Commerce plans. Contacts, signups and events work on every plan.',
                'code' => 'PLAN_FEATURE', 'feature' => 'storeSync'));
        }
        return array('code' => 200, 'body' => array('ok' => true));
    });
    for ($i = 1; $i <= 20 && agumail_bulk_state()['status'] === 'running'; $i++) {
        $GLOBALS['bulk_sync_now'] += 16 * MINUTE_IN_SECONDS;
        agm_request('bulk step ' . $i, 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    }
    $state = agumail_bulk_state();
    expect_same('failed', $state['status'], 'the sync did not stop for a plan without store sync');
    expect_that(agumail_store_sync_held(), 'store data was not held');
    expect_that(agumail_connection()['status'] !== 'error', 'a plan without store sync read as a refused store');
    expect_same(0, agumail_backoff_until(), 'everything was paused, signups included');
    expect_same(1, count(array_filter(agm_http_requests(), function ($request) { return $request['path'] === '/api/v1/products'; })),
        'the sync kept asking for store data the plan does not take');
}

// ── Range and bound ──

function test_bulk_sync_sends_all_history_by_default_or_the_last_months_asked() {
    agm_p4_order(120, 'new@example.com', gmdate('Y-m-d H:i:s', strtotime('-2 months')));
    agm_p4_order(121, 'old@example.com', gmdate('Y-m-d H:i:s', strtotime('-14 months')));
    $state = bulk_sync_run(array('agumail_bulk_months' => '12'));
    expect_same(12, $state['months'], 'the range was not kept');
    expect_same(array('120'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), 'the sync did not keep to the last 12 months');

    $GLOBALS['agm']['http'] = array();
    bulk_sync_forget_synced();
    $state = bulk_sync_run();
    expect_same(0, $state['months'], 'all history is not the default');
    expect_same(array('120', '121'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), 'all history did not send every paid order');
}

function test_bulk_sync_stops_at_the_last_order_there_was_when_it_started() {
    bulk_sync_store();
    bulk_sync_start();
    agm_p4_order(200, 'late@example.com', gmdate('Y-m-d H:i:s'));
    agm_p4_run_bulk();
    expect_same(109, agumail_bulk_state()['max_order_id'], 'the bound is not the highest order id at the start');
    expect_that(!in_array('200', array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), true),
        'an order placed after the sync started went as history');
}

function test_bulk_sync_pages_large_stores_by_id() {
    for ($id = 1000; $id < 1250; $id++) {
        agm_p4_order($id, "g$id@example.com", '2025-01-01 00:00:00');
    }
    bulk_sync_run();
    $orders = array_values(array_filter(bulk_sync_requests(), function ($r) { return $r[0] === '/api/v1/orders'; }));
    expect_same(array(100, 100, 50), array_map(function ($r) { return count($r[1]); }, $orders), 'orders should go 100 at a time');
    expect_same(250, count(array_unique(array_merge($orders[0][1], $orders[1][1], $orders[2][1]))), 'an order was skipped or sent twice');
}

function test_bulk_sync_reads_the_order_tables_when_hpos_is_on() {
    $GLOBALS['agm']['hpos'] = true;
    bulk_sync_store();
    $state = bulk_sync_run();
    expect_same('done', $state['status'], 'the sync did not finish with HPOS on');
    expect_same(array('100', '101', '102', '107', '108', '109'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), 'HPOS orders were not sent');
    expect_same('2024-07-09T12:00:00Z', bulk_sync_contact('pam@example.com')['consentedAt'], 'the opted-in order was not found in the order tables');
}

// ── The admin side ──

function test_bulk_sync_only_a_store_manager_with_a_nonce_starts_or_cancels_it() {
    bulk_sync_store();
    agm_sign_in(5, 'clerk@shop.test');
    do_action('init');
    $forbidden = agm_p4_admin_action('bulk_sync_start');
    expect_that(strpos((string) $forbidden['halt'], 'wp_die') === 0, 'a user without manage_woocommerce started a sync');
    expect_same('idle', agumail_bulk_state()['status'], 'a refused start changed the state');

    agm_grant('manage_woocommerce');
    $GLOBALS['agm']['nonces_ok'] = false;
    $stale = agm_p4_admin_action('bulk_sync_start');
    expect_that(strpos((string) $stale['halt'], 'wp_die') === 0, 'a start without a valid nonce ran');
    expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a refused start scheduled a step');
}

function test_bulk_sync_start_schedules_a_step_and_calls_nothing() {
    bulk_sync_store();
    $result = bulk_sync_start();
    expect_same(array(), $result['http'], 'pressing Start called AguMail from the page request');
    $state = agumail_bulk_state();
    expect_same('running', $state['status'], 'the sync did not start');
    expect_same(array('subscribers' => 2, 'checkout_subscribers' => 3, 'customers' => 4, 'products' => 1, 'orders' => 6), $state['totals'], 'the totals the page shows are wrong');
    $step = agm_scheduled('agumail_bulk_step');
    expect_same(1, count($step), 'no step was scheduled');
    expect_same(array($state['id']), $step[0]['args'], 'the step does not name its run');

    $again = agm_p4_admin_action('bulk_sync_start');
    expect_same(1, count(agm_scheduled('agumail_bulk_step')), 'a second start while running scheduled another step');
    unset($again);
}

function test_bulk_sync_a_step_never_runs_from_a_shopper_request() {
    bulk_sync_store();
    bulk_sync_start();
    $id = agumail_bulk_state()['id'];
    $result = agm_request('a shopper request fires a step', 'shopper', function () use ($id) {
        do_action('agumail_bulk_step', $id);
    });
    agm_expect_shopper_quiet($result);
}

function test_bulk_sync_progress_is_polled_through_admin_ajax() {
    bulk_sync_store();
    bulk_sync_start();
    agm_request('settings page', 'admin', function () {
        do_action('admin_enqueue_scripts', 'toplevel_page_agumail');
    });
    $script = agm_script('agumail-admin');
    expect_that($script && $script['enqueued'], 'admin.js is not loaded on the settings page');
    expect_that(isset($script['data']['agumailAdmin']['nonce']), 'admin.js has no nonce to poll with');

    $html = agm_p4_section('sync');
    expect_that(strpos($html, 'data-agumail-bulk') !== false && strpos($html, 'data-status="running"') !== false, 'the page has no progress for the script to follow');
    expect_that(strpos($html, 'bulk_sync_cancel') !== false, 'a running sync offers no Cancel');

    agm_p4_admin_action('bulk_sync_progress', array(), 'shopper_ajax');
    $json = end($GLOBALS['agm']['json']);
    expect_same(true, $json['body']['success'], 'the progress call failed');
    expect_same('running', $json['body']['data']['status'], 'the progress call does not say the sync runs');
    expect_that(strpos($json['body']['data']['html'], 'data-agumail-bulk') !== false, 'the progress call does not return the progress');
}

function test_bulk_sync_the_page_offers_a_range_and_shows_the_last_run() {
    agm_p4_merchant();
    $idle = agm_p4_section('sync');
    expect_that(strpos($idle, 'name="agumail_bulk_months"') !== false, 'the page offers no range');
    expect_that(strpos($idle, 'bulk_sync_start') !== false, 'the page offers no way to start');
    bulk_sync_store();
    bulk_sync_run();
    $text = bulk_sync_text(agm_p4_section('sync'));
    expect_that(strpos($text, 'Finished') !== false, 'the page does not show the finished run: ' . $text);
    expect_that(strpos($text, 'Sync again') !== false, 'the page does not offer to sync again');
}

// ── Review follow-ups ──

function test_bulk_sync_a_page_that_keeps_crashing_is_halved_then_stops() {
    for ($id = 200; $id < 500; $id++) {
        agm_p4_user($id, "sub$id@example.com", array(), array('_agumail_optin' => 'yes'));
    }
    bulk_sync_start();
    agm_http_default(function () {
        throw new Error('Allowed memory size exhausted');
    });
    for ($i = 0; $i < 10 && agm_scheduled('agumail_bulk_step'); $i++) {
        try {
            agm_request('bulk step ' . ($i + 1), 'cron', function () { agm_run_actions('agumail_bulk_step'); });
        } catch (Error $e) {
            // PHP died; Action Scheduler's next run finds the step left behind.
        }
    }
    $sizes = array_map(function ($r) { return count($r[1]); }, bulk_sync_requests());
    expect_same(array(250, 125, 62, 31, 15), $sizes, 'a page that crashed was not halved each time');
    $state = agumail_bulk_state();
    expect_same('failed', $state['status'], 'a page that crashed five times did not stop the sync');
    expect_that(strpos($state['error'], 'memory') !== false, 'the stop does not say why in plain words: ' . $state['error']);
    expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a stopped sync left a step scheduled');
}

function test_bulk_sync_a_crash_after_progress_is_not_counted_and_a_good_page_resets_the_count() {
    for ($id = 200; $id < 800; $id++) {
        agm_p4_user($id, "sub$id@example.com", array(), array('_agumail_optin' => 'yes'));
    }
    bulk_sync_start();
    $calls = 0;
    agm_http_default(function ($url, $args) use (&$calls) {
        $calls++;
        if ($calls === 2 || $calls === 3) {
            throw new Error('Allowed memory size exhausted');
        }
        return array('code' => 200, 'body' => array('ok' => true, 'created' => count(json_decode($args['body'], true)['contacts'])));
    });
    for ($i = 0; $i < 10 && agm_scheduled('agumail_bulk_step'); $i++) {
        try {
            agm_request('bulk step ' . ($i + 1), 'cron', function () { agm_run_actions('agumail_bulk_step'); });
        } catch (Error $e) {
        }
    }
    $state = agumail_bulk_state();
    expect_same('done', $state['status'], 'the sync did not recover from two crashes');
    expect_same(0, $state['crashes'], 'a good page did not reset the crash count');
    expect_same(600, $state['contacts']['created'], 'an account was lost or counted twice');
}

function test_bulk_sync_paid_means_what_the_live_path_counts_as_a_purchase() {
    // A paid date, or a paid status (wc_get_is_paid_statuses(): processing, completed, and any a store adds there): cash on delivery counts once processing.
    agm_p4_order(170, 'ship@example.com', '2025-04-01 10:00:00', 'shipped', array('date_paid' => agm_date(strtotime('2025-04-01 10:00:00 UTC'))));
    agm_p4_order(171, 'cod@example.com', '2025-04-02 10:00:00', 'processing', array('date_paid' => null, 'payment_method' => 'cod'));
    agm_p4_order(172, 'hold@example.com', '2025-04-03 10:00:00', 'on-hold', array('date_paid' => agm_date(strtotime('2025-04-03 10:00:00 UTC'))));
    agm_p4_order(173, 'can@example.com', '2025-04-04 10:00:00', 'cancelled', array('date_paid' => agm_date(strtotime('2025-04-04 10:00:00 UTC'))));
    agm_p4_order(174, 'trash@example.com', '2025-04-05 10:00:00', 'trash', array('date_paid' => agm_date(strtotime('2025-04-05 10:00:00 UTC'))));
    agm_p4_order(175, 'draft@example.com', '2025-04-06 10:00:00', 'checkout-draft', array('date_paid' => agm_date(strtotime('2025-04-06 10:00:00 UTC'))));
    agm_p4_order(176, 'bacs@example.com', '2025-04-07 10:00:00', 'on-hold', array('date_paid' => null, 'payment_method' => 'bacs'));
    foreach (array(false, true) as $hpos) {
        $GLOBALS['agm']['hpos'] = $hpos;
        $GLOBALS['agm']['http'] = array();
        delete_option('agumail_bulk_state');
        bulk_sync_forget_synced();
        bulk_sync_run();
        expect_same(array('170', '171', '172'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'),
            'purchases (a paid date, or a paid status) were not told from unpaid, cancelled, trashed and draft orders' . ($hpos ? ' (HPOS)' : ''));
    }
}

function test_bulk_sync_leaves_accounts_made_after_it_started_to_the_live_path() {
    bulk_sync_store();
    bulk_sync_start();
    agm_p4_user(60, 'late@example.com', array(), array('_agumail_optin' => 'yes'));
    agm_p4_user(61, 'later@example.com');
    agm_p4_run_bulk();
    expect_same(17, agumail_bulk_state()['max_user_id'], 'the bound is not the highest user id at the start');
    $sent = array_column(agm_sent_records('/api/v1/contacts', 'contacts'), 'email');
    expect_that(!in_array('late@example.com', $sent, true) && !in_array('later@example.com', $sent, true), 'an account made after the sync started went as history');
}

function test_bulk_sync_leaves_accounts_and_orders_the_live_path_holds() {
    bulk_sync_store();
    // An account change waiting to be sent, and one AguMail refused: both are the live path's.
    agumail_enqueue('user', 12);
    agumail_enqueue('user', 16, array('answer' => 'yes', 'source' => 'my_account'));
    $row = agm_store()->find('user', '16');
    agm_store()->park($row['id'], $row['version'], 'Boom', agumail_mysql_time());
    // Orders the live path already delivered.
    wc_get_order(100)->meta['_agumail_synced'] = 'yes';
    wc_get_order(101)->meta['_agumail_synced'] = 'yes';
    bulk_sync_run();
    $contacts = array_column(agm_sent_records('/api/v1/contacts', 'contacts'), 'email');
    expect_that(!in_array('max@example.com', $contacts, true), 'an account waiting in the live queue went as history');
    expect_that(!in_array('pam@example.com', $contacts, true), 'an account with a parked answer went as history');
    expect_that(!in_array('ann@example.com', $contacts, true), 'a buyer the live path delivered was stamped as history');
    $orders = array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId');
    expect_that(!in_array('100', $orders, true) && !in_array('101', $orders, true), 'an order the live path delivered was sent again as history');
}

function test_bulk_sync_stops_between_requests_when_its_time_is_up() {
    for ($id = 180; $id < 190; $id++) {
        agm_p4_order($id, "g$id@example.com", '2025-05-01 10:00:00');
    }
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_default(function ($url, $args) {
        $GLOBALS['bulk_sync_now'] += 25;
        return array('code' => 200, 'body' => array('ok' => true));
    });
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    expect_same(array('/api/v1/contacts'), array_column(bulk_sync_requests(), 0), 'the step sent another request after its time was up');
    agm_p4_run_bulk();
    expect_same(array('/api/v1/contacts', '/api/v1/orders'), array_column(bulk_sync_requests(), 0), 'the next step sent the page\'s contacts again, or not its orders');
    expect_same('done', agumail_bulk_state()['status'], 'the sync did not finish');
}

function test_bulk_sync_a_smaller_page_after_a_timeout_does_not_resend_what_went() {
    for ($id = 1000; $id < 1100; $id++) {
        agm_p4_order($id, "g$id@example.com", '2025-01-01 00:00:00');
    }
    bulk_sync_clock();
    bulk_sync_start();
    agm_http_respond(200, array('ok' => true, 'created' => 100));
    agm_http_respond(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 60000 milliseconds'));
    agm_request('bulk step 1', 'cron', function () { agm_run_actions('agumail_bulk_step'); });
    $GLOBALS['bulk_sync_now'] += 120;
    agm_p4_run_bulk();
    $requests = bulk_sync_requests();
    $contacts = array_values(array_filter($requests, function ($r) { return $r[0] === '/api/v1/contacts'; }));
    $orders = array_values(array_filter($requests, function ($r) { return $r[0] === '/api/v1/orders'; }));
    expect_same(1, count($contacts), 'contacts AguMail already took were sent again after the page was made smaller');
    expect_same(array(100, 50, 50), array_map(function ($r) { return count($r[1]); }, $orders), 'the page was not made smaller after the timeout');
    expect_same('done', agumail_bulk_state()['status'], 'the sync did not finish');
}

function test_bulk_sync_a_database_error_is_retried_not_taken_as_the_end() {
    bulk_sync_store();
    bulk_sync_clock();
    bulk_sync_start();
    $GLOBALS['agm']['p4_db_errors'] = array("/'shop_order'/" => 1, '/^SELECT LOWER\(/' => 1);
    agm_p4_run_bulk(3);
    $state = agumail_bulk_state();
    expect_same('running', $state['status'], 'a database error ended the sync');
    expect_that(strpos($state['error'], 'database') !== false, 'the wait does not say the database failed: ' . $state['error']);
    for ($i = 0; $i < 5 && agumail_bulk_state()['status'] === 'running'; $i++) {
        $GLOBALS['bulk_sync_now'] += 7 * HOUR_IN_SECONDS;
        agm_p4_run_bulk();
    }
    expect_same('done', agumail_bulk_state()['status'], 'the sync did not recover from the database errors');
    expect_same(array('100', '101', '102', '107', '108', '109'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), 'orders were lost to a database error');
}

function test_bulk_sync_looks_buyers_up_from_postmeta_on_posts_storage() {
    global $wpdb;
    bulk_sync_store();
    bulk_sync_run();
    $lookups = $wpdb->statements('/^SELECT (LOWER\(e\.meta_value\)|c\.meta_value AS user_id)/');
    expect_that(count($lookups) >= 2, 'the buyer lookups did not run');
    foreach ($lookups as $sql) {
        expect_that((bool) preg_match('/ FROM wp_postmeta [ec] STRAIGHT_JOIN wp_posts p ON p\.ID = [ec]\.post_id /', $sql),
            'a buyer lookup does not start from postmeta: ' . $sql);
    }
}

function test_bulk_sync_admin_js_stops_on_403_and_says_to_reload($plugin_dir) {
    agm_p4_merchant();
    agm_request('settings page', 'admin', function () {
        do_action('admin_enqueue_scripts', 'toplevel_page_agumail');
    });
    $data = agm_script('agumail-admin')['data']['agumailAdmin'];
    expect_same('Reload this page to see progress.', isset($data['reload']) ? $data['reload'] : null, 'admin.js has no words for a lost session');
    $js = file_get_contents($plugin_dir . '/assets/js/admin.js');
    expect_that(strpos($js, '403') !== false && strpos($js, 'config.reload') !== false, 'admin.js does not stop and show the words on a 403');
}

function test_bulk_sync_will_not_start_when_the_database_does_not_answer() {
    bulk_sync_store();
    // Where the store ends, and how many paid orders there are: a count it refuses is not 0.
    foreach (array('/^wc_get_orders/', '/^SELECT COUNT\(\*\) FROM wp_posts p LEFT JOIN/') as $pattern) {
        $GLOBALS['agm']['p4_db_errors'] = array($pattern => 1);
        bulk_sync_start();
        expect_same('idle', agumail_bulk_state()['status'], "a sync started after the database refused $pattern");
        expect_same(array(), agm_scheduled('agumail_bulk_step'), 'a refused start scheduled a step');
    }
}

function test_bulk_sync_reads_all_history_on_a_database_that_refuses_to_compare_a_date_with_nothing() {
    global $wpdb;
    // MySQL 8 answers "Incorrect DATETIME value: ''" (the P4 fake does too): one customer, all history, as on a small store.
    agm_p4_user(12, 'max@example.com', array('user_registered' => '2022-03-03 08:00:00'));
    agm_p4_order(102, 'max@example.com', '2025-02-02 10:00:00', 'processing', array('customer_id' => 12));
    agm_p4_order(110, 'guest@example.com', '2025-02-03 10:00:00', 'completed');
    foreach (array(false, true) as $hpos) {
        $GLOBALS['agm']['hpos'] = $hpos;
        $GLOBALS['agm']['http'] = array();
        delete_option('agumail_bulk_state');
        bulk_sync_forget_synced();
        $state = bulk_sync_run();
        $label = $hpos ? ' (HPOS)' : ' (posts)';
        expect_same('done', $state['status'], "the sync did not finish$label: " . $state['error']);
        expect_same(2, $state['totals']['orders'], "the paid orders were not counted$label");
        expect_same(array('102', '110'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'), "the orders were not sent$label");
        expect_same(array(), agm_logs('could not read the store database'), "a read failed$label");
    }
    expect_same(array(), $wpdb->statements("/_gmt\\s*>=\\s*''/"), 'a query compared a date with an empty string');
}

function test_bulk_sync_the_wait_says_what_the_database_said() {
    // One customer: their opt-in lookup fails, and the next lookup and the log would each clear $wpdb->last_error.
    agm_p4_user(12, 'max@example.com', array('user_registered' => '2022-03-03 08:00:00'));
    bulk_sync_clock();
    bulk_sync_start();
    $GLOBALS['agm']['p4_db_errors'] = array('/ AS user_id, MIN\(/' => 1);
    agm_p4_run_bulk(1);
    $state = agumail_bulk_state();
    expect_same('customers', $state['phase'], 'the failure was not in the customers phase');
    expect_that(strpos($state['error'], 'Lost connection to MySQL server during query') !== false, "the wait lost the database's own words: " . $state['error']);
    $text = bulk_sync_text(agumail_bulk_progress()['html']);
    expect_that(strpos($text, '(the store database did not answer: Lost connection to MySQL server during query)') !== false, 'the page does not show the database error: ' . $text);
}

// ── Cross-cutting review ──

function test_bulk_sync_an_account_opts_in_only_by_orders_under_its_own_address() {
    agm_p4_user(25, 'acct@example.com', array('user_registered' => '2023-01-01 00:00:00'));
    // Their order, billed to someone else's address with the box ticked (a gift, a shared card).
    agm_p4_order(180, 'Gift@Example.com', '2025-03-01 10:00:00', 'processing', array('customer_id' => 25), array('_agumail_optin' => 'yes'));
    foreach (array(false, true) as $hpos) {
        $GLOBALS['agm']['hpos'] = $hpos;
        $GLOBALS['agm']['http'] = array();
        delete_option('agumail_bulk_state');
        bulk_sync_forget_synced();
        bulk_sync_run();
        $label = $hpos ? ' (HPOS)' : ' (posts)';
        $account = bulk_sync_contact('acct@example.com');
        expect_that(!isset($account['consent']) && !isset($account['consentedAt']), "an account was opted in by a tick under another address$label");
        expect_same(true, bulk_sync_contact('gift@example.com')['consent']['optedIn'], "the address that ticked the box was not opted in$label");
    }
}

function test_bulk_sync_a_buyer_whose_ticked_order_waits_in_the_live_queue_still_gets_a_contact() {
    agm_p4_order(185, 'hl@example.com', '2025-02-01 10:00:00', 'processing');
    agm_p4_order(186, 'hl@example.com', '2025-02-02 10:00:00', 'processing', array(), array('_agumail_optin' => 'yes'));
    agumail_enqueue('order', 186);
    bulk_sync_run();
    $requests = bulk_sync_requests();
    $orders_at = null;
    foreach ($requests as $i => $request) {
        if ($request[0] === '/api/v1/orders') {
            $orders_at = $i;
        }
    }
    expect_same(array('185'), $requests[$orders_at][1], 'the older order was not sent (or the live one was)');
    expect_same(array('/api/v1/contacts', array('hl@example.com')), $requests[$orders_at - 1], 'the older order went without its buyer');
    $contact = bulk_sync_contact('hl@example.com');
    expect_that(!isset($contact['consent']) && !isset($contact['consentedAt']), 'the buyer was opted in as history; the live order carries the tick');
}

function test_bulk_sync_unsubscribes_an_account_only_when_agumail_does_not_know_the_address() {
    agm_p4_user(26, 'gone@example.com', array(), array('_agumail_optin' => 'unsubscribed'));
    agm_p4_user(27, 'back@example.com', array(), array('_agumail_optin' => 'unsubscribed'));
    agm_http_default(function ($url, $args) {
        $body = json_decode($args['body'], true);
        if (isset($body['emails'])) {
            return array('code' => 200, 'body' => array('ok' => true, 'contacts' => array(
                array('email' => 'gone@example.com', 'status' => null, 'subscribed' => false),
                array('email' => 'back@example.com', 'status' => 'active', 'subscribed' => true),
            )));
        }
        return array('code' => 200, 'body' => array('ok' => true));
    });
    bulk_sync_run();
    $status = agm_http_requests('/api/v1/contacts/status');
    expect_same(1, count($status), 'the addresses were not checked in one request');
    expect_same(array('gone@example.com', 'back@example.com'), $status[0]['body']['emails'], 'the wrong addresses were checked');
    expect_same(true, isset(bulk_sync_contact('gone@example.com')['unsubscribed']), 'an unsubscribed account AguMail never heard of was not sent as unsubscribed');
    $back = bulk_sync_contact('back@example.com');
    expect_that(!isset($back['unsubscribed']) && !isset($back['consent']), 'an account that came back in AguMail was unsubscribed again');
}

function test_bulk_sync_an_order_is_synced_for_the_account_that_holds_it() {
    update_option('agumail_connection', array('status' => 'connected', 'userId' => 'usr_1'));
    agm_p4_order(187, 'a187@example.com', '2025-01-01 10:00:00', 'processing', array(), array('_agumail_synced' => 'usr_old'));
    agm_p4_order(188, 'a188@example.com', '2025-01-02 10:00:00', 'processing', array(), array('_agumail_synced' => 'yes'));
    agm_p4_order(189, 'a189@example.com', '2025-01-03 10:00:00', 'processing', array(), array('_agumail_synced' => 'usr_1'));
    agm_p4_order(190, 'a190@example.com', '2025-01-04 10:00:00', 'processing');
    bulk_sync_run();
    expect_same(array('187', '190'), array_column(agm_sent_records('/api/v1/orders', 'orders'), 'externalId'),
        'orders held by this AguMail account were sent again, or one synced to another account was skipped');
    expect_same('usr_1', wc_get_order(190)->get_meta('_agumail_synced'), 'the sent order was not stamped with the account that holds it');
    expect_same('usr_1', wc_get_order(187)->get_meta('_agumail_synced'), 'the re-sent order was not stamped with the account that holds it now');
}

function test_bulk_sync_leaves_the_object_cache_alone_when_it_cannot_flush_its_runtime_part() {
    $GLOBALS['agm']['cache_unsupported'] = array('flush_runtime');
    bulk_sync_store();
    bulk_sync_run();
    expect_same(0, isset($GLOBALS['agm']['runtime_flushes']) ? $GLOBALS['agm']['runtime_flushes'] : 0, 'the runtime cache was flushed on a cache that does not support it');
}

function test_bulk_sync_every_translated_string_with_a_placeholder_says_what_it_holds($plugin_dir) {
    $missing = array();
    foreach (array('includes/customers.php', 'includes/bulk-sync.php', 'includes/status.php') as $file) {
        $lines = file($plugin_dir . '/' . $file);
        foreach ($lines as $i => $line) {
            if (!preg_match("/\\b(?:__|_n|esc_html__)\\(\\s*(['\"])[^'\"]*%(?:\\d\\\$)?[sd]/", $line)) {
                continue;
            }
            $before = implode('', array_slice($lines, max(0, $i - 2), 3));
            if (strpos($before, 'translators:') === false) {
                $missing[] = $file . ':' . ($i + 1);
            }
        }
    }
    expect_same(array(), $missing, 'strings with placeholders have no translators comment');
}
