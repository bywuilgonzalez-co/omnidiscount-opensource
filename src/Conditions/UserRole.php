<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class UserRole implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $roles    = !empty($data['value']) ? (array)$data['value'] : [];

        $current_user = wp_get_current_user();
        $user_roles = [];

        if (is_user_logged_in() && !empty($current_user->roles)) {
            $user_roles = $current_user->roles;
        } else {
            $user_roles = ['guest'];
        }

        // Check intersection
        $has_match = !empty(array_intersect($user_roles, $roles));

        if ($operator === 'in_list') {
            return $has_match;
        } elseif ($operator === 'not_in_list') {
            return !$has_match;
        }

        return false;
    }
}
