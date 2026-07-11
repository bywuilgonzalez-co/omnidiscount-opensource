<?php

namespace Drw\App\Adjustments;

if (!defined('ABSPATH')) {
    exit;
}

class Bogo
{
    /**
     * Calculate BOGO discounts for the cart.
     *
     * @param array $rule_adjustments Adjustments array from the rule
     * @param \WC_Cart $cart WooCommerce Cart object
     * @return array Array of cart item keys mapped to their new unit price
     */
    public function calculate($rule_adjustments, $cart)
    {
        if (!$cart || $cart->is_empty()) {
            return [];
        }

        // Parse adjustments parameters
        $buy_qty = isset($rule_adjustments['buy_qty']) ? (int)$rule_adjustments['buy_qty'] : (isset($rule_adjustments['buy_quantity']) ? (int)$rule_adjustments['buy_quantity'] : 1);
        $get_qty = isset($rule_adjustments['get_qty']) ? (int)$rule_adjustments['get_qty'] : (isset($rule_adjustments['get_quantity']) ? (int)$rule_adjustments['get_quantity'] : 1);
        
        if ($buy_qty <= 0 || $get_qty <= 0) {
            return [];
        }

        $get_product_type = isset($rule_adjustments['get_product_type']) ? $rule_adjustments['get_product_type'] : (isset($rule_adjustments['apply_to']) ? $rule_adjustments['apply_to'] : 'same');
        
        $discount_type = isset($rule_adjustments['discount_type']) ? $rule_adjustments['discount_type'] : (isset($rule_adjustments['type']) ? $rule_adjustments['type'] : 'free');
        $discount_value = isset($rule_adjustments['discount_value']) ? (float)$rule_adjustments['discount_value'] : (isset($rule_adjustments['value']) ? (float)$rule_adjustments['value'] : 0.0);

        $buy_products = isset($rule_adjustments['buy_products']) ? (array)$rule_adjustments['buy_products'] : [];
        $buy_categories = isset($rule_adjustments['buy_categories']) ? (array)$rule_adjustments['buy_categories'] : [];
        
        $get_products = isset($rule_adjustments['get_products']) ? (array)$rule_adjustments['get_products'] : [];
        $get_categories = isset($rule_adjustments['get_categories']) ? (array)$rule_adjustments['get_categories'] : [];

        $cart_items = $cart->get_cart();
        $buy_items = [];
        $get_items = [];

        foreach ($cart_items as $key => $item) {
            $product = $item['data'];
            
            // Check if matches Buy list
            if ($this->is_product_in_list($product, $buy_products, $buy_categories)) {
                $buy_items[$key] = $item;
            }
            
            // Check if matches Get list. Across EVERY get_product_type, an empty
            // get_products AND get_categories means "no gift/get scope configured"
            // on the get side -- it must NOT fall back to "matches everything", or
            // an unrelated cart line can end up discounted/free. This applies not
            // just to 'different' (buy X of one thing, get Y of a genuinely
            // different thing) but also 'cheapest'/'cheapest_in_cart', which have
            // their own intentional fallback to $buy_items (see below) for when
            // the get side is unconfigured. That fallback is keyed on whether
            // get_products/get_categories were actually configured, NOT merely on
            // whether $get_items ends up empty -- a configured-but-unmatched get
            // scope (e.g. get_products => [20] but product 20 isn't in the cart)
            // must still yield an empty $get_items here, but must NOT trigger the
            // buy_items fallback, since that would incorrectly discount the buy
            // item itself. 'same' never reads $get_items at all, so this has no
            // effect there. See is_product_in_list()'s $match_all_when_empty
            // param -- the buy-side call above intentionally keeps the default
            // (true), since an empty buy scope legitimately means "storewide".
            if ($this->is_product_in_list($product, $get_products, $get_categories, false)) {
                $get_items[$key] = $item;
            }
        }

        $results = [];

        if ($get_product_type === 'same') {
            // Buy X Get Y of the SAME product (e.g. Buy 1 Jeans, Get 1 Jeans Free)
            foreach ($buy_items as $key => $item) {
                $product = $item['data'];
                $qty = (int)$item['quantity'];
                $price = (float)$product->get_regular_price();

                $group_size = $buy_qty + $get_qty;
                $times = floor($qty / $group_size);
                $discounted_qty = $times * $get_qty;
                
                if ($discounted_qty > 0) {
                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $discounted_qty) * $price) + ($discounted_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;
                    $results[$key] = $average_price;
                }
            }
        } elseif ($get_product_type === 'different') {
            // Buy X of any matching Buy items, Get Y of any matching Get items discounted (e.g. Buy 2 Jeans, Get 1 Shirt)
            //
            // Round-8 audit fix: mirrors the round-7 fix applied to the
            // 'cheapest'/'cheapest_in_cart' branch below. The original formula
            // (total_buy_qty / buy_qty, with total_buy_qty drawn from
            // $buy_items) is only correct when the buy and get scopes are
            // fully DISJOINT. When a cart line matches BOTH scopes -- e.g.
            // buy_categories=[5] and get_categories=[5], an ordinary "Buy 1
            // Get 1 within Category X" promo -- the same physical units were
            // counted twice: once to satisfy buy_qty (from $buy_items, which
            // includes the overlap) and again as discountable stock (from
            // $get_items, which also includes the overlap).
            //
            // Fix: split buy-matching quantity into $buy_only_qty (matches
            // buy scope only) and $overlap_qty (matches BOTH scopes, keyed by
            // cart line), and get-matching quantity into $get_only_qty
            // (matches get scope only). Each overlapping unit can serve at
            // most ONE role (spent toward buy_qty OR granted the discount),
            // so find the largest number of complete buy/get groups ($times)
            // such that the overlap units needed to cover any shortfall in
            // $buy_only_qty and $get_only_qty together do not exceed
            // $overlap_qty. When the two scopes are disjoint, $overlap_qty is
            // always 0 and this reduces to exactly the original
            // total_buy_qty/buy_qty formula.
            $buy_only_qty = 0;
            $overlap_qty = 0;
            foreach ($buy_items as $buy_key => $item) {
                $qty = (int)$item['quantity'];
                if (isset($get_items[$buy_key])) {
                    $overlap_qty += $qty;
                } else {
                    $buy_only_qty += $qty;
                }
            }

            $get_only_qty = 0;
            foreach ($get_items as $get_key => $item) {
                if (!isset($buy_items[$get_key])) {
                    $get_only_qty += (int)$item['quantity'];
                }
            }

            $max_times_by_buy = (int)floor(($buy_only_qty + $overlap_qty) / $buy_qty);

            $times = 0;
            $overlap_for_buy_at_times = 0;
            for ($t = 0; $t <= $max_times_by_buy; $t++) {
                $needed_from_overlap_for_buy = max(0, ($buy_qty * $t) - $buy_only_qty);
                $needed_from_overlap_for_get = max(0, ($get_qty * $t) - $get_only_qty);

                if (($needed_from_overlap_for_buy + $needed_from_overlap_for_get) <= $overlap_qty) {
                    $times = $t;
                    $overlap_for_buy_at_times = $needed_from_overlap_for_buy;
                } else {
                    break;
                }
            }

            $max_discount_qty = $times * $get_qty;

            // Round-10 audit fix: $times above proves the cycle count is
            // achievable only under a specific split of $overlap_qty between
            // the buy role ($overlap_for_buy_at_times units -- unavailable
            // for discount, since they are what makes buy_qty*$times
            // feasible) and the get role (the rest). Without this cap, the
            // application loop below would greedily hand the discount to
            // whichever $get_items line is cheapest -- including overlap
            // lines -- with no regard for how many of those units the
            // feasibility proof already reserved for the buy side, silently
            // granting more free/discounted units than the buy quantity
            // actually purchased justifies. $overlap_budget is the total
            // overlap-line quantity (summed across however many overlap
            // lines exist) still eligible for discount; get-only lines are
            // never capped by it.
            $overlap_budget = max(0, $overlap_qty - $overlap_for_buy_at_times);

            if ($max_discount_qty > 0 && !empty($get_items)) {
                // Sort get items by price ascending (cheapest first)
                uasort($get_items, function ($a, $b) {
                    $price_a = (float)$a['data']->get_regular_price();
                    $price_b = (float)$b['data']->get_regular_price();
                    return $price_a <=> $price_b;
                });

                $remaining_discount_qty = $max_discount_qty;

                foreach ($get_items as $key => $item) {
                    if ($remaining_discount_qty <= 0) {
                        break;
                    }

                    $product = $item['data'];
                    $qty = (int)$item['quantity'];
                    $price = (float)$product->get_regular_price();

                    $is_overlap_line = isset($buy_items[$key]);
                    $eligible_qty = $is_overlap_line ? min($qty, $overlap_budget) : $qty;

                    $apply_to_qty = min($eligible_qty, $remaining_discount_qty);
                    if ($apply_to_qty <= 0) {
                        continue;
                    }

                    if ($is_overlap_line) {
                        $overlap_budget -= $apply_to_qty;
                    }
                    $remaining_discount_qty -= $apply_to_qty;

                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $apply_to_qty) * $price) + ($apply_to_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;

                    $results[$key] = $average_price;
                }
            }
        } elseif ($get_product_type === 'cheapest' || $get_product_type === 'cheapest_in_cart') {
            // Cheapest item in the cart or cheapest item from categories/products
            // Group size is buy_qty + get_qty. E.g. Buy 2 Get 1 Free requires 3 items total in candidate pool.
            //
            // The buy_items fallback below is intentional ONLY when the get side
            // is genuinely unconfigured (get_products AND get_categories both
            // empty) -- in that case $get_items is always empty regardless of
            // cart contents, and "cheapest in the whole eligible pool" should
            // fall back to the buy scope. But if the merchant DID configure a
            // get scope (e.g. get_products => [20]) and it simply doesn't match
            // anything currently in the cart, $get_items is ALSO empty -- and
            // falling back to $buy_items there would incorrectly discount the
            // buy item itself instead of correctly yielding no discount. So the
            // fallback must be keyed on whether the get scope was configured at
            // all, not merely on whether $get_items happens to be empty.
            $get_scope_configured = !empty($get_products) || !empty($get_categories);
            $candidate_pool = $get_scope_configured ? $get_items : $buy_items;

            // Round-4 audit fix: when the get scope IS configured (and thus can
            // legitimately differ from the buy scope, e.g. buy_products=[A],
            // get_products=[B]), the buy_qty requirement must be checked against
            // $buy_items independently -- mirroring the 'different' branch above
            // -- instead of being derived from the candidate pool's own quantity.
            // Pre-fix, $total_candidate_qty/$group_size were computed solely from
            // $candidate_pool (== $get_items here), so buy_qty was silently
            // bypassed whenever the buy scope had too few (or zero) matching
            // units in the cart: a fully-stocked, unrelated get scope alone was
            // enough to trigger the discount. When the get scope is UNCONFIGURED,
            // $candidate_pool falls back to $buy_items itself, so buy and get
            // roles share the same pool and the original group_size-based
            // calculation (buy_qty + get_qty items per group) is correct and
            // preserved as-is.
            //
            // Round-7 audit fix: the round-4 formula above (total_buy_qty /
            // buy_qty) is only correct when the buy and get scopes are fully
            // DISJOINT (no cart line matches both). When a cart line matches
            // BOTH scopes -- e.g. buy_categories=[5] and get_categories=[5], an
            // ordinary "Buy 1 Get 1 within Category X" -- the SAME physical
            // units were being counted twice: once to satisfy buy_qty (from
            // $buy_items, which includes the overlap) and again as free/discounted
            // stock in $candidate_pool (== $get_items, which also includes the
            // overlap). That double count let, e.g., a 4-unit cart entirely in
            // category 5 with buy_qty=1/get_qty=1 come back with ALL 4 units
            // free instead of the correct 2 of 4.
            //
            // Fix: split buy-matching quantity into $buy_only_qty (matches buy
            // scope only) and $overlap_qty (matches BOTH scopes, keyed by cart
            // line), and get-matching quantity into $get_only_qty (matches get
            // scope only). Each overlapping unit can serve at most ONE role
            // (either "spent to satisfy buy_qty" or "granted the discount"), so
            // find the largest number of complete buy/get groups ($times) such
            // that the overlap units needed to cover any shortfall in
            // $buy_only_qty and $get_only_qty together do not exceed
            // $overlap_qty. Feasibility is monotonic in $times (both shortfall
            // terms are non-decreasing), so iterating upward and stopping at the
            // first infeasible value finds the maximum. When the two scopes are
            // disjoint, $overlap_qty is always 0 and this reduces to exactly the
            // round-4 formula (verified against tests/test-bogo-different-empty-
            // get-list.php cases 7 and 8a-8c).
            if ($get_scope_configured) {
                $buy_only_qty = 0;
                $overlap_qty = 0;
                foreach ($buy_items as $buy_key => $item) {
                    $qty = (int)$item['quantity'];
                    if (isset($get_items[$buy_key])) {
                        $overlap_qty += $qty;
                    } else {
                        $buy_only_qty += $qty;
                    }
                }

                $get_only_qty = 0;
                foreach ($get_items as $get_key => $item) {
                    if (!isset($buy_items[$get_key])) {
                        $get_only_qty += (int)$item['quantity'];
                    }
                }

                $max_times_by_buy = (int)floor(($buy_only_qty + $overlap_qty) / $buy_qty);

                $times = 0;
                $overlap_for_buy_at_times = 0;
                for ($t = 0; $t <= $max_times_by_buy; $t++) {
                    $needed_from_overlap_for_buy = max(0, ($buy_qty * $t) - $buy_only_qty);
                    $needed_from_overlap_for_get = max(0, ($get_qty * $t) - $get_only_qty);

                    if (($needed_from_overlap_for_buy + $needed_from_overlap_for_get) <= $overlap_qty) {
                        $times = $t;
                        $overlap_for_buy_at_times = $needed_from_overlap_for_buy;
                    } else {
                        break;
                    }
                }

                $max_discount_qty = $times * $get_qty;

                // Round-10 audit fix: see the matching comment in the
                // 'different' branch above -- $overlap_budget caps how much
                // overlap-line quantity the application loop below may
                // discount, so it can never exceed the split of $overlap_qty
                // that made $times feasible (i.e. it cannot eat into the
                // units the feasibility proof reserved for the buy side).
                $overlap_budget = max(0, $overlap_qty - $overlap_for_buy_at_times);
            } else {
                $total_candidate_qty = 0;
                foreach ($candidate_pool as $item) {
                    $total_candidate_qty += (int)$item['quantity'];
                }

                $group_size = $buy_qty + $get_qty;
                $times = floor($total_candidate_qty / $group_size);
                $max_discount_qty = $times * $get_qty;
                // Unconfigured get scope: candidate_pool falls back to
                // $buy_items itself, so buy and get share the same pool and
                // there is no separate overlap role to cap -- see the
                // is_overlap_line check below, which is gated on
                // $get_scope_configured for exactly this reason.
                $overlap_budget = 0;
            }

            if ($max_discount_qty > 0 && !empty($candidate_pool)) {
                // Sort candidates by price ascending (cheapest first)
                uasort($candidate_pool, function ($a, $b) {
                    $price_a = (float)$a['data']->get_regular_price();
                    $price_b = (float)$b['data']->get_regular_price();
                    return $price_a <=> $price_b;
                });

                $remaining_discount_qty = $max_discount_qty;

                foreach ($candidate_pool as $key => $item) {
                    if ($remaining_discount_qty <= 0) {
                        break;
                    }

                    $product = $item['data'];
                    $qty = (int)$item['quantity'];
                    $price = (float)$product->get_regular_price();

                    $is_overlap_line = $get_scope_configured && isset($buy_items[$key]);
                    $eligible_qty = $is_overlap_line ? min($qty, $overlap_budget) : $qty;

                    $apply_to_qty = min($eligible_qty, $remaining_discount_qty);
                    if ($apply_to_qty <= 0) {
                        continue;
                    }

                    if ($is_overlap_line) {
                        $overlap_budget -= $apply_to_qty;
                    }
                    $remaining_discount_qty -= $apply_to_qty;

                    $discounted_unit_price = $this->get_discounted_price($price, $discount_type, $discount_value);
                    $total_price = (($qty - $apply_to_qty) * $price) + ($apply_to_qty * $discounted_unit_price);
                    $average_price = $total_price / $qty;

                    $results[$key] = $average_price;
                }
            }
        }

        return $results;
    }

    /**
     * Check if product is in the products or categories lists.
     *
     * @param \WC_Product $product
     * @param array $product_ids
     * @param array $category_ids
     * @param bool $match_all_when_empty When both lists are empty: true (default)
     *   means "match every product" (used for buy-side scope, where an empty
     *   scope legitimately means storewide). Pass false for get-side matching
     *   where an empty list means "nothing was configured", so it must match
     *   nothing instead of everything (calculate() passes false for every
     *   get_product_type's get-side call).
     * @return bool
     */
    private function is_product_in_list($product, $product_ids, $category_ids, $match_all_when_empty = true)
    {
        // If both lists are empty, by default it's considered a match (meaning
        // "all products"). Callers that need "empty means no eligible items"
        // (e.g. an unconfigured BOGO get-side, any get_product_type) pass false.
        if (empty($product_ids) && empty($category_ids)) {
            return $match_all_when_empty;
        }
        
        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();
        
        if (!empty($product_ids)) {
            $ids = array_map('intval', $product_ids);
            if (in_array($product_id, $ids, true) || ($parent_id && in_array($parent_id, $ids, true))) {
                return true;
            }
        }
        
        if (!empty($category_ids)) {
            $cats = array_map('intval', $category_ids);
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (!empty(array_intersect($product_cats, $cats))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Helper to compute the discounted unit price.
     *
     * @param float $price
     * @param string $discount_type
     * @param float $discount_value
     * @return float
     */
    private function get_discounted_price($price, $discount_type, $discount_value)
    {
        switch ($discount_type) {
            case 'percent':
            case 'percentage':
                return max(0.0, $price - ($price * ($discount_value / 100)));
            case 'fixed_price':
            case 'fixed':
                return max(0.0, $discount_value);
            case 'fixed_discount':
                return max(0.0, $price - $discount_value);
            case 'free':
            default:
                return 0.0;
        }
    }
}
