<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class CartItemProductOnsale implements ConditionInterface
{
    /**
     * Check if the product or cart items are already on sale.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $operator = !empty($data['operator']) ? $data['operator'] : 'is_on_sale'; // is_on_sale, not_on_sale, equal
        $value = isset($data['value']) ? $data['value'] : 'yes'; // yes, no, true, false

        // Determine target boolean: is it checking for "on sale" (true) or "not on sale" (false)?
        $target_on_sale = true;
        if ($operator === 'not_on_sale') {
            $target_on_sale = false;
        } elseif ($operator === 'equal' || $operator === 'eq') {
            $target_on_sale = filter_var($value, FILTER_VALIDATE_BOOLEAN) || in_array(strtolower($value), ['yes', '1', 'true'], true);
        } else {
            // For other operators, check value
            if (in_array(strtolower($value), ['no', '0', 'false'], true)) {
                $target_on_sale = false;
            }
        }

        // If product is provided, check that specific product
        if ($product) {
            $is_on_sale = $product->is_on_sale();
            return $is_on_sale === $target_on_sale;
        }

        // Otherwise check the cart items context
        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return false;
        }

        // Check if any product in the cart is on sale
        $has_on_sale_in_cart = false;
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['data']) && $cart_item['data'] instanceof \WC_Product) {
                if ($cart_item['data']->is_on_sale()) {
                    $has_on_sale_in_cart = true;
                    break;
                }
            }
        }

        if ($target_on_sale) {
            // Check if there is at least one on sale item in the cart
            return $has_on_sale_in_cart;
        } else {
            // Check if there are NO on sale items in the cart
            return !$has_on_sale_in_cart;
        }
    }
}
