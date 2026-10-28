<?php
// Bulk sync: loading the store's past into AguMail, as history.
//
// "Sync store data" starts a job that Action Scheduler runs in steps of about
// 20 seconds (agumail_bulk_step). A step pages through the store by id, in
// five phases, each finished before the next begins:
//   1. accounts that opted in (our _agumail_optin answer, or the older
//      marketing_opt_in key when we hold no answer);
//   1b. shoppers who ticked the box at checkout (paid orders a checkout
//      created, with the box ticked) and gave no other answer since, whatever
//      the order range;
//   2. the other customers (the customer role, or a paying customer);
//   3. published products;
//   4. paid orders, each page's buyers not sent yet going as contacts before
//      their orders; all history by default or the last N months.
// Subscribers go first so a plan's contact limit keeps them rather than
// whoever came first. A purchase is what the live path counts as one: a paid
// date or a paid status (cash on delivery counts once processing), never a
// cancelled, refunded, failed, trashed or draft order.
//
// What the live path holds stays with it. No phase reads past the highest
// account or order id there was when the sync started; an account or order
// waiting in the live queue (or refused there) is skipped; so is an order the
// live path already delivered (_agumail_synced), so a recent buyer is never
// stamped as history. Every request says backfill: true: AguMail stores all
// of it and starts no flow for any of it.
//
// A step sends the records itself rather than queueing them in the outbox:
//   - counts: each answer's created, updated, suppressedSkipped, overCapacity,
//     upserted and duplicates add up to exactly what AguMail did;
//   - order: a phase is done before the next begins, which a queue sending
//     every type round by round cannot promise;
//   - the live queue stays small, and a checkout's order is never stuck behind
//     ten thousand old ones;
//   - progress, cancel and resume are one cursor per phase in one option.
// What AguMail answers is read with the drain's own rules: a record it names
// as invalid is counted as refused and the rest go again; 429 waits for
// Retry-After (and holds the live queue as long, since both share the key's
// limit); 401 and 403 wait 15 minutes and show why; 402 (a plan without store
// sync) does too, and stops the sync after three; a 5xx, a network error or a
// database error waits 30 seconds, doubling to 6 hours, and the sync stops
// after 20 in a row, to carry on with "Try again".
//
// Every accepted request is saved before the next one goes, with the records
// of the page AguMail already took (kept by id, so a smaller page after a
// timeout sends none of them again), and a step stops between requests once
// its time is up. Each step first schedules its own replacement ten minutes
// out: if PHP dies halfway (memory, a timeout), that step picks the sync up
// from the last page saved. A step that dies before saving anything counts as
// a crash: the page is halved each time, and five in a row stop the sync.
// Cancel lives in its own option, which only the merchant's cancel (or
// deactivating the plugin) writes; a step checks it before and after every
// request, and never writes over it.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_BULK_BUDGET', 20);
define('AGUMAIL_BULK_WATCHDOG', 10 * MINUTE_IN_SECONDS);
define('AGUMAIL_BULK_MAX_ATTEMPTS', 20);
define('AGUMAIL_BULK_MAX_CRASHES', 5);
define('AGUMAIL_BULK_MAX_PLAN_REFUSALS', 3);
define('AGUMAIL_BULK_MAX_SHRINK', 32);
define('AGUMAIL_BULK_PAUSED_RETRY', 15 * MINUTE_IN_SECONDS);
define('AGUMAIL_BULK_MAX_MONTHS', 240);
define('AGUMAIL_BULK_CANCEL', 'agumail_bulk_cancel');

add_action('init', 'agumail_bulk_register_admin');
add_action('admin_enqueue_scripts', 'agumail_bulk_enqueue_script');
agumail_add_background_action('agumail_bulk_step', 'agumail_bulk_step', 10, 1);
register_deactivation_hook(AGUMAIL_FILE, 'agumail_bulk_deactivate');

function agumail_bulk_phases() {
    return array('subscribers', 'checkout_subscribers', 'customers', 'products', 'orders');
}

/** Rows read per page: an orders page sends its guests and its orders, a request each. */
function agumail_bulk_page_sizes() {
    return array('subscribers' => 250, 'checkout_subscribers' => 250, 'customers' => 250, 'products' => 250, 'orders' => 100);
}

// ─────────────────────────────────────────────────────────────────────────────
// State, in the option agumail_bulk_state; the cancel, in agumail_bulk_cancel.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_bulk_default_state() {
    $zero = array_fill_keys(agumail_bulk_phases(), 0);
    return array(
        'id'            => '',
        'status'        => 'idle',        // idle, running, done, cancelled, failed
        'phase'         => '',
        'cursor'        => 0,             // the last id of the phase already sent
        'months'        => 0,             // 0 for all history
        'since'         => '',            // orders placed from this UTC time on, or '' for all
        'max_order_id'  => 0,
        'max_user_id'   => 0,
        'started_at'    => 0,
        'finished_at'   => 0,
        'updated_at'    => 0,
        'retry_at'      => 0,
        'wait'          => '',            // why a step waits: rate, retry, auth, paused
        'attempts'      => 0,             // failures in a row
        'plan_refusals' => 0,             // 402s in a row
        'crashes'       => 0,             // steps in a row that died before saving anything
        'step'          => 0,             // 1 while a step works
        'step_mark'     => '',            // where that step started
        'error'         => '',
        'shrink'        => 1,             // pages are divided by this after a timeout or a crash
        'totals'        => $zero,
        'done'          => $zero,
        'contacts'      => array('created' => 0, 'updated' => 0, 'suppressedSkipped' => 0, 'overCapacity' => 0),
        'products'      => array('upserted' => 0),
        'orders'        => array('created' => 0, 'updated' => 0, 'duplicates' => 0),
        'refused'       => array('contacts' => 0, 'products' => 0, 'orders' => 0),
        'skipped'       => array('contacts' => 0, 'orders' => 0),
        // Records of the current page AguMail took or refused, by the id they
        // belong to: u:user, b:ticked order, g:first order in range, p:product, o:order.
        'page'          => array('done' => array(), 'refused' => array()),
    );
}

/** Whether the merchant cancelled this run. Read past any cache: another request writes it. */
function agumail_bulk_cancel_requested($run_id) {
    if ((string) $run_id === '') {
        return false;
    }
    wp_cache_delete(AGUMAIL_BULK_CANCEL, 'options');
    wp_cache_delete('notoptions', 'options');
    return (string) get_option(AGUMAIL_BULK_CANCEL, '') === (string) $run_id;
}

/** The state, read fresh: the merchant may have cancelled from another request. */
function agumail_bulk_state() {
    wp_cache_delete('agumail_bulk_state', 'options');
    $saved = get_option('agumail_bulk_state', array());
    $state = array_replace_recursive(agumail_bulk_default_state(), is_array($saved) ? $saved : array());
    if ($state['status'] === 'running' && agumail_bulk_cancel_requested($state['id'])) {
        $state['status'] = 'cancelled';
    }
    return $state;
}

/** A step saves its progress only while its run is still the one running; never over a cancel. */
function agumail_bulk_save(array $state) {
    $stored = agumail_bulk_state();
    if ($stored['id'] === '' || $stored['id'] !== $state['id'] || $stored['status'] !== 'running') {
        return false;
    }
    $state['updated_at'] = agumail_time();
    update_option('agumail_bulk_state', $state, false);
    return true;
}

/** Replaces this run's pending step with one in $delay seconds (at once for 0). */
function agumail_bulk_schedule($run_id, $delay) {
    if (!function_exists('as_schedule_single_action')) {
        return false;
    }
    as_unschedule_all_actions('agumail_bulk_step', array((string) $run_id), AGUMAIL_GROUP);
    if ($delay <= 0 && function_exists('as_enqueue_async_action')) {
        return (bool) as_enqueue_async_action('agumail_bulk_step', array((string) $run_id), AGUMAIL_GROUP);
    }
    return (bool) as_schedule_single_action(agumail_time() + max(1, (int) $delay), 'agumail_bulk_step', array((string) $run_id), AGUMAIL_GROUP);
}

/** Where a step starts: a step that dies leaves this unchanged. */
function agumail_bulk_mark(array $state) {
    return $state['phase'] . '|' . (int) $state['cursor'] . '|' . count($state['page']['done']) . '|' . count($state['page']['refused']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Starting, cancelling, following. Admin actions: the settings page checks the
// capability and nonce; none of these calls AguMail.
// ─────────────────────────────────────────────────────────────────────────────

/** A store's section, offered while its store data goes to AguMail (agumail_store_sync_on()). */
function agumail_bulk_register_admin() {
    agumail_admin_register_action('bulk_sync_cancel', 'agumail_bulk_cancel');
    agumail_admin_register_action('bulk_sync_progress', 'agumail_bulk_progress');
    if (!agumail_store_sync_on()) {
        return;
    }
    agumail_admin_register_section('sync', __('Store data', 'agumail'), 'agumail_bulk_render_section', 20, array(
        'icon'        => 'database',
        'description' => __('Customers, products and order history, sent in the background.', 'agumail'),
    ));
    agumail_admin_register_action('bulk_sync_start', 'agumail_bulk_start', array(
        'agumail_bulk_resume' => 'text',
        'agumail_bulk_months' => 'int',
    ));
}

function agumail_bulk_start(array $request = array()) {
    if (!agumail_has_api_key()) {
        return new WP_Error('agumail_not_connected', __('Connect the store to AguMail first.', 'agumail'));
    }
    $state = agumail_bulk_state();
    if ($state['status'] === 'running') {
        return array('type' => 'info', 'message' => __('The store data sync is already running.', 'agumail'));
    }
    if (!empty($request['agumail_bulk_resume']) && $state['status'] === 'failed' && $state['id'] !== '') {
        $state = array_merge($state, array('status' => 'running', 'attempts' => 0, 'plan_refusals' => 0, 'crashes' => 0, 'step' => 0,
            'error' => '', 'wait' => '', 'retry_at' => 0, 'finished_at' => 0));
        update_option('agumail_bulk_state', $state, false);
        agumail_bulk_schedule($state['id'], 0);
        agumail_log('info', 'Store data sync resumed.');
        return array('message' => __('The sync carries on from where it stopped.', 'agumail'));
    }

    $max_order_id = agumail_bulk_max_order_id();
    $max_user_id = agumail_bulk_max_user_id();
    if ($max_order_id === null || $max_user_id === null) {
        return new WP_Error('agumail_database', __('The store database did not answer. Try again in a moment.', 'agumail'));
    }
    $months = isset($request['agumail_bulk_months']) ? min(AGUMAIL_BULK_MAX_MONTHS, absint($request['agumail_bulk_months'])) : 0;
    $state = agumail_bulk_default_state();
    $state['id'] = wp_generate_uuid4();
    $state['status'] = 'running';
    $state['phase'] = 'subscribers';
    $state['months'] = $months;
    $state['since'] = $months > 0 ? gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months', agumail_time())) : '';
    $state['started_at'] = agumail_time();
    $state['max_order_id'] = $max_order_id;
    $state['max_user_id'] = $max_user_id;
    foreach (agumail_bulk_phases() as $phase) {
        $total = agumail_bulk_count($phase, $state);
        // A count the database refuses is a page it refuses too: say so now, not 0 and a sync that waits forever.
        if ($total === null) {
            return new WP_Error('agumail_database', __('The store database did not answer. Try again in a moment.', 'agumail'));
        }
        $state['totals'][$phase] = $total;
    }
    update_option('agumail_bulk_state', $state, false);
    agumail_bulk_schedule($state['id'], 0);
    agumail_log('info', sprintf('Store data sync started: %d subscribed accounts, %d ticked checkout orders, %d other customers, %d products, %d paid orders (%s).',
        $state['totals']['subscribers'], $state['totals']['checkout_subscribers'], $state['totals']['customers'], $state['totals']['products'],
        $state['totals']['orders'], $months > 0 ? sprintf('the last %d months', $months) : 'all history'));
    return array('message' => __('Store data sync started. It runs in the background, so you can leave this page.', 'agumail'));
}

/** Marks the running sync cancelled: its own option first (what steps check), then the state for the page. */
function agumail_bulk_mark_cancelled(array $state) {
    update_option(AGUMAIL_BULK_CANCEL, $state['id'], false);
    $state = array_merge($state, array('status' => 'cancelled', 'finished_at' => agumail_time(), 'retry_at' => 0, 'wait' => '', 'step' => 0));
    update_option('agumail_bulk_state', $state, false);
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('agumail_bulk_step');
    }
}

function agumail_bulk_cancel() {
    $state = agumail_bulk_state();
    if ($state['status'] !== 'running') {
        return array('type' => 'info', 'message' => __('No sync is running.', 'agumail'));
    }
    agumail_bulk_mark_cancelled($state);
    agumail_log('info', 'Store data sync cancelled.');
    return array('message' => __('Sync cancelled. What was already sent stays in AguMail.', 'agumail'));
}

/** Deactivating the plugin stops its jobs; a sync that was running shows as cancelled, not stuck. */
function agumail_bulk_deactivate() {
    $state = agumail_bulk_state();
    if ($state['status'] === 'running') {
        agumail_bulk_mark_cancelled($state);
        agumail_log('info', 'Store data sync cancelled: the plugin was deactivated.');
    }
}

/** For admin.js: the status and the progress block, rendered. */
function agumail_bulk_progress() {
    $state = agumail_bulk_state();
    ob_start();
    agumail_bulk_render_panel($state);
    return array('status' => $state['status'], 'html' => ob_get_clean());
}

function agumail_bulk_enqueue_script($hook_suffix = '') {
    if ($hook_suffix !== agumail_admin_hook_suffix() || !current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return;
    }
    wp_enqueue_script('agumail-admin', plugins_url('assets/js/admin.js', AGUMAIL_FILE), array(), AGUMAIL_VERSION, true);
    wp_localize_script('agumail-admin', 'agumailAdmin', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => agumail_admin_nonce('bulk_sync_progress'),
        'interval' => 3000,
        'reload'   => __('Reload this page to see progress.', 'agumail'),
    ));
}

// ─────────────────────────────────────────────────────────────────────────────
// A step.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Runs the sync for about 20 seconds. Returns why it stopped: more (another
 * step is scheduled), wait, done, failed, cancelled, stale (not the running
 * sync) or refused (fired from a request that may not call AguMail).
 */
function agumail_bulk_step($run_id = '') {
    if (!agumail_http_allowed()) {
        return 'refused';
    }
    $state = agumail_bulk_state();
    if ($state['status'] !== 'running' || $state['id'] === '' || $state['id'] !== (string) $run_id) {
        return $state['status'] === 'cancelled' && $state['id'] === (string) $run_id ? agumail_bulk_abandon($state) : 'stale';
    }
    // If this request dies halfway, this carries the sync on from the last page saved.
    agumail_bulk_schedule($state['id'], AGUMAIL_BULK_WATCHDOG);
    if (!agumail_has_api_key()) {
        return agumail_bulk_finish($state, 'failed', __('The store is no longer connected to AguMail.', 'agumail'));
    }
    if (agumail_store_sync_held()) {
        return agumail_bulk_finish($state, 'failed', __('Your AguMail plan does not include store sync, so the store data sync stopped. Resume it once your plan includes store sync.', 'agumail'));
    }
    // The step before started and saved nothing: the page it read stopped PHP.
    if (!empty($state['step']) && $state['step_mark'] === agumail_bulk_mark($state)) {
        $state['crashes']++;
        $state['shrink'] = min(AGUMAIL_BULK_MAX_SHRINK, max(1, (int) $state['shrink']) * 2);
        agumail_log('warning', sprintf('A store data sync step stopped before it could save anything (%d in a row); pages are smaller now.', $state['crashes']));
        if ($state['crashes'] >= AGUMAIL_BULK_MAX_CRASHES) {
            return agumail_bulk_finish($state, 'failed', __('A page of store data stopped the sync five times in a row, most likely for lack of memory or time. Try again, or ask your host to raise the PHP memory limit.', 'agumail'));
        }
    }
    $state['step'] = 0;

    $now = agumail_time();
    $until = max((int) $state['retry_at'], agumail_backoff_until());
    if (agumail_backoff_until() > $now && $state['wait'] === '') {
        $state['wait'] = 'rate';
    }
    // Sending is paused, or WooCommerce is not active right now: the sync waits for both.
    if (agumail_drain_paused() || !agumail_store_connected()) {
        $until = max($until, $now + AGUMAIL_BULK_PAUSED_RETRY);
        $state['wait'] = 'paused';
    }
    if ($until > $now) {
        $state['retry_at'] = $until;
        if (!agumail_bulk_save($state)) {
            return agumail_bulk_abandon($state);
        }
        agumail_bulk_schedule($state['id'], $until - $now);
        return 'wait';
    }

    $state['step'] = 1;
    $state['step_mark'] = agumail_bulk_mark($state);
    if (!agumail_bulk_save($state)) {
        return agumail_bulk_abandon($state);
    }
    $deadline = agumail_clock() + AGUMAIL_BULK_BUDGET;
    // Marking orders as synced is our own write, not a change to queue.
    agumail_enqueue_suppressed(true);
    try {
        while (true) {
            if ($state['phase'] === 'done' || !in_array($state['phase'], agumail_bulk_phases(), true)) {
                return agumail_bulk_finish($state, 'done');
            }
            $outcome = agumail_bulk_page($state, $deadline);
            if ($outcome === 'cancelled') {
                return agumail_bulk_abandon($state);
            }
            if ($outcome === 'failed') {
                return agumail_bulk_finish($state, 'failed', $state['error']);
            }
            if ($outcome === 'wait' || $outcome === 'budget') {
                $state['step'] = 0;
                if (!agumail_bulk_save($state)) {
                    return agumail_bulk_abandon($state);
                }
                agumail_bulk_schedule($state['id'], $outcome === 'wait' ? max(1, (int) $state['retry_at'] - agumail_time()) : 0);
                return $outcome === 'wait' ? 'wait' : 'more';
            }
            if ($outcome === 'phase_done') {
                agumail_bulk_next_phase($state);
            }
            // The users, products and orders of this page need not stay in memory for the next.
            if (function_exists('wp_cache_flush_runtime') && (!function_exists('wp_cache_supports') || wp_cache_supports('flush_runtime'))) {
                wp_cache_flush_runtime();
            }
            if ($state['phase'] === 'done') {
                return agumail_bulk_finish($state, 'done');
            }
            $out_of_time = agumail_drain_should_stop($deadline);
            if ($out_of_time) {
                $state['step'] = 0;
            }
            if (!agumail_bulk_save($state)) {
                return agumail_bulk_abandon($state);
            }
            if ($out_of_time) {
                agumail_bulk_schedule($state['id'], 0);
                return 'more';
            }
        }
    } finally {
        agumail_enqueue_suppressed(false);
    }
}

/** The run was cancelled (or replaced) while this step worked: it leaves, taking its steps with it, and the page says cancelled. */
function agumail_bulk_abandon(array $state) {
    as_unschedule_all_actions('agumail_bulk_step', array((string) $state['id']), AGUMAIL_GROUP);
    $stored = agumail_bulk_state();
    wp_cache_delete('agumail_bulk_state', 'options');
    $raw = get_option('agumail_bulk_state', array());
    if ($stored['id'] === $state['id'] && $stored['status'] === 'cancelled' && is_array($raw) && isset($raw['status']) && $raw['status'] === 'running') {
        $raw['status'] = 'cancelled';
        $raw['finished_at'] = agumail_time();
        $raw['step'] = 0;
        update_option('agumail_bulk_state', $raw, false);
    }
    return 'cancelled';
}

function agumail_bulk_next_phase(array &$state) {
    $phases = agumail_bulk_phases();
    $at = array_search($state['phase'], $phases, true);
    $state['phase'] = $at === false || $at + 1 >= count($phases) ? 'done' : $phases[$at + 1];
    $state['cursor'] = 0;
    $state['page'] = array('done' => array(), 'refused' => array());
}

function agumail_bulk_finish(array $state, $status, $message = '') {
    as_unschedule_all_actions('agumail_bulk_step', array((string) $state['id']), AGUMAIL_GROUP);
    $state['status'] = $status;
    $state['finished_at'] = agumail_time();
    $state['retry_at'] = 0;
    $state['wait'] = '';
    $state['step'] = 0;
    if ($status === 'done') {
        $state['phase'] = 'done';
        $state['error'] = '';
    } elseif ($message !== '') {
        $state['error'] = $message;
    }
    if (!agumail_bulk_save($state)) {
        return agumail_bulk_abandon($state);
    }
    $c = $state['contacts'];
    $o = $state['orders'];
    agumail_log($status === 'done' ? 'info' : 'warning', sprintf(
        'Store data sync %s. Contacts: %d added, %d updated, %d skipped, %d over the plan limit. Products: %d. Orders: %d added, %d updated, %d unchanged. Refused: %d.%s',
        $status === 'done' ? 'finished' : 'stopped', $c['created'], $c['updated'], $c['suppressedSkipped'], $c['overCapacity'],
        $state['products']['upserted'], $o['created'], $o['updated'], $o['duplicates'], array_sum($state['refused']),
        $status === 'done' ? '' : ' ' . $state['error']
    ));
    return $status;
}

/**
 * Sends the next page of the current phase. Returns more, phase_done, budget
 * (out of time between requests; the page carries on in the next step), wait
 * (retry_at says until when), failed or cancelled.
 */
function agumail_bulk_page(array &$state, $deadline) {
    $phase = $state['phase'];
    $limit = agumail_bulk_page_size($phase, $state);
    $ids = agumail_bulk_ids($phase, (int) $state['cursor'], $limit, $state);
    if ($ids === null) {
        return agumail_bulk_hold($state, agumail_bulk_database_error());
    }
    if (!$ids) {
        return 'phase_done';
    }
    if ($phase === 'products') {
        $result = agumail_bulk_send_products($ids, $state, $deadline);
    } elseif ($phase === 'checkout_subscribers') {
        $result = agumail_bulk_send_checkout_subscribers($ids, $state, $deadline);
    } elseif ($phase === 'orders') {
        $result = agumail_bulk_send_orders($ids, $state, $deadline);
    } else {
        $result = agumail_bulk_send_users($ids, $state, $deadline);
    }
    if ($result['status'] === 'budget') {
        return 'budget';
    }
    if ($result['status'] !== 'ok') {
        return agumail_bulk_hold($state, $result);
    }
    foreach (isset($result['skipped']) ? $result['skipped'] : array() as $kind => $count) {
        $state['skipped'][$kind] += (int) $count;
    }
    $state['attempts'] = 0;
    $state['plan_refusals'] = 0;
    $state['crashes'] = 0;
    $state['error'] = '';
    $state['wait'] = '';
    $state['retry_at'] = 0;
    $state['done'][$phase] += count($ids);
    $state['cursor'] = (int) end($ids);
    // Records of ids past the cursor were taken by a larger page, before a timeout made pages smaller.
    $state['page'] = agumail_bulk_prune_page($state['page'], $state['cursor']);
    return count($ids) < $limit ? 'phase_done' : 'more';
}

function agumail_bulk_prune_page(array $page, $cursor) {
    $keep = function ($key) use ($cursor) {
        return (int) substr((string) $key, 2) > (int) $cursor;
    };
    return array(
        'done'    => array_values(array_filter($page['done'], $keep)),
        'refused' => array_values(array_filter($page['refused'], $keep)),
    );
}

function agumail_bulk_page_size($phase, array $state) {
    $sizes = agumail_bulk_page_sizes();
    $size = isset($sizes[$phase]) ? $sizes[$phase] : 100;
    return max(10, (int) floor($size / max(1, (int) $state['shrink'])));
}

function agumail_bulk_database_error() {
    /* translators: the database's own error message */
    return array('status' => 'retry', 'message' => sprintf(__('the store database did not answer: %s', 'agumail'), agumail_bulk_last_read_error()));
}

/** AguMail did not take the page: when to try again, or that the sync stops. */
function agumail_bulk_hold(array &$state, array $result) {
    $now = agumail_time();
    switch ($result['status']) {
        case 'cancelled':
            return 'cancelled';
        case 'rate':
            $state['retry_at'] = $now + (int) $result['wait'];
            $state['wait'] = 'rate';
            agumail_set_backoff_until(max(agumail_backoff_until(), $state['retry_at']));
            return 'wait';
        case 'auth':
            if (!empty($result['store_sync'])) {
                // The plan has no store sync: store data is held, and the sync stops until it is back.
                agumail_store_sync_hold($result['message']);
                $state['error'] = $result['message'];
                return 'failed';
            }
            agumail_record_connection_error($result['code'], $result['message']);
            $state['error'] = $result['message'];
            if ((int) $result['code'] === 402 && ++$state['plan_refusals'] >= AGUMAIL_BULK_MAX_PLAN_REFUSALS) {
                return 'failed';
            }
            $state['retry_at'] = $now + AGUMAIL_AUTH_PAUSE;
            $state['wait'] = 'auth';
            return 'wait';
        case 'refused':
            $state['error'] = $result['message'];
            if ($result['code'] === 'agumail_not_connected') {
                return 'failed';
            }
            $state['retry_at'] = $now + AGUMAIL_BULK_PAUSED_RETRY;
            $state['wait'] = 'retry';
            return 'wait';
    }
    $state['attempts']++;
    $state['error'] = $result['message'];
    if ($state['attempts'] >= AGUMAIL_BULK_MAX_ATTEMPTS) {
        return 'failed';
    }
    if (!empty($result['timed_out'])) {
        // AguMail took too long to answer a page: smaller pages from now on.
        $state['shrink'] = min(AGUMAIL_BULK_MAX_SHRINK, max(1, (int) $state['shrink']) * 2);
    }
    $state['retry_at'] = $now + agumail_retry_delay($state['attempts']);
    $state['wait'] = 'retry';
    return 'wait';
}

// ─────────────────────────────────────────────────────────────────────────────
// What the live path holds, and what an account said.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Whether AguMail holds the order: its _agumail_synced stamp names the
 * connected AguMail account (the live path's stamp), or is the older 'yes'.
 * An order stamped for another account is sent again.
 */
function agumail_bulk_order_synced($order) {
    $stamp = (string) $order->get_meta('_agumail_synced');
    return $stamp === 'yes' || ($stamp !== '' && $stamp === (string) agumail_connection()['userId']);
}

/** The stamp an order gets once AguMail holds it: the connected account's id, or 'yes' before one is known. */
function agumail_bulk_sync_stamp() {
    $user_id = (string) agumail_connection()['userId'];
    return $user_id !== '' ? $user_id : 'yes';
}

/** A row waiting in the live queue, or refused there: the live path holds it. */
function agumail_bulk_held_live($type, $key) {
    $row = agumail_store()->find($type, (string) $key);
    return $row && in_array($row['status'], array('pending', 'failed'), true);
}

function agumail_bulk_legacy_optin($user_id) {
    return in_array((string) get_user_meta($user_id, 'marketing_opt_in', true), array('yes', '1'), true);
}

/**
 * Whether a tick on a checkout still speaks for this buyer: a guest, an
 * account with no answer of its own, or one AguMail holds as not subscribed
 * (no). An account that subscribed (phase one sent it) or unsubscribed since
 * is sent by what it said.
 */
function agumail_bulk_may_opt_in_from_orders($account) {
    if (!$account) {
        return true;
    }
    $saved = (string) get_user_meta($account->ID, '_agumail_optin', true);
    return $saved === 'no' || ($saved === '' && !agumail_bulk_legacy_optin($account->ID));
}

function agumail_bulk_optin_moment($user_id) {
    return agumail_customers_optin_moment($user_id);
}

/** A stored UTC date as a timestamp, or null for none (WordPress keeps 0000-00-00 for unknown). */
function agumail_bulk_date($value) {
    $value = trim((string) $value);
    if ($value === '' || strpos($value, '0000-00-00') === 0) {
        return null;
    }
    $time = strtotime($value . ' UTC');
    return $time && $time > 0 ? $time : null;
}

/** When an order was paid (it was placed then, if WooCommerce never set a paid date), or null when it is gone. */
function agumail_bulk_order_paid_at($order_id) {
    $order = wc_get_order((int) $order_id);
    if (!is_a($order, 'WC_Order')) {
        return null;
    }
    $date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
    return $date ? $date->getTimestamp() : null;
}

/** The account behind an order's email: the order's customer when the address is theirs, else any account with it. */
function agumail_bulk_account_for($order, $email) {
    $user_id = (int) $order->get_customer_id();
    $user = $user_id > 0 ? get_userdata($user_id) : false;
    if ($user && strtolower(trim((string) $user->user_email)) === $email) {
        return $user;
    }
    $user = get_user_by('email', $email);
    return $user ? $user : null;
}

/** Whether phase one or two covers this account (sent it, or left it to the live queue): the same test as their SQL. */
function agumail_bulk_account_in_sync($user) {
    if (!$user || !$user->roles || trim((string) $user->user_email) === '') {
        return false;
    }
    $saved = (string) get_user_meta($user->ID, '_agumail_optin', true);
    if ($saved === 'yes' || ($saved === '' && agumail_bulk_legacy_optin($user->ID))) {
        return true;
    }
    return (bool) array_intersect((array) $user->roles, agumail_customer_roles())
        || (string) get_user_meta($user->ID, 'paying_customer', true) === '1';
}

// ─────────────────────────────────────────────────────────────────────────────
// Sending a page.
// ─────────────────────────────────────────────────────────────────────────────

/** Accounts of phase one or two, as contacts. An account waiting in the live queue is left to it. */
function agumail_bulk_send_users(array $ids, array &$state, $deadline) {
    $users = array();
    $lookup = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        $user = get_userdata($id);
        if (!$user || in_array('u:' . $id, $state['page']['done'], true) || agumail_bulk_held_live('user', $id)) {
            continue;
        }
        $users[$id] = $user;
        $saved = (string) get_user_meta($id, '_agumail_optin', true);
        if ($saved !== 'unsubscribed' && !($saved === '' && agumail_bulk_legacy_optin($id))) {
            $lookup[] = $id;
        }
    }
    $optin_orders = agumail_bulk_optin_orders($lookup, $state);
    if ($optin_orders === null) {
        return agumail_bulk_database_error();
    }
    $checkout_subscribers = $state['phase'] === 'customers' ? agumail_bulk_sent_as_checkout_subscribers($users, $state) : array();
    if ($checkout_subscribers === null) {
        return agumail_bulk_database_error();
    }
    $unsubscribed = array();
    foreach ($users as $id => $user) {
        $email = agumail_normalize_email($user->user_email);
        if ($email !== '' && !isset($checkout_subscribers[$id]) && (string) get_user_meta($id, '_agumail_optin', true) === 'unsubscribed') {
            $unsubscribed[] = $email;
        }
    }
    $known = agumail_bulk_unknown_addresses($unsubscribed, $state['id'], $deadline);
    if ($known['status'] !== 'ok') {
        return $known;
    }
    $records = array();
    $skipped = 0;
    foreach ($users as $id => $user) {
        if (isset($checkout_subscribers[$id])) {
            continue;
        }
        $unknown = isset($known['unknown'][agumail_normalize_email($user->user_email)]);
        $contact = agumail_bulk_user_contact($user, isset($optin_orders[$id]) ? $optin_orders[$id] : 0, $unknown);
        if (!$contact) {
            $skipped++;
            continue;
        }
        $records['u:' . $id] = $contact;
    }
    $result = agumail_bulk_deliver('/api/v1/contacts', 'contacts', $records, 'contacts', $state, $deadline);
    $result['skipped'] = array('contacts' => $skipped);
    return $result;
}

/**
 * Which of these addresses AguMail does not hold for this account: status
 * null in /api/v1/contacts/status. An account unsubscribed here goes to
 * AguMail as unsubscribed only then; an address AguMail holds may have come
 * back since (a confirmed rejoin), which a store's old answer must not undo.
 * A read: it writes nothing, so it carries no backfill flag. Returns
 * ['status' => ok, 'unknown' => [email => true]] or a status to hold on.
 */
function agumail_bulk_unknown_addresses(array $emails, $run_id, $deadline) {
    if (!$emails) {
        return array('status' => 'ok', 'unknown' => array());
    }
    if (agumail_bulk_cancel_requested($run_id)) {
        return array('status' => 'cancelled');
    }
    if (agumail_drain_should_stop($deadline)) {
        return array('status' => 'budget');
    }
    $response = agumail_api_request('POST', '/api/v1/contacts/status', array('emails' => array_values($emails)), array('timeout' => AGUMAIL_DRAIN_TIMEOUT));
    if (agumail_bulk_cancel_requested($run_id)) {
        return array('status' => 'cancelled');
    }
    $answer = agumail_drain_classify($response);
    if ($answer === 'ok') {
        $unknown = array();
        $contacts = isset($response['body']['contacts']) && is_array($response['body']['contacts']) ? $response['body']['contacts'] : array();
        foreach ($contacts as $contact) {
            if (is_array($contact) && isset($contact['email']) && array_key_exists('status', $contact) && $contact['status'] === null) {
                $unknown[strtolower(trim((string) $contact['email']))] = true;
            }
        }
        return array('status' => 'ok', 'unknown' => $unknown);
    }
    if ($answer === 'auth') {
        return array('status' => 'auth', 'code' => (int) $response['code'], 'message' => agumail_drain_error_message($response));
    }
    if ($answer === 'rate') {
        return array('status' => 'rate', 'wait' => agumail_drain_retry_after($response));
    }
    if ($answer === 'refused') {
        return array('status' => 'refused', 'code' => $response->get_error_code(), 'message' => $response->get_error_message());
    }
    return array(
        'status'    => 'retry',
        'message'   => is_wp_error($response) ? $response->get_error_message() : agumail_drain_error_message($response),
        'timed_out' => agumail_drain_timed_out($response),
    );
}

/** Accounts of a phase-two page that phase 1b already covered: their own address ticked the box on a checkout. */
function agumail_bulk_sent_as_checkout_subscribers(array $users, array $state) {
    $emails = array();
    foreach ($users as $id => $user) {
        $email = agumail_normalize_email($user->user_email);
        if ($email !== '' && agumail_bulk_may_opt_in_from_orders($user)) {
            $emails[$email] = $id;
        }
    }
    $rows = agumail_bulk_buyer_orders(array_keys($emails), $state);
    if ($rows === null) {
        return null;
    }
    $sent = array();
    foreach ($rows as $email => $row) {
        if ($row['optin_id'] && isset($emails[$email])) {
            $sent[$emails[$email]] = true;
        }
    }
    return $sent;
}

/**
 * An account as a contact. Opted in, with consentedAt: an account whose
 * answer is yes, an account with only the older marketing_opt_in key, and an
 * account with no answer (or held as not subscribed) that ticked the box on a
 * checkout. The date is the best one known: the recorded opt-in moment
 * (_agumail_optin_at), else when the order it ticked the box on was paid,
 * else registration. An account that unsubscribed goes as unsubscribed when
 * AguMail does not hold its address ($unknown). Everyone else carries no
 * consent at all, and never a consentedAt, which AguMail reads as an opt-in.
 */
function agumail_bulk_user_contact($user, $optin_order_id, $unknown = false) {
    $saved = (string) get_user_meta($user->ID, '_agumail_optin', true);
    $registered = agumail_bulk_date($user->user_registered);
    $moment = agumail_bulk_optin_moment($user->ID);
    $consented_at = null;
    if ($saved === 'yes' || ($saved === '' && agumail_bulk_legacy_optin($user->ID))) {
        $consented_at = $moment !== null ? $moment : ($optin_order_id && $saved === 'yes' ? agumail_bulk_order_paid_at($optin_order_id) : null);
        if ($consented_at === null) {
            $consented_at = $registered !== null ? $registered : agumail_time();
        }
    } elseif (($saved === '' || $saved === 'no') && $optin_order_id) {
        $consented_at = $moment !== null ? $moment : agumail_bulk_order_paid_at($optin_order_id);
    }
    return agumail_contact_payload($user->user_email, array(
        'first_name'     => agumail_customers_first_name($user),
        'last_name'      => agumail_customers_last_name($user),
        'opted_in'       => $consented_at !== null ? true : null,
        'source'         => 'bulk_sync',
        'unsubscribed'   => $saved === 'unsubscribed' && $unknown,
        'customer_since' => $registered,
        'consented_at'   => $consented_at,
    ));
}

function agumail_bulk_send_products(array $ids, array &$state, $deadline) {
    $records = array();
    foreach ($ids as $id) {
        $key = 'p:' . (int) $id;
        if (in_array($key, $state['page']['done'], true)) {
            continue;
        }
        $product = wc_get_product((int) $id);
        if (!$product || $product->is_type('variation') || $product->get_status() !== 'publish') {
            continue;
        }
        try {
            $records[$key] = agumail_product_record($product);
        } catch (Throwable $e) {
            agumail_log('warning', sprintf('Product %d could not be read for the store data sync: %s', $id, $e->getMessage()));
        }
    }
    return agumail_bulk_deliver('/api/v1/products', 'products', $records, 'products', $state, $deadline);
}

/**
 * Phase 1b, a page of paid orders a checkout created with the box ticked: each
 * buyer goes once, as opted in, on the page of their first such order, unless
 * an account of theirs subscribed or unsubscribed since (it is sent by what it
 * said). An order the live path holds or delivered is news, or already known;
 * its buyer is left to it, as is an account waiting in the live queue.
 */
function agumail_bulk_send_checkout_subscribers(array $ids, array &$state, $deadline) {
    $buyers = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        $order = wc_get_order($id);
        if (!agumail_order_is_shop_order($order) || agumail_bulk_order_synced($order) || agumail_bulk_held_live('order', $id)) {
            continue;
        }
        $email = agumail_normalize_email($order->get_billing_email());
        if ($email === '' || isset($buyers[$email])) {
            continue;
        }
        $account = agumail_bulk_account_for($order, $email);
        if (agumail_bulk_may_opt_in_from_orders($account) && !($account && agumail_bulk_held_live('user', $account->ID))) {
            $buyers[$email] = agumail_bulk_buyer($order, $account) + array('order_id' => $id);
        }
    }
    $known = agumail_bulk_buyer_orders(array_keys($buyers), $state);
    if ($known === null) {
        return agumail_bulk_database_error();
    }
    $records = array();
    foreach ($buyers as $email => $buyer) {
        $key = 'b:' . $buyer['order_id'];
        $row = isset($known[$email]) ? $known[$email] : null;
        if (!$row || (int) $row['optin_id'] !== $buyer['order_id'] || in_array($key, $state['page']['done'], true)) {
            continue;
        }
        $contact = agumail_bulk_buyer_contact($email, $buyer, $row, agumail_bulk_order_paid_at($buyer['order_id']));
        if ($contact) {
            $records[$key] = $contact;
        }
    }
    return agumail_bulk_deliver('/api/v1/contacts', 'contacts', $records, 'contacts', $state, $deadline);
}

/** What an order says about its buyer, and the account behind the address. */
function agumail_bulk_buyer($order, $account) {
    $buyer = array('first_name' => '', 'last_name' => '', 'created' => $order->get_date_created(), 'account' => $account, 'first_order' => (int) $order->get_id());
    if ($account) {
        $buyer['first_name'] = trim((string) agumail_customers_first_name($account));
        $buyer['last_name'] = trim((string) agumail_customers_last_name($account));
    }
    return agumail_bulk_buyer_names($buyer, $order);
}

/** Fills names the buyer still lacks from an order's billing details. */
function agumail_bulk_buyer_names(array $buyer, $order) {
    foreach (array('first_name', 'last_name') as $field) {
        $value = trim((string) call_user_func(array($order, 'get_billing_' . $field)));
        if ($buyer[$field] === '' && $value !== '') {
            $buyer[$field] = $value;
        }
    }
    return $buyer;
}

/**
 * A buyer as a contact: a customer since whichever came first, the account or
 * the first paid order; opted in, dated $consented_at, only when given one.
 */
function agumail_bulk_buyer_contact($email, array $buyer, $row, $consented_at) {
    $dates = array_filter(array(
        $buyer['account'] ? agumail_bulk_date($buyer['account']->user_registered) : null,
        $row ? agumail_bulk_date($row['first_at']) : null,
    ));
    if (!$dates && $buyer['created']) {
        $dates[] = $buyer['created']->getTimestamp();
    }
    return agumail_contact_payload($email, array(
        'first_name'     => $buyer['first_name'],
        'last_name'      => $buyer['last_name'],
        'opted_in'       => $consented_at !== null ? true : null,
        'source'         => 'bulk_sync',
        'customer_since' => $dates ? min($dates) : null,
        'consented_at'   => $consented_at,
    ));
}

/**
 * A page of paid orders: first the buyers AguMail does not have yet (guests,
 * and customers under another email), then the orders. An order the live path
 * holds (waiting or refused in its queue) or already delivered is left to it:
 * it is news, or known, and sending it as history would swallow its
 * post-purchase flow or stamp a recent buyer as the past.
 */
function agumail_bulk_send_orders(array $ids, array &$state, $deadline) {
    $orders = array();
    $records = array();
    $skipped = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        if (in_array('o:' . $id, $state['page']['done'], true)) {
            continue;
        }
        $order = wc_get_order($id);
        if (!agumail_order_is_shop_order($order) || agumail_bulk_order_synced($order) || agumail_bulk_held_live('order', $id)) {
            continue;
        }
        try {
            $record = agumail_order_record($order);
        } catch (Throwable $e) {
            agumail_log('warning', sprintf('Order %d could not be read for the store data sync: %s', $id, $e->getMessage()));
            $record = null;
        }
        if (!$record) {
            $skipped++;
            continue;
        }
        $orders[$id] = $order;
        $records['o:' . $id] = $record;
    }

    $buyers = agumail_bulk_order_contacts($orders, $state);
    if ($buyers === null) {
        return agumail_bulk_database_error();
    }
    $result = agumail_bulk_deliver('/api/v1/contacts', 'contacts', $buyers['contacts'], 'contacts', $state, $deadline);
    if ($result['status'] !== 'ok') {
        return $result;
    }
    // An order whose buyer AguMail refused would land without a contact: it is refused too.
    foreach ($orders as $id => $order) {
        $email = agumail_normalize_email($order->get_billing_email());
        $key = 'o:' . $id;
        if (isset($buyers['keys'][$email], $records[$key]) && in_array($buyers['keys'][$email], $state['page']['refused'], true)) {
            unset($records[$key]);
            $state['refused']['orders']++;
            $state['page']['done'][] = $key;
        }
    }
    $result = agumail_bulk_deliver('/api/v1/orders', 'orders', $records, 'orders', $state, $deadline, function (array $keys) use ($orders) {
        foreach ($keys as $key) {
            $id = (int) substr($key, 2);
            if (isset($orders[$id])) {
                agumail_bulk_mark_synced($orders[$id]);
            }
        }
    });
    $result['skipped'] = array('orders' => $skipped);
    return $result;
}

/**
 * AguMail holds the order, as the live path marks one: a later refund or edit
 * is then an update, and does not send the buyer's old answer again. Only the
 * flag is saved; with HPOS, WooCommerce would otherwise save the whole order.
 */
function agumail_bulk_mark_synced($order) {
    if (agumail_bulk_order_synced($order)) {
        return;
    }
    $order->update_meta_data('_agumail_synced', agumail_bulk_sync_stamp());
    add_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false');
    try {
        $order->save_meta_data();
    } finally {
        remove_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false');
    }
}

/**
 * The buyers of a page of orders that no earlier phase covered, each once:
 * with the page that holds their first order in the sync's range. None of
 * them opted in: a buyer whose checkout tick still speaks for them went in
 * phase 1b, and one whose account answered since goes by that answer.
 * Returns ['contacts' => key => record, 'keys' => email => key], or null on a
 * database error.
 */
function agumail_bulk_order_contacts(array $orders, array $state) {
    $covered = array();
    $buyers = array();
    foreach ($orders as $order) {
        $email = agumail_normalize_email($order->get_billing_email());
        if ($email === '' || isset($covered[$email])) {
            continue;
        }
        if (isset($buyers[$email])) {
            $buyers[$email] = agumail_bulk_buyer_names($buyers[$email], $order);
            continue;
        }
        $account = agumail_bulk_account_for($order, $email);
        if ($account && (agumail_bulk_account_in_sync($account) || agumail_bulk_held_live('user', $account->ID))) {
            $covered[$email] = true;
            continue;
        }
        $buyers[$email] = agumail_bulk_buyer($order, $account);
    }
    $out = array('contacts' => array(), 'keys' => array());
    if (!$buyers) {
        return $out;
    }
    $known = agumail_bulk_buyer_orders(array_keys($buyers), $state);
    if ($known === null) {
        return null;
    }
    foreach ($buyers as $email => $buyer) {
        $row = isset($known[$email]) ? $known[$email] : null;
        // Phase 1b sent a buyer whose ticked order it could send, and the live path one whose ticked order it delivered.
        // One whose ticked order still waits in the live queue goes now, without consent, so their older orders have a contact.
        if ($row && $row['optin_id'] && agumail_bulk_may_opt_in_from_orders($buyer['account']) && !agumail_bulk_held_live('order', $row['optin_id'])) {
            continue;
        }
        if ($row && $row['first_id'] !== null && (int) $row['first_id'] <= (int) $state['cursor']) {
            continue;
        }
        $key = 'g:' . ($row && $row['first_id'] !== null ? (int) $row['first_id'] : $buyer['first_order']);
        $out['keys'][$email] = $key;
        if (in_array($key, $state['page']['done'], true)) {
            continue;
        }
        $contact = agumail_bulk_buyer_contact($email, $buyer, $row, null);
        if ($contact) {
            $out['contacts'][$key] = $contact;
        }
    }
    return $out;
}

/**
 * Takes the records a page sends to one endpoint (key => record), in requests
 * of the endpoint's size, keeping the page's progress in the state after each
 * one AguMail takes. Returns ['status' => ok|budget|rate|auth|retry|refused|cancelled, ...].
 */
function agumail_bulk_deliver($endpoint, $collection, array $records, $kind, array &$state, $deadline, $after_sent = null) {
    if (!$records) {
        return array('status' => 'ok');
    }
    $sizes = array('contacts' => 250, 'products' => 250, 'orders' => 100);
    $size = max(1, (int) floor($sizes[$kind] / max(1, (int) $state['shrink'])));
    $run_id = $state['id'];
    return agumail_bulk_post($endpoint, $collection, $records, $size, $run_id, $deadline, array(
        'sent'    => function (array $keys, array $body) use (&$state, $kind, $after_sent) {
            $fields = array(
                'contacts' => array('created', 'updated', 'suppressedSkipped', 'overCapacity'),
                'products' => array('upserted'),
                'orders'   => array('created', 'updated', 'duplicates'),
            );
            foreach ($fields[$kind] as $field) {
                if (isset($body[$field]) && is_numeric($body[$field])) {
                    $state[$kind][$field] += (int) $body[$field];
                }
            }
            $state['page']['done'] = array_values(array_merge($state['page']['done'], $keys));
            if (is_callable($after_sent)) {
                call_user_func($after_sent, $keys);
            }
            return agumail_bulk_save($state);
        },
        'refused' => function ($key, $message) use (&$state, $kind, $endpoint) {
            $state['refused'][$kind]++;
            $state['page']['done'][] = $key;
            $state['page']['refused'][] = $key;
            agumail_log('warning', sprintf('AguMail refused %s from the store data sync (%s): %s', $key, $endpoint, $message));
            return agumail_bulk_save($state);
        },
    ));
}

/**
 * Posts the records with backfill: true, reading each answer by the drain's
 * rules. Before each request it checks the cancel and the step's time, and
 * after each it checks the cancel again. $on['sent']($keys, $body) and
 * $on['refused']($key, $message) return false once the sync was cancelled.
 */
function agumail_bulk_post($endpoint, $collection, array $records, $size, $run_id, $deadline, array $on) {
    $entries = array();
    foreach ($records as $key => $record) {
        $entries[] = array('key' => (string) $key, 'record' => $record, 'bytes' => strlen((string) wp_json_encode($record)));
    }
    $queue = agumail_drain_chunks($entries, $size);
    while ($queue) {
        $chunk = array_values(array_shift($queue));
        if (!$chunk) {
            continue;
        }
        if (agumail_bulk_cancel_requested($run_id)) {
            return array('status' => 'cancelled');
        }
        if (agumail_drain_should_stop($deadline)) {
            return array('status' => 'budget');
        }
        $body = array($collection => array_map(function ($entry) { return $entry['record']; }, $chunk), 'backfill' => true);
        $response = agumail_api_request('POST', $endpoint, $body, array('timeout' => AGUMAIL_DRAIN_TIMEOUT));
        if (agumail_bulk_cancel_requested($run_id)) {
            return array('status' => 'cancelled');
        }
        $answer = agumail_drain_classify($response);

        if ($answer === 'ok') {
            agumail_clear_connection_error();
            do_action('agumail_bulk_sent', $endpoint, count($chunk), $response);
            if (!call_user_func($on['sent'], array_column($chunk, 'key'), is_array($response['body']) ? $response['body'] : array())) {
                return array('status' => 'cancelled');
            }
            continue;
        }
        if ($answer === 'too_large' || ($answer === 'invalid' && count($chunk) > 1 && agumail_drain_error_index($response, $collection) === null
                && agumail_drain_error_names_collection($response, $collection))) {
            if (count($chunk) > 1) {
                $half = (int) ceil(count($chunk) / 2);
                array_unshift($queue, array_slice($chunk, 0, $half), array_slice($chunk, $half));
                continue;
            }
            if (!call_user_func($on['refused'], $chunk[0]['key'], 'The record is larger than AguMail accepts.')) {
                return array('status' => 'cancelled');
            }
            continue;
        }
        if ($answer === 'invalid') {
            $message = agumail_drain_error_message($response);
            $index = agumail_drain_error_index($response, $collection);
            if ($index === null || !isset($chunk[$index])) {
                return array('status' => 'retry', 'message' => $message);
            }
            if (!call_user_func($on['refused'], $chunk[$index]['key'], $message)) {
                return array('status' => 'cancelled');
            }
            unset($chunk[$index]);
            array_unshift($queue, array_values($chunk));
            continue;
        }
        if ($answer === 'auth') {
            return array('status' => 'auth', 'code' => (int) $response['code'], 'message' => agumail_drain_error_message($response),
                'store_sync' => agumail_is_store_sync_refusal($response));
        }
        if ($answer === 'rate') {
            return array('status' => 'rate', 'wait' => agumail_drain_retry_after($response));
        }
        if ($answer === 'refused') {
            return array('status' => 'refused', 'code' => $response->get_error_code(), 'message' => $response->get_error_message());
        }
        return array(
            'status'    => 'retry',
            'message'   => is_wp_error($response) ? $response->get_error_message() : agumail_drain_error_message($response),
            'timed_out' => agumail_drain_timed_out($response),
        );
    }
    return array('status' => 'ok');
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the store: keyset pages by id, straight from the tables, so a page
// is the same page however long the sync takes, and a resumed sync starts
// exactly after the last id sent. Orders are read from WooCommerce's own order
// tables when HPOS is on, and from posts otherwise. A query the database
// fails is a failure to retry, never an empty page (null, not array()).
//
// Each query is written out whole for the table it reads: only table names
// are interpolated, every value is a placeholder, and lists of values are
// placeholders too. Each is a read of the store's own tables by a background
// job, one page at a time, so there is nothing worth caching.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Whether the last read failed: the database answered with an error (an empty
 * answer is not one). Its words are kept for the page: the next query clears
 * $wpdb->last_error, and logging may run one (WooCommerce reads its settings).
 */
function agumail_bulk_read_failed() {
    global $wpdb;
    $error = (string) $wpdb->last_error;
    if ($error === '') {
        return false;
    }
    $GLOBALS['agumail_bulk_read_error'] = $error;
    agumail_log('warning', 'The store data sync could not read the store database: ' . $error);
    return true;
}

/** What the database said when the last read failed. */
function agumail_bulk_last_read_error() {
    return isset($GLOBALS['agumail_bulk_read_error']) ? (string) $GLOBALS['agumail_bulk_read_error'] : '';
}

/** Rows from a lookup, or null on a database error. */
function agumail_bulk_checked($result) {
    return agumail_bulk_read_failed() ? null : (array) $result;
}

/** Ids from a page query, or null on a database error. */
function agumail_bulk_checked_ids($result) {
    return agumail_bulk_read_failed() ? null : array_map('intval', (array) $result);
}

/** A count or a highest id (0 for none), or null on a database error. */
function agumail_bulk_checked_count($result) {
    return agumail_bulk_read_failed() ? null : (int) $result;
}

function agumail_bulk_hpos() {
    $util = '\Automattic\WooCommerce\Utilities\OrderUtil';
    return class_exists($util) && method_exists($util, 'custom_orders_table_usage_is_enabled')
        && call_user_func(array($util, 'custom_orders_table_usage_is_enabled'));
}

/** Statuses that are never a purchase, paid date or not: gone back, never finished, or not an order yet. */
function agumail_bulk_unpaid_statuses() {
    return array('wc-cancelled', 'wc-refunded', 'wc-failed', 'trash', 'wc-checkout-draft', 'checkout-draft', 'auto-draft', 'draft');
}

/** WooCommerce's paid statuses (processing, completed, and any a store adds through its filter), as stored: wc-processing. */
function agumail_bulk_paid_statuses() {
    $statuses = function_exists('wc_get_is_paid_statuses') ? (array) wc_get_is_paid_statuses() : array('processing', 'completed');
    $stored = array();
    foreach ($statuses as $status) {
        $stored[] = 'wc-' . preg_replace('/^wc-/', '', (string) $status);
    }
    return array_values(array_unique($stored));
}

function agumail_bulk_ids($phase, $after, $limit, array $state) {
    if ($phase === 'subscribers' || $phase === 'customers') {
        return agumail_bulk_user_ids($phase === 'subscribers', $state, $after, $limit);
    }
    if ($phase === 'products') {
        return agumail_bulk_product_ids($after, $limit);
    }
    if ($phase === 'checkout_subscribers') {
        return agumail_bulk_ticked_order_ids($state, $after, $limit);
    }
    if ($phase === 'orders') {
        return agumail_bulk_order_ids($state, $after, $limit);
    }
    return array();
}

/** How many records a phase holds, or null on a database error. */
function agumail_bulk_count($phase, array $state) {
    if ($phase === 'subscribers' || $phase === 'customers') {
        return agumail_bulk_user_ids($phase === 'subscribers', $state, 0, 0, true);
    }
    if ($phase === 'products') {
        return agumail_bulk_product_ids(0, 0, true);
    }
    if ($phase === 'checkout_subscribers') {
        return agumail_bulk_ticked_order_ids($state, 0, 0, true);
    }
    return agumail_bulk_order_ids($state, 0, 0, true);
}

/**
 * The order range as two query values, for "(%d = 0 OR <date> >= %s)": 0 for
 * all history, else 1 and where the range starts. MySQL 8 refuses to compare
 * a date with '' (Incorrect DATETIME value), even behind an OR, so all history
 * passes a real date that the 0 keeps it from reading.
 */
function agumail_bulk_range_args(array $state) {
    $since = (string) $state['since'];
    return $since !== '' ? array(1, $since) : array(0, '1970-01-01 00:00:00');
}

/**
 * Accounts of this site with an email, by id, up to the highest id there was
 * when the sync started: those who opted in (our answer is yes, or we hold none
 * and the older marketing_opt_in key says yes), or the other customers (a
 * customer role, or a paying customer). $count asks how many.
 */
function agumail_bulk_user_ids($subscribed, array $state, $after, $limit, $count = false) {
    global $wpdb;
    $after = (int) $after;
    $max = (int) $state['max_user_id'];
    $limit = (int) $limit;
    $caps = $wpdb->prefix . 'capabilities';
    $roles = agumail_customer_roles_regexp();
    $wpdb->last_error = '';
    if ($subscribed && $count) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's accounts for the sync's progress, read once.
        return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.ID > %d AND u.ID <= %d AND u.user_email <> ''"
            . " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} cm WHERE cm.user_id = u.ID AND cm.meta_key = %s)"
            . " AND (EXISTS (SELECT 1 FROM {$wpdb->usermeta} om WHERE om.user_id = u.ID AND om.meta_key = '_agumail_optin' AND om.meta_value = 'yes')"
            . " OR (EXISTS (SELECT 1 FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = 'marketing_opt_in' AND lm.meta_value IN ('yes', '1'))"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} nm WHERE nm.user_id = u.ID AND nm.meta_key = '_agumail_optin' AND nm.meta_value <> '')))",
            $after, $max, $caps
        )));
    }
    if ($subscribed) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's accounts, read once by a background job.
        return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
            "SELECT u.ID FROM {$wpdb->users} u WHERE u.ID > %d AND u.ID <= %d AND u.user_email <> ''"
            . " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} cm WHERE cm.user_id = u.ID AND cm.meta_key = %s)"
            . " AND (EXISTS (SELECT 1 FROM {$wpdb->usermeta} om WHERE om.user_id = u.ID AND om.meta_key = '_agumail_optin' AND om.meta_value = 'yes')"
            . " OR (EXISTS (SELECT 1 FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = 'marketing_opt_in' AND lm.meta_value IN ('yes', '1'))"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} nm WHERE nm.user_id = u.ID AND nm.meta_key = '_agumail_optin' AND nm.meta_value <> '')))"
            . ' ORDER BY u.ID ASC LIMIT %d',
            $after, $max, $caps, $limit
        )));
    }
    if ($count) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's accounts for the sync's progress, read once.
        return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.ID > %d AND u.ID <= %d AND u.user_email <> ''"
            . " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} cm WHERE cm.user_id = u.ID AND cm.meta_key = %s AND (cm.meta_value REGEXP %s"
            . " OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} pm WHERE pm.user_id = u.ID AND pm.meta_key = 'paying_customer' AND pm.meta_value = '1')))"
            . " AND NOT (EXISTS (SELECT 1 FROM {$wpdb->usermeta} om WHERE om.user_id = u.ID AND om.meta_key = '_agumail_optin' AND om.meta_value = 'yes')"
            . " OR (EXISTS (SELECT 1 FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = 'marketing_opt_in' AND lm.meta_value IN ('yes', '1'))"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} nm WHERE nm.user_id = u.ID AND nm.meta_key = '_agumail_optin' AND nm.meta_value <> '')))",
            $after, $max, $caps, $roles
        )));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's accounts, read once by a background job.
    return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
        "SELECT u.ID FROM {$wpdb->users} u WHERE u.ID > %d AND u.ID <= %d AND u.user_email <> ''"
        . " AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} cm WHERE cm.user_id = u.ID AND cm.meta_key = %s AND (cm.meta_value REGEXP %s"
        . " OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} pm WHERE pm.user_id = u.ID AND pm.meta_key = 'paying_customer' AND pm.meta_value = '1')))"
        . " AND NOT (EXISTS (SELECT 1 FROM {$wpdb->usermeta} om WHERE om.user_id = u.ID AND om.meta_key = '_agumail_optin' AND om.meta_value = 'yes')"
        . " OR (EXISTS (SELECT 1 FROM {$wpdb->usermeta} lm WHERE lm.user_id = u.ID AND lm.meta_key = 'marketing_opt_in' AND lm.meta_value IN ('yes', '1'))"
        . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} nm WHERE nm.user_id = u.ID AND nm.meta_key = '_agumail_optin' AND nm.meta_value <> '')))"
        . ' ORDER BY u.ID ASC LIMIT %d',
        $after, $max, $caps, $roles, $limit
    )));
}

function agumail_bulk_product_ids($after, $limit, $count = false) {
    global $wpdb;
    $wpdb->last_error = '';
    if ($count) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's products for the sync's progress, read once.
        return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID > %d",
            (int) $after
        )));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's products, read once by a background job.
    return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
        (int) $after, (int) $limit
    )));
}

/**
 * Purchases in the sync's range, after $after and up to the bound, by id. A
 * purchase is what the live path counts as one (agumail_order_counts_as_paid()):
 * a paid date, or a paid status (so cash on delivery counts once processing),
 * and never an unpaid status. HPOS keeps the date in the operational data
 * table; posts keep it in _date_paid, or _paid_date for orders from before
 * WooCommerce 3.0. Both store statuses as wc-processing. An empty range start
 * reads all history (agumail_bulk_range_args()).
 */
function agumail_bulk_order_ids(array $state, $after, $limit, $count = false) {
    global $wpdb;
    $unpaid = agumail_bulk_unpaid_statuses();
    $paid = agumail_bulk_paid_statuses();
    $args = array_merge($unpaid, $paid, array((int) $after, (int) $state['max_order_id']), agumail_bulk_range_args($state));
    $wpdb->last_error = '';
    if (agumail_bulk_hpos()) {
        if ($count) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's orders for the sync's progress, read once.
            return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
                . " WHERE o.type = 'shop_order' AND o.status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
                . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
                . " AND o.id > %d AND o.id <= %d AND (%d = 0 OR o.date_created_gmt >= %s)",
                $args
            )));
        }
        $args[] = (int) $limit;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's orders, read once by a background job.
        return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
            "SELECT o.id FROM {$wpdb->prefix}wc_orders o LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
            . " WHERE o.type = 'shop_order' AND o.status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . " AND o.id > %d AND o.id <= %d AND (%d = 0 OR o.date_created_gmt >= %s) ORDER BY o.id ASC LIMIT %d",
            $args
        )));
    }
    if ($count) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's orders for the sync's progress, read once.
        return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
            . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
            . " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
            . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . " AND p.ID > %d AND p.ID <= %d AND (%d = 0 OR p.post_date_gmt >= %s)",
            $args
        )));
    }
    $args[] = (int) $limit;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's orders, read once by a background job.
    return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p"
        . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
        . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
        . " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
        . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
        . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
        . " AND p.ID > %d AND p.ID <= %d AND (%d = 0 OR p.post_date_gmt >= %s) ORDER BY p.ID ASC LIMIT %d",
        $args
    )));
}

/**
 * Purchases a checkout created with the box ticked, after $after and up to
 * the bound, by id, whatever the range: a subscriber is one however long ago
 * they ticked it. Only a checkout's answer is trusted, as on the live path.
 */
function agumail_bulk_ticked_order_ids(array $state, $after, $limit, $count = false) {
    global $wpdb;
    $unpaid = agumail_bulk_unpaid_statuses();
    $paid = agumail_bulk_paid_statuses();
    $args = array_merge($unpaid, $paid, array((int) $after, (int) $state['max_order_id']));
    $wpdb->last_error = '';
    if (agumail_bulk_hpos()) {
        if ($count) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's orders for the sync's progress, read once.
            return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o"
                . " INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
                . " LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
                . " WHERE o.type = 'shop_order' AND d.created_via IN ('checkout', 'store-api')"
                . ' AND o.status NOT IN (' . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
                . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
                . ' AND o.id > %d AND o.id <= %d',
                $args
            )));
        }
        $args[] = (int) $limit;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's orders, read once by a background job.
        return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
            "SELECT o.id FROM {$wpdb->prefix}wc_orders o"
            . " INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
            . " LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
            . " WHERE o.type = 'shop_order' AND d.created_via IN ('checkout', 'store-api')"
            . ' AND o.status NOT IN (' . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND o.id > %d AND o.id <= %d ORDER BY o.id ASC LIMIT %d',
            $args
        )));
    }
    if ($count) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a count of the store's orders for the sync's progress, read once.
        return agumail_bulk_checked_count($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p"
            . " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
            . " INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_created_via' AND v.meta_value IN ('checkout', 'store-api')"
            . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
            . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
            . " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
            . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND p.ID > %d AND p.ID <= %d',
            $args
        )));
    }
    $args[] = (int) $limit;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a keyset page of the store's orders, read once by a background job.
    return agumail_bulk_checked_ids($wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p"
        . " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
        . " INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_created_via' AND v.meta_value IN ('checkout', 'store-api')"
        . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
        . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
        . " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
        . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
        . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
        . ' AND p.ID > %d AND p.ID <= %d ORDER BY p.ID ASC LIMIT %d',
        $args
    )));
}

/** The highest order id now: the sync's bound. Orders after it reach AguMail through the live queue. Null on a database error. */
function agumail_bulk_max_order_id() {
    global $wpdb;
    $wpdb->last_error = '';
    if (agumail_bulk_hpos()) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the sync's bound, read once when it starts.
        return agumail_bulk_checked_count($wpdb->get_var("SELECT MAX(o.id) FROM {$wpdb->prefix}wc_orders o WHERE o.type = 'shop_order'"));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the sync's bound, read once when it starts.
    return agumail_bulk_checked_count($wpdb->get_var("SELECT MAX(p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'shop_order'"));
}

/** The highest account id now: the bound of the account phases. Accounts made after it are the live path's. */
function agumail_bulk_max_user_id() {
    global $wpdb;
    $wpdb->last_error = '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the sync's bound, read once when it starts.
    return agumail_bulk_checked_count($wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->users}"));
}

/**
 * For each billing email, over its purchases up to the bound: the first one's
 * date (first_at), the first id inside the sync's range (first_id), and the
 * first with the marketing box ticked on a submitted checkout (optin_id). The
 * box's answer is trusted only on an order a checkout created, as the live
 * path trusts it: a renewal or an admin copy carries the meta it was copied
 * from. On posts storage the lookup starts from the addresses asked for
 * (postmeta), never from every order. Null on a database error.
 */
function agumail_bulk_buyer_orders(array $emails, array $state) {
    global $wpdb;
    if (!$emails) {
        return array();
    }
    $emails = array_values($emails);
    $unpaid = agumail_bulk_unpaid_statuses();
    $paid = agumail_bulk_paid_statuses();
    $args = array_merge(agumail_bulk_range_args($state), $emails, $unpaid, $paid, array((int) $state['max_order_id']));
    $wpdb->last_error = '';
    if (agumail_bulk_hpos()) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the first orders of one page's buyers, read once by a background job.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT LOWER(o.billing_email) AS email, MIN(o.date_created_gmt) AS first_at,"
            . " MIN(CASE WHEN %d = 0 OR o.date_created_gmt >= %s THEN o.id END) AS first_id,"
            . " MIN(CASE WHEN m.meta_value = 'yes' AND d.created_via IN ('checkout', 'store-api') THEN o.id END) AS optin_id"
            . " FROM {$wpdb->prefix}wc_orders o LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
            . " LEFT JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_agumail_optin'"
            . ' WHERE o.billing_email IN (' . implode(', ', array_fill(0, count($emails), '%s')) . ") AND o.type = 'shop_order'"
            . ' AND o.status NOT IN (' . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND o.id <= %d GROUP BY LOWER(o.billing_email)',
            $args
        ), ARRAY_A);
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the first orders of one page's buyers, read once by a background job.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT LOWER(e.meta_value) AS email, MIN(p.post_date_gmt) AS first_at,"
            . " MIN(CASE WHEN %d = 0 OR p.post_date_gmt >= %s THEN p.ID END) AS first_id,"
            . " MIN(CASE WHEN m.meta_value = 'yes' AND v.meta_value IN ('checkout', 'store-api') THEN p.ID END) AS optin_id"
            . " FROM {$wpdb->postmeta} e STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = e.post_id"
            . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
            . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
            . " LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_agumail_optin'"
            . " LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_created_via'"
            . " WHERE e.meta_key = '_billing_email' AND e.meta_value IN (" . implode(', ', array_fill(0, count($emails), '%s')) . ')'
            . " AND p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
            . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND p.ID <= %d GROUP BY LOWER(e.meta_value)',
            $args
        ), ARRAY_A);
    }
    $rows = agumail_bulk_checked($rows);
    if ($rows === null) {
        return null;
    }
    $found = array();
    foreach ((array) $rows as $row) {
        $row = (array) $row;
        $found[strtolower((string) $row['email'])] = array(
            'first_at' => isset($row['first_at']) ? (string) $row['first_at'] : '',
            'first_id' => isset($row['first_id']) && $row['first_id'] !== null ? (int) $row['first_id'] : null,
            'optin_id' => isset($row['optin_id']) && $row['optin_id'] !== null ? (int) $row['optin_id'] : 0,
        );
    }
    return $found;
}

/**
 * For each account, its first purchase (up to the bound) with the box ticked
 * on a submitted checkout and billed to the account's own address: user id =>
 * order id. A tick under another address (a gift, a shared card) opts in that
 * address, never the account. On posts storage the lookup starts from the
 * accounts asked for (postmeta). Null on a database error.
 */
function agumail_bulk_optin_orders(array $user_ids, array $state) {
    global $wpdb;
    if (!$user_ids) {
        return array();
    }
    $user_ids = array_values(array_map('intval', $user_ids));
    $unpaid = agumail_bulk_unpaid_statuses();
    $paid = agumail_bulk_paid_statuses();
    $args = array_merge($user_ids, $unpaid, $paid, array((int) $state['max_order_id']));
    $wpdb->last_error = '';
    if (agumail_bulk_hpos()) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the opted-in orders of one page's accounts, read once by a background job.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.customer_id AS user_id, MIN(o.id) AS optin_id FROM {$wpdb->prefix}wc_orders o"
            . " INNER JOIN {$wpdb->users} u ON u.ID = o.customer_id AND LOWER(o.billing_email) = LOWER(u.user_email)"
            . " INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
            . " LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id"
            . ' WHERE o.customer_id IN (' . implode(', ', array_fill(0, count($user_ids), '%d')) . ") AND o.type = 'shop_order'"
            . " AND d.created_via IN ('checkout', 'store-api')"
            . ' AND o.status NOT IN (' . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . ' AND (d.date_paid_gmt IS NOT NULL OR o.status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND o.id <= %d GROUP BY o.customer_id',
            $args
        ), ARRAY_A);
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the opted-in orders of one page's accounts, read once by a background job.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.meta_value AS user_id, MIN(p.ID) AS optin_id FROM {$wpdb->postmeta} c STRAIGHT_JOIN {$wpdb->posts} p ON p.ID = c.post_id"
            . " INNER JOIN {$wpdb->users} u ON u.ID = c.meta_value"
            . " INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_billing_email' AND LOWER(e.meta_value) = LOWER(u.user_email)"
            . " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_agumail_optin' AND m.meta_value = 'yes'"
            . " INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_created_via' AND v.meta_value IN ('checkout', 'store-api')"
            . " LEFT JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'"
            . " LEFT JOIN {$wpdb->postmeta} dq ON dq.post_id = p.ID AND dq.meta_key = '_paid_date'"
            . " WHERE c.meta_key = '_customer_user' AND c.meta_value IN (" . implode(', ', array_fill(0, count($user_ids), '%s')) . ')'
            . " AND p.post_type = 'shop_order' AND p.post_status NOT IN (" . implode(', ', array_fill(0, count($unpaid), '%s')) . ')'
            . " AND (COALESCE(dp.meta_value, '') NOT IN ('', '0') OR COALESCE(dq.meta_value, '') <> ''"
            . ' OR p.post_status IN (' . implode(', ', array_fill(0, count($paid), '%s')) . '))'
            . ' AND p.ID <= %d GROUP BY c.meta_value',
            $args
        ), ARRAY_A);
    }
    $rows = agumail_bulk_checked($rows);
    if ($rows === null) {
        return null;
    }
    $found = array();
    foreach ((array) $rows as $row) {
        $row = (array) $row;
        $found[(int) $row['user_id']] = (int) $row['optin_id'];
    }
    return $found;
}


// ─────────────────────────────────────────────────────────────────────────────
// The Store data section. The progress block is also what admin.js fetches
// every few seconds while the sync runs.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_bulk_phase_labels() {
    return array(
        'subscribers'          => __('Subscribed customers', 'agumail'),
        'checkout_subscribers' => __('Subscribers from checkout (orders)', 'agumail'),
        'customers'            => __('Other customers', 'agumail'),
        'products'    => __('Products', 'agumail'),
        'orders'      => __('Paid orders', 'agumail'),
    );
}

function agumail_bulk_render_section() {
    if (!agumail_has_api_key()) {
        echo '<p>' . esc_html__('Connect the store to AguMail first. You can then bring in your customers, products and past orders.', 'agumail') . '</p>';
        return;
    }
    agumail_bulk_render_panel(agumail_bulk_state());
}

function agumail_bulk_datetime($timestamp) {
    return wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $timestamp);
}

function agumail_bulk_render_panel(array $state) {
    echo '<div class="agumail-bulk" data-agumail-bulk data-status="' . esc_attr($state['status']) . '" aria-live="polite">';
    if ($state['status'] === 'running') {
        echo '<p class="agumail-bulk-outcome is-running"><span class="spinner is-active" aria-hidden="true"></span><strong>' . esc_html__('Syncing store data.', 'agumail') . '</strong> '
            /* translators: the date and time the sync started */
            . esc_html(sprintf(__('Started %s. It runs in the background, so you can leave this page.', 'agumail'), agumail_bulk_datetime($state['started_at']))) . '</p>';
        agumail_bulk_render_phases($state);
        agumail_bulk_render_wait($state);
        agumail_bulk_render_results($state);
        agumail_bulk_button('bulk_sync_cancel', __('Cancel sync', 'agumail'), false);
    } else {
        agumail_bulk_render_outcome($state);
        if ($state['status'] !== 'idle') {
            agumail_bulk_render_results($state);
        }
        agumail_bulk_render_start($state);
    }
    echo '</div>';
}

function agumail_bulk_render_phases(array $state) {
    $phases = agumail_bulk_phases();
    $current = array_search($state['phase'], $phases, true);
    echo '<table class="widefat striped agumail-bulk-phases" role="presentation"><tbody>';
    foreach (agumail_bulk_phase_labels() as $phase => $label) {
        $at = array_search($phase, $phases, true);
        $done = number_format_i18n($state['done'][$phase]);
        $total = number_format_i18n(max($state['totals'][$phase], $state['done'][$phase]));
        // The bar under the words; a finished part is full even when it had nothing to send.
        $bar_max = max(1, $state['totals'][$phase], $state['done'][$phase]);
        if ($current === false || $at < $current) {
            /* translators: 1: records sent, 2: records in this part of the sync */
            $text = sprintf(__('Done: %1$s of %2$s', 'agumail'), $done, $total);
            $bar = $bar_max;
        } elseif ($at === $current) {
            /* translators: 1: records sent so far, 2: records in this part of the sync */
            $text = sprintf(__('In progress: %1$s of %2$s', 'agumail'), $done, $total);
            $bar = (int) $state['done'][$phase];
        } else {
            /* translators: records in this part of the sync */
            $text = sprintf(__('Waiting: %s', 'agumail'), $total);
            $bar = 0;
        }
        echo '<tr><th scope="row">' . esc_html($label) . '</th> <td>' . esc_html($text)
            . '<progress class="agumail-progress" max="' . esc_attr((string) $bar_max) . '" value="' . esc_attr((string) $bar) . '" aria-hidden="true"></progress></td></tr>';
    }
    echo '</tbody></table>';
}

function agumail_bulk_render_wait(array $state) {
    if ((int) $state['retry_at'] <= agumail_time() && $state['wait'] !== 'paused') {
        return;
    }
    $at = wp_date(get_option('time_format', 'H:i'), (int) $state['retry_at']);
    $error = rtrim((string) $state['error'], '. ');
    switch ($state['wait']) {
        case 'rate':
            /* translators: the time the sync carries on */
            $text = sprintf(__('AguMail asked the plugin to slow down. Carrying on at %s.', 'agumail'), $at);
            break;
        case 'auth':
            /* translators: 1: AguMail's reason, 2: the time of the next try */
            $text = sprintf(__('AguMail refused this store: %1$s. Trying again at %2$s.', 'agumail'), $error, $at);
            break;
        case 'paused':
            $text = __('Sending is paused while the store is disconnected in AguMail. The sync carries on once it is reconnected.', 'agumail');
            break;
        default:
            /* translators: 1: the error, 2: the time of the next try */
            $text = sprintf(__('AguMail could not take the last page (%1$s). Trying again at %2$s.', 'agumail'), $error, $at);
    }
    echo '<p class="agumail-bulk-wait">' . esc_html($text) . '</p>';
}

function agumail_bulk_render_outcome(array $state) {
    $when = agumail_bulk_datetime($state['finished_at']);
    $icons = array('done' => 'yes-alt', 'cancelled' => 'dismiss', 'failed' => 'warning');
    if ($state['status'] === 'done') {
        /* translators: the date and time the sync finished */
        $text = sprintf(__('Finished on %s.', 'agumail'), $when);
    } elseif ($state['status'] === 'cancelled') {
        /* translators: the date and time the sync was cancelled */
        $text = sprintf(__('The sync was cancelled on %s. What was sent before that stays in AguMail.', 'agumail'), $when);
    } elseif ($state['status'] === 'failed') {
        /* translators: 1: the date and time, 2: why the sync stopped */
        $text = sprintf(__('The sync stopped on %1$s: %2$s', 'agumail'), $when, $state['error']);
    } else {
        $text = '';
    }
    if ($text !== '') {
        echo '<p class="agumail-bulk-outcome is-' . esc_attr($state['status']) . '"><span class="dashicons dashicons-' . esc_attr($icons[$state['status']]) . '" aria-hidden="true"></span>'
            . '<strong>' . esc_html($text) . '</strong></p>';
    }
}

/** What AguMail reported, in plain lines. */
function agumail_bulk_result_lines(array $state) {
    $c = $state['contacts'];
    $o = $state['orders'];
    $lines = array();
    /* translators: 1: contacts added, 2: contacts updated */
    $lines[] = sprintf(__('Contacts: %1$s added, %2$s updated.', 'agumail'), number_format_i18n($c['created']), number_format_i18n($c['updated']));
    if ($c['overCapacity'] > 0) {
        /* translators: number of contacts the plan's limit kept out */
        $lines[] = sprintf(_n("%s contact not added: your plan's contact limit.", "%s contacts not added: your plan's contact limit.", $c['overCapacity'], 'agumail'), number_format_i18n($c['overCapacity']));
    }
    if ($c['suppressedSkipped'] > 0) {
        /* translators: number of contacts not added */
        $lines[] = sprintf(_n('%s contact not added: unsubscribed earlier, or not a valid address.', '%s contacts not added: unsubscribed earlier, or not a valid address.', $c['suppressedSkipped'], 'agumail'), number_format_i18n($c['suppressedSkipped']));
    }
    /* translators: number of products */
    $lines[] = sprintf(__('Products: %s synced.', 'agumail'), number_format_i18n($state['products']['upserted']));
    /* translators: 1: orders added, 2: orders updated, 3: orders unchanged */
    $lines[] = sprintf(__('Orders: %1$s added, %2$s updated, %3$s already up to date.', 'agumail'), number_format_i18n($o['created']), number_format_i18n($o['updated']), number_format_i18n($o['duplicates']));
    $skipped = $state['skipped']['contacts'] + $state['skipped']['orders'];
    if ($skipped > 0) {
        /* translators: number of records */
        $lines[] = sprintf(_n('%s record skipped: no valid email address.', '%s records skipped: no valid email address.', $skipped, 'agumail'), number_format_i18n($skipped));
    }
    $refused = array_sum($state['refused']);
    if ($refused > 0) {
        /* translators: number of records */
        $lines[] = sprintf(_n('%s record refused by AguMail. The log has the details.', '%s records refused by AguMail. The log has the details.', $refused, 'agumail'), number_format_i18n($refused));
    }
    return $lines;
}

function agumail_bulk_render_results(array $state) {
    echo '<ul class="agumail-bulk-results">';
    foreach (agumail_bulk_result_lines($state) as $i => $line) {
        $strong = $state['contacts']['overCapacity'] > 0 && $i === 1;
        echo $strong ? '<li class="is-warning"><strong>' . esc_html($line) . '</strong></li>' : '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul>';
}

function agumail_bulk_render_start(array $state) {
    if ($state['status'] === 'idle') {
        echo '<p>' . esc_html__('Bring your customers, products and past orders into AguMail. Past orders arrive as history and start no emails.', 'agumail') . '</p>';
    }
    if ($state['status'] === 'failed') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="agumail-inline-form">';
        agumail_admin_action_fields('bulk_sync_start');
        echo '<input type="hidden" name="agumail_bulk_resume" value="1" />';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Try again', 'agumail') . '</button></p></form>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="agumail-bulk-start">';
    agumail_admin_action_fields('bulk_sync_start');
    echo '<label for="agumail_bulk_months">' . esc_html__('Orders to include', 'agumail') . '</label> ';
    echo '<select name="agumail_bulk_months" id="agumail_bulk_months">';
    $choices = array(
        0  => __('All order history', 'agumail'),
        24 => __('The last 24 months', 'agumail'),
        12 => __('The last 12 months', 'agumail'),
        6  => __('The last 6 months', 'agumail'),
        3  => __('The last 3 months', 'agumail'),
    );
    foreach ($choices as $months => $label) {
        echo '<option value="' . esc_attr((string) $months) . '"' . selected((int) $state['months'], $months, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    $label = $state['status'] === 'idle' ? __('Sync store data', 'agumail') : ($state['status'] === 'failed' ? __('Start over', 'agumail') : __('Sync again', 'agumail'));
    echo '<button type="submit" class="button' . ($state['status'] === 'failed' ? '' : ' button-primary') . '">' . esc_html($label) . '</button>';
    echo '</form>';
}

function agumail_bulk_button($action, $label, $primary = true) {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="agumail-inline-form">';
    agumail_admin_action_fields($action);
    echo '<p><button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button></p></form>';
}

/** One line for the Status section. */
function agumail_bulk_summary(array $state) {
    $labels = agumail_bulk_phase_labels();
    switch ($state['status']) {
        case 'running':
            $phase = isset($labels[$state['phase']]) ? $state['phase'] : 'subscribers';
            /* translators: 1: the part of the store being sent, 2: records sent, 3: records in that part */
            return sprintf(__('Running: %1$s, %2$s of %3$s.', 'agumail'), strtolower($labels[$phase]),
                number_format_i18n($state['done'][$phase]), number_format_i18n(max($state['totals'][$phase], $state['done'][$phase])));
        case 'done':
            /* translators: 1: the date and time, 2: contacts added, 3: orders added */
            return sprintf(__('Finished on %1$s: %2$s contacts and %3$s orders added.', 'agumail'), agumail_bulk_datetime($state['finished_at']),
                number_format_i18n($state['contacts']['created']), number_format_i18n($state['orders']['created']));
        case 'cancelled':
            /* translators: the date and time the sync was cancelled */
            return sprintf(__('Cancelled on %s.', 'agumail'), agumail_bulk_datetime($state['finished_at']));
        case 'failed':
            /* translators: why the sync stopped */
            return sprintf(__('Stopped: %s', 'agumail'), $state['error']);
    }
    return __('Not run yet.', 'agumail');
}
