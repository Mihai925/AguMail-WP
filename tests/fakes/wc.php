<?php
// The slice of WooCommerce the plugin touches, under WooCommerce's own class
// names so instanceof checks behave. Objects keep their data in props and meta;
// a get_x()/set_x() nobody wrote explicitly reads and writes props['x'].
//
// save() on any object is logged as a write, because a plugin that saves an
// order from a shopper hook is making a write of its own. The simulated store
// persists its objects with agm_wc_persist(), which is not logged.

namespace Automattic\WooCommerce\Utilities {
    class FeaturesUtil {
        public static function declare_compatibility($feature, $file, $compatible = true) {
            $GLOBALS['agm']['compat'][$feature] = array('file' => $file, 'compatible' => $compatible);
            return true;
        }
    }
}

namespace {
    $GLOBALS['agm']['compat']   = array();
    $GLOBALS['agm']['orders']   = array();
    $GLOBALS['agm']['products'] = array();
    $GLOBALS['agm']['fields']   = array();
    $GLOBALS['agm']['page']     = '';
    $GLOBALS['agm']['notices']  = array();
    $GLOBALS['agm']['next_id']  = 1000;

    class WC_DateTime extends DateTime {
        public function getOffsetTimestamp() { return $this->getTimestamp(); }
        public function date($format) { return gmdate($format, $this->getTimestamp()); }
        public function __toString() { return $this->format(DATE_ATOM); }
    }
    function agm_date($timestamp) {
        $date = new WC_DateTime('@' . (int) $timestamp);
        return $date;
    }

    class WC_Data {
        protected $id = 0;
        public $props = array();
        public $meta = array();
        public $object_type = 'data';
        public function __construct($id = 0) { $this->id = (int) $id; }
        public function get_id() { return $this->id; }
        public function set_id($id) { $this->id = (int) $id; }
        public function get_meta($key = '', $single = true, $context = 'view') {
            if ($key === '') {
                return $this->meta;
            }
            return array_key_exists($key, $this->meta) ? $this->meta[$key] : ($single ? '' : array());
        }
        public function meta_exists($key = '') { return array_key_exists($key, $this->meta); }
        public function update_meta_data($key, $value, $meta_id = 0) { $this->meta[$key] = $value; }
        public function add_meta_data($key, $value, $unique = false) { if (!$unique || !$this->meta_exists($key)) { $this->meta[$key] = $value; } }
        public function delete_meta_data($key) { unset($this->meta[$key]); }
        public function get_meta_data() { return $this->meta; }
        public function save_meta_data() { agm_record_write('wc_save', get_class($this), 'meta'); }
        public function save() {
            if (!$this->id) {
                $this->id = $GLOBALS['agm']['next_id']++;
            }
            agm_record_write('wc_save', get_class($this));
            return $this->id;
        }
        public function get_data() { return array_merge(array('id' => $this->id), $this->props); }
        public function get_changes() { return array(); }
        public function __call($name, $args) {
            if (strpos($name, 'get_') === 0) {
                $prop = substr($name, 4);
                return array_key_exists($prop, $this->props) ? $this->props[$prop] : null;
            }
            if (strpos($name, 'set_') === 0) {
                $this->props[substr($name, 4)] = isset($args[0]) ? $args[0] : null;
                return null;
            }
            throw new BadMethodCallException(get_class($this) . '::' . $name . ' is not faked');
        }
    }

    /** Stores an object the way WooCommerce's own save would, without logging a plugin write. */
    function agm_wc_persist(WC_Data $object) {
        if (!$object->get_id()) {
            $object->set_id($GLOBALS['agm']['next_id']++);
        }
        if ($object instanceof WC_Abstract_Order) {
            $GLOBALS['agm']['orders'][$object->get_id()] = $object;
        } elseif ($object instanceof WC_Product) {
            $GLOBALS['agm']['products'][$object->get_id()] = $object;
        }
        return $object;
    }

    // ── Catalogue ──

    class WC_Product extends WC_Data {
        public $object_type = 'product';
        public function __construct($id = 0, array $props = array()) {
            parent::__construct($id);
            $this->props = array_merge(array(
                'name' => 'Product ' . $id, 'price' => '10', 'regular_price' => '10', 'sale_price' => '', 'sku' => '',
                'status' => 'publish', 'image_id' => 0, 'stock_quantity' => null, 'stock_status' => 'instock',
                'slug' => 'product-' . $id, 'parent_id' => 0, 'category_ids' => array(), 'date_on_sale_from' => null,
                'date_on_sale_to' => null, 'type' => 'simple', 'catalog_visibility' => 'visible', 'description' => '',
                'short_description' => '', 'manage_stock' => false, 'purchasable' => true,
            ), $props);
        }
        public function get_type() { return $this->props['type']; }
        public function get_name($context = 'view') { return $this->props['name']; }
        public function get_price($context = 'view') { return $this->props['price']; }
        public function get_sku($context = 'view') { return $this->props['sku']; }
        public function get_status($context = 'view') { return $this->props['status']; }
        public function get_image_id($context = 'view') { return $this->props['image_id']; }
        public function get_parent_id($context = 'view') { return $this->props['parent_id']; }
        public function get_stock_quantity($context = 'view') { return $this->props['stock_quantity']; }
        public function set_stock_quantity($qty) { $this->props['stock_quantity'] = $qty; }
        public function is_type($type) { return in_array($this->props['type'], (array) $type, true); }
        public function is_purchasable() { return (bool) $this->props['purchasable']; }
        public function is_in_stock() { return $this->props['stock_status'] !== 'outofstock'; }
        public function exists() { return $this->id > 0; }
        public function get_permalink() { return get_permalink($this->id); }
    }
    class WC_Product_Simple extends WC_Product {}
    class WC_Product_Variable extends WC_Product {
        public function __construct($id = 0, array $props = array()) { parent::__construct($id, array_merge(array('type' => 'variable'), $props)); }
    }
    class WC_Product_Variation extends WC_Product {
        public function __construct($id = 0, array $props = array()) { parent::__construct($id, array_merge(array('type' => 'variation', 'attributes' => array()), $props)); }
    }
    function agm_product($id, $name = null, $price = '10', array $props = array()) {
        $props = array_merge(array('name' => $name ?: 'Product ' . $id, 'price' => (string) $price, 'regular_price' => (string) $price), $props);
        $product = isset($props['type']) && $props['type'] === 'variation' ? new WC_Product_Variation($id, $props) : new WC_Product_Simple($id, $props);
        return agm_wc_persist($product);
    }
    function wc_get_product($id = false) {
        if ($id instanceof WC_Product) {
            return $id;
        }
        return isset($GLOBALS['agm']['products'][(int) $id]) ? $GLOBALS['agm']['products'][(int) $id] : false;
    }
    /** WooCommerce's product query, for the arguments the plugin passes: type, and return => ids. */
    function wc_get_products($args = array()) {
        $products = array_values($GLOBALS['agm']['products']);
        if (isset($args['type'])) {
            $products = array_values(array_filter($products, function ($p) use ($args) { return $p->is_type($args['type']); }));
        }
        if (isset($args['return']) && $args['return'] === 'ids') {
            return array_map(function ($p) { return $p->get_id(); }, $products);
        }
        return $products;
    }
    function get_permalink($id = 0) { return 'https://shop.test/?p=' . (is_object($id) ? $id->ID : (int) $id); }
    function get_post_status($id = 0) { $p = wc_get_product($id); return $p ? $p->get_status() : false; }
    function get_post_type($id = 0) { return isset($GLOBALS['agm']['products'][(int) $id]) ? 'product' : (isset($GLOBALS['agm']['orders'][(int) $id]) ? 'shop_order' : false); }
    function wp_get_attachment_image_url($id, $size = 'thumbnail') { return $id ? 'https://shop.test/img/' . $id . '.jpg' : false; }
    function wp_get_attachment_url($id) { return $id ? 'https://shop.test/img/' . $id . '.jpg' : false; }
    function get_the_terms($id, $taxonomy) { return isset($GLOBALS['agm']['terms'][$id][$taxonomy]) ? $GLOBALS['agm']['terms'][$id][$taxonomy] : false; }
    function get_post_meta($id, $key = '', $single = false) { return $single ? '' : array(); }
    function update_post_meta($id, $key, $value) { agm_record_write('post_meta', $key); return true; }

    // ── Orders ──

    class WC_Order_Item_Product extends WC_Data {
        public function __construct($id = 0, array $props = array()) {
            parent::__construct($id);
            $this->props = array_merge(array('product_id' => 0, 'variation_id' => 0, 'name' => '', 'quantity' => 1, 'total' => '0', 'subtotal' => '0'), $props);
        }
        public function get_product_id() { return $this->props['product_id']; }
        public function get_variation_id() { return $this->props['variation_id']; }
        public function get_name($context = 'view') { return $this->props['name']; }
        public function get_quantity() { return $this->props['quantity']; }
        public function get_total() { return $this->props['total']; }
        public function get_subtotal() { return $this->props['subtotal']; }
        public function get_product() { return wc_get_product($this->props['variation_id'] ?: $this->props['product_id']); }
    }

    abstract class WC_Abstract_Order extends WC_Data {
        public $items = array();
        public function __construct($id = 0, array $props = array()) {
            parent::__construct($id);
            $this->props = array_merge(array(
                'status' => 'pending', 'billing_email' => '', 'billing_first_name' => '', 'billing_last_name' => '',
                'total' => '0', 'total_refunded' => 0, 'currency' => 'EUR', 'customer_id' => 0,
                'date_created' => agm_date(time()), 'date_paid' => null, 'date_completed' => null, 'date_modified' => null,
                'payment_method' => 'cod', 'created_via' => 'checkout', 'parent_id' => 0, 'order_key' => 'wc_order_' . $id,
                'customer_note' => '', 'transaction_id' => '',
            ), $props);
        }
        public function get_status($context = 'view') { return $this->props['status']; }
        public function set_status($status, $note = '', $manual = false) {
            $from = $this->props['status'];
            $this->props['status'] = strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
            return array('from' => $from, 'to' => $this->props['status']);
        }
        public function has_status($status) { return in_array($this->props['status'], (array) $status, true); }
        public function get_items($types = 'line_item') { return $this->items; }
        public function add_item($item) { $this->items[] = $item; return 0; }
        public function get_item_total($item, $inc_tax = false, $round = true) {
            $qty = max(1, (int) $item->get_quantity());
            return (float) $item->get_total() / $qty;
        }
        public function get_total($context = 'view') { return $this->props['total']; }
        public function get_currency($context = 'view') { return $this->props['currency']; }
        public function get_date_created($context = 'view') { return $this->props['date_created']; }
        public function get_date_paid($context = 'view') { return $this->props['date_paid']; }
        public function get_customer_id($context = 'view') { return $this->props['customer_id']; }
        public function get_user_id($context = 'view') { return $this->props['customer_id']; }
        public function get_billing_email($context = 'view') { return $this->props['billing_email']; }
        public function set_billing_email($email) { $this->props['billing_email'] = $email; }
        public function get_billing_first_name($context = 'view') { return $this->props['billing_first_name']; }
        public function get_billing_last_name($context = 'view') { return $this->props['billing_last_name']; }
        public function get_parent_id($context = 'view') { return $this->props['parent_id']; }
        public function get_order_key($context = 'view') { return $this->props['order_key']; }
        public function get_payment_method($context = 'view') { return $this->props['payment_method']; }
        public function get_type() { return 'shop_order'; }
    }
    class WC_Order extends WC_Abstract_Order {
        public $object_type = 'order';
        public function get_total_refunded() { return $this->props['total_refunded']; }
        public function is_paid() { return $this->has_status(wc_get_is_paid_statuses()); }
        public function needs_payment() { return !$this->is_paid() && (float) $this->get_total() > 0; }
        public function get_checkout_order_received_url() { return 'https://shop.test/checkout/order-received/' . $this->id . '/'; }
    }
    class WC_Order_Refund extends WC_Abstract_Order {
        public function get_type() { return 'shop_order_refund'; }
        public function get_amount() { return isset($this->props['amount']) ? $this->props['amount'] : '0'; }
    }

    /** A new order in the simulated store, with one line per [product, qty]. */
    function agm_order($id, array $props = array(), array $lines = array()) {
        $order = new WC_Order($id, array_merge(array('billing_email' => 'ada@example.com', 'billing_first_name' => 'Ada', 'billing_last_name' => 'Lovelace'), $props));
        $total = 0.0;
        foreach ($lines as $line) {
            list($product, $qty) = $line;
            $line_total = (float) $product->get_price() * $qty;
            $total += $line_total;
            $order->items[] = new WC_Order_Item_Product(0, array('product_id' => $product->get_parent_id() ?: $product->get_id(),
                'variation_id' => $product->get_parent_id() ? $product->get_id() : 0, 'name' => $product->get_name(),
                'quantity' => $qty, 'total' => (string) $line_total, 'subtotal' => (string) $line_total));
        }
        if (!isset($props['total'])) {
            $order->props['total'] = (string) $total;
        }
        return $id ? agm_wc_persist($order) : $order;
    }
    function wc_get_order($id = false) {
        if ($id instanceof WC_Abstract_Order) {
            return $id;
        }
        return isset($GLOBALS['agm']['orders'][(int) $id]) ? $GLOBALS['agm']['orders'][(int) $id] : false;
    }
    function wc_get_orders($args = array()) {
        $GLOBALS['agm']['order_queries'][] = $args;
        // A fake that holds orders (P4) answers order queries itself.
        return isset($GLOBALS['agm']['wc_get_orders']) ? call_user_func($GLOBALS['agm']['wc_get_orders'], $args) : array();
    }
    function wc_get_is_paid_statuses() { return array('processing', 'completed'); }
    function wc_get_order_statuses() {
        return array('wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold',
            'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed',
            'wc-checkout-draft' => 'Draft');
    }

    // ── Cart, session, customer, checkout ──

    class WC_Cart {
        public $cart_contents = array();
        public function add_line(WC_Product $product, $qty = 1, array $variation = array()) {
            $key = md5($product->get_id() . wp_json_encode($variation));
            $this->cart_contents[$key] = array(
                'key' => $key, 'product_id' => $product->get_parent_id() ?: $product->get_id(),
                'variation_id' => $product->get_parent_id() ? $product->get_id() : 0, 'variation' => $variation,
                'quantity' => $qty, 'data' => $product, 'line_total' => (float) $product->get_price() * $qty,
            );
            return $key;
        }
        public function get_cart() { return $this->cart_contents; }
        public function get_cart_contents() { return $this->cart_contents; }
        public function get_cart_item($key) { return isset($this->cart_contents[$key]) ? $this->cart_contents[$key] : array(); }
        public function is_empty() { return count($this->cart_contents) === 0; }
        public function get_cart_contents_count() { return array_sum(array_column($this->cart_contents, 'quantity')); }
        public function set_quantity($key, $qty = 1, $refresh = true) {
            if ($qty <= 0) {
                unset($this->cart_contents[$key]);
            } elseif (isset($this->cart_contents[$key])) {
                $this->cart_contents[$key]['quantity'] = $qty;
                $this->cart_contents[$key]['line_total'] = (float) $this->cart_contents[$key]['data']->get_price() * $qty;
            }
            return true;
        }
        public function remove_cart_item($key) { unset($this->cart_contents[$key]); return true; }
        public function empty_cart($clear_persistent = true) { $this->cart_contents = array(); }
        public function get_total($context = 'view') { return array_sum(array_column($this->cart_contents, 'line_total')); }
        public function get_cart_hash() { return md5(wp_json_encode(array_keys($this->cart_contents))); }
        public function calculate_totals() {}
        public function add_to_cart($product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array()) {
            // A scenario can make WooCommerce refuse every item, as a validation filter might.
            if (!empty($GLOBALS['agm']['add_to_cart_fails'])) {
                return false;
            }
            $product = wc_get_product($variation_id ?: $product_id);
            return $product ? $this->add_line($product, $quantity, $variation) : false;
        }
    }
    class WC_Session_Handler {
        public $data = array();
        public $customer_id = 't_guest';
        public function get($key, $default = null) { return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
        public function set($key, $value) { $this->data[$key] = $value; }
        public function __unset($key) { unset($this->data[$key]); }
        public function get_customer_id() { return $this->customer_id; }
        public function get_customer_unique_id() { return $this->customer_id; }
        public function has_session() { return true; }
        public function set_customer_session_cookie($set) {}
        public function save_data() {}
    }
    class WC_Customer extends WC_Data {
        public $object_type = 'customer';
        public function get_billing_email($context = 'view') { return isset($this->props['billing_email']) ? $this->props['billing_email'] : ''; }
        public function set_billing_email($email) { $this->props['billing_email'] = $email; }
        public function get_email($context = 'view') { return isset($this->props['email']) ? $this->props['email'] : ''; }
        public function get_billing_first_name($context = 'view') { return isset($this->props['billing_first_name']) ? $this->props['billing_first_name'] : ''; }
        public function get_billing_last_name($context = 'view') { return isset($this->props['billing_last_name']) ? $this->props['billing_last_name'] : ''; }
    }
    class WC_Checkout {
        public function get_value($key) { return isset($_POST[$key]) ? $_POST[$key] : null; }
    }
    class WooCommerce {
        public $version = '10.7.0';
        public $cart;
        public $session;
        public $customer;
        private $checkout;
        public function __construct() {
            $this->cart = new WC_Cart();
            $this->session = new WC_Session_Handler();
            $this->customer = new WC_Customer(0);
            $this->checkout = new WC_Checkout();
        }
        public function checkout() { return $this->checkout; }
        public function __get($key) { return $key === 'checkout' ? $this->checkout : null; }
    }
    $GLOBALS['agm']['wc'] = new WooCommerce();
    function WC() { return $GLOBALS['agm']['wc']; }

    class WP_REST_Request implements ArrayAccess {
        public $method;
        public $route;
        public $params;
        public function __construct($method = 'GET', $route = '', array $params = array()) {
            $this->method = strtoupper($method);
            $this->route = $route;
            $this->params = $params;
        }
        public function get_method() { return $this->method; }
        public function get_route() { return $this->route; }
        public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
        public function get_params() { return $this->params; }
        public function get_json_params() { return $this->params; }
        public function get_body_params() { return $this->params; }
        public function has_param($key) { return array_key_exists($key, $this->params); }
        public function set_param($key, $value) { $this->params[$key] = $value; }
        #[\ReturnTypeWillChange]
        public function offsetExists($offset) { return isset($this->params[$offset]); }
        #[\ReturnTypeWillChange]
        public function offsetGet($offset) { return $this->get_param($offset); }
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value) { $this->params[$offset] = $value; }
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset) { unset($this->params[$offset]); }
    }

    // ── Templates, pages, helpers ──

    function woocommerce_form_field($key, $args, $value = null) {
        $html = '<p class="form-row ' . esc_attr(implode(' ', isset($args['class']) ? (array) $args['class'] : array())) . '">'
            . '<label><input type="' . esc_attr(isset($args['type']) ? $args['type'] : 'text') . '" name="' . esc_attr($key) . '" value="1"'
            . ($value ? ' checked="checked"' : '') . ' /> ' . (isset($args['label']) ? $args['label'] : '') . '</label></p>';
        if (isset($args['return']) && $args['return']) {
            return $html;
        }
        echo $html;
        return null;
    }
    function woocommerce_register_additional_checkout_field($field) { $GLOBALS['agm']['fields'][] = $field; }
    function wc_string_to_bool($string) { return is_bool($string) ? $string : ('yes' === strtolower((string) $string) || 1 === $string || 'true' === strtolower((string) $string) || '1' === $string); }
    function wc_bool_to_string($bool) { return $bool ? 'yes' : 'no'; }
    function wc_clean($var) { return is_array($var) ? array_map('wc_clean', $var) : (is_scalar($var) ? sanitize_text_field($var) : $var); }
    function wc_get_price_decimals() { return 2; }
    function wc_format_decimal($number, $dp = false) { return $dp === false ? (string) $number : number_format((float) $number, (int) $dp, '.', ''); }
    function get_woocommerce_currency() { return 'EUR'; }
    function wc_get_checkout_url() { return 'https://shop.test/checkout/'; }
    function wc_get_cart_url() { return 'https://shop.test/cart/'; }
    function wc_get_page_permalink($page) { return 'https://shop.test/' . $page . '/'; }
    function wc_get_account_endpoint_url($endpoint) { return 'https://shop.test/my-account/' . $endpoint . '/'; }
    function wc_add_notice($message, $type = 'success', $data = array()) { $GLOBALS['agm']['notices'][] = array($type, $message); }
    function wc_setcookie($name, $value, $expire = 0, $secure = false, $httponly = false) { $_COOKIE[$name] = $value; }
    function is_checkout() { return $GLOBALS['agm']['page'] === 'checkout'; }
    function is_cart() { return $GLOBALS['agm']['page'] === 'cart'; }
    function is_account_page() { return $GLOBALS['agm']['page'] === 'account'; }
    function is_product() { return $GLOBALS['agm']['page'] === 'product'; }
    function get_queried_object_id() { return isset($GLOBALS['agm']['queried_id']) ? (int) $GLOBALS['agm']['queried_id'] : 0; }
    /** As WooCommerce prints an option flat and without names: "Canvas, 16x20". */
    function wc_get_formatted_variation($variation, $flat = false, $include_names = true, $skip_attributes_in_name = false) {
        $attributes = $variation instanceof WC_Product ? (array) $variation->get_attributes() : (array) $variation;
        return implode(', ', array_values($attributes));
    }
    function is_woocommerce() { return in_array($GLOBALS['agm']['page'], array('product', 'shop'), true); }
    function is_order_received_page() { return $GLOBALS['agm']['page'] === 'order-received'; }
    function is_wc_endpoint_url($endpoint = false) { return false; }
}
