<?php

namespace Drw\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Router {
	private static $instance = null;

	/**
	 * Get class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin functionality.
	 */
	private function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_database' ) );
		register_activation_hook( dirname( __DIR__, 2 ) . '/discount-rules-woo.php', array( __CLASS__, 'activate_plugin' ) );

		$catalog_controller   = \Drw\App\Controllers\CatalogController::instance();
		$cart_controller      = \Drw\App\Controllers\CartController::instance();
		$admin_controller     = \Drw\App\Controllers\AdminController::instance();
		$api_controller       = \Drw\App\Controllers\ApiController::instance();
		$shortcode_controller = \Drw\App\Controllers\ShortcodeController::instance();
		$updater              = \Drw\App\Controllers\Updater::instance();
		$settings_controller  = \Drw\App\Controllers\SettingsController::instance();
		$analytics_controller = \Drw\App\Controllers\AnalyticsController::instance();
		$import_export        = \Drw\App\Controllers\ImportExportController::instance();
		$progress_bar         = \Drw\App\Controllers\ProgressBarController::instance();
		$promos_controller    = \Drw\App\Controllers\PromosController::instance();

		$catalog_controller->register_hooks();
		$cart_controller->register_hooks();
		$admin_controller->register_hooks();
		$api_controller->register_hooks();
		$shortcode_controller->register_hooks();
		$updater->register_hooks();
		$settings_controller->register_hooks();
		$analytics_controller->register_hooks();
		$import_export->register_hooks();
		$progress_bar->register_hooks();
		$promos_controller->register_hooks();

		\Drw\App\Controllers\StoreApiController::instance()->register_hooks();
	}

	/**
	 * Check if database table creation or migration is required.
	 */
	public static function check_database() {
		if ( get_option( 'drw_db_version' ) !== DRW_VERSION ) {
			self::activate_plugin();
		}
	}

	/**
	 * Activation hook to run database migrations.
	 */
	public static function activate_plugin() {
		\Drw\App\Models\Database::create_tables();
		update_option( 'drw_db_version', DRW_VERSION );
	}
}
