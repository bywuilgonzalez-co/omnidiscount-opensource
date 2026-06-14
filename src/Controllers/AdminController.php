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
        add_action('admin_post_drw_save_settings', [$this, 'save_settings']);
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

        add_submenu_page(
            'woocommerce',
            __('Discount Rules – Settings', 'discount-rules-woo'),
            __('Discount Rules Settings', 'discount-rules-woo'),
            'manage_woocommerce',
            'drw-discount-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Handle settings form submission.
     */
    public function save_settings()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'discount-rules-woo'));
        }

        check_admin_referer('drw_save_settings');

        update_option('drw_global_no_coupon_stacking', !empty($_POST['drw_global_no_coupon_stacking']) ? 1 : 0);

        wp_safe_redirect(add_query_arg([
            'page'    => 'drw-discount-settings',
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Render the global settings page.
     */
    public function render_settings_page()
    {
        $no_coupon_stacking = (bool)get_option('drw_global_no_coupon_stacking', false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Discount Rules – Settings', 'discount-rules-woo'); ?></h1>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'discount-rules-woo'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('drw_save_settings'); ?>
                <input type="hidden" name="action" value="drw_save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="drw_global_no_coupon_stacking">
                                <?php esc_html_e('Coupon Stacking', 'discount-rules-woo'); ?>
                            </label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="drw_global_no_coupon_stacking">
                                    <input
                                        type="checkbox"
                                        id="drw_global_no_coupon_stacking"
                                        name="drw_global_no_coupon_stacking"
                                        value="1"
                                        <?php checked($no_coupon_stacking, true); ?>
                                    >
                                    <?php esc_html_e('Disable all discount rules when a coupon is applied (globally)', 'discount-rules-woo'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, no discount rule will apply if the customer has entered a coupon code. Individual rules can also be marked non-stackable via the API.', 'discount-rules-woo'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'discount-rules-woo')); ?>
            </form>
        </div>
        <?php
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

        // Enqueue compiled React app
        wp_enqueue_script(
            'drw-admin-app',
            DRW_PLUGIN_URL . 'assets/js/admin-app.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'jquery'],
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
            'apiRoot'    => esc_url_raw(rest_url('drw/v1/rules')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'products'   => $products,
            'categories' => $categories,
            'roles'      => $this->get_user_roles(),
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
            <h1 class="wp-heading-inline"><?php esc_html_e('Discount Rules for WooCommerce', 'discount-rules-woo'); ?></h1>
            <hr class="wp-header-end">
            <div id="drw-admin-app"></div>
        </div>
        <?php
    }
}
