<?php
// The checkout's marketing checkbox, on both checkouts. The answer counts only
// on a submitted order: a tick on a page nobody submitted, or on a block
// checkout's draft, is not an answer. The box starts unticked unless the
// merchant sets it to start ticked, and its label may carry links.

function consent_order($id = 0) {
    return agm_order($id, array('status' => 'pending'), array(array(agm_product(11, 'Mug', 30), 1)));
}

function consent_render_classic() {
    ob_start();
    do_action('woocommerce_review_order_before_submit');
    return ob_get_clean();
}

/** The classic checkout's submit, with the given posted fields. */
function consent_submit_classic(array $post) {
    $order = consent_order();
    agm_request('classic checkout: place order', 'shopper', function () use ($post, $order) {
        agm_input(array('wc-ajax' => 'checkout'), $post);
        do_action('woocommerce_checkout_process');
        do_action('woocommerce_checkout_create_order', $order, $post);
    });
    return $order;
}

/** A Store API checkout request (PUT saves the draft, POST places the order). */
function consent_block_request($method, array $additional_fields = null) {
    $order = consent_order();
    $params = array('billing_address' => array('email' => 'dee@example.com'));
    if ($additional_fields !== null) {
        $params['additional_fields'] = $additional_fields;
    }
    agm_request("block checkout $method", 'shopper_rest', function () use ($method, $params, $order) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $order, new WP_REST_Request($method, '/wc/store/v1/checkout', $params));
    });
    return $order;
}

// ── What WooCommerce is told ──

function test_consent_declares_hpos_and_block_checkout_support() {
    do_action('before_woocommerce_init');
    foreach (array('custom_order_tables', 'cart_checkout_blocks') as $feature) {
        expect_that(!empty($GLOBALS['agm']['compat'][$feature]['compatible']), "$feature compatibility is not declared");
        expect_same(AGUMAIL_FILE, $GLOBALS['agm']['compat'][$feature]['file'], "$feature is declared for the wrong file");
    }
}

function test_consent_the_plugin_header_names_the_versions_it_needs($plugin_dir) {
    $header = file_get_contents($plugin_dir . '/agumail.php', false, null, 0, 2048);
    foreach (array('Plugin Name:          AguMail: Email Marketing Made Easy', 'Version:              1.1.0', 'Requires at least:    6.5',
        'Requires PHP:         7.4', 'WC requires at least: 8.0', 'WC tested up to:      11.2') as $line) {
        expect_that(strpos($header, $line) !== false, 'the plugin header is missing "' . $line . '"');
    }
    // It runs on any WordPress site, so WordPress must not hold it back without WooCommerce.
    expect_that(strpos($header, 'Requires Plugins') === false, 'the plugin header still requires WooCommerce');
    expect_same('1.1.0', AGUMAIL_VERSION, 'wrong AGUMAIL_VERSION');
    // WordPress.org refuses a version that is not digits and dots, and serves the readme's Stable tag.
    expect_that(preg_match('/^\d+(\.\d+)*$/', AGUMAIL_VERSION) === 1, 'AGUMAIL_VERSION is not digits and dots');
    $readme = file_get_contents($plugin_dir . '/readme.txt');
    expect_that(strpos($readme, "\nStable tag: " . AGUMAIL_VERSION . "\n") !== false, 'the readme\'s Stable tag is not AGUMAIL_VERSION');
}

function test_consent_the_block_checkout_registers_the_checkbox() {
    update_option('agumail_consent_label', 'Send me the good stuff');
    do_action('woocommerce_init');
    // Kept off the thank-you page and the order emails, which would otherwise
    // print "Additional information: Send me the good stuff: No" on every order.
    expect_same(array(array('id' => 'agumail/optin', 'label' => 'Send me the good stuff', 'location' => 'order', 'type' => 'checkbox',
        'show_in_order_confirmation' => false)),
        $GLOBALS['agm']['fields'], 'the block checkout checkbox is not registered as expected');
}

function test_consent_the_classic_checkout_renders_the_checkbox_with_its_marker() {
    $html = consent_render_classic();
    expect_that(strpos($html, 'name="agumail_optin"') !== false, 'no checkbox rendered');
    expect_that(strpos($html, 'Email me about new products and offers') !== false, 'the default label is missing');
    expect_that(strpos($html, '<input type="hidden" name="agumail_optin_shown" value="1" />') !== false, 'the marker that the box was shown is missing');
}

// ── Classic checkout ──

function test_consent_the_order_review_refresh_keeps_the_tick_on_screen() {
    agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com&agumail_optin=1&agumail_optin_shown=1'));
    expect_that(strpos(consent_render_classic(), 'checked="checked"') !== false, 'refreshing the order review unticked the box');
    agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com&agumail_optin_shown=1'));
    expect_that(strpos(consent_render_classic(), 'checked="checked"') === false, 'refreshing the order review ticked a box the shopper left empty');
    expect_same(array(), agm_writes(), 'redrawing the box wrote something');
}

function test_consent_a_submitted_classic_order_records_the_answer() {
    expect_same('yes', consent_submit_classic(array('agumail_optin' => '1', 'agumail_optin_shown' => '1'))->get_meta('_agumail_optin'), 'a ticked box was not recorded as yes');
    expect_same('no', consent_submit_classic(array('agumail_optin_shown' => '1'))->get_meta('_agumail_optin'), 'a box shown and left unticked was not recorded as no');
    $unasked = consent_submit_classic(array('agumail_optin' => '1'));
    expect_same(null, agumail_order_optin_answer($unasked), 'an order placed where the box was never shown has an answer');
    expect_same(array(), agm_writes(), 'recording the answer made a write of its own; it rides on WooCommerce\'s save');
}

function test_consent_a_tick_that_is_never_submitted_records_nothing() {
    $order = consent_order(701);
    agm_request('classic checkout: order review refresh', 'shopper', function () {
        agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com&agumail_optin=1&agumail_optin_shown=1'));
        do_action('woocommerce_checkout_update_order_review', $_POST['post_data']);
    });
    expect_same(array(), $order->get_meta(), 'a tick on the page changed an order');
    expect_same(array(), agm_writes(), 'a tick on the page was written somewhere');
}

// ── Block checkout ──

function test_consent_block_draft_saves_record_nothing_and_the_submission_does() {
    expect_same(null, agumail_order_optin_answer(consent_block_request('POST', array('agumail/optin' => true))), 'recorded before the field was registered for this request');

    do_action('woocommerce_init');
    $draft = consent_block_request('PUT', array('agumail/optin' => true));
    expect_same(null, agumail_order_optin_answer($draft), 'a tick saved into the draft counted as an answer');

    expect_same('yes', consent_block_request('POST', array('agumail/optin' => true))->get_meta('_agumail_optin'), 'a ticked submission was not recorded as yes');
    expect_same('yes', consent_block_request('POST', array('agumail/optin' => '1'))->get_meta('_agumail_optin'), 'a ticked submission sent as "1" was not recorded as yes');
    expect_same('no', consent_block_request('POST', array('agumail/optin' => false))->get_meta('_agumail_optin'), 'an unticked submission was not recorded as no');
    expect_same('no', consent_block_request('POST', array())->get_meta('_agumail_optin'), 'a submission without the field was not recorded as no');

    $ticked_draft_unticked_submit = consent_order();
    $ticked_draft_unticked_submit->update_meta_data('_wc_other/agumail/optin', '1');
    agm_request('block checkout POST after a ticked draft', 'shopper_rest', function () use ($ticked_draft_unticked_submit) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $ticked_draft_unticked_submit,
            new WP_REST_Request('POST', '/wc/store/v1/checkout', array('additional_fields' => array('agumail/optin' => false))));
    });
    expect_same('no', $ticked_draft_unticked_submit->get_meta('_agumail_optin'), 'the draft\'s tick outweighed the submission');

    $ticked_draft_silent_submit = consent_order();
    $ticked_draft_silent_submit->update_meta_data('_wc_other/agumail/optin', '1');
    agm_request('block checkout POST without the field after a ticked draft', 'shopper_rest', function () use ($ticked_draft_silent_submit) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $ticked_draft_silent_submit,
            new WP_REST_Request('POST', '/wc/store/v1/checkout', array('additional_fields' => array())));
    });
    expect_same('no', $ticked_draft_silent_submit->get_meta('_agumail_optin'), 'a tick left on the draft counted for a submission that did not carry it');
    expect_same(array(), agm_writes(), 'recording the answer made a write of its own; it rides on WooCommerce\'s save');
}

// ── Ticked by default ──

/** The merchant set the box to start ticked (a setup write, forgotten like the plugin's own setup). */
function consent_tick_by_default() {
    update_option('agumail_checkout_optin_default', 'yes');
    $GLOBALS['agm']['writes'] = array();
}

function consent_ticked($html) {
    return (bool) preg_match('/<input[^>]*name="agumail_optin"[^>]*checked/', $html);
}

/** The block checkout asks for the box's default while drawing the draft order (and the customer record). */
function consent_block_default($object) {
    return apply_filters('woocommerce_get_default_value_for_agumail/optin', null, 'other', $object);
}

function test_consent_the_box_starts_unticked_unless_the_merchant_ticks_it_by_default() {
    expect_that(!agumail_checkout_optin_ticked_by_default(), 'the box starts ticked out of the box');
    expect_that(!consent_ticked(consent_render_classic()), 'the classic box starts ticked out of the box');
    expect_same(null, consent_block_default(consent_order()), 'the block box starts ticked out of the box');

    consent_tick_by_default();
    expect_that(agumail_checkout_optin_ticked_by_default(), 'the setting is not read');
    expect_that(consent_ticked(consent_render_classic()), 'the classic box does not start ticked');
    expect_same(true, consent_block_default(consent_order()), 'the block box does not start ticked');
    expect_same(array(), agm_writes(), 'drawing the box wrote something');
}

function test_consent_a_box_ticked_by_default_keeps_the_shoppers_choice_on_the_classic_checkout() {
    consent_tick_by_default();
    // The order review refresh posts the form as drawn: the box was on the page.
    agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com&agumail_optin_shown=1'));
    expect_that(!consent_ticked(consent_render_classic()), 'a box the shopper unticked was ticked again by the refresh');
    agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com&agumail_optin=1&agumail_optin_shown=1'));
    expect_that(consent_ticked(consent_render_classic()), 'a ticked box was unticked by the refresh');
    // A refresh from a page that never had the box on it: nothing was posted for it yet.
    agm_input(array('wc-ajax' => 'update_order_review'), array('post_data' => 'billing_email=ada%40example.com'));
    expect_that(consent_ticked(consent_render_classic()), 'a box drawn for the first time did not start ticked');
    // The form posted without scripts, and drawn again: the shopper's choice stands.
    agm_input(array(), array('billing_email' => 'ada@example.com', 'agumail_optin_shown' => '1'));
    expect_that(!consent_ticked(consent_render_classic()), 'a posted form lost the shopper\'s untick');
}

function test_consent_a_box_ticked_by_default_still_counts_only_on_a_submitted_order() {
    consent_tick_by_default();
    expect_same('yes', consent_submit_classic(array('agumail_optin' => '1', 'agumail_optin_shown' => '1'))->get_meta('_agumail_optin'), 'a box left ticked was not a yes');
    expect_same('no', consent_submit_classic(array('agumail_optin_shown' => '1'))->get_meta('_agumail_optin'), 'an unticked box counted as the default');
    expect_same(null, agumail_order_optin_answer(consent_submit_classic(array())), 'an order that never showed the box has an answer');

    do_action('woocommerce_init');
    expect_same(null, agumail_order_optin_answer(consent_block_request('PUT', array('agumail/optin' => true))), 'a ticked draft counted as an answer');
    expect_same('yes', consent_block_request('POST', array('agumail/optin' => true))->get_meta('_agumail_optin'), 'a box left ticked was not a yes');
    expect_same('no', consent_block_request('POST', array('agumail/optin' => false))->get_meta('_agumail_optin'), 'an unticked box counted as the default');
    expect_same('no', consent_block_request('POST', array())->get_meta('_agumail_optin'), 'a submission that did not carry the box counted as the default');
    expect_same(array(), agm_writes(), 'recording the answer made a write of its own; it rides on WooCommerce\'s save');
}

function test_consent_the_block_default_only_ticks_a_draft_that_holds_no_value() {
    consent_tick_by_default();
    $unticked = consent_order();
    $unticked->update_meta_data('_wc_other/agumail/optin', false);
    expect_same(null, consent_block_default($unticked), 'a draft the shopper unticked was ticked again');
    $ticked = consent_order();
    $ticked->update_meta_data('_wc_other/agumail/optin', '1');
    expect_same(null, consent_block_default($ticked), 'a draft with a value was given the default');
    // WooCommerce copies the customer record into the draft each time it updates it.
    expect_same(null, consent_block_default(new WC_Customer(7)), 'the customer record was given the default, and would tick an unticked draft again');
    expect_same(null, consent_block_default(null), 'no object was given the default');
    expect_same('1', apply_filters('woocommerce_get_default_value_for_agumail/optin', '1', 'other', consent_order()), 'another plugin\'s default was overridden');
}

function test_consent_from_woocommerce_10_9_the_block_default_ticks_a_customer_record_that_holds_no_value() {
    require_once dirname(__DIR__) . '/fakes/wc_10_9/checkout_schema.php';
    expect_same(null, consent_block_default(new WC_Customer(7)), 'the customer record starts ticked out of the box');

    consent_tick_by_default();
    // No order exists until the shopper places it: the checkout is drawn from the customer record.
    expect_same(true, consent_block_default(new WC_Customer(7)), 'the block box does not start ticked');
    $unticked = new WC_Customer(7);
    $unticked->update_meta_data('_wc_other/agumail/optin', '0');
    expect_same(null, consent_block_default($unticked), 'a box the shopper unticked was ticked again');
    $ticked = new WC_Customer(7);
    $ticked->update_meta_data('_wc_other/agumail/optin', '1');
    expect_same(null, consent_block_default($ticked), 'a customer record with a value was given the default');
    // A payment retried on the order of one that failed is drawn from that order.
    expect_same(true, consent_block_default(consent_order()), 'an order holding no value does not start ticked');

    // A customer who already said no.
    add_filter('agumail_checkout_optin_starts_ticked', '__return_false');
    expect_same(null, consent_block_default(new WC_Customer(7)), 'the box started ticked for a shopper it should not');
    expect_same(array(), agm_writes(), 'drawing the box wrote something');
}

// ── Links in the label ──

function test_consent_a_label_with_a_link_keeps_it_on_the_classic_checkout_and_gives_the_block_checkout_its_words() {
    update_option('agumail_consent_label', 'Email me offers. See our <a href="https://shop.test/privacy" target="_blank" rel="noopener" onclick="steal()">privacy &amp; cookies</a> <script>steal()</script>');
    $html = consent_render_classic();
    expect_that(strpos($html, '<a href="https://shop.test/privacy" target="_blank" rel="noopener">privacy &amp; cookies</a>') !== false, 'the link did not reach the classic checkout: ' . $html);
    expect_that(strpos($html, 'onclick') === false && strpos($html, '<script') === false, 'markup other than the link reached the classic checkout: ' . $html);

    do_action('woocommerce_init');
    expect_same('Email me offers. See our privacy & cookies steal()', $GLOBALS['agm']['fields'][0]['label'], 'the block checkout, which shows its label as text, did not get plain words');
}

function test_consent_a_link_that_is_not_a_web_address_is_dropped() {
    update_option('agumail_consent_label', 'Email me <a href="javascript:steal()">offers</a>');
    expect_that(strpos(consent_render_classic(), 'javascript:') === false, 'a script link reached the classic checkout');
}

function test_consent_the_setting_is_saved_from_the_settings_page_and_shown_there() {
    agm_grant('manage_woocommerce');
    do_action('init');
    $save = function (array $fields) {
        return agm_request('save checkout settings', 'admin', function () use ($fields) {
            agm_input(array(), array_merge(array('action' => 'agumail', 'agumail_action' => 'save_checkout', '_wpnonce' => agumail_admin_nonce('save_checkout')), $fields));
            do_action('admin_post_agumail');
        });
    };
    $render = function () {
        ob_start();
        agumail_admin_render_checkout();
        return ob_get_clean();
    };
    $html = $render();
    expect_that(strpos($html, 'Tick the box by default') !== false, 'the setting is missing from the settings page');
    expect_that(strpos($html, 'Links show on the classic checkout and registration forms. The block checkout shows the words only.') !== false, 'the label\'s help text does not say where links show');
    expect_that(!preg_match('/name="agumail_checkout_optin_default"[^>]*checked/', $html), 'the setting shows on out of the box');

    $save(array('agumail_consent_label' => "Email me.\n See <a href=\"https://shop.test/privacy\" style=\"color:red\">our policy</a> <em>now</em>", 'agumail_checkout_optin_default' => 'yes'));
    expect_same('Email me. See <a href="https://shop.test/privacy">our policy</a> now', get_option('agumail_consent_label'), 'the label was not saved with its link alone');
    expect_same('yes', get_option('agumail_checkout_optin_default'), 'ticking the box by default was not saved');
    $html = $render();
    expect_that((bool) preg_match('/name="agumail_checkout_optin_default"[^>]*checked/', $html), 'the saved setting does not show as on');
    expect_that(strpos($html, 'value="Email me. See &lt;a href=&quot;https://shop.test/privacy&quot;&gt;our policy&lt;/a&gt; now"') !== false, 'the label with its link is not shown safely in the field: ' . $html);

    $result = $save(array('agumail_consent_label' => 'Email me.'));
    expect_same('no', get_option('agumail_checkout_optin_default'), 'unticking the setting was not saved');
    expect_same(array(), $result['http'], 'saving the checkout settings called AguMail');
}

function test_consent_the_registration_box_stays_unticked_when_the_checkout_box_starts_ticked() {
    consent_tick_by_default();
    update_option('agumail_consent_label', 'Email me. <a href="https://shop.test/privacy">Privacy</a>');
    foreach (array('woocommerce_register_form', 'register_form') as $hook) {
        ob_start();
        do_action($hook);
        $html = ob_get_clean();
        expect_that(strpos($html, 'name="agumail_optin"') !== false, "$hook shows no marketing checkbox");
        expect_that(!consent_ticked($html), "$hook ticks the box because the checkout's starts ticked");
        expect_that(strpos($html, '<a href="https://shop.test/privacy">Privacy</a>') !== false, "$hook lost the label's link: $html");
    }
}

// ── Hiding the box ──

function test_consent_the_filter_hides_the_box_on_both_checkouts_and_nothing_is_recorded() {
    add_filter('agumail_show_checkout_optin', '__return_false');
    expect_same('', consent_render_classic(), 'the classic checkbox was rendered while hidden');
    do_action('woocommerce_init');
    expect_same(array(), $GLOBALS['agm']['fields'], 'the block checkbox was registered while hidden');
    expect_same(null, agumail_order_optin_answer(consent_submit_classic(array())), 'a hidden classic box recorded an answer');
    expect_same(null, agumail_order_optin_answer(consent_block_request('POST', array('agumail/optin' => true))), 'a hidden block box recorded an answer');
}

function test_consent_a_store_not_connected_shows_no_box_and_records_nothing() {
    $key = $GLOBALS['agm']['options']['agumail_api_key'];
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    expect_same('', consent_render_classic(), 'the classic checkbox shows on a store not connected');
    do_action('woocommerce_init');
    expect_same(array(), $GLOBALS['agm']['fields'], 'the block checkbox is registered on a store not connected');
    expect_same(null, agumail_order_optin_answer(consent_submit_classic(array())), 'a store not connected recorded a classic answer');
    expect_same(null, agumail_order_optin_answer(consent_block_request('POST', array('agumail/optin' => true))), 'a store not connected recorded a block answer');

    $GLOBALS['agm']['options']['agumail_api_key'] = $key;
    expect_that(strpos(consent_render_classic(), 'name="agumail_optin"') !== false, 'connecting the store did not show the box');
}

// ── Reading it back ──

function test_consent_only_a_yes_counts_as_opted_in() {
    $order = consent_order();
    foreach (array('yes' => true, 'no' => false, '' => false, '1' => false) as $meta => $opted_in) {
        $order->update_meta_data('_agumail_optin', $meta);
        expect_same($opted_in, agumail_order_opted_in($order), "meta '$meta' read wrong");
    }
    expect_same(false, agumail_order_opted_in(false), 'a missing order counted as opted in');
}

// ── The label setting ──

function test_consent_the_label_is_saved_from_the_settings_page() {
    agm_grant('manage_woocommerce');
    do_action('init');
    $save = function ($label) {
        return agm_request('save checkout settings', 'admin', function () use ($label) {
            agm_input(array(), array('action' => 'agumail', 'agumail_action' => 'save_checkout', '_wpnonce' => agumail_admin_nonce('save_checkout'), 'agumail_consent_label' => $label));
            do_action('admin_post_agumail');
        });
    };
    $result = $save('  Yes, <b>email</b> me  ');
    expect_same('Yes, email me', get_option('agumail_consent_label'), 'the label was not saved clean');
    expect_same('https://shop.test/wp-admin/admin.php?page=agumail', $GLOBALS['agm']['redirects'][0]['location'], 'the merchant was not sent back to the settings page');
    expect_same(array('type' => 'success', 'message' => 'Checkout settings saved.'), get_transient('agumail_notice_0'), 'no notice was kept for the next page view');
    expect_same(array(), $result['http'], 'saving a label called AguMail');
    $save('');
    expect_same(false, get_option('agumail_consent_label'), 'an empty label should fall back to the default');
    expect_same('Email me about new products and offers', agumail_consent_label(), 'wrong default label');
}

function test_consent_every_placeholder_string_in_the_foundation_has_a_translators_comment($plugin_dir) {
    $missing = array();
    foreach (array('agumail.php', 'uninstall.php', 'includes/settings.php', 'includes/log.php', 'includes/store.php', 'includes/class-agumail-store.php', 'includes/install.php',
        'includes/http.php', 'includes/payloads.php', 'includes/drain.php', 'includes/consent.php', 'includes/admin.php') as $file) {
        $lines = file($plugin_dir . '/' . $file);
        foreach ($lines as $i => $line) {
            if (!preg_match('/\b(__|_n|_x|esc_html__|esc_attr__)\(\s*\'[^\']*%(\d\$)?[sd]/', $line)) {
                continue;
            }
            if (strpos(implode('', array_slice($lines, max(0, $i - 2), min(3, $i + 1))), 'translators:') === false) {
                $missing[] = $file . ':' . ($i + 1);
            }
        }
    }
    expect_same(array(), $missing, 'strings with placeholders but no translators comment');
}
