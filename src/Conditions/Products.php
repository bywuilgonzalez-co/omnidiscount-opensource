<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class Products implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $target_ids = !empty($data['value']) ? array_map('intval', (array)$data['value']) : [];

        if (empty($target_ids)) {
            return true;
        }

        // Catalog context
        if ($product) {
            $product_id = $product->get_id();
            $parent_id  = $product->get_parent_id();
            
            $is_matched = in_array($product_id, $target_ids, true) || 
                          ($parent_id && in_array($parent_id, $target_ids, true));

            return $operator === 'in_list' ? $is_matched : !$is_matched;
        }

        // Cart context
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        $cart_product_ids = [];
        foreach ($cart->get_cart() as $cart_item) {
            $cart_product_ids[] = (int)$cart_item['product_id'];
            if (!empty($cart_item['variation_id'])) {
                $cart_product_ids[] = (int)$cart_item['variation_id'];
            }
        }

        $intersection = array_intersect($cart_product_ids, $target_ids);
        $has_match    = !empty($intersection);

        if ($operator === 'in_list') {
            return $has_match;
        } elseif ($operator === 'not_in_list') {
            return !$has_match;
        }

        return false;
    }
}
