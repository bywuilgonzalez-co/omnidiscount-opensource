<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoTypeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class AdminController
{
    private static $instance = null;

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
     * Register admin hooks.
     */
    public function register_hooks()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // "Managed by OmniDiscount" badge on the native coupon list
        // (Marketing > Cupones) + a non-blocking heads-up when editing one
        // of those coupons directly. Both read the '_drw_promo_id' post meta
        // that PromoBridgeController::compile_coupon() stamps on Vía A
        // coupons — see src/Controllers/PromoBridgeController.php.
        add_filter('manage_edit-shop_coupon_columns', [$this, 'add_coupon_managed_column']);
        add_action('manage_shop_coupon_posts_custom_column', [$this, 'render_coupon_managed_column'], 10, 2);
        add_action('admin_notices', [$this, 'render_coupon_lock_notice']);
    }

    /**
     * Add menu under WooCommerce section.
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('OmniDiscount', 'discount-rules-woo'),
            __('OmniDiscount', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-discount-rules',
            [$this, 'render_admin_page'],
            'dashicons-tag',
            56
        );
    }

    /**
     * Enqueue CSS and JS assets for React UI.
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on our custom admin page
        if ($hook !== 'toplevel_page_drw-discount-rules') {
            return;
        }

        // Enqueue WP core dependencies
        wp_enqueue_script('wp-element');
        wp_enqueue_script('wp-components');
        wp_enqueue_script('wp-api-fetch');

        // Enqueue custom CSS
        wp_enqueue_style(
            'drw-admin-style',
            DRW_PLUGIN_URL . 'assets/css/admin-style.css',
            ['wp-components'],
            DRW_VERSION
        );
        wp_enqueue_style(
            'drw-admin-promos-style',
            DRW_PLUGIN_URL . 'assets/css/admin-promos.css',
            ['drw-admin-style'],
            DRW_VERSION
        );
        // Material Symbols icon font — used by the promo wizard's template
        // gallery (drw-material-icon.js / drw-template-gallery.js).
        wp_enqueue_style(
            'drw-material-icons',
            DRW_PLUGIN_URL . 'assets/css/drw-material-icons.css',
            [],
            DRW_VERSION
        );

        // Enqueue compiled React app
        wp_enqueue_script(
            'drw-admin-app',
            DRW_PLUGIN_URL . 'assets/js/admin-app.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'jquery'],
            DRW_VERSION,
            true
        );

        // ------------------------------------------------------------------
        // Promo wizard component library. Each script exposes a window.Drw*
        // component and is loaded AFTER drw-admin-app so window.drwAdminData
        // (promoTypes, categories) is already defined when they run.
        // ------------------------------------------------------------------
        wp_enqueue_script(
            'drw-material-icon',
            DRW_PLUGIN_URL . 'assets/js/drw-material-icon.js',
            ['wp-element'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-template-gallery',
            DRW_PLUGIN_URL . 'assets/js/drw-template-gallery.js',
            ['wp-element', 'drw-admin-app', 'drw-material-icon'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-product-category-picker',
            DRW_PLUGIN_URL . 'assets/js/drw-product-category-picker.js',
            ['wp-element', 'wp-components', 'wp-api-fetch'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-tiered-bundle-editors',
            DRW_PLUGIN_URL . 'assets/js/drw-tiered-bundle-editors.js',
            ['wp-element', 'drw-product-category-picker'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-code-input',
            DRW_PLUGIN_URL . 'assets/js/drw-code-input.js',
            ['wp-element', 'wp-api-fetch'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-conflict-checker',
            DRW_PLUGIN_URL . 'assets/js/drw-conflict-checker.js',
            ['wp-element', 'wp-api-fetch'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-live-preview-panel',
            DRW_PLUGIN_URL . 'assets/js/drw-live-preview-panel.js',
            ['wp-element', 'wp-api-fetch'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-natural-language-summary',
            DRW_PLUGIN_URL . 'assets/js/drw-natural-language-summary.js',
            ['wp-element', 'drw-admin-app'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-promo-stats-panel',
            DRW_PLUGIN_URL . 'assets/js/drw-promo-stats-panel.js',
            ['wp-element', 'wp-api-fetch'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-promo-wizard',
            DRW_PLUGIN_URL . 'assets/js/drw-promo-wizard.js',
            [
                'wp-element',
                'wp-components',
                'wp-api-fetch',
                'drw-admin-app',
                'drw-material-icon',
                'drw-template-gallery',
                'drw-product-category-picker',
                'drw-tiered-bundle-editors',
                'drw-code-input',
                'drw-conflict-checker',
                'drw-live-preview-panel',
                'drw-natural-language-summary',
                'drw-promo-stats-panel',
            ],
            DRW_VERSION,
            true
        );

        wp_enqueue_script(
            'drw-admin-promos',
            DRW_PLUGIN_URL . 'assets/js/admin-promos.js',
            ['wp-element', 'wp-api-fetch', 'drw-admin-app', 'drw-promo-wizard'],
            DRW_VERSION,
            true
        );

        // Product selection is handled by the async REST search endpoint.
        $products = [];

        $categories = [];
        $cat_terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (!is_wp_error($cat_terms)) {
            foreach ($cat_terms as $term) {
                $categories[] = [
                    'id'   => $term->term_id,
                    'name' => $term->name,
                ];
            }
        }

        // Localize scripts with configuration and REST info
        wp_localize_script('drw-admin-app', 'drwAdminData', [
            'apiRoot'         => esc_url_raw(rest_url('drw/v1/rules')),
            'settingsApiRoot' => esc_url_raw(rest_url('drw/v1/settings')),
            'nonce'           => wp_create_nonce('wp_rest'),
            'products'        => $products,
            'categories'      => $categories,
            'roles'           => $this->get_user_roles(),
            // Single source of truth for promo types; see Drw\App\Models\PromoTypeRegistry.
            'promoTypes'      => PromoTypeRegistry::all(),
            // Used by LivePreviewPanel/PromoStatsPanel/NaturalLanguageSummary to
            // format money in the store's real currency instead of a bare number.
            'currencySymbol'  => get_woocommerce_currency_symbol(),
        ]);
    }

    /**
     * Get user roles key-value pair.
     */
    private function get_user_roles()
    {
        global $wp_roles;
        if (!isset($wp_roles)) {
            require_once(ABSPATH . 'wp-admin/includes/schema.php');
            populate_roles();
        }

        $roles = [['id' => 'guest', 'name' => __('Guest (Not logged in)', 'discount-rules-woo')]];
        foreach ($wp_roles->roles as $key => $role) {
            $roles[] = [
                'id'   => $key,
                'name' => translate_user_role($role['name']),
            ];
        }

        return $roles;
    }

    /**
     * Renders the React Admin container.
     */
    public function render_admin_page()
    {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('OmniDiscount', 'discount-rules-woo'); ?></h1>
            <hr class="wp-header-end">
            <div id="drw-admin-app"></div>
        </div>
        <?php
    }

    /**
     * Insert a "Managed by OmniDiscount" column into the native coupon list
     * (edit.php?post_type=shop_coupon), right after the coupon code column.
     *
     * @param array $columns
     * @return array
     */
    public function add_coupon_managed_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('name' === $key) {
                $new['drw_managed'] = __('Origen', 'discount-rules-woo');
            }
        }
        // Defensive fallback in case the 'name' column is ever renamed/removed.
        if (!isset($new['drw_managed'])) {
            $new['drw_managed'] = __('Origen', 'discount-rules-woo');
        }

        return $new;
    }

    /**
     * Render the "Gestionado por OmniDiscount" badge for coupons that were
     * compiled from a promo (Vía A — carry '_drw_promo_id' post meta).
     * Plain coupons created by hand render an em dash and stay untouched.
     *
     * @param string $column
     * @param int    $post_id
     */
    public function render_coupon_managed_column($column, $post_id)
    {
        if ('drw_managed' !== $column) {
            return;
        }

        $promo_id = (new \WC_Coupon($post_id))->get_meta('_drw_promo_id');
        if (empty($promo_id)) {
            echo '&#8212;';
            return;
        }

        printf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#eef2ff;color:#4338ca;font-size:11px;font-weight:600;white-space:nowrap;" title="%1$s">%2$s</span>',
            esc_attr__('Este cupón fue generado automáticamente por una promoción de OmniDiscount.', 'discount-rules-woo'),
            esc_html__('Gestionado por OmniDiscount', 'discount-rules-woo')
        );
    }

    /**
     * Non-blocking, informational notice shown when editing a coupon that
     * OmniDiscount owns, nudging merchants toward the Promotions panel
     * instead of hand-editing the auto-generated coupon.
     *
     * Intentionally NOT a lock: it never disables fields or blocks saving.
     * PromoBridgeController::compile_coupon() overwrites code/amount/etc. on
     * the coupon it owns every time the source promo is saved, so a manual
     * edit here would silently get clobbered later — this notice exists
     * purely to prevent that surprise, not to enforce anything server-side.
     */
    public function render_coupon_lock_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'shop_coupon' !== $screen->id || 'post' !== $screen->base) {
            return;
        }

        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($post_id <= 0) {
            return;
        }

        $promo_id = (new \WC_Coupon($post_id))->get_meta('_drw_promo_id');
        if (empty($promo_id)) {
            return;
        }

        $promos_url = admin_url('admin.php?page=drw-discount-rules');

        /* translators: %s: URL to the OmniDiscount Promotions panel. */
        $message = sprintf(
            wp_kses(
                __('Este cupón está gestionado por OmniDiscount. Se recomienda editarlo desde el <a href="%s">panel de Promociones</a> para mantener la sincronización.', 'discount-rules-woo'),
                ['a' => ['href' => []]]
            ),
            esc_url($promos_url)
        );

        echo '<div class="notice notice-info is-dismissible"><p>&#128274; ' . $message . '</p></div>';
    }
}
