<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

class RuleModel
{
    /**
     * Get all active and enabled rules, sorted by priority.
     *
     * @return array Array of formatted rules
     */
    public static function get_active_rules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $now = time();

        $query = "SELECT * FROM $table 
                  WHERE enabled = 1 AND deleted = 0 
                  ORDER BY priority ASC, id ASC";

        $results = $wpdb->get_results($query, ARRAY_A);
        $active_rules = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                // Check date limits if set
                if (!empty($row['date_from']) && $now < (int)$row['date_from']) {
                    continue;
                }
                if (!empty($row['date_to']) && $now > (int)$row['date_to']) {
                    continue;
                }

                // Check usage limit
                if (!empty($row['usage_limit']) && (int)$row['used_count'] >= (int)$row['usage_limit']) {
                    continue;
                }

                $active_rules[] = self::format_rule($row);
            }
        }

        return $active_rules;
    }

    /**
     * Get all rules (active, inactive, deleted=0) for Admin display.
     */
    public static function get_all_rules()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        $query = "SELECT * FROM $table WHERE deleted = 0 ORDER BY priority ASC, id ASC";
        $results = $wpdb->get_results($query, ARRAY_A);
        $rules = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                $rules[] = self::format_rule($row);
            }
        }

        return $rules;
    }

    /**
     * Find a single rule by ID.
     */
    public static function get_rule($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND deleted = 0", $id), ARRAY_A);

        return $row ? self::format_rule($row) : null;
    }

    /**
     * Save or update a rule.
     */
    public static function save_rule($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $data = self::sanitize_rule_payload($data);

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        $db_data = [
            'enabled'      => isset($data['enabled']) ? (int)$data['enabled'] : 1,
            'deleted'      => 0,
            'exclusive'    => isset($data['exclusive']) ? (int)$data['exclusive'] : 0,
            'title'        => sanitize_text_field($data['title']),
            'priority'     => isset($data['priority']) ? (int)$data['priority'] : 10,
            'apply_to'     => sanitize_text_field($data['apply_to']),
            'filters'      => $json_encode($data['filters']),
            'conditions'   => $json_encode($data['conditions']),
            'adjustments'  => $json_encode($data['adjustments']),
            'date_from'    => !empty($data['date_from']) ? (int)$data['date_from'] : null,
            'date_to'      => !empty($data['date_to']) ? (int)$data['date_to'] : null,
            'usage_limit'  => !empty($data['usage_limit']) ? (int)$data['usage_limit'] : null,
            'modified_at'  => current_time('mysql'),
        ];

        if ($id) {
            $wpdb->update($table, $db_data, ['id' => $id]);
            return $id;
        } else {
            $db_data['created_at'] = current_time('mysql');
            $db_data['used_count'] = 0;
            $wpdb->insert($table, $db_data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Normalize rule payloads before persistence.
     */
    public static function sanitize_rule_payload($data)
    {
        $data = is_array($data) ? $data : [];
        $allowed_apply_to = ['all_products', 'specific_products', 'specific_categories'];
        $apply_to = !empty($data['apply_to']) ? sanitize_text_field($data['apply_to']) : 'all_products';
        if (!in_array($apply_to, $allowed_apply_to, true)) {
            $apply_to = 'all_products';
        }

        $data['title'] = !empty($data['title']) ? sanitize_text_field($data['title']) : '';
        $data['apply_to'] = $apply_to;
        $data['filters'] = self::sanitize_filters(!empty($data['filters']) && is_array($data['filters']) ? $data['filters'] : []);
        $data['conditions'] = self::sanitize_conditions(!empty($data['conditions']) && is_array($data['conditions']) ? $data['conditions'] : []);
        $data['adjustments'] = self::sanitize_adjustments(!empty($data['adjustments']) && is_array($data['adjustments']) ? $data['adjustments'] : []);

        return $data;
    }

    /**
     * Mark a rule as deleted (soft delete).
     */
    public static function delete_rule($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';

        return $wpdb->update($table, ['deleted' => 1], ['id' => (int)$id]);
    }

    /**
     * Increment usage limit counter for a rule.
     */
    public static function increment_usage($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_rules';
        $wpdb->query($wpdb->prepare("UPDATE $table SET used_count = used_count + 1 WHERE id = %d", $id));
    }

    /**
     * Format DB row values into PHP arrays/objects.
     */
    private static function format_rule($row)
    {
        $row['id']          = (int)$row['id'];
        $row['enabled']     = (int)$row['enabled'] === 1;
        $row['deleted']     = (int)$row['deleted'] === 1;
        $row['exclusive']   = (int)$row['exclusive'] === 1;
        $row['priority']    = (int)$row['priority'];
        $row['usage_limit'] = !empty($row['usage_limit']) ? (int)$row['usage_limit'] : null;
        $row['used_count']  = (int)$row['used_count'];
        $row['date_from']   = !empty($row['date_from']) ? (int)$row['date_from'] : null;
        $row['date_to']     = !empty($row['date_to']) ? (int)$row['date_to'] : null;

        $row['filters']     = !empty($row['filters']) ? json_decode($row['filters'], true) : [];
        $row['conditions']   = !empty($row['conditions']) ? json_decode($row['conditions'], true) : [];
        $row['adjustments']  = !empty($row['adjustments']) ? json_decode($row['adjustments'], true) : [];

        return $row;
    }

    /**
     * Sanitize target filters.
     */
    private static function sanitize_filters($filters)
    {
        $filters['product_ids'] = self::normalize_id_list(isset($filters['product_ids']) ? $filters['product_ids'] : []);
        $filters['category_ids'] = self::normalize_id_list(isset($filters['category_ids']) ? $filters['category_ids'] : []);

        return $filters;
    }

    /**
     * Sanitize condition rows while preserving supported condition-specific fields.
     */
    private static function sanitize_conditions($conditions)
    {
        $normalized = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $condition = self::sanitize_deep($condition);

            if (isset($condition['product_ids'])) {
                $condition['product_ids'] = self::normalize_id_list($condition['product_ids']);
            }
            if (isset($condition['category_ids'])) {
                $condition['category_ids'] = self::normalize_id_list($condition['category_ids']);
            }
            if (isset($condition['value']) && is_array($condition['value'])) {
                $condition['value'] = self::normalize_id_list($condition['value']);
            }

            $normalized[] = $condition;
        }

        return $normalized;
    }

    /**
     * Sanitize adjustment payloads and normalize legacy admin field names.
     */
    private static function sanitize_adjustments($adjustments)
    {
        $adjustments = self::sanitize_deep($adjustments);
        $type = !empty($adjustments['type']) ? sanitize_text_field($adjustments['type']) : 'percentage';
        if ($type === 'bundle') {
            $type = 'bundle_set';
        }

        $allowed_types = ['percentage', 'fixed', 'bulk', 'bogo', 'free_shipping', 'bundle_set'];
        if (!in_array($type, $allowed_types, true)) {
            $type = 'percentage';
        }
        $adjustments['type'] = $type;

        if ($type === 'bogo') {
            if (!empty($adjustments['get_product_id'])) {
                $adjustments['get_products'] = self::normalize_id_list([$adjustments['get_product_id']]);
                unset($adjustments['get_product_id']);
            } elseif (isset($adjustments['get_products'])) {
                $adjustments['get_products'] = self::normalize_id_list($adjustments['get_products']);
            }

            if (isset($adjustments['buy_products'])) {
                $adjustments['buy_products'] = self::normalize_id_list($adjustments['buy_products']);
            }
            if (isset($adjustments['buy_categories'])) {
                $adjustments['buy_categories'] = self::normalize_id_list($adjustments['buy_categories']);
            }
            if (isset($adjustments['get_categories'])) {
                $adjustments['get_categories'] = self::normalize_id_list($adjustments['get_categories']);
            }
            if (!empty($adjustments['bogo_discount_type']) && empty($adjustments['discount_type'])) {
                $adjustments['discount_type'] = sanitize_text_field($adjustments['bogo_discount_type']);
                unset($adjustments['bogo_discount_type']);
            }
            if (isset($adjustments['bogo_value']) && !isset($adjustments['discount_value'])) {
                $adjustments['discount_value'] = (float)$adjustments['bogo_value'];
                unset($adjustments['bogo_value']);
            }
        }

        if ($type === 'bundle_set') {
            if (isset($adjustments['set_price']) && !isset($adjustments['bundle_price'])) {
                $adjustments['bundle_price'] = (float)$adjustments['set_price'];
                unset($adjustments['set_price']);
            }
            if (isset($adjustments['bundle_items']) && is_array($adjustments['bundle_items'])) {
                foreach ($adjustments['bundle_items'] as $index => $item) {
                    if (isset($item['id'])) {
                        $adjustments['bundle_items'][$index]['id'] = (int)$item['id'];
                    }
                    if (isset($item['product_id'])) {
                        $adjustments['bundle_items'][$index]['product_id'] = (int)$item['product_id'];
                    }
                    if (isset($item['qty'])) {
                        $adjustments['bundle_items'][$index]['qty'] = max(1, (int)$item['qty']);
                    }
                }
            }
        }

        return $adjustments;
    }

    /**
     * Recursively sanitize scalar values inside rule JSON payloads.
     */
    private static function sanitize_deep($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[sanitize_text_field((string)$key)] = self::sanitize_deep($item);
            }
            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return sanitize_text_field((string)$value);
    }

    /**
     * Normalize mixed ID lists into unique positive integers.
     */
    private static function normalize_id_list($ids)
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
