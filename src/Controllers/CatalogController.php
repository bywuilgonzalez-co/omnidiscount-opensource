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
        // Adjust prices
        add_filter('woocommerce_product_get_price', [$this, 'get_catalog_price'], 99, 2);
        add_filter('woocommerce_variation_get_price', [$this, 'get_catalog_price'], 99, 2);

        // Adjust HTML display (crossed out pricing)
        add_filter('woocommerce_get_price_html', [$this, 'get_crossed_out_price_html'], 99, 2);
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

        $this->is_calculating = true;

        $regular_price = (float)$product->get_regular_price();
        $engine = \Drw\App\Controllers\RulesEngine::instance();
        
        $discounted_price = $engine->calculate_catalog_discount($product, $regular_price);

        $this->is_calculating = false;

        if ($discounted_price !== null) {
            return $discounted_price;
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

        $this->is_calculating = true;
        
        $regular_price = (float)$product->get_regular_price();
        $engine = \Drw\App\Controllers\RulesEngine::instance();
        
        $discounted_price = $engine->calculate_catalog_discount($product, $regular_price);
        
        $this->is_calculating = false;

        if ($discounted_price !== null && $discounted_price < $regular_price) {
            // Render crossed-out style
            $html = sprintf(
                '<del aria-hidden="true">%s</del> <ins>%s</ins>',
                wc_price($regular_price),
                wc_price($discounted_price)
            );
        }

        return $html;
    }
}
