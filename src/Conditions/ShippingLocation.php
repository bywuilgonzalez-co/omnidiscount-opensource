<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class ShippingLocation implements ConditionInterface
{
    public function check(array $data, $cart = null, $product = null)
    {
        if (empty(WC()->customer)) {
            return false;
        }

        $type      = !empty($data['location_type']) ? $data['location_type'] : 'country'; // country, state, city, zip
        $operator  = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $targets   = !empty($data['value']) ? (array)$data['value'] : [];

        $actual_val = '';
        switch ($type) {
            case 'country':
                $actual_val = WC()->customer->get_shipping_country();
                break;
            case 'state':
                // Check format: COUNTRY:STATE or STATE
                $actual_val = WC()->customer->get_shipping_state();
                break;
            case 'city':
                $actual_val = WC()->customer->get_shipping_city();
                break;
            case 'zip':
                $actual_val = WC()->customer->get_shipping_postcode();
                break;
        }

        $actual_val = strtolower(trim($actual_val));
        $match_found = false;

        foreach ($targets as $target) {
            $target = strtolower(trim($target));
            
            if ($type === 'zip' && strpos($target, '*') !== false) {
                // Wildcard zip code matching (e.g. 902*)
                $pattern = str_replace('*', '.*', preg_quote($target, '/'));
                if (preg_match('/^' . $pattern . '$/i', $actual_val)) {
                    $match_found = true;
                    break;
                }
            } else {
                if ($actual_val === $target) {
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
