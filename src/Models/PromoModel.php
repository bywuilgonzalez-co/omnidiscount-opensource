<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

class PromoModel
{
    /**
     * Get all promos that have not been soft-deleted, newest first.
     *
     * @return array Array of formatted promos
     */
    public static function get_all_promos()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        $query = "SELECT * FROM $table WHERE deleted_at IS NULL ORDER BY created_at DESC";
        $results = $wpdb->get_results($query, ARRAY_A);
        $promos = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                $promos[] = self::format_promo($row);
            }
        }

        return $promos;
    }

    /**
     * Find a single promo by ID.
     */
    public static function get_promo($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND deleted_at IS NULL", $id), ARRAY_A);

        return $row ? self::format_promo($row) : null;
    }

    /**
     * Find a single promo by its redeemable code.
     */
    public static function get_promo_by_code($code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code = %s AND deleted_at IS NULL", $code), ARRAY_A);

        return $row ? self::format_promo($row) : null;
    }

    /**
     * Insert a new promo atomically and return its new ID.
     */
    public static function insert($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        $data = self::encode_json_fields(is_array($data) ? $data : []);
        // Preserve a caller-supplied historical counter (e.g. the legacy
        // migration passes the promo's prior `uses`); default to 0 for the
        // REST create path, which deliberately omits it.
        $data['uses']        = isset($data['uses']) ? (int) $data['uses'] : 0;
        $data['created_at']  = current_time('mysql');
        $data['modified_at'] = current_time('mysql');
        $data['deleted_at']  = null;

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Update an existing promo atomically.
     */
    public static function update($id, $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        $data = self::encode_json_fields(is_array($data) ? $data : []);
        $data['modified_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => (int)$id]);
    }

    /**
     * Mark a promo as deleted (soft delete).
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        return $wpdb->update($table, ['deleted_at' => current_time('mysql')], ['id' => (int)$id]);
    }

    /**
     * Restore a soft-deleted promo.
     */
    public static function restore($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        return $wpdb->update($table, ['deleted_at' => null], ['id' => (int)$id]);
    }

    /**
     * Increment the usage counter for a promo.
     */
    public static function increment_usage($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';
        $wpdb->query($wpdb->prepare("UPDATE $table SET uses = uses + 1 WHERE id = %d", $id));
    }

    /**
     * Check whether a promo code is already in use (case-sensitive, as stored).
     *
     * @param string   $code       Code to look up (already uppercased by the controller).
     * @param int|null $exclude_id Promo ID to exclude from the check (the promo being updated).
     * @return bool True if the code already exists.
     */
    public static function code_exists($code, $exclude_id = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drw_promos';

        if ($exclude_id !== null) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE code = %s AND deleted_at IS NULL AND id != %d",
                $code,
                (int)$exclude_id
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE code = %s AND deleted_at IS NULL",
                $code
            ));
        }

        return (int)$count > 0;
    }

    /**
     * Format DB row values into PHP arrays/scalars.
     */
    private static function format_promo($row)
    {
        $row['id']           = (int)$row['id'];
        $row['uses']         = (int)$row['uses'];
        $row['limit_global'] = !empty($row['limit_global']) ? (int)$row['limit_global'] : null;
        $row['limit_user']   = !empty($row['limit_user']) ? (int)$row['limit_user'] : null;
        $row['active']       = (int)$row['active'] === 1;
        $row['home']         = (int)$row['home'] === 1;

        $row['scope']        = !empty($row['scope']) ? json_decode($row['scope'], true) : null;
        $row['gift_config']  = !empty($row['gift_config']) ? json_decode($row['gift_config'], true) : null;
        $row['tier_config']  = !empty($row['tier_config']) ? json_decode($row['tier_config'], true) : null;

        return $row;
    }

    /**
     * JSON-encode the promo config columns when they arrive as arrays.
     */
    private static function encode_json_fields($data)
    {
        $json_encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        foreach (['scope', 'gift_config', 'tier_config'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = $json_encode($data[$field]);
            }
        }

        return $data;
    }
}
