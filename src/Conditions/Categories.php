<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class Categories implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $target_cats = !empty($data['value']) ? array_map('intval', (array)$data['value']) : [];

        if (empty($target_cats)) {
            return true;
        }

        // Catalog context
        if ($product) {
            $product_id = $product->get_id();
            // Get product category term IDs
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            
            $has_match = !empty(array_intersect($product_cats, $target_cats));
            return $operator === 'in_list' ? $has_match : !$has_match;
        }

        // Cart context
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        $has_match = false;
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (!empty(array_intersect($product_cats, $target_cats))) {
                $has_match = true;
                break;
            }
        }

        if ($operator === 'in_list') {
            return $has_match;
        } elseif ($operator === 'not_in_list') {
            return !$has_match;
        }

        return false;
    }
}
