<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class UserLoggedIn implements ConditionInterface
{
    /**
     * Check if the visitor is logged in.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'is_logged_in'; // is_logged_in, is_guest, equal
        $value = isset($data['value']) ? $data['value'] : 'yes'; // yes, no, true, false

        $logged_in = is_user_logged_in();

        $target = true;
        if ($operator === 'is_guest') {
            $target = false;
        } elseif ($operator === 'equal' || $operator === 'eq') {
            $target = filter_var($value, FILTER_VALIDATE_BOOLEAN) || in_array(strtolower($value), ['yes', '1', 'true'], true);
        } else {
            if (in_array(strtolower($value), ['no', '0', 'false'], true)) {
                $target = false;
            }
        }

        return $logged_in === $target;
    }
}
