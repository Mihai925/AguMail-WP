<?php
// Checkout is never slowed. Every storefront request a shopper can make is
// played here with the hooks WordPress and WooCommerce fire for it. None may
// call AguMail, not even to be refused by the guard; none may write options,
// transients or scheduled actions, or save an order or product of its own; and
// each may make at most one write to the plugin's tables.
//
// Hooks nobody listens to yet are fired anyway, so a lane that adds a listener
// is held to the rule the moment it does.

// The site script loads only from AguMail's own hosts or one wp-config.php
// pins. This store pins the test API, as a development site would, so the
// addresses below count as AguMail's.
define('AGUMAIL_API_URL', 'https://api.agumail.test');

// ── What a quiet request looks like ──

function shopper_path_describe(array $writes) {
    return implode(', ', array_map(function ($write) {
        return $write['kind'] . ':' . $write['name'] . ($write['detail'] ? ' (' . $write['detail'] . ')' : '');
    }, $writes));
}

/**
 * What a shopper's request may write, all told: at most ONE of these, and
 * nothing else. The plugin's tables (a queued change, or the shopper's cart
 * row) and one user meta key, the account's marketing answer. Order meta is
 * set on the order before WooCommerce saves it, which is not a write of ours.
 */
function shopper_path_allowed_write(array $write) {
    return $write['kind'] === 'outbox'
        || $write['kind'] === 'carts'
        || ($write['kind'] === 'user_meta' && $write['name'] === '_agumail_optin');
}

function shopper_path_expect_quiet(array $result) {
    $name = $result['name'];
    $paths = array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $result['http']);
    expect_that(!$paths, "\"$name\" called AguMail from the shopper's request: " . implode(', ', $paths));

    $refused = array_filter($result['logs'], function ($entry) { return stripos($entry['message'], 'refused') !== false; });
    expect_that(!$refused, "\"$name\" tried to call AguMail; the guard refused it, but the attempt should never be made");

    $allowed = array_values(array_filter($result['writes'], 'shopper_path_allowed_write'));
    expect_that(count($allowed) <= 1, "\"$name\" made " . count($allowed) . ' writes (' . shopper_path_describe($allowed) . '); at most one is allowed');

    $forbidden = array_values(array_filter($result['writes'], function ($write) { return !shopper_path_allowed_write($write); }));
    expect_that(!$forbidden, "\"$name\" wrote " . shopper_path_describe($forbidden) . ' from the shopper\'s request');
}

// ── The store and its shopper ──

/**
 * The catalogue, in P1's products that report their changes while saving
 * (tests/fakes/orders_products.php): a mug with one left in stock, a candle
 * nobody counts, and a blue variation of a variable mug with five.
 */
function shopper_path_catalogue() {
    return array(
        'mug'    => agm_catalogue_product(11, array('name' => 'Mug', 'price' => '30', 'regular_price' => '30', 'sku' => 'MUG-1', 'manage_stock' => true, 'stock_quantity' => 1)),
        'candle' => agm_catalogue_product(12, array('name' => 'Candle', 'price' => '12', 'regular_price' => '12', 'sku' => 'CAN-1')),
        'mugs'   => agm_catalogue_product(13, array('name' => 'Mugs', 'price' => '25'), 'Agm_Variable_Product'),
        'blue'   => agm_catalogue_product(14, array('name' => 'Mugs - Blue', 'price' => '25', 'regular_price' => '25', 'parent_id' => 13, 'sku' => 'MUG-BLUE', 'manage_stock' => true, 'stock_quantity' => 5), 'Agm_Variation_Product'),
    );
}

/** What WordPress and WooCommerce fire around every storefront page. */
function shopper_path_page(callable $inside = null) {
    do_action('plugins_loaded');
    do_action('woocommerce_loaded');
    do_action('init');
    do_action('woocommerce_init');
    do_action('wp_loaded');
    do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    do_action('parse_request');
    do_action('wp');
    do_action('template_redirect');
    if ($inside) {
        $inside();
    }
    do_action('wp_enqueue_scripts');
    do_action('wp_head');
    do_action('wp_footer');
    do_action('shutdown');
}

/** A wc-ajax call: WooCommerce answers it during template_redirect and never renders a page. */
function shopper_path_wc_ajax($action, callable $inside) {
    agm_input(array('wc-ajax' => $action), $_POST);
    do_action('plugins_loaded');
    do_action('init');
    do_action('woocommerce_init');
    do_action('wp_loaded');
    do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    do_action('template_redirect');
    $inside();
    do_action('shutdown');
}

/** A Store API call from the block cart or checkout. */
function shopper_path_store_api(callable $inside) {
    do_action('plugins_loaded');
    do_action('init');
    do_action('woocommerce_init');
    do_action('wp_loaded');
    do_action('rest_api_init');
    do_action('woocommerce_store_api_cart_loaded', WC()->cart);
    $inside();
    do_action('shutdown');
}

/**
 * WordPress's hooks for a post WooCommerce writes: the shop_order post of a
 * store on legacy order storage, or a variation's post.
 */
function shopper_path_post_hooks($post_type, $id, $after, $before = null) {
    $update = $before !== null;
    if ($update) {
        do_action('post_updated', $id, $after, $before);
    }
    do_action('save_post_' . $post_type, $id, $after, $update);
    do_action('save_post', $id, $after, $update);
    do_action('wp_insert_post', $id, $after, $update);
    do_action('wp_after_insert_post', $id, $after, $update, $before);
}

/** A new order saved by the checkout, on legacy storage: its post is inserted, then woocommerce_new_order. */
function shopper_path_create_order(WC_Order $order) {
    agm_wc_persist($order);
    shopper_path_post_hooks('shop_order', $order->get_id(), agm_order_post($order));
    do_action('woocommerce_new_order', $order->get_id(), $order);
}

/** WC_Order::set_status() then save(): the shop_order post is rewritten (legacy storage), then the order hooks. */
function shopper_path_transition(WC_Order $order, $to) {
    $from = $order->get_status();
    $before = agm_order_post($order);
    $order->set_status($to);
    agm_wc_persist($order);
    $id = $order->get_id();
    shopper_path_post_hooks('shop_order', $id, agm_order_post($order), $before);
    if ($from === 'checkout-draft') {
        do_action('woocommerce_new_order', $id, $order);
    }
    do_action('woocommerce_update_order', $id, $order);
    do_action('woocommerce_order_status_' . $to, $id, $order, array('from' => $from, 'to' => $to));
    do_action('woocommerce_order_status_' . $from . '_to_' . $to, $id, $order);
    do_action('woocommerce_order_status_changed', $id, $from, $to, $order);
    if (in_array($to, wc_get_is_paid_statuses(), true) !== in_array($from, wc_get_is_paid_statuses(), true)) {
        do_action('woocommerce_order_payment_status_changed', $id, $order);
    }
}

/**
 * WC_Product::save() during a stock change, with get_changes() reporting
 * $changes. A variation whose stored attribute summary is out of date (a
 * global attribute term was renamed, say) has its post rewritten on the way
 * with wp_update_post(), so WordPress's post hooks run inside the save; the
 * store here is in that state, since it is the case a listener must survive.
 */
function shopper_path_save_product(WC_Product $product, array $changes) {
    $id = $product->get_id();
    $before = agm_product_post($product);
    foreach ($changes as $prop => $value) {
        $product->props[$prop] = $value;
    }
    $product->agm_changes = $changes;
    do_action('woocommerce_before_product_object_save', $product, null);
    agm_wc_persist($product);
    if ($product->is_type('variation')) {
        shopper_path_post_hooks('product_variation', $id, agm_product_post($product), $before);
        do_action('woocommerce_update_product_variation', $id, $product);
    } else {
        do_action('woocommerce_update_product', $id, $product);
    }
    $product->agm_changes = array();
    do_action('woocommerce_after_product_object_save', $product, null);
}

/**
 * Stock leaves the shelf: wc_reduce_stock_levels(), then wc_update_product_stock()
 * per line. The new quantity goes straight into the product's data and the
 * product is saved; the save reports the stock status as changed when it
 * flips, and the quantity is reported too, as a plain save after
 * set_stock_quantity() would.
 */
function shopper_path_reduce_stock(WC_Order $order) {
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product || empty($product->props['manage_stock'])) {
            continue;
        }
        $prefix = $product->is_type('variation') ? 'woocommerce_variation' : 'woocommerce_product';
        $from = (int) $product->get_stock_quantity();
        $to = $from - (int) $item->get_quantity();
        do_action($prefix . '_before_set_stock', $product);
        $changes = array('stock_quantity' => $to);
        $status = $to > 0 ? 'instock' : 'outofstock';
        if ($status !== $product->props['stock_status']) {
            $changes['stock_status'] = $status;
        }
        shopper_path_save_product($product, $changes);
        do_action($prefix . '_set_stock', $product);
        if (isset($changes['stock_status'])) {
            do_action($prefix . '_set_stock_status', $product->get_id(), $status, $product);
        }
        do_action('woocommerce_reduce_order_item_stock', $item, array('from' => $from, 'to' => $to), $order);
    }
    do_action('woocommerce_reduce_order_stock', $order);
}

/** WC_Order::payment_complete(): paid date, processing, stock. */
function shopper_path_payment_complete(WC_Order $order) {
    $id = $order->get_id();
    do_action('woocommerce_pre_payment_complete', $id, 'txn_1');
    $order->props['date_paid'] = agm_date(time());
    $order->props['transaction_id'] = 'txn_1';
    shopper_path_transition($order, 'processing');
    do_action('woocommerce_payment_complete', $id, 'txn_1');
    shopper_path_reduce_stock($order);
}

/** The cart is emptied once the order is placed. */
function shopper_path_empty_cart() {
    WC()->cart->empty_cart();
    do_action('woocommerce_cart_emptied', true);
}

// ── Every shopper hook, each on its own ──

function test_shopper_path_every_shopper_hook_on_its_own_is_quiet() {
    shopper_path_fire_every_hook('shopper');
}

function test_shopper_path_every_shopper_hook_is_quiet_from_admin_ajax_and_the_store_api() {
    shopper_path_fire_every_hook('shopper_ajax');
    shopper_path_fire_every_hook('shopper_rest');
}

function test_shopper_path_the_rule_counts_user_meta_and_allows_only_the_listed_key() {
    agm_add_user(7, 'bea@example.com');
    $one = agm_request('answer saved', 'shopper', function () {
        update_user_meta(7, '_agumail_optin', 'yes');
    });
    shopper_path_expect_quiet($one);
    $two = agm_request('answer saved and queued', 'shopper', function () {
        update_user_meta(7, '_agumail_optin', 'yes');
        agumail_enqueue('user', 7);
    });
    $other = agm_request('other meta', 'shopper', function () {
        update_user_meta(7, '_agumail_last_seen', time());
    });
    foreach (array($two, $other) as $result) {
        try {
            shopper_path_expect_quiet($result);
            $caught = false;
        } catch (Exception $e) {
            $caught = true;
        }
        expect_that($caught, '"' . $result['name'] . '" should break the shopper-path rule');
    }
}

function shopper_path_fire_every_hook($kind) {
    $products = shopper_path_catalogue();
    $user = agm_add_user(7, 'bea@example.com', 'Bea', 'Stone');
    $order = agm_order(501, array('status' => 'processing', 'date_paid' => agm_date(time()), 'customer_id' => 7), array(array($products['mug'], 1)));
    $item_key = WC()->cart->add_line($products['candle'], 2);
    $customer = WC()->customer;
    $customer->set_billing_email('bea@example.com');
    $checkout_post = new WP_REST_Request('POST', '/wc/store/v1/checkout', array('billing_address' => array('email' => 'bea@example.com'), 'additional_fields' => array('agumail/optin' => true)));
    $checkout_put = new WP_REST_Request('PUT', '/wc/store/v1/checkout', array('additional_fields' => array('agumail/optin' => true)));
    $customer_request = new WP_REST_Request('POST', '/wc/store/v1/cart/update-customer', array('billing_address' => array('email' => 'bea@example.com')));
    $data = array('billing_email' => 'bea@example.com', 'billing_first_name' => 'Bea', 'payment_method' => 'cod');
    $errors = new WP_Error();

    $hooks = array(
        // Every page
        array('plugins_loaded'), array('woocommerce_loaded'), array('init'), array('woocommerce_init'), array('wp_loaded'),
        array('parse_request'), array('wp'), array('template_redirect'), array('wp_enqueue_scripts'), array('wp_head'),
        array('wp_footer'), array('shutdown'),
        array('woocommerce_before_single_product'), array('woocommerce_after_single_product'),
        // Cart
        array('woocommerce_cart_loaded_from_session', WC()->cart),
        array('woocommerce_add_to_cart', $item_key, 12, 2, 0, array(), array()),
        array('woocommerce_ajax_added_to_cart', 12),
        array('woocommerce_cart_item_removed', $item_key, WC()->cart),
        array('woocommerce_cart_item_restored', $item_key, WC()->cart),
        array('woocommerce_after_cart_item_quantity_update', $item_key, 3, 2, WC()->cart),
        array('woocommerce_cart_item_set_quantity', $item_key, 3, WC()->cart),
        array('woocommerce_cart_updated'),
        array('woocommerce_cart_emptied', true),
        array('woocommerce_store_api_cart_update_customer_from_request', $customer, $customer_request),
        array('woocommerce_store_api_cart_update_order_from_request', $order, $customer_request),
        // Capture and the classic checkout's order review
        array('wc_ajax_agumail_capture'),
        array('woocommerce_checkout_update_order_review', 'billing_email=bea%40example.com&agumail_optin=1'),
        // Classic checkout
        array('woocommerce_before_checkout_process'), array('woocommerce_checkout_process'),
        array('woocommerce_after_checkout_validation', $data, $errors),
        array('woocommerce_checkout_create_order', $order, $data),
        array('woocommerce_checkout_update_order_meta', 501, $data),
        array('woocommerce_checkout_order_created', $order),
        array('woocommerce_checkout_order_processed', 501, $data, $order),
        array('woocommerce_review_order_before_submit'),
        // Block checkout
        array('woocommerce_store_api_checkout_update_customer_from_request', $customer, $checkout_post),
        array('woocommerce_store_api_checkout_update_order_from_request', $order, $checkout_put),
        array('woocommerce_store_api_checkout_update_order_from_request', $order, $checkout_post),
        array('woocommerce_store_api_checkout_order_processed', $order),
        // Order creation, payment and status changes
        array('woocommerce_new_order', 501, $order),
        array('woocommerce_update_order', 501, $order),
        array('woocommerce_pre_payment_complete', 501, 'txn'),
        array('woocommerce_payment_complete', 501, 'txn'),
        array('woocommerce_order_status_pending', 501, $order), array('woocommerce_order_status_on-hold', 501, $order),
        array('woocommerce_order_status_processing', 501, $order), array('woocommerce_order_status_completed', 501, $order),
        array('woocommerce_order_status_failed', 501, $order), array('woocommerce_order_status_cancelled', 501, $order),
        array('woocommerce_order_status_pending_to_processing', 501, $order),
        array('woocommerce_order_status_pending_to_on-hold', 501, $order),
        array('woocommerce_order_status_on-hold_to_processing', 501, $order),
        array('woocommerce_order_status_changed', 501, 'pending', 'processing', $order),
        array('woocommerce_order_payment_status_changed', 501, $order),
        array('woocommerce_thankyou', 501), array('woocommerce_before_thankyou', 501),
        // Legacy order storage: WordPress's hooks for the shop_order post
        array('post_updated', 501, agm_order_post($order), agm_order_post($order)),
        array('save_post_shop_order', 501, agm_order_post($order), true),
        array('save_post', 501, agm_order_post($order), true),
        array('wp_insert_post', 501, agm_order_post($order), true),
        array('wp_after_insert_post', 501, agm_order_post($order), true, agm_order_post($order)),
        array('wp_after_insert_post', 501, agm_order_post($order), false, null),
        // Stock-only product saves while the order is placed
        array('woocommerce_product_before_set_stock', $products['mug']),
        array('woocommerce_variation_before_set_stock', $products['blue']),
        array('woocommerce_update_product_variation', 14, $products['blue']),
        array('woocommerce_after_product_object_save', $products['mug'], null),
        array('save_post_product_variation', 14, agm_product_post($products['blue']), true),
        array('wp_after_insert_post', 14, agm_product_post($products['blue']), true, agm_product_post($products['blue'])),
        array('woocommerce_before_product_object_save', $products['mug'], null),
        array('woocommerce_update_product', 11, $products['mug']),
        array('woocommerce_product_object_updated_props', $products['mug'], array('stock_quantity')),
        array('woocommerce_product_set_stock', $products['mug']),
        array('woocommerce_variation_set_stock', $products['mug']),
        array('woocommerce_product_set_stock_status', 11, 'instock', $products['mug']),
        array('woocommerce_reduce_order_stock', $order),
        // Registration, login and account
        array('woocommerce_register_post', 'bea', 'bea@example.com', $errors),
        array('register_post', 'bea', 'bea@example.com', $errors),
        array('user_register', 7, array('user_email' => 'bea@example.com')),
        array('woocommerce_created_customer', 7, array('user_email' => 'bea@example.com'), false),
        array('register_new_user', 7),
        array('wp_login', 'bea', $user),
        array('woocommerce_save_account_details', 7),
        array('profile_update', 7, $user, array('user_email' => 'bea@example.com')),
        array('woocommerce_customer_save_address', 7, 'billing'),
        array('woocommerce_edit_account_form'), array('woocommerce_register_form'), array('register_form'),
    );

    foreach ($hooks as $hook) {
        $name = array_shift($hook);
        $result = agm_request($kind . ': ' . $name, $kind, function () use ($name, $hook) {
            do_action($name, ...$hook);
        });
        shopper_path_expect_quiet($result);
    }
}

// ── Whole requests ──

function test_shopper_path_browsing_and_changing_the_cart_is_quiet() {
    $products = shopper_path_catalogue();
    agm_sign_in(7, 'bea@example.com');

    shopper_path_expect_quiet(agm_request('product page', 'shopper', function () {
        $GLOBALS['agm']['page'] = 'product';
        shopper_path_page(function () {
            do_action('woocommerce_before_single_product');
            do_action('woocommerce_after_single_product');
        });
    }));

    $key = null;
    shopper_path_expect_quiet(agm_request('add to cart', 'shopper', function () use ($products, &$key) {
        shopper_path_wc_ajax('add_to_cart', function () use ($products, &$key) {
            $key = WC()->cart->add_line($products['candle'], 1);
            do_action('woocommerce_add_to_cart', $key, 12, 1, 0, array(), array());
            do_action('woocommerce_cart_updated');
            do_action('woocommerce_ajax_added_to_cart', 12);
        });
    }));

    shopper_path_expect_quiet(agm_request('cart page: change quantity', 'shopper', function () use (&$key) {
        $GLOBALS['agm']['page'] = 'cart';
        shopper_path_page(function () use ($key) {
            WC()->cart->set_quantity($key, 3);
            do_action('woocommerce_after_cart_item_quantity_update', $key, 3, 1, WC()->cart);
            do_action('woocommerce_cart_item_set_quantity', $key, 3, WC()->cart);
            do_action('woocommerce_cart_updated');
        });
    }));

    shopper_path_expect_quiet(agm_request('cart page: remove item', 'shopper', function () use (&$key) {
        shopper_path_page(function () use ($key) {
            WC()->cart->remove_cart_item($key);
            do_action('woocommerce_cart_item_removed', $key, WC()->cart);
            do_action('woocommerce_cart_emptied', false);
            do_action('woocommerce_cart_updated');
        });
    }));

    shopper_path_expect_quiet(agm_request('block cart: add item', 'shopper_rest', function () use ($products) {
        shopper_path_store_api(function () use ($products) {
            $key = WC()->cart->add_line($products['mug'], 1);
            do_action('woocommerce_add_to_cart', $key, 11, 1, 0, array(), array());
            do_action('woocommerce_cart_updated');
        });
    }));

    shopper_path_expect_quiet(agm_request('theme add-to-cart through admin-ajax', 'shopper_ajax', function () use ($products) {
        do_action('plugins_loaded');
        do_action('init');
        do_action('admin_init');
        $key = WC()->cart->add_line($products['candle'], 1);
        do_action('woocommerce_add_to_cart', $key, 12, 1, 0, array(), array());
        do_action('wp_ajax_nopriv_woocommerce_add_to_cart');
        do_action('shutdown');
    }));
}

function test_shopper_path_a_paid_classic_checkout_is_quiet() {
    $products = shopper_path_catalogue();
    WC()->cart->add_line($products['mug'], 1);

    shopper_path_expect_quiet(agm_request('classic checkout page', 'shopper', function () {
        $GLOBALS['agm']['page'] = 'checkout';
        shopper_path_page(function () {
            do_action('woocommerce_before_checkout_form', WC()->checkout());
            do_action('woocommerce_review_order_before_submit');
            do_action('woocommerce_after_checkout_form', WC()->checkout());
        });
    }));

    shopper_path_expect_quiet(agm_request('classic checkout: order review refresh', 'shopper', function () {
        agm_input(array(), array('post_data' => 'billing_email=ada%40example.com&agumail_optin=1&agumail_optin_shown=1'));
        shopper_path_wc_ajax('update_order_review', function () {
            do_action('woocommerce_checkout_update_order_review', $_POST['post_data']);
        });
    }));

    shopper_path_expect_quiet(agm_request('capture while typing the email', 'shopper', function () {
        agm_input(array(), array('email' => 'ada@example.com', 'context' => 'checkout'));
        shopper_path_wc_ajax('agumail_capture', function () {
            do_action('wc_ajax_agumail_capture');
        });
    }));

    shopper_path_expect_quiet(agm_request('classic checkout: place order and pay by card', 'shopper', function () use ($products) {
        $data = array('billing_email' => 'ada@example.com', 'billing_first_name' => 'Ada', 'billing_last_name' => 'Lovelace', 'payment_method' => 'card');
        agm_input(array(), array_merge($data, array('agumail_optin' => '1', 'agumail_optin_shown' => '1', 'woocommerce-process-checkout-nonce' => 'n')));
        shopper_path_wc_ajax('checkout', function () use ($products, $data) {
            do_action('woocommerce_before_checkout_process');
            do_action('woocommerce_checkout_process');
            do_action('woocommerce_after_checkout_validation', $data, new WP_Error());
            $order = agm_order(0, array('status' => 'pending', 'billing_email' => 'ada@example.com'), array(array($products['mug'], 1), array($products['blue'], 2)));
            do_action('woocommerce_checkout_create_order', $order, $data);
            shopper_path_create_order($order);
            do_action('woocommerce_checkout_update_order_meta', $order->get_id(), $data);
            do_action('woocommerce_checkout_order_created', $order);
            do_action('woocommerce_checkout_order_processed', $order->get_id(), $data, $order);
            shopper_path_payment_complete($order);
            shopper_path_empty_cart();
            wp_send_json(array('result' => 'success', 'redirect' => $order->get_checkout_order_received_url()));
        });
    }));

    shopper_path_expect_quiet(agm_request('order received page', 'shopper', function () {
        $GLOBALS['agm']['page'] = 'order-received';
        $order = end($GLOBALS['agm']['orders']);
        shopper_path_page(function () use ($order) {
            do_action('woocommerce_before_thankyou', $order->get_id());
            do_action('woocommerce_thankyou', $order->get_id());
        });
    }));
}

function test_shopper_path_a_paid_block_checkout_is_quiet() {
    $products = shopper_path_catalogue();
    WC()->cart->add_line($products['candle'], 2);
    $draft = agm_order(0, array('status' => 'checkout-draft', 'billing_email' => ''), array(array($products['candle'], 2), array($products['blue'], 1)));
    agm_wc_persist($draft);

    shopper_path_expect_quiet(agm_request('block checkout: email typed', 'shopper_rest', function () {
        shopper_path_store_api(function () {
            $request = new WP_REST_Request('POST', '/wc/store/v1/cart/update-customer', array('billing_address' => array('email' => 'dee@example.com')));
            WC()->customer->set_billing_email('dee@example.com');
            do_action('woocommerce_store_api_cart_update_customer_from_request', WC()->customer, $request);
        });
    }));

    shopper_path_expect_quiet(agm_request('block checkout: box ticked, draft saved', 'shopper_rest', function () use ($draft) {
        shopper_path_store_api(function () use ($draft) {
            $request = new WP_REST_Request('PUT', '/wc/store/v1/checkout', array('additional_fields' => array('agumail/optin' => true)));
            $draft->update_meta_data('_wc_other/agumail/optin', '1');
            do_action('woocommerce_store_api_checkout_update_order_from_request', $draft, $request);
            agm_wc_persist($draft);
        });
    }));

    shopper_path_expect_quiet(agm_request('block checkout: place order and pay by card', 'shopper_rest', function () use ($draft) {
        shopper_path_store_api(function () use ($draft) {
            $request = new WP_REST_Request('POST', '/wc/store/v1/checkout', array(
                'billing_address' => array('email' => 'dee@example.com', 'first_name' => 'Dee'),
                'payment_method' => 'card',
                'additional_fields' => array('agumail/optin' => true),
            ));
            do_action('woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request);
            $draft->set_billing_email('dee@example.com');
            $draft->update_meta_data('_wc_other/agumail/optin', '1');
            do_action('woocommerce_store_api_checkout_update_order_from_request', $draft, $request);
            agm_wc_persist($draft);
            shopper_path_transition($draft, 'pending');
            do_action('woocommerce_store_api_checkout_order_processed', $draft);
            shopper_path_payment_complete($draft);
            shopper_path_empty_cart();
        });
    }));
}

function test_shopper_path_an_on_hold_checkout_and_its_later_payment_are_quiet() {
    $products = shopper_path_catalogue();
    WC()->cart->add_line($products['mug'], 1);
    $placed = null;

    shopper_path_expect_quiet(agm_request('classic checkout: place order, pay by bank transfer', 'shopper', function () use ($products, &$placed) {
        $data = array('billing_email' => 'eve@example.com', 'payment_method' => 'bacs');
        agm_input(array(), array_merge($data, array('agumail_optin_shown' => '1')));
        shopper_path_wc_ajax('checkout', function () use ($products, $data, &$placed) {
            do_action('woocommerce_checkout_process');
            $placed = agm_order(0, array('status' => 'pending', 'billing_email' => 'eve@example.com', 'payment_method' => 'bacs'), array(array($products['mug'], 1), array($products['blue'], 1)));
            do_action('woocommerce_checkout_create_order', $placed, $data);
            shopper_path_create_order($placed);
            do_action('woocommerce_checkout_order_processed', $placed->get_id(), $data, $placed);
            shopper_path_transition($placed, 'on-hold');
            shopper_path_reduce_stock($placed);
            shopper_path_empty_cart();
        });
    }));

    // Days later a gateway callback, a storefront request with no shopper in it, marks it paid.
    shopper_path_expect_quiet(agm_request('payment gateway callback marks the order paid', 'shopper', function () use (&$placed) {
        agm_input(array('wc-api' => 'gateway_callback'));
        do_action('init');
        do_action('woocommerce_api_gateway_callback');
        $placed->props['date_paid'] = agm_date(time());
        shopper_path_transition($placed, 'processing');
        do_action('woocommerce_payment_complete', $placed->get_id(), 'txn_9');
    }));
}

function test_shopper_path_capture_restore_registration_and_account_requests_are_quiet() {
    $products = shopper_path_catalogue();
    WC()->cart->add_line($products['candle'], 1);

    shopper_path_expect_quiet(agm_request('capture on the login form', 'shopper', function () {
        agm_input(array(), array('email' => 'fay@example.com', 'context' => 'login'));
        shopper_path_wc_ajax('agumail_capture', function () {
            do_action('wc_ajax_agumail_capture');
        });
    }));

    shopper_path_expect_quiet(agm_request('restore link opened in a fresh browser', 'shopper', function () {
        agm_input(array('agumail_cart' => 'wc-00000000-0000-4000-8000-000000000000', 'agumail_token' => str_repeat('a', 32), 'agm_cid' => 'cmp_1', 'utm_source' => 'agumail'));
        shopper_path_page();
    }));

    shopper_path_expect_quiet(agm_request('register on My Account', 'shopper', function () {
        $GLOBALS['agm']['page'] = 'account';
        agm_input(array(), array('email' => 'gus@example.com', 'agumail_optin' => '1', 'woocommerce-register-nonce' => 'n'));
        shopper_path_page(function () {
            do_action('woocommerce_register_post', 'gus', 'gus@example.com', new WP_Error());
            $user = agm_add_user(21, 'gus@example.com', 'Gus', 'Ray');
            do_action('user_register', 21, array('user_email' => 'gus@example.com'));
            do_action('woocommerce_created_customer', 21, array('user_email' => 'gus@example.com'), false);
            agm_sign_in(21);
            do_action('wp_login', 'gus', $user);
        });
    }));

    shopper_path_expect_quiet(agm_request('register on wp-login.php', 'shopper', function () {
        agm_input(array(), array('user_email' => 'hal@example.com', 'agumail_optin' => '1'));
        do_action('init');
        do_action('register_post', 'hal', 'hal@example.com', new WP_Error());
        agm_add_user(22, 'hal@example.com');
        do_action('user_register', 22, array('user_email' => 'hal@example.com'));
        do_action('register_new_user', 22);
    }));

    shopper_path_expect_quiet(agm_request('save account details', 'shopper', function () {
        $user = agm_sign_in(21);
        agm_input(array(), array('account_email' => 'gus@example.com', 'account_first_name' => 'Gus', 'agumail_optin' => '1'));
        shopper_path_page(function () use ($user) {
            do_action('woocommerce_save_account_details_errors', new WP_Error(), $user);
            do_action('profile_update', 21, $user, array('user_email' => 'gus@example.com'));
            do_action('woocommerce_save_account_details', 21);
        });
    }));

    shopper_path_expect_quiet(agm_request('save billing address', 'shopper', function () {
        $user = agm_sign_in(21);
        shopper_path_page(function () use ($user) {
            do_action('profile_update', 21, $user, array());
            do_action('woocommerce_customer_save_address', 21, 'billing');
        });
    }));
}

function test_shopper_path_a_queued_change_is_one_write_and_no_call() {
    $result = agm_request('a shopper hook queues an order', 'shopper', function () {
        agumail_enqueue('order', 501);
    });
    shopper_path_expect_quiet($result);
    expect_same(1, count(agm_writes('outbox', 'a shopper hook queues an order')), 'queueing a change should be exactly one write');
}

// ── Form plugin signups ──
// A visitor submits a form built with Elementor Pro (admin-ajax.php), Contact
// Form 7 (its REST feedback endpoint) or WPForms (admin-ajax.php, or a plain
// post to the page). Each is played with the form plugin's own hooks around
// the AguMail adapter's.

/** The form plugins' fakes, and tracking on, so the answer also names the visitor (no write, no call either). */
function shopper_path_builder_fakes() {
    foreach (array('elementor', 'cf7', 'wpforms') as $builder) {
        require_once dirname(__DIR__) . '/fakes/builders/' . $builder . '.php';
    }
    update_option('agumail_config', array('siteKey' => 'site_abc123', 'siteScriptUrl' => 'https://api.agumail.test/p/_/v1/site.js', 'identifySecret' => 'secret'));
    update_option('agumail_tracking_enabled', 'yes');
}

function shopper_path_signup_count($name, $expected) {
    expect_same($expected, count(agm_outbox('signup')), "after \"$name\", wrong number of queued signups");
}

function test_shopper_path_an_elementor_form_signup_is_quiet() {
    shopper_path_builder_fakes();
    $name = 'elementor form submitted through admin-ajax';
    shopper_path_expect_quiet(agm_request($name, 'shopper_ajax', function () {
        agm_input(array(), array('action' => 'elementor_pro_forms_send_form', 'post_id' => '12', 'form_id' => 'abc123', 'referrer' => 'https://shop.test/sale/'));
        do_action('plugins_loaded');
        do_action('init');
        do_action('admin_init');
        $registrar = new Agm_Elementor_Registrar();
        do_action('elementor_pro/forms/actions/register', $registrar);
        $record = agm_elementor_record(array(
            array('name', 'text', 'Name', 'Ada Lovelace'),
            array('email', 'email', 'Email', 'ada@example.com'),
            array('ok', 'acceptance', 'Consent', 'on', array('acceptance_text' => 'Send me offers')),
        ), array('agumail_tags' => 'popup'));
        $handler = agm_elementor_handler();
        do_action('elementor_pro/forms/validation', $record, $handler);
        do_action('elementor_pro/forms/process', $record, $handler);
        foreach ($registrar->get() as $action) {
            $action->run($record, $handler);
            do_action('elementor_pro/forms/actions/after_run', $action, null);
        }
        do_action('elementor_pro/forms/new_record', $record, $handler);
        do_action('shutdown');
        wp_send_json_success(array('message' => 'Your submission was successful.', 'data' => $handler->data));
    }));
    shopper_path_signup_count($name, 1);
    expect_that(!empty(end($GLOBALS['agm']['json'])['body']['data']['data']['agumail']['sig']), 'the answer does not name the visitor');
}

function test_shopper_path_a_contact_form_7_signup_is_quiet() {
    shopper_path_builder_fakes();
    $name = 'contact form 7 submitted through its REST endpoint';
    shopper_path_expect_quiet(agm_request($name, 'shopper_rest', function () {
        do_action('plugins_loaded');
        do_action('init');
        do_action('rest_api_init');
        $form = agm_cf7_form(7, 'Contact form 1', array(agm_cf7_tag('text*', 'your-name'), agm_cf7_tag('email*', 'your-email'), agm_cf7_tag('submit', '')),
            array('enabled' => '1', 'tags' => 'contact', 'double_opt_in' => '1', 'fields' => array()));
        agm_cf7_submit($form, array('your-name' => 'Ada Lovelace', 'your-email' => 'ada@example.com'));
        do_action('shutdown');
    }));
    shopper_path_signup_count($name, 1);
}

function test_shopper_path_a_wpforms_signup_is_quiet_by_ajax_and_by_a_plain_post() {
    shopper_path_builder_fakes();
    $form = agm_wpforms_form(42, 'Newsletter', array(), array('agumail_enable' => '1', 'agumail_tags' => 'news'));
    $fields = array(
        agm_wpforms_field(1, 'name', 'Name', 'Ada Lovelace', array('first' => 'Ada', 'middle' => '', 'last' => 'Lovelace')),
        agm_wpforms_field(2, 'email', 'Email', 'ada@example.com'),
    );
    $name = 'wpforms submitted through admin-ajax';
    shopper_path_expect_quiet(agm_request($name, 'shopper_ajax', function () use ($form, $fields) {
        agm_input(array(), array('action' => 'wpforms_submit', 'wpforms' => array('id' => '42')));
        do_action('plugins_loaded');
        do_action('init');
        do_action('admin_init');
        do_action('wpforms_ajax_submit_before_processing', 42);
        do_action('wpforms_process_before', array(), $form);
        agm_wpforms_submit($form, $fields);
        do_action('wpforms_ajax_submit_completed', 42, array());
        do_action('shutdown');
    }));
    shopper_path_signup_count($name, 1);

    $fields[1]['value'] = 'bea@example.com';
    $name = 'wpforms posted to its page without ajax';
    shopper_path_expect_quiet(agm_request($name, 'shopper', function () use ($form, $fields) {
        agm_input(array(), array('wpforms' => array('id' => '42')));
        shopper_path_page(function () use ($form, $fields) {
            do_action('wpforms_process_before', array(), $form);
            agm_wpforms_submit($form, $fields);
        });
    }));
    shopper_path_signup_count($name, 2);
}

// ── Only http.php may call out ──

function test_shopper_path_only_http_php_calls_out($plugin_dir) {
    $offenders = array();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (substr($file->getFilename(), -4) !== '.php') {
            continue;
        }
        $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($plugin_dir))), '/');
        // Action Scheduler, bundled for sites without WooCommerce: its only request
        // is the loopback that starts its queue runner on this site's own admin-ajax.php.
        if ($relative === 'includes/http.php' || strpos($relative, 'vendor/woocommerce/action-scheduler/') === 0) {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if (preg_match('/\bwp_(safe_)?remote_|\bcurl_exec\b|\bfsockopen\b|\bstream_socket_client\b|new\s+WP_Http\b|Requests::request/', $code, $m)) {
            $offenders[] = $relative . ' (' . $m[0] . ')';
        }
    }
    expect_that(!$offenders, 'outbound HTTP outside includes/http.php: ' . implode(', ', $offenders));
}
