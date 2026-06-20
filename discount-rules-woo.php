<?php
/**
 * Plugin Name: OmniDiscount — Dynamic Pricing & Discount Rules for WooCommerce
 * Plugin URI: https://bywuilgonzalez.com
 * Description: Premium dynamic pricing, discount rules, and promotional coupon engine for WooCommerce.
 * Author: Bywuilgonzalez.com
 * Author URI: https://bywuilgonzalez.com
 * Version: 1.4.0
 * Text Domain: discount-rules-woo
 * Domain Path: /languages/
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 10.8
 * License: GPLv3 or later
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Define core constants
define('DRW_VERSION', '1.4.0');
define('DRW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DRW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DRW_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DRW_TEXT_DOMAIN', 'discount-rules-woo');
define('DRW_DB_PREFIX', 'drw_');

// Check and load Composer autoload
$autoload_file = DRW_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($autoload_file)) {
    require_once $autoload_file;
} else {
    // Fallback autoloader: supports class-name.php (PHPCS) and ClassName.php (legacy)
    spl_autoload_register(function ($class) {
        $prefix = 'Drw\\App\\';
        $len    = strlen($prefix);
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }
        $relative_class = substr($class, $len);
        $parts          = explode('\\', $relative_class);
        $class_name     = array_pop($parts);
        $dir            = DRW_PLUGIN_PATH . 'src/' . (empty($parts) ? '' : implode('/', $parts) . '/');

        // PHPCS convention: class-{lowercased}.php
        $file = $dir . 'class-' . strtolower($class_name) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // Legacy convention: ClassName.php
        $file = $dir . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Bootstrap the plugin on plugins_loaded
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        // Initialize the Router
        \Drw\App\Router::instance();
    } else {
        // Display admin notice if WooCommerce is missing
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . esc_html__('OmniDiscount requires WooCommerce to be active.', 'discount-rules-woo') . '</p></div>';
        });
    }
}, 10);
