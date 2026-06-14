<?php

namespace Drw\App\Controllers;

use Drw\App\Models\RuleModel;

if (!defined('ABSPATH')) {
    exit;
}

class RulesEngine
{
    private static $instance = null;
    private $cached_rules = null;

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
            'items_count'       => '\\Drw\\App\\Conditions\\CartLineItemsCount',
            'user_role'         => '\\Drw\\App\\Conditions\\UserRole',
            'user_email'        => '\\Drw\\App\\Conditions\\UserEmail',
            'shipping_location' => '\\Drw\\App\\Conditions\\ShippingLocation',
            'products'          => '\\Drw\\App\\Conditions\\Products',
            'categories'        => '\\Drw\\App\\Conditions\\Categories',
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

        if ($apply_to === 'all_products') {
            return true;
        }

        $product_id = $product->get_id();
        $parent_id  = $product->get_parent_id();

        if ($apply_to === 'specific_products') {
            $target_ids = !empty($filters['product_ids']) ? array_map('intval', (array)$filters['product_ids']) : [];
            return in_array($product_id, $target_ids, true) || ($parent_id && in_array($parent_id, $target_ids, true));
        }

        if ($apply_to === 'specific_categories') {
            $target_cats = !empty($filters['category_ids']) ? array_map('intval', (array)$filters['category_ids']) : [];
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            return !empty(array_intersect($product_cats, $target_cats));
        }

        return false;
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

        $price = (float)$original_price;
        $applied = false;

        foreach ($rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

            // Catalog rules only support simple percentage, fixed product adjustments, and bulk tiering.
            // BOGO is cart-only and handled during cart recalculation.
            if (!in_array($type, ['percentage', 'fixed', 'bulk'])) {
                continue;
            }

            // Check if rule conditions (like user roles, dates) match
            if (!$this->is_rule_matched($rule, null, $product)) {
                continue;
            }

            // Check if this specific product is targeted by the rule
            if (!$this->is_product_targeted_by_rule($rule, $product)) {
                continue;
            }

            // Apply adjustment
            if ($type === 'percentage') {
                $discount_val = (float)$adjustments['value'];
                $price -= ($price * ($discount_val / 100));
                $applied = true;
            } elseif ($type === 'fixed') {
                $discount_val = (float)$adjustments['value'];
                $price = max(0.0, $price - $discount_val);
                $applied = true;
            } elseif ($type === 'bulk') {
                // Bulk discount tiering (checks quantity breaks).
                // In catalog view, we assume qty = 1 or default tier unless we have a specific qty.
                // Let's use the lowest tier or check if bulk adjustments contains tier 1.
                $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
                foreach ($tiers as $tier) {
                    $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                    $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;
                    
                    // Default to tier 1 check (qty = 1)
                    if (1 >= $min_qty && 1 <= $max_qty) {
                        $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                        $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);

                        if ($tier_type === 'percentage') {
                            $price -= ($price * ($tier_value / 100));
                        } elseif ($tier_type === 'fixed') {
                            $price = max(0.0, $price - $tier_value);
                        }
                        $applied = true;
                        break;
                    }
                }
            }

            // Stop processing further rules if this rule is marked exclusive
            if ($applied && $rule['exclusive']) {
                break;
            }
        }

        return $applied ? $price : null;
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
        $product = $cart_item['data'];
        $rules   = $this->get_active_rules();
        if (empty($rules)) {
            return null;
        }

        $original_price = (float)$product->get_regular_price();
        $price = $original_price;
        $qty = (int)$cart_item['quantity'];
        $applied = false;

        foreach ($rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type = !empty($adjustments['type']) ? $adjustments['type'] : '';

            if (!in_array($type, ['percentage', 'fixed', 'bulk'])) {
                continue;
            }

            // Evaluate conditions in cart context
            if (!$this->is_rule_matched($rule, $cart, $product)) {
                continue;
            }

            // Check if product is targeted
            if (!$this->is_product_targeted_by_rule($rule, $product)) {
                continue;
            }

            if ($type === 'percentage') {
                $discount_val = (float)$adjustments['value'];
                $price -= ($price * ($discount_val / 100));
                $applied = true;
            } elseif ($type === 'fixed') {
                $discount_val = (float)$adjustments['value'];
                $price = max(0.0, $price - $discount_val);
                $applied = true;
            } elseif ($type === 'bulk') {
                $tiers = !empty($adjustments['tiers']) ? (array)$adjustments['tiers'] : [];
                foreach ($tiers as $tier) {
                    $min_qty = isset($tier['min']) ? (int)$tier['min'] : 0;
                    $max_qty = isset($tier['max']) && $tier['max'] !== '' ? (int)$tier['max'] : PHP_INT_MAX;

                    if ($qty >= $min_qty && $qty <= $max_qty) {
                        $tier_type  = !empty($tier['type']) ? $tier['type'] : 'percentage';
                        $tier_value = (float)(!empty($tier['value']) ? $tier['value'] : 0);

                        if ($tier_type === 'percentage') {
                            $price -= ($price * ($tier_value / 100));
                        } elseif ($tier_type === 'fixed') {
                            $price = max(0.0, $price - $tier_value);
                        }
                        $applied = true;
                        break;
                    }
                }
            }

            if ($applied && $rule['exclusive']) {
                break;
            }
        }

        return $applied ? $price : null;
    }
}
