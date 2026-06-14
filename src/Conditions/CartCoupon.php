<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartCoupon implements ConditionInterface
{
    /**
     * Check if specific WooCommerce coupons are applied in the cart.
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
        $target_coupons = !empty($data['value']) ? (array)$data['value'] : [];

        // WooCommerce applied coupons are usually lowercase
        $applied_coupons = array_map('strtolower', (array)$cart->get_applied_coupons());
        $target_coupons = array_map('strtolower', array_map('trim', $target_coupons));

        $has_match = false;
        foreach ($target_coupons as $coupon) {
            if (in_array($coupon, $applied_coupons, true)) {
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
