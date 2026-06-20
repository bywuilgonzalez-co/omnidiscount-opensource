<?php

namespace Drw\App\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

class ShortcodeController
{
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks()
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('drw_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('awdr_sale_items_list', [$this, 'render_sale_items_list']);
            add_shortcode('on_sale', [$this, 'render_sale_items_list']);
        }

        if (function_exists('add_action')) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
            add_action('wp_ajax_drw_filter_sale_items', [$this, 'ajax_filter_sale_items']);
            add_action('wp_ajax_nopriv_drw_filter_sale_items', [$this, 'ajax_filter_sale_items']);
        }
    }

    /**
     * Enqueue public CSS and JS on the frontend.
     */
    public function enqueue_public_assets()
    {
        wp_enqueue_style(
            'drw-public-style',
            DRW_PLUGIN_URL . 'assets/css/public-style.css',
            [],
            DRW_VERSION
        );

        wp_enqueue_script(
            'drw-public-script',
            DRW_PLUGIN_URL . 'assets/js/public-script.js',
            ['jquery'],
            DRW_VERSION,
            true
        );
    }

    /**
     * Render sale products shortcode.
     *
     * Supported examples:
     * [awdr_sale_items_list]
     * [awdr_sale_items_list category="combos" limit="12" columns="4" show_filter="yes"]
     */
    public function render_sale_items_list($atts = [])
    {
        $atts = shortcode_atts([
            'limit'       => 12,
            'columns'     => 4,
            'category'    => '',
            'ids'         => '',
            'scan_limit'  => 240,
            'class'       => '',
            'show_filter' => 'yes',
        ], (array)$atts, 'drw_sale_items_list');

        $limit       = min(48, max(1, absint($atts['limit'])));
        $columns     = min(6, max(1, absint($atts['columns'])));
        $scan_limit  = min(200, max($limit, absint($atts['scan_limit'])));
        $category    = sanitize_text_field($atts['category']);
        $ids         = $this->parse_id_list($atts['ids']);
        $show_filter = ('no' !== $atts['show_filter']);
        $class       = sanitize_text_field($atts['class']);

        $this->enqueue_public_assets();

        $cards = $this->build_sale_cards($ids, $category, $limit, $scan_limit);

        $grid_content = empty($cards)
            ? '<div class="drw-sale-items-empty">' . esc_html__('No sale products found.', 'discount-rules-woo') . '</div>'
            : implode('', $cards);

        $filter_bar = '';
        if ($show_filter && empty($ids)) {
            $filter_bar = $this->render_filter_bar($category);
        }

        return sprintf(
            '<div class="drw-sale-shortcode" data-limit="%d" data-columns="%d" data-scan-limit="%d" data-ajax-url="%s" data-nonce="%s">%s<div class="drw-sale-wrap"><div class="drw-sale-items-grid %s" style="--drw-sale-columns:%d">%s</div></div></div>',
            $limit,
            $columns,
            $scan_limit,
            esc_url(admin_url('admin-ajax.php')),
            esc_attr(wp_create_nonce('drw_public_nonce')),
            $filter_bar,
            esc_attr($class),
            $columns,
            $grid_content
        );
    }

    /**
     * Render the category/sort filter bar.
     */
    private function render_filter_bar($current_category = '')
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        $options = '<option value="">' . esc_html__('Todas las categorías', 'discount-rules-woo') . '</option>';
        foreach ((array)$terms as $term) {
            $selected = selected($current_category, $term->slug, false);
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($term->slug),
                $selected,
                esc_html($term->name)
            );
        }

        $clear_style = $current_category ? '' : ' style="display:none"';

        return sprintf(
            '<div class="drw-sort-bar">
                <select class="drw-cat-select">%s</select>
                <button type="button" class="drw-clear-filter"%s>&#x2715; %s</button>
                <select class="drw-sort-select">
                    <option value="discount">%s</option>
                    <option value="latest">%s</option>
                    <option value="price_asc">%s</option>
                    <option value="price_desc">%s</option>
                </select>
            </div>',
            $options,
            $clear_style,
            esc_html__('Limpiar', 'discount-rules-woo'),
            esc_html__('Mayor descuento', 'discount-rules-woo'),
            esc_html__('Más recientes', 'discount-rules-woo'),
            esc_html__('Precio: menor a mayor', 'discount-rules-woo'),
            esc_html__('Precio: mayor a menor', 'discount-rules-woo')
        );
    }

    /**
     * AJAX handler for category/sort filter reload.
     */
    public function ajax_filter_sale_items()
    {
        check_ajax_referer('drw_public_nonce', 'nonce');

        $category   = isset($_POST['category'])   ? sanitize_text_field(wp_unslash($_POST['category']))   : '';
        $sort       = isset($_POST['sort'])        ? sanitize_text_field(wp_unslash($_POST['sort']))        : 'discount';
        $limit      = isset($_POST['limit'])       ? min(48, max(1, absint($_POST['limit'])))               : 12;
        $columns    = isset($_POST['columns'])     ? min(6, max(1, absint($_POST['columns'])))              : 4;
        $scan_limit = isset($_POST['scan_limit'])  ? min(200, max($limit, absint($_POST['scan_limit'])))    : 240;

        $cards = $this->build_sale_cards([], $category, $limit, $scan_limit, $sort);

        if (empty($cards)) {
            $html = sprintf(
                '<div class="drw-sale-items-grid" style="--drw-sale-columns:%d"><div class="drw-sale-items-empty">%s</div></div>',
                $columns,
                esc_html__('No sale products found.', 'discount-rules-woo')
            );
        } else {
            $html = sprintf(
                '<div class="drw-sale-items-grid" style="--drw-sale-columns:%d">%s</div>',
                $columns,
                implode('', $cards)
            );
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Build product card HTML array, optionally sorted.
     *
     * @param array  $ids        Specific product IDs (overrides category scan).
     * @param string $category   Category slug filter.
     * @param int    $limit      Max cards to return.
     * @param int    $scan_limit How many candidates to scan.
     * @param string $sort       'discount' | 'latest' | 'price_asc' | 'price_desc'
     * @return string[]
     */
    private function build_sale_cards(array $ids, $category, $limit, $scan_limit, $sort = 'discount')
    {
        $extra_query_args = [];
        if ('price_asc' === $sort) {
            $extra_query_args = ['orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC'];
        } elseif ('price_desc' === $sort) {
            $extra_query_args = ['orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'DESC'];
        } elseif ('latest' === $sort) {
            $extra_query_args = ['orderby' => 'date', 'order' => 'DESC'];
        }

        $product_ids = $this->get_sale_candidate_product_ids($ids, $category, $scan_limit, $extra_query_args);
        $cards       = [];
        $pending     = [];

        foreach ($product_ids as $product_id) {
            $product_id = is_object($product_id) && isset($product_id->ID) ? $product_id->ID : $product_id;
            $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                continue;
            }

            $sale_data = self::get_sale_data_for_product($product);
            if (empty($sale_data['percentage'])) {
                continue;
            }

            if ('discount' === $sort) {
                $pending[] = [
                    'product'   => $product,
                    'sale_data' => $sale_data,
                    'pct'       => $sale_data['percentage'],
                ];
            } else {
                $cards[] = $this->render_product_card($product, $sale_data);
                if (count($cards) >= $limit) {
                    break;
                }
            }
        }

        if ('discount' === $sort && !empty($pending)) {
            usort($pending, function ($a, $b) {
                return $b['pct'] - $a['pct'];
            });
            foreach (array_slice($pending, 0, $limit) as $item) {
                $cards[] = $this->render_product_card($item['product'], $item['sale_data']);
            }
        }

        return $cards;
    }

    /**
     * Build a broad candidate list from active discount rules before falling back
     * to a generic product scan.
     *
     * @param array  $ids              Specific IDs (if set, used directly).
     * @param string $category         Category slug filter.
     * @param int    $scan_limit       Max products to scan.
     * @param array  $extra_query_args Additional WP_Query args (e.g. orderby).
     * @return int[]
     */
    private function get_sale_candidate_product_ids(array $ids, $category, $scan_limit, array $extra_query_args = [])
    {
        if (!empty($ids)) {
            return $this->query_product_ids(
                array_merge(['post__in' => $ids, 'orderby' => 'post__in'], $extra_query_args),
                $category,
                min($scan_limit, count($ids))
            );
        }

        $candidate_ids = [];
        $engine        = RulesEngine::instance();
        $rules         = method_exists($engine, 'get_active_rules') ? $engine->get_active_rules() : [];

        foreach ((array)$rules as $rule) {
            $adjustments = !empty($rule['adjustments']) ? (array)$rule['adjustments'] : [];
            $type        = !empty($adjustments['type']) ? $adjustments['type'] : '';
            if (!in_array($type, ['percentage', 'fixed', 'bulk'], true)) {
                continue;
            }

            $apply_to = !empty($rule['apply_to']) ? $rule['apply_to'] : 'all_products';
            $filters  = !empty($rule['filters']) ? (array)$rule['filters'] : [];

            if ('specific_products' === $apply_to && !empty($filters['product_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids(
                        array_merge(['post__in' => array_map('intval', (array)$filters['product_ids']), 'orderby' => 'post__in'], $extra_query_args),
                        $category,
                        $scan_limit
                    )
                );
            } elseif ('specific_categories' === $apply_to && !empty($filters['category_ids'])) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids(
                        array_merge([
                            'tax_query' => [[
                                'taxonomy' => 'product_cat',
                                'field'    => 'term_id',
                                'terms'    => array_map('intval', (array)$filters['category_ids']),
                            ]],
                        ], $extra_query_args),
                        $category,
                        $scan_limit
                    )
                );
            } elseif ('all_products' === $apply_to) {
                $candidate_ids = array_merge(
                    $candidate_ids,
                    $this->query_product_ids($extra_query_args, $category, $scan_limit)
                );
            }
        }

        if (function_exists('wc_get_product_ids_on_sale')) {
            $candidate_ids = array_merge($candidate_ids, array_map('intval', (array)wc_get_product_ids_on_sale()));
        }

        $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));

        if (empty($candidate_ids)) {
            $candidate_ids = $this->query_product_ids($extra_query_args, $category, $scan_limit);
        }

        return $candidate_ids;
    }

    /**
     * Query product IDs with optional category and extra WP_Query args.
     *
     * @param array  $extra_args  Extra WP_Query args merged into defaults.
     * @param string $category    Category slug filter.
     * @param int    $limit       Max results.
     * @return int[]
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

        if ('' !== $category) {
            $cat_filter = [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array_map('trim', explode(',', $category)),
            ]];

            if (!empty($query_args['tax_query'])) {
                $query_args['tax_query'] = array_merge((array)$query_args['tax_query'], $cat_filter);
            } else {
                $query_args['tax_query'] = $cat_filter;
            }
        }

        return get_posts($query_args);
    }

    /**
     * Build sale data for simple or variable products.
     *
     * @param \WC_Product $product
     * @return array|null
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
     *
     * @param int $percentage
     * @return string
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
     *
     * @param \WC_Product|null $product
     * @return array|null
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
        $native_sale      = (float)$product->get_sale_price();
        if ($native_sale > 0 && $native_sale < $regular_price) {
            $candidate_prices[] = $native_sale;
        }

        $dynamic_sale = RulesEngine::instance()->calculate_catalog_discount($product, $regular_price);
        if (null !== $dynamic_sale && (float)$dynamic_sale > 0 && (float)$dynamic_sale < $regular_price) {
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
     *
     * @param \WC_Product $product
     * @param array       $sale_data
     * @return string
     */
    private function render_product_card($product, array $sale_data)
    {
        $product_id = (int)$product->get_id();

        $image  = function_exists('get_the_post_thumbnail')
            ? get_the_post_thumbnail($product_id, 'woocommerce_thumbnail', ['class' => 'drw-sale-item-image'])
            : '';

        if (empty($image) && function_exists('wc_placeholder_img')) {
            $image = wc_placeholder_img('woocommerce_thumbnail', ['class' => 'drw-sale-item-image']);
        }

        $price_html = sprintf(
            '<span class="drw-sale-item-price"><del>%s</del> <ins>%s</ins></span>',
            function_exists('wc_price') ? wc_price($sale_data['regular_price']) : esc_html($sale_data['regular_price']),
            function_exists('wc_price') ? wc_price($sale_data['sale_price'])    : esc_html($sale_data['sale_price'])
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
     *
     * @param string|array $value
     * @return int[]
     */
    private function parse_id_list($value)
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $ids   = [];
        foreach ($items as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
