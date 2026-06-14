<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class UserEmail implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $emails   = !empty($data['value']) ? (array)$data['value'] : [];

        $email = '';
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $email = strtolower($current_user->user_email);
        }

        // Fallback to billing email during checkout if cart is present
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = strtolower(sanitize_email($_POST['billing_email']));
        }
        if (empty($email) && $cart && !empty(WC()->customer)) {
            $email = strtolower(WC()->customer->get_billing_email());
        }

        if (empty($email)) {
            return $operator === 'not_in_list'; // If email is empty, it's not in the list
        }

        $match_found = false;
        foreach ($emails as $rule_email) {
            $rule_email = strtolower(trim($rule_email));
            
            // Handle wildcards like *@domain.com or @domain.com
            if (strpos($rule_email, '*') !== false || strpos($rule_email, '@') === 0) {
                $pattern = str_replace(['*', '@'], ['.*', '@'], preg_quote($rule_email, '/'));
                if (preg_match('/^' . $pattern . '$/i', $email)) {
                    $match_found = true;
                    break;
                }
            } else {
                if ($email === $rule_email) {
                    $match_found = true;
                    break;
                }
            }
        }

        if ($operator === 'in_list') {
            return $match_found;
        } elseif ($operator === 'not_in_list') {
            return !$match_found;
        }

        return false;
    }
}
