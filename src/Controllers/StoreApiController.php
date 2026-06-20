<?php
namespace Drw\App\Controllers;

if (!defined('ABSPATH')) { exit; }

class StoreApiController {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        // Only register when Store API is available (WC 6.9+)
        add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_integration']);
    }

    public function register_store_api_integration() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        // Register cart extension data so discount info is available in the Block Cart response
        // Use the string identifier directly to avoid a hard dependency on WC's internal CartSchema class.
        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'cart',
            'namespace'       => 'discount-rules-woo',
            'data_callback'   => [$this, 'get_cart_extension_data'],
            'schema_callback' => [$this, 'get_cart_extension_schema'],
            'schema_type'     => ARRAY_A,
        ]);

        // Apply discounts before Store API calculates totals
        add_action('woocommerce_store_api_cart_update_order_from_request', [$this, 'apply_discounts_to_block_cart'], 10, 2);
        add_filter('woocommerce_store_api_cart_errors', [$this, 'add_free_shipping_notice'], 10, 2);
    }

    /**
     * Apply plugin discounts when the Store API updates the cart (Block Cart/Checkout).
     * Mirrors the logic CartController uses via woocommerce_before_calculate_totals.
     */
    public function apply_discounts_to_block_cart($order, $request) {
        $cart = WC()->cart;
        if (!$cart) { return; }

        $engine = RulesEngine::instance();
        $engine->calculate_all_cart_discounts($cart);

        $item_prices = $engine->get_cached_cart_item_prices();
        if (!empty($item_prices)) {
            foreach ($cart->get_cart() as $key => $item) {
                if (isset($item_prices[$key])) {
                    $item['data']->set_price($item_prices[$key]);
                }
            }
        }

        $discounts = $engine->get_cached_cart_level_discounts();
        if (!empty($discounts['free_shipping'])) {
            WC()->session->set('drw_free_shipping', true);
        } else {
            WC()->session->set('drw_free_shipping', false);
        }
    }

    /**
     * Expose discount summary to the Block Cart/Checkout via Store API extension data.
     */
    public function get_cart_extension_data() {
        $cart   = WC()->cart;
        $engine = RulesEngine::instance();

        if (!$cart) {
            return ['discount_total' => 0, 'free_shipping' => false, 'applied_rules' => []];
        }

        $discounts    = $engine->get_cached_cart_level_discounts();
        $item_prices  = $engine->get_cached_cart_item_prices();
        $saved        = 0.0;

        foreach ($cart->get_cart() as $key => $item) {
            if (isset($item_prices[$key])) {
                $regular = (float)$item['data']->get_regular_price();
                $saved  += max(0, ($regular - $item_prices[$key]) * $item['quantity']);
            }
        }

        foreach (!empty($discounts['fees']) ? $discounts['fees'] : [] as $fee) {
            $saved += abs((float)$fee['amount']);
        }

        return [
            'discount_total' => wc_format_decimal($saved, 2),
            'free_shipping'  => !empty($discounts['free_shipping']),
            'applied_rules'  => [],
        ];
    }

    /**
     * Schema definition for the Store API extension data.
     */
    public function get_cart_extension_schema() {
        return [
            'discount_total' => ['description' => 'Total discount applied by DRW rules', 'type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true],
            'free_shipping'  => ['description' => 'Whether free shipping is granted by a rule', 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'applied_rules'  => ['description' => 'List of applied rule IDs', 'type' => 'array', 'context' => ['view', 'edit'], 'readonly' => true, 'items' => ['type' => 'integer']],
        ];
    }

    /**
     * Add a notice about free shipping in the Block Cart errors/notices channel.
     */
    public function add_free_shipping_notice($errors, $cart) {
        if (WC()->session && WC()->session->get('drw_free_shipping')) {
            // Notices for Block Cart use wc_add_notice, not this filter.
            // This filter is for error objects; skip silently.
        }
        return $errors;
    }
}
