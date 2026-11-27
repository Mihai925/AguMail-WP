<?php
// Products: the catalogue AguMail's emails pick from. A product is queued once
// WooCommerce has finished saving it, and only when something AguMail shows
// changed; the drain builds the record from the product as it is then.

function products_catalogue() {
    $GLOBALS['agm']['terms'][11]['product_cat'] = array((object) array('name' => 'Mugs'), (object) array('name' => 'Home &amp; Garden'));
    return array(
        'mug'  => agm_catalogue_product(11, array('name' => 'Mug', 'price' => '30', 'regular_price' => '30', 'sku' => 'MUG-1', 'image_id' => 501, 'manage_stock' => true, 'stock_quantity' => 5)),
        'tee'  => agm_catalogue_product(20, array('name' => 'Tee', 'price' => '8', 'regular_price' => '', 'sku' => 'TEE'), 'Agm_Variable_Product'),
        'blue' => agm_catalogue_product(21, array('name' => 'Tee - Blue', 'price' => '15', 'regular_price' => '15', 'sku' => 'TEE-BLUE', 'parent_id' => 20, 'manage_stock' => true, 'stock_quantity' => 3), 'Agm_Variation_Product'),
        'red'  => agm_catalogue_product(22, array('name' => 'Tee - Red', 'price' => '8', 'regular_price' => '8', 'sku' => 'TEE-RED', 'parent_id' => 20), 'Agm_Variation_Product'),
    );
}

function products_sent() {
    return agm_sent_records('/api/v1/products', 'products');
}

function products_last_sent($id) {
    $found = null;
    foreach (products_sent() as $record) {
        if ($record['externalId'] === (string) $id) {
            $found = $record;
        }
    }
    return $found;
}

function products_queued_ids() {
    return array_map(function ($row) { return $row['object_key']; }, agm_outbox('product'));
}

// ── Edits that sync ──

function test_products_an_admin_edit_sends_the_new_price_and_sku() {
    $products = products_catalogue();
    agm_request('admin: edit the mug', 'admin', function () use ($products) {
        agm_admin_edit_product($products['mug'], array('name' => 'Big mug'), array('regular_price' => '25', 'price' => '25', 'sku' => 'MUG-2'));
    });
    agm_drain();
    $sent = products_last_sent(11);
    expect_that($sent !== null, 'the edited product was not sent');
    expect_same(array('Big mug', 25.0, 'MUG-2'), array($sent['title'], $sent['price'], $sent['sku']),
        'the product went with what it held before WooCommerce saved it (the save_post_product bug)');
    expect_same(1, count(agm_http_requests('/api/v1/products')), 'one edit should be one request');
}

function test_products_the_full_record() {
    products_catalogue();
    agm_request('admin: update', 'admin', function () {
        agm_admin_edit_product(wc_get_product(11), array(), array());
    });
    agm_drain();
    expect_same(array(
        'externalId' => '11',
        'title'      => 'Mug',
        'price'      => 30.0,
        'currency'   => 'EUR',
        'imageUrl'   => 'https://shop.test/img/501.jpg',
        'productUrl' => 'https://shop.test/?p=11',
        'categories' => array('Mugs', 'Home & Garden'),
        'options'    => array(),
        'status'     => 'active',
        'sku'        => 'MUG-1',
        'source'     => 'woocommerce',
    ), products_last_sent(11), 'wrong product record');
}

function test_products_what_wordpress_saves_itself_syncs_too() {
    $products = products_catalogue();
    // Title, status, categories and image are written by WordPress before WooCommerce loads the product, so they never show in get_changes().
    agm_request('admin: rename, recategorise, new image', 'admin', function () use ($products) {
        agm_admin_edit_product($products['mug'], array('name' => 'Stoneware mug', 'image_id' => 777), array(), array('Kitchen'));
    });
    agm_request('admin: quick edit to draft', 'admin', function () use ($products) {
        $before = agm_product_post($products['mug']);
        $products['mug']->props['status'] = 'draft';
        do_action('wp_after_insert_post', 11, agm_product_post($products['mug']), true, $before);
    });
    agm_drain();
    $sent = products_last_sent(11);
    expect_same(array('Stoneware mug', array('Kitchen'), 'https://shop.test/img/777.jpg', 'archived'),
        array($sent['title'], $sent['categories'], $sent['imageUrl'], $sent['status']), 'changes WordPress saved did not sync');
}

function test_products_a_rest_or_code_price_change_syncs() {
    $products = products_catalogue();
    $result = agm_request('REST: PUT /wc/v3/products/11 {regular_price: 22}', 'shopper_rest', function () use ($products) {
        agm_save_product($products['mug'], array('regular_price' => '22'));
    });
    expect_same(array('product:11'), array_column($result['writes'], 'name'), 'a price-only change through the REST API was not queued');
    // WooCommerce sets price itself after the write when regular or sale price changed.
    $products['mug']->props['price'] = '22';
    agm_drain();
    expect_same(22.0, products_last_sent(11)['price'], 'the new price was not sent');
}

function test_products_a_scheduled_sale_syncs_from_cron() {
    $products = products_catalogue();
    agm_request('wc_scheduled_sales', 'cron', function () use ($products) {
        agm_save_product($products['mug'], array('price' => '20', 'sale_price' => '20', 'date_on_sale_from' => null));
    });
    agm_drain();
    expect_same(20.0, products_last_sent(11)['price'], 'a sale that started on schedule did not sync');
}

function test_products_every_synced_field_counts_and_nothing_else_does() {
    $fields = array('name' => 'X', 'regular_price' => '1', 'sale_price' => '1', 'price' => '1', 'date_on_sale_from' => '2026-10-01',
        'date_on_sale_to' => '2026-10-09', 'sku' => 'X-1', 'image_id' => 9, 'status' => 'private', 'category_ids' => array(3), 'slug' => 'x',
        'attributes' => array('size' => 'L'));
    $id = 100;
    foreach ($fields as $field => $value) {
        $product = agm_catalogue_product(++$id);
        $result = agm_request("change $field", 'admin', function () use ($product, $field, $value) {
            agm_save_product($product, array($field => $value));
        });
        expect_same(array('product:' . $id), array_column($result['writes'], 'name'), "a change to $field was not queued");
    }
    foreach (array('catalog_visibility' => 'hidden') as $field => $value) {
        $product = agm_catalogue_product(++$id);
        $result = agm_request("change $field", 'admin', function () use ($product, $field, $value) {
            agm_save_product($product, array($field => $value));
        });
        expect_same(array('product:' . $id), array_column($result['writes'], 'name'), "a change to $field was not queued");
    }
    foreach (array('stock_quantity' => 2, 'stock_status' => 'outofstock', 'total_sales' => 9, 'rating_counts' => array(5 => 1),
        'average_rating' => '5.00', 'review_count' => 1, 'date_modified' => '2026-09-28') as $field => $value) {
        $product = agm_catalogue_product(++$id);
        $result = agm_request("change $field", 'admin', function () use ($product, $field, $value) {
            agm_save_product($product, array($field => $value));
        });
        expect_same(array(), $result['writes'], "a change to $field alone was queued");
    }
}

function test_products_a_new_product_is_queued() {
    $product = new Agm_Simple_Product(0, array('name' => 'Candle', 'price' => '12', 'regular_price' => '12', 'sku' => 'CAN-1'));
    agm_request('REST: POST /wc/v3/products', 'shopper_rest', function () use ($product) {
        agm_save_product($product, array('name' => 'Candle', 'regular_price' => '12'));
    });
    expect_same(array((string) $product->get_id()), products_queued_ids(), 'a new product was not queued');
    agm_drain();
    expect_same('Candle', products_last_sent($product->get_id())['title'], 'the new product was not sent');
}

// ── Variations ──

function test_products_a_variation_change_queues_its_parent() {
    $products = products_catalogue();
    $result = agm_request('admin: save variations', 'admin', function () use ($products) {
        agm_save_product($products['red'], array('regular_price' => '6', 'price' => '6'));
    });
    expect_same(array('product:20'), array_column($result['writes'], 'name'), 'a variation price change did not queue its parent');
    // WooCommerce re-syncs the parent's stored lowest price at the end of the request.
    $products['tee']->props['price'] = '6';
    agm_drain();
    $sent = products_sent();
    expect_same(array('20'), array_column($sent, 'externalId'), 'a variation was sent on its own, or its parent was not');
    expect_same(6.0, $sent[0]['price'], 'a variable product should go with its lowest price');

    $result = agm_request('a new variation', 'admin', function () use ($products) {
        $green = new Agm_Variation_Product(0, array('name' => 'Tee - Green', 'price' => '9', 'regular_price' => '9', 'parent_id' => 20));
        agm_save_product($green, array('regular_price' => '9'));
    });
    expect_same(array('product:20'), array_column($result['writes'], 'name'), 'a new variation did not queue its parent');

    $result = agm_request('a variation stock change', 'admin', function () use ($products) {
        agm_save_product($products['blue'], array('stock_quantity' => 2));
    });
    expect_same(array(), $result['writes'], 'a variation stock change queued its parent');
}

function test_products_a_variable_product_goes_with_its_lowest_stored_price() {
    products_catalogue();
    agm_request('admin: update the tee', 'admin', function () {
        agm_admin_edit_product(wc_get_product(20), array(), array());
    });
    agm_drain();
    expect_same(array(8.0, 'TEE'), array(products_last_sent(20)['price'], products_last_sent(20)['sku']), 'wrong price or SKU for a variable product');
}

function products_painting() {
    agm_catalogue_product(411, array('name' => 'Painting', 'price' => '34.99', 'children' => array(414, 415, 416)), 'Agm_Variable_Product');
    agm_catalogue_product(414, array('name' => 'Painting', 'price' => '34.99', 'sku' => 'PC-8X10', 'image_id' => 9, 'parent_id' => 411,
        'attributes' => array('attribute_pa_type' => 'Canvas', 'attribute_pa_size' => '8x10')), 'Agm_Variation_Product');
    agm_catalogue_product(415, array('name' => 'Painting', 'price' => '89.99', 'sku' => '', 'image_id' => 0, 'parent_id' => 411,
        'attributes' => array('attribute_pa_type' => 'Canvas', 'attribute_pa_size' => '16x20')), 'Agm_Variation_Product');
    // Switched off in the product editor: the shopper cannot pick it.
    agm_catalogue_product(416, array('name' => 'Painting', 'price' => '120', 'status' => 'private', 'parent_id' => 411,
        'attributes' => array('attribute_pa_type' => 'Framed', 'attribute_pa_size' => '24x36')), 'Agm_Variation_Product');
}

function test_products_a_variable_product_goes_with_the_options_a_shopper_can_pick() {
    // So a product view naming one can be checked against the catalogue for
    // every shopper, whoever named them.
    products_painting();
    agm_request('admin: update the painting', 'admin', function () {
        agm_admin_edit_product(wc_get_product(411), array(), array());
    });
    agm_drain();
    expect_same(array(
        array('externalId' => '414', 'title' => 'Canvas, 8x10', 'sku' => 'PC-8X10', 'price' => 34.99, 'imageUrl' => 'https://shop.test/img/9.jpg', 'productUrl' => 'https://shop.test/?p=414'),
        array('externalId' => '415', 'title' => 'Canvas, 16x20', 'price' => 89.99, 'productUrl' => 'https://shop.test/?p=415'),
    ), products_last_sent(411)['options'], 'wrong options');
}

function test_products_a_product_sends_at_most_a_hundred_options() {
    $children = array();
    for ($i = 1; $i <= 101; $i++) {
        $children[] = 1000 + $i;
        agm_catalogue_product(1000 + $i, array('price' => '10', 'parent_id' => 900, 'attributes' => array('size' => 'S' . $i)), 'Agm_Variation_Product');
    }
    agm_catalogue_product(900, array('name' => 'Poster', 'price' => '10', 'children' => $children), 'Agm_Variable_Product');
    agm_request('admin: update the poster', 'admin', function () {
        agm_admin_edit_product(wc_get_product(900), array(), array());
    });
    agm_drain();
    expect_same(100, count(products_last_sent(900)['options']), 'more options were sent than AguMail takes');
}

function test_products_a_renamed_option_queues_its_product() {
    products_painting();
    $result = agm_request('admin: rename a size', 'admin', function () {
        agm_save_product(wc_get_product(414), array('attributes' => array('attribute_pa_type' => 'Canvas', 'attribute_pa_size' => '8 x 10')));
    });
    expect_same(array('product:411'), array_column($result['writes'], 'name'), 'renaming an option did not queue its product');
}

function test_products_an_update_sends_products_with_options_once_so_the_catalogue_learns_them() {
    // Stores connected before options were sent hold their products without
    // them until each is sent again.
    products_catalogue();
    products_painting();
    delete_option('agumail_catalogue_version');
    delete_transient('agumail_maintained');
    agm_grant('manage_woocommerce');
    agm_request('the first admin page after the update', 'admin', function () { do_action('admin_init'); });
    expect_same(array('20', '411'), products_queued_ids(), 'the products with options were not queued');
    agm_drain();
    delete_transient('agumail_maintained');
    agm_request('the next admin page', 'admin', function () { do_action('admin_init'); });
    expect_same(array(), products_queued_ids(), 'the products were queued again');
}

// ── Checkout ──

function test_products_a_stock_only_save_during_checkout_queues_nothing() {
    $products = products_catalogue();
    $order = agm_order(501, array('status' => 'processing', 'date_paid' => agm_date(time())), array(array($products['mug'], 1), array($products['blue'], 1)));
    $result = agm_request('checkout: stock leaves the shelf', 'shopper', function () use ($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            do_action('woocommerce_product_before_set_stock', $product);
            agm_save_product($product, array('stock_quantity' => (int) $product->get_stock_quantity() - 1, 'stock_status' => 'instock'));
            do_action('woocommerce_product_set_stock', $product);
        }
        // The deferred sync of the variable parent at the end of the request: stock status only.
        agm_save_product(wc_get_product(20), array('stock_status' => 'instock'));
        do_action('woocommerce_reduce_order_stock', $order);
    });
    agm_expect_shopper_quiet($result);
    expect_same(array(), $result['writes'], 'a stock-only save wrote something during checkout');
    agm_drain();
    expect_same(array(), products_sent(), 'a stock-only save was sent');
}

function test_products_nothing_listens_to_save_post_product() {
    expect_same(false, has_action('save_post_product'), 'save_post_product fires before WooCommerce saves prices and SKUs, so nothing may sync from it');
}

// ── Trash, restore, delete ──

function test_products_the_trash_archives_a_product_and_a_restore_brings_it_back() {
    $products = products_catalogue();
    agm_request('admin: trash', 'admin', function () use ($products) { agm_trash_product($products['mug']); });
    agm_drain();
    expect_same('archived', products_last_sent(11)['status'], 'a trashed product should be archived');
    agm_request('admin: restore', 'admin', function () use ($products) { agm_untrash_product($products['mug']); });
    agm_drain();
    expect_same('active', products_last_sent(11)['status'], 'a restored product should be active again');
}

function test_products_a_deleted_product_is_sent_archived_from_a_record_stored_at_once() {
    $products = products_catalogue();
    agm_request('admin: delete the tee for good', 'admin', function () use ($products) {
        agm_delete_product($products['tee']);
    });
    expect_same(false, wc_get_product(20), 'the product should be gone');
    $rows = agm_outbox('product');
    expect_same(array('20'), products_queued_ids(), 'deleting a variable product should queue it, and only it');
    expect_that(isset($rows[0]['payload']['record']), 'the record was not stored while the product still existed');
    agm_drain();
    $sent = products_last_sent(20);
    expect_same(array('Tee', 'archived'), array($sent['title'], $sent['status']), 'the deleted product was not sent archived');
}

function test_products_trashing_or_deleting_a_variation_updates_its_parent() {
    $products = products_catalogue();
    $result = agm_request('admin: remove a variation', 'admin', function () use ($products) {
        agm_delete_product($products['red']);
    });
    expect_same(array('20'), products_queued_ids(), 'removing a variation should queue its parent');
    expect_that(!isset(agm_outbox('product')[0]['payload']['record']), 'the parent was queued as deleted');
    agm_drain();
    expect_same('active', products_last_sent(20)['status'], 'the parent should still be active');
}

function test_products_auto_drafts_and_other_posts_are_ignored() {
    products_catalogue();
    $draft = agm_catalogue_product(30, array('name' => 'AUTO-DRAFT', 'status' => 'auto-draft'));
    agm_request('admin: Add New', 'admin', function () use ($draft) {
        do_action('wp_after_insert_post', 30, agm_product_post($draft), false, null);
        do_action('wp_after_insert_post', 31, new Agm_Post(array('ID' => 31, 'post_type' => 'page')), true, null);
        do_action('trashed_post', 32, 'publish');
        do_action('before_delete_post', 33, new Agm_Post(array('ID' => 33, 'post_type' => 'post')));
    });
    // WordPress deletes week-old auto-drafts every day (wp_delete_auto_drafts).
    agm_request('wp_scheduled_auto_draft_delete', 'cron', function () use ($draft) {
        agm_delete_product($draft);
    });
    expect_same(array(), agm_outbox(), 'an auto-draft or a post that is not a product was queued');
}

function test_products_the_drain_drops_a_product_that_no_longer_exists() {
    products_catalogue();
    agumail_enqueue('product', 999);
    agm_drain();
    expect_same(array(), products_sent(), 'a product that no longer exists was sent');
    expect_same(array(), agm_outbox('product'), 'its row stayed queued');
}

// ── Limits ──

function test_products_the_record_is_clamped_to_what_agumail_accepts() {
    $categories = array();
    for ($i = 1; $i <= 30; $i++) {
        $categories[] = (object) array('name' => ($i === 1 ? str_repeat('Long ', 30) : 'Category ' . $i));
    }
    $GLOBALS['agm']['terms'][40]['product_cat'] = $categories;
    $product = agm_catalogue_product(40, array('name' => str_repeat('Handmade stoneware ', 30), 'price' => '', 'sku' => str_repeat('S', 150)));
    agm_request('admin: update', 'admin', function () use ($product) { agm_admin_edit_product($product, array(), array()); });
    agm_drain();
    $sent = products_last_sent(40);
    expect_that(strlen($sent['title']) <= 300, 'a title longer than 300 characters was sent');
    expect_same(100, strlen($sent['sku']), 'the SKU was not clamped to 100 characters');
    expect_same(20, count($sent['categories']), 'more than 20 categories were sent');
    expect_that(strlen($sent['categories'][0]) <= 100, 'a category name longer than 100 characters was sent');
    expect_that(!array_key_exists('price', $sent), 'a product with no price was sent a price');
    expect_same('', $sent['imageUrl'], 'a product with no image should say so, or the image it had stays in AguMail');
}

// ── Saves WooCommerce writes through the post ──

function test_products_a_variation_post_rewritten_during_a_stock_save_queues_nothing() {
    $products = products_catalogue();
    $result = agm_request('checkout: a variation\'s stock, its title regenerated', 'shopper', function () use ($products) {
        agm_save_product($products['blue'], array('stock_quantity' => 2), true);
    });
    agm_expect_shopper_quiet($result);
    expect_same(array(), $result['writes'], 'the post a stock save rewrote queued the parent');
}

function test_products_a_rename_through_code_queues_once_the_save_is_done() {
    $products = products_catalogue();
    $result = agm_request('REST: rename the mug', 'shopper_rest', function () use ($products) {
        agm_save_product($products['mug'], array('name' => 'Cup'));
    });
    expect_same(array('product:11'), array_column($result['writes'], 'name'), 'a rename should be queued once, after its meta is written');
}

// ── What the record clears ──

function test_products_a_removed_image_sku_or_price_does_not_linger() {
    $products = products_catalogue();
    agm_request('admin: update', 'admin', function () use ($products) {
        agm_admin_edit_product($products['mug'], array(), array());
    });
    agm_drain();
    expect_same(array('https://shop.test/img/501.jpg', 'MUG-1', 30.0), array(products_last_sent(11)['imageUrl'], products_last_sent(11)['sku'], products_last_sent(11)['price']), 'setup');
    agm_request('admin: remove the image, SKU and price', 'admin', function () use ($products) {
        agm_admin_edit_product($products['mug'], array('image_id' => 0), array('sku' => '', 'regular_price' => '', 'price' => ''));
    });
    agm_drain();
    $sent = products_last_sent(11);
    // AguMail's schema takes a string for both, and an empty one reads as none; a price can only be left out.
    expect_same(array('', ''), array($sent['imageUrl'], $sent['sku']), 'a removed image or SKU was left out, so AguMail keeps the old one');
    expect_that(!array_key_exists('price', $sent), 'a product with no price was sent one');
}

function test_products_titles_are_plain_text() {
    $product = agm_catalogue_product(41, array('name' => 'Tom &amp; Jerry&#8217;s mug'));
    agm_request('admin: update', 'admin', function () use ($product) { agm_admin_edit_product($product, array(), array()); });
    agm_drain();
    expect_same("Tom & Jerry\u{2019}s mug", products_last_sent(41)['title'], 'a title went with its HTML entities');
}

function test_products_a_hidden_product_is_archived() {
    $products = products_catalogue();
    agm_request('admin: hide from the catalogue', 'admin', function () use ($products) {
        agm_save_product($products['mug'], array('catalog_visibility' => 'hidden'));
    });
    agm_drain();
    expect_same('archived', products_last_sent(11)['status'], 'a product hidden from the catalogue should not be offered in emails');
    foreach (array('catalog', 'search', 'visible') as $visibility) {
        $products['mug']->props['catalog_visibility'] = $visibility;
        expect_same('active', agumail_product_record($products['mug'])['status'], "a product visible in $visibility should stay active");
    }
}

function test_products_renaming_a_category_queues_the_products_filed_there() {
    products_catalogue();
    $GLOBALS['agm']['terms'][11]['product_cat'] = array((object) array('term_id' => 5, 'name' => 'Mugs'));
    $GLOBALS['agm']['terms'][20]['product_cat'] = array((object) array('term_id' => 5, 'name' => 'Mugs'), (object) array('term_id' => 6, 'name' => 'Tees'));
    $GLOBALS['agm']['terms'][30]['product_cat'] = array((object) array('term_id' => 6, 'name' => 'Tees'));
    agm_catalogue_product(30, array('name' => 'Sock'));
    $result = agm_request('admin: rename Mugs to Cups', 'admin', function () {
        $GLOBALS['agm']['terms'][11]['product_cat'][0]->name = 'Cups';
        $GLOBALS['agm']['terms'][20]['product_cat'][0]->name = 'Cups';
        do_action('edited_product_cat', 5, 5, array('name' => 'Cups'));
    });
    expect_same(array('product:11', 'product:20'), array_column($result['writes'], 'name'), 'the products filed under the renamed category were not queued (or others were)');
    agm_drain();
    expect_same(array('Cups'), products_last_sent(11)['categories'], 'the new category name was not sent');
}

function test_products_a_category_renamed_after_a_delete_keeps_the_deleted_record() {
    $products = products_catalogue();
    $GLOBALS['agm']['terms'][11]['product_cat'] = array((object) array('term_id' => 5, 'name' => 'Mugs'));
    $GLOBALS['agm']['terms'][20]['product_cat'] = array((object) array('term_id' => 5, 'name' => 'Mugs'));
    agm_request('admin: delete the tee for good', 'admin', function () use ($products) {
        agm_delete_product($products['tee']);
    });
    $deleted = agm_outbox('product')[0];
    // The term relationship outlives the product here, as it can when a hook runs between the two deletes.
    $result = agm_request('admin: rename Mugs to Cups', 'admin', function () {
        do_action('edited_product_cat', 5, 5, array('name' => 'Cups'));
    });
    expect_same(array('product:11'), array_column($result['writes'], 'name'), 'only the product still there should be written');
    $rows = array();
    foreach (agm_outbox('product') as $row) {
        $rows[$row['object_key']] = $row;
    }
    expect_same($deleted, $rows['20'], 'the row holding the deleted product\'s record was changed');
    expect_same(null, $rows['11']['payload'], 'the product still there was not queued to be built at send time');
    agm_drain();
    expect_same('archived', products_last_sent(20)['status'], 'the deleted product was not sent archived');
}

function test_products_the_real_store_queues_a_saved_product_in_one_statement_without_a_lookup() {
    global $wpdb;
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    expect_that(agumail_product_queue(11), 'the product was not queued');
    $sql = $wpdb->statements();
    expect_same(1, count($sql), 'queueing a product should be one statement: ' . implode(' | ', $sql));
    expect_that(strpos($sql[0], "INSERT INTO wp_agumail_outbox") === 0 && strpos($sql[0], "VALUES ('product', '11', NULL, 1, 0, 'pending'") !== false, 'wrong upsert: ' . $sql[0]);
    expect_that(strpos($sql[0], 'ON DUPLICATE KEY UPDATE `version` = IF(`payload` IS NULL, `version` + 1, `version`), `status` = IF(`payload` IS NULL, \'pending\', `status`)') !== false,
        'the upsert would change a row holding a deleted product\'s record: ' . $sql[0]);
    expect_that(strpos($sql[0], '`payload` =') === false && strpos($sql[0], '`attempts` =') === false, 'the upsert must leave the payload and attempts alone: ' . $sql[0]);
}

function test_products_the_real_store_queues_a_renamed_category_in_batches_of_200() {
    global $wpdb;
    for ($id = 1001; $id <= 1450; $id++) {
        $GLOBALS['agm']['terms'][$id]['product_cat'] = array((object) array('term_id' => 5, 'name' => 'Mugs'));
    }
    agumail_set_store(new Agumail_Store());
    $wpdb->queries = array();
    do_action('edited_product_cat', 5, 5, array('name' => 'Cups'));
    $sql = $wpdb->statements();
    expect_same(3, count($sql), '450 products should take three statements: ' . count($sql));
    expect_same(array(200, 200, 50), array_map(function ($statement) { return substr_count($statement, "('product', '"); }, $sql), 'wrong batch sizes');
    expect_that(strpos($sql[0], "('product', '1001', NULL, 1, 0, 'pending'") !== false && strpos($sql[2], "('product', '1450', NULL, 1, 0, 'pending'") !== false, 'a product was left out');
    foreach ($sql as $statement) {
        expect_that(strpos($statement, 'IF(`payload` IS NULL, `version` + 1, `version`)') !== false, 'a batch would overwrite a deleted product\'s record: ' . substr($statement, -300));
    }
}
