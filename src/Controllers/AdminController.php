<?php

namespace Drw\App\Controllers;

use Drw\App\Models\PromoTypeRegistry;
use Drw\App\Models\RuleTemplateRegistry;
use Drw\App\Models\SettingsModel;

if (!defined('ABSPATH')) {
    exit;
}

class AdminController
{
    private static $instance = null;

    /**
     * Hook suffixes for every OmniDiscount admin screen, captured verbatim from
     * the return value of add_menu_page()/add_submenu_page() in add_admin_menu().
     * enqueue_admin_assets() matches $hook against this list. Captured (not
     * hardcoded) on purpose — see the note in enqueue_admin_assets() about the
     * sanitize_title() hook-suffix gotcha.
     *
     * @var string[]
     */
    private $hook_suffixes = [];

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
     * Register the OmniDiscount top-level menu and its submenus.
     *
     * Everything lives under the single 'drw-discount-rules' parent so the whole
     * plugin is consolidated in one menu instead of being scattered between an
     * OmniDiscount top-level and the WooCommerce menu. add_menu_page() and
     * add_submenu_page() each RETURN the hook suffix WordPress will later hand to
     * admin_enqueue_scripts(); we stash every one in $this->hook_suffixes so the
     * asset guard can compare against the real values (see enqueue_admin_assets()).
     */
    public function add_admin_menu()
    {
        $this->hook_suffixes[] = add_menu_page(
            __('OmniDiscount', 'discount-rules-woo'),
            __('OmniDiscount', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-discount-rules',
            [$this, 'render_admin_page'],
            'dashicons-tag',
            56
        );

        // First submenu deliberately shares its slug with the parent. This is the
        // standard WordPress idiom to control the label of the auto-generated first
        // submenu entry: without it WP labels that entry with the parent page_title
        // ('OmniDiscount'); with it we get the more specific 'Reglas'. Because the
        // slug equals the parent slug, WordPress keeps this entry's hook suffix
        // identical to the parent's ('toplevel_page_drw-discount-rules') — it does
        // NOT gain a '..._page_...' suffix. Capturing the return value keeps us
        // correct either way.
        $this->hook_suffixes[] = add_submenu_page(
            'drw-discount-rules',
            __('OmniDiscount — Reglas', 'discount-rules-woo'),
            __('Reglas', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-discount-rules',
            [$this, 'render_admin_page']
        );

        // Cupones y Promociones + Configuración are the SAME single-page React app
        // (render_admin_page); the SPA decides which view to show. The desired
        // opening screen is passed to JS via drwAdminData.initialScreen, computed
        // from $_GET['page'] in enqueue_admin_assets().
        $this->hook_suffixes[] = add_submenu_page(
            'drw-discount-rules',
            __('OmniDiscount — Cupones y Promociones', 'discount-rules-woo'),
            __('Cupones y Promociones', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-promos',
            [$this, 'render_admin_page']
        );

        $this->hook_suffixes[] = add_submenu_page(
            'drw-discount-rules',
            __('OmniDiscount — Configuración', 'discount-rules-woo'),
            __('Configuración', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-settings',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue CSS and JS assets for React UI.
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on OmniDiscount's own screens. $this->hook_suffixes holds the
        // exact strings add_menu_page()/add_submenu_page() returned, i.e. exactly
        // what WordPress passes here as $hook, so this matches all four entries
        // (top-level + Reglas + Cupones/Promociones + Configuración).
        //
        // WHY captured instead of a hardcoded array: WordPress does NOT build a
        // submenu hook suffix as '{parent_slug}_page_{submenu_slug}'. Per
        // wp-admin/includes/plugin.php::get_plugin_page_hookname(), the prefix is
        // sanitize_title( <PARENT menu_title> ), not the parent slug. Our parent
        // menu_title is 'OmniDiscount', so the real suffixes are
        // 'omnidiscount_page_drw-promos' / 'omnidiscount_page_drw-settings' — NOT
        // 'drw-discount-rules_page_drw-promos'. The first submenu (slug == parent
        // slug) instead keeps 'toplevel_page_drw-discount-rules'. Matching on the
        // captured return values is correct regardless of the menu title or locale.
        if (!in_array($hook, $this->hook_suffixes, true)) {
            return;
        }

        // Which SPA screen to open on load, derived from the submenu slug. Read-only
        // use of $_GET['page'] purely to pick a view; the React app falls back to
        // 'list'. URL/history.pushState sync is intentionally out of scope here.
        $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $initial_screen = 'list';
        if ('drw-promos' === $current_page) {
            $initial_screen = 'promos';
        } elseif ('drw-settings' === $current_page) {
            $initial_screen = 'settings';
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
            // One-click template gallery for "Crear nueva regla de descuento".
            // Each entry carries a complete RuleEditor-ready `rule` default; see
            // Drw\App\Models\RuleTemplateRegistry and DrwApp.handleSelectTemplate().
            'ruleTemplates'   => RuleTemplateRegistry::all(),
            // Used by LivePreviewPanel/PromoStatsPanel/NaturalLanguageSummary to
            // format money in the store's real currency instead of a bare number.
            'currencySymbol'  => get_woocommerce_currency_symbol(),
            // Map of settings.conditions (key => ['enabled' => bool]) so the Rule
            // Editor can hide condition types the merchant disabled in
            // Configuración Global → "Condiciones y Filtros Habilitados". Seeds
            // DrwApp's conditionsSettings state; GlobalSettings refreshes it live
            // in-session after a save. See RuleEditor's condition-type filtering.
            'conditionsSettings' => SettingsModel::get_setting('conditions', []),
            // Which SPA view to open on load, derived from the submenu slug the
            // merchant clicked ($_GET['page']). admin-app.js reads this and falls
            // back to 'list' when absent.
            'initialScreen'   => $initial_screen,
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
