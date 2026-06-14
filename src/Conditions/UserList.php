<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class UserList implements ConditionInterface
{
    /**
     * Check if the current user ID is in a specified list of user IDs.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $target_ids = !empty($data['value']) ? array_map('intval', (array)$data['value']) : [];
        
        if (empty($target_ids) && !empty($data['user_ids'])) {
            $target_ids = array_map('intval', (array)$data['user_ids']);
        }

        $current_user_id = get_current_user_id();

        $has_match = false;
        if ($current_user_id > 0 && !empty($target_ids)) {
            $has_match = in_array($current_user_id, $target_ids, true);
        }

        if ($operator === 'in_list') {
            return $has_match;
        } elseif ($operator === 'not_in_list') {
            return !$has_match;
        }

        return false;
    }
}
