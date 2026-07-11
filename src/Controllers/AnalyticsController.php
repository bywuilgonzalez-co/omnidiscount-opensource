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
                'days'      => ['default' => 30, 'sanitize_callback' => 'absint'],
                // Number of rows to return in each "top rules/promos" ranking.
                'top_limit' => ['default' => 5, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function get_analytics($request) {
        global $wpdb;
        $days      = max(1, min(365, (int)$request->get_param('days')));
        $top_limit = max(1, min(20, (int)$request->get_param('top_limit')));
        $table     = esc_sql($wpdb->prefix . 'drw_order_discounts');
        // Site-local "now" minus $days, matching how every DATETIME column in
        // this plugin is written (current_time('mysql'), not the MySQL
        // server's own NOW() nor gmdate()'s UTC) — see
        // CartController::release_stale_promo_reservations() for the same
        // pattern. created_at below (record_order_discounts()) is written
        // with current_time('mysql'), so the comparison bound must be
        // computed in the same site-local timezone or the "last N days"
        // window drifts by the store's UTC offset.
        $since     = date('Y-m-d H:i:s', strtotime("-{$days} days", strtotime(current_time('mysql'))));

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
            // --- Original fields (unchanged shape; see CLAUDE.md REST contract rule) ---
            'days'               => $days,
            'total_discount'     => $total_discount,
            'orders_with_discounts' => $orders_count,
            'free_shipping_orders'  => $free_shipping_count,
            'average_discount'   => $avg_discount,
            'currency_symbol'    => get_woocommerce_currency_symbol(),

            // --- New, additive fields for the dashboard rebuild ---
            'timeseries'              => $this->get_timeseries($table, $has_created_at, $since, $days),
            'timeseries_granularity'  => $days > 60 ? 'week' : 'day',
            'top_rules_by_amount'     => $this->get_top_by_amount($table, 'rule_id', $has_created_at, $since, $top_limit),
            'top_promos_by_amount'    => $this->get_top_by_amount($table, 'promo_id', $has_created_at, $since, $top_limit),
            'top_rules_by_redemptions'  => $this->get_top_rules_by_redemptions($top_limit),
            'top_promos_by_redemptions' => $this->get_top_promos_by_redemptions($top_limit),
        ]);
    }

    /**
     * Build a day- or week-bucketed time series of discount amount and order
     * count over the selected range, so the dashboard can render a trend
     * chart instead of a single period total.
     *
     * Weeks are Monday-aligned via WEEKDAY() rather than YEARWEEK() to avoid
     * ISO week-numbering edge cases at year boundaries; both are portable
     * across MySQL and MariaDB without relying on window functions.
     *
     * @param string $table          Fully escaped order-discounts table name.
     * @param bool   $has_created_at Whether the optional created_at column exists.
     * @param string $since          Prepared lower bound (Y-m-d H:i:s, site-local).
     * @param int    $days           Selected range in days (drives granularity).
     * @return array<int, array{date: string, discount_amount: float, orders_count: int}>
     */
    private function get_timeseries($table, $has_created_at, $since, $days) {
        global $wpdb;

        if (!$has_created_at) {
            return [];
        }

        if ($days > 60) {
            // Monday-of-week bucket.
            $bucket_expr = "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))";
        } else {
            $bucket_expr = "DATE(created_at)";
        }

        $sql = "SELECT {$bucket_expr} AS bucket, "
            . "COALESCE(SUM(discount_amount), 0) AS amount, "
            . "COUNT(DISTINCT order_id) AS orders_count "
            . "FROM {$table} WHERE created_at >= %s "
            . "GROUP BY bucket ORDER BY bucket ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'date'             => (string) $row['bucket'],
                'discount_amount'  => (float) $row['amount'],
                'orders_count'     => (int) $row['orders_count'],
            ];
        }
        return $out;
    }

    /**
     * Rank rules or promos by $ amount discounted using the atomic
     * drw_promo_redemptions table (rule_id/order_id/promo_id, status
     * confirmed) as the attribution link, joined against order_discounts for
     * the actual amount. The 'details' column on drw_order_discounts itself
     * carries no rule/promo attribution today — record_order_discounts()
     * always writes an empty JSON array — so redemptions is the real source
     * of truth for "which rule/promo produced this order's discount".
     *
     * When a single order carries more than one confirmed redemption (e.g.
     * a rule plus a bridge-compiled promo both confirmed against it), the
     * order's discount_amount is split evenly across its redemptions rather
     * than counted in full for each, to avoid overstating totals.
     *
     * The rule branch excludes redemptions tied to a promo
     * (red.promo_id IS NOT NULL) because PromoBridgeController compiles
     * every promo into its own wp_drw_rules row (source = 'promo', reusing
     * the promo's own name as the rule title) and stamps redemptions from
     * that bridge rule with promo_id set. Without the exclusion, every
     * promo-driven redemption would be attributed twice — once here under
     * its synthetic bridge rule, and again in the promo branch below under
     * the promo itself — double-counting revenue and mislabeling
     * promo-driven discounts as organic "rule" performance on the
     * dashboard. Genuine standalone rules never carry a promo_id, so this
     * exclusion does not affect them.
     *
     * @see PromoBridgeController::compile_rule() (source, promo_id columns)
     *
     * order_discounts is joined via a per-order_id SUM() subquery rather
     * than a direct row join: the table's only index on order_id
     * (Database.php) is a non-unique KEY, not a UNIQUE constraint, and
     * record_order_discounts() never checks for an existing row before
     * inserting, so nothing guarantees exactly one row per order. A direct
     * INNER JOIN would fan out and inflate the summed amount if that hook
     * ever fired twice for the same order; pre-aggregating collapses any
     * duplicate rows to their sum before the join, matching total_discount's
     * own per-order semantics regardless of row count.
     *
     * Like the redemption-count rankings below, a soft-deleted rule/promo is
     * intentionally still returned here (the LEFT JOIN is unfiltered by
     * active/deleted status) since it really did produce this past discount
     * volume; the 'deleted' flag lets the frontend label it as gone instead
     * of implying it is still active. rules use the `deleted` TINYINT flag,
     * promos use `deleted_at IS NOT NULL` — same columns/semantics as
     * get_top_rules_by_redemptions()/get_top_promos_by_redemptions().
     *
     * @param string      $table          Fully escaped order-discounts table name.
     * @param string      $entity_column   'rule_id' or 'promo_id'.
     * @param bool        $has_created_at Whether the optional created_at column exists.
     * @param string      $since          Prepared lower bound (Y-m-d H:i:s, site-local).
     * @param int         $limit          Max rows to return.
     * @return array<int, array{id: int, title: string, amount: float, orders_count: int, deleted: bool}>
     */
    private function get_top_by_amount($table, $entity_column, $has_created_at, $since, $limit) {
        global $wpdb;

        $redemptions_table = esc_sql($wpdb->prefix . 'drw_promo_redemptions');
        if (!$this->table_exists($redemptions_table)) {
            return [];
        }

        $entity_column = $entity_column === 'promo_id' ? 'promo_id' : 'rule_id';

        if ($entity_column === 'rule_id') {
            $label_table   = esc_sql($wpdb->prefix . 'drw_rules');
            $label_col     = 'title';
            $deleted_col   = 'deleted';
            // Exclude bridge-compiled "shadow" rules (promo_id IS NOT NULL):
            // those redemptions are already attributed to their promo in the
            // promo_id branch below. Without this, a promo-driven redemption
            // would be double-counted across both rankings — see the
            // docblock above.
            $extra_where   = ' AND red.promo_id IS NULL';
        } else {
            $label_table   = esc_sql($wpdb->prefix . 'drw_promos');
            $label_col     = 'name';
            $deleted_col   = 'deleted_at';
            $extra_where   = ' AND red.promo_id IS NOT NULL';
        }

        $od_date_where = ($has_created_at ? ' WHERE created_at >= %s' : '');

        $sql = "SELECT red.{$entity_column} AS entity_id, lbl.{$label_col} AS title, "
            . "lbl.{$deleted_col} AS deleted_flag, "
            . "SUM(od.discount_amount / cnt.n) AS amount, "
            . "COUNT(DISTINCT od.order_id) AS orders_count "
            . "FROM {$redemptions_table} red "
            . "INNER JOIN ( "
            . "    SELECT order_id, SUM(discount_amount) AS discount_amount "
            . "    FROM {$table}{$od_date_where} GROUP BY order_id "
            . ") od ON od.order_id = red.order_id "
            . "INNER JOIN ( "
            . "    SELECT order_id, COUNT(*) AS n FROM {$redemptions_table} "
            . "    WHERE status = 'confirmed' GROUP BY order_id "
            . ") cnt ON cnt.order_id = red.order_id "
            . "LEFT JOIN {$label_table} lbl ON lbl.id = red.{$entity_column} "
            . "WHERE red.status = 'confirmed'{$extra_where} "
            . "GROUP BY red.{$entity_column}, lbl.{$label_col}, lbl.{$deleted_col} "
            . "ORDER BY amount DESC "
            . "LIMIT %d";

        $params = $has_created_at ? [$since, $limit] : [$limit];
        $rows   = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (empty($row['entity_id'])) {
                continue;
            }
            $out[] = [
                'id'           => (int) $row['entity_id'],
                'title'        => $row['title'] !== null ? (string) $row['title'] : '',
                'amount'       => round((float) $row['amount'], 2),
                'orders_count' => (int) $row['orders_count'],
                'deleted'      => !empty($row['deleted_flag']),
            ];
        }
        return $out;
    }

    /**
     * Top rules by real redemption count (wp_drw_rules.used_count), the
     * source of truth for "how many times has this rule fired" — an
     * all-time cumulative counter, independent of the selected date range.
     *
     * Deleted rules are included when they carry usage history: a rule a
     * merchant later removed still really produced those past redemptions,
     * and hiding it would understate the campaign's real impact. The row is
     * flagged 'deleted' so the frontend can label it instead of implying it
     * is still active.
     *
     * @param int $limit Max rows to return.
     * @return array<int, array{id: int, title: string, used_count: int, usage_limit: int|null, deleted: bool}>
     */
    private function get_top_rules_by_redemptions($limit) {
        global $wpdb;
        $rules_table = esc_sql($wpdb->prefix . 'drw_rules');

        $sql  = "SELECT id, title, used_count, usage_limit, deleted FROM {$rules_table} "
            . "WHERE used_count > 0 "
            . "ORDER BY used_count DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'used_count'  => (int) $row['used_count'],
                'usage_limit' => $row['usage_limit'] !== null ? (int) $row['usage_limit'] : null,
                'deleted'     => !empty($row['deleted']),
            ];
        }
        return $out;
    }

    /**
     * Top promos by real redemption count (wp_drw_promos.uses), the source
     * of truth for "how many times has this promo been redeemed" — an
     * all-time cumulative counter, independent of the selected date range.
     *
     * Soft-deleted promos are included when they carry usage history, for
     * the same reason as get_top_rules_by_redemptions() above (e.g. a past
     * "Black Friday" promo the merchant later deleted still really drove
     * those redemptions). Flagged 'deleted' for the frontend to label.
     *
     * @param int $limit Max rows to return.
     * @return array<int, array{id: int, title: string, uses: int, limit_global: int|null, deleted: bool}>
     */
    private function get_top_promos_by_redemptions($limit) {
        global $wpdb;
        $promos_table = esc_sql($wpdb->prefix . 'drw_promos');

        if (!$this->table_exists($promos_table)) {
            return [];
        }

        $sql  = "SELECT id, name, uses, limit_global, deleted_at FROM {$promos_table} "
            . "WHERE uses > 0 "
            . "ORDER BY uses DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['name'],
                'uses'         => (int) $row['uses'],
                'limit_global' => $row['limit_global'] !== null ? (int) $row['limit_global'] : null,
                'deleted'      => !empty($row['deleted_at']),
            ];
        }
        return $out;
    }

    /**
     * Whether a given (already esc_sql-escaped) table name currently exists.
     * Used to gracefully degrade the new ranking fields to empty arrays on
     * installs where drw_promo_redemptions/drw_promos predate this plugin
     * version instead of throwing a fatal "table doesn't exist" error.
     *
     * The caller-supplied value has already been through esc_sql() (it is
     * reused as-is for raw FROM-clause identifier interpolation elsewhere in
     * this class, where esc_sql() is required since placeholders can't bind
     * identifiers). Do not additionally route it through $wpdb->prepare()'s
     * %s here — prepare() escapes its argument again internally, so the
     * value would be escaped twice. Currently a no-op since table names are
     * always plain prefix_literal strings, but interpolating the
     * already-escaped value directly avoids the double-escape becoming a
     * silent false negative if this ever handles a less trivial name.
     *
     * @param string $escaped_table Table name already passed through esc_sql().
     * @return bool
     */
    private function table_exists($escaped_table) {
        global $wpdb;
        return (bool) $wpdb->get_var("SHOW TABLES LIKE '{$escaped_table}'");
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
        // Consolidated under the OmniDiscount top-level menu ('drw-discount-rules')
        // instead of WooCommerce. permission_callback / capability stays
        // 'manage_woocommerce' unchanged.
        add_submenu_page(
            'drw-discount-rules',
            __('OmniDiscount — Analíticas', 'discount-rules-woo'),
            __('Analíticas', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-analytics',
            [$this, 'render_analytics_page']
        );
    }

    public function enqueue_analytics_assets($hook) {
        $drw_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($drw_page !== 'drw-analytics') { return; }

        // Shared plugin admin stylesheet — AdminController only enqueues this
        // on its own SPA hook suffixes (drw-discount-rules/drw-promos/
        // drw-settings), which does not include this standalone Analíticas
        // submenu page, so it is registered here too under the same handle.
        // Provides the dashboard's stat-tile/panel/ranking styles.
        wp_enqueue_style(
            'drw-admin-style',
            DRW_PLUGIN_URL . 'assets/css/admin-style.css',
            [],
            DRW_VERSION
        );

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
