<?php

namespace Drw\App\Adjustments;

if (!defined('ABSPATH')) {
    exit;
}

class FreeShipping
{
    /**
     * Determine if free shipping is unlocked.
     *
     * @param array $rule_adjustments Adjustments configuration for the rule
     * @param \WC_Cart $cart WooCommerce Cart object
     * @return bool True if free shipping is unlocked
     */
    public function is_free_shipping_unlocked($rule_adjustments, $cart)
    {
        if (!$cart || $cart->is_empty()) {
            return false;
        }

        // 1. Min Subtotal requirement (if set in adjustments)
        if (isset($rule_adjustments['min_subtotal']) && (float)$rule_adjustments['min_subtotal'] > 0) {
            $min_subtotal = (float)$rule_adjustments['min_subtotal'];
            $cart_subtotal = (float)$cart->get_subtotal();
            if ($cart_subtotal < $min_subtotal) {
                return false;
            }
        }

        // 2. Min Quantity requirement (if set in adjustments)
        if (isset($rule_adjustments['min_qty']) && (int)$rule_adjustments['min_qty'] > 0) {
            $min_qty = (int)$rule_adjustments['min_qty'];
            $cart_qty = (int)$cart->get_cart_contents_count();
            if ($cart_qty < $min_qty) {
                return false;
            }
        }

        // 3. Product / Category requirements (if set in adjustments)
        $apply_to = isset($rule_adjustments['apply_to']) ? $rule_adjustments['apply_to'] : 'all';
        if ($apply_to !== 'all') {
            $product_ids = isset($rule_adjustments['product_ids']) ? array_map('intval', (array)$rule_adjustments['product_ids']) : [];
            $category_ids = isset($rule_adjustments['category_ids']) ? array_map('intval', (array)$rule_adjustments['category_ids']) : [];

            if (!empty($product_ids) || !empty($category_ids)) {
                $has_match = false;
                foreach ($cart->get_cart() as $item) {
                    $product = $item['data'];
                    $product_id = $product->get_id();
                    $parent_id = $product->get_parent_id();

                    if (!empty($product_ids)) {
                        if (in_array($product_id, $product_ids, true) || ($parent_id && in_array($parent_id, $product_ids, true))) {
                            $has_match = true;
                            break;
                        }
                    }

                    if (!empty($category_ids)) {
                        $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
                        if (!empty(array_intersect($product_cats, $category_ids))) {
                            $has_match = true;
                            break;
                        }
                    }
                }

                if (!$has_match) {
                    return false;
                }
            }
        }

        // If all specified checks pass, free shipping is unlocked
        return true;
    }
}
