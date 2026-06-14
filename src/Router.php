<?php

namespace Drw\App;

if (!defined('ABSPATH')) {
    exit;
}

class Router
{
    private static $instance = null;

    /**
     * Get class instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->init();
    }

    /**
     * Initialize plugin functionality.
     */
    private function init()
    {
        // 1. Database table check/creation
        add_action('admin_init', [__CLASS__, 'check_database']);
        register_activation_hook(dirname(dirname(__FILE__)) . '/discount-rules-woo.php', [__CLASS__, 'activate_plugin']);

        // 2. Load Controllers
        $catalog_controller = \Drw\App\Controllers\CatalogController::instance();
        $cart_controller    = \Drw\App\Controllers\CartController::instance();
        $admin_controller   = \Drw\App\Controllers\AdminController::instance();
        $api_controller     = \Drw\App\Controllers\ApiController::instance();
        $shortcode_controller = \Drw\App\Controllers\ShortcodeController::instance();
        $updater            = \Drw\App\Controllers\Updater::instance();
        $store_api_controller    = \Drw\App\Controllers\StoreApiController::instance();
        $analytics_controller    = \Drw\App\Controllers\AnalyticsController::instance();
        $import_export_controller = \Drw\App\Controllers\ImportExportController::instance();
        $progress_bar_controller = \Drw\App\Controllers\ProgressBarController::instance();

        // 3. Register hooks
        $catalog_controller->register_hooks();
        $cart_controller->register_hooks();
        $admin_controller->register_hooks();
        $api_controller->register_hooks();
        $shortcode_controller->register_hooks();
        $updater->register_hooks();
        $store_api_controller->register_hooks();
        $analytics_controller->register_hooks();
        $import_export_controller->register_hooks();
        $progress_bar_controller->register_hooks();
    }

    /**
     * Check if database table creation or migration is required.
     */
    public static function check_database()
    {
        if (get_option('drw_db_version') !== DRW_VERSION) {
            self::activate_plugin();
        }
    }

    /**
     * Activation hook to run database migrations.
     */
    public static function activate_plugin()
    {
        \Drw\App\Models\Database::create_tables();
        update_option('drw_db_version', DRW_VERSION);
    }
}
