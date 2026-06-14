<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CartController
{
    private static $instance = null;
    private $is_recalculating = false;
    private $is_adding_to_cart = false;

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

        // Shipping modifications
        add_filter('woocommerce_package_rates', [$this, 'modify_shipping_package_rates'], 20, 2);

        // Coupon matching
        add_filter('woocommerce_get_shop_coupon_data', [$this, 'get_shop_coupon_data'], 20, 2);

        // Layout formatting filters
        add_filter('woocommerce_cart_item_price', [$this, 'format_cart_item_price'], 20, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'format_cart_item_subtotal'], 20, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [$this, 'format_cart_totals_order_total_html'], 20, 1);
        add_filter('woocommerce_get_formatted_order_total', [$this, 'format_order_total'], 20, 4);
        add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'format_order_line_subtotal'], 20, 3);
        add_action('woocommerce_admin_order_totals_after_total', [$this, 'display_admin_order_totals_after_total'], 20, 1);
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

        // BOGO Auto-addition logic
        if (!$this->is_adding_to_cart) {
            $rules = $engine->get_active_rules();
            if (!empty($rules)) {
                foreach ($rules as $rule) {
                    $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
                    $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

                    if ($type === 'bogo') {
                        if ($engine->is_rule_matched($rule, $cart)) {
                            $buy_qty = isset($adjustments['buy_qty']) ? (int)$adjustments['buy_qty'] : (isset($adjustments['buy_quantity']) ? (int)$adjustments['buy_quantity'] : 1);
                            $get_qty = isset($adjustments['get_qty']) ? (int)$adjustments['get_qty'] : (isset($adjustments['get_quantity']) ? (int)$adjustments['get_quantity'] : 1);
                            $get_product_type = isset($adjustments['get_product_type']) ? $adjustments['get_product_type'] : (isset($adjustments['apply_to']) ? $adjustments['apply_to'] : 'same');

                            $buy_products = isset($adjustments['buy_products']) ? (array)$adjustments['buy_products'] : [];
                            $buy_categories = isset($adjustments['buy_categories']) ? (array)$adjustments['buy_categories'] : [];
                            $get_products = isset($adjustments['get_products']) ? (array)$adjustments['get_products'] : [];
                            $get_categories = isset($adjustments['get_categories']) ? (array)$adjustments['get_categories'] : [];

                            if ($get_product_type === 'different') {
                                $total_buy_qty = 0;
                                foreach ($cart->get_cart() as $item) {
                                    if ($this->is_product_in_list($item['data'], $buy_products, $buy_categories)) {
                                        $total_buy_qty += (int)$item['quantity'];
                                    }
                                }

                                if ($total_buy_qty >= $buy_qty && !empty($get_products)) {
                                    $gift_in_cart = false;
                                    foreach ($cart->get_cart() as $item) {
                                        if ($this->is_product_in_list($item['data'], $get_products, $get_categories)) {
                                            $gift_in_cart = true;
                                            break;
                                        }
                                    }

                                    if (!$gift_in_cart) {
                                        $gift_product_id = (int)reset($get_products);
                                        if ($gift_product_id > 0) {
                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($gift_product_id, $get_qty);
                                            $this->is_adding_to_cart = false;
                                            break;
                                        }
                                    }
                                }
                            } elseif ($get_product_type === 'same') {
                                foreach ($cart->get_cart() as $item) {
                                    $product = $item['data'];
                                    if ($this->is_product_in_list($product, $buy_products, $buy_categories)) {
                                        $qty = (int)$item['quantity'];
                                        if ($qty >= $buy_qty && $qty < ($buy_qty + $get_qty)) {
                                            $product_id = $product->get_id();
                                            $variation_id = $item['variation_id'];
                                            $variations = $item['variation'];

                                            $this->is_adding_to_cart = true;
                                            WC()->cart->add_to_cart($product_id, $get_qty, $variation_id, $variations);
                                            $this->is_adding_to_cart = false;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

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
        $discounts = $engine->calculate_cart_level_discounts($cart);

        if (!empty($discounts['fees'])) {
            foreach ($discounts['fees'] as $fee) {
                $cart->add_fee($fee['name'], $fee['amount'], true);
            }
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

    /**
     * Modifies shipping package rates to set shipping rate cost to 0 when Free Shipping is unlocked.
     */
    public function modify_shipping_package_rates($rates, $package)
    {
        $cart = WC()->cart;
        if (!$cart) {
            return $rates;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $discounts = $engine->calculate_cart_level_discounts($cart);

        if (!empty($discounts['free_shipping'])) {
            foreach ($rates as $rate_key => $rate) {
                $rates[$rate_key]->cost = 0;
                $taxes = [];
                foreach ($rates[$rate_key]->taxes as $key => $tax) {
                    $taxes[$key] = 0;
                }
                $rates[$rate_key]->taxes = $taxes;
            }
        }

        return $rates;
    }

    /**
     * Integrates coupon matching filters to hook into WooCommerce core coupon operations for dynamic rules.
     */
    public function get_shop_coupon_data($data, $code)
    {
        if (empty($code)) {
            return $data;
        }

        // If the coupon already exists in the database, let WooCommerce handle it
        if ($data !== false && !empty($data)) {
            return $data;
        }

        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $rules = $engine->get_active_rules();

        if (empty($rules)) {
            return $data;
        }

        $coupon_matched = false;
        foreach ($rules as $rule) {
            $conditions = !empty($rule['conditions']) ? (array)$rule['conditions'] : [];
            foreach ($conditions as $cond) {
                $type = !empty($cond['type']) ? $cond['type'] : '';
                if ($type === 'cart_coupon' || $type === 'coupon') {
                    $target_coupons = !empty($cond['value']) ? (array)$cond['value'] : [];
                    $target_coupons = array_map('strtolower', array_map('trim', $target_coupons));
                    if (in_array(strtolower($code), $target_coupons, true)) {
                        $coupon_matched = true;
                        break 2;
                    }
                }
            }
        }

        if ($coupon_matched) {
            return [
                'id'                         => 99999900 + mt_rand(1, 99),
                'code'                       => $code,
                'amount'                     => 0,
                'discount_type'              => 'fixed_cart',
                'individual_use'             => false,
                'product_ids'                => array(),
                'exclude_product_ids'        => array(),
                'usage_limit'                => '',
                'usage_limit_per_user'       => '',
                'limit_usage_to_x_items'     => '',
                'expiry_date'                => '',
                'free_shipping'              => false,
                'product_categories'         => array(),
                'exclude_product_categories' => array(),
                'exclude_sale_items'         => false,
                'minimum_amount'             => '',
                'maximum_amount'             => '',
                'customer_email'             => array(),
            ];
        }

        return $data;
    }

    /**
     * Formats cart item price.
     */
    public function format_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $original = (float)$cart_item['drw_original_price'];
            $discounted = (float)$cart_item['drw_discount_price'];
            if ($original > $discounted) {
                $price_html = '<del>' . wc_price($original) . '</del> <ins>' . wc_price($discounted) . '</ins>';
            }
        }
        return $price_html;
    }

    /**
     * Formats cart item subtotal.
     */
    public function format_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
            $qty = $cart_item['quantity'];
            $original_subtotal = (float)$cart_item['drw_original_price'] * $qty;
            $discounted_subtotal = (float)$cart_item['drw_discount_price'] * $qty;
            if ($original_subtotal > $discounted_subtotal) {
                $subtotal_html = '<del>' . wc_price($original_subtotal) . '</del> <ins>' . wc_price($discounted_subtotal) . '</ins>';
            }
        }
        return $subtotal_html;
    }

    /**
     * Formats cart totals order total html.
     */
    public function format_cart_totals_order_total_html($value)
    {
        $cart = WC()->cart;
        if ($cart) {
            $savings = $this->get_total_savings($cart);
            if ($savings > 0) {
                $value .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
            }
        }
        return $value;
    }

    /**
     * Formats formatted order total.
     */
    public function format_order_total($formatted_total, $order, $tax_display, $display_refunded)
    {
        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            $formatted_total .= '<div class="drw-savings-message" style="font-size: 0.9em; color: #10b981; margin-top: 5px;">' . sprintf(__('You Saved: %s', 'discount-rules-woo'), wc_price($savings)) . '</div>';
        }
        return $formatted_total;
    }

    /**
     * Formats order formatted line subtotal.
     */
    public function format_order_line_subtotal($subtotal, $item, $order)
    {
        $original = $item->get_meta('_drw_original_price', true);
        $discounted = $item->get_meta('_drw_discount_price', true);
        if ($original !== '' && $discounted !== '') {
            $original = (float)$original;
            $discounted = (float)$discounted;
            if ($original > $discounted) {
                $qty = (int)$item->get_quantity();
                $subtotal = '<del>' . wc_price($original * $qty) . '</del> <ins>' . wc_price($discounted * $qty) . '</ins>';
            }
        }
        return $subtotal;
    }

    /**
     * Displays admin order totals after total.
     */
    public function display_admin_order_totals_after_total($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $savings = $this->get_order_total_savings($order);
        if ($savings > 0) {
            ?>
            <tr>
                <td class="label"><?php _e('Total Saved:', 'discount-rules-woo'); ?></td>
                <td width="1%"></td>
                <td class="total" style="color: #10b981; font-weight: bold;">
                    <?php echo wc_price($savings); ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Helper to compute total cart savings.
     */
    private function get_total_savings($cart)
    {
        if (!$cart) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['drw_discounted']) && isset($cart_item['drw_original_price']) && isset($cart_item['drw_discount_price'])) {
                $diff = (float)$cart_item['drw_original_price'] - (float)$cart_item['drw_discount_price'];
                if ($diff > 0) {
                    $savings += $diff * $cart_item['quantity'];
                }
            }
        }

        foreach ($cart->get_fees() as $fee) {
            if ($fee->amount < 0) {
                $savings += abs($fee->amount);
            }
        }

        return $savings;
    }

    /**
     * Helper to compute total order savings.
     */
    private function get_order_total_savings($order)
    {
        if (!$order) {
            return 0.0;
        }

        $savings = 0.0;
        foreach ($order->get_items() as $item) {
            $original = $item->get_meta('_drw_original_price', true);
            $discounted = $item->get_meta('_drw_discount_price', true);
            if ($original !== '' && $discounted !== '') {
                $diff = (float)$original - (float)$discounted;
                if ($diff > 0) {
                    $savings += $diff * (int)$item->get_quantity();
                }
            }
        }

        foreach ($order->get_fees() as $fee) {
            if ($fee->get_total() < 0) {
                $savings += abs($fee->get_total());
            }
        }

        return $savings;
    }

    /**
     * Check if product is in the products or categories lists.
     */
    private function is_product_in_list($product, $product_ids, $category_ids)
    {
        if (empty($product_ids) && empty($category_ids)) {
            return true;
        }
        
        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', $product_ids);
            if (in_array($product_id, $ids, true) || ($parent_id && in_array($parent_id, $ids, true))) {
                return true;
            }
        }
        
        if (!empty($category_ids)) {
            $cats = array_map('intval', $category_ids);
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (!empty(array_intersect($product_cats, $cats))) {
                return true;
            }
        }
        
        return false;
    }
}
