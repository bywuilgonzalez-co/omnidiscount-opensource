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

        $id = !empty($data['id']) ? (int)$data['id'] : null;

        $db_data = [
            'enabled'      => isset($data['enabled']) ? (int)$data['enabled'] : 1,
            'deleted'      => 0,
            'exclusive'    => isset($data['exclusive']) ? (int)$data['exclusive'] : 0,
            'title'        => sanitize_text_field($data['title']),
            'priority'     => isset($data['priority']) ? (int)$data['priority'] : 10,
            'apply_to'     => sanitize_text_field($data['apply_to']),
            'filters'      => is_array($data['filters']) ? json_encode($data['filters']) : $data['filters'],
            'conditions'   => is_array($data['conditions']) ? json_encode($data['conditions']) : $data['conditions'],
            'adjustments'  => is_array($data['adjustments']) ? json_encode($data['adjustments']) : $data['adjustments'],
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
}
