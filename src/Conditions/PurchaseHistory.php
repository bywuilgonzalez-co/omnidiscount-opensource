<?php

namespace Drw\App\Conditions;

if (!defined('ABSPATH')) {
    exit;
}

class PurchaseHistory implements ConditionInterface
{
    /**
     * Check if user historical purchase criteria are met.
     *
     * @param array $data The condition configuration.
     * @param \WC_Cart|null $cart WooCommerce Cart object.
     * @param \WC_Product|null $product WooCommerce Product object.
     * @return bool
     */
    public function check(array $data, $cart = null, $product = null)
    {
        $user_id = get_current_user_id();
        $email = '';

        if ($user_id > 0) {
            $current_user = wp_get_current_user();
            if ($current_user) {
                $email = $current_user->user_email;
            }
        }

        // Fallbacks for billing email (checkout context / session)
        if (empty($email) && !empty($_POST['billing_email'])) {
            $email = sanitize_email(wp_unslash($_POST['billing_email']));
        }
        if (empty($email) && !empty(WC()->customer)) {
            $email = WC()->customer->get_billing_email();
        }

        // If no user ID and no email, we cannot fetch history.
        // Unless we are checking if it's a first order (which is true for new visitors).
        $check_type = !empty($data['check_type']) ? $data['check_type'] : 'spent_total';
        $operator = !empty($data['operator']) ? $data['operator'] : 'greater_than_or_equal';
        $value = isset($data['value']) ? $data['value'] : 0;

        if ($user_id <= 0 && empty($email)) {
            if ($check_type === 'first_order') {
                $target_first_order = filter_var($value, FILTER_VALIDATE_BOOLEAN) || in_array(strtolower($value), ['yes', '1', 'true'], true);
                return $target_first_order; // Guest with no email is treated as first order
            }
            return false;
        }

        // Query successful orders (HPOS compatible)
        $order_ids = [];
        if ($user_id > 0) {
            $user_orders = wc_get_orders([
                'customer' => $user_id,
                'status'   => ['completed', 'processing'],
                'limit'    => -1,
                'return'   => 'ids',
            ]);
            if (is_array($user_orders)) {
                $order_ids = array_merge($order_ids, $user_orders);
            }
        }

        if (!empty($email)) {
            $email_orders = wc_get_orders([
                'billing_email' => $email,
                'status'        => ['completed', 'processing'],
                'limit'         => -1,
                'return'        => 'ids',
            ]);
            if (is_array($email_orders)) {
                $order_ids = array_merge($order_ids, $email_orders);
            }
        }

        $order_ids = array_unique($order_ids);

        // Gather metrics
        $spent_total = 0.0;
        $orders_count = count($order_ids);
        $purchased_product_ids = [];
        $purchased_category_ids = [];

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $spent_total += (float)$order->get_total();

            // Extract products and categories purchased
            foreach ($order->get_items() as $item) {
                $p_id = (int)$item->get_product_id();
                $v_id = (int)$item->get_variation_id();

                $purchased_product_ids[] = $p_id;
                if ($v_id > 0) {
                    $purchased_product_ids[] = $v_id;
                }

                $cats = wc_get_product_term_ids($p_id, 'product_cat');
                if (!empty($cats)) {
                    $purchased_category_ids = array_merge($purchased_category_ids, $cats);
                }
            }
        }

        $purchased_product_ids = array_unique($purchased_product_ids);
        $purchased_category_ids = array_unique(array_map('intval', $purchased_category_ids));

        // Evaluate criteria based on check_type
        switch ($check_type) {
            case 'spent_total':
                return $this->evaluate_numeric($spent_total, $operator, (float)$value);

            case 'orders_count':
                return $this->evaluate_numeric($orders_count, $operator, (int)$value);

            case 'first_order':
                $target_first_order = filter_var($value, FILTER_VALIDATE_BOOLEAN) || in_array(strtolower($value), ['yes', '1', 'true'], true);
                $is_first_order = ($orders_count === 0);
                return $is_first_order === $target_first_order;

            case 'previous_purchase_products':
                $target_product_ids = !empty($data['value']) ? array_map('intval', (array)$data['value']) : [];
                if (empty($target_product_ids) && !empty($data['product_ids'])) {
                    $target_product_ids = array_map('intval', (array)$data['product_ids']);
                }

                $intersection = array_intersect($purchased_product_ids, $target_product_ids);
                $has_match = !empty($intersection);

                return ($operator === 'in_list') ? $has_match : !$has_match;

            case 'previous_purchase_categories':
                $target_category_ids = !empty($data['value']) ? array_map('intval', (array)$data['value']) : [];
                if (empty($target_category_ids) && !empty($data['category_ids'])) {
                    $target_category_ids = array_map('intval', (array)$data['category_ids']);
                }

                $intersection = array_intersect($purchased_category_ids, $target_category_ids);
                $has_match = !empty($intersection);

                return ($operator === 'in_list') ? $has_match : !$has_match;

            default:
                return false;
        }
    }

    /**
     * Evaluate numeric comparisons.
     */
    private function evaluate_numeric($actual, $operator, $target)
    {
        switch ($operator) {
            case 'greater_than':
                return $actual > $target;
            case 'less_than':
                return $actual < $target;
            case 'equal':
                return $actual == $target;
            case 'greater_than_or_equal':
                return $actual >= $target;
            case 'less_than_or_equal':
                return $actual <= $target;
            default:
                return false;
        }
    }
}
