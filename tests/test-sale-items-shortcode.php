<?php
/**
 * Focused smoke test for the public sale-items shortcode.
 */

namespace Drw\App\Controllers {
    class RulesEngine {
        public static function instance() {
            return new self();
        }

        public function calculate_catalog_discount($product, $regular_price) {
            return $product->dynamic_discount;
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    define('DRW_PLUGIN_URL', 'https://example.test/wp-content/plugins/discount-rules-woo/');
    define('DRW_VERSION', '1.1.1');

    global $shortcodes, $enqueued_styles, $mock_products, $last_query_args;
    $shortcodes = [];
    $enqueued_styles = [];
    $mock_products = [];
    $last_query_args = null;

    class Drw_Test_Product {
        public $dynamic_discount;
        private $id;
        private $name;
        private $regular_price;
        private $sale_price;

        public function __construct($id, $name, $regular_price, $sale_price = '', $dynamic_discount = null) {
            $this->id = $id;
            $this->name = $name;
            $this->regular_price = $regular_price;
            $this->sale_price = $sale_price;
            $this->dynamic_discount = $dynamic_discount;
        }

        public function get_id() {
            return $this->id;
        }

        public function get_name() {
            return $this->name;
        }

        public function get_regular_price() {
            return $this->regular_price;
        }

        public function get_sale_price() {
            return $this->sale_price;
        }

        public function is_type($type) {
            return false;
        }

        public function get_visible_children() {
            return [];
        }
    }

    function add_shortcode($tag, $callback) {
        global $shortcodes;
        $shortcodes[$tag] = $callback;
    }

    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }

    function shortcode_atts($pairs, $atts, $shortcode = '') {
        return array_merge($pairs, $atts);
    }

    function wp_enqueue_style($handle, $src, $deps = [], $ver = false) {
        global $enqueued_styles;
        $enqueued_styles[$handle] = [$src, $deps, $ver];
    }

    function get_posts($args) {
        global $last_query_args;
        $last_query_args = $args;
        return [(object)['ID' => 101]];
    }

    function wc_get_product($id) {
        global $mock_products;
        return isset($mock_products[$id]) ? $mock_products[$id] : null;
    }

    function get_permalink($id) {
        return 'https://example.test/product/' . $id;
    }

    function get_the_post_thumbnail($id, $size = 'woocommerce_thumbnail', $attr = []) {
        return '<img src="https://example.test/product-' . (int)$id . '.jpg" alt="">';
    }

    function wc_price($price) {
        return '$' . number_format((float)$price, 2);
    }

    function esc_attr($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function esc_html($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function esc_url($value) {
        return (string)$value;
    }

    function sanitize_text_field($value) {
        return trim(strip_tags((string)$value));
    }

    function absint($value) {
        return max(0, abs((int)$value));
    }

    function assert_true($condition, $message) {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    require_once dirname(__DIR__) . '/src/Controllers/ShortcodeController.php';

    $mock_products[101] = new Drw_Test_Product(101, 'Combo Energia', 100, '', 88);

    $controller = Drw\App\Controllers\ShortcodeController::instance();
    $controller->register_hooks();

    assert_true(isset($shortcodes['drw_sale_items_list']), 'Native DRW shortcode should be registered.');
    assert_true(isset($shortcodes['awdr_sale_items_list']), 'AWDR-compatible shortcode alias should be registered.');

    $html = call_user_func($shortcodes['awdr_sale_items_list'], ['limit' => 4, 'columns' => 4, 'scan_limit' => 4]);

    assert_true(strpos($html, '<div class="sale-perc">-12 %</div>') !== false, 'Shortcode should render the exact sale percentage badge.');
    assert_true(strpos($html, 'Combo Energia') !== false, 'Shortcode should render the product name.');
    assert_true(isset($enqueued_styles['drw-public-style']), 'Shortcode should enqueue public CSS.');
    assert_true($last_query_args['posts_per_page'] === 4, 'Shortcode limit should control the product query.');

    echo "Sale items shortcode OK\n";
}
