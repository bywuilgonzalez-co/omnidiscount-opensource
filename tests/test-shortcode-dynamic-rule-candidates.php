<?php
/**
 * Focused smoke test to ensure shortcode finds products targeted by dynamic rules.
 */

namespace Drw\App\Controllers {
    class RulesEngine {
        public static function instance() {
            return new self();
        }

        public function get_active_rules() {
            return [
                [
                    'apply_to' => 'specific_products',
                    'filters' => [
                        'product_ids' => [150],
                        'exclude_product_ids' => [],
                        'exclude_category_ids' => [],
                    ],
                    'adjustments' => [
                        'type' => 'percentage',
                        'value' => 12,
                    ],
                ],
            ];
        }

        public function calculate_catalog_discount($product, $regular_price) {
            return $product->get_id() === 150 ? 88.0 : null;
        }
    }

    // Minimal stub: ShortcodeController::enqueue_popup_assets() (a later
    // phase's popup feature, out of scope for this dynamic-rule-candidates
    // smoke test) only needs the render-token issuer and its two public
    // constants.
    class PopupController {
        const NONCE_ACTION   = 'drw_popup_submit';
        const HONEYPOT_FIELD = 'drw_popup_hp';

        public static function issue_render_token() {
            return ['rendered_at' => 0, 'signature' => ''];
        }
    }
}

namespace Drw\App\Models {
    class SettingsModel {
        public static function get_theme_css_variables() {
            return [];
        }

        // Popup settings are irrelevant to this shortcode test; always
        // fall back so ShortcodeController::enqueue_popup_assets() sees an
        // empty/disabled popup config.
        public static function get_setting($key, $fallback = null) {
            return $fallback;
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    define('DRW_PLUGIN_URL', 'https://example.test/wp-content/plugins/discount-rules-woo/');
    define('DRW_VERSION', '1.2.0');

    global $mock_products, $last_get_posts_args;
    $mock_products = [];
    $last_get_posts_args = [];

    class Drw_Test_Product {
        private $id;

        public function __construct($id) {
            $this->id = (int)$id;
        }

        public function get_id() {
            return $this->id;
        }

        public function get_name() {
            return 'Dynamic Product ' . $this->id;
        }

        public function get_regular_price() {
            return 100;
        }

        public function get_sale_price() {
            return '';
        }

        public function is_type($type) {
            return false;
        }

        public function add_to_cart_url() {
            return 'https://example.test/?add-to-cart=' . (int)$this->id;
        }
    }

    function shortcode_atts($pairs, $atts, $shortcode = '') {
        return array_merge($pairs, $atts);
    }

    function wp_enqueue_style($handle, $src, $deps = [], $ver = false) {}

    function wp_enqueue_script($handle, $src, $deps = [], $ver = false, $in_footer = false) {
        return true;
    }

    function wp_localize_script($handle, $object_name, $data) {
        return true;
    }

    function esc_url_raw($url) {
        return (string)$url;
    }

    function get_posts($args) {
        global $last_get_posts_args;
        $last_get_posts_args[] = $args;
        if (!empty($args['post__in'])) {
            return array_values($args['post__in']);
        }
        return range(1, 80);
    }

    function wc_get_product($id) {
        global $mock_products;
        if (!isset($mock_products[$id])) {
            $mock_products[$id] = new Drw_Test_Product($id);
        }
        return $mock_products[$id];
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

    function esc_html__($value, $domain = null) {
        return esc_html($value);
    }

    function esc_attr__($value, $domain = null) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function __($value, $domain = null) {
        return $value;
    }

    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }

    function admin_url($path = '') {
        return 'https://example.test/wp-admin/' . ltrim((string)$path, '/');
    }

    function wp_create_nonce($action = -1) {
        return 'test-nonce';
    }

    function selected($a, $b = true, $echo = true) {
        $result = ((string)$a === (string)$b) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }

    function get_terms($args = []) {
        return [];
    }

    function is_wp_error($thing) {
        return false;
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

    function sanitize_key($value) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$value));
    }

    function assert_true($condition, $message) {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    require_once dirname(__DIR__) . '/src/Controllers/ShortcodeController.php';

    $controller = Drw\App\Controllers\ShortcodeController::instance();
    $html = $controller->render_sale_items_list(['limit' => 4, 'scan_limit' => 20]);

    assert_true(strpos($html, 'Dynamic Product 150') !== false, 'Shortcode should include products explicitly targeted by active dynamic rules.');
    assert_true(strpos($html, '<div class="drw-sale-badge">-12 %</div>') !== false, 'Shortcode should render the dynamic rule percentage badge.');

    echo "Shortcode dynamic rule candidates OK\n";
}
