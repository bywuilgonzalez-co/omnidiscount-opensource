<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartItemsQuantity implements ConditionInterface
{
    /**
     * Check if total items quantity in the cart meets the criteria.
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

        $operator = !empty($data['operator']) ? $data['operator'] : 'greater_than_or_equal';
        $target   = (int)(!empty($data['value']) ? $data['value'] : 0);
        $actual   = (int)$cart->get_cart_contents_count();

        switch ($operator) {
            case 'greater_than':
                return $actual > $target;
            case 'less_than':
                return $actual < $target;
            case 'equal':
                return $actual === $target;
            case 'greater_than_or_equal':
                return $actual >= $target;
            case 'less_than_or_equal':
                return $actual <= $target;
            default:
                return false;
        }
    }
}
