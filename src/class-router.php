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
		// Was 'admin_init' only, which never fires on front-end/Store API
		// checkout traffic -- so a version bump (new columns/tables the
		// reservation system depends on, e.g. wp_drw_rules.limit_user or
		// wp_drw_promo_redemptions) stayed unapplied on a real store until an
		// admin next loaded wp-admin, silently degrading
		// RuleModel::try_reserve_usage() to a hard DB error on every rule
		// (and the disambiguation queries in CartController) in the
		// meantime. 'init' fires on every request (front-end, admin, and
		// Store API/REST) and check_database() is a cheap, cached
		// get_option() comparison on the fast (already-migrated) path, so
		// moving the check here is safe and closes that window.
		add_action( 'init', array( __CLASS__, 'check_database' ), 5 );
		register_activation_hook( dirname( __DIR__, 2 ) . '/discount-rules-woo.php', array( __CLASS__, 'activate_plugin' ) );
		register_deactivation_hook( dirname( __DIR__, 2 ) . '/discount-rules-woo.php', array( __CLASS__, 'deactivate_plugin' ) );
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
		$diagnostics_controller = \Drw\App\Controllers\DiagnosticsController::instance();
		$popup_controller     = \Drw\App\Controllers\PopupController::instance();

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
		$diagnostics_controller->register_hooks();
		$popup_controller->register_hooks();

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

		// SettingsModel::get_all_settings() caches the deep-merged defaults+saved
		// result in a 12h transient. A version bump that adds new keys to
		// get_defaults() (e.g. the 'popup' section) must invalidate that cache
		// immediately -- otherwise, on a real site (not this dev install, where
		// the cache gets flushed manually during testing), a merchant loading
		// Configuración right after updating would silently see stale settings
		// missing the new section for up to 12h, until the transient expires on
		// its own.
		\Drw\App\Models\SettingsModel::flush_cache();

		// Register the daily stale-promo-reservation cleanup cron job here,
		// next to table creation, since this method already runs on both
		// plugin activation and on every version-bump admin_init check. Guarded
		// by wp_next_scheduled() so re-running migrations (e.g. a version bump)
		// never double-schedules the event.
		if ( ! wp_next_scheduled( 'drw_release_stale_promo_reservations' ) ) {
			wp_schedule_event( time(), 'daily', 'drw_release_stale_promo_reservations' );
		}

		// Same pattern, for the popup email-capture feature's own janitorial
		// cleanup (PopupModel::purge_stale_rows(), handled by
		// PopupController::release_stale_claims()): expired unconfirmed
		// 'pending' rows and long-abandoned 'claimed' rows would otherwise
		// permanently block their email under the UNIQUE(email) constraint,
		// since nothing else ever revisits a row once its own short
		// (60s/48h) reclaim windows pass.
		if ( ! wp_next_scheduled( 'drw_popup_release_stale_claims' ) ) {
			wp_schedule_event( time(), 'daily', 'drw_popup_release_stale_claims' );
		}
	}

	/**
	 * Deactivation hook: clear the stale-promo-reservation and popup
	 * stale-claims cron events so a deactivated (or uninstalled) plugin
	 * doesn't leave a dangling wp_next_scheduled() entry with no registered
	 * callback.
	 */
	public static function deactivate_plugin() {
		wp_clear_scheduled_hook( 'drw_release_stale_promo_reservations' );
		wp_clear_scheduled_hook( 'drw_popup_release_stale_claims' );
	}
}
