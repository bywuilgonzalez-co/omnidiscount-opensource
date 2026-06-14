<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartItemProductCombination implements ConditionInterface
{
    /**
     * Check if a combination of products/categories (AND logic) exists in the cart.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        
        $target_products = [];
        $target_categories = [];

        if (!empty($data['product_ids'])) {
            $target_products = array_map('intval', (array)$data['product_ids']);
        }
        if (!empty($data['category_ids'])) {
            $target_categories = array_map('intval', (array)$data['category_ids']);
        }

        // Fallback to 'value' if both are empty
        if (empty($target_products) && empty($target_categories) && !empty($data['value'])) {
            $values = array_map('intval', (array)$data['value']);
            $combination_type = !empty($data['combination_type']) ? $data['combination_type'] : 'products'; // products, categories
            
            if ($combination_type === 'categories') {
                $target_categories = $values;
            } else {
                $target_products = $values;
            }
        }

        // If no combination is configured, return true
        if (empty($target_products) && empty($target_categories)) {
            return true;
        }

        // Gather product/variation IDs and category IDs currently in the cart
        $cart_product_ids = [];
        $cart_category_ids = [];

        foreach ($cart->get_cart() as $cart_item) {
            $p_id = (int)$cart_item['product_id'];
            $v_id = !empty($cart_item['variation_id']) ? (int)$cart_item['variation_id'] : 0;
            
            $cart_product_ids[] = $p_id;
            if ($v_id > 0) {
                $cart_product_ids[] = $v_id;
            }

            // Extract categories
            $cats = wc_get_product_term_ids($p_id, 'product_cat');
            if (!empty($cats)) {
                $cart_category_ids = array_merge($cart_category_ids, $cats);
            }
        }

        $cart_product_ids = array_unique($cart_product_ids);
        $cart_category_ids = array_unique(array_map('intval', $cart_category_ids));

        // Check product combination (AND logic)
        $products_matched = true;
        if (!empty($target_products)) {
            foreach ($target_products as $tpl) {
                if (!in_array($tpl, $cart_product_ids, true)) {
                    $products_matched = false;
                    break;
                }
            }
        }

        // Check category combination (AND logic)
        $categories_matched = true;
        if (!empty($target_categories)) {
            foreach ($target_categories as $tcat) {
                if (!in_array($tcat, $cart_category_ids, true)) {
                    $categories_matched = false;
                    break;
                }
            }
        }

        $combination_matched = $products_matched && $categories_matched;

        if ($operator === 'in_list') {
            return $combination_matched;
        } elseif ($operator === 'not_in_list') {
            return !$combination_matched;
        }

        return false;
    }
}
