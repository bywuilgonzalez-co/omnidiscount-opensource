<?php

namespace Drw\App\Adjustments;

if (!defined('ABSPATH')) {
    exit;
}

class Bogo
{
    /**
     * Calculate BOGO discounts for the cart.
     *
     * @param array $rule_adjustments Adjustments array from the rule
     * @param \WC_Cart $cart WooCommerce Cart object
     * @return array Array of cart item keys mapped to their new unit price
     */
    public function calculate($rule_adjustments, $cart)
    {
        if (!$cart || $cart->is_empty()) {
            return [];
        }

        // Parse adjustments parameters
        $buy_qty = isset($rule_adjustments['buy_qty']) ? (int)$rule_adjustments['buy_qty'] : (isset($rule_adjustments['buy_quantity']) ? (int)$rule_adjustments['buy_quantity'] : 1);
        $get_qty = isset($rule_adjustments['get_qty']) ? (int)$rule_adjustments['get_qty'] : (isset($rule_adjustments['get_quantity']) ? (int)$rule_adjustments['get_quantity'] : 1);
        
        if ($buy_qty <= 0 || $get_qty <= 0) {
            return [];
        }

        $get_product_type = isset($rule_adjustments['get_product_type']) ? $rule_adjustments['get_product_type'] : (isset($rule_adjustments['apply_to']) ? $rule_adjustments['apply_to'] : 'same');
        
        $discount_type = isset($rule_adjustments['discount_type']) ? $rule_adjustments['discount_type'] : (isset($rule_adjustments['type']) ? $rule_adjustments['type'] : 'free');
        $discount_value = isset($rule_adjustments['discount_value']) ? (float)$rule_adjustments['discount_value'] : (isset($rule_adjustments['value']) ? (float)$rule_adjustments['value'] : 0.0);

        $buy_products = isset($rule_adjustments['buy_products']) ? (array)$rule_adjustments['buy_products'] : [];
        $buy_categories = isset($rule_adjustments['buy_categories']) ? (array)$rule_adjustments['buy_categories'] : [];
        
        $get_products = isset($rule_adjustments['get_products']) ? (array)$rule_adjustments['get_products'] : [];
        $get_categories = isset($rule_adjustments['get_categories']) ? (array)$rule_adjustments['get_categories'] : [];

        $cart_items = $cart->get_cart();
        $buy_items = [];
        $get_items = [];

        foreach ($cart_items as $key => $item) {
            $product = $item['data'];
            
            // Check if matches Buy list
            if ($this->is_product_in_list($product, $buy_products, $buy_categories)) {
                $buy_items[$key] = $item;
            }
            
            // Check if matches Get list
            if ($this->is_product_in_list($product, $get_products, $get_categories)) {
                $get_items[$key] = $item;
            }
        }

        $results = [];

        if ($get_product_type === 'same') {
            // Buy X Get Y of the SAME product (e.g. Buy 1 Jeans, Get 1 Jeans Free)
            foreach ($buy_items as $key => $item) {
                $product = $item['data'];
                $qty = (int)$item['quantity'];
                $price = (float)$product->get_regular_price();

                $group_size = $buy_qty + $get_qty;
                $times = floor($qty / $group_size);
                $discounted_qty = $times * $get_qty;
                
                if ($discounted_qty > 0) {
                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $discounted_qty) * $price) + ($discounted_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;
                    $results[$key] = $average_price;
                }
            }
        } elseif ($get_product_type === 'different') {
            // Buy X of any matching Buy items, Get Y of any matching Get items discounted (e.g. Buy 2 Jeans, Get 1 Shirt)
            $total_buy_qty = 0;
            foreach ($buy_items as $key => $item) {
                $total_buy_qty += (int)$item['quantity'];
            }

            $times = floor($total_buy_qty / $buy_qty);
            $max_discount_qty = $times * $get_qty;

            if ($max_discount_qty > 0 && !empty($get_items)) {
                // Sort get items by price ascending (cheapest first)
                uasort($get_items, function ($a, $b) {
                    $price_a = (float)$a['data']->get_regular_price();
                    $price_b = (float)$b['data']->get_regular_price();
                    return $price_a <=> $price_b;
                });

                $remaining_discount_qty = $max_discount_qty;
                
                foreach ($get_items as $key => $item) {
                    if ($remaining_discount_qty <= 0) {
                        break;
                    }

                    $product = $item['data'];
                    $qty = (int)$item['quantity'];
                    $price = (float)$product->get_regular_price();

                    $apply_to_qty = min($qty, $remaining_discount_qty);
                    $remaining_discount_qty -= $apply_to_qty;

                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $apply_to_qty) * $price) + ($apply_to_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;

                    $results[$key] = $average_price;
                }
            }
        } elseif ($get_product_type === 'cheapest' || $get_product_type === 'cheapest_in_cart') {
            // Cheapest item in the cart or cheapest item from categories/products
            // Group size is buy_qty + get_qty. E.g. Buy 2 Get 1 Free requires 3 items total in candidate pool.
            
            $candidate_pool = !empty($get_items) ? $get_items : $buy_items;
            
            $total_candidate_qty = 0;
            foreach ($candidate_pool as $item) {
                $total_candidate_qty += (int)$item['quantity'];
            }

            $group_size = $buy_qty + $get_qty;
            $times = floor($total_candidate_qty / $group_size);
            $max_discount_qty = $times * $get_qty;

            if ($max_discount_qty > 0 && !empty($candidate_pool)) {
                // Sort candidates by price ascending (cheapest first)
                uasort($candidate_pool, function ($a, $b) {
                    $price_a = (float)$a['data']->get_regular_price();
                    $price_b = (float)$b['data']->get_regular_price();
                    return $price_a <=> $price_b;
                });

                $remaining_discount_qty = $max_discount_qty;

                foreach ($candidate_pool as $key => $item) {
                    if ($remaining_discount_qty <= 0) {
                        break;
                    }

                    $product = $item['data'];
                    $qty = (int)$item['quantity'];
                    $price = (float)$product->get_regular_price();

                    $apply_to_qty = min($qty, $remaining_discount_qty);
                    $remaining_discount_qty -= $apply_to_qty;

                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $apply_to_qty) * $price) + ($apply_to_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;

                    $results[$key] = $average_price;
                }
            }
        }

        return $results;
    }

    /**
     * Check if product is in the products or categories lists.
     *
     * @param \WC_Product $product
     * @param array $product_ids
     * @param array $category_ids
     * @return bool
     */
    private function is_product_in_list($product, $product_ids, $category_ids)
    {
        // If both lists are empty, it's considered a match (meaning "all products")
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

    /**
     * Helper to compute the discounted unit price.
     *
     * @param float $price
     * @param string $discount_type
     * @param float $discount_value
     * @return float
     */
    private function get_discounted_price($price, $discount_type, $discount_value)
    {
        switch ($discount_type) {
            case 'percent':
            case 'percentage':
                return max(0.0, $price - ($price * ($discount_value / 100)));
            case 'fixed_price':
            case 'fixed':
                return max(0.0, $discount_value);
            case 'fixed_discount':
                return max(0.0, $price - $discount_value);
            case 'free':
            default:
                return 0.0;
        }
    }
}
