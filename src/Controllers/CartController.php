<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CartController
{
    private static $instance = null;
    private $is_recalculating = false;

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register hooks for cart and checkout pricing adjustments.
     */
    public function register_hooks()
    {
        // Line item pricing recalculation
        add_action('woocommerce_before_calculate_totals', [$this, 'recalculate_cart_item_prices'], 20, 1);

        // Cart-wide fees (subtotal based discounts)
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_cart_wide_fees'], 20, 1);

        // Save order metadata on checkout
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_line_item_metadata'], 20, 4);
    }

    /**
     * Intercepts WooCommerce cart calculations to apply item-specific rules.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function recalculate_cart_item_prices($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        // Avoid infinite loop
        if ($this->is_recalculating) {
            return;
        }

        $this->is_recalculating = true;

        $engine = \Drw\App\Controllers\RulesEngine::instance();

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            // Get discounted price based on matching rules
            $discounted_price = $engine->calculate_cart_item_discount($cart_item, $cart);

            if ($discounted_price !== null) {
                // Apply the adjusted price to the product instance inside the cart
                $product->set_price($discounted_price);
                
                // Track metadata in the cart item session for display or saving later
                $cart->cart_contents[$cart_item_key]['drw_discounted'] = true;
                $cart->cart_contents[$cart_item_key]['drw_original_price'] = (float)$product->get_regular_price();
                $cart->cart_contents[$cart_item_key]['drw_discount_price'] = $discounted_price;
            }
        }

        $this->is_recalculating = false;
    }

    /**
     * Calculates cart subtotal rules and applies them as a negative fee.
     *
     * @param \WC_Cart $cart WooCommerce Cart object
     */
    public function apply_cart_wide_fees($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $rules = $engine->get_active_rules();

        if (empty($rules)) {
            return;
        }

        $subtotal = (float)$cart->get_subtotal();
        $discount_fee = 0.0;
        $applied_rule_titles = [];

        foreach ($rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

            if (!$engine->is_rule_matched($rule, $cart)) {
                continue;
            }

            if ($rule['apply_to'] === 'all_products' && in_array($type, ['percentage', 'fixed'])) {
                if ($type === 'percentage') {
                    $discount_val = (float)$adjustments['value'];
                    $discount_fee += ($subtotal * ($discount_val / 100));
                    $applied_rule_titles[] = $rule['title'];
                } elseif ($type === 'fixed') {
                    $discount_val = (float)$adjustments['value'];
                    $discount_fee += $discount_val;
                    $applied_rule_titles[] = $rule['title'];
                }
            }

            if ($discount_fee > 0 && $rule['exclusive']) {
                break;
            }
        }

        if ($discount_fee > 0) {
            // Cap fee to not exceed subtotal
            $discount_fee = min($discount_fee, $subtotal);
            
            $fee_name = !empty($applied_rule_titles) 
                ? implode(', ', $applied_rule_titles) 
                : __('Cart Discount', 'discount-rules-woo');

            // Apply negative fee to give the discount
            $cart->add_fee($fee_name, -$discount_fee, true);
        }
    }

    /**
     * Attaches metadata of the applied discounts to the order items.
     *
     * @param \WC_Order_Item_Product $item Order line item object
     * @param string $cart_item_key Cart item unique key
     * @param array $values Cart item array values
     * @param \WC_Order $order WooCommerce Order object
     */
    public function save_line_item_metadata($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['drw_discounted'])) {
            $original = $values['drw_original_price'];
            $discounted = $values['drw_discount_price'];
            $saving = $original - $discounted;

            if ($saving > 0) {
                // Store saved amount in item meta (visible in admin backend)
                $item->add_meta_data('_drw_original_price', $original, true);
                $item->add_meta_data('_drw_discount_price', $discounted, true);
                $item->add_meta_data(
                    __('Discount Rules Saved', 'discount-rules-woo'), 
                    wc_price($saving * (int)$values['quantity']), 
                    false
                );
            }
        }
    }
}
