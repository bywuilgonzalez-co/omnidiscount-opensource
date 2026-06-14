<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartSubtotal implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        $subtotal = (float)$cart->get_subtotal();
        $operator = !empty($data['operator']) ? $data['operator'] : 'greater_than_or_equal';
        $target   = (float)(!empty($data['value']) ? $data['value'] : 0);

        switch ($operator) {
            case 'greater_than':
                return $subtotal > $target;
            case 'less_than':
                return $subtotal < $target;
            case 'equal':
                return $subtotal === $target;
            case 'greater_than_or_equal':
                return $subtotal >= $target;
            case 'less_than_or_equal':
                return $subtotal <= $target;
            default:
                return false;
        }
    }
}
