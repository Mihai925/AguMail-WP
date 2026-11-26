<?php
// Lane P4 (customers, bulk sync, status): the store's tables as SQL sees them.
//
// The bulk sync and the reverse sync page through users, products and orders
// by id with plain SELECTs. The answers here read the simulated store (agm
// users and their meta, products, orders and their meta), so a scenario sets
// up a store and the plugin's queries find it. OrderUtil stands in for
// WooCommerce's switch between the order tables (HPOS, on when
// $GLOBALS['agm']['hpos'] is set) and orders stored as posts.
//
// This file sorts before wc.php and wpdb.php, so it loads both first.

namespace Automattic\WooCommerce\Utilities {
    if (!class_exists(__NAMESPACE__ . '\OrderUtil')) {
        class OrderUtil {
            public static function custom_orders_table_usage_is_enabled() {
                return !empty($GLOBALS['agm']['hpos']);
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/wc.php';
    require_once __DIR__ . '/wpdb.php';

    /** The core fake with the users table WordPress names on $wpdb, which the core fake leaves out. */
    class Agm_P4_WPDB extends Agm_Fake_WPDB {
        public $users = 'wp_users';
    }
    $GLOBALS['wpdb'] = new Agm_P4_WPDB();
    $GLOBALS['wpdb']->answers['/^SELECT .* FROM wp_(?:\d+_)?(?:users|posts|postmeta|wc_orders)\b/'] = 'agm_p4_sql';

    // ── The store ──

    /** A registered user: $props sets user_registered and roles; $meta their user meta. */
    function agm_p4_user($id, $email, array $props = array(), array $meta = array()) {
        $user = agm_add_user($id, $email, isset($props['first_name']) ? $props['first_name'] : '', isset($props['last_name']) ? $props['last_name'] : '');
        foreach (array('user_registered', 'roles') as $prop) {
            if (isset($props[$prop])) {
                $user->$prop = $props[$prop];
            }
        }
        foreach ($meta as $key => $value) {
            $GLOBALS['agm']['user_meta'][$id][$key] = $value;
        }
        return $user;
    }

    /** An order already in the store, placed at $created ('Y-m-d H:i:s' UTC) and paid then unless its status says otherwise. */
    function agm_p4_order($id, $email, $created, $status = 'processing', array $props = array(), array $meta = array()) {
        $at = strtotime($created . ' UTC');
        $paid = in_array($status, array('processing', 'completed', 'refunded'), true);
        $order = agm_order($id, array_merge(array(
            'status'             => $status,
            'billing_email'      => $email,
            'billing_first_name' => '',
            'billing_last_name'  => '',
            'date_created' => agm_date($at),
            'date_paid'    => $paid ? agm_date($at) : null,
            'total'        => '30',
        ), $props));
        foreach ($meta as $key => $value) {
            $order->meta[$key] = $value;
        }
        return $order;
    }

    // ── Answers ──

    /**
     * Answers the plugin's queries. $GLOBALS['agm']['p4_db_errors'] maps a
     * pattern to how many times a matching query fails, as MySQL would: no
     * rows, and $wpdb->last_error set.
     */
    function agm_p4_sql($sql) {
        foreach (isset($GLOBALS['agm']['p4_db_errors']) ? $GLOBALS['agm']['p4_db_errors'] : array() as $pattern => $times) {
            if ($times > 0 && preg_match($pattern, $sql)) {
                $GLOBALS['agm']['p4_db_errors'][$pattern]--;
                $GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
                return array();
            }
        }
        // MySQL 8 refuses to compare a date with '', even behind an OR that never reaches it; MariaDB only warns.
        if (preg_match("/_gmt\\s*(?:[<>]=?|<>|!?=)\\s*''/", $sql)) {
            $GLOBALS['wpdb']->last_error = "Incorrect DATETIME value: ''";
            return array();
        }
        if (preg_match('/^SELECT MAX\(ID\) FROM wp_users/', $sql)) {
            $ids = array_keys($GLOBALS['agm']['users']);
            return $ids ? (string) max($ids) : null;
        }
        if (preg_match('/^SELECT ID, user_email FROM wp_users /', $sql)) {
            return agm_p4_reverse_page($sql);
        }
        if (preg_match('/^SELECT (COUNT\(\*\)|u\.ID) FROM wp_users u /', $sql, $m)) {
            return agm_p4_users($sql, $m[1] === 'COUNT(*)');
        }
        if (preg_match("/^SELECT (COUNT\\(\\*\\)|p\\.ID) FROM wp_posts p WHERE p\\.post_type = 'product'/", $sql, $m)) {
            return agm_p4_products($sql, $m[1] === 'COUNT(*)');
        }
        if (preg_match('/^SELECT MAX\((?:p\.ID|o\.id)\)/', $sql)) {
            $ids = array_keys(agm_p4_shop_orders());
            return $ids ? (string) max($ids) : null;
        }
        if (preg_match('/^SELECT LOWER\(/', $sql)) {
            return agm_p4_guest_orders($sql);
        }
        if (preg_match('/ AS user_id, MIN\(/', $sql)) {
            return agm_p4_optin_orders($sql);
        }
        if (preg_match('/^SELECT (COUNT\\(\\*\\)|p\\.ID|o\\.id) FROM wp_(?:posts p|wc_orders o) /', $sql, $m) && strpos($sql, "'_agumail_optin'") !== false) {
            return agm_p4_ticked_orders($sql, $m[1] === 'COUNT(*)');
        }
        if (preg_match("/^SELECT (COUNT\\(\\*\\)|p\\.ID|o\\.id) FROM wp_(?:posts p|wc_orders o) .*'shop_order'/", $sql, $m)) {
            return agm_p4_orders($sql, $m[1] === 'COUNT(*)');
        }
        throw new Exception('The P4 SQL fake does not know this query: ' . $sql);
    }

    /** Every query that asks which store a table belongs to reads the same data: the table named tells HPOS from posts. */
    function agm_p4_expect_table($sql) {
        $hpos = strpos($sql, 'wc_orders') !== false;
        if ($hpos !== !empty($GLOBALS['agm']['hpos'])) {
            throw new Exception('Queried ' . ($hpos ? 'the order tables' : 'posts') . ' while HPOS is ' . (empty($GLOBALS['agm']['hpos']) ? 'off' : 'on') . ': ' . $sql);
        }
    }

    function agm_p4_after($sql, $column) {
        return preg_match('/' . preg_quote($column, '/') . ' > (\d+)/', $sql, $m) ? (int) $m[1] : 0;
    }

    function agm_p4_limit($sql) {
        return preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
    }

    /** The quoted values of the first "<column> IN (...)" list. */
    function agm_p4_in($sql, $column) {
        if (!preg_match('/' . preg_quote($column, '/') . ' IN \(([^)]*)\)/', $sql, $m)) {
            return array();
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|(\d+)/", $m[1], $values, PREG_SET_ORDER);
        return array_map(function ($value) { return isset($value[2]) && $value[2] !== '' ? $value[2] : stripslashes($value[1]); }, $values);
    }

    /** The quoted values of the first "<column> NOT IN (...)" list. */
    function agm_p4_not_in($sql, $column) {
        if (!preg_match('/' . preg_quote($column, '/') . ' NOT IN \(([^)]*)\)/', $sql, $m)) {
            return array();
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $values);
        return array_map('stripslashes', $values[1]);
    }

    function agm_p4_meta($id, $key) {
        return isset($GLOBALS['agm']['user_meta'][$id][$key]) ? (string) $GLOBALS['agm']['user_meta'][$id][$key] : '';
    }

    /** The user meta opt-in, as the plugin's SQL says it: yes, or the legacy key's yes when no answer of ours is stored. */
    function agm_p4_meta_opted_in($id) {
        $ours = agm_p4_meta($id, '_agumail_optin');
        if ($ours === 'yes') {
            return true;
        }
        return $ours === '' && in_array(agm_p4_meta($id, 'marketing_opt_in'), array('yes', '1'), true);
    }

    function agm_p4_reverse_page($sql) {
        $after = agm_p4_after($sql, 'ID');
        $rows = array();
        foreach (agm_p4_sorted_users() as $user) {
            $customer = array_intersect((array) $user->roles, array('customer')) || agm_p4_meta($user->ID, 'paying_customer') === '1';
            if ($user->ID > $after && $user->user_email !== '' && $customer) {
                $rows[] = array('ID' => (string) $user->ID, 'user_email' => $user->user_email);
            }
        }
        return array_slice($rows, 0, agm_p4_limit($sql));
    }

    function agm_p4_sorted_users() {
        $users = $GLOBALS['agm']['users'];
        ksort($users);
        return $users;
    }

    /** Phase one (opted in) or phase two (other customers: the customer role or a paying customer), by id. */
    function agm_p4_users($sql, $count) {
        $others = strpos($sql, 'AND NOT (') !== false;
        $after = agm_p4_after($sql, 'u.ID');
        if (!preg_match('/u\.ID <= (\d+)/', $sql, $bound)) {
            throw new Exception('An account page has no upper bound: ' . $sql);
        }
        $ids = array();
        foreach (agm_p4_sorted_users() as $user) {
            if ($user->ID <= $after || $user->ID > (int) $bound[1] || $user->user_email === '' || !$user->roles) {
                continue;
            }
            $customer = array_intersect((array) $user->roles, agumail_customer_roles()) || agm_p4_meta($user->ID, 'paying_customer') === '1';
            $opted = agm_p4_meta_opted_in($user->ID);
            if ($others ? ($customer && !$opted) : $opted) {
                $ids[] = (string) $user->ID;
            }
        }
        return $count ? (string) count($ids) : array_slice($ids, 0, agm_p4_limit($sql));
    }

    function agm_p4_products($sql, $count) {
        $after = agm_p4_after($sql, 'p.ID');
        $products = $GLOBALS['agm']['products'];
        ksort($products);
        $ids = array();
        foreach ($products as $id => $product) {
            if ($id > $after && !$product->is_type('variation') && $product->get_status() === 'publish') {
                $ids[] = (string) $id;
            }
        }
        return $count ? (string) count($ids) : array_slice($ids, 0, agm_p4_limit($sql));
    }

    /** Store orders (not refunds), by id. */
    /**
     * wc_get_orders() as the plugin calls it: the newest shop order's id, by
     * ID, among the statuses asked for. Fails like a query matching
     * '/^wc_get_orders/' in p4_db_errors.
     */
    function agm_p4_wc_get_orders($args) {
        foreach (isset($GLOBALS['agm']['p4_db_errors']) ? $GLOBALS['agm']['p4_db_errors'] : array() as $pattern => $times) {
            if ($times > 0 && preg_match($pattern, 'wc_get_orders')) {
                $GLOBALS['agm']['p4_db_errors'][$pattern]--;
                $GLOBALS['wpdb']->last_error = 'Lost connection to MySQL server during query';
                return array();
            }
        }
        $statuses = isset($args['status']) ? (array) $args['status'] : array();
        $ids = array();
        foreach (agm_p4_shop_orders() as $id => $order) {
            if (!$statuses || in_array('wc-' . $order->get_status(), $statuses, true) || in_array($order->get_status(), $statuses, true)) {
                $ids[] = $id;
            }
        }
        rsort($ids);
        return array_slice($ids, 0, isset($args['limit']) && $args['limit'] > 0 ? (int) $args['limit'] : count($ids));
    }
    $GLOBALS['agm']['wc_get_orders'] = 'agm_p4_wc_get_orders';

    function agm_p4_shop_orders() {
        $orders = array();
        foreach ($GLOBALS['agm']['orders'] as $id => $order) {
            if ($order instanceof WC_Order && $order->get_type() === 'shop_order') {
                $orders[$id] = $order;
            }
        }
        ksort($orders);
        return $orders;
    }

    /**
     * A purchase as the live path counts one: a paid date or a paid status,
     * and not cancelled, refunded, failed, in the trash or a draft. The query
     * must say all three.
     */
    function agm_p4_status_matches($sql, WC_Order $order) {
        $column = strpos($sql, 'o.status') !== false ? 'o.status' : 'p.post_status';
        $excluded = agm_p4_not_in($sql, $column);
        $paid_statuses = agm_p4_in($sql, $column);
        $paid_date = strpos($sql, 'd.date_paid_gmt IS NOT NULL') !== false || strpos($sql, "dp.meta_key = '_date_paid'") !== false;
        if (!$excluded || !$paid_statuses || !$paid_date) {
            throw new Exception('The query does not read purchases as the live path does: ' . $sql);
        }
        $status = $order->get_status();
        if (in_array('wc-' . $status, $excluded, true) || in_array($status, $excluded, true)) {
            return false;
        }
        return (bool) $order->get_date_paid() || in_array('wc-' . $status, $paid_statuses, true);
    }

    function agm_p4_created($order) {
        $date = $order->get_date_created();
        return $date ? gmdate('Y-m-d H:i:s', $date->getTimestamp()) : '';
    }

    function agm_p4_orders($sql, $count) {
        agm_p4_expect_table($sql);
        $column = strpos($sql, 'wc_orders') !== false ? 'o.id' : 'p.ID';
        $after = agm_p4_after($sql, $column);
        $upto = preg_match('/' . preg_quote($column, '/') . ' <= (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
        $since = preg_match("/\\(1 = 0 OR (?:o\\.date_created_gmt|p\\.post_date_gmt) >= '([^']+)'\\)/", $sql, $m) ? $m[1] : null;
        $ids = array();
        foreach (agm_p4_shop_orders() as $id => $order) {
            if ($id > $after && $id <= $upto && agm_p4_status_matches($sql, $order) && ($since === null || agm_p4_created($order) >= $since)) {
                $ids[] = (string) $id;
            }
        }
        return $count ? (string) count($ids) : array_slice($ids, 0, agm_p4_limit($sql));
    }

    /**
     * The box ticked on an order a checkout created. The query must say so
     * itself (created_via, or the posts' _created_via), or no order counts.
     */
    function agm_p4_trusted_optin($sql, WC_Order $order) {
        $asks = preg_match("/(?:d\\.created_via|v\\.meta_value) IN \\('checkout', 'store-api'\\)/", $sql)
            && (strpos($sql, 'wc_order_operational_data d') !== false || strpos($sql, "v.meta_key = '_created_via'") !== false);
        return $asks && $order->get_meta('_agumail_optin') === 'yes'
            && in_array((string) $order->get_created_via(), array('checkout', 'store-api'), true);
    }

    /** Paid orders a checkout created with the box ticked, by id, up to the bound (phase 1b). */
    function agm_p4_ticked_orders($sql, $count) {
        agm_p4_expect_table($sql);
        $column = strpos($sql, 'wc_orders') !== false ? 'o.id' : 'p.ID';
        $after = agm_p4_after($sql, $column);
        $upto = preg_match('/' . preg_quote($column, '/') . ' <= (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
        $ids = array();
        foreach (agm_p4_shop_orders() as $id => $order) {
            if ($id > $after && $id <= $upto && agm_p4_status_matches($sql, $order) && agm_p4_trusted_optin($sql, $order)) {
                $ids[] = (string) $id;
            }
        }
        return $count ? (string) count($ids) : array_slice($ids, 0, agm_p4_limit($sql));
    }

    /** Per billing email: the first paid order's date, the first id inside the sync's range, and the first opted-in order's id. */
    function agm_p4_guest_orders($sql) {
        agm_p4_expect_table($sql);
        $hpos = strpos($sql, 'wc_orders') !== false;
        $emails = array_map('strtolower', agm_p4_in($sql, $hpos ? 'o.billing_email' : 'e.meta_value'));
        $column = $hpos ? 'o.id' : 'p.ID';
        $upto = preg_match('/' . preg_quote($column, '/') . ' <= (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
        $since = preg_match("/WHEN 1 = 0 OR (?:o\\.date_created_gmt|p\\.post_date_gmt) >= '([^']+)' THEN/", $sql, $m) ? $m[1] : null;
        $rows = array();
        foreach (agm_p4_shop_orders() as $id => $order) {
            $email = strtolower($order->get_billing_email());
            if ($id > $upto || !in_array($email, $emails, true) || !agm_p4_status_matches($sql, $order)) {
                continue;
            }
            $created = agm_p4_created($order);
            if (!isset($rows[$email])) {
                $rows[$email] = array('email' => $email, 'first_at' => $created, 'first_id' => null, 'optin_id' => null);
            }
            $row = &$rows[$email];
            $row['first_at'] = min($row['first_at'], $created);
            if (($since === null || $created >= $since) && ($row['first_id'] === null || $id < (int) $row['first_id'])) {
                $row['first_id'] = (string) $id;
            }
            if (agm_p4_trusted_optin($sql, $order) && ($row['optin_id'] === null || $id < (int) $row['optin_id'])) {
                $row['optin_id'] = (string) $id;
            }
            unset($row);
        }
        return array_values($rows);
    }

    /** Per customer id: their first paid order with the box ticked. */
    function agm_p4_optin_orders($sql) {
        agm_p4_expect_table($sql);
        $hpos = strpos($sql, 'wc_orders') !== false;
        $ids = array_map('intval', agm_p4_in($sql, $hpos ? 'o.customer_id' : 'c.meta_value'));
        $upto = preg_match('/(?:o\.id|p\.ID) <= (\d+)/', $sql, $m) ? (int) $m[1] : PHP_INT_MAX;
        if (strpos($sql, 'LOWER(u.user_email)') === false) {
            throw new Exception('The account opt-in lookup does not require the order to be billed to the account\'s own address: ' . $sql);
        }
        $rows = array();
        foreach (agm_p4_shop_orders() as $id => $order) {
            $customer = (int) $order->get_customer_id();
            if ($id > $upto || !in_array($customer, $ids, true) || !agm_p4_status_matches($sql, $order) || !agm_p4_trusted_optin($sql, $order)) {
                continue;
            }
            $user = get_userdata($customer);
            if (!$user || strtolower($order->get_billing_email()) !== strtolower($user->user_email)) {
                continue;
            }
            if (!isset($rows[$customer])) {
                $rows[$customer] = array('user_id' => (string) $customer, 'optin_id' => (string) $id);
            }
        }
        return array_values($rows);
    }

    // ── Running the jobs ──

    /**
     * Plays Action Scheduler for the bulk sync: runs the pending step in a cron
     * request, again and again, until none is left (or $max rounds). Returns
     * the outcome of each round.
     */
    function agm_p4_run_bulk($max = 50) {
        $outcomes = array();
        for ($round = 0; $round < $max && agm_scheduled('agumail_bulk_step'); $round++) {
            agm_request('bulk step ' . ($round + 1), 'cron', function () {
                agm_run_actions('agumail_bulk_step');
            });
            $outcomes[] = $round;
        }
        return $outcomes;
    }

    /** An admin action as the settings page posts it, by a store manager. */
    function agm_p4_admin_action($action, array $fields = array(), $kind = 'admin') {
        return agm_request('admin: ' . $action, $kind, function () use ($action, $fields, $kind) {
            agm_input(array(), array_merge(array(
                'action'         => 'agumail',
                'agumail_action' => $action,
                '_wpnonce'       => agumail_admin_nonce($action),
            ), $fields));
            do_action($kind === 'admin' ? 'admin_post_agumail' : 'wp_ajax_agumail');
        });
    }

    /** A store manager signed in to wp-admin, with the settings page's sections registered. */
    function agm_p4_merchant() {
        agm_sign_in(1, 'owner@shop.test');
        $GLOBALS['agm']['users'][1]->roles = array('administrator');
        agm_grant('manage_woocommerce');
        do_action('init');
    }

    /** What a settings page section prints. */
    function agm_p4_section($id) {
        $sections = agumail_admin_sections();
        if (!isset($sections[$id])) {
            throw new Exception("The settings page has no \"$id\" section");
        }
        ob_start();
        try {
            call_user_func($sections[$id]['render']);
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }
}
