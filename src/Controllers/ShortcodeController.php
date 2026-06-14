<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeController
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
     * Register public shortcodes and assets.
     */
    public function register_hooks()
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('drw_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('awdr_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('on_sale', [$this, 'render_sale_items_list']);
        }

        if (function_exists('add_action')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
        }
    }

    /**
     * Enqueue public CSS used by dynamic sale badges and shortcode cards.
     */
    public function enqueue_public_assets()
    {
        wp_enqueue_style(
            'drw-public-style',
            DRW_PLUGIN_URL . 'assets/css/public-style.css',
            [],
            DRW_VERSION
        );
    }

    /**
     * Render sale products.
     *
     * Supported examples:
     * [awdr_sale_items_list]
     * [awdr_sale_items_list category="combos" limit="12" columns="4"]
     */
    public function render_sale_items_list($atts = [])
    {
        $atts = shortcode_atts([
            'limit'      => 12,
            'columns'    => 4,
            'category'   => '',
            'ids'        => '',
            'scan_limit' => 80,
            'class'      => '',
        ], (array)$atts, 'drw_sale_items_list');

        $limit = min(48, max(1, absint($atts['limit'])));
        $columns = min(6, max(1, absint($atts['columns'])));
        $scan_limit = min(200, max($limit, absint($atts['scan_limit'])));
        $category = sanitize_text_field($atts['category']);
        $ids = $this->parse_id_list($atts['ids']);

        $query_args = [
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $scan_limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if (!empty($ids)) {
            $query_args['post__in'] = $ids;
            $query_args['orderby'] = 'post__in';
            $query_args['posts_per_page'] = min($scan_limit, count($ids));
        }

        if ($category !== '') {
            $query_args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => array_map('trim', explode(',', $category)),
                ],
            ];
        }

        $product_ids = get_posts($query_args);
        $cards = [];

        foreach ($product_ids as $product_id) {
            $product_id = is_object($product_id) && isset($product_id->ID) ? $product_id->ID : $product_id;
            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                continue;
            }

            $sale_data = self::get_sale_data_for_product($product);
            if (empty($sale_data['percentage'])) {
                continue;
            }

            $cards[] = $this->render_product_card($product, $sale_data);
            if (count($cards) >= $limit) {
                break;
            }
        }

        $this->enqueue_public_assets();

        if (empty($cards)) {
            return '<div class="drw-sale-items-empty">' . esc_html__('No sale products found.', 'discount-rules-woo') . '</div>';
        }

        $class = sanitize_text_field($atts['class']);
        $style = '--drw-sale-columns:' . $columns . ';';

        return sprintf(
            '<div class="drw-sale-items-grid %s" style="%s">%s</div>',
            esc_attr($class),
            esc_attr($style),
            implode('', $cards)
        );
    }

    /**
     * Build sale data for simple or variable products.
     */
    public static function get_sale_data_for_product($product)
    {
        if (!$product) {
            return null;
        }

        if ($product->is_type('variable')) {
            $best = null;
            foreach ((array)$product->get_visible_children() as $variation_id) {
                $variation = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
                $sale_data = self::get_simple_sale_data($variation);
                if ($sale_data && (!$best || $sale_data['percentage'] > $best['percentage'])) {
                    $best = $sale_data;
                }
            }
            return $best;
        }

        return self::get_simple_sale_data($product);
    }

    /**
     * Render exact requested percentage badge markup.
     */
    public static function render_sale_percentage_badge($percentage)
    {
        $percentage = (int)$percentage;
        if ($percentage <= 0) {
            return '';
        }

        return '<div class="sale-perc">-' . esc_html($percentage) . ' %</div>';
    }

    /**
     * Calculate sale data for one concrete product or variation.
     */
    private static function get_simple_sale_data($product)
    {
        if (!$product) {
            return null;
        }

        $regular_price = (float)$product->get_regular_price();
        if ($regular_price <= 0) {
            return null;
        }

        $candidate_prices = [];
        $native_sale = (float)$product->get_sale_price();
        if ($native_sale > 0 && $native_sale < $regular_price) {
            $candidate_prices[] = $native_sale;
        }

        $dynamic_sale = RulesEngine::instance()->calculate_catalog_discount($product, $regular_price);
        if ($dynamic_sale !== null && (float)$dynamic_sale > 0 && (float)$dynamic_sale < $regular_price) {
            $candidate_prices[] = (float)$dynamic_sale;
        }

        if (empty($candidate_prices)) {
            return null;
        }

        $sale_price = min($candidate_prices);
        $percentage = (int)round((($regular_price - $sale_price) / $regular_price) * 100);

        if ($percentage <= 0) {
            return null;
        }

        return [
            'percentage'    => $percentage,
            'regular_price' => $regular_price,
            'sale_price'    => $sale_price,
        ];
    }

    /**
     * Render one product card.
     */
    private function render_product_card($product, array $sale_data)
    {
        $product_id = (int)$product->get_id();
        $image = function_exists('get_the_post_thumbnail')
            ? get_the_post_thumbnail($product_id, 'woocommerce_thumbnail', ['class' => 'drw-sale-item-image'])
            : '';

        $price_html = sprintf(
            '<span class="drw-sale-item-price"><del>%s</del> <ins>%s</ins></span>',
            function_exists('wc_price') ? wc_price($sale_data['regular_price']) : esc_html($sale_data['regular_price']),
            function_exists('wc_price') ? wc_price($sale_data['sale_price']) : esc_html($sale_data['sale_price'])
        );

        return sprintf(
            '<article class="drw-sale-item"><a class="drw-sale-item-link" href="%s"><span class="drw-sale-item-media">%s%s</span><span class="drw-sale-item-title">%s</span>%s</a></article>',
            esc_url(get_permalink($product_id)),
            self::render_sale_percentage_badge($sale_data['percentage']),
            $image,
            esc_html($product->get_name()),
            $price_html
        );
    }

    /**
     * Normalize comma-separated product IDs.
     */
    private function parse_id_list($value)
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string)$value);
        }

        $ids = [];
        foreach ($items as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
