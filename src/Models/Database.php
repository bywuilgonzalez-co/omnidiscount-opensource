<?php

namespace Drw\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

class Database
{
    /**
     * Create custom tables for storing discount rules and order discount stats.
     */
    public static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Rules table
        $rules_table = $wpdb->prefix . 'drw_rules';
        $rules_sql = "CREATE TABLE $rules_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            enabled TINYINT(1) DEFAULT 1,
            deleted TINYINT(1) DEFAULT 0,
            exclusive TINYINT(1) DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            priority INT(11) DEFAULT 10,
            apply_to VARCHAR(100) DEFAULT 'all_products',
            filters LONGTEXT NOT NULL,
            conditions LONGTEXT DEFAULT NULL,
            adjustments LONGTEXT NOT NULL,
            date_from INT(11) DEFAULT NULL,
            date_to INT(11) DEFAULT NULL,
            usage_limit INT(11) DEFAULT NULL,
            used_count INT(11) DEFAULT 0,
            source VARCHAR(16) DEFAULT NULL,
            promo_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            modified_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY enabled_deleted_idx (enabled, deleted),
            KEY source_idx (source)
        ) $charset_collate;";

        dbDelta($rules_sql);

        // Order discounts table
        $order_discounts_table = $wpdb->prefix . 'drw_order_discounts';
        $discounts_sql = "CREATE TABLE $order_discounts_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) NOT NULL,
            discount_amount FLOAT NOT NULL,
            details LONGTEXT NOT NULL,
            free_shipping TINYINT(1) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY order_id_idx (order_id)
        ) $charset_collate;";

        dbDelta($discounts_sql);

        // Promos table
        $promos_table = $wpdb->prefix . 'drw_promos';
        $promos_sql = "CREATE TABLE $promos_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            code VARCHAR(64) NULL,
            type VARCHAR(32) NOT NULL,
            value DECIMAL(20,4) NOT NULL DEFAULT 0,
            scope LONGTEXT NULL,
            min_amount DECIMAL(20,4) NULL,
            limit_global INT NULL,
            limit_user INT NULL,
            uses INT NOT NULL DEFAULT 0,
            date_from DATETIME NULL,
            date_to DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            home TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            cart_message VARCHAR(255) NULL,
            gift_config LONGTEXT NULL,
            tier_config LONGTEXT NULL,
            wc_coupon_id BIGINT UNSIGNED NULL,
            rule_id BIGINT UNSIGNED NULL,
            template_origin VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            modified_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_unique (code),
            KEY active_idx (active, deleted_at),
            KEY dates_idx (date_from, date_to),
            KEY type_idx (type)
        ) $charset_collate;";

        dbDelta($promos_sql);

        // Add sample rules if empty
        self::add_sample_rules();
    }

    /**
     * Add a sample rule if the table is empty.
     */
    public static function add_sample_rules()
    {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'drw_rules';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $rules_table");

        if ((int)$count === 0) {
            $wpdb->insert(
                $rules_table,
                [
                    'enabled' => 0, // Disabled by default
                    'deleted' => 0,
                    'exclusive' => 0,
                    'title' => esc_html__('Sample Storewide 10% Discount', 'discount-rules-woo'),
                    'priority' => 10,
                    'apply_to' => 'all_products',
                    'filters' => json_encode([]),
                    'conditions' => json_encode([]),
                    'adjustments' => json_encode([
                        'type' => 'percentage', // percentage, fixed, bogo, bulk
                        'value' => 10,
                    ]),
                    'created_at' => current_time('mysql'),
                    'modified_at' => current_time('mysql'),
                ]
            );
        }
    }
}
