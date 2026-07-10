<?php
namespace Drw\App\Controllers;

if (!defined('ABSPATH')) { exit; }

class AnalyticsController {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'add_analytics_submenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_analytics_assets']);
        // Record discounts when an order is placed
        add_action('woocommerce_checkout_order_created', [$this, 'record_order_discounts'], 10, 1);
    }

    public function register_rest_routes() {
        register_rest_route('drw/v1', '/analytics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_analytics'],
            'permission_callback' => function() { return current_user_can('manage_woocommerce'); },
            'args' => [
                'days' => ['default' => 30, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function get_analytics($request) {
        global $wpdb;
        $days  = max(1, min(365, (int)$request->get_param('days')));
        $table = esc_sql($wpdb->prefix . 'drw_order_discounts');
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // The created_at column is absent from the baseline schema and is only
        // added by the dbDelta migration (admin_init/activation). This REST
        // endpoint runs on rest_api_init and never triggers that migration, so
        // on an in-place file update it can execute before the column exists.
        // Probe for it and drop the date filter when missing to avoid a fatal
        // "unknown column" error. Mirrors the defensive reads in
        // record_order_discounts() and PromosController::get_promo_stats().
        $has_created_at = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'created_at' )
        );
        $date_where = $has_created_at ? ' WHERE created_at >= %s' : '';

        // Total discount amount
        $sql = "SELECT COALESCE(SUM(discount_amount), 0) FROM {$table}{$date_where}";
        $total_discount = (float)$wpdb->get_var(
            $has_created_at ? $wpdb->prepare($sql, $since) : $sql
        );

        // Number of orders with discounts
        $sql = "SELECT COUNT(DISTINCT order_id) FROM {$table}{$date_where}";
        $orders_count = (int)$wpdb->get_var(
            $has_created_at ? $wpdb->prepare($sql, $since) : $sql
        );

        // Free shipping orders
        $sql = "SELECT COUNT(DISTINCT order_id) FROM {$table} WHERE free_shipping = 1"
            . ($has_created_at ? ' AND created_at >= %s' : '');
        $free_shipping_count = (int)$wpdb->get_var(
            $has_created_at ? $wpdb->prepare($sql, $since) : $sql
        );

        $avg_discount = $orders_count > 0 ? round($total_discount / $orders_count, 2) : 0;

        return rest_ensure_response([
            'days'               => $days,
            'total_discount'     => $total_discount,
            'orders_with_discounts' => $orders_count,
            'free_shipping_orders'  => $free_shipping_count,
            'average_discount'   => $avg_discount,
            'currency_symbol'    => get_woocommerce_currency_symbol(),
        ]);
    }

    public function record_order_discounts($order) {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'drw_order_discounts');

        // Compute discount amount from per-line-item meta saved by CartController.
        // _drw_discount_amount is never set externally; derive it from saved item meta.
        $discount_amount = 0.0;
        $free_shipping   = false;

        foreach ($order->get_items() as $item) {
            $original   = $item->get_meta('_drw_original_price', true);
            $discounted = $item->get_meta('_drw_discount_price', true);
            if ($original !== '' && $discounted !== '') {
                $diff = (float)$original - (float)$discounted;
                if ($diff > 0) {
                    $discount_amount += $diff * (int)$item->get_quantity();
                }
            }
        }

        // Account for negative fees added by cart-level rules.
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_total() < 0) {
                $discount_amount += abs((float)$fee->get_total());
            }
        }

        // Check free shipping: WooCommerce sets shipping cost to 0 when free;
        // fall back to a session flag written by StoreApiController.
        foreach ($order->get_shipping_methods() as $shipping) {
            if ((float)$shipping->get_total() === 0.0 && (float)$shipping->get_total_tax() === 0.0) {
                $free_shipping = true;
                break;
            }
        }

        if ($discount_amount <= 0 && !$free_shipping) {
            return;
        }

        $row = [
            'order_id'        => $order->get_id(),
            'discount_amount' => $discount_amount,
            'details'         => wp_json_encode([]),
            'free_shipping'   => $free_shipping ? 1 : 0,
        ];

        // The created_at column is absent from the baseline schema and is only
        // added by the dbDelta migration, which runs on admin_init/activation.
        // On an in-place file update a front-end checkout can fire before that
        // migration runs, so probe for the column and omit it when missing to
        // avoid a fatal "unknown column" error that would silently drop the row.
        // Mirrors the defensive read in PromosController::get_promo_stats().
        $has_created_at = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'created_at' )
        );
        if ( $has_created_at ) {
            $row['created_at'] = current_time('mysql');
        }

        $wpdb->insert($table, $row);
    }

    public function add_analytics_submenu() {
        add_submenu_page(
            'woocommerce',
            __('Analytics', 'discount-rules-woo'),
            __('Analytics', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-analytics',
            [$this, 'render_analytics_page']
        );
    }

    public function enqueue_analytics_assets($hook) {
        $drw_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($drw_page !== 'drw-analytics') { return; }

        wp_enqueue_script(
            'drw-analytics',
            DRW_PLUGIN_URL . 'assets/js/drw-analytics.js',
            ['wp-api-fetch', 'jquery'],
            DRW_VERSION,
            true
        );

        wp_localize_script('drw-analytics', 'drwAnalyticsData', [
            'apiRoot' => esc_url_raw(rest_url('drw/v1/analytics')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render_analytics_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OmniDiscount — Analíticas', 'discount-rules-woo'); ?></h1>
            <div id="drw-analytics-app">
                <p><?php esc_html_e('Cargando analíticas...', 'discount-rules-woo'); ?></p>
            </div>
        </div>
        <?php
    }
}
