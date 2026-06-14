<?php
/**
 * Test script to simulate and verify all the functions of the discount-rules-woo plugin.
 */

namespace {
    // 1. Mock the WordPress and WooCommerce Environment
    define('ABSPATH', dirname(dirname(__FILE__)) . '/');
    define('WP_DEBUG', true);

    class WooCommerce {
        // Dummy class to pass class_exists('WooCommerce')
    }

    class WC_Holder {
        public $cart;
        public $customer;
    }

    class WC_Customer_Mock {
        public $billing_email = '';
        public function get_billing_email() {
            return $this->billing_email;
        }
    }

    // Global registry for mocks
    global $mock_product_categories, $mock_product_prices, $mock_product_parents;
    global $mock_user_logged_in, $mock_current_user_id, $mock_current_user;
    global $mock_current_time, $mock_orders, $wp_options, $wp_filters;
    global $woocommerce;

    $woocommerce = new WC_Holder();
    $woocommerce->customer = new WC_Customer_Mock();

    $mock_product_categories = [];
    $mock_product_prices = [];
    $mock_product_parents = [];
    $mock_user_logged_in = false;
    $mock_current_user_id = 0;
    $mock_current_user = null;
    $mock_current_time = null;
    $mock_orders = [];
    $wp_options = [];
    $wp_filters = [];

    // Mock WordPress functions
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_filters;
        $wp_filters[$tag][] = $callback;
        return true;
    }

    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
        return add_filter($tag, $callback, $priority, $accepted_args);
    }

    function apply_filters($tag, $value, ...$args) {
        global $wp_filters;
        if (isset($wp_filters[$tag])) {
            foreach ($wp_filters[$tag] as $callback) {
                $value = call_user_func($callback, $value, ...$args);
            }
        }
        return $value;
    }

    function do_action($tag, ...$args) {
        global $wp_filters;
        if (isset($wp_filters[$tag])) {
            foreach ($wp_filters[$tag] as $callback) {
                call_user_func($callback, ...$args);
            }
        }
    }

    function register_activation_hook($file, $callback) {}

    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }

    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }

    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }

    function plugin_dir_url($file) {
        return 'http://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }

    function plugin_basename($file) {
        return basename($file);
    }

    function __($text, $domain = 'default') {
        return $text;
    }

    function esc_html__($text, $domain = 'default') {
        return $text;
    }

    function sanitize_text_field($str) {
        return trim($str);
    }

    function sanitize_email($email) {
        return trim($email);
    }

    function wp_unslash($str) {
        return $str;
    }

    function is_admin() { return false; }
    function wp_doing_ajax() { return false; }

    function is_user_logged_in() {
        global $mock_user_logged_in;
        return $mock_user_logged_in;
    }

    function get_current_user_id() {
        global $mock_current_user_id;
        return $mock_current_user_id;
    }

    class Mock_WP_User {
        public $ID = 0;
        public $user_email = '';
        public $roles = [];
    }

    function wp_get_current_user() {
        global $mock_current_user;
        if ($mock_current_user === null) {
            $mock_current_user = new Mock_WP_User();
        }
        return $mock_current_user;
    }

    function current_time($type) {
        global $mock_current_time;
        $t = ($mock_current_time !== null) ? $mock_current_time : time();
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s', $t);
        }
        return $t;
    }

    // Mock WooCommerce functions
    function wc_get_product_term_ids($product_id, $taxonomy) {
        global $mock_product_categories;
        return isset($mock_product_categories[$product_id]) ? $mock_product_categories[$product_id] : [];
    }

    function wc_price($price, $args = []) {
        return '$' . number_format((float)$price, 2);
    }

    function WC() {
        global $woocommerce;
        return $woocommerce;
    }

    class WC_Product {
        public $id;
        public $regular_price = 0.0;
        public $price = 0.0;
        public $parent_id = 0;

        public function __construct($id) {
            global $mock_product_prices, $mock_product_parents;
            $this->id = (int)$id;
            $this->regular_price = isset($mock_product_prices[$id]) ? (float)$mock_product_prices[$id] : 0.0;
            $this->price = $this->regular_price;
            $this->parent_id = isset($mock_product_parents[$id]) ? (int)$mock_product_parents[$id] : 0;
        }

        public function get_id() { return $this->id; }
        public function get_parent_id() { return $this->parent_id; }
        public function get_regular_price() { return $this->regular_price; }
        public function get_price() { return $this->price; }
        public function set_price($price) { $this->price = (float)$price; }
    }

    class WC_Cart {
        public $cart_contents = [];
        public $subtotal = 0.0;
        public $fees = [];
        public $applied_coupons = [];

        public function get_cart() {
            return $this->cart_contents;
        }

        public function is_empty() {
            return empty($this->cart_contents);
        }

        public function get_subtotal() {
            return $this->subtotal;
        }

        public function set_subtotal($val) {
            $this->subtotal = $val;
        }

        public function get_cart_contents_count() {
            $count = 0;
            foreach ($this->cart_contents as $item) {
                $count += $item['quantity'];
            }
            return $count;
        }

        public function get_applied_coupons() {
            return $this->applied_coupons;
        }

        public function add_fee($name, $amount, $taxable = true) {
            $fee = new stdClass();
            $fee->name = $name;
            $fee->amount = $amount;
            $this->fees[] = $fee;
        }

        public function get_fees() {
            return $this->fees;
        }

        public function add_to_cart($product_id, $quantity, $variation_id = 0, $variation = []) {
            // Find if product already in cart
            foreach ($this->cart_contents as $key => &$item) {
                if ($item['product_id'] == $product_id && $item['variation_id'] == $variation_id) {
                    $item['quantity'] += $quantity;
                    return $key;
                }
            }
            $key = md5($product_id . '_' . $variation_id);
            $product = new WC_Product($product_id);
            $this->cart_contents[$key] = [
                'key' => $key,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'variation' => $variation,
                'quantity' => $quantity,
                'data' => $product,
            ];
            return $key;
        }
    }

    class WC_Shipping_Rate {
        public $id;
        public $cost = 15.0;
        public $taxes = [1 => 1.5];
    }

    class WC_Order_Mock {
        public $id;
        public $total = 0.0;
        public $customer_id = 0;
        public $billing_email = '';
        public $items = [];

        public function get_id() { return $this->id; }
        public function get_total() { return $this->total; }
        public function get_items() { return $this->items; }
    }

    class WC_Order_Item_Mock {
        public $product_id;
        public $variation_id = 0;
        public $quantity = 1;
        public $meta = [];

        public function get_product_id() { return $this->product_id; }
        public function get_variation_id() { return $this->variation_id; }
        public function get_quantity() { return $this->quantity; }
        public function get_meta($key, $single = true) {
            return isset($this->meta[$key]) ? $this->meta[$key] : '';
        }
        public function add_meta_data($key, $value, $unique = false) {
            $this->meta[$key] = $value;
        }
    }

    global $mock_orders;
    function wc_get_order($order_id) {
        global $mock_orders;
        return isset($mock_orders[$order_id]) ? $mock_orders[$order_id] : null;
    }

    function wc_get_orders($args) {
        global $mock_orders;
        $ids = [];
        foreach ($mock_orders as $order) {
            if (isset($args['customer']) && $order->customer_id != $args['customer']) {
                continue;
            }
            if (isset($args['billing_email']) && $order->billing_email != $args['billing_email']) {
                continue;
            }
            $ids[] = $order->get_id();
        }
        return $ids;
    }
}

// 2. Mock the RuleModel class in the correct namespace
namespace Drw\App\Models {
    class RuleModel {
        public static $mocked_rules = [];

        public static function get_active_rules() {
            return self::$mocked_rules;
        }

        public static function get_all_rules() {
            return self::$mocked_rules;
        }

        public static function get_rule($id) {
            foreach (self::$mocked_rules as $rule) {
                if ($rule['id'] == $id) {
                    return $rule;
                }
            }
            return null;
        }

        public static function save_rule($data) {
            return 1;
        }

        public static function delete_rule($id) {
            return true;
        }
    }
}

// 3. Main script execution in global namespace
namespace {
    // Include the plugin bootstrap
    require_once dirname(dirname(__FILE__)) . '/discount-rules-woo.php';

    // Trigger plugins_loaded hook to initialize routing and controllers
    do_action('plugins_loaded');

    use Drw\App\Controllers\CartController;
    use Drw\App\Controllers\RulesEngine;
    use Drw\App\Models\RuleModel;

    $cart_controller = CartController::instance();
    $engine = RulesEngine::instance();

    $all_passed = true;

    // ----------------------------------------------------
    // Test Case 1: Simple percentage and fixed discount on target items
    // ----------------------------------------------------
    echo "Running Test Case 1...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 1,
            'title' => '10% off Product A',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'specific_products',
            'filters' => [
                'product_ids' => [101]
            ],
            'conditions' => [],
            'adjustments' => [
                'type' => 'percentage',
                'value' => 10
            ]
        ],
        [
            'id' => 2,
            'title' => '$5 off Product B',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 20,
            'apply_to' => 'specific_products',
            'filters' => [
                'product_ids' => [102]
            ],
            'conditions' => [],
            'adjustments' => [
                'type' => 'fixed',
                'value' => 5
            ]
        ]
    ];

    $mock_product_prices = [
        101 => 100.0,
        102 => 50.0
    ];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 2);
    $cart->add_to_cart(102, 1);
    $cart->set_subtotal(250.0);

    global $woocommerce;
    $woocommerce->cart = $cart;

    $cart_controller->recalculate_cart_item_prices($cart);

    $items = $cart->get_cart();
    $prodA_ok = false;
    $prodB_ok = false;

    foreach ($items as $item) {
        if ($item['product_id'] == 101) {
            $price = $item['data']->get_price();
            if ($price == 90.0) $prodA_ok = true;
            else echo "  Fail: Product A price is $price, expected 90\n";
        }
        if ($item['product_id'] == 102) {
            $price = $item['data']->get_price();
            if ($price == 45.0) $prodB_ok = true;
            else echo "  Fail: Product B price is $price, expected 45\n";
        }
    }

    if ($prodA_ok && $prodB_ok) {
        echo "[PASS] Test case 1: Simple percentage and fixed discount\n";
    } else {
        echo "[FAIL] Test case 1: Simple percentage and fixed discount\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 2: Bulk quantity breaks tiered pricing rule
    // ----------------------------------------------------
    echo "Running Test Case 2...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 3,
            'title' => 'Bulk Product C',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'specific_products',
            'filters' => [
                'product_ids' => [103]
            ],
            'conditions' => [],
            'adjustments' => [
                'type' => 'bulk',
                'tiers' => [
                    ['min' => 1, 'max' => 4, 'type' => 'percentage', 'value' => 0],
                    ['min' => 5, 'max' => 9, 'type' => 'percentage', 'value' => 10],
                    ['min' => 10, 'max' => '', 'type' => 'percentage', 'value' => 20]
                ]
            ]
        ]
    ];

    $mock_product_prices = [
        103 => 10.0
    ];

    // Qty 3 (no discount, price 10)
    $cart1 = new WC_Cart();
    $cart1->add_to_cart(103, 3);
    $cart1->set_subtotal(30.0);
    $woocommerce->cart = $cart1;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart1);
    $price1 = current($cart1->get_cart())['data']->get_price();

    // Qty 6 (10% discount, price 9)
    $cart2 = new WC_Cart();
    $cart2->add_to_cart(103, 6);
    $cart2->set_subtotal(60.0);
    $woocommerce->cart = $cart2;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart2);
    $price2 = current($cart2->get_cart())['data']->get_price();

    // Qty 12 (20% discount, price 8)
    $cart3 = new WC_Cart();
    $cart3->add_to_cart(103, 12);
    $cart3->set_subtotal(120.0);
    $woocommerce->cart = $cart3;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart3);
    $price3 = current($cart3->get_cart())['data']->get_price();

    if ($price1 == 10.0 && $price2 == 9.0 && $price3 == 8.0) {
        echo "[PASS] Test case 2: Bulk quantity breaks tiered pricing\n";
    } else {
        echo "[FAIL] Test case 2: Bulk quantity breaks tiered pricing. Got Qty3=$price1, Qty6=$price2, Qty12=$price3\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 3: BOGO same product (Buy 2 Get 1 Free, same product)
    // ----------------------------------------------------
    echo "Running Test Case 3...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 4,
            'title' => 'BOGO Same Product',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'specific_products',
            'filters' => [
                'product_ids' => [104]
            ],
            'conditions' => [],
            'adjustments' => [
                'type' => 'bogo',
                'buy_qty' => 2,
                'get_qty' => 1,
                'get_product_type' => 'same',
                'discount_type' => 'free',
                'buy_products' => [104],
                'get_products' => [104]
            ]
        ]
    ];

    $mock_product_prices = [
        104 => 30.0
    ];

    $cart = new WC_Cart();
    $cart->add_to_cart(104, 2);
    $cart->set_subtotal(60.0);
    $woocommerce->cart = $cart;

    $cart_controller->recalculate_cart_item_prices($cart);

    $items = $cart->get_cart();
    $item = current($items);
    $qty = $item['quantity'];
    $price = $item['data']->get_price();

    if ($qty == 3 && $price == 20.0) {
        echo "[PASS] Test case 3: BOGO same product\n";
    } else {
        echo "[FAIL] Test case 3: BOGO same product. Qty: $qty (expected 3), Price: $price (expected 20)\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 4: BOGO different product (Buy 1 of A, Get 1 of B 50% off)
    // ----------------------------------------------------
    echo "Running Test Case 4...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 5,
            'title' => 'BOGO Different Product',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'specific_products',
            'filters' => [
                'product_ids' => [101]
            ],
            'conditions' => [],
            'adjustments' => [
                'type' => 'bogo',
                'buy_qty' => 1,
                'get_qty' => 1,
                'get_product_type' => 'different',
                'discount_type' => 'percentage',
                'discount_value' => 50,
                'buy_products' => [101],
                'get_products' => [102]
            ]
        ]
    ];

    $mock_product_prices = [
        101 => 100.0,
        102 => 80.0
    ];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;

    $cart_controller->recalculate_cart_item_prices($cart);

    $items = $cart->get_cart();
    $has_102 = false;
    $price_102 = 0.0;

    foreach ($items as $item) {
        if ($item['product_id'] == 102) {
            $has_102 = true;
            $price_102 = $item['data']->get_price();
        }
    }

    if ($has_102 && $price_102 == 40.0) {
        echo "[PASS] Test case 4: BOGO different product\n";
    } else {
        echo "[FAIL] Test case 4: BOGO different product. Has 102: " . ($has_102 ? 'yes' : 'no') . ", Price 102: $price_102 (expected 40)\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 5: BOGO cheapest (Buy 2, Get the cheapest item in the cart free)
    // ----------------------------------------------------
    echo "Running Test Case 5...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 6,
            'title' => 'Buy 2 Get Cheapest Free',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'all_products',
            'conditions' => [],
            'adjustments' => [
                'type' => 'bogo',
                'buy_qty' => 2,
                'get_qty' => 1,
                'get_product_type' => 'cheapest',
                'discount_type' => 'free',
                'buy_products' => [],
                'get_products' => []
            ]
        ]
    ];

    $mock_product_prices = [
        101 => 100.0,
        102 => 30.0
    ];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->add_to_cart(102, 2);
    $cart->set_subtotal(160.0);
    $woocommerce->cart = $cart;

    $cart_controller->recalculate_cart_item_prices($cart);

    $items = $cart->get_cart();
    $price_101 = 0.0;
    $price_102 = 0.0;

    foreach ($items as $item) {
        if ($item['product_id'] == 101) {
            $price_101 = $item['data']->get_price();
        }
        if ($item['product_id'] == 102) {
            $price_102 = $item['data']->get_price();
        }
    }

    if ($price_101 == 100.0 && $price_102 == 15.0) {
        echo "[PASS] Test case 5: BOGO cheapest\n";
    } else {
        echo "[FAIL] Test case 5: BOGO cheapest. Price 101: $price_101 (expected 100), Price 102: $price_102 (expected 15)\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 6: Bundle Set pricing (Package Deal)
    // ----------------------------------------------------
    echo "Running Test Case 6...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 7,
            'title' => 'Bundle ABC',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'all_products',
            'conditions' => [],
            'adjustments' => [
                'type' => 'bundle_set',
                'bundle_price' => 120.0,
                'bundle_items' => [
                    ['type' => 'product', 'id' => 101, 'qty' => 1],
                    ['type' => 'product', 'id' => 102, 'qty' => 1],
                    ['type' => 'product', 'id' => 103, 'qty' => 1]
                ]
            ]
        ]
    ];

    $mock_product_prices = [
        101 => 50.0,
        102 => 40.0,
        103 => 60.0
    ];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->add_to_cart(102, 1);
    $cart->add_to_cart(103, 1);
    $cart->set_subtotal(150.0);
    $woocommerce->cart = $cart;

    $cart_controller->recalculate_cart_item_prices($cart);

    $items = $cart->get_cart();
    $price_101 = 0.0;
    $price_102 = 0.0;
    $price_103 = 0.0;

    foreach ($items as $item) {
        if ($item['product_id'] == 101) {
            $price_101 = $item['data']->get_price();
        }
        if ($item['product_id'] == 102) {
            $price_102 = $item['data']->get_price();
        }
        if ($item['product_id'] == 103) {
            $price_103 = $item['data']->get_price();
        }
    }

    if (abs($price_101 - 40.0) < 0.01 && abs($price_102 - 32.0) < 0.01 && abs($price_103 - 48.0) < 0.01) {
        echo "[PASS] Test case 6: Bundle Set pricing\n";
    } else {
        echo "[FAIL] Test case 6: Bundle Set pricing. Price 101: $price_101 (expected 40), Price 102: $price_102 (expected 32), Price 103: $price_103 (expected 48)\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 7: Free Shipping (unlocked when subtotal exceeds 100)
    // ----------------------------------------------------
    echo "Running Test Case 7...\n";
    $engine->clear_cache();
    RuleModel::$mocked_rules = [
        [
            'id' => 8,
            'title' => 'Free Shipping Over 100',
            'enabled' => true,
            'deleted' => false,
            'exclusive' => false,
            'priority' => 10,
            'apply_to' => 'all_products',
            'conditions' => [],
            'adjustments' => [
                'type' => 'free_shipping',
                'min_subtotal' => 100.0
            ]
        ]
    ];

    $mock_product_prices = [
        101 => 50.0
    ];

    $cart1 = new WC_Cart();
    $cart1->add_to_cart(101, 1);
    $cart1->set_subtotal(50.0);
    $rates1 = ['flat_rate:1' => new WC_Shipping_Rate()];
    $woocommerce->cart = $cart1;
    $rates1 = $cart_controller->modify_shipping_package_rates($rates1, []);
    $cost1 = $rates1['flat_rate:1']->cost;

    $cart2 = new WC_Cart();
    $cart2->add_to_cart(101, 3);
    $cart2->set_subtotal(150.0);
    $rates2 = ['flat_rate:1' => new WC_Shipping_Rate()];
    $woocommerce->cart = $cart2;
    $rates2 = $cart_controller->modify_shipping_package_rates($rates2, []);
    $cost2 = $rates2['flat_rate:1']->cost;

    if ($cost1 == 15.0 && $cost2 == 0.0) {
        echo "[PASS] Test case 7: Free Shipping\n";
    } else {
        echo "[FAIL] Test case 7: Free Shipping. Cost1: $cost1 (expected 15), Cost2: $cost2 (expected 0)\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 8: Conditions evaluation
    // ----------------------------------------------------
    echo "Running Test Case 8...\n";

    $mock_product_prices = [101 => 100.0];

    // 8.1 CartSubtotal
    $engine->clear_cache();
    RuleModel::$mocked_rules = [[
        'id' => 9,
        'title' => 'Subtotal Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'cart_subtotal',
            'operator' => 'greater_than_or_equal',
            'value' => 150.0
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 2);
    $cart->set_subtotal(200.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $subtotal_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$subtotal_ok) echo "  Fail: Subtotal condition. Got fail=$price_fail, pass=$price_pass\n";

    // 8.2 CartItemsQuantity
    $engine->clear_cache();
    RuleModel::$mocked_rules = [[
        'id' => 10,
        'title' => 'Quantity Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'cart_items_quantity',
            'operator' => 'greater_than',
            'value' => 5
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 3);
    $cart->set_subtotal(300.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 6);
    $cart->set_subtotal(600.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $qty_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$qty_ok) echo "  Fail: Quantity condition. Got fail=$price_fail, pass=$price_pass\n";

    // 8.3 CartCoupon
    $engine->clear_cache();
    RuleModel::$mocked_rules = [[
        'id' => 11,
        'title' => 'Coupon Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'cart_coupon',
            'operator' => 'in_list',
            'value' => ['SAVE10']
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->applied_coupons = ['save10'];
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $coupon_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$coupon_ok) echo "  Fail: Coupon condition. Got fail=$price_fail, pass=$price_pass\n";

    // 8.4 UserLoggedIn
    $engine->clear_cache();
    RuleModel::$mocked_rules = [[
        'id' => 12,
        'title' => 'LoggedIn Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'user_logged_in',
            'operator' => 'is_logged_in',
            'value' => 'yes'
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    global $mock_user_logged_in;
    $mock_user_logged_in = false;
    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $mock_user_logged_in = true;
    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $logged_in_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$logged_in_ok) echo "  Fail: LoggedIn condition. Got fail=$price_fail, pass=$price_pass\n";

    // 8.5 OrderDate
    $engine->clear_cache();
    $now = time();
    RuleModel::$mocked_rules = [[
        'id' => 13,
        'title' => 'Date Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'order_date',
            'operator' => 'in_list',
            'date_from' => $now - 3600,
            'date_to' => $now + 3600
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    global $mock_current_time;
    $mock_current_time = $now;
    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $mock_current_time = $now + 7200; // 2 hours later
    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $mock_current_time = null; // reset

    $date_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$date_ok) echo "  Fail: Date condition. Got fail=$price_fail, pass=$price_pass\n";

    // 8.6 PurchaseHistory
    $engine->clear_cache();
    RuleModel::$mocked_rules = [[
        'id' => 14,
        'title' => 'History Condition Rule',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [[
            'type' => 'purchase_history',
            'check_type' => 'spent_total',
            'operator' => 'greater_than_or_equal',
            'value' => 500.0
        ]],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ]];

    global $mock_orders, $mock_current_user_id;
    $mock_current_user_id = 42;

    $mock_orders = [];
    $order1 = new WC_Order_Mock();
    $order1->id = 1001;
    $order1->total = 200.0;
    $order1->customer_id = 42;
    $mock_orders[1001] = $order1;

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_fail = current($cart->get_cart())['data']->get_price();

    $order2 = new WC_Order_Mock();
    $order2->id = 1002;
    $order2->total = 400.0;
    $order2->customer_id = 42;
    $mock_orders[1002] = $order2;

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $engine->clear_cache();
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_pass = current($cart->get_cart())['data']->get_price();

    $mock_orders = []; // reset

    $history_ok = ($price_fail == 100.0 && $price_pass == 90.0);
    if (!$history_ok) echo "  Fail: Purchase history condition. Got fail=$price_fail, pass=$price_pass\n";

    if ($subtotal_ok && $qty_ok && $coupon_ok && $logged_in_ok && $date_ok && $history_ok) {
        echo "[PASS] Test case 8: Conditions evaluation\n";
    } else {
        echo "[FAIL] Test case 8: Conditions evaluation\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Test Case 9: Compounding strategies
    // ----------------------------------------------------
    echo "Running Test Case 9...\n";

    $mock_product_prices = [101 => 100.0];

    $ruleA = [
        'id' => 15,
        'title' => 'Rule A 10% off',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 10,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [],
        'adjustments' => ['type' => 'percentage', 'value' => 10]
    ];

    $ruleB = [
        'id' => 16,
        'title' => 'Rule B 20% off',
        'enabled' => true,
        'deleted' => false,
        'exclusive' => false,
        'priority' => 20,
        'apply_to' => 'specific_products',
        'filters' => ['product_ids' => [101]],
        'conditions' => [],
        'adjustments' => ['type' => 'percentage', 'value' => 20]
    ];

    // 9.1: consecutive
    $engine->set_compounding_strategy('consecutive');
    $engine->clear_cache();
    RuleModel::$mocked_rules = [$ruleA, $ruleB];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_consecutive = current($cart->get_cart())['data']->get_price();

    // 9.2: highest
    $engine->set_compounding_strategy('highest');
    $engine->clear_cache();
    RuleModel::$mocked_rules = [$ruleA, $ruleB];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_highest = current($cart->get_cart())['data']->get_price();

    // 9.3: priority_exclusivity with Rule A exclusive
    $engine->set_compounding_strategy('priority_exclusivity');
    $engine->clear_cache();
    $ruleA_excl = $ruleA;
    $ruleA_excl['exclusive'] = true;
    RuleModel::$mocked_rules = [$ruleA_excl, $ruleB];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_excl = current($cart->get_cart())['data']->get_price();

    // 9.4: priority_exclusivity with no exclusive rules
    $engine->clear_cache();
    RuleModel::$mocked_rules = [$ruleA, $ruleB];

    $cart = new WC_Cart();
    $cart->add_to_cart(101, 1);
    $cart->set_subtotal(100.0);
    $woocommerce->cart = $cart;
    $cart_controller->recalculate_cart_item_prices($cart);
    $price_no_excl = current($cart->get_cart())['data']->get_price();

    $consecutive_ok = (abs($price_consecutive - 72.0) < 0.01);
    $highest_ok = (abs($price_highest - 80.0) < 0.01);
    $exclusive_ok = (abs($price_excl - 90.0) < 0.01);
    $no_exclusive_ok = (abs($price_no_excl - 72.0) < 0.01);

    if (!$consecutive_ok) echo "  Fail: Consecutive. Got $price_consecutive, expected 72\n";
    if (!$highest_ok) echo "  Fail: Highest. Got $price_highest, expected 80\n";
    if (!$exclusive_ok) echo "  Fail: Exclusive. Got $price_excl, expected 90\n";
    if (!$no_exclusive_ok) echo "  Fail: No Exclusive. Got $price_no_excl, expected 72\n";

    if ($consecutive_ok && $highest_ok && $exclusive_ok && $no_exclusive_ok) {
        echo "[PASS] Test case 9: Compounding strategies\n";
    } else {
        echo "[FAIL] Test case 9: Compounding strategies\n";
        $all_passed = false;
    }

    // ----------------------------------------------------
    // Final Summary
    // ----------------------------------------------------
    echo "\n----------------------------------------------------\n";
    if ($all_passed) {
        echo "All tests passed successfully!\n";
        exit(0);
    } else {
        echo "Some tests failed.\n";
        exit(1);
    }
}
