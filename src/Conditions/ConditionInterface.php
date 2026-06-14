<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

interface ConditionInterface
{
    /**
     * Check if the condition passes.
     *
     * @param array $condition_data The condition config (e.g. operator, value)
     * @param \WC_Cart|null $cart WooCommerce Cart object (if in cart context)
     * @param \WC_Product|null $product WooCommerce Product object (if in catalog context)
     * @return bool True if condition passes, false otherwise
     */
    public function check(array $condition_data, $cart = null, $product = null);
}
