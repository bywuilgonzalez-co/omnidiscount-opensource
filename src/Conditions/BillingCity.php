<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class BillingCity implements ConditionInterface
{
    /**
     * Check if the billing city matches the criteria.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'in_list'; // in_list, not_in_list
        $target_cities = !empty($data['value']) ? (array)$data['value'] : [];

        $billing_city = '';
        if (!empty($_POST['billing_city'])) {
            $billing_city = sanitize_text_field(wp_unslash($_POST['billing_city']));
        }
        if (empty($billing_city) && !empty(WC()->customer)) {
            $billing_city = WC()->customer->get_billing_city();
        }

        $billing_city = strtolower(trim($billing_city));
        $target_cities = array_map('strtolower', array_map('trim', $target_cities));

        $has_match = false;
        if (!empty($billing_city) && !empty($target_cities)) {
            $has_match = in_array($billing_city, $target_cities, true);
        }

        if ($operator === 'in_list') {
            return $has_match;
        } elseif ($operator === 'not_in_list') {
            return !$has_match;
        }

        return false;
    }
}
