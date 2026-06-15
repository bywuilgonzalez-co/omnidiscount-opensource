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
            'scan_limit' => 240,
            'class'      => '',
        ], (array)$atts, 'drw_sale_items_list');

        $limit = min(48, max(1, absint($atts['limit'])));
        $columns = min(6, max(1, absint($atts['columns'])));
        $scan_limit = min(200, max($limit, absint($atts['scan_limit'])));
        $category = sanitize_text_field($atts['category']);
        $ids = $this->parse_id_list($atts['ids']);

        $product_ids = $this->get_sale_candidate_product_ids($ids, $category, $scan_limit);
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
     * Build a broad candidate list from active discount rules before falling back
     * to a generic product scan. This lets dynamic-rule products appear even when
     * they are not in the first page of products.
     */
    private function get_sale_candidate_product_ids(array $ids, $category, $scan_limit)
    {
        if (!empty($ids)) {
            return $this->query_product_ids([
                'post__in' => $ids,
                'orderby'  => 'post__in',
            ], $category, min($scan_limit, count($ids)));
        }

        $candidate_ids = [];
        $engine = RulesEngine::instance();
        $rules = method_exists($engine, 'get_active_rules') ? $engine->get_active_rules() : [];

        foreach ((array)$rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type = !empty($adjustments['type']) ? $adjustments['type'] : '';
            if (!in_array($type, ['percentage', 'fixed', 'bulk'], true)) {
                continue;
            }

            $apply_to = !empty($rule['apply_to']) ? $rule['apply_to'] : 'all_products';
            $filters = !empty($rule['filters']) ? (array)$rule['filters'] : [];

            if ($apply_to === 'specific_products' && !empty($filters['product_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([
                        'post__in' => array_map('intval', (array)$filters['product_ids']),
                        'orderby'  => 'post__in',
                    ], $category, $scan_limit)
                );
            } elseif ($apply_to === 'specific_categories' && !empty($filters['category_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([
                        'tax_query' => [
                            [
                                'taxonomy' => 'product_cat',
                                'field'    => 'term_id',
                                'terms'    => array_map('intval', (array)$filters['category_ids']),
                            ],
                        ],
                    ], $category, $scan_limit)
                );
            } elseif ($apply_to === 'all_products') {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids([], $category, $scan_limit)
                );
            }
        }

        if (function_exists('wc_get_product_ids_on_sale')) {
            $candidate_ids = array_merge($candidate_ids, array_map('intval', (array)wc_get_product_ids_on_sale()));
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
        if (empty($candidate_ids)) {
            $candidate_ids = $this->query_product_ids([], $category, $scan_limit);
        }

        return $candidate_ids;
    }

    /**
     * Query product IDs with optional shortcode category narrowing.
     */
    private function query_product_ids(array $extra_args, $category, $limit)
    {
        $query_args = array_merge([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ], $extra_args);

        if ($category !== '') {
            $category_filter = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => array_map('trim', explode(',', $category)),
                ],
            ];

            if (!empty($query_args['tax_query'])) {
                $query_args['tax_query'] = array_merge((array)$query_args['tax_query'], $category_filter);
            } else {
                $query_args['tax_query'] = $category_filter;
            }
        }

        return get_posts($query_args);
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
        // Variations come from wc_get_product_ids_on_sale(); normalize to parent
        // so we get the correct "Imagen del producto" and the right product page URL.
        $display = $product->is_type('variation')
            ? (wc_get_product($product->get_parent_id()) ?: $product)
            : $product;

        $product_id  = (int)$display->get_id();
        $product_url = get_permalink($product_id);

        // Image — use "Imagen del producto" (parent featured image) first,
        // then gallery, then WC placeholder. Never use variation-specific images.
        $image = get_the_post_thumbnail($product_id, 'woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);

        if (empty($image)) {
            $gallery = $display->get_gallery_image_ids();
            if (!empty($gallery)) {
                $image = wp_get_attachment_image($gallery[0], 'woocommerce_thumbnail', false, ['class' => 'drw-sale-item-image']);
            }
        }

        if (empty($image) && function_exists('wc_placeholder_img')) {
            $image = wc_placeholder_img('woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);
        }

        $price_html = sprintf(
            '<span class="drw-sale-item-price"><del>%s</del> <ins>%s</ins></span>',
            wc_price($sale_data['regular_price']),
            wc_price($sale_data['sale_price'])
        );

        // WooCommerce's add_to_cart_text() is already translated by WC language packs
        // (Spanish: "Añadir al carrito" / "Seleccionar opciones").
        if ($display->is_type('variable') || $display->is_type('grouped')) {
            $btn_url   = $product_url;
            $btn_class = 'drw-sale-item-btn button';
        } else {
            $btn_url   = $display->add_to_cart_url();
            $btn_class = 'drw-sale-item-btn button add_to_cart_button ajax_add_to_cart';
        }

        $btn_text = $display->add_to_cart_text();

        $button = sprintf(
            '<a href="%s" data-product_id="%d" data-quantity="1" class="%s" aria-label="%s" rel="nofollow">%s</a>',
            esc_url($btn_url),
            $product_id,
            esc_attr($btn_class),
            esc_attr($btn_text),
            esc_html($btn_text)
        );

        return sprintf(
            '<article class="drw-sale-item">
            <a class="drw-sale-item-link" href="%s">
                <span class="drw-sale-item-media">%s%s</span>
                <span class="drw-sale-item-title">%s</span>
                %s
            </a>
            <div class="drw-sale-item-footer">%s</div>
        </article>',
            esc_url($product_url),
            self::render_sale_percentage_badge($sale_data['percentage']),
            $image,
            esc_html($display->get_name()),
            $price_html,
            $button
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
