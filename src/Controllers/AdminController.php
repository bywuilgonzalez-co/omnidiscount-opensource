<?php

namespace Drw\App\Controllers;

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
    }

    /**
     * Add menu under WooCommerce section.
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Discount Rules', 'discount-rules-woo'),
            __('Discount Rules', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-discount-rules',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue CSS and JS assets for React UI.
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on our custom admin page
        if ($hook !== 'woocommerce_page_drw-discount-rules') {
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

        // Enqueue compiled React app
        wp_enqueue_script(
            'drw-admin-app',
            DRW_PLUGIN_URL . 'assets/js/admin-app.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'jquery'],
            DRW_VERSION,
            true
        );
        wp_enqueue_script(
            'drw-admin-promos',
            DRW_PLUGIN_URL . 'assets/js/admin-promos.js',
            ['wp-element', 'wp-api-fetch', 'drw-admin-app'],
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
}
