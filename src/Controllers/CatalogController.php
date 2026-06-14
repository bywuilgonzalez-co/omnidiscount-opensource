<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class CatalogController
{
    private static $instance = null;
    private $is_calculating = false;

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
     * Register hooks for catalog view and price replacements.
     */
    public function register_hooks()
    {
        // Adjust prices (both normal price and sale price filters)
        add_filter('woocommerce_product_get_price', [$this, 'get_catalog_price'], 99, 2);
        add_filter('woocommerce_variation_get_price', [$this, 'get_catalog_price'], 99, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'get_catalog_price'], 99, 2);
        add_filter('woocommerce_variation_get_sale_price', [$this, 'get_catalog_price'], 99, 2);

        // Adjust HTML display (crossed out pricing)
        add_filter('woocommerce_get_price_html', [$this, 'get_crossed_out_price_html'], 99, 2);
        add_filter('woocommerce_variable_price_html', [$this, 'get_variable_price_html'], 99, 2);
        add_filter('woocommerce_variable_sale_price_html', [$this, 'get_variable_price_html'], 99, 2);

        // Customize sale badge
        add_filter('woocommerce_sale_flash', [$this, 'customize_sale_flash'], 99, 3);

        // Dynamic transient hash for variable product prices to prevent cache issues
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'get_variation_prices_hash'], 99, 2);
    }

    /**
     * Filter product/variation price to apply dynamic catalog discount.
     *
     * @param float|string $price Current price
     * @param \WC_Product $product Product object
     * @return float|string Adjusted price
     */
    public function get_catalog_price($price, $product)
    {
        // Don't apply in admin dashboard unless doing AJAX
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        // Avoid infinite recursion
        if ($this->is_calculating) {
            return $price;
        }

        // Skip variable products to prevent double-discounting or calculation issues on the variable level
        if ($product->is_type('variable')) {
            return $price;
        }

        $this->is_calculating = true;

        $regular_price = (float)$product->get_regular_price();
        $engine = \Drw\App\Controllers\RulesEngine::instance();
        
        $discounted_price = $engine->calculate_catalog_discount($product, $regular_price);

        $this->is_calculating = false;

        if ($discounted_price !== null) {
            $price_float = ($price !== '') ? (float)$price : $regular_price;
            return min($price_float, $discounted_price);
        }

        return $price;
    }

    /**
     * Formats price HTML to show crossed-out original price alongside discounted price.
     *
     * @param string $html Original price HTML
     * @param \WC_Product $product Product object
     * @return string Modified price HTML
     */
    public function get_crossed_out_price_html($html, $product)
    {
        // Avoid modifying in admin dashboard
        if (is_admin() && !wp_doing_ajax()) {
            return $html;
        }

        if ($this->is_calculating) {
            return $html;
        }

        // Skip variable products as they are handled by get_variable_price_html
        if ($product->is_type('variable')) {
            return $html;
        }

        $this->is_calculating = true;

        $regular_price = (float)$product->get_regular_price();
        $db_price      = (float)$product->get_price(); // read while guard is on → gets raw DB price
        $engine        = \Drw\App\Controllers\RulesEngine::instance();

        $discounted_price = $engine->calculate_catalog_discount($product, $regular_price);

        $this->is_calculating = false;

        $final_price = ($discounted_price !== null) ? min($db_price, $discounted_price) : $db_price;

        if ($final_price < $regular_price && $regular_price > 0) {
            $html = sprintf(
                '<del aria-hidden="true">%s</del> <ins>%s</ins>',
                wc_price($regular_price),
                wc_price($final_price)
            );
        }

        return $html;
    }

    /**
     * Formats price HTML for variable products to show crossed-out original price ranges
     * alongside discounted price ranges when dynamic discounts apply.
     *
     * @param string $html Original price HTML
     * @param \WC_Product_Variable $product Variable product object
     * @return string Modified price HTML
     */
    public function get_variable_price_html($html, $product)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return $html;
        }

        if ($this->is_calculating) {
            return $html;
        }

        $this->is_calculating = true;

        $prices = [];
        $regular_prices = [];
        $has_discount = false;

        $engine = \Drw\App\Controllers\RulesEngine::instance();

        foreach ($product->get_visible_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $reg_price = (float)$variation->get_regular_price();
            $discounted_price = $engine->calculate_catalog_discount($variation, $reg_price);
            $wc_price = (float)$variation->get_price();

            if ($discounted_price !== null) {
                $final_price = min($wc_price, $discounted_price);
                if ($final_price < $wc_price) {
                    $has_discount = true;
                }
                $prices[] = $final_price;
            } else {
                $prices[] = $wc_price;
            }
            $regular_prices[] = $reg_price;
        }

        $this->is_calculating = false;

        if (!$has_discount || empty($prices) || empty($regular_prices)) {
            return $html;
        }

        $min_reg = min($regular_prices);
        $max_reg = max($regular_prices);
        $min_disc = min($prices);
        $max_disc = max($prices);

        // Format the regular price range
        if ($min_reg === $max_reg) {
            $reg_html = wc_price($min_reg);
        } else {
            $reg_html = sprintf('%s&ndash;%s', wc_price($min_reg), wc_price($max_reg));
        }

        // Format the discounted price range
        if ($min_disc === $max_disc) {
            $disc_html = wc_price($min_disc);
        } else {
            $disc_html = sprintf('%s&ndash;%s', wc_price($min_disc), wc_price($max_disc));
        }

        // Return crossed-out price range HTML
        return sprintf(
            '<del aria-hidden="true">%s</del> <ins>%s</ins>',
            $reg_html,
            $disc_html
        );
    }

    /**
     * Customize the sale badge display when discount rules match.
     *
     * @param string $html Original sale flash HTML
     * @param \WP_Post|null $post Post object
     * @param \WC_Product $product Product object
     * @return string Modified sale flash HTML
     */
    public function customize_sale_flash($html, $post, $product)
    {
        // Avoid modifying in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $html;
        }

        if (!$product) {
            if ($post) {
                $product = wc_get_product($post->ID);
            }
            if (!$product) {
                return $html;
            }
        }

        $sale_data = ShortcodeController::get_sale_data_for_product($product);
        if (empty($sale_data['percentage'])) {
            return $html;
        }

        return ShortcodeController::render_sale_percentage_badge($sale_data['percentage']);
    }

    /**
     * Include rules state and user context in the variation prices transient hash
     * to avoid stale cached variation prices.
     *
     * @param array $hash
     * @param \WC_Product_Variable $product
     * @return array
     */
    public function get_variation_prices_hash($hash, $product)
    {
        $engine = \Drw\App\Controllers\RulesEngine::instance();
        $rules = $engine->get_active_rules();
        
        $rules_state = [];
        foreach ($rules as $rule) {
            $rules_state[] = [
                'id' => $rule['id'],
                'enabled' => $rule['enabled'],
                'adjustments' => $rule['adjustments'],
            ];
        }

        $hash['drw_rules'] = md5(serialize($rules_state));
        $hash['drw_compounding'] = $engine->get_compounding_strategy();
        
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $hash['drw_user_roles'] = implode(',', $user->roles);
        } else {
            $hash['drw_user_roles'] = 'guest';
        }

        return $hash;
    }
}
