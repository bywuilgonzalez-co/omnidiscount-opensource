<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartLineItemsCount implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        // operator can check: 'total_quantity' or 'line_items_count'
        $check_type = !empty($data['check_type']) ? $data['check_type'] : 'total_quantity';
        $operator   = !empty($data['operator']) ? $data['operator'] : 'greater_than_or_equal';
        $target     = (int)(!empty($data['value']) ? $data['value'] : 0);

        $actual = 0;
        if ($check_type === 'line_items_count') {
            $actual = count($cart->get_cart());
        } else {
            $actual = $cart->get_cart_contents_count();
        }

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
