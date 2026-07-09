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
		add_action( 'wpmu_new_blog', array( __CLASS__, 'activate_new_site' ) );

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
	 *
	 * When activated network-wide on a multisite install, create the tables
	 * on every existing site in the network. Otherwise, create them only on
	 * the current site (covers single-site installs and per-site activation
	 * on an existing multisite network).
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	public static function activate_plugin( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids' ) );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::run_migrations();
				restore_current_blog();
			}

			return;
		}

		self::run_migrations();
	}

	/**
	 * Create the tables for a newly created site when the plugin is active
	 * network-wide, so new sites in the network get the tables automatically.
	 *
	 * @param int $blog_id ID of the newly created site.
	 */
	public static function activate_new_site( $blog_id ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( DRW_PLUGIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		self::run_migrations();
		restore_current_blog();
	}

	/**
	 * Run the table creation/migration for the current blog context.
	 */
	private static function run_migrations() {
		\Drw\App\Models\Database::create_tables();
		update_option( 'drw_db_version', DRW_VERSION );
	}
}
