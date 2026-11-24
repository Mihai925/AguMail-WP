<?php
// Personal data: Tools > Export Personal Data and Erase Personal Data find
// what the plugin keeps on an address, and erase its carts and the address it
// held, keeping the marketing answer as the record of consent.

function privacy_customer_with_a_cart() {
    agm_add_user(7, 'ada@example.com', 'Ada', 'Lovelace');
    update_user_meta(7, '_agumail_email', 'ada@example.com');
    update_user_meta(7, '_agumail_optin', 'yes');
    update_user_meta(7, '_agumail_optin_at', '2026-01-02T03:04:05Z');
    agumail_cart_save('wc-ada', array('token' => str_repeat('a', 32), 'email' => 'ada@example.com', 'total' => 42, 'currency' => 'EUR'));
    agumail_cart_save('wc-bea', array('token' => str_repeat('b', 32), 'email' => 'bea@example.com'));
}

function test_privacy_registers_an_exporter_and_an_eraser() {
    $exporters = apply_filters('wp_privacy_personal_data_exporters', array());
    $erasers   = apply_filters('wp_privacy_personal_data_erasers', array());
    expect_same('agumail_privacy_export', $exporters['agumail']['callback'], 'the exporter was not registered');
    expect_same('agumail_privacy_erase', $erasers['agumail']['callback'], 'the eraser was not registered');
}

function test_privacy_exports_the_account_answer_and_the_carts() {
    privacy_customer_with_a_cart();
    $export = agumail_privacy_export('ada@example.com', 1);
    expect_that($export['done'], 'the export did not finish');
    $groups = array_column($export['data'], 'group_id');
    expect_same(array('agumail', 'agumail-carts'), $groups, 'the export did not hold the account and its one cart');
    $values = array_column($export['data'][0]['data'], 'value');
    expect_same(array('ada@example.com', 'yes', '2026-01-02T03:04:05Z'), $values, 'the account data was not exported');
    expect_same('agumail-cart-wc-ada', $export['data'][1]['item_id'], 'another shopper\'s cart was exported');
}

function test_privacy_exports_nothing_for_an_unknown_address() {
    privacy_customer_with_a_cart();
    expect_same(array(), agumail_privacy_export('nobody@example.com', 1)['data'], 'an unknown address exported data');
}

function test_privacy_erases_the_carts_and_held_address_and_keeps_the_answer() {
    privacy_customer_with_a_cart();
    $result = agumail_privacy_erase('ada@example.com', 1);
    expect_that($result['done'], 'the erasure did not finish');
    expect_that($result['items_removed'], 'nothing was reported removed');
    expect_that($result['items_retained'], 'the kept answer was not reported');
    expect_same(null, agumail_cart_get('wc-ada'), 'the cart was not erased');
    expect_that(null !== agumail_cart_get('wc-bea'), 'another shopper\'s cart was erased');
    expect_same('', get_user_meta(7, '_agumail_email', true), 'the held address was not erased');
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the record of consent was erased');
}
