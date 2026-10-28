<?php
// Carts: the shopper's cart row, the checkout email capture, the restore link
// in a recovery email, and cart housekeeping.
//
// Every storefront request here ends with shutdown, as it does in WordPress,
// because that is where a request's cart changes are written: once, however
// many cart hooks fired.

// ── The store ──

function carts_catalogue() {
    return array(
        'mug'     => agm_carts_product(11, 'Mug', 30, array('sku' => 'MUG-1', 'image_id' => 501)),
        'candle'  => agm_carts_product(12, 'Candle', 12, array('sku' => 'CAN-1')),
        'shirt'   => agm_carts_product(20, 'Shirt', 25, array('type' => 'variable', 'sku' => 'SHIRT', 'image_id' => 700)),
        'shirt_m' => agm_carts_product(21, 'Shirt - M', 27, array('type' => 'variation', 'parent_id' => 20, 'sku' => 'SHIRT-M', 'image_id' => 701)),
    );
}

/** One storefront request of a kind, ending with shutdown even when it ends in JSON, a redirect or wp_die. */
function carts_request($name, callable $body, $kind = 'shopper') {
    return agm_request($name, $kind, function () use ($body) {
        try {
            $body();
        } finally {
            do_action('shutdown');
        }
    });
}

/** The capture call the checkout script makes: POST ?wc-ajax=agumail_capture. Adds the JSON answer as 'response'. */
function carts_capture($email, $context = 'checkout', array $extra = array()) {
    static $calls = 0;
    $calls++;
    $before = count($GLOBALS['agm']['json']);
    $result = carts_request("capture #$calls " . $email, function () use ($email, $context, $extra) {
        agm_input(array('wc-ajax' => 'agumail_capture'), array_merge(array('email' => $email, 'context' => $context), $extra));
        do_action('wc_ajax_agumail_capture');
    });
    $answers = array_slice($GLOBALS['agm']['json'], $before);
    $result['response'] = $answers ? $answers[0]['body'] : null;
    return $result;
}

/** A cart change through the hooks WooCommerce fires for it, in its own request. */
function carts_add($product, $qty = 1, array $variation = array()) {
    $key = null;
    carts_request('add ' . $product->get_name(), function () use ($product, $qty, $variation, &$key) {
        $key = WC()->cart->add_line($product, $qty, $variation);
        $item = WC()->cart->get_cart_item($key);
        do_action('woocommerce_add_to_cart', $key, $item['product_id'], $qty, $item['variation_id'], $variation, array());
    });
    return $key;
}

/** $array's values for $keys, in that order; keys it lacks are left out. */
function carts_pick(array $array, array $keys) {
    $picked = array();
    foreach ($keys as $key) {
        if (array_key_exists($key, $array)) {
            $picked[$key] = $array[$key];
        }
    }
    return $picked;
}

/** A request may call nothing and write at most $max rows of the plugin's own tables, and nothing else. */
function carts_expect_quiet(array $result, $max = 1) {
    $name = $result['name'];
    $calls = array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $result['http']);
    expect_same(array(), $calls, "\"$name\" called AguMail");
    $describe = function (array $writes) {
        return implode(', ', array_map(function ($write) { return $write['kind'] . ':' . $write['name'] . ($write['detail'] ? ' (' . $write['detail'] . ')' : ''); }, $writes));
    };
    $other = array_values(array_filter($result['writes'], function ($write) { return !in_array($write['kind'], array('carts', 'outbox'), true); }));
    expect_that(!$other, "\"$name\" wrote " . $describe($other));
    expect_that(count($result['writes']) <= $max, "\"$name\" made " . count($result['writes']) . " writes, more than $max: " . $describe($result['writes']));
}

function carts_session($key) {
    return WC()->session->get($key);
}

function carts_row($id = null) {
    return agumail_cart_get($id === null ? carts_session('agumail_cart_id') : $id);
}

/** Line summaries: [product_id, variation_id, qty]. */
function carts_line_keys(array $lines) {
    return array_map(function ($line) { return array($line['product_id'], $line['variation_id'], $line['qty']); }, $lines);
}

/** What is in WooCommerce's cart: [product_id, variation_id, qty]. */
function carts_contents() {
    return array_values(array_map(function ($item) {
        return array($item['product_id'], $item['variation_id'], $item['quantity']);
    }, WC()->cart->get_cart()));
}

/** One Action Scheduler run of the drain, in cron. */
function carts_drain() {
    agm_request('drain', 'cron', function () {
        agumail_run_in_background('agumail_drain');
    });
}

function carts_sent($path) {
    $requests = agm_http_requests($path);
    return $requests ? $requests[count($requests) - 1]['body'] : null;
}

// ── Capture ──

function test_carts_capture_with_items_creates_the_cart_row_in_one_write_with_no_call() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 2);

    $result = carts_capture(' Ada@Example.com ');
    carts_expect_quiet($result);
    expect_same(1, count(agm_writes('carts', $result['name'])), 'capture should write the cart row, once');
    expect_same(true, $result['response']['ok'], 'capture should answer ok');

    $id = carts_session('agumail_cart_id');
    expect_that(is_string($id) && preg_match('/^wc-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id), 'the cart id is not wc-<uuid>: ' . var_export($id, true));
    expect_same('ada@example.com', carts_session('agumail_email'), 'the email was not kept in the session');
    $row = carts_row();
    expect_same(1, count($row['lines']), 'the cart lines were not recorded');
    expect_same(
        array('product_id' => 11, 'variation_id' => 0, 'variation' => array(), 'qty' => 2, 'line_total' => 60.0),
        array_merge($row['lines'][0], array('line_total' => (float) $row['lines'][0]['line_total'])),
        'the cart line is wrong'
    );
    expect_same(
        array('ada@example.com', 60.0, 'EUR', 't_guest', null, 'open'),
        array($row['email'], $row['total'], $row['currency'], $row['session_key'], $row['user_id'], $row['status']),
        'the cart row is wrong'
    );
    expect_that(preg_match('/^[0-9a-f]{32}$/', $row['token']) === 1, 'the cart has no restore token');
}

function test_carts_capture_of_a_new_email_updates_the_same_row() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $id = carts_session('agumail_cart_id');

    $result = carts_capture('bea@example.com', 'checkout');
    carts_expect_quiet($result);
    expect_same(1, count(agm_writes('carts', $result['name'])), 'a changed email should update the row, once');
    expect_same($id, carts_session('agumail_cart_id'), 'a changed email started a new cart');
    expect_same('bea@example.com', carts_row($id)['email'], 'the row kept the old email');
    expect_same(1, count(agm_store()->carts), 'a second row was written');
}

function test_carts_capture_of_the_same_email_again_writes_nothing() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $version = carts_row()['version'];

    $again = carts_capture('ADA@example.com');
    expect_same(array(), $again['writes'], 'the same email written again');
    expect_same(true, $again['response']['ok'], 'the repeat was not answered');
    expect_same($version, carts_row()['version'], 'the cart moved on for nothing');
}

function test_carts_capture_with_an_empty_cart_keeps_the_email_in_the_session_only() {
    carts_catalogue();
    $result = carts_capture('ada@example.com', 'login');
    carts_expect_quiet($result, 0);
    expect_same('ada@example.com', carts_session('agumail_email'), 'the email was not kept in the session');
    expect_same(null, carts_session('agumail_cart_id'), 'an empty cart was given an id');
    expect_same(array(), agm_store()->carts, 'an empty cart was written');
}

function test_carts_the_email_captured_before_shopping_names_the_cart_started_later() {
    $products = carts_catalogue();
    carts_capture('ada@example.com', 'register');
    carts_add($products['candle']);
    expect_same('ada@example.com', carts_row()['email'], 'the cart did not take the email captured earlier');
}

function test_carts_capture_is_capped_at_twenty_changes_per_session() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    for ($i = 1; $i <= 20; $i++) {
        $result = carts_capture("shopper$i@example.com");
        expect_same(1, count($result['writes']), "change $i was not recorded");
    }
    $over = carts_capture('shopper21@example.com');
    expect_same(array(), $over['writes'], 'a 21st change was written');
    expect_same(true, $over['response']['ok'], 'an over-the-cap capture should still answer ok');
    expect_that(!isset($over['response']['sig']), 'an over-the-cap capture was signed');
    expect_same('shopper20@example.com', carts_session('agumail_email'), 'the session took a 21st change');
    expect_same('shopper20@example.com', carts_row()['email'], 'the row took a 21st change');

    $same = carts_capture('shopper20@example.com');
    expect_same(array(), $same['writes'], 'the current email is not a change');
}

function test_carts_capture_is_limited_per_address_when_a_persistent_object_cache_can_count() {
    carts_catalogue();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $capture = function ($email) {
        WC()->session = new WC_Session_Handler();
        $result = carts_capture($email, 'login');
        expect_same(array(), $result['writes'], 'counting captures wrote to the database');
        return carts_session('agumail_email');
    };
    for ($i = 1; $i <= 61; $i++) {
        expect_same("a$i@example.com", $capture("a$i@example.com"), "without a persistent object cache, capture $i was refused");
    }
    $GLOBALS['agm']['ext_object_cache'] = true;
    for ($i = 1; $i <= 60; $i++) {
        expect_same("b$i@example.com", $capture("b$i@example.com"), "capture $i from one address was refused");
    }
    expect_same(null, $capture('b61@example.com'), 'a 61st new email from one address within the hour was kept');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    expect_same('c1@example.com', $capture('c1@example.com'), 'another address was refused');
}

function test_carts_capture_ignores_bad_addresses_store_managers_and_unconnected_stores() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);

    foreach (array('', 'not-an-email', 'ada@', '@example.com') as $bad) {
        $result = carts_capture($bad);
        expect_same(array(), $result['writes'], "\"$bad\" was captured");
        expect_same(true, $result['response']['ok'], "\"$bad\" was not answered");
    }
    expect_same(null, carts_session('agumail_email'), 'a bad address reached the session');

    agm_sign_in(1, 'owner@example.com');
    agm_grant('manage_woocommerce');
    $manager = carts_capture('owner@example.com');
    expect_same(array(), $manager['writes'], "the store manager's own test cart was captured");
    expect_same(null, carts_session('agumail_email'), "the store manager's email reached the session");
    agm_grant('manage_woocommerce', false);
    agm_sign_out();

    unset($GLOBALS['agm']['options']['agumail_api_key']);
    $unconnected = carts_capture('ada@example.com');
    expect_same(array(), $unconnected['writes'], 'a cart was recorded before the store was connected');
    expect_same(true, $unconnected['response']['ok'], 'the capture call was not answered');
}

function test_carts_capture_without_a_session_keeps_nothing_and_starts_none() {
    $products = carts_catalogue();
    WC()->session = agm_carts_fresh_session();
    WC()->cart->add_line($products['mug'], 1);

    $result = carts_capture('ada@example.com');
    expect_same(array(), $result['writes'], 'a cart was written for a browser with no session');
    expect_same(false, WC()->session->started, 'capture started a session, which stops page caching for this shopper');
    expect_that(!isset($result['response']['sig']), 'an uncounted capture was signed');
}

function test_carts_capture_answers_with_the_signature_tracking_makes() {
    update_option('agumail_tracking_enabled', 'yes');
    update_option('agumail_config', array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://api.agumail.test/t/site.js',
        'identifySecret' => '0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg',
    ));
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);

    // The page identifies with the address the answer names, which is the one signed, not the one typed.
    $result = carts_capture(' Ada.Lovelace@Example.COM ');
    $expected = function_exists('agumail_identify_signature') ? (string) agumail_identify_signature('ada.lovelace@example.com') : '';
    if ($expected === '') {
        expect_that(!isset($result['response']['sig']) && !isset($result['response']['email']), 'a signature or address was sent though tracking made no signature');
    } else {
        expect_same(
            array('sig' => $expected, 'email' => 'ada.lovelace@example.com'),
            carts_pick($result['response'], array('sig', 'email')),
            'the answer does not name the exact address it signed'
        );
    }
    $again = carts_capture('ada.lovelace@example.com');
    if ($expected !== '') {
        expect_same($expected, isset($again['response']['sig']) ? $again['response']['sig'] : null, 'the repeat was not signed');
    }
}

function test_carts_capture_hands_out_no_signature_while_tracking_is_off() {
    update_option('agumail_config', array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://api.agumail.test/t/site.js',
        'identifySecret' => '0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg',
    ));
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    $result = carts_capture('ada.lovelace@example.com');
    expect_same(array(), carts_pick($result['response'], array('sig', 'email')), 'a signature was handed out with website tracking off');
    expect_same(true, $result['response']['ok'], 'the capture did not answer ok');
}

// ── The checkout's own updates, for when the script never ran ──

function test_carts_classic_order_review_captures_the_guest_cart() {
    $products = carts_catalogue();
    $added = carts_request('add to cart', function () use ($products) {
        $key = WC()->cart->add_line($products['candle'], 1);
        do_action('woocommerce_add_to_cart', $key, 12, 1, 0, array(), array());
    });
    expect_same(array(), $added['writes'], 'a cart with nobody to recover it was recorded');

    $review = carts_request('order review', function () {
        $posted = 'billing_first_name=Cy&billing_email=cy%40example.com&agumail_optin=1&agumail_optin_shown=1';
        agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => $posted));
        do_action('woocommerce_checkout_update_order_review', $posted);
    });
    carts_expect_quiet($review);
    expect_same(1, count($review['writes']), 'the order review did not record the cart');
    expect_same('cy@example.com', carts_row()['email'], "the guest's cart was not captured from the classic checkout");
    expect_same(array(array(12, 0, 1)), carts_line_keys(carts_row()['lines']), 'the cart lines are wrong');

    $again = carts_request('order review again', function () {
        do_action('woocommerce_checkout_update_order_review', 'billing_email=cy%40example.com');
    });
    expect_same(array(), $again['writes'], 'every order review refresh rewrites the cart');
}

function test_carts_block_checkout_customer_update_captures_the_guest_cart() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['candle'], 1);
    $result = carts_request('block checkout: email typed', function () {
        WC()->customer->set_billing_email('Dee@Example.com');
        $request = new WP_REST_Request('POST', '/wc/store/v1/cart/update-customer', array('billing_address' => array('email' => 'Dee@Example.com')));
        do_action('woocommerce_store_api_cart_update_customer_from_request', WC()->customer, $request);
    }, 'shopper_rest');
    carts_expect_quiet($result);
    expect_same(1, count($result['writes']), 'the block checkout update did not record the cart');
    expect_same('dee@example.com', carts_row()['email'], "the guest's cart was not captured from the block checkout");
}

// ── Cart hooks ──

function test_carts_an_anonymous_cart_is_not_recorded() {
    $products = carts_catalogue();
    carts_add($products['mug']);
    expect_same(array(), agm_store()->carts, 'a cart with nobody to recover it was recorded');
    expect_same(null, carts_session('agumail_cart_id'), 'an anonymous cart was given an id');
}

function test_carts_cart_changes_update_the_row_once_per_request() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');

    $keys = array();
    $added = carts_request('add two items', function () use ($products, &$keys) {
        $keys['mug'] = WC()->cart->add_line($products['mug'], 1);
        do_action('woocommerce_add_to_cart', $keys['mug'], 11, 1, 0, array(), array());
        do_action('woocommerce_cart_updated');
        $keys['shirt'] = WC()->cart->add_line($products['shirt_m'], 1, array('attribute_size' => 'M'));
        do_action('woocommerce_add_to_cart', $keys['shirt'], 20, 1, 21, array('attribute_size' => 'M'), array());
        do_action('woocommerce_ajax_added_to_cart', 20);
    });
    carts_expect_quiet($added);
    expect_same(1, count($added['writes']), 'two items added in one request should be one write');
    $id = carts_session('agumail_cart_id');
    $row = carts_row($id);
    expect_same(array('bea@example.com', 9), array($row['email'], $row['user_id']), 'a signed-in cart should carry the account');
    expect_same(array(array(11, 0, 1), array(20, 21, 1)), carts_line_keys($row['lines']), 'the lines are wrong');
    expect_same(array('attribute_size' => 'M'), $row['lines'][1]['variation'], 'the variation was lost');
    expect_same(57.0, $row['total'], 'the total is wrong');

    $changed = carts_request('change quantity', function () use ($keys) {
        WC()->cart->set_quantity($keys['mug'], 3);
        do_action('woocommerce_after_cart_item_quantity_update', $keys['mug'], 3, 1, WC()->cart);
        do_action('woocommerce_cart_item_set_quantity', $keys['mug'], 3, WC()->cart);
    });
    carts_expect_quiet($changed);
    expect_same(1, count($changed['writes']), 'a quantity change should be one write');
    expect_same(array(array(11, 0, 3), array(20, 21, 1)), carts_line_keys(carts_row($id)['lines']), 'the quantity was not recorded');
    expect_same(117.0, carts_row($id)['total'], 'the total did not follow the quantity');

    $removed = carts_request('remove item', function () use ($keys) {
        WC()->cart->remove_cart_item($keys['shirt']);
        do_action('woocommerce_cart_item_removed', $keys['shirt'], WC()->cart);
    });
    carts_expect_quiet($removed);
    expect_same(array(array(11, 0, 3)), carts_line_keys(carts_row($id)['lines']), 'the removed line stayed');

    $restored = carts_request('undo remove', function () use ($products) {
        $key = WC()->cart->add_line($products['shirt_m'], 1, array('attribute_size' => 'M'));
        do_action('woocommerce_cart_item_restored', $key, WC()->cart);
    });
    carts_expect_quiet($restored);
    expect_same(2, count(carts_row($id)['lines']), 'the restored line was not recorded');
    expect_same($id, carts_session('agumail_cart_id'), 'the cart changed id along the way');
    expect_same(1, count(agm_store()->carts), 'more than one row for one cart');
}

function test_carts_coupons_and_the_saved_cart_merged_at_login_update_the_row() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    carts_add($products['mug']);
    foreach (array('woocommerce_applied_coupon', 'woocommerce_removed_coupon') as $hook) {
        $result = carts_request($hook, function () use ($hook) {
            do_action($hook, 'save10');
        });
        carts_expect_quiet($result);
        expect_same(1, count($result['writes']), "$hook did not update the cart's total");
    }

    WC()->session->set('cart', array('k' => array('product_id' => 11)));
    $page = carts_request('a page reading the cart', function () {
        do_action('woocommerce_load_cart_from_session');
        do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    });
    expect_same(array(), $page['writes'], 'every page load rewrites the cart');

    update_user_meta(9, '_woocommerce_load_saved_cart_after_login', 1);
    $merged = carts_request('first page after login', function () use ($products) {
        do_action('woocommerce_load_cart_from_session');
        WC()->cart->add_line($products['candle'], 1);
        do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    });
    carts_expect_quiet($merged);
    expect_same(array(array(11, 0, 1), array(12, 0, 1)), carts_line_keys(carts_row()['lines']), 'the saved cart merged at login was not recorded');
    delete_user_meta(9, '_woocommerce_load_saved_cart_after_login');

    WC()->session->set('cart', null);
    $fresh = carts_request('first page in a new session', function () use ($products) {
        do_action('woocommerce_load_cart_from_session');
        WC()->cart->add_line($products['shirt_m'], 1, array('attribute_size' => 'M'));
        do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    });
    carts_expect_quiet($fresh);
    expect_same(3, count(carts_row()['lines']), 'the saved cart loaded into a new session was not recorded');
}

function test_carts_a_signed_in_customer_has_one_open_cart_across_devices() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    $devices = array();
    $use = function ($device) use (&$devices) {
        if (!isset($devices[$device])) {
            $devices[$device] = array('session' => new WC_Session_Handler(), 'cart' => new WC_Cart());
            $devices[$device]['session']->customer_id = 't_' . $device;
        }
        WC()->session = $devices[$device]['session'];
        WC()->cart = $devices[$device]['cart'];
    };
    $open_rows = function () {
        return array_values(array_filter(agm_store()->carts, function ($row) { return $row['status'] === 'open'; }));
    };

    $use('laptop');
    carts_add($products['mug']);
    $id = carts_session('agumail_cart_id');

    $use('phone');
    $keys = array();
    $result = carts_request('phone: add item', function () use ($products, &$keys) {
        $keys['candle'] = WC()->cart->add_line($products['candle'], 1);
        do_action('woocommerce_add_to_cart', $keys['candle'], 12, 1, 0, array(), array());
    });
    carts_expect_quiet($result);
    expect_same(1, count($result['writes']), 'a change on the second device should be one write');
    expect_same($id, carts_session('agumail_cart_id'), "the second device started another cart for the same customer, so they would get two reminder series");
    expect_same(1, count($open_rows()), 'the customer has more than one open cart');
    expect_same(array(array(12, 0, 1)), carts_line_keys(carts_row($id)['lines']), "the row does not follow the device the customer is using");

    $use('laptop');
    $result = carts_request('laptop: add item', function () use ($products) {
        $key = WC()->cart->add_line($products['shirt_m'], 1, array('attribute_size' => 'M'));
        do_action('woocommerce_add_to_cart', $key, 20, 1, 21, array('attribute_size' => 'M'), array());
    });
    carts_expect_quiet($result);
    expect_same(1, count($result['writes']), 'a change back on the first device should be one write');
    expect_same(array($id, 1), array(carts_session('agumail_cart_id'), count($open_rows())), 'the first device left the shared cart');

    // Guests stay one cart per session.
    agm_sign_out();
    $use('guest-a');
    carts_capture('cy@example.com');
    carts_add($products['mug']);
    $use('guest-b');
    carts_capture('cy@example.com');
    carts_add($products['mug']);
    expect_same(3, count($open_rows()), "guests' separate sessions should keep separate carts");
}

function test_carts_a_guest_session_racing_itself_keeps_one_cart() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('dee@example.com');
    $id = carts_session('agumail_cart_id');
    // The block checkout's customer update ran beside the capture, on a copy of the session from before it.
    WC()->session->set('agumail_cart_id', null);
    WC()->session->set('agumail_email', null);
    $result = carts_request('block checkout: update customer', function () {
        WC()->customer->set_billing_email('dee@example.com');
        do_action('woocommerce_store_api_cart_update_customer_from_request', WC()->customer, new WP_REST_Request('POST', '/wc/store/v1/cart/update-customer'));
    }, 'shopper_rest');
    carts_expect_quiet($result);
    expect_same($id, carts_session('agumail_cart_id'), "the guest's session got a second cart");
    expect_same(1, count(agm_store()->carts), 'one guest session has two cart rows');
}

function test_carts_a_new_cart_right_after_a_paid_checkout_is_not_the_bought_one() {
    $products = carts_catalogue();
    list($id) = carts_checkout_empties(function () {
        $order = carts_paid_order(900);
        do_action('woocommerce_checkout_order_processed', 900, array(), $order);
    });
    // The bought cart stays open until its order is sent; the next cart must not take it over.
    carts_add($products['candle']);
    $next = carts_session('agumail_cart_id');
    expect_that($next && $next !== $id, 'the next cart took over the one just bought');
    expect_same(array(array(11, 0, 1)), carts_line_keys(carts_row($id)['lines']), 'the bought cart was overwritten');
}

function test_carts_a_signed_in_customer_is_reminded_at_their_account_email() {
    $products = carts_catalogue();
    // A guest typed an address, then signed in to another account: the account's address wins.
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('cy@example.com');
    $id = carts_session('agumail_cart_id');
    $user = agm_sign_in(9, 'bea@example.com');
    carts_request('first page signed in', function () {
        do_action('woocommerce_guest_session_to_user_id', 't_guest', '9');
    });
    expect_same(null, carts_session('agumail_email'), 'the address a guest typed was kept after signing in to another account');
    carts_add($products['candle']);
    expect_same(array($id, 'bea@example.com'), array(carts_session('agumail_cart_id'), carts_row($id)['email']), 'the cart did not move to the account email');

    // Signing in through the login form clears it too.
    agm_sign_out();
    carts_capture('cy2@example.com');
    carts_request('log in', function () use ($user) {
        do_action('wp_login', 'bea', $user);
    });
    expect_same(null, carts_session('agumail_email'), 'the address typed before logging in was kept');
    agm_sign_in(9);

    // An address typed while signed in is the customer's own choice, and wins.
    carts_capture('bea.work@example.com');
    expect_same('bea.work@example.com', carts_row($id)['email'], 'an address typed while signed in did not win');

    // Typing the account address itself says nothing new: after the account email changes, the cart follows.
    carts_capture('bea@example.com');
    $user->user_email = 'bea.new@example.com';
    carts_add($products['mug'], 2);
    expect_same('bea.new@example.com', carts_row($id)['email'], 'the cart did not move to the new account email');
}

function test_carts_capture_is_refused_from_an_address_with_thirty_carts_this_hour() {
    $now = 1790000000;
    $clock = $now;
    agumail_set_clock(function () use (&$clock) { return $clock; });
    $products = carts_catalogue();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $in_new_session = function ($email, $label) use ($products) {
        WC()->session = new WC_Session_Handler();
        WC()->session->customer_id = 't_' . $label;
        WC()->cart = new WC_Cart();
        WC()->cart->add_line($products['mug'], 1);
        return carts_capture($email);
    };
    $clock = $now - HOUR_IN_SECONDS - 60;
    for ($i = 1; $i <= 5; $i++) {
        $in_new_session("old$i@example.com", "old$i");
    }
    $clock = $now;
    for ($i = 1; $i <= 30; $i++) {
        $result = $in_new_session("s$i@example.com", "s$i");
        expect_same(1, count($result['writes']), "cart $i from one address was refused");
    }
    $row = carts_row();
    expect_same(hash_hmac('sha256', '203.0.113.9', wp_salt()), $row['ip_hash'], 'the cart does not record a hash of the address it came from');

    $over = $in_new_session('s31@example.com', 's31');
    expect_same(array(), $over['writes'], 'a 31st cart from one address within the hour was recorded');
    expect_same(null, carts_session('agumail_email'), 'a 31st capture from one address within the hour was kept');
    expect_that(!isset($over['response']['sig']), 'a refused capture was signed');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    expect_same(1, count($in_new_session('t1@example.com', 't1')['writes']), 'another address was refused');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $clock = $now + HOUR_IN_SECONDS + 1;
    expect_same(1, count($in_new_session('s32@example.com', 's32')['writes']), 'the address was still refused an hour later');
}

function test_carts_an_emptied_cart_is_sent_empty() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    $key = carts_add($products['candle']);
    $id = carts_session('agumail_cart_id');

    $removed = carts_request('remove the last item', function () use ($key) {
        WC()->cart->remove_cart_item($key);
        do_action('woocommerce_cart_item_removed', $key, WC()->cart);
        do_action('woocommerce_cart_updated');
    });
    carts_expect_quiet($removed);
    expect_same('emptied', carts_row($id)['status'], 'the emptied cart is still open');
    expect_same(null, carts_session('agumail_cart_id'), 'the emptied cart kept its id');

    carts_drain();
    expect_same(array('/api/v1/carts'), array_map(function ($r) { return $r['path']; }, agm_http_requests()), 'an emptied cart needs no contact');
    $carts = carts_sent('/api/v1/carts')['carts'];
    expect_same(1, count($carts), 'expected one cart');
    expect_same(array($id, array(), 0.0), array($carts[0]['externalId'], $carts[0]['items'], (float) $carts[0]['total']), 'the emptied cart still holds its old items');
    expect_same('open', $carts[0]['status'], 'an emptied cart goes as an open cart with nothing in it');
    expect_same(1, count(carts_row($id)['lines']), 'the row forgot its lines, so its recovery link can no longer restore it');
}

function test_carts_the_next_cart_after_an_emptied_one_is_a_new_cart() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    carts_add($products['candle']);
    $first = carts_session('agumail_cart_id');
    $emptied = carts_request('empty cart', function () {
        WC()->cart->empty_cart();
        do_action('woocommerce_cart_emptied', true);
    });
    carts_expect_quiet($emptied);
    carts_add($products['mug']);
    $second = carts_session('agumail_cart_id');
    expect_that($second && $second !== $first, "the second cart reuses the first one's id, so it can never be recovered");

    carts_drain();
    $carts = carts_sent('/api/v1/carts')['carts'];
    expect_same(2, count($carts), 'expected two carts, got ' . count($carts));
    expect_same(array($first, array()), array($carts[0]['externalId'], $carts[0]['items']), 'the first cart was not sent empty');
    expect_same($second, $carts[1]['externalId'], 'the second cart was not sent');
    expect_same(array('Mug'), array_column($carts[1]['items'], 'title'), 'the new cart does not hold the new item');
}

/** A guest's captured cart, then a request in which WooCommerce empties it after $before ran. */
function carts_checkout_empties(callable $before) {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $id = carts_session('agumail_cart_id');
    $version = carts_row($id)['version'];
    $result = carts_request('checkout empties the cart', function () use ($before) {
        $before();
        WC()->cart->empty_cart();
        do_action('woocommerce_cart_emptied', true);
    });
    return array($id, $version, $result);
}

/** A paid order: the orders feature sends it and closes its cart, so the row is left alone here. */
function carts_expect_left_for_the_order(array $emptied, $label) {
    list($id, $version, $result) = $emptied;
    carts_expect_quiet($result, 0);
    expect_same(array('open', $version), array(carts_row($id)['status'], carts_row($id)['version']), "$label: the cart was changed, though its paid order carries it");
    expect_same(null, carts_session('agumail_cart_id'), "$label: the ordered cart's id was not retired, so the next cart would reuse it");
    $products = carts_catalogue();
    carts_add($products['candle']);
    $next = carts_session('agumail_cart_id');
    expect_that($next && $next !== $id, "$label: the next cart reused the ordered one's id");
    expect_same(array('open', $version), array(carts_row($id)['status'], carts_row($id)['version']), "$label: the next cart touched the ordered one");
}

/**
 * An order not paid yet (bank transfer, cheque, cash on delivery held on-hold):
 * nothing sends it until it is paid, so its cart closes here, in the one write
 * of that request, and goes to AguMail as completed.
 */
function carts_expect_closed_for_the_order(array $emptied, $order_id, $label) {
    list($id, , $result) = $emptied;
    carts_expect_quiet($result);
    expect_same(1, count($result['writes']), "$label: the cart of an unpaid order was not closed");
    expect_same(array('ordered', $order_id), array(carts_row($id)['status'], carts_row($id)['order_id']), "$label: the cart is still open, so the buyer would get reminders");
    expect_same(null, carts_session('agumail_cart_id'), "$label: the ordered cart's id was not retired");
    carts_drain();
    $cart = carts_sent('/api/v1/carts')['carts'][0];
    expect_same(array($id, 'completed'), array($cart['externalId'], $cart['status']), "$label: AguMail was not told the cart was bought");
    expect_same(array(), agm_http_requests('/api/v1/contacts'), "$label: a bought cart sent its contact");
    $products = carts_catalogue();
    carts_add($products['candle']);
    $next = carts_session('agumail_cart_id');
    expect_that($next && $next !== $id, "$label: the next cart reused the ordered one's id");
}

function carts_paid_order($id, $status = 'processing') {
    return agm_order($id, array('status' => $status, 'date_paid' => agm_date(time())));
}

function test_carts_the_classic_checkout_emptying_the_cart_leaves_the_row_for_the_order() {
    $order = carts_paid_order(900);
    carts_expect_left_for_the_order(carts_checkout_empties(function () use ($order) {
        do_action('woocommerce_checkout_order_processed', 900, array('billing_email' => 'ada@example.com'), $order);
    }), 'classic checkout');
}

function test_carts_the_block_checkout_emptying_the_cart_leaves_the_row_for_the_order() {
    $order = carts_paid_order(900);
    carts_expect_left_for_the_order(carts_checkout_empties(function () use ($order) {
        do_action('woocommerce_store_api_checkout_order_processed', $order);
    }), 'block checkout');
}

function test_carts_the_thank_you_page_emptying_the_cart_leaves_the_row_for_the_order() {
    carts_paid_order(904, 'completed');
    carts_expect_left_for_the_order(carts_checkout_empties(function () {
        $GLOBALS['agm']['page'] = 'order-received';
        $GLOBALS['wp'] = (object) array('query_vars' => array('order-received' => '904'));
    }), 'thank-you page');
}

function test_carts_a_paid_order_awaiting_payment_emptying_the_cart_leaves_the_row_for_the_order() {
    carts_paid_order(901);
    carts_expect_left_for_the_order(carts_checkout_empties(function () {
        WC()->session->set('order_awaiting_payment', 901);
    }), 'paid order');
}

function test_carts_an_on_hold_bank_transfer_order_closes_its_cart() {
    $order = agm_order(903, array('status' => 'on-hold', 'payment_method' => 'bacs'));
    carts_expect_closed_for_the_order(carts_checkout_empties(function () use ($order) {
        do_action('woocommerce_checkout_order_processed', 903, array('billing_email' => 'ada@example.com'), $order);
    }), 903, 'bank transfer');
}

function test_carts_an_on_hold_block_checkout_order_closes_its_cart() {
    $order = agm_order(905, array('status' => 'on-hold', 'payment_method' => 'cod'));
    carts_expect_closed_for_the_order(carts_checkout_empties(function () use ($order) {
        do_action('woocommerce_store_api_checkout_order_processed', $order);
    }), 905, 'cash on delivery, on hold');
}

function test_carts_an_unpaid_order_on_the_thank_you_page_closes_its_cart() {
    agm_order(906, array('status' => 'on-hold', 'payment_method' => 'cheque'));
    carts_expect_closed_for_the_order(carts_checkout_empties(function () {
        $GLOBALS['agm']['page'] = 'order-received';
        $GLOBALS['wp'] = (object) array('query_vars' => array('order-received' => '906'));
    }), 906, 'thank-you page, cheque');
}

/** WC_Order::set_status() then save(), and the hooks that fire for it. */
function carts_transition(WC_Order $order, $to) {
    $from = $order->get_status();
    $order->set_status($to);
    agm_wc_persist($order);
    do_action('woocommerce_update_order', $order->get_id(), $order);
    do_action('woocommerce_order_status_' . $to, $order->get_id(), $order);
    do_action('woocommerce_order_status_changed', $order->get_id(), $from, $to, $order);
}

/**
 * Cash on delivery puts the order in processing with no paid date, then empties
 * the cart. It counts as a purchase, so the orders feature sends it (the
 * request's one write) and closes the cart once it is sent; the cart is not
 * closed a second time here. Without that feature's check, the cart closes
 * itself instead, as for any unpaid order. Either way AguMail hears it was bought.
 */
function test_carts_a_cash_on_delivery_order_in_processing_closes_its_cart_in_one_write() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $id = carts_session('agumail_cart_id');
    $order = null;
    $result = carts_request('classic checkout: cash on delivery', function () use ($products, &$order) {
        $data = array('billing_email' => 'ada@example.com', 'payment_method' => 'cod');
        agm_input(array('wc-ajax' => 'checkout'), $data);
        $order = agm_order(0, array('status' => 'pending', 'payment_method' => 'cod', 'billing_email' => 'ada@example.com'), array(array($products['mug'], 1)));
        do_action('woocommerce_checkout_create_order', $order, $data);
        agm_wc_persist($order);
        do_action('woocommerce_new_order', $order->get_id(), $order);
        do_action('woocommerce_checkout_order_processed', $order->get_id(), $data, $order);
        carts_transition($order, 'processing');
        WC()->cart->empty_cart();
        do_action('woocommerce_cart_emptied', true);
    });
    carts_expect_quiet($result);
    expect_same(1, count($result['writes']), 'a cash on delivery checkout should make exactly one write');
    expect_same($id, $order->get_meta('_agumail_cart_id'), 'the order does not carry its cart');
    $write = array($result['writes'][0]['kind'], $result['writes'][0]['name']);
    if (function_exists('agumail_order_counts_as_paid')) {
        expect_same(array('outbox', 'order:' . $order->get_id()), $write, 'the one write should be the order, which closes its cart once sent');
        expect_same('open', carts_row($id)['status'], 'the cart was closed besides queueing the order');
    } else {
        expect_same(array('carts', $id), $write, 'without the orders feature counting it paid, the cart should close itself');
    }

    carts_drain();
    carts_drain();
    expect_same(array('ordered', $order->get_id()), array(carts_row($id)['status'], carts_row($id)['order_id']), 'the cart was not closed by its order');
    $sent = agm_http_requests('/api/v1/carts');
    $last = end($sent)['body']['carts'];
    expect_same(array($id, 'completed'), array($last[0]['externalId'], $last[0]['status']), 'AguMail was not told the cart was bought');
}

function test_carts_the_cart_id_for_the_current_session_falls_back_to_its_open_row() {
    $products = carts_catalogue();
    expect_same('', agumail_cart_id_for_current_session(), 'a session with no cart has a cart id');
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $id = carts_session('agumail_cart_id');
    expect_same($id, agumail_cart_id_for_current_session(), 'the session key was not used');

    // The checkout loaded the session before the capture saved its key.
    WC()->session->set('agumail_cart_id', null);
    expect_same($id, agumail_cart_id_for_current_session(), "the open row for this session was not found, so the order would carry no cart");
    agumail_cart_update($id, array('status' => 'ordered'), false);
    expect_same('', agumail_cart_id_for_current_session(), 'an ordered cart was offered to a new order');
    agumail_cart_save('wc-elsewhere', array('token' => str_repeat('a', 32), 'email' => 'bea@example.com', 'session_key' => 't_other'));
    expect_same('', agumail_cart_id_for_current_session(), "another session's cart was offered");
}

function test_carts_go_before_orders_so_a_purchase_closes_them() {
    $order_phase = agumail_builder('order') ? agumail_builder('order')['phase'] : 30;
    expect_that(agumail_builder('cart')['phase'] < $order_phase, 'carts are sent after orders, so a cart that missed its order stays open after the purchase');
    agumail_register_builder('agm_carts_order', array('endpoint' => '/api/v1/orders', 'collection' => 'orders', 'phase' => $order_phase,
        'build'    => function ($key) { return array('externalId' => (string) $key, 'email' => 'ada@example.com', 'total' => 30); },
        'contacts' => function () { return array(array('email' => 'ada@example.com', 'tags' => array('woocommerce'))); },
    ));
    agumail_enqueue('agm_carts_order', 900);
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    carts_drain();
    $paths = array_map(function ($r) { return $r['path']; }, agm_http_requests());
    $carts = array_search('/api/v1/carts', $paths, true);
    $orders = array_search('/api/v1/orders', $paths, true);
    expect_that($carts !== false && $orders !== false && $carts < $orders, 'the cart went after the order: ' . implode(', ', $paths));
}

function test_carts_emptying_the_cart_after_a_failed_payment_is_the_shoppers_own() {
    agm_order(902, array('status' => 'failed'));
    list($id, , $result) = carts_checkout_empties(function () {
        WC()->session->set('order_awaiting_payment', 902);
    });
    carts_expect_quiet($result);
    expect_same('emptied', carts_row($id)['status'], 'a cart the shopper emptied after a failed payment is still open');
}

function test_carts_an_ordered_cart_is_never_reopened() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    carts_capture('ada@example.com');
    $ordered = carts_session('agumail_cart_id');
    agumail_cart_update($ordered, array('status' => 'ordered', 'order_id' => 900), false);
    $version = carts_row($ordered)['version'];

    carts_add($products['candle']);
    $next = carts_session('agumail_cart_id');
    expect_that($next && $next !== $ordered, 'a new change reopened a cart that was already ordered');
    expect_same(array('ordered', $version), array(carts_row($ordered)['status'], carts_row($ordered)['version']), 'the ordered cart was changed');
    expect_same(array('ada@example.com', 'open'), array(carts_row($next)['email'], carts_row($next)['status']), 'the new cart is wrong');
}

function test_carts_store_managers_and_unconnected_stores_record_no_carts() {
    $products = carts_catalogue();
    agm_sign_in(1, 'owner@example.com');
    agm_grant('manage_woocommerce');
    carts_add($products['mug']);
    expect_same(array(), agm_store()->carts, "the store manager's own cart was recorded");
    agm_grant('manage_woocommerce', false);

    agm_sign_in(9, 'bea@example.com');
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    carts_add($products['candle']);
    expect_same(array(), agm_store()->carts, 'a cart was recorded before the store was connected');

    $GLOBALS['agm']['options']['agumail_api_key'] = 'agm_' . str_repeat('a', 40);
    unset($GLOBALS['agm']['options']['agumail_db_version']);
    carts_add($products['candle']);
    expect_same(array(), agm_store()->carts, 'a cart was recorded before the tables exist');
}

// ── Sending ──

function test_carts_drain_sends_the_cart_with_its_restore_link_and_a_contact_without_consent() {
    $products = carts_catalogue();
    WC()->cart->add_line($products['mug'], 2);
    carts_capture('Ada@Example.com', 'checkout', array('agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    $id = carts_session('agumail_cart_id');
    $row = carts_row($id);

    carts_drain();
    expect_same(array('/api/v1/contacts', '/api/v1/carts'), array_map(function ($r) { return $r['path']; }, agm_http_requests()), 'the contact should go first, then the cart');
    expect_same(
        array('email' => 'ada@example.com', 'tags' => array('woocommerce')),
        carts_sent('/api/v1/contacts')['contacts'][0],
        'a captured contact must carry no consent: a ticked box counts only on a submitted order'
    );
    $sent = carts_sent('/api/v1/carts');
    expect_that(!isset($sent['backfill']), 'a live cart was sent as history');
    $cart = $sent['carts'][0];
    expect_same('https://shop.test/checkout/?agumail_cart=' . $id . '&agumail_token=' . $row['token'], $cart['checkoutUrl'], 'the checkout link is not the restore link on the checkout page');
    expect_same(
        array('externalId' => $id, 'email' => 'ada@example.com', 'currency' => 'EUR', 'status' => 'open', 'source' => 'woocommerce'),
        carts_pick($cart, array('externalId', 'email', 'currency', 'status', 'source')),
        'the cart record is wrong'
    );
    expect_same(60.0, (float) $cart['total'], 'the total is wrong');
    expect_same(agumail_iso_date($row['updated_at']), $cart['lastActivityAt'], 'the last activity is not when the cart last changed');
    $item = $cart['items'][0];
    expect_same(
        array('title' => 'Mug', 'qty' => 2, 'productExternalId' => '11', 'url' => 'https://shop.test/?p=11', 'imageUrl' => 'https://shop.test/img/501.jpg', 'sku' => 'MUG-1'),
        carts_pick($item, array('title', 'qty', 'productExternalId', 'url', 'imageUrl', 'sku')),
        'the line is wrong'
    );
    expect_same(30.0, (float) $item['price'], 'the line price is wrong');
    expect_same(array(), agm_writes('user_meta'), 'capture recorded a marketing answer');
}

function test_carts_drain_resolves_lines_from_the_catalogue_when_it_sends() {
    $products = carts_catalogue();
    agm_product(14, str_repeat('x', 400), 5);
    WC()->cart->add_line($products['mug'], 1);
    WC()->cart->add_line($products['shirt_m'], 2, array('attribute_size' => 'M'));
    WC()->cart->add_line($products['candle'], 1);
    WC()->cart->add_line(wc_get_product(14), 1);
    carts_capture('ada@example.com');

    $products['mug']->props['price'] = '25';
    unset($GLOBALS['agm']['products'][12]);
    carts_drain();

    $items = carts_sent('/api/v1/carts')['carts'][0]['items'];
    expect_same(array('Mug', 'Shirt - M'), array_slice(array_column($items, 'title'), 0, 2), 'a line for a deleted product was sent, or a line was lost');
    expect_same(3, count($items), 'expected three lines');
    expect_same(30.0, (float) $items[0]['price'], 'the price is not the one the shopper saw in their cart');
    expect_same(
        array('productExternalId' => '20', 'sku' => 'SHIRT-M', 'url' => 'https://shop.test/?p=21', 'imageUrl' => 'https://shop.test/img/701.jpg', 'qty' => 2),
        carts_pick($items[1], array('productExternalId', 'sku', 'url', 'imageUrl', 'qty')),
        'a variation should name its parent product, with its own sku, link and image'
    );
    expect_same(27.0, (float) $items[1]['price'], "the variation's price is wrong");
    expect_same(AGUMAIL_MAX_TITLE, strlen($items[2]['title']), 'a long title was not clamped');
    $products['mug']->props['name'] = 'Tom &amp; Jerry&#8217;s mug';
    agumail_cart_update(carts_session('agumail_cart_id'), array('status' => 'open'));
    carts_drain();
    expect_same("Tom & Jerry\u{2019}s mug", carts_sent('/api/v1/carts')['carts'][0]['items'][0]['title'], 'the title still holds HTML entities');
    expect_that(!isset($items[2]['imageUrl']) && !isset($items[2]['sku']), 'a product with no image or sku sent empty ones');
}

function test_carts_prices_come_from_the_cart_in_its_own_currency() {
    carts_catalogue();
    agumail_cart_save('wc-usd', array('token' => str_repeat('a', 32), 'email' => 'ada@example.com', 'currency' => 'USD', 'total' => 62,
        'lines' => array(
            array('product_id' => 11, 'variation_id' => 0, 'variation' => array(), 'qty' => 2, 'line_total' => 50),
            array('product_id' => 12, 'variation_id' => 0, 'variation' => array(), 'qty' => 1),
        )));
    carts_drain();
    $cart = carts_sent('/api/v1/carts')['carts'][0];
    expect_same('USD', $cart['currency'], "the cart's own currency was not sent");
    expect_same(array(25.0, 12.0), array((float) $cart['items'][0]['price'], (float) $cart['items'][1]['price']),
        'a line price should be its line total over its quantity, in the cart currency, and the catalogue price only without one');
}

function test_carts_drain_sends_at_most_one_hundred_lines() {
    carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    for ($i = 1; $i <= 105; $i++) {
        WC()->cart->add_line(agm_product(1000 + $i, "Item $i", 1), 1);
    }
    carts_add(wc_get_product(1001));
    carts_drain();
    expect_same(100, count(carts_sent('/api/v1/carts')['carts'][0]['items']), 'more lines than AguMail takes were sent');
}

function test_carts_pending_carts_are_flushed_to_agumail() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    carts_add($products['candle']);
    $id = carts_session('agumail_cart_id');
    carts_drain();
    $sent = agm_http_requests('/api/v1/carts');
    expect_that(count($sent) === 1 && count($sent[0]['body']['carts']) === 1, 'the changed cart was not sent');
    expect_same(0, carts_row($id)['dirty'], 'the cart is still waiting to be sent');
    expect_same(array(), agm_outbox('cart'), 'the cart stayed in the outbox after a successful send');
    carts_drain();
    expect_same(1, count(agm_http_requests('/api/v1/carts')), 'an unchanged cart was sent again');
}

function test_carts_an_ordered_cart_is_sent_as_completed() {
    $products = carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    carts_add($products['mug']);
    $id = carts_session('agumail_cart_id');
    agumail_cart_update($id, array('status' => 'ordered', 'order_id' => 900));
    carts_drain();
    $cart = carts_sent('/api/v1/carts')['carts'][0];
    expect_same(array($id, 'completed', 1), array($cart['externalId'], $cart['status'], count($cart['items'])), 'an ordered cart should close as completed');
    expect_same(array(), agm_http_requests('/api/v1/contacts'), 'an ordered cart sent its contact again');
}

function test_carts_a_cart_without_a_row_or_email_is_dropped_unsent() {
    agumail_enqueue('cart', 'wc-gone');
    agumail_cart_save('wc-nobody', array('token' => str_repeat('a', 32), 'lines' => array()));
    carts_drain();
    expect_same(array(), agm_http_requests('/api/v1/carts'), 'a cart nobody can recover was sent');
    expect_same(array(), agm_outbox('cart'), 'the dropped carts stayed queued');
}

// ── The restore link ──

define('CARTS_SAVED_ID', 'wc-11111111-1111-4111-8111-111111111111');
define('CARTS_TOKEN', 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1');

/** A cart recorded in an earlier visit, maybe on another device: a mug x2 and a shirt in M. */
function carts_saved_cart(array $fields = array(), $id = CARTS_SAVED_ID) {
    carts_catalogue();
    agumail_cart_save($id, array_merge(array(
        'token'       => CARTS_TOKEN,
        'email'       => 'ada@example.com',
        'session_key' => 't_elsewhere',
        'currency'    => 'EUR',
        'total'       => 87,
        'status'      => 'open',
        'lines'       => array(
            array('product_id' => 11, 'variation_id' => 0, 'variation' => array(), 'qty' => 2, 'line_total' => 60),
            array('product_id' => 20, 'variation_id' => 21, 'variation' => array('attribute_size' => 'M'), 'qty' => 1, 'line_total' => 27),
        ),
    ), $fields));
    $GLOBALS['agm']['writes'] = array();
    return $id;
}

/** Opens a link with this query, on the checkout page unless said otherwise, as a storefront page load. */
function carts_open_link(array $query, $kind = 'shopper', $path = '/checkout/') {
    return carts_request('restore link', function () use ($query, $path) {
        agm_input($query);
        $_SERVER['REQUEST_URI'] = $path . '?' . http_build_query($query);
        do_action('plugins_loaded');
        do_action('init');
        do_action('wp_loaded');
        do_action('template_redirect');
    }, $kind);
}

function carts_redirected_to() {
    $redirect = end($GLOBALS['agm']['redirects']);
    return $redirect ? $redirect['location'] : null;
}

function test_carts_restore_rebuilds_the_cart_and_redirects_to_checkout_keeping_other_params() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart();
    $products = carts_catalogue();
    WC()->cart->add_line($products['candle'], 1);

    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN,
        'agm_cid' => 'cmp_1', 'agm_uid' => 'u_9', 'utm_source' => 'agumail', 'utm_medium' => 'email', 'utm_campaign' => 'cart reminder'));
    carts_expect_quiet($result, 0);
    expect_same('https://shop.test/checkout/?agm_cid=cmp_1&agm_uid=u_9&utm_source=agumail&utm_medium=email&utm_campaign=cart%20reminder',
        carts_redirected_to(), 'the restore did not go to checkout with the other params');
    expect_same(302, end($GLOBALS['agm']['redirects'])['status'], 'the restore redirect is not a 302');
    expect_same(array(array(11, 0, 2), array(20, 21, 1)), carts_contents(), 'the cart was not rebuilt from the saved one');
    $items = array_values(WC()->cart->get_cart());
    expect_same(array('attribute_size' => 'M'), $items[1]['variation'], 'the variation was lost');
    expect_same(array($id, 'ada@example.com'), array(carts_session('agumail_cart_id'), carts_session('agumail_email')), 'the session does not carry the restored cart');
    expect_same('ada@example.com', WC()->customer->get_billing_email(), 'checkout does not know the email');
    expect_same(array('open', 1), array(carts_row($id)['status'], carts_row($id)['version']), 'restoring changed the saved cart');

    carts_add($products['candle']);
    expect_same(array($id, 3), array(carts_session('agumail_cart_id'), count(carts_row($id)['lines'])), 'a change after restoring did not continue the same cart');
}

function test_carts_restore_with_a_wrong_or_missing_token_only_drops_the_params() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart();
    $products = carts_catalogue();
    WC()->cart->add_line($products['candle'], 1);
    $links = array(
        'wrong token'   => array('agumail_cart' => $id, 'agumail_token' => str_repeat('c', 32), 'agm_cid' => 'cmp_1'),
        'no token'      => array('agumail_cart' => $id, 'agm_cid' => 'cmp_1'),
        'empty token'   => array('agumail_cart' => $id, 'agumail_token' => '', 'agm_cid' => 'cmp_1'),
        'unknown cart'  => array('agumail_cart' => 'wc-nope', 'agumail_token' => CARTS_TOKEN, 'agm_cid' => 'cmp_1'),
        'token as list' => array('agumail_cart' => $id, 'agumail_token' => array(CARTS_TOKEN), 'agm_cid' => 'cmp_1'),
    );
    foreach ($links as $label => $query) {
        $result = carts_open_link($query);
        carts_expect_quiet($result, 0);
        expect_same('/checkout/?agm_cid=cmp_1', carts_redirected_to(), "$label: the params were not simply dropped");
        expect_same(array(array(12, 0, 1)), carts_contents(), "$label: the cart changed");
        expect_same(array(null, null), array(carts_session('agumail_cart_id'), carts_session('agumail_email')), "$label: the session changed");
    }
}

function test_carts_restore_of_an_ordered_cart_goes_to_the_cart_page() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart(array('status' => 'ordered', 'order_id' => 900));
    $products = carts_catalogue();
    WC()->cart->add_line($products['candle'], 1);
    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN, 'agm_cid' => 'cmp_1'));
    carts_expect_quiet($result, 0);
    expect_same('https://shop.test/cart/?agm_cid=cmp_1', carts_redirected_to(), 'an ordered cart did not go to the cart page');
    expect_same(array(array(12, 0, 1)), carts_contents(), 'an ordered cart was put back in the cart');
    expect_same(null, carts_session('agumail_cart_id'), 'an ordered cart was taken up again');
}

function test_carts_restore_works_after_the_session_expired() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart();
    WC()->session = agm_carts_fresh_session();
    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN));
    carts_expect_quiet($result, 0);
    expect_same(true, WC()->session->started, 'no session was started, so the rebuilt cart is lost on the next page');
    expect_same(array(array(11, 0, 2), array(20, 21, 1)), carts_contents(), 'the cart was not rebuilt in a fresh browser');
    expect_same($id, carts_session('agumail_cart_id'), 'the fresh session does not carry the cart');
    expect_same('https://shop.test/checkout/', carts_redirected_to(), 'a fresh browser was not sent to checkout');
}

function test_carts_restore_skips_products_no_longer_for_sale() {
    WC()->cart = agm_carts_hooked_cart();
    carts_catalogue();
    agm_carts_product(13, 'Lamp', 40, array('purchasable' => false));
    agm_carts_product(14, 'Vase', 18, array('stock_status' => 'outofstock'));
    agm_carts_product(15, 'Draft', 9, array('status' => 'draft'));
    $line = function ($product_id, $qty = 1) {
        return array('product_id' => $product_id, 'variation_id' => 0, 'variation' => array(), 'qty' => $qty, 'line_total' => 1);
    };
    $id = carts_saved_cart(array('lines' => array($line(13), $line(11, 2), $line(14), $line(15), $line(99))));
    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN));
    carts_expect_quiet($result, 0);
    expect_same(array(array(11, 0, 2)), carts_contents(), 'only the mug is still for sale');
    expect_same('https://shop.test/checkout/', carts_redirected_to(), 'the partly restored cart did not go to checkout');

    WC()->cart->cart_contents = array();
    $none = carts_saved_cart(array('lines' => array($line(13), $line(99))), 'wc-22222222-2222-4222-8222-222222222222');
    carts_open_link(array('agumail_cart' => $none, 'agumail_token' => CARTS_TOKEN, 'utm_source' => 'agumail'));
    expect_same(array(), carts_contents(), 'something not for sale was put in the cart');
    expect_same('https://shop.test/cart/?utm_source=agumail', carts_redirected_to(), 'a cart with nothing left to buy should show the cart page, not an empty checkout');
}

function test_carts_restore_records_none_of_its_own_changes_and_retires_the_cart_it_replaced() {
    $products = carts_catalogue();
    WC()->cart = agm_carts_hooked_cart();
    agm_sign_in(9, 'bea@example.com');
    carts_request('an earlier cart in this browser', function () {
        WC()->cart->add_to_cart(12, 1);
    });
    $previous = carts_session('agumail_cart_id');
    expect_that($previous, 'the earlier cart was not recorded');
    $id = carts_saved_cart();

    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN));
    carts_expect_quiet($result);
    expect_same(array(array('carts', $previous)), array_map(function ($w) { return array($w['kind'], $w['name']); }, $result['writes']), 'only the replaced cart should be written');
    expect_same('emptied', carts_row($previous)['status'], 'the cart the restore replaced is still open');
    expect_same(array('open', 1), array(carts_row($id)['status'], carts_row($id)['version']), 'the restore recorded its own emptying and re-adding');
    expect_same(array(array(11, 0, 2), array(20, 21, 1)), carts_contents(), 'the cart was not rebuilt');
    expect_same($id, carts_session('agumail_cart_id'), 'the session does not carry the restored cart');
}

function test_carts_restore_asks_for_no_more_than_can_be_bought() {
    WC()->cart = agm_carts_hooked_cart();
    carts_catalogue();
    agm_carts_product(16, 'Print', 20, array('max_purchase_quantity' => 1));
    agm_carts_product(17, 'Kettle', 45, array('manage_stock' => true, 'stock_quantity' => 2));
    agm_carts_product(18, 'Teapot', 35, array('manage_stock' => true, 'stock_quantity' => 0));
    $line = function ($product_id, $qty) {
        return array('product_id' => $product_id, 'variation_id' => 0, 'variation' => array(), 'qty' => $qty, 'line_total' => 1);
    };
    $id = carts_saved_cart(array('lines' => array($line(16, 3), $line(17, 5), $line(18, 1), $line(11, 2))));
    carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN));
    expect_same(array(array(16, 0, 1), array(17, 0, 2), array(11, 0, 2)), carts_contents(), 'quantities were not held to what can be bought');
    expect_same('https://shop.test/checkout/', carts_redirected_to(), 'the restore did not go to checkout');
}

function test_carts_a_restore_that_adds_nothing_keeps_the_shoppers_own_cart() {
    WC()->cart = agm_carts_hooked_cart();
    carts_catalogue();
    agm_sign_in(9, 'bea@example.com');
    carts_request('the cart in this browser', function () {
        WC()->cart->add_to_cart(12, 1);
        WC()->cart->set_applied_coupons(array('save10'));
    });
    $previous = carts_session('agumail_cart_id');
    $id = carts_saved_cart();
    $GLOBALS['agm']['add_to_cart_fails'] = true;

    $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN, 'utm_source' => 'agumail'));
    carts_expect_quiet($result, 0);
    expect_same('https://shop.test/cart/?utm_source=agumail', carts_redirected_to(), 'a restore that added nothing should show the cart page');
    expect_same(array(array(12, 0, 1)), carts_contents(), "the shopper's own cart was wiped");
    expect_same(array('save10'), WC()->cart->get_applied_coupons(), "the shopper's coupon was lost");
    expect_same($previous, carts_session('agumail_cart_id'), "the shopper's own cart lost its id");
    expect_same('open', carts_row($previous)['status'], "the shopper's own cart was retired");
    expect_same(1, carts_row($id)['version'], 'the saved cart was changed');
}

function test_carts_item_data_is_kept_and_restored_and_bundled_children_are_left_to_their_parent() {
    WC()->cart = agm_carts_hooked_cart();
    carts_catalogue();
    agm_carts_product(30, 'Gift box', 50);
    agm_sign_in(9, 'bea@example.com');
    $stamp = array('7' => array('product_id' => 12, 'quantity' => 2));
    $addons = array(array('name' => 'Card message', 'value' => 'Happy birthday'));
    carts_request('add a bundle', function () use ($stamp, $addons) {
        $parent = WC()->cart->add_to_cart(30, 1, 0, array(), array(
            'stamp' => $stamp, 'addons' => $addons, 'unique_key' => 'u1',
            'bundled_items' => array('child-key'), 'hook' => new stdClass(), 'mixed' => array('ok' => 1, 'bad' => new stdClass()),
        ));
        WC()->cart->add_to_cart(12, 2, 0, array(), array('bundled_by' => $parent, 'bundled_item_id' => 7, 'stamp' => $stamp));
    });
    $row = carts_row();
    expect_same(array('stamp' => $stamp, 'addons' => $addons, 'unique_key' => 'u1'), $row['lines'][0]['item_data'],
        'only plain data a plugin added should be kept, without links to other lines');
    expect_that(empty($row['lines'][0]['child']), 'the bundle was taken for a child');
    expect_same(true, isset($row['lines'][1]['child']) ? $row['lines'][1]['child'] : null, 'the bundled item was not marked as a child');

    WC()->cart = agm_carts_hooked_cart();
    carts_open_link(array('agumail_cart' => $row['id'], 'agumail_token' => $row['token']));
    expect_same(array(array(30, 0, 1)), carts_contents(), 'a bundled child was added besides its bundle, which adds it itself');
    $items = array_values(WC()->cart->get_cart());
    expect_same(array($stamp, $addons, 'u1'), array($items[0]['stamp'], $items[0]['addons'], $items[0]['unique_key']), 'the item data was not restored');
}

function test_carts_restore_falls_back_to_the_cart_page_when_checkout_is_on_another_host() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart();
    add_filter('allowed_redirect_hosts', '__return_empty_array');
    carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN));
    expect_same('https://shop.test/cart/', carts_redirected_to(), 'the redirect fell back to wp-admin, not the cart page');
}

function test_carts_restore_only_runs_on_storefront_pages() {
    WC()->cart = agm_carts_hooked_cart();
    $id = carts_saved_cart();
    foreach (array('admin', 'shopper_ajax', 'cron', 'shopper_rest') as $kind) {
        $result = carts_open_link(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN), $kind);
        expect_same(null, $result['halt'], "a $kind request acted on the restore link");
        expect_same(array(), carts_contents(), "a $kind request restored the cart");
    }
    $rest = array(
        'pretty REST route' => array('/wp-json/wc/store/v1/cart', array()),
        'plain REST route'  => array('/', array('rest_route' => '/wc/store/v1/cart')),
    );
    foreach ($rest as $label => $request) {
        $result = carts_open_link(array_merge(array('agumail_cart' => $id, 'agumail_token' => CARTS_TOKEN), $request[1]), 'shopper', $request[0]);
        expect_same(null, $result['halt'], "a $label acted on the restore link");
        expect_same(array(), carts_contents(), "a $label restored the cart");
    }
}

// ── Housekeeping ──

function test_carts_housekeeping_drops_finished_carts_after_14_days_and_every_cart_after_90() {
    $now = 1790000000;
    $clock = $now;
    agumail_set_clock(function () use (&$clock) { return $clock; });
    $carts = array(
        'emptied-15' => array('emptied', 15), 'ordered-15' => array('ordered', 15), 'emptied-13' => array('emptied', 13),
        'ordered-13' => array('ordered', 13), 'open-15' => array('open', 15), 'open-89' => array('open', 89),
        'open-91' => array('open', 91), 'emptied-91' => array('emptied', 91),
    );
    foreach ($carts as $id => $cart) {
        $clock = $now - $cart[1] * DAY_IN_SECONDS;
        agumail_cart_save($id, array('token' => str_repeat('a', 32), 'email' => 'a@example.com', 'status' => $cart[0]));
    }
    $clock = $now;

    $shopper = agm_request('storefront page', 'shopper', function () { agumail_carts_housekeeping(); });
    expect_same(array(), $shopper['writes'], 'a storefront request purged carts');
    expect_same(8, count(agm_store()->carts), 'a storefront request purged carts');

    expect_that(has_action('agumail_housekeeping', 'agumail_carts_housekeeping') !== false, 'cart housekeeping is not part of the daily housekeeping job');
    agm_request('housekeeping', 'cron', function () { agumail_carts_housekeeping(); });
    $left = array_keys(agm_store()->carts);
    sort($left);
    expect_same(array('emptied-13', 'open-15', 'open-89', 'ordered-13'), $left, 'the wrong carts were purged');
}

// ── The capture script ──

function carts_page_has_script($page) {
    $GLOBALS['agm']['scripts'] = array();
    $GLOBALS['agm']['page'] = $page;
    agm_request('page ' . $page, 'shopper', function () {
        do_action('wp_enqueue_scripts');
    });
    return wp_script_is('agumail-capture');
}

function test_carts_capture_script_loads_deferred_where_an_email_is_typed($plugin_dir) {
    expect_that(is_file($plugin_dir . '/assets/js/capture.js'), 'assets/js/capture.js is missing');
    $expected = array('checkout' => true, 'cart' => true, 'account' => true, 'product' => false, 'shop' => false, '' => false, 'order-received' => false);
    foreach ($expected as $page => $loads) {
        expect_same($loads, carts_page_has_script($page), "capture.js on the \"$page\" page");
    }

    carts_page_has_script('checkout');
    $script = $GLOBALS['agm']['scripts']['agumail-capture'];
    expect_same('https://shop.test/wp-content/plugins/agumail/assets/js/capture.js', $script['src'], 'the script is not the plugin\'s capture.js');
    expect_same('defer', isset($script['data']['strategy']) ? $script['data']['strategy'] : null, 'the script is not deferred');
    $inline = implode("\n", $script['inline']);
    expect_that(strpos($inline, 'window.agumailCapture') !== false && strpos($inline, 'wc-ajax=agumail_capture') !== false, 'the script is not told where to post: ' . $inline);

    agm_sign_in(9, 'bea@example.com');
    expect_same(false, carts_page_has_script('account'), 'a signed-in customer on My Account has no login form to capture');
    expect_same(true, carts_page_has_script('checkout'), 'a signed-in customer may still type another email at checkout');
    agm_grant('manage_woocommerce');
    expect_same(false, carts_page_has_script('checkout'), "the store manager's own checkout loaded the script");
    agm_grant('manage_woocommerce', false);
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    expect_same(false, carts_page_has_script('checkout'), 'an unconnected store loaded the script');
}

function test_carts_capture_script_behaves_in_the_browser($plugin_dir) {
    $script = $plugin_dir . '/assets/js/capture.js';
    expect_that(is_file($script), 'assets/js/capture.js is missing');
    $source = (string) file_get_contents($script);
    expect_that(stripos($source, 'agumail_optin') === false && stripos($source, '.checked') === false, 'the script reads the marketing checkbox; only a submitted order may');

    exec('command -v node 2>/dev/null', $found, $missing);
    if ($missing || !$found) {
        // No Node here: the static checks above are all this machine can do.
        return;
    }
    $base = tempnam(sys_get_temp_dir(), 'agm-capture-');
    $harness = $base . '.js';
    file_put_contents($harness, carts_capture_script_harness());
    $acorn = dirname(dirname($plugin_dir)) . '/app/node_modules/acorn';
    exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($acorn) . ' 2>&1', $output, $code);
    @unlink($harness);
    @unlink($base);
    expect_same(0, $code, "capture.js failed in the browser harness:\n        " . implode("\n        ", $output));
    expect_same('capture-ok', end($output), 'the browser harness did not finish: ' . implode("\n", $output));
}

/** A browser for capture.js in plain Node: a document of listeners, fetch and sendBeacon that record, and window.agumail. */
function carts_capture_script_harness() {
    return <<<'JS'
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const [file, acornPath] = process.argv.slice(2);
const source = fs.readFileSync(file, 'utf8');

try {
  require(acornPath).parse(source, { ecmaVersion: 5 });
} catch (error) {
  if (error.code !== 'MODULE_NOT_FOUND') throw new Error('capture.js is not ES5: ' + error.message);
}

function browser(options) {
  const listeners = {};
  const posts = [];
  const beacons = [];
  const identified = [];
  const document = {
    readyState: 'complete',
    visibilityState: 'visible',
    activeElement: null,
    addEventListener(type, fn, capture) { (listeners[type] = listeners[type] || []).push({ fn, capture }); }
  };
  const window = {
    agumailCapture: { endpoint: '/?wc-ajax=agumail_capture' },
    document,
    navigator: {
      sendBeacon(url, body) { beacons.push({ url, body }); return true; }
    },
    Blob,
    URLSearchParams,
    addEventListener() {},
    agumail: { identify(email, sig) { identified.push([email, sig]); } }
  };
  if (!options.noFetch) {
    window.fetch = function (url, init) {
      posts.push({ url, init });
      // The server signs the address as it sanitised it, which is not always what was typed.
      const signed = new URLSearchParams(init.body).get('email').replace(/[()]/g, '');
      const answer = options.unsigned ? { ok: true } : (options.noEmail ? { ok: true, sig: 'sig:' + signed } : { ok: true, sig: 'sig:' + signed, email: signed });
      return Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
    };
  }
  vm.runInNewContext(source, { window, document, navigator: window.navigator });
  function node(props, parent) {
    return Object.assign({ nodeType: 1, tagName: 'DIV', id: '', className: '', parentNode: parent || null }, props);
  }
  function input(id, type, value, parent) {
    return node({ tagName: 'INPUT', id, type, value }, parent || node({ tagName: 'FORM' }, node({ tagName: 'BODY' })));
  }
  function fire(type, target) {
    for (const entry of listeners[type] || []) entry.fn({ type, target });
  }
  return { listeners, posts, beacons, identified, node, input, fire, document };
}

function body(post) {
  return Object.fromEntries(new URLSearchParams(post.init.body));
}

(async () => {
  const b = browser({});
  assert.ok(b.listeners.focusout && b.listeners.focusout[0].capture, 'focusout is not listened to on the document in the capture phase');
  assert.ok(b.listeners.change && b.listeners.change[0].capture, 'change is not listened to on the document in the capture phase');

  // Classic checkout and CheckoutWC: #billing_email.
  b.fire('focusout', b.input('billing_email', 'email', '  Ada@Example.com '));
  assert.strictEqual(b.posts.length, 1, 'leaving #billing_email did not post');
  assert.deepStrictEqual(body(b.posts[0]), { email: 'ada@example.com', context: 'checkout' });
  assert.strictEqual(b.posts[0].url, '/?wc-ajax=agumail_capture');
  assert.strictEqual(b.posts[0].init.method, 'POST');
  assert.strictEqual(b.posts[0].init.keepalive, true, 'the post does not survive the page moving on');
  assert.strictEqual(b.posts[0].init.credentials, 'same-origin');
  assert.ok(/application\/x-www-form-urlencoded/.test(b.posts[0].init.headers['Content-Type']), 'not a form post, so PHP would not read it');

  // The same email again, from a change event: nothing new.
  b.fire('change', b.input('billing_email', 'email', 'ada@example.com'));
  assert.strictEqual(b.posts.length, 1, 'the same email was posted twice');

  // Not an email yet.
  for (const value of ['', 'ada', 'ada@', 'ada@example', '@example.com', 'a da@example.com']) {
    b.fire('focusout', b.input('billing_email', 'email', value));
  }
  assert.strictEqual(b.posts.length, 1, 'something that is not an email was posted');

  // Block checkout: #email inside the contact step.
  const blockCheckout = b.node({ className: 'wp-block-woocommerce-checkout wc-block-checkout is-large' });
  const contact = b.node({ id: 'contact-fields', className: 'wc-block-checkout__contact-fields' }, blockCheckout);
  b.fire('focusout', b.input('email', 'email', 'bea@example.com', b.node({ className: 'wc-block-components-address-form' }, contact)));
  assert.deepStrictEqual(body(b.posts[1]), { email: 'bea@example.com', context: 'checkout' });
  // Another email input in the contact step (a gift recipient, say) is not the shopper's.
  b.fire('change', b.input('gift-recipient-email', 'email', 'cy@example.com', contact));
  assert.strictEqual(b.posts.length, 2, 'an email input other than #email in #contact-fields was posted');
  b.fire('focusout', b.input('email', 'email', 'cy@example.com', b.node({ className: 'wc-block-components-address-form' }, contact)));
  assert.deepStrictEqual(body(b.posts[2]), { email: 'cy@example.com', context: 'checkout' });

  // My Account: #username (login) and #reg_email (register).
  b.fire('focusout', b.input('username', 'text', 'dee'));
  assert.strictEqual(b.posts.length, 3, 'a plain username was posted');
  b.fire('focusout', b.input('username', 'text', 'dee@example.com'));
  assert.deepStrictEqual(body(b.posts[3]), { email: 'dee@example.com', context: 'login' });
  b.fire('focusout', b.input('reg_email', 'email', 'eve@example.com'));
  assert.deepStrictEqual(body(b.posts[4]), { email: 'eve@example.com', context: 'register' });

  // Anything else is left alone: other fields, a newsletter box outside checkout, the checkbox.
  b.fire('focusout', b.input('billing_first_name', 'text', 'fay@example.com'));
  b.fire('focusout', b.input('newsletter-email', 'email', 'gus@example.com'));
  b.fire('focusout', b.input('email', 'email', 'hal@example.com'));
  b.fire('change', b.input('agumail_optin', 'checkbox', '1', contact));
  b.fire('focusout', b.node({ tagName: 'TEXTAREA', id: 'billing_email', value: 'ivy@example.com' }));
  b.fire('focusout', null);
  assert.strictEqual(b.posts.length, 5, 'a field that is not a capture field was posted');

  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.deepStrictEqual(b.identified[0], ['ada@example.com', 'sig:ada@example.com'], 'the signed answer was not handed to the site script');
  assert.strictEqual(b.identified.length, 5);

  // Leaving the page while still in the field: the unsent email goes as a beacon.
  const leaving = browser({});
  assert.ok(leaving.listeners.visibilitychange, 'visibilitychange is not listened to');
  const typing = leaving.input('billing_email', 'email', 'jo@example.com');
  leaving.document.activeElement = typing;
  leaving.fire('visibilitychange', leaving.document);
  assert.strictEqual(leaving.beacons.length + leaving.posts.length, 0, 'a visible page sent on visibilitychange');
  leaving.document.visibilityState = 'hidden';
  leaving.fire('visibilitychange', leaving.document);
  assert.strictEqual(leaving.beacons.length, 1, 'the unsent email was not sent when the page was hidden');
  assert.strictEqual(leaving.posts.length, 0, 'a hidden page should send a beacon, not a fetch');
  const beaconBody = leaving.beacons[0].body;
  assert.strictEqual(beaconBody instanceof Blob ? await beaconBody.text() : String(beaconBody), 'email=jo%40example.com&context=checkout');
  leaving.fire('visibilitychange', leaving.document);
  leaving.fire('focusout', typing);
  assert.strictEqual(leaving.beacons.length + leaving.posts.length, 1, 'the email went twice');
  leaving.document.activeElement = leaving.input('billing_first_name', 'text', 'kim@example.com');
  leaving.fire('visibilitychange', leaving.document);
  leaving.document.activeElement = leaving.input('billing_email', 'email', 'kim@');
  leaving.fire('visibilitychange', leaving.document);
  leaving.document.activeElement = null;
  leaving.fire('visibilitychange', leaving.document);
  assert.strictEqual(leaving.beacons.length, 1, 'a field that is not a capture field, or not an email yet, was sent');

  // Identify uses the address the server signed, not the typed one.
  const sanitised = browser({});
  sanitised.fire('focusout', sanitised.input('billing_email', 'email', 'ad(a)@example.com'));
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.deepStrictEqual(sanitised.identified, [['ada@example.com', 'sig:ada@example.com']], 'identify did not use the address the signature is for');

  // A signature without the address it is for: no identify.
  const nameless = browser({ noEmail: true });
  nameless.fire('focusout', nameless.input('billing_email', 'email', 'ada@example.com'));
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.strictEqual(nameless.identified.length, 0, 'identify was called without the signed address');

  // No signature, no identify.
  const unsigned = browser({ unsigned: true });
  unsigned.fire('focusout', unsigned.input('billing_email', 'email', 'ada@example.com'));
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.strictEqual(unsigned.identified.length, 0, 'identify was called without a signature');

  // A browser without fetch: sendBeacon, as a form body.
  const old = browser({ noFetch: true });
  old.fire('focusout', old.input('billing_email', 'email', 'ada@example.com'));
  assert.strictEqual(old.beacons.length, 1, 'no beacon without fetch');
  assert.strictEqual(old.beacons[0].url, '/?wc-ajax=agumail_capture');
  const sent = old.beacons[0].body;
  const text = typeof sent === 'string' ? sent : (sent instanceof Blob ? await sent.text() : String(sent));
  assert.strictEqual(text, 'email=ada%40example.com&context=checkout');
  if (sent instanceof Blob) assert.ok(/application\/x-www-form-urlencoded/.test(sent.type), 'the beacon is not a form post');

  // No endpoint: the script stays out of the way.
  const listeners = {};
  vm.runInNewContext(source, { window: { document: {} }, document: { addEventListener(type) { listeners[type] = true; } } });
  assert.deepStrictEqual(listeners, {}, 'the script listened without an endpoint');

  console.log('capture-ok');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(1);
});
JS;
}
