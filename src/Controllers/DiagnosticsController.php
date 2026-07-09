<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only diagnostics endpoint for admins.
 *
 * Helps troubleshoot conflicts by reporting:
 *  - which other coupon/dynamic-pricing plugins are active alongside this one
 *  - the priority order of callbacks currently hooked into
 *    woocommerce_before_calculate_totals (where this plugin recalculates
 *    cart item prices), so an admin can see if another plugin runs before
 *    or after it.
 */
class DiagnosticsController
{
    private static $instance = null;

    /**
     * Known coupon / dynamic-pricing plugins that commonly conflict with
     * cart price recalculation. Mapped as:
     *   plugin_basename (as stored in active_plugins) => human-readable label.
     *
     * Basenames are best-effort; some plugins ship multiple free/pro variants
     * so a few common ones are listed per vendor.
     */
    private static $known_plugins = [
        'advanced-coupons-for-woocommerce-free/advanced-coupons-for-woocommerce-free.php' => 'Advanced Coupons',
        'advanced-coupons-for-woocommerce-pro/advanced-coupons-for-woocommerce-pro.php'    => 'Advanced Coupons (Pro)',
        'smart-coupons/woocommerce-smart-coupons.php' => 'Smart Coupons (StoreApps)',
        'woocommerce-smart-coupons/woocommerce-smart-coupons.php' => 'Smart Coupons (StoreApps)',
        'yith-woocommerce-dynamic-pricing-and-discounts/init.php' => 'YITH Dynamic Pricing and Discounts',
        'yith-woocommerce-dynamic-pricing-and-discounts-premium/init.php' => 'YITH Dynamic Pricing and Discounts (Premium)',
        'discount-rules-for-woocommerce/discount-rules-for-woocommerce.php' => 'Discount Rules for WooCommerce (Flycart)',
        'woo-discount-rules/woo-discount-rules.php' => 'Discount Rules for WooCommerce (Flycart)',
        'advanced-dynamic-pricing-for-woocommerce/advanced-dynamic-pricing-for-woocommerce.php' => 'Advanced Dynamic Pricing for WooCommerce',
        'conditional-discounts-for-woocommerce/conditional-discounts-for-woocommerce.php' => 'Conditional Discounts for WooCommerce',
        'woocommerce-conditional-discounts/woocommerce-conditional-discounts.php' => 'Conditional Discounts for WooCommerce',
        'woocommerce-name-your-price/woocommerce-name-your-price.php' => 'Booster (Name Your Price)',
        'booster-for-woocommerce/booster-for-woocommerce.php' => 'Booster for WooCommerce',
        'woocommerce-jetpack/woocommerce-jetpack.php' => 'Booster for WooCommerce (Booster Plus/Jetpack)',
    ];

    /**
     * The hook whose subscriber priorities we report on.
     */
    private static $monitored_hook = 'woocommerce_before_calculate_totals';

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register REST API routes.
     */
    public function register_hooks()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register endpoints.
     */
    public function register_routes()
    {
        register_rest_route('drw/v1', '/diagnostics', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_diagnostics'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission check: admin-only, informational endpoint.
     */
    public function check_permission()
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * GET /drw/v1/diagnostics
     */
    public function get_diagnostics($request)
    {
        return new \WP_REST_Response([
            'conflicting_plugins' => $this->detect_known_plugins(),
            'hook'                => self::$monitored_hook,
            'hook_subscribers'    => $this->get_hook_subscribers(self::$monitored_hook),
        ], 200);
    }

    /**
     * Compare active_plugins (and, on multisite, network-active plugins)
     * against the list of known coupon/dynamic-pricing plugins.
     *
     * @return array List of ['plugin' => basename, 'name' => label] for matches.
     */
    private function detect_known_plugins()
    {
        $active = (array) get_option('active_plugins', []);

        if (is_multisite()) {
            $network_active = get_site_option('active_sitewide_plugins', []);
            if (is_array($network_active) && !empty($network_active)) {
                $active = array_merge($active, array_keys($network_active));
            }
        }

        $active = array_unique($active);
        $found  = [];

        foreach (self::$known_plugins as $basename => $label) {
            if (in_array($basename, $active, true)) {
                $found[] = [
                    'plugin' => $basename,
                    'name'   => $label,
                ];
            }
        }

        return $found;
    }

    /**
     * List priorities/callbacks currently registered for a given hook via
     * the $wp_filter global, without exposing raw closures.
     *
     * Each entry reports the priority and, where identifiable, a
     * "Class::method" or function name. Anonymous closures are reported
     * generically (with their declaring file/line when available) rather
     * than dumping internal object state.
     *
     * @param string $hook_name Hook to inspect.
     * @return array List of ['priority' => int, 'callback' => string] entries.
     */
    private function get_hook_subscribers($hook_name)
    {
        global $wp_filter;

        if (empty($wp_filter[$hook_name]) || !($wp_filter[$hook_name] instanceof \WP_Hook)) {
            return [];
        }

        $subscribers = [];

        foreach ($wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                $subscribers[] = [
                    'priority' => (int) $priority,
                    'callback' => $this->describe_callback($registered['function']),
                ];
            }
        }

        return $subscribers;
    }

    /**
     * Turn a stored callback into a safe, human-readable identifier.
     * Never returns object internals/state — only class/function names.
     *
     * @param callable $callback
     * @return string
     */
    private function describe_callback($callback)
    {
        // 'Class::method' string form.
        if (is_string($callback) && strpos($callback, '::') !== false) {
            return $callback;
        }

        // Plain function name.
        if (is_string($callback)) {
            return $callback;
        }

        // [$object_or_class, 'method']
        if (is_array($callback) && count($callback) === 2) {
            $target = $callback[0];
            $method = is_string($callback[1]) ? $callback[1] : '(unknown method)';
            $class  = is_object($target) ? get_class($target) : (string) $target;
            return $class . '::' . $method;
        }

        // Closures: identify by declaring file/line, not by contents/bound state.
        if ($callback instanceof \Closure) {
            try {
                $reflector = new \ReflectionFunction($callback);
                $file = $reflector->getFileName();
                $line = $reflector->getStartLine();
                if ($file) {
                    return sprintf('{closure} (%s:%d)', str_replace(ABSPATH, '', $file), $line);
                }
            } catch (\ReflectionException $e) {
                // Fall through to generic label below.
            }
            return '{closure}';
        }

        // Invokable objects.
        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }

        return '(unknown callback)';
    }
}
