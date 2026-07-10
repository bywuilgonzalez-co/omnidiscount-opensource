<?php

namespace Drw\App\Controllers;

use Drw\App\Models\RuleModel;
use Drw\App\Adjustments\Bogo;
use Drw\App\Adjustments\FreeShipping;
use Drw\App\Adjustments\BundleSet;

if (!defined('ABSPATH')) {
    exit;
}

class RulesEngine
{
    private static $instance = null;
    private $cached_rules = null;

    private $cached_cart_hash = null;
    private $cached_cart_item_prices = null;
    private $cached_cart_level_discounts = null;
    private $compounding_strategy = 'priority_exclusivity';

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Load active rules (cached per request).
     */
    public function get_active_rules()
    {
        if ($this->cached_rules === null) {
            $this->cached_rules = RuleModel::get_active_rules();
        }
        return $this->cached_rules;
    }

    /**
     * Clear the request cache (useful after saving a rule).
     */
    public function clear_cache()
    {
        $this->cached_rules = null;
        $this->cached_cart_hash = null;
        $this->cached_cart_item_prices = null;
        $this->cached_cart_level_discounts = null;
    }

    /**
     * Get the current compounding strategy.
     *
     * @return string
     */
    public function get_compounding_strategy()
    {
        return apply_filters('drw_compounding_strategy', $this->compounding_strategy);
    }

    /**
     * Set the current compounding strategy.
     *
     * @param string $strategy
     */
    public function set_compounding_strategy($strategy)
    {
        $this->compounding_strategy = $strategy;
    }

    /**
     * Check if a rule's conditions are fully satisfied.
     *
     * @param array $rule Rule data array
     * @param \WC_Cart|null $cart Cart object
     * @param \WC_Product|null $product Product object (for catalog/product-specific checks)
     * @return bool True if conditions match
     */
    public function is_rule_matched(array $rule, $cart = null, $product = null)
    {
        $conditions = !empty($rule['conditions']) ? (array)$rule['conditions'] : [];
        if (empty($conditions)) {
            return true;
        }

        // Map condition types to their respective classes
        $map = [
            'subtotal'          => '\\Drw\\App\\Conditions\\CartSubtotal',
            'cart_subtotal'     => '\\Drw\\App\\Conditions\\CartSubtotal',
            'items_count'       => '\\Drw\\App\\Conditions\\CartLineItemsCount',
            'cart_line_items_count' => '\\Drw\\App\\Conditions\\CartLineItemsCount',
            'user_role'         => '\\Drw\\App\\Conditions\\UserRole',
            'user_email'        => '\\Drw\\App\\Conditions\\UserEmail',
            'shipping_location' => '\\Drw\\App\\Conditions\\ShippingLocation',
            'products'          => '\\Drw\\App\\Conditions\\Products',
            'categories'        => '\\Drw\\App\\Conditions\\Categories',
            'billing_city'      => '\\Drw\\App\\Conditions\\BillingCity',
            'cart_coupon'       => '\\Drw\\App\\Conditions\\CartCoupon',
            'coupon'            => '\\Drw\\App\\Conditions\\CartCoupon',
            'cart_item_product_combination' => '\\Drw\\App\\Conditions\\CartItemProductCombination',
            'cart_item_product_onsale'      => '\\Drw\\App\\Conditions\\CartItemProductOnsale',
            'cart_items_quantity'           => '\\Drw\\App\\Conditions\\CartItemsQuantity',
            'items_quantity'                => '\\Drw\\App\\Conditions\\CartItemsQuantity',
            'cart_items_qty'                => '\\Drw\\App\\Conditions\\CartItemsQuantity',
            'cart_items_weight'             => '\\Drw\\App\\Conditions\\CartItemsWeight',
            'order_date'                    => '\\Drw\\App\\Conditions\\OrderDate',
            'date'                          => '\\Drw\\App\\Conditions\\OrderDate',
            'purchase_history'              => '\\Drw\\App\\Conditions\\PurchaseHistory',
            'history'                       => '\\Drw\\App\\Conditions\\PurchaseHistory',
            'user_list'                     => '\\Drw\\App\\Conditions\\UserList',
            'user_logged_in'                => '\\Drw\\App\\Conditions\\UserLoggedIn',
            'logged_in'                     => '\\Drw\\App\\Conditions\\UserLoggedIn',
        ];

        foreach ($conditions as $cond) {
            $type = !empty($cond['type']) ? $cond['type'] : '';
            if (!isset($map[$type])) {
                continue; // Skip unknown conditions or log
            }

            $class_name = $map[$type];
            if (class_exists($class_name)) {
                $evaluator = new $class_name();
                if (!$evaluator->check($cond, $cart, $product)) {
                    return false; // All conditions must pass (AND behavior)
                }
            }
        }

        return true;
    }

    /**
     * Check if a rule's filter matches a product.
     * Filters target which products the discount should apply to.
     *
     * @param array $rule Rule data array
     * @param \WC_Product $product WooCommerce Product
     * @return bool True if product is target of this rule
     */
    public function is_product_targeted_by_rule(array $rule, \WC_Product $product)
    {
        $apply_to = !empty($rule['apply_to']) ? $rule['apply_to'] : 'all_products';
        $filters  = !empty($rule['filters']) ? (array)$rule['filters'] : [];

        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();
        $excluded_ids = !empty($filters['exclude_product_ids']) ? array_map('intval', (array)$filters['exclude_product_ids']) : [];
        $excluded_cats = !empty($filters['exclude_category_ids']) ? array_map('intval', (array)$filters['exclude_category_ids']) : [];

        if (!empty($excluded_ids) && (in_array($product_id, $excluded_ids, true) || ($parent_id && in_array($parent_id, $excluded_ids, true)))) {
            return false;
        }

        if (!empty($excluded_cats)) {
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (empty($product_cats) && $parent_id) {
                $product_cats = wc_get_product_term_ids($parent_id, 'product_cat');
            }
            if (!empty(array_intersect($product_cats, $excluded_cats))) {
                return false;
            }
        }

        if ($apply_to === 'all_products') {
            return true;
        }

        if ($apply_to === 'specific_products') {
            $target_ids = !empty($filters['product_ids']) ? array_map('intval', (array)$filters['product_ids']) : [];
            return in_array($product_id, $target_ids, true) || ($parent_id && in_array($parent_id, $target_ids, true));
        }

        if ($apply_to === 'specific_categories') {
            $target_cats  = !empty($filters['category_ids']) ? array_map('intval', (array)$filters['category_ids']) : [];
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (empty($product_cats) && $parent_id) {
                $product_cats = wc_get_product_term_ids($parent_id, 'product_cat');
            }
            return !empty(array_intersect($product_cats, $target_cats));
        }

        return false;
    }

    /**
     * Check if a rule is a cart-level rule (applied as a fee or free shipping).
     *
     * @param array $rule
     * @return bool
     */
    public function is_cart_level_rule(array $rule)
    {
        $apply_to = !empty($rule['apply_to']) ? $rule['apply_to'] : 'all_products';
        if ($apply_to !== 'all_products') {
            return false;
        }

        $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
        $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

        if ($type === 'free_shipping') {
            return true;
        }

        if (in_array($type, ['percentage', 'fixed'])) {
            $conditions = !empty($rule['conditions']) ? (array)$rule['conditions'] : [];
            foreach ($conditions as $cond) {
                $cond_type = !empty($cond['type']) ? $cond['type'] : '';
                if (in_array($cond_type, ['subtotal', 'cart_subtotal', 'items_count', 'cart_line_items_count', 'cart_coupon', 'coupon'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a rule should be skipped because coupons are applied and stacking is disabled.
     *
     * @param array         $rule Rule array with optional no_coupon_stacking key.
     * @param \WC_Cart|null $cart Cart object when available.
     * @return bool
     */
    private function should_skip_due_to_coupons(array $rule, $cart = null)
    {
        $global_no_stack = (bool)get_option('drw_global_no_coupon_stacking', false);
        $rule_no_stack   = !empty($rule['no_coupon_stacking']);

        if (!$global_no_stack && !$rule_no_stack) {
            return false;
        }

        if ($cart !== null) {
            return !empty($cart->get_applied_coupons());
        }

        $wc = function_exists('WC') ? WC() : null;
        return $wc && !empty($wc->session) && !empty($wc->session->get('applied_coupons'));
    }

    /**
     * Calculate catalog discount for a specific product.
     * Used for product page and shop loops to show dynamic pricing.
     *
     * @param \WC_Product $product
     * @param float $original_price
     * @return float|null The new price, or null if no rule applies
     */
    public function calculate_catalog_discount(\WC_Product $product, $original_price)
    {
        $rules = $this->get_active_rules();
        if (empty($rules)) {
            return null;
        }

        $strategy = $this->get_compounding_strategy();

        if ($strategy === 'highest') {
            $best_price = (float)$original_price;
            $applied = false;

            foreach ($rules as $rule) {
                $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
                $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

                if (!in_array($type, ['percentage', 'fixed', 'bulk'])) {
                    continue;
                }

                if (!$this->is_rule_matched($rule, null, $product)) {
                    continue;
                }

                if (!$this->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }

                if ($this->should_skip_due_to_coupons($rule)) {
                    continue;
                }

                $temp_price = (float)$original_price;
                if ($type === 'percentage') {
                    $discount_val = (float)$adjustments['value'];
                    $temp_price = max(0.0, $temp_price - ($temp_price * ($discount_val / 100)));
                } elseif ($type === 'fixed') {
                    $discount_val = (float)$adjustments['value'];
                    $temp_price = max(0.0, $temp_price - $discount_val);
                } elseif ($type === 'bulk') {
                    $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
                    foreach ($tiers as $tier) {
                        $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                        $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;
                        if (1 >= $min_qty && 1 <= $max_qty) {
                            $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                            $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);

                            if ($tier_type === 'percentage') {
                                $temp_price = max(0.0, $temp_price - ($temp_price * ($tier_value / 100)));
                            } elseif ($tier_type === 'fixed') {
                                $temp_price = max(0.0, $temp_price - $tier_value);
                            }
                            break;
                        }
                    }
                }

                if ($temp_price < $best_price) {
                    $best_price = $temp_price;
                    $applied = true;
                }
            }

            return $applied ? $best_price : null;

        } else {
            $price = (float)$original_price;
            $applied = false;

            foreach ($rules as $rule) {
                $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
                $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

                if (!in_array($type, ['percentage', 'fixed', 'bulk'])) {
                    continue;
                }

                if (!$this->is_rule_matched($rule, null, $product)) {
                    continue;
                }

                if (!$this->is_product_targeted_by_rule($rule, $product)) {
                    continue;
                }

                if ($this->should_skip_due_to_coupons($rule)) {
                    continue;
                }

                $rule_applied = false;
                if ($type === 'percentage') {
                    $discount_val = (float)$adjustments['value'];
                    $price = max(0.0, $price - ($price * ($discount_val / 100)));
                    $rule_applied = true;
                } elseif ($type === 'fixed') {
                    $discount_val = (float)$adjustments['value'];
                    $price = max(0.0, $price - $discount_val);
                    $rule_applied = true;
                } elseif ($type === 'bulk') {
                    $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
                    foreach ($tiers as $tier) {
                        $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                        $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;
                        if (1 >= $min_qty && 1 <= $max_qty) {
                            $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                            $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);

                            if ($tier_type === 'percentage') {
                                $price = max(0.0, $price - ($price * ($tier_value / 100)));
                            } elseif ($tier_type === 'fixed') {
                                $price = max(0.0, $price - $tier_value);
                            }
                            $rule_applied = true;
                            break;
                        }
                    }
                }

                if ($rule_applied) {
                    $applied = true;
                    if ($strategy === 'priority_exclusivity' && $rule['exclusive']) {
                        break;
                    }
                }
            }

            return $applied ? $price : null;
        }
    }

    /**
     * Evaluate rules on a specific cart item to compute its final price.
     * Takes quantity into account for bulk tier matching.
     *
     * @param array $cart_item WooCommerce Cart item array
     * @param \WC_Cart $cart WooCommerce Cart
     * @return float|null The new price, or null if no adjustment applies
     */
    public function calculate_cart_item_discount(array $cart_item, \WC_Cart $cart)
    {
        $this->calculate_all_cart_discounts($cart);

        $key = isset($cart_item['key']) ? $cart_item['key'] : '';
        if (empty($key)) {
            foreach ($cart->get_cart() as $k => $item) {
                if ($item['data'] === $cart_item['data']) {
                    $key = $k;
                    break;
                }
            }
        }

        if ($key && isset($this->cached_cart_item_prices[$key])) {
            $discounted_price = $this->cached_cart_item_prices[$key];
            $regular_price = (float)$cart_item['data']->get_regular_price();
            if ($discounted_price < $regular_price) {
                return $discounted_price;
            }
        }

        return null;
    }

    /**
     * Calculate cart-wide fee/discount and shipping status.
     *
     * @param \WC_Cart $cart
     * @return array Array describing the adjustments made:
     *               [
     *                   'fees' => [ [ 'name' => string, 'amount' => float ], ... ],
     *                   'free_shipping' => bool
     *               ]
     */
    public function calculate_cart_level_discounts($cart)
    {
        $this->calculate_all_cart_discounts($cart);

        return $this->cached_cart_level_discounts;
    }

    /**
     * Return the cached per-item discounted prices keyed by cart item key.
     *
     * @return array|null
     */
    public function get_cached_cart_item_prices()
    {
        return $this->cached_cart_item_prices;
    }

    /**
     * Return the cached cart-level discount data (fees + free_shipping flag).
     *
     * @return array|null
     */
    public function get_cached_cart_level_discounts()
    {
        return $this->cached_cart_level_discounts;
    }

    /**
     * Calculate all discounts for the cart in a single pass.
     *
     * @param \WC_Cart $cart
     */
    public function calculate_all_cart_discounts($cart)
    {
        if (!$cart || $cart->is_empty()) {
            $this->cached_cart_item_prices = [];
            $this->cached_cart_level_discounts = [
                'fees' => [],
                'free_shipping' => false,
            ];
            $this->cached_cart_hash = null;
            return;
        }

        // Generate cart hash
        $hash_parts = [];
        foreach ($cart->get_cart() as $key => $item) {
            $hash_parts[$key] = [
                'id' => $item['product_id'],
                'variation_id' => $item['variation_id'],
                'qty' => $item['quantity'],
                'price' => (float)$item['data']->get_regular_price(),
            ];
        }
        $cart_hash = md5(serialize($hash_parts));

        if ($this->cached_cart_item_prices !== null && $this->cached_cart_hash === $cart_hash) {
            return;
        }

        $rules = $this->get_active_rules();
        $item_regular_prices = [];
        $item_prices = [];
        foreach ($cart->get_cart() as $key => $item) {
            $reg_price = (float)$item['data']->get_regular_price();
            $item_regular_prices[$key] = $reg_price;
            $item_prices[$key] = $reg_price;
        }

        if (empty($rules)) {
            $this->cached_cart_item_prices = $item_prices;
            $this->cached_cart_level_discounts = [
                'fees' => [],
                'free_shipping' => false,
            ];
            $this->cached_cart_hash = $cart_hash;
            return;
        }

        $strategy = $this->get_compounding_strategy();

        // Backup original cart prices and subtotal
        $original_prices = [];
        foreach ($cart->get_cart() as $key => $item) {
            $original_prices[$key] = $item['data']->get_price();
        }
        $original_subtotal = $cart->get_subtotal();

        $apply_current_prices = function($prices) use ($cart) {
            $current_subtotal = 0.0;
            foreach ($cart->get_cart() as $key => $item) {
                $price = isset($prices[$key]) ? $prices[$key] : (float)$item['data']->get_regular_price();
                $item['data']->set_price($price);
                $current_subtotal += $price * $item['quantity'];
            }
            if (method_exists($cart, 'set_subtotal')) {
                $cart->set_subtotal($current_subtotal);
            } else {
                $cart->subtotal = $current_subtotal;
            }
            return $current_subtotal;
        };

        $restore_cart = function() use ($cart, $original_prices, $original_subtotal) {
            foreach ($cart->get_cart() as $key => $item) {
                if (isset($original_prices[$key])) {
                    $item['data']->set_price($original_prices[$key]);
                }
            }
            if (method_exists($cart, 'set_subtotal')) {
                $cart->set_subtotal($original_subtotal);
            } else {
                $cart->subtotal = $original_subtotal;
            }
        };

        $fees = [];
        $free_shipping = false;

        if ($strategy === 'highest') {
            $best_item_prices = $item_regular_prices;
            $best_fees = [];
            $best_free_shipping = false;
            $max_savings = 0.0;

            foreach ($rules as $rule) {
                $apply_current_prices($item_regular_prices);

                if (!$this->is_rule_matched($rule, $cart)) {
                    continue;
                }

                if ($this->should_skip_due_to_coupons($rule, $cart)) {
                    continue;
                }

                $temp_prices = $item_regular_prices;
                $temp_fees = [];
                $temp_free_shipping = false;

                $this->apply_rule_adjustments($rule, $cart, $temp_prices, $temp_fees, $temp_free_shipping);

                $savings = 0.0;
                foreach ($cart->get_cart() as $key => $item) {
                    $savings += ($item_regular_prices[$key] - $temp_prices[$key]) * $item['quantity'];
                }
                foreach ($temp_fees as $fee) {
                    $savings += abs($fee['amount']);
                }

                if ($savings > $max_savings) {
                    $max_savings = $savings;
                    $best_item_prices = $temp_prices;
                    $best_fees = $temp_fees;
                    $best_free_shipping = $temp_free_shipping;
                }
            }

            $item_prices = $best_item_prices;
            $fees = $best_fees;
            $free_shipping = $best_free_shipping;

        } else {
            foreach ($rules as $rule) {
                $apply_current_prices($item_prices);

                if (!$this->is_rule_matched($rule, $cart)) {
                    continue;
                }

                if ($this->should_skip_due_to_coupons($rule, $cart)) {
                    continue;
                }

                $old_prices = $item_prices;
                $old_fees_count = count($fees);
                $old_free_shipping = $free_shipping;

                $this->apply_rule_adjustments($rule, $cart, $item_prices, $fees, $free_shipping);

                $applied = false;
                foreach ($item_prices as $k => $p) {
                    if ($p < $old_prices[$k]) {
                        $applied = true;
                        break;
                    }
                }
                if (count($fees) > $old_fees_count) {
                    $applied = true;
                }
                if ($free_shipping && !$old_free_shipping) {
                    $applied = true;
                }

                if ($applied && $strategy === 'priority_exclusivity' && !empty($rule['exclusive'])) {
                    break;
                }
            }
        }

        $restore_cart();

        $this->cached_cart_item_prices = $item_prices;
        $this->cached_cart_level_discounts = [
            'fees' => $fees,
            'free_shipping' => $free_shipping,
        ];
        $this->cached_cart_hash = $cart_hash;
    }

    /**
     * Apply a single rule's adjustments to the item prices, fees, and free shipping status.
     *
     * @param array $rule
     * @param \WC_Cart $cart
     * @param array &$item_prices
     * @param array &$fees
     * @param bool &$free_shipping
     */
    private function apply_rule_adjustments($rule, $cart, &$item_prices, &$fees, &$free_shipping)
    {
        $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
        $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

        if (empty($type)) {
            return;
        }

        if ($this->is_cart_level_rule($rule)) {
            if ($type === 'free_shipping') {
                $shipping_engine = new FreeShipping();
                if ($shipping_engine->is_free_shipping_unlocked($adjustments, $cart)) {
                    $free_shipping = true;
                }
            } elseif (in_array($type, ['percentage', 'fixed'])) {
                $subtotal = 0.0;
                foreach ($cart->get_cart() as $key => $item) {
                    $subtotal += $item_prices[$key] * $item['quantity'];
                }

                $fee_amount = 0.0;
                if ($type === 'percentage') {
                    $discount_val = (float)$adjustments['value'];
                    $fee_amount = $subtotal * ($discount_val / 100);
                } elseif ($type === 'fixed') {
                    $discount_val = (float)$adjustments['value'];
                    $fee_amount = $discount_val;
                }

                if ($fee_amount > 0) {
                    $fee_amount = min($fee_amount, $subtotal);
                    $fees[] = [
                        'name' => $rule['title'],
                        'amount' => -$fee_amount,
                    ];
                }
            }
        } else {
            if ($type === 'percentage' || $type === 'fixed') {
                $value = (float)$adjustments['value'];
                foreach ($cart->get_cart() as $key => $item) {
                    $product = $item['data'];
                    if ($this->is_product_targeted_by_rule($rule, $product)) {
                        if ($type === 'percentage') {
                            $item_prices[$key] = max(0.0, $item_prices[$key] - $item_prices[$key] * ($value / 100));
                        } elseif ($type === 'fixed') {
                            $item_prices[$key] = max(0.0, $item_prices[$key] - $value);
                        }
                    }
                }
            } elseif ($type === 'bulk') {
                $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
                foreach ($cart->get_cart() as $key => $item) {
                    $product = $item['data'];
                    if ($this->is_product_targeted_by_rule($rule, $product)) {
                        $qty = (int)$item['quantity'];
                        foreach ($tiers as $tier) {
                            $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                            $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;

                            if ($qty >= $min_qty && $qty <= $max_qty) {
                                $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                                $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);

                                if ($tier_type === 'percentage') {
                                    $item_prices[$key] = max(0.0, $item_prices[$key] - $item_prices[$key] * ($tier_value / 100));
                                } elseif ($tier_type === 'fixed') {
                                    $item_prices[$key] = max(0.0, $item_prices[$key] - $tier_value);
                                }
                                break;
                            }
                        }
                    }
                }
            } elseif ($type === 'bogo') {
                $bogo_engine = new Bogo();
                $bogo_results = $bogo_engine->calculate($adjustments, $cart);
                if (!empty($bogo_results)) {
                    foreach ($bogo_results as $key => $new_price) {
                        $item = $cart->get_cart()[$key];
                        $reg_price = (float)$item['data']->get_regular_price();
                        if ($reg_price > 0) {
                            $ratio = $new_price / $reg_price;
                            $item_prices[$key] = $item_prices[$key] * $ratio;
                        }
                    }
                }
            } elseif ($type === 'bundle_set') {
                $bundle_engine = new BundleSet();
                $bundle_results = $bundle_engine->calculate($adjustments, $cart);
                if (!empty($bundle_results['applied']) && !empty($bundle_results['items'])) {
                    foreach ($bundle_results['items'] as $key => $new_price) {
                        $item = $cart->get_cart()[$key];
                        $reg_price = (float)$item['data']->get_regular_price();
                        if ($reg_price > 0) {
                            $ratio = $new_price / $reg_price;
                            $item_prices[$key] = $item_prices[$key] * $ratio;
                        }
                    }
                }
            }
        }
    }
}
