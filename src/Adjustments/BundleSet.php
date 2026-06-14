<?php

namespace Drw\App\Adjustments;

if (!defined('ABSPATH')) {
    exit;
}

class BundleSet
{
    /**
     * Calculate Bundle Set/Package Deal discounts for the cart.
     *
     * @param array $rule_adjustments Adjustments array from the rule
     * @param \WC_Cart $cart WooCommerce Cart object
     * @return array Array describing the adjustments made:
     *               [
     *                   'items'          => [ $cart_item_key => $new_average_unit_price ],
     *                   'total_discount' => $total_discount,
     *                   'applied'        => bool
     *               ]
     */
    public function calculate($rule_adjustments, $cart)
    {
        if (!$cart || $cart->is_empty()) {
            return [
                'items'          => [],
                'total_discount' => 0.0,
                'applied'        => false,
            ];
        }

        $bundle_price = isset($rule_adjustments['bundle_price']) ? (float)$rule_adjustments['bundle_price'] : 0.0;
        $bundle_items = isset($rule_adjustments['bundle_items']) ? (array)$rule_adjustments['bundle_items'] : [];

        if (empty($bundle_items) || $bundle_price <= 0) {
            return [
                'items'          => [],
                'total_discount' => 0.0,
                'applied'        => false,
            ];
        }

        // Store current cart item quantities and regular prices
        $available_qtys = [];
        $cart_item_prices = [];
        foreach ($cart->get_cart() as $key => $item) {
            $available_qtys[$key] = (int)$item['quantity'];
            $cart_item_prices[$key] = (float)$item['data']->get_regular_price();
        }

        $bundle_count = 0;
        $allocated_units = []; // Tracks: [ $cart_item_key => $qty_allocated_to_bundles ]

        // Greedy matching of bundles one by one
        while (true) {
            $temp_qtys = $available_qtys;
            $possible_allocation = [];
            $can_form_bundle = true;

            foreach ($bundle_items as $req) {
                $req_qty = isset($req['qty']) ? (int)$req['qty'] : (isset($req['quantity']) ? (int)$req['quantity'] : 1);
                $allocated_for_req = 0;

                // Find matching items in the cart
                $matching_keys = [];
                foreach ($cart->get_cart() as $key => $item) {
                    if ($this->is_item_match($item, $req)) {
                        $matching_keys[] = $key;
                    }
                }

                // Sort matches by price descending to favor higher value items
                usort($matching_keys, function ($a, $b) use ($cart_item_prices) {
                    return $cart_item_prices[$b] <=> $cart_item_prices[$a];
                });

                foreach ($matching_keys as $key) {
                    if ($temp_qtys[$key] > 0) {
                        $take = min($req_qty - $allocated_for_req, $temp_qtys[$key]);
                        if ($take > 0) {
                            $possible_allocation[] = [
                                'key' => $key,
                                'qty' => $take,
                            ];
                            $temp_qtys[$key] -= $take;
                            $allocated_for_req += $take;
                            if ($allocated_for_req >= $req_qty) {
                                break;
                            }
                        }
                    }
                }

                // If this requirement is not fully met, we cannot form a bundle
                if ($allocated_for_req < $req_qty) {
                    $can_form_bundle = false;
                    break;
                }
            }

            if ($can_form_bundle) {
                $bundle_count++;
                $available_qtys = $temp_qtys; // Commit the consumed quantities
                
                foreach ($possible_allocation as $alloc) {
                    $key = $alloc['key'];
                    if (!isset($allocated_units[$key])) {
                        $allocated_units[$key] = 0;
                    }
                    $allocated_units[$key] += $alloc['qty'];
                }
            } else {
                break; // No more bundles can be formed
            }
        }

        if ($bundle_count === 0 || empty($allocated_units)) {
            return [
                'items'          => [],
                'total_discount' => 0.0,
                'applied'        => false,
            ];
        }

        // Calculate total regular price of the allocated bundle items
        $total_regular_price = 0.0;
        foreach ($allocated_units as $key => $qty) {
            $total_regular_price += $qty * $cart_item_prices[$key];
        }

        $total_bundle_price = $bundle_count * $bundle_price;
        $total_discount = max(0.0, $total_regular_price - $total_bundle_price);

        if ($total_discount <= 0.0) {
            return [
                'items'          => [],
                'total_discount' => 0.0,
                'applied'        => false,
            ];
        }

        // Distribute discount proportionally and compute new average prices
        $results = [];
        foreach ($allocated_units as $key => $qty) {
            $item_regular_total = $qty * $cart_item_prices[$key];
            
            // Proportional share of the total discount
            $item_discount = $total_discount * ($item_regular_total / $total_regular_price);
            
            // Total quantity of this item in the cart
            $cart_item = $cart->get_cart()[$key];
            $total_item_qty = (int)$cart_item['quantity'];
            $price_reg = $cart_item_prices[$key];

            // New average unit price for the entire line item
            $average_price = $price_reg - ($item_discount / $total_item_qty);
            $results[$key] = max(0.0, $average_price);
        }

        return [
            'items'          => $results,
            'total_discount' => $total_discount,
            'applied'        => true,
        ];
    }

    /**
     * Check if a cart item matches a bundle requirement.
     *
     * @param array $cart_item
     * @param array $requirement
     * @return bool
     */
    private function is_item_match($cart_item, $requirement)
    {
        $product = $cart_item['data'];
        $type = isset($requirement['type']) ? $requirement['type'] : 'product';
        $target_id = isset($requirement['id']) ? (int)$requirement['id'] : (isset($requirement['product_id']) ? (int)$requirement['product_id'] : 0);
        
        if ($target_id <= 0) {
            return false;
        }

        if ($type === 'product') {
            $product_id = $product->get_id();
            $parent_id = $product->get_parent_id();
            return $product_id === $target_id || ($parent_id && $parent_id === $target_id);
        } elseif ($type === 'category') {
            $product_id = $product->get_id();
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            return in_array($target_id, $product_cats, true);
        }

        return false;
    }
}
